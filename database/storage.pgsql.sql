CREATE OR REPLACE FUNCTION ledger_timestamps() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE stamp timestamptz; fields jsonb := to_jsonb(NEW);
BEGIN
    IF TG_OP = 'INSERT' THEN
        stamp := clock_timestamp();
        NEW := jsonb_populate_record(NEW, jsonb_build_object('created_at', stamp));
        IF fields ? 'updated_at' THEN NEW := jsonb_populate_record(NEW, jsonb_build_object('updated_at', stamp)); END IF;
        IF fields->>'deleted_at' IS NOT NULL THEN RAISE EXCEPTION 'New catalog records cannot already be soft deleted'; END IF;
    ELSE
        IF NEW.id IS DISTINCT FROM OLD.id THEN RAISE EXCEPTION 'Ledger identifiers are immutable'; END IF;
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
