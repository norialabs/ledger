CREATE OR REPLACE FUNCTION ledger_timestamps() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE stamp timestamptz; fields jsonb := to_jsonb(NEW);
BEGIN
    IF TG_OP = 'INSERT' THEN
        stamp := clock_timestamp();
        NEW := jsonb_populate_record(NEW, jsonb_build_object('created_at', stamp));
        IF fields ? 'updated_at' THEN NEW := jsonb_populate_record(NEW, jsonb_build_object('updated_at', stamp)); END IF;
        IF fields->>'deleted_at' IS NOT NULL THEN RAISE EXCEPTION 'New catalog records cannot already be soft deleted'; END IF;
    ELSE
        IF NEW.created_at IS DISTINCT FROM OLD.created_at THEN RAISE EXCEPTION 'Creation timestamps are immutable'; END IF;
        IF fields ? 'updated_at' THEN
            stamp := CASE WHEN (to_jsonb(NEW) - 'next_sequence' - 'updated_at') = (to_jsonb(OLD) - 'next_sequence' - 'updated_at')
                THEN (to_jsonb(OLD)->>'updated_at')::timestamptz ELSE clock_timestamp() END;
            NEW := jsonb_populate_record(NEW, jsonb_build_object('updated_at', stamp));
        END IF;
        IF fields->>'deleted_at' IS NOT NULL THEN
            IF to_jsonb(OLD)->>'deleted_at' IS NULL THEN
                NEW := jsonb_populate_record(NEW, jsonb_build_object('deleted_at', clock_timestamp()));
            ELSIF fields->>'deleted_at' IS DISTINCT FROM to_jsonb(OLD)->>'deleted_at' THEN
                RAISE EXCEPTION 'Restore a catalog record before deleting it again';
            END IF;
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_require_live_book(tenant uuid, book uuid, allow_archived boolean DEFAULT false)
RETURNS void LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE archived timestamptz;
BEGIN
    SELECT deleted_at INTO STRICT archived FROM ledger_books WHERE tenant_id = tenant AND id = book FOR UPDATE;
    IF archived IS NOT NULL AND NOT allow_archived THEN RAISE EXCEPTION 'Restore the soft-deleted accounting book before new activity'; END IF;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_catalog_lifecycle() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE entity uuid;
BEGIN
    IF TG_TABLE_NAME = 'ledger_entities' THEN
        IF NEW.deleted_at IS NOT NULL AND EXISTS (SELECT 1 FROM ledger_books WHERE tenant_id = NEW.tenant_id AND entity_id = NEW.id AND deleted_at IS NULL) THEN
            RAISE EXCEPTION 'Soft delete active accounting books before their legal entity';
        END IF;
    ELSIF TG_TABLE_NAME = 'ledger_books' THEN
        IF NEW.deleted_at IS NULL THEN
            PERFORM 1 FROM ledger_entities WHERE tenant_id = NEW.tenant_id AND id = NEW.entity_id AND deleted_at IS NULL FOR SHARE;
            IF NOT FOUND THEN RAISE EXCEPTION 'Restore the soft-deleted legal entity before using its books'; END IF;
        END IF;
    ELSIF TG_TABLE_NAME = 'ledger_accounts' THEN
        PERFORM 1 FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id FOR UPDATE;
        IF NEW.deleted_at IS NULL THEN
            PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id);
            IF NEW.parent_id IS NOT NULL AND EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.parent_id AND deleted_at IS NOT NULL) THEN
                RAISE EXCEPTION 'Restore the soft-deleted parent account first';
            END IF;
        ELSIF EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND parent_id = NEW.id AND deleted_at IS NULL) THEN
            RAISE EXCEPTION 'Soft delete child accounts before their parent';
        END IF;
    ELSIF TG_TABLE_NAME = 'ledger_account_dimensions' THEN
        PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id);
        IF EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.account_id AND deleted_at IS NOT NULL) THEN
            RAISE EXCEPTION 'Cannot configure a soft-deleted account';
        END IF;
    ELSIF TG_TABLE_NAME = 'ledger_periods' THEN
        PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id, TG_OP = 'UPDATE' AND NEW.closed);
    ELSE
        entity := NEW.entity_id;
        IF NEW.deleted_at IS NULL THEN
            PERFORM 1 FROM ledger_entities WHERE tenant_id = NEW.tenant_id AND id = entity AND deleted_at IS NULL FOR SHARE;
            IF NOT FOUND THEN RAISE EXCEPTION 'Restore the soft-deleted legal entity before using its catalog'; END IF;
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_require_live_dimensions(tenant uuid, book uuid, labels jsonb, allow_archived boolean DEFAULT false)
RETURNS void LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE entity uuid; label record;
BEGIN
    SELECT entity_id INTO STRICT entity FROM ledger_books WHERE tenant_id = tenant AND id = book;
    FOR label IN SELECT key, value FROM jsonb_each(labels) LOOP
        IF jsonb_typeof(label.value) <> 'string' THEN RAISE EXCEPTION 'Invalid accounting dimension'; END IF;
        PERFORM 1 FROM ledger_dimension_values WHERE tenant_id = tenant AND entity_id = entity AND dimension = label.key AND value = label.value #>> '{}'
            AND (deleted_at IS NULL OR allow_archived) FOR SHARE;
        IF NOT FOUND THEN RAISE EXCEPTION 'Unknown or soft-deleted accounting dimension'; END IF;
    END LOOP;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_json_evidence_stamp() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    IF TG_TABLE_NAME = 'ledger_configurations' THEN
        NEW.definition_hash := encode(sha256(convert_to(NEW.definition_json::text, 'UTF8')), 'hex');
    ELSE
        NEW.result_hash := encode(sha256(convert_to(NEW.result_json::text, 'UTF8')), 'hex');
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_install_storage() RETURNS void LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE relation record; column_info record; constraint_info record; bound integer; qualified text; forced_tables text[]; forced_table text;
    added_created boolean; added_updated boolean; identity_expression text; creation_expression text; modification_expression text;
    catalog_tables text[] := ARRAY['ledger_entities', 'ledger_books', 'ledger_accounts', 'ledger_parties', 'ledger_dimension_values'];
    mutable_tables text[] := ARRAY['ledger_entities', 'ledger_books', 'ledger_accounts', 'ledger_parties', 'ledger_dimension_values', 'ledger_periods'];
BEGIN
    SELECT array_agg(c.relname) INTO forced_tables FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = current_schema() AND c.relkind = 'r' AND c.relname LIKE 'ledger\_%' ESCAPE '\' AND c.relforcerowsecurity;
    FOREACH forced_table IN ARRAY coalesce(forced_tables, ARRAY[]::text[]) LOOP
        EXECUTE format('ALTER TABLE %I.%I NO FORCE ROW LEVEL SECURITY', current_schema(), forced_table);
    END LOOP;
    FOR relation IN SELECT c.oid, c.relname, c.relforcerowsecurity FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = current_schema() AND c.relkind = 'r' AND c.relname LIKE 'ledger\_%' ESCAPE '\' ORDER BY c.relname LOOP
        qualified := format('%I.%I', current_schema(), relation.relname);
        EXECUTE format('ALTER TABLE %s DISABLE TRIGGER USER', qualified);
        FOR column_info IN SELECT a.attname, a.atttypid, a.atttypmod FROM pg_attribute a WHERE a.attrelid = relation.oid AND a.attnum > 0 AND NOT a.attisdropped LOOP
            IF column_info.atttypid = 'varchar'::regtype AND column_info.atttypmod > 4 THEN
                bound := 1;
                WHILE bound < column_info.atttypmod - 4 LOOP bound := bound * 2; END LOOP;
                IF bound <> column_info.atttypmod - 4 THEN EXECUTE format('ALTER TABLE %s ALTER COLUMN %I TYPE varchar(%s)', qualified, column_info.attname, bound); END IF;
            ELSIF column_info.atttypid = 'uuid'::regtype AND column_info.attname = 'id' THEN
                EXECUTE format('ALTER TABLE %s ALTER COLUMN id SET DEFAULT uuidv7()', qualified);
            END IF;
        END LOOP;
        IF relation.relname IN ('ledger_configurations', 'ledger_statement_runs') THEN
            FOR constraint_info IN SELECT conname FROM pg_constraint WHERE conrelid = relation.oid AND contype = 'c'
                AND pg_get_constraintdef(oid) ~ '(definition_json|definition_hash|result_json|result_hash)' LOOP
                EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I', qualified, constraint_info.conname);
            END LOOP;
            IF relation.relname = 'ledger_configurations' THEN
                EXECUTE format('ALTER TABLE %s ALTER COLUMN definition_json TYPE jsonb USING definition_json::jsonb', qualified);
                EXECUTE format('UPDATE %s SET definition_hash = encode(sha256(convert_to(definition_json::text, ''UTF8'')), ''hex'')', qualified);
                EXECUTE format('ALTER TABLE %s ADD CONSTRAINT ledger_configuration_json_object CHECK (jsonb_typeof(definition_json) = ''object''), ADD CONSTRAINT ledger_configuration_json_hash CHECK (definition_hash = encode(sha256(convert_to(definition_json::text, ''UTF8'')), ''hex''))', qualified);
            ELSE
                EXECUTE format('ALTER TABLE %s ALTER COLUMN result_json TYPE jsonb USING result_json::jsonb', qualified);
                EXECUTE format('UPDATE %s SET result_hash = encode(sha256(convert_to(result_json::text, ''UTF8'')), ''hex'')', qualified);
                EXECUTE format('ALTER TABLE %s ADD CONSTRAINT ledger_statement_json_object CHECK (jsonb_typeof(result_json) = ''object''), ADD CONSTRAINT ledger_statement_json_hash CHECK (result_hash = encode(sha256(convert_to(result_json::text, ''UTF8'')), ''hex''))', qualified);
            END IF;
        ELSIF relation.relname = 'ledger_consolidation_runs' THEN
            EXECUTE format('ALTER TABLE %s ALTER COLUMN result_hash TYPE char(64)', qualified);
        ELSIF relation.relname = 'ledger_audit_events' THEN
            EXECUTE format('ALTER TABLE %s ALTER COLUMN table_name TYPE varchar(64), ALTER COLUMN operation TYPE varchar(8)', qualified);
        ELSIF relation.relname = 'ledger_currencies' THEN
            FOR constraint_info IN SELECT conname FROM pg_constraint WHERE conrelid = relation.oid AND contype = 'c' AND pg_get_constraintdef(oid) ~ 'code.*~' LOOP
                EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I', qualified, constraint_info.conname);
            END LOOP;
            EXECUTE format('ALTER TABLE %s ADD CONSTRAINT ledger_currency_code_format CHECK (code ~ ''^[A-Z][A-Z0-9]{2,15}$'')', qualified);
        END IF;
        added_created := NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = relation.oid AND attname = 'created_at' AND NOT attisdropped);
        added_updated := relation.relname = ANY(mutable_tables) AND NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = relation.oid AND attname = 'updated_at' AND NOT attisdropped);
        EXECUTE format('ALTER TABLE %s ADD COLUMN IF NOT EXISTS created_at timestamptz', qualified);
        IF relation.relname = ANY(mutable_tables) THEN EXECUTE format('ALTER TABLE %s ADD COLUMN IF NOT EXISTS updated_at timestamptz', qualified); END IF;
        IF relation.relname = ANY(catalog_tables) THEN EXECUTE format('ALTER TABLE %s ADD COLUMN IF NOT EXISTS deleted_at timestamptz', qualified); END IF;
        IF added_created OR added_updated THEN
            SELECT 'jsonb_build_object(' || string_agg(format('%L, to_jsonb(t)->%L', a.attname, a.attname), ', ') || ')' INTO identity_expression
                FROM pg_index i CROSS JOIN LATERAL unnest(i.indkey) key_number JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = key_number
                WHERE i.indrelid = relation.oid AND i.indisprimary;
            identity_expression := coalesce(identity_expression, 'NULL::jsonb');
            creation_expression := format('coalesce((to_jsonb(t)->>''recorded_at'')::timestamptz, (SELECT min(a.recorded_at) FROM ledger_audit_events a WHERE a.table_name = %L AND a.after_value @> %s), transaction_timestamp())', relation.relname, identity_expression);
            IF relation.relname IN ('ledger_postings', 'ledger_open_items', 'ledger_schedule_runs', 'ledger_batch_members') THEN
                creation_expression := 'coalesce((SELECT j.recorded_at FROM ledger_journals j WHERE j.tenant_id = t.tenant_id AND j.book_id = t.book_id AND j.id = t.journal_id), transaction_timestamp())';
            ELSIF relation.relname = 'ledger_bank_lines' THEN
                creation_expression := 'coalesce((SELECT s.recorded_at FROM ledger_bank_statements s WHERE s.tenant_id = t.tenant_id AND s.book_id = t.book_id AND s.id = t.statement_id), transaction_timestamp())';
            ELSIF relation.relname = 'ledger_inventory_layer_entries' THEN
                creation_expression := 'coalesce((SELECT m.recorded_at FROM ledger_inventory_moves m WHERE m.tenant_id = t.tenant_id AND m.book_id = t.book_id AND m.id = t.movement_id), transaction_timestamp())';
            ELSIF relation.relname = 'ledger_events' THEN
                creation_expression := 'coalesce((SELECT a.recorded_at FROM ledger_audit_events a WHERE a.tenant_id = t.tenant_id AND a.id = t.audit_id), transaction_timestamp())';
            END IF;
            modification_expression := format('coalesce((SELECT max(a.recorded_at) FROM ledger_audit_events a WHERE a.table_name = %L AND a.after_value @> %s), %s)', relation.relname, identity_expression, creation_expression);
            IF added_created THEN EXECUTE format('UPDATE %s t SET created_at = %s', qualified, creation_expression); END IF;
            IF added_updated THEN EXECUTE format('UPDATE %s t SET updated_at = %s', qualified, modification_expression); END IF;
        END IF;
        EXECUTE format('ALTER TABLE %s ALTER COLUMN created_at SET DEFAULT clock_timestamp(), ALTER COLUMN created_at SET NOT NULL', qualified);
        IF relation.relname = ANY(mutable_tables) THEN EXECUTE format('ALTER TABLE %s ALTER COLUMN updated_at SET DEFAULT clock_timestamp(), ALTER COLUMN updated_at SET NOT NULL', qualified); END IF;
        EXECUTE format('ALTER TABLE %s ENABLE TRIGGER USER', qualified);
        EXECUTE format('DROP TRIGGER IF EXISTS x_timestamps ON %s', qualified);
        EXECUTE format('CREATE TRIGGER x_timestamps BEFORE INSERT OR UPDATE ON %s FOR EACH ROW EXECUTE FUNCTION ledger_timestamps()', qualified);
        IF relation.relname = ANY(mutable_tables) OR relation.relname = 'ledger_account_dimensions' THEN
            EXECUTE format('DROP TRIGGER IF EXISTS w_catalog_lifecycle ON %s', qualified);
            EXECUTE format('CREATE TRIGGER w_catalog_lifecycle BEFORE INSERT OR UPDATE ON %s FOR EACH ROW EXECUTE FUNCTION ledger_catalog_lifecycle()', qualified);
        END IF;
        IF relation.relname IN ('ledger_configurations', 'ledger_statement_runs') THEN
            EXECUTE format('DROP TRIGGER IF EXISTS b_json_evidence ON %s', qualified);
            EXECUTE format('CREATE TRIGGER b_json_evidence BEFORE INSERT ON %s FOR EACH ROW EXECUTE FUNCTION ledger_json_evidence_stamp()', qualified);
        END IF;
    END LOOP;
    FOREACH forced_table IN ARRAY coalesce(forced_tables, ARRAY[]::text[]) LOOP
        EXECUTE format('ALTER TABLE %I.%I FORCE ROW LEVEL SECURITY', current_schema(), forced_table);
    END LOOP;
END;
$body$;

SELECT ledger_install_storage();
REVOKE ALL ON FUNCTION ledger_install_storage() FROM PUBLIC;
