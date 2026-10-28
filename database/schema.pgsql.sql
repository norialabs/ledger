ALTER TABLE ledger_currency_definitions ADD CHECK (scale BETWEEN 0 AND 12);
ALTER TABLE ledger_currencies ADD CHECK (code ~ '^[A-Z][A-Z0-9]{2,15}$'), ADD CHECK (scale BETWEEN 0 AND 12);
ALTER TABLE ledger_entities ADD CHECK (length(btrim(name)) > 0), ADD CHECK (country ~ '^[A-Z]{2}$');
ALTER TABLE ledger_books ADD CHECK (length(btrim(name)) > 0), ADD CHECK (next_sequence >= 0);
ALTER TABLE ledger_accounts
    ADD CHECK (length(btrim(code)) > 0), ADD CHECK (length(btrim(name)) > 0),
    ADD CHECK (type IN ('asset', 'liability', 'equity', 'income', 'expense')),
    ADD CHECK (normal_side IN ('debit', 'credit')),
    ADD CHECK (cash_flow IN ('cash', 'operating', 'investing', 'financing')),
    ADD CHECK (parent_id IS NULL OR parent_id <> id), ADD CHECK (NOT is_group OR NOT is_control),
    ADD CHECK (NOT is_control OR type IN ('asset', 'liability'));
ALTER TABLE ledger_parties ADD CHECK (length(btrim(name)) > 0);
ALTER TABLE ledger_periods ADD CHECK (ends_on >= starts_on);
ALTER TABLE ledger_sequence_reservations ADD CHECK (size BETWEEN 1 AND 10000), ADD CHECK (through_sequence = after_sequence + size);
ALTER TABLE ledger_journals
    ADD CHECK (fingerprint ~ '^[a-f0-9]{64}$'), ADD CHECK (sequence > 0), ADD CHECK (length(btrim(description)) > 0),
    ADD CHECK (jsonb_typeof(postings) = 'array' AND jsonb_array_length(postings) BETWEEN 2 AND 10000),
    ADD CHECK (jsonb_typeof(metadata) = 'object'), ADD CHECK (jsonb_typeof(policy_versions) = 'array'),
    ADD CHECK ((source_type IS NULL) = (source_reference IS NULL));
ALTER TABLE ledger_postings
    ADD CHECK (line_no > 0), ADD CHECK (side IN ('debit', 'credit')), ADD CHECK (amount_minor >= 0), ADD CHECK (functional_minor >= 0),
    ADD CHECK (rate > 0), ADD CHECK (length(btrim(rate_source)) > 0), ADD CHECK (rounding IN ('half_even', 'half_up', 'down')),
    ADD CHECK (jsonb_typeof(dimensions) = 'object'),
    ADD CHECK ((NOT valuation AND amount_minor > 0) OR (valuation AND amount_minor = 0 AND functional_minor > 0));
CREATE INDEX ledger_postings_party ON ledger_postings (tenant_id, book_id, party_id, account_id) WHERE party_id IS NOT NULL;
ALTER TABLE ledger_allocations
    ADD CHECK (fingerprint ~ '^[a-f0-9]{64}$'), ADD CHECK (amount_minor > 0), ADD CHECK (debit_functional_minor >= 0),
    ADD CHECK (credit_functional_minor >= 0), ADD CHECK (sequence > 0), ADD CHECK (debit_item_id <> credit_item_id);
ALTER TABLE ledger_schedules
    ADD CHECK (amount_minor > 0), ADD CHECK (jsonb_typeof(plan) = 'array' AND jsonb_array_length(plan) BETWEEN 1 AND 10000),
    ADD CHECK (jsonb_typeof(dimensions) = 'object'), ADD CHECK (debit_account_id <> credit_account_id);
ALTER TABLE ledger_schedule_runs ADD CHECK (line_no > 0);
ALTER TABLE ledger_schedule_cancellations ADD CHECK (length(btrim(reason)) > 0);
ALTER TABLE ledger_year_closures ADD CHECK (ends_on >= starts_on);
ALTER TABLE ledger_bank_statements
    ADD CHECK (effective_on >= starts_on), ADD CHECK (jsonb_typeof(movements) = 'array' AND jsonb_array_length(movements) <= 10000);
ALTER TABLE ledger_bank_lines ADD CHECK (amount_minor <> 0);
ALTER TABLE ledger_bank_matches ADD CHECK (amount_minor > 0);
ALTER TABLE ledger_bank_voids ADD CHECK (length(btrim(reason)) > 0);
ALTER TABLE ledger_batches ADD CHECK (jsonb_typeof(members) = 'array' AND jsonb_array_length(members) BETWEEN 2 AND 10000);

CREATE OR REPLACE FUNCTION ledger_fraction(numerator numeric, denominator numeric, mode text) RETURNS numeric LANGUAGE plpgsql SET search_path FROM CURRENT IMMUTABLE STRICT AS $body$
DECLARE
    integral numeric;
    remainder numeric;
BEGIN
    IF denominator <= 0 OR mode NOT IN ('half_even', 'half_up', 'down') THEN
        RAISE EXCEPTION 'Invalid exact fraction';
    END IF;
    integral := div(abs(numerator), denominator);
    remainder := mod(abs(numerator), denominator);
    IF mode <> 'down' AND (2 * remainder > denominator OR (2 * remainder = denominator AND (mode = 'half_up' OR mod(integral, 2) = 1))) THEN
        integral := integral + 1;
    END IF;
    RETURN sign(numerator) * integral;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_scope_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    IF NEW.tenant_id::text IS DISTINCT FROM nullif(current_setting('noria.ledger_tenant', true), '')
       OR nullif(current_setting('noria.ledger_actor', true), '') IS NULL THEN
        RAISE EXCEPTION 'An explicit ledger tenant and actor are required';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_immutable() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    RAISE EXCEPTION '% is append-only', TG_TABLE_NAME;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_derived_insert() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    IF pg_trigger_depth() < 2 THEN
        RAISE EXCEPTION '% can only be derived from a journal', TG_TABLE_NAME;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_audit() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    INSERT INTO ledger_audit_events (tenant_id, table_name, operation, before_value, after_value, actor_id, firm_id)
    VALUES (NEW.tenant_id, TG_TABLE_NAME, TG_OP, CASE WHEN TG_OP = 'UPDATE' THEN to_jsonb(OLD) ELSE NULL END,
            to_jsonb(NEW), current_setting('noria.ledger_actor'), nullif(current_setting('noria.ledger_firm', true), ''));
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_master_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    locked_book uuid;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Ledger configuration cannot be deleted';
    END IF;
    IF TG_TABLE_NAME = 'ledger_currencies' THEN
        IF TG_OP = 'UPDATE' OR EXISTS (SELECT 1 FROM ledger_currency_definitions WHERE code = NEW.code AND scale <> NEW.scale) THEN
            RAISE EXCEPTION 'Currency definitions are immutable and ISO scales must match';
        END IF;
    ELSIF TG_TABLE_NAME = 'ledger_books' THEN
        IF TG_OP = 'INSERT' THEN
            NEW.next_sequence := 0;
        ELSIF (to_jsonb(NEW) - 'name' - 'next_sequence' - 'deleted_at' - 'updated_at') <> (to_jsonb(OLD) - 'name' - 'next_sequence' - 'deleted_at' - 'updated_at')
           OR (NEW.next_sequence <> OLD.next_sequence AND pg_trigger_depth() < 2) THEN
            RAISE EXCEPTION 'Book identity and sequence are protected';
        END IF;
    ELSIF TG_TABLE_NAME = 'ledger_periods' THEN
        SELECT id INTO locked_book FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id FOR UPDATE;
        IF TG_OP = 'UPDATE' AND (to_jsonb(NEW) - 'closed' - 'change_reason' - 'updated_at') <> (to_jsonb(OLD) - 'closed' - 'change_reason' - 'updated_at') THEN
            RAISE EXCEPTION 'Period boundaries cannot change';
        END IF;
        IF EXISTS (SELECT 1 FROM ledger_periods WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
                   AND id <> NEW.id AND starts_on <= NEW.ends_on AND ends_on >= NEW.starts_on) THEN
            RAISE EXCEPTION 'Accounting periods cannot overlap';
        END IF;
    ELSIF TG_TABLE_NAME = 'ledger_accounts' THEN
        SELECT id INTO locked_book FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id FOR UPDATE;
        IF NEW.cash_flow = 'cash' AND (NEW.type <> 'asset' OR NEW.is_group OR NEW.is_control) THEN
            RAISE EXCEPTION 'Cash classification requires a cash asset posting account';
        END IF;
        IF TG_OP = 'UPDATE' AND (to_jsonb(NEW) - 'active' - 'name' - 'parent_id' - 'deleted_at' - 'updated_at') <> (to_jsonb(OLD) - 'active' - 'name' - 'parent_id' - 'deleted_at' - 'updated_at') THEN
            RAISE EXCEPTION 'Account classification cannot change; create a new account';
        END IF;
        IF TG_OP = 'UPDATE' AND NEW.parent_id IS DISTINCT FROM OLD.parent_id AND NEW.parent_id IS NOT NULL AND EXISTS (
            WITH RECURSIVE ancestors(id, parent_id) AS (
                SELECT id, parent_id FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.parent_id
                UNION SELECT a.id, a.parent_id FROM ledger_accounts a JOIN ancestors ON a.tenant_id = NEW.tenant_id AND a.book_id = NEW.book_id AND a.id = ancestors.parent_id)
            SELECT 1 FROM ancestors WHERE id = NEW.id) THEN
            RAISE EXCEPTION 'An account cannot move beneath its own descendant';
        END IF;
        IF NEW.type IN ('income', 'expense', 'equity') AND NEW.currency_code IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id AND currency_code = NEW.currency_code) THEN
            RAISE EXCEPTION 'Income, expense, and equity accounts use functional currency';
        END IF;
        IF NEW.parent_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id
            AND book_id = NEW.book_id AND id = NEW.parent_id AND is_group AND type = NEW.type) THEN
            RAISE EXCEPTION 'An account parent must be a group of the same type';
        END IF;
    ELSIF TG_TABLE_NAME IN ('ledger_entities', 'ledger_parties') THEN
        IF TG_OP = 'UPDATE' AND (to_jsonb(NEW) - 'name' - 'deleted_at' - 'updated_at') <> (to_jsonb(OLD) - 'name' - 'deleted_at' - 'updated_at') THEN
            RAISE EXCEPTION 'Ledger master identity is immutable';
        END IF;
    ELSIF TG_TABLE_NAME = 'ledger_dimension_values' THEN
        IF TG_OP = 'UPDATE' AND (to_jsonb(NEW) - 'deleted_at' - 'updated_at') <> (to_jsonb(OLD) - 'deleted_at' - 'updated_at') THEN
            RAISE EXCEPTION 'Ledger dimension identity is immutable';
        END IF;
    ELSIF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION 'Ledger master identity is immutable';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_event_stamp() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    book ledger_books%ROWTYPE;
BEGIN
    SELECT * INTO STRICT book FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id FOR UPDATE;
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id, (to_jsonb(NEW)->>'reverses_id') IS NOT NULL OR coalesce((to_jsonb(NEW)->>'closing')::boolean, false));
    IF NOT EXISTS (SELECT 1 FROM ledger_periods WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
                   AND starts_on <= NEW.effective_on AND ends_on >= NEW.effective_on AND NOT closed) THEN
        RAISE EXCEPTION 'Posting requires an open accounting period';
    END IF;
    IF TG_TABLE_NAME = 'ledger_journals' AND NEW.sequence IS NOT NULL THEN
        IF NOT EXISTS (SELECT 1 FROM ledger_sequence_reservations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
                       AND through_sequence >= NEW.sequence AND after_sequence < NEW.sequence AND transaction_xid = pg_current_xact_id()::text::bigint) THEN
            RAISE EXCEPTION 'An explicit journal sequence must come from a reservation made by this transaction';
        END IF;
    ELSE
        UPDATE ledger_books SET next_sequence = next_sequence + 1 WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id
        RETURNING next_sequence INTO NEW.sequence;
    END IF;
    NEW.recorded_at := clock_timestamp();
    NEW.actor_id := current_setting('noria.ledger_actor');
    NEW.firm_id := nullif(current_setting('noria.ledger_firm', true), '');
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_sequence_reserve() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id);
    UPDATE ledger_books SET next_sequence = next_sequence + NEW.size WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id
    RETURNING next_sequence - NEW.size, next_sequence INTO NEW.after_sequence, NEW.through_sequence;
    NEW.transaction_xid := pg_current_xact_id()::text::bigint;
    NEW.recorded_at := clock_timestamp();
    NEW.actor_id := current_setting('noria.ledger_actor');
    NEW.firm_id := nullif(current_setting('noria.ledger_firm', true), '');
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_journal_post() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    book ledger_books%ROWTYPE;
    line jsonb;
    ordinal bigint;
    account ledger_accounts%ROWTYPE;
    source_scale integer;
    target_scale integer;
    expected numeric;
    balance numeric;
    original ledger_journals%ROWTYPE;
    opposite jsonb;
    item record;
    reversed_item uuid;
    dimension record;
    cash_delta numeric;
    classified numeric;
BEGIN
    SELECT * INTO STRICT book FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id;
    SELECT scale INTO STRICT target_scale FROM ledger_currencies WHERE tenant_id = NEW.tenant_id AND code = book.currency_code;
    IF NEW.reverses_id IS NOT NULL THEN
        SELECT * INTO STRICT original FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
        IF EXISTS (SELECT 1 FROM ledger_bank_matches m WHERE m.tenant_id = NEW.tenant_id AND m.book_id = NEW.book_id AND m.journal_id = original.id AND m.reverses_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM ledger_bank_matches r WHERE r.tenant_id = m.tenant_id AND r.book_id = m.book_id AND r.reverses_id = m.id)) THEN
            RAISE EXCEPTION 'Release bank matches before reversing a journal';
        END IF;
        IF original.reverses_id IS NOT NULL OR NEW.effective_on < original.effective_on OR NEW.closing <> original.closing THEN
            RAISE EXCEPTION 'Invalid journal reversal';
        END IF;
        SELECT jsonb_agg(jsonb_set(value, '{side}', to_jsonb(CASE WHEN value->>'side' = 'debit' THEN 'credit'::text ELSE 'debit'::text END)) ORDER BY ordinality)
        INTO opposite FROM jsonb_array_elements(original.postings) WITH ORDINALITY;
        IF NEW.postings <> opposite THEN
            RAISE EXCEPTION 'A reversal must exactly reverse every original posting';
        END IF;
        IF EXISTS (SELECT 1 FROM ledger_open_items oi JOIN ledger_allocations a ON a.tenant_id = oi.tenant_id AND a.book_id = oi.book_id
                   AND (a.debit_item_id = oi.id OR a.credit_item_id = oi.id)
                   WHERE oi.tenant_id = NEW.tenant_id AND oi.book_id = NEW.book_id AND oi.journal_id = original.id AND a.reverses_id IS NULL
                   AND NOT EXISTS (SELECT 1 FROM ledger_allocations r WHERE r.tenant_id = a.tenant_id AND r.book_id = a.book_id AND r.reverses_id = a.id)) THEN
            RAISE EXCEPTION 'Release active allocations before reversing a journal';
        END IF;
    END IF;
    FOR line, ordinal IN SELECT value, ordinality FROM jsonb_array_elements(NEW.postings) WITH ORDINALITY LOOP
        IF jsonb_typeof(line) <> 'object' OR jsonb_typeof(line->'dimensions') <> 'object'
           OR (line->>'amount_minor') !~ '^[0-9]+$' OR (line->>'functional_minor') !~ '^[0-9]+$'
           OR length(line->>'amount_minor') > 38 OR length(line->>'functional_minor') > 38
           OR (line->>'rate') !~ '^[0-9]+(\.[0-9]{1,18})?$' THEN
            RAISE EXCEPTION 'Malformed exact posting';
        END IF;
        SELECT * INTO STRICT account FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = (line->>'account_id')::uuid;
        IF account.is_group OR ((NOT account.active OR account.deleted_at IS NOT NULL) AND NEW.reverses_id IS NULL AND NOT NEW.closing)
           OR coalesce(account.currency_code, book.currency_code) <> line->>'currency_code' THEN
            RAISE EXCEPTION 'Account is inactive, soft deleted, a group, or has a different currency';
        END IF;
        IF NEW.closing AND account.type NOT IN ('income', 'expense', 'equity') THEN
            RAISE EXCEPTION 'Closing journals can only transfer earnings to equity';
        END IF;
        IF account.is_control AND line->>'party_id' IS NULL THEN
            RAISE EXCEPTION 'Control accounts require a party';
        END IF;
        IF line->>'party_id' IS NOT NULL THEN
            PERFORM 1 FROM ledger_parties WHERE tenant_id = NEW.tenant_id AND id = (line->>'party_id')::uuid AND entity_id = book.entity_id
                AND (deleted_at IS NULL OR NEW.reverses_id IS NOT NULL OR NEW.closing) FOR SHARE;
            IF NOT FOUND THEN RAISE EXCEPTION 'Party is soft deleted or belongs to a different entity'; END IF;
        END IF;
        FOR dimension IN SELECT key, value FROM jsonb_each(line->'dimensions') LOOP
            IF jsonb_typeof(dimension.value) <> 'string' THEN RAISE EXCEPTION 'Unknown accounting dimension'; END IF;
            PERFORM 1 FROM ledger_dimension_values WHERE tenant_id = NEW.tenant_id AND entity_id = book.entity_id
                AND ledger_dimension_values.dimension = dimension.key AND value = dimension.value #>> '{}'
                AND (deleted_at IS NULL OR NEW.reverses_id IS NOT NULL OR NEW.closing) FOR SHARE;
            IF NOT FOUND THEN RAISE EXCEPTION 'Unknown or soft-deleted accounting dimension'; END IF;
        END LOOP;
        IF NEW.reverses_id IS NULL AND NOT NEW.closing AND EXISTS (SELECT 1 FROM ledger_account_dimensions d WHERE d.tenant_id = NEW.tenant_id AND d.book_id = NEW.book_id
            AND d.account_id = account.id AND NOT (line->'dimensions' ? d.dimension)) THEN
            RAISE EXCEPTION 'A mandatory accounting dimension is missing';
        END IF;
        SELECT scale INTO STRICT source_scale FROM ledger_currencies WHERE tenant_id = NEW.tenant_id AND code = line->>'currency_code';
        IF (line->>'rate_as_of')::date > NEW.effective_on OR (line->>'rate')::numeric <= 0
           OR (line->>'currency_code' = book.currency_code AND (line->>'rate')::numeric <> 1) THEN
            RAISE EXCEPTION 'Invalid exchange rate evidence';
        END IF;
        IF NOT (line->>'valuation')::boolean THEN
            expected := ledger_fraction((line->>'amount_minor')::numeric * (line->>'rate')::numeric * power(10::numeric, target_scale),
                         power(10::numeric, source_scale), line->>'rounding');
            IF expected <> (line->>'functional_minor')::numeric THEN
                RAISE EXCEPTION 'Functional amount does not match the exchange rate';
            END IF;
        ELSIF line->>'currency_code' = book.currency_code THEN
            RAISE EXCEPTION 'Functional-only valuations require a foreign-currency account';
        END IF;
        INSERT INTO ledger_postings (tenant_id, book_id, journal_id, line_no, account_id, side, amount_minor, functional_minor,
            currency_code, rate, rate_as_of, rate_source, rounding, party_id, due_on, dimensions, description, valuation)
        VALUES (NEW.tenant_id, NEW.book_id, NEW.id, ordinal, account.id, line->>'side', (line->>'amount_minor')::numeric,
            (line->>'functional_minor')::numeric, line->>'currency_code', (line->>'rate')::numeric, (line->>'rate_as_of')::date,
            line->>'rate_source', line->>'rounding', (line->>'party_id')::uuid, (line->>'due_on')::date, line->'dimensions',
            line->>'description', (line->>'valuation')::boolean);
        IF account.is_control AND NOT (line->>'valuation')::boolean THEN
            INSERT INTO ledger_open_items (tenant_id, id, book_id, journal_id, line_no, party_id, effective_on, sequence)
            VALUES (NEW.tenant_id, uuidv7(), NEW.book_id, NEW.id, ordinal, (line->>'party_id')::uuid, NEW.effective_on, NEW.sequence);
        END IF;
    END LOOP;
    SELECT sum(CASE WHEN side = 'debit' THEN functional_minor ELSE -functional_minor END)
    INTO balance FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id;
    IF balance <> 0 THEN
        RAISE EXCEPTION 'Journal must balance exactly';
    END IF;
    IF NEW.metadata ? 'cash_flow' THEN
        IF jsonb_typeof(NEW.metadata->'cash_flow') <> 'object' THEN
            RAISE EXCEPTION 'Cash-flow allocation must be an object of minor-unit strings';
        END IF;
        classified := 0;
        FOR dimension IN SELECT key, value FROM jsonb_each(NEW.metadata->'cash_flow') LOOP
            IF dimension.key NOT IN ('operating', 'investing', 'financing', 'exchange') OR jsonb_typeof(dimension.value) <> 'string'
               OR (dimension.value #>> '{}') !~ '^-?[0-9]{1,38}$' THEN
                RAISE EXCEPTION 'Invalid cash-flow classification';
            END IF;
            classified := classified + (dimension.value #>> '{}')::numeric;
        END LOOP;
        SELECT coalesce(sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END), 0) INTO cash_delta
            FROM ledger_postings p JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
            WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.id AND a.cash_flow = 'cash';
        IF cash_delta <> classified THEN
            RAISE EXCEPTION 'Cash-flow classifications must equal the actual cash movement';
        END IF;
    END IF;
    IF NEW.reverses_id IS NOT NULL THEN
        FOR item IN SELECT oi.id, oi.line_no, p.side, p.amount_minor, p.functional_minor
            FROM ledger_open_items oi JOIN ledger_postings p USING (tenant_id, book_id, journal_id, line_no)
            WHERE oi.tenant_id = NEW.tenant_id AND oi.book_id = NEW.book_id AND oi.journal_id = NEW.reverses_id LOOP
            SELECT id INTO STRICT reversed_item FROM ledger_open_items WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
                AND journal_id = NEW.id AND line_no = item.line_no;
            INSERT INTO ledger_allocations (tenant_id, id, book_id, idempotency_key, fingerprint, debit_item_id, credit_item_id,
                amount_minor, debit_functional_minor, credit_functional_minor, effective_on, recorded_at, sequence, actor_id, reason)
            VALUES (NEW.tenant_id, uuidv7(), NEW.book_id, 'reversal:' || NEW.id || ':' || item.line_no,
                encode(sha256(NEW.id::text::bytea), 'hex'), CASE WHEN item.side = 'debit' THEN item.id ELSE reversed_item END,
                CASE WHEN item.side = 'credit' THEN item.id ELSE reversed_item END, item.amount_minor,
                item.functional_minor, item.functional_minor, NEW.effective_on, clock_timestamp(), 1, NEW.actor_id, 'Journal reversal');
        END LOOP;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_allocation_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    debit record;
    credit record;
    allocated numeric;
    functional_allocated numeric;
    expected_debit numeric;
    expected_credit numeric;
    original ledger_allocations%ROWTYPE;
    fx_balance numeric;
BEGIN
    SELECT p.*, j.effective_on AS posted_on INTO STRICT debit FROM ledger_open_items oi
        JOIN ledger_postings p USING (tenant_id, book_id, journal_id, line_no)
        JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
        WHERE oi.tenant_id = NEW.tenant_id AND oi.book_id = NEW.book_id AND oi.id = NEW.debit_item_id;
    SELECT p.*, j.effective_on AS posted_on INTO STRICT credit FROM ledger_open_items oi
        JOIN ledger_postings p USING (tenant_id, book_id, journal_id, line_no)
        JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
        WHERE oi.tenant_id = NEW.tenant_id AND oi.book_id = NEW.book_id AND oi.id = NEW.credit_item_id;
    IF debit.side <> 'debit' OR credit.side <> 'credit' OR debit.account_id <> credit.account_id
       OR debit.party_id IS DISTINCT FROM credit.party_id OR debit.currency_code <> credit.currency_code OR debit.dimensions <> credit.dimensions
       OR NEW.effective_on < greatest(debit.posted_on, credit.posted_on) THEN
        RAISE EXCEPTION 'Allocation requires compatible open items and a valid accounting date';
    END IF;
    IF EXISTS (SELECT 1 FROM ledger_allocations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
        AND (debit_item_id IN (NEW.debit_item_id, NEW.credit_item_id) OR credit_item_id IN (NEW.debit_item_id, NEW.credit_item_id))
        AND effective_on > NEW.effective_on) THEN
        RAISE EXCEPTION 'Allocation dates must follow the existing item history';
    END IF;
    IF NEW.reverses_id IS NOT NULL THEN
        SELECT * INTO STRICT original FROM ledger_allocations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
        IF original.reverses_id IS NOT NULL OR NEW.effective_on < original.effective_on
           OR (NEW.debit_item_id, NEW.credit_item_id, NEW.amount_minor, NEW.debit_functional_minor, NEW.credit_functional_minor)
              IS DISTINCT FROM (original.debit_item_id, original.credit_item_id, original.amount_minor, original.debit_functional_minor, original.credit_functional_minor) THEN
            RAISE EXCEPTION 'Allocation release must exactly reverse the original';
        END IF;
        IF (original.fx_journal_id IS NULL) <> (NEW.fx_journal_id IS NULL) THEN
            RAISE EXCEPTION 'Allocation release must preserve the FX linkage';
        END IF;
        IF original.fx_journal_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND id = NEW.fx_journal_id AND reverses_id = original.fx_journal_id) THEN
            RAISE EXCEPTION 'Allocation release must reverse the realized FX journal';
        END IF;
        RETURN NEW;
    END IF;
    SELECT coalesce(sum(CASE WHEN reverses_id IS NULL THEN amount_minor ELSE -amount_minor END), 0),
           coalesce(sum(CASE WHEN reverses_id IS NULL THEN debit_functional_minor ELSE -debit_functional_minor END), 0)
    INTO allocated, functional_allocated FROM ledger_allocations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND debit_item_id = NEW.debit_item_id;
    IF allocated + NEW.amount_minor > debit.amount_minor THEN
        RAISE EXCEPTION 'Allocation exceeds the debit outstanding';
    END IF;
    expected_debit := ledger_fraction(NEW.amount_minor * (debit.functional_minor - functional_allocated), debit.amount_minor - allocated, 'half_even');
    SELECT coalesce(sum(CASE WHEN reverses_id IS NULL THEN amount_minor ELSE -amount_minor END), 0),
           coalesce(sum(CASE WHEN reverses_id IS NULL THEN credit_functional_minor ELSE -credit_functional_minor END), 0)
    INTO allocated, functional_allocated FROM ledger_allocations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND credit_item_id = NEW.credit_item_id;
    IF allocated + NEW.amount_minor > credit.amount_minor THEN
        RAISE EXCEPTION 'Allocation exceeds the credit outstanding';
    END IF;
    expected_credit := ledger_fraction(NEW.amount_minor * (credit.functional_minor - functional_allocated), credit.amount_minor - allocated, 'half_even');
    IF NEW.debit_functional_minor <> expected_debit OR NEW.credit_functional_minor <> expected_credit THEN
        RAISE EXCEPTION 'Incorrect functional allocation';
    END IF;
    IF expected_debit <> expected_credit THEN
        IF NEW.fx_journal_id IS NULL THEN
            RAISE EXCEPTION 'A realized FX journal is required';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM ledger_journals j WHERE j.tenant_id = NEW.tenant_id AND j.book_id = NEW.book_id AND j.id = NEW.fx_journal_id
            AND j.effective_on = NEW.effective_on AND j.reverses_id IS NULL AND NOT j.closing AND jsonb_array_length(j.postings) = 2
            AND j.metadata->>'allocation_id' = NEW.id::text)
           OR NOT EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
            WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.fx_journal_id AND NOT p.valuation
            AND p.party_id IS NULL AND p.dimensions = debit.dimensions
            AND p.currency_code = (SELECT currency_code FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id)
            AND p.functional_minor = abs(expected_credit - expected_debit)
            AND a.type = CASE WHEN expected_credit > expected_debit THEN 'income' ELSE 'expense' END
            AND p.side = CASE WHEN expected_credit > expected_debit THEN 'credit' ELSE 'debit' END) THEN
            RAISE EXCEPTION 'Realized FX requires an attributable gain or loss journal';
        END IF;
        SELECT sum(CASE WHEN side = 'debit' THEN functional_minor ELSE -functional_minor END) INTO fx_balance
        FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.fx_journal_id
            AND account_id = debit.account_id AND party_id = debit.party_id AND currency_code = debit.currency_code AND dimensions = debit.dimensions AND valuation;
        IF fx_balance IS DISTINCT FROM expected_credit - expected_debit THEN
            RAISE EXCEPTION 'Realized FX journal does not clear the carrying-value difference';
        END IF;
    ELSIF NEW.fx_journal_id IS NOT NULL THEN
        RAISE EXCEPTION 'No FX journal is needed for equal carrying values';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_extension_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    book ledger_books%ROWTYPE;
    schedule ledger_schedules%ROWTYPE;
    line jsonb;
    previous_date date;
    total numeric := 0;
BEGIN
    SELECT * INTO STRICT book FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id FOR UPDATE;
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id, TG_TABLE_NAME = 'ledger_year_closures');
    IF TG_TABLE_NAME = 'ledger_schedules' THEN
        PERFORM ledger_require_live_dimensions(NEW.tenant_id, NEW.book_id, NEW.dimensions);
        IF EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND id IN (NEW.debit_account_id, NEW.credit_account_id)
             AND (is_group OR is_control OR deleted_at IS NOT NULL OR coalesce(currency_code, book.currency_code) <> book.currency_code)) THEN
            RAISE EXCEPTION 'Schedules require functional-currency posting accounts without open items';
        END IF;
        FOR line IN SELECT value FROM jsonb_array_elements(NEW.plan) LOOP
            IF jsonb_typeof(line) <> 'object' OR line->>'amount_minor' IS NULL OR (line->>'amount_minor') !~ '^[0-9]{1,38}$'
               OR line->>'due_on' IS NULL OR (line->>'due_on') !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
               OR (previous_date IS NOT NULL AND previous_date >= (line->>'due_on')::date) THEN
                RAISE EXCEPTION 'Invalid schedule plan';
            END IF;
            previous_date := (line->>'due_on')::date;
            total := total + (line->>'amount_minor')::numeric;
        END LOOP;
        IF total IS DISTINCT FROM NEW.amount_minor THEN
            RAISE EXCEPTION 'Schedule total does not match its lines';
        END IF;
    ELSIF TG_TABLE_NAME = 'ledger_schedule_runs' THEN
        SELECT * INTO STRICT schedule FROM ledger_schedules WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.schedule_id;
        line := schedule.plan->(NEW.line_no - 1);
        IF line IS NULL OR EXISTS (SELECT 1 FROM ledger_schedule_cancellations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND schedule_id = NEW.schedule_id)
           OR NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id
               AND effective_on = (line->>'due_on')::date AND source_type = 'schedule:' || NEW.schedule_id AND source_reference = NEW.line_no::text
               AND reverses_id IS NULL AND jsonb_array_length(postings) = 2)
           OR (SELECT count(*) FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id
               AND ((account_id = schedule.debit_account_id AND side = 'debit') OR (account_id = schedule.credit_account_id AND side = 'credit'))
               AND functional_minor = (line->>'amount_minor')::numeric AND amount_minor = functional_minor AND dimensions = schedule.dimensions) <> 2 THEN
            RAISE EXCEPTION 'Schedule run does not match its immutable plan';
        END IF;
    ELSIF TG_TABLE_NAME = 'ledger_year_closures' THEN
        IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.retained_account_id AND type = 'equity' AND NOT is_group)
           OR (NEW.journal_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id AND closing AND reverses_id IS NULL AND effective_on = NEW.ends_on)) THEN
            RAISE EXCEPTION 'Invalid fiscal closure';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM ledger_periods WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND starts_on >= NEW.starts_on AND ends_on <= NEW.ends_on
            HAVING min(starts_on) = NEW.starts_on AND max(ends_on) = NEW.ends_on AND sum(ends_on - starts_on + 1) = NEW.ends_on - NEW.starts_on + 1) THEN
            RAISE EXCEPTION 'Fiscal closure requires complete contiguous accounting periods';
        END IF;
        IF EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
            JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
            WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND a.type IN ('income', 'expense') AND j.effective_on < NEW.starts_on
            GROUP BY p.account_id, p.dimensions HAVING sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END) <> 0)
            OR EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
                JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
                WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND a.type IN ('income', 'expense') AND j.effective_on BETWEEN NEW.starts_on AND NEW.ends_on
                GROUP BY p.account_id, p.dimensions HAVING sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END) <> 0)
            OR EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
                WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.journal_id AND a.type = 'equity' AND p.account_id <> NEW.retained_account_id) THEN
            RAISE EXCEPTION 'Fiscal closure must clear earnings by account and dimension into its retained earnings account';
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_evidence_stamp() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    PERFORM 1 FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id FOR UPDATE;
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id, TG_TABLE_NAME IN ('ledger_statement_runs', 'ledger_consolidation_runs') OR (to_jsonb(NEW)->>'reverses_id') IS NOT NULL);
    UPDATE ledger_books SET next_sequence = next_sequence + 1 WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id RETURNING next_sequence INTO NEW.sequence;
    NEW.recorded_at := clock_timestamp();
    NEW.actor_id := current_setting('noria.ledger_actor');
    NEW.firm_id := nullif(current_setting('noria.ledger_firm', true), '');
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_bank_import() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    line jsonb;
    ordinal bigint;
    total numeric := NEW.opening_minor;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.account_id
        AND cash_flow = 'cash' AND type = 'asset' AND NOT is_group AND NOT is_control) THEN
        RAISE EXCEPTION 'Statements require a bank or cash asset account';
    END IF;
    IF EXISTS (SELECT 1 FROM ledger_bank_statements s WHERE s.tenant_id = NEW.tenant_id AND s.book_id = NEW.book_id AND s.account_id = NEW.account_id
        AND s.id <> NEW.id AND s.starts_on <= NEW.effective_on AND s.effective_on >= NEW.starts_on
        AND NOT EXISTS (SELECT 1 FROM ledger_bank_voids v WHERE v.tenant_id = s.tenant_id AND v.book_id = s.book_id AND v.statement_id = s.id)) THEN
        RAISE EXCEPTION 'Active bank statement ranges cannot overlap';
    END IF;
    FOR line, ordinal IN SELECT value, ordinality FROM jsonb_array_elements(NEW.movements) WITH ORDINALITY LOOP
        IF (line->>'amount_minor') !~ '^-?[0-9]{1,38}$' OR (line->>'value_on')::date NOT BETWEEN NEW.starts_on AND NEW.effective_on
           OR line->>'external_id' IS NULL OR length(btrim(line->>'external_id')) = 0 THEN
            RAISE EXCEPTION 'Malformed bank movement';
        END IF;
        IF EXISTS (SELECT 1 FROM ledger_bank_lines bl WHERE bl.tenant_id = NEW.tenant_id AND bl.book_id = NEW.book_id AND bl.account_id = NEW.account_id
            AND bl.external_id = line->>'external_id' AND NOT EXISTS (SELECT 1 FROM ledger_bank_voids v
                WHERE v.tenant_id = bl.tenant_id AND v.book_id = bl.book_id AND v.statement_id = bl.statement_id)) THEN
            RAISE EXCEPTION 'Bank movement was already imported';
        END IF;
        INSERT INTO ledger_bank_lines (tenant_id, id, book_id, statement_id, account_id, line_no, external_id, value_on, amount_minor, description)
        VALUES (NEW.tenant_id, uuidv7(), NEW.book_id, NEW.id, NEW.account_id, ordinal, line->>'external_id',
            (line->>'value_on')::date, (line->>'amount_minor')::numeric, line->>'description');
        total := total + (line->>'amount_minor')::numeric;
    END LOOP;
    IF total <> NEW.closing_minor THEN
        RAISE EXCEPTION 'Bank statement does not reconcile to its closing balance';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_bank_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    bank_line ledger_bank_lines%ROWTYPE;
    posting ledger_postings%ROWTYPE;
    original ledger_bank_matches%ROWTYPE;
    matched numeric;
    posted_on date;
BEGIN
    IF TG_TABLE_NAME = 'ledger_bank_voids' THEN
        IF NEW.effective_on < (SELECT effective_on FROM ledger_bank_statements WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.statement_id)
            OR EXISTS (SELECT 1 FROM ledger_bank_matches m JOIN ledger_bank_lines bl ON bl.tenant_id = m.tenant_id AND bl.book_id = m.book_id AND bl.id = m.bank_line_id
                WHERE bl.tenant_id = NEW.tenant_id AND bl.book_id = NEW.book_id AND bl.statement_id = NEW.statement_id AND m.effective_on > NEW.effective_on) THEN
            RAISE EXCEPTION 'Statement voids must follow the statement closing date and match history';
        END IF;
        IF EXISTS (SELECT 1 FROM ledger_bank_lines bl JOIN ledger_bank_matches m ON m.tenant_id = bl.tenant_id AND m.book_id = bl.book_id AND m.bank_line_id = bl.id
            WHERE bl.tenant_id = NEW.tenant_id AND bl.book_id = NEW.book_id AND bl.statement_id = NEW.statement_id AND m.reverses_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM ledger_bank_matches r WHERE r.tenant_id = m.tenant_id AND r.book_id = m.book_id AND r.reverses_id = m.id)) THEN
            RAISE EXCEPTION 'Release bank matches before voiding a statement';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO STRICT bank_line FROM ledger_bank_lines WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.bank_line_id;
    SELECT * INTO STRICT posting FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id AND line_no = NEW.line_no;
    SELECT effective_on INTO STRICT posted_on FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id;
    IF bank_line.account_id <> posting.account_id OR sign(bank_line.amount_minor) <> (CASE WHEN posting.side = 'debit' THEN 1 ELSE -1 END)
       OR NEW.effective_on < greatest(bank_line.value_on, posted_on)
       OR EXISTS (SELECT 1 FROM ledger_bank_voids WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND statement_id = bank_line.statement_id) THEN
        RAISE EXCEPTION 'Bank match has incompatible account, direction, date, or statement';
    END IF;
    IF EXISTS (SELECT 1 FROM ledger_bank_matches WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND effective_on > NEW.effective_on
        AND (bank_line_id = NEW.bank_line_id OR (journal_id = NEW.journal_id AND line_no = NEW.line_no))) THEN
        RAISE EXCEPTION 'Bank match dates must follow existing history';
    END IF;
    IF NEW.reverses_id IS NOT NULL THEN
        SELECT * INTO STRICT original FROM ledger_bank_matches WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
        IF original.reverses_id IS NOT NULL OR (NEW.bank_line_id, NEW.journal_id, NEW.line_no, NEW.amount_minor)
            IS DISTINCT FROM (original.bank_line_id, original.journal_id, original.line_no, original.amount_minor) THEN
            RAISE EXCEPTION 'Bank match release must exactly reverse the original';
        END IF;
        RETURN NEW;
    END IF;
    SELECT coalesce(sum(CASE WHEN reverses_id IS NULL THEN amount_minor ELSE -amount_minor END), 0) INTO matched
        FROM ledger_bank_matches WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND bank_line_id = NEW.bank_line_id;
    IF matched + NEW.amount_minor > abs(bank_line.amount_minor) THEN
        RAISE EXCEPTION 'Match exceeds the bank movement';
    END IF;
    SELECT coalesce(sum(CASE WHEN reverses_id IS NULL THEN amount_minor ELSE -amount_minor END), 0) INTO matched
        FROM ledger_bank_matches WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id AND line_no = NEW.line_no;
    IF matched + NEW.amount_minor > posting.amount_minor THEN
        RAISE EXCEPTION 'Match exceeds the accounting posting';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_batch_record() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    member jsonb;
    original ledger_batches%ROWTYPE;
BEGIN
    IF (SELECT count(DISTINCT value->>'book_id') FROM jsonb_array_elements(NEW.members)) < 2 THEN
        RAISE EXCEPTION 'A batch requires distinct accounting books';
    END IF;
    IF NEW.reverses_id IS NOT NULL THEN
        SELECT * INTO STRICT original FROM ledger_batches WHERE tenant_id = NEW.tenant_id AND id = NEW.reverses_id;
        IF original.reverses_id IS NOT NULL OR jsonb_array_length(original.members) <> jsonb_array_length(NEW.members) THEN
            RAISE EXCEPTION 'Invalid batch reversal';
        END IF;
        IF EXISTS (SELECT 1 FROM jsonb_array_elements(original.members) old_member WHERE NOT EXISTS (
            SELECT 1 FROM jsonb_array_elements(NEW.members) new_member JOIN ledger_journals j ON j.tenant_id = NEW.tenant_id
                AND j.book_id = (new_member->>'book_id')::uuid AND j.id = (new_member->>'journal_id')::uuid
                WHERE j.reverses_id = (old_member->>'journal_id')::uuid AND j.book_id = (old_member->>'book_id')::uuid)) THEN
            RAISE EXCEPTION 'Every batch member must be reversed';
        END IF;
    END IF;
    FOR member IN SELECT value FROM jsonb_array_elements(NEW.members) LOOP
        INSERT INTO ledger_batch_members (tenant_id, batch_id, book_id, journal_id)
        VALUES (NEW.tenant_id, NEW.id, (member->>'book_id')::uuid, (member->>'journal_id')::uuid);
    END LOOP;
    RETURN NEW;
END;
$body$;

DO $body$
DECLARE
    table_name text;
BEGIN
    FOREACH table_name IN ARRAY ARRAY['ledger_currencies', 'ledger_entities', 'ledger_books', 'ledger_accounts', 'ledger_parties',
        'ledger_periods', 'ledger_dimension_values', 'ledger_account_dimensions', 'ledger_sequence_reservations', 'ledger_journals', 'ledger_postings',
        'ledger_open_items', 'ledger_allocations', 'ledger_audit_events', 'ledger_schedules', 'ledger_schedule_runs', 'ledger_schedule_cancellations', 'ledger_year_closures', 'ledger_bank_statements', 'ledger_bank_lines', 'ledger_bank_matches', 'ledger_bank_voids', 'ledger_batches', 'ledger_batch_members'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', table_name);
        EXECUTE format('CREATE POLICY ledger_tenant ON %I USING (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid) WITH CHECK (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid)', table_name);
        EXECUTE format('CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard()', table_name);
        EXECUTE format('CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable()', table_name);
        IF table_name IN ('ledger_sequence_reservations', 'ledger_journals', 'ledger_postings', 'ledger_open_items', 'ledger_allocations', 'ledger_audit_events', 'ledger_schedules', 'ledger_schedule_runs', 'ledger_schedule_cancellations', 'ledger_year_closures', 'ledger_bank_statements', 'ledger_bank_lines', 'ledger_bank_matches', 'ledger_bank_voids', 'ledger_batches', 'ledger_batch_members') THEN
            EXECUTE format('CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_immutable()', table_name);
        ELSE
            EXECUTE format('CREATE TRIGGER stage_20_master BEFORE INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_master_guard()', table_name);
        END IF;
        IF table_name NOT IN ('ledger_postings', 'ledger_open_items', 'ledger_audit_events', 'ledger_bank_lines', 'ledger_batch_members') THEN
            EXECUTE format('CREATE TRIGGER stage_90_audit AFTER INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_audit()', table_name);
        END IF;
    END LOOP;
END;
$body$;

CREATE TRIGGER stage_20_derived BEFORE INSERT ON ledger_postings FOR EACH ROW EXECUTE FUNCTION ledger_derived_insert();
CREATE TRIGGER stage_20_derived BEFORE INSERT ON ledger_open_items FOR EACH ROW EXECUTE FUNCTION ledger_derived_insert();
CREATE TRIGGER stage_20_derived BEFORE INSERT ON ledger_audit_events FOR EACH ROW EXECUTE FUNCTION ledger_derived_insert();
CREATE TRIGGER stage_30_reserve BEFORE INSERT ON ledger_sequence_reservations FOR EACH ROW EXECUTE FUNCTION ledger_sequence_reserve();
CREATE TRIGGER stage_30_stamp BEFORE INSERT ON ledger_journals FOR EACH ROW EXECUTE FUNCTION ledger_event_stamp();
CREATE TRIGGER stage_30_stamp BEFORE INSERT ON ledger_allocations FOR EACH ROW EXECUTE FUNCTION ledger_event_stamp();
CREATE TRIGGER stage_40_validate BEFORE INSERT ON ledger_allocations FOR EACH ROW EXECUTE FUNCTION ledger_allocation_validate();
CREATE TRIGGER stage_80_project_postings AFTER INSERT ON ledger_journals FOR EACH ROW EXECUTE FUNCTION ledger_journal_post();

CREATE TRIGGER stage_31_validate BEFORE INSERT ON ledger_schedules FOR EACH ROW EXECUTE FUNCTION ledger_extension_validate();
CREATE TRIGGER stage_31_validate BEFORE INSERT ON ledger_schedule_runs FOR EACH ROW EXECUTE FUNCTION ledger_extension_validate();
CREATE TRIGGER stage_31_validate BEFORE INSERT ON ledger_schedule_cancellations FOR EACH ROW EXECUTE FUNCTION ledger_extension_validate();
CREATE TRIGGER stage_31_validate BEFORE INSERT ON ledger_year_closures FOR EACH ROW EXECUTE FUNCTION ledger_extension_validate();

CREATE TRIGGER stage_20_derived BEFORE INSERT ON ledger_bank_lines FOR EACH ROW EXECUTE FUNCTION ledger_derived_insert();
CREATE TRIGGER stage_30_stamp BEFORE INSERT ON ledger_bank_statements FOR EACH ROW EXECUTE FUNCTION ledger_evidence_stamp();
CREATE TRIGGER stage_30_stamp BEFORE INSERT ON ledger_bank_matches FOR EACH ROW EXECUTE FUNCTION ledger_evidence_stamp();
CREATE TRIGGER stage_30_stamp BEFORE INSERT ON ledger_bank_voids FOR EACH ROW EXECUTE FUNCTION ledger_evidence_stamp();
CREATE TRIGGER stage_40_validate BEFORE INSERT ON ledger_bank_matches FOR EACH ROW EXECUTE FUNCTION ledger_bank_validate();
CREATE TRIGGER stage_40_validate BEFORE INSERT ON ledger_bank_voids FOR EACH ROW EXECUTE FUNCTION ledger_bank_validate();
CREATE TRIGGER stage_80_project_bank_lines AFTER INSERT ON ledger_bank_statements FOR EACH ROW EXECUTE FUNCTION ledger_bank_import();

CREATE TRIGGER stage_20_derived BEFORE INSERT ON ledger_batch_members FOR EACH ROW EXECUTE FUNCTION ledger_derived_insert();
CREATE TRIGGER stage_80_project_batch_members AFTER INSERT ON ledger_batches FOR EACH ROW EXECUTE FUNCTION ledger_batch_record();

CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON ledger_currency_definitions FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON ledger_currency_definitions FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable();

ALTER TABLE ledger_configurations
    ADD CHECK (length(btrim(name)) > 0), ADD CHECK (length(btrim(version)) > 0),
    ADD CHECK (jsonb_typeof(account_ids) = 'object'), ADD CHECK (jsonb_typeof(roles) = 'object'),
    ADD CHECK (jsonb_typeof(definition_json) = 'object'),
    ADD CHECK (definition_hash = encode(sha256(convert_to(definition_json::text, 'UTF8')), 'hex'));
ALTER TABLE ledger_statement_runs
    ADD CHECK (length(btrim(idempotency_key)) > 0), ADD CHECK (fingerprint ~ '^[a-f0-9]{64}$'),
    ADD CHECK (jsonb_typeof(result_json) = 'object'),
    ADD CHECK (result_hash = encode(sha256(convert_to(result_json::text, 'UTF8')), 'hex'));

CREATE OR REPLACE FUNCTION ledger_configuration_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE definition jsonb := NEW.definition_json; entry record; country_code text; role_definitions jsonb;
BEGIN
    IF NOT (definition ?& ARRAY['name', 'version', 'accounts', 'roles', 'tax_rules', 'statements', 'settings', 'valid_from', 'valid_until', 'country'])
        OR definition->>'name' IS DISTINCT FROM NEW.name OR definition->>'version' IS DISTINCT FROM NEW.version
        OR jsonb_typeof(definition->'accounts') <> 'array' OR (jsonb_typeof(definition->'roles') <> 'object' AND definition->'roles' <> '[]'::jsonb)
        OR jsonb_typeof(definition->'tax_rules') <> 'array' OR jsonb_typeof(definition->'statements') <> 'array'
        OR (definition->>'valid_from')::date IS NULL
        OR (definition->>'valid_until')::date < (definition->>'valid_from')::date THEN
        RAISE EXCEPTION 'Invalid versioned configuration definition';
    END IF;
    SELECT e.country INTO STRICT country_code FROM ledger_entities e JOIN ledger_books b ON b.tenant_id = e.tenant_id AND b.entity_id = e.id
        WHERE b.tenant_id = NEW.tenant_id AND b.id = NEW.book_id;
    IF definition->>'country' IS NOT NULL AND definition->>'country' <> country_code THEN RAISE EXCEPTION 'Configuration country differs from the legal entity'; END IF;
    FOR entry IN SELECT key, value FROM jsonb_each_text(NEW.account_ids) LOOP
        IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = entry.value::uuid AND code = entry.key) THEN
            RAISE EXCEPTION 'Configuration accounts must belong to the same book and code';
        END IF;
    END LOOP;
    role_definitions := CASE WHEN definition->'roles' = '[]'::jsonb THEN '{}'::jsonb ELSE definition->'roles' END;
    IF (SELECT count(*) FROM jsonb_each(NEW.roles)) <> (SELECT count(*) FROM jsonb_each(role_definitions)) THEN
        RAISE EXCEPTION 'Configuration roles do not match the definition';
    END IF;
    FOR entry IN SELECT key, value FROM jsonb_each_text(role_definitions) LOOP
        IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = (NEW.roles->>entry.key)::uuid
            AND code = entry.value AND NOT is_group) THEN RAISE EXCEPTION 'Configuration roles require matching posting accounts in the same book'; END IF;
    END LOOP;
    RETURN NEW;
END;
$body$;

DO $body$
DECLARE table_name text;
BEGIN
    FOREACH table_name IN ARRAY ARRAY['ledger_configurations', 'ledger_statement_runs'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', table_name);
        EXECUTE format('CREATE POLICY ledger_tenant ON %I USING (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid) WITH CHECK (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid)', table_name);
        EXECUTE format('CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard()', table_name);
        EXECUTE format('CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_immutable()', table_name);
        EXECUTE format('CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable()', table_name);
        EXECUTE format('CREATE TRIGGER stage_30_stamp BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_evidence_stamp()', table_name);
        EXECUTE format('CREATE TRIGGER stage_90_audit AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_audit()', table_name);
    END LOOP;
END;
$body$;

CREATE TRIGGER stage_40_validate BEFORE INSERT ON ledger_configurations FOR EACH ROW EXECUTE FUNCTION ledger_configuration_validate();

ALTER TABLE ledger_events ADD CHECK (sequence > 0);

CREATE OR REPLACE FUNCTION ledger_event_project() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE book uuid; next_event bigint;
BEGIN
    book := CASE WHEN NEW.table_name = 'ledger_books' THEN (NEW.after_value->>'id')::uuid ELSE (NEW.after_value->>'book_id')::uuid END;
    IF book IS NULL OR (NEW.table_name = 'ledger_books' AND NEW.operation = 'UPDATE'
        AND (NEW.before_value - 'next_sequence') = (NEW.after_value - 'next_sequence')) THEN RETURN NEW; END IF;
    PERFORM 1 FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = book FOR UPDATE;
    SELECT coalesce(max(sequence), 0) + 1 INTO next_event FROM ledger_events WHERE tenant_id = NEW.tenant_id AND book_id = book;
    INSERT INTO ledger_events (tenant_id, book_id, sequence, audit_id) VALUES (NEW.tenant_id, book, next_event, NEW.id);
    RETURN NEW;
END;
$body$;

ALTER TABLE ledger_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger_events FORCE ROW LEVEL SECURITY;
CREATE POLICY ledger_tenant ON ledger_events USING (tenant_id = nullif(current_setting('noria.ledger_tenant', true), '')::uuid)
    WITH CHECK (tenant_id = nullif(current_setting('noria.ledger_tenant', true), '')::uuid);
CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON ledger_events FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard();
CREATE TRIGGER stage_20_derived BEFORE INSERT ON ledger_events FOR EACH ROW EXECUTE FUNCTION ledger_derived_insert();
CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON ledger_events FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON ledger_events FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER stage_80_project_events AFTER INSERT ON ledger_audit_events FOR EACH ROW EXECUTE FUNCTION ledger_event_project();

ALTER TABLE ledger_exchange_rates
    ADD CHECK (base_code <> quote_code), ADD CHECK (rate_type IN ('spot', 'closing', 'average', 'historical')),
    ADD CHECK (revision > 0), ADD CHECK (rate > 0 AND rate = trunc(rate, 18) AND rate < power(10::numeric, 20)),
    ADD CHECK (length(btrim(source)) > 0), ADD CHECK ((revision = 1) = (reason IS NULL)),
    ADD CHECK (reason IS NULL OR length(btrim(reason)) > 0);

CREATE OR REPLACE FUNCTION ledger_exchange_rate_stamp() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended(NEW.tenant_id::text || NEW.base_code || NEW.quote_code || NEW.rate_type || NEW.effective_on::text, 0));
    SELECT coalesce(max(revision), 0) + 1 INTO NEW.revision FROM ledger_exchange_rates WHERE tenant_id = NEW.tenant_id
        AND base_code = NEW.base_code AND quote_code = NEW.quote_code AND rate_type = NEW.rate_type AND effective_on = NEW.effective_on;
    NEW.recorded_at := clock_timestamp();
    NEW.actor_id := current_setting('noria.ledger_actor');
    NEW.firm_id := nullif(current_setting('noria.ledger_firm', true), '');
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_posting_rate_evidence() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE stored ledger_exchange_rates%ROWTYPE; functional_code text; reversal boolean;
BEGIN
    IF NEW.rate_source NOT LIKE 'ledger-rate:%' THEN
        RETURN NEW;
    END IF;
    IF NEW.rate_source !~ '^ledger-rate:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN
        RAISE EXCEPTION 'Stored exchange-rate evidence does not match the posting';
    END IF;
    SELECT * INTO stored FROM ledger_exchange_rates WHERE tenant_id = NEW.tenant_id AND id = substr(NEW.rate_source, 13)::uuid;
    SELECT currency_code INTO STRICT functional_code FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id;
    SELECT reverses_id IS NOT NULL INTO STRICT reversal FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id;
    IF stored.id IS NULL OR stored.base_code <> NEW.currency_code OR stored.quote_code <> functional_code
       OR stored.rate <> NEW.rate OR stored.effective_on <> NEW.rate_as_of THEN
        RAISE EXCEPTION 'Stored exchange-rate evidence does not match the posting';
    END IF;
    IF NOT reversal AND EXISTS (SELECT 1 FROM ledger_exchange_rates WHERE tenant_id = stored.tenant_id AND base_code = stored.base_code
        AND quote_code = stored.quote_code AND rate_type = stored.rate_type AND effective_on = stored.effective_on AND revision > stored.revision) THEN
        RAISE EXCEPTION 'A corrected exchange rate cannot be used for new postings';
    END IF;
    RETURN NEW;
END;
$body$;

ALTER TABLE ledger_exchange_rates ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger_exchange_rates FORCE ROW LEVEL SECURITY;
CREATE POLICY ledger_tenant ON ledger_exchange_rates USING (tenant_id = nullif(current_setting('noria.ledger_tenant', true), '')::uuid)
    WITH CHECK (tenant_id = nullif(current_setting('noria.ledger_tenant', true), '')::uuid);
CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON ledger_exchange_rates FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard();
CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON ledger_exchange_rates FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON ledger_exchange_rates FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER stage_30_stamp BEFORE INSERT ON ledger_exchange_rates FOR EACH ROW EXECUTE FUNCTION ledger_exchange_rate_stamp();
CREATE TRIGGER stage_90_audit AFTER INSERT ON ledger_exchange_rates FOR EACH ROW EXECUTE FUNCTION ledger_audit();
CREATE TRIGGER stage_40_rate_evidence BEFORE INSERT ON ledger_postings FOR EACH ROW EXECUTE FUNCTION ledger_posting_rate_evidence();

ALTER TABLE ledger_recurring_journals
    ADD CHECK (fingerprint ~ '^[a-f0-9]{64}$'), ADD CHECK (length(btrim(description)) > 0),
    ADD CHECK (jsonb_typeof(postings) = 'array' AND jsonb_array_length(postings) BETWEEN 2 AND 10000),
    ADD CHECK (jsonb_typeof(metadata) = 'object'), ADD CHECK (rate_type IN ('spot', 'closing', 'average', 'historical')),
    ADD CHECK (unit IN ('day', 'week', 'month', 'year')), ADD CHECK (every BETWEEN 1 AND 1000),
    ADD CHECK (ends_on IS NULL OR ends_on >= starts_on), ADD CHECK (occurrences IS NULL OR occurrences BETWEEN 1 AND 100000),
    ADD CHECK (due_after_days IS NULL OR due_after_days BETWEEN 0 AND 3660);
ALTER TABLE ledger_recurring_runs ADD CHECK (occurrence >= 0), ADD CHECK (kind IN ('post', 'reverse'));
ALTER TABLE ledger_recurring_cancellations ADD CHECK (length(btrim(reason)) > 0);

CREATE OR REPLACE FUNCTION ledger_recurring_date(starts date, unit text, every integer, occurrence integer) RETURNS date
LANGUAGE plpgsql SET search_path FROM CURRENT IMMUTABLE STRICT AS $body$
DECLARE month_start date;
BEGIN
    IF unit IN ('day', 'week') THEN
        RETURN starts + occurrence * every * CASE WHEN unit = 'week' THEN 7 ELSE 1 END;
    END IF;
    month_start := (date_trunc('month', starts::timestamp) + make_interval(months => occurrence * every * CASE WHEN unit = 'year' THEN 12 ELSE 1 END))::date;
    RETURN month_start + least(extract(day FROM starts)::integer, extract(day FROM (month_start + interval '1 month' - interval '1 day'))::integer) - 1;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_recurring_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    book ledger_books%ROWTYPE;
    template ledger_recurring_journals%ROWTYPE;
    line jsonb;
    ordinal bigint;
    occurs_on date;
    posted ledger_journals%ROWTYPE;
    original ledger_journals%ROWTYPE;
BEGIN
    SELECT * INTO STRICT book FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id FOR UPDATE;
    IF TG_TABLE_NAME = 'ledger_recurring_journals' THEN
        PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id);
        FOR line IN SELECT value FROM jsonb_array_elements(NEW.postings) LOOP
            IF jsonb_typeof(line) <> 'object' OR line->>'side' NOT IN ('debit', 'credit') OR (line->>'amount_minor') !~ '^[1-9][0-9]{0,37}$'
               OR jsonb_typeof(line->'dimensions') <> 'object' OR NOT EXISTS (SELECT 1 FROM ledger_accounts a WHERE a.tenant_id = NEW.tenant_id
                   AND a.book_id = NEW.book_id AND a.id = (line->>'account_id')::uuid AND NOT a.is_group AND a.deleted_at IS NULL
                   AND coalesce(a.currency_code, book.currency_code) = line->>'currency_code' AND (NOT a.is_control OR line->>'party_id' IS NOT NULL)) THEN
                RAISE EXCEPTION 'Recurring journal templates require positive lines on live posting accounts';
            END IF;
            PERFORM ledger_require_live_dimensions(NEW.tenant_id, NEW.book_id, line->'dimensions');
        END LOOP;
        RETURN NEW;
    END IF;
    SELECT * INTO STRICT template FROM ledger_recurring_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.recurring_id;
    IF TG_TABLE_NAME = 'ledger_recurring_cancellations' THEN
        IF NEW.effective_on < template.starts_on THEN
            RAISE EXCEPTION 'A recurring journal cannot be cancelled before it starts';
        END IF;
        IF EXISTS (SELECT 1 FROM ledger_recurring_runs r JOIN ledger_journals j ON j.tenant_id = r.tenant_id AND j.book_id = r.book_id AND j.id = r.journal_id
            WHERE r.tenant_id = NEW.tenant_id AND r.book_id = NEW.book_id AND r.recurring_id = NEW.recurring_id AND r.kind = 'post' AND j.effective_on >= NEW.effective_on) THEN
            RAISE EXCEPTION 'Reverse occurrences on or after the cancellation date first';
        END IF;
        RETURN NEW;
    END IF;
    occurs_on := ledger_recurring_date(template.starts_on, template.unit, template.every, NEW.occurrence);
    IF (template.occurrences IS NOT NULL AND NEW.occurrence >= template.occurrences) OR (template.ends_on IS NOT NULL AND occurs_on > template.ends_on)
       OR EXISTS (SELECT 1 FROM ledger_recurring_cancellations c WHERE c.tenant_id = NEW.tenant_id AND c.book_id = NEW.book_id
           AND c.recurring_id = NEW.recurring_id AND c.effective_on <= occurs_on AND NEW.kind = 'post') THEN
        RAISE EXCEPTION 'Recurring occurrence is outside its active schedule';
    END IF;
    SELECT * INTO STRICT posted FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id;
    IF NEW.kind = 'reverse' THEN
        SELECT j.* INTO original FROM ledger_recurring_runs r JOIN ledger_journals j ON j.tenant_id = r.tenant_id AND j.book_id = r.book_id AND j.id = r.journal_id
            WHERE r.tenant_id = NEW.tenant_id AND r.book_id = NEW.book_id AND r.recurring_id = NEW.recurring_id AND r.occurrence = NEW.occurrence AND r.kind = 'post';
        IF NOT template.auto_reverse OR original.id IS NULL OR posted.reverses_id IS DISTINCT FROM original.id
           OR posted.effective_on <> (SELECT ends_on + 1 FROM ledger_periods WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
               AND starts_on <= original.effective_on AND ends_on >= original.effective_on) THEN
            RAISE EXCEPTION 'Automatic reversals must reverse their occurrence on the first day after its period';
        END IF;
        RETURN NEW;
    END IF;
    IF posted.effective_on <> occurs_on OR posted.reverses_id IS NOT NULL OR posted.closing
       OR posted.source_type IS DISTINCT FROM 'recurring:' || NEW.recurring_id OR posted.source_reference IS DISTINCT FROM NEW.occurrence::text
       OR jsonb_array_length(posted.postings) <> jsonb_array_length(template.postings) THEN
        RAISE EXCEPTION 'Recurring occurrence does not match its immutable template';
    END IF;
    FOR line, ordinal IN SELECT value, ordinality FROM jsonb_array_elements(template.postings) WITH ORDINALITY LOOP
        IF NOT EXISTS (SELECT 1 FROM ledger_postings p LEFT JOIN ledger_exchange_rates x ON p.rate_source LIKE 'ledger-rate:%'
                AND x.tenant_id = p.tenant_id AND x.id = substr(p.rate_source, 13)::uuid
            WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.journal_id AND p.line_no = ordinal
            AND p.account_id = (line->>'account_id')::uuid AND p.side = line->>'side' AND p.amount_minor = (line->>'amount_minor')::numeric
            AND p.currency_code = line->>'currency_code' AND p.party_id IS NOT DISTINCT FROM (line->>'party_id')::uuid
            AND p.dimensions = line->'dimensions' AND p.description = line->>'description' AND NOT p.valuation
            AND p.due_on IS NOT DISTINCT FROM CASE WHEN line->>'party_id' IS NOT NULL AND template.due_after_days IS NOT NULL THEN occurs_on + template.due_after_days END
            AND (p.currency_code = book.currency_code OR x.rate_type = template.rate_type)) THEN
            RAISE EXCEPTION 'Recurring occurrence does not match its immutable template';
        END IF;
    END LOOP;
    RETURN NEW;
END;
$body$;

DO $body$
DECLARE table_name text;
BEGIN
    FOREACH table_name IN ARRAY ARRAY['ledger_recurring_journals', 'ledger_recurring_runs', 'ledger_recurring_cancellations'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', table_name);
        EXECUTE format('CREATE POLICY ledger_tenant ON %I USING (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid) WITH CHECK (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid)', table_name);
        EXECUTE format('CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard()', table_name);
        EXECUTE format('CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable()', table_name);
        EXECUTE format('CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_immutable()', table_name);
        EXECUTE format('CREATE TRIGGER stage_31_validate BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_recurring_validate()', table_name);
        EXECUTE format('CREATE TRIGGER stage_90_audit AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_audit()', table_name);
    END LOOP;
END;
$body$;
CREATE TRIGGER stage_30_stamp BEFORE INSERT ON ledger_recurring_journals FOR EACH ROW EXECUTE FUNCTION ledger_evidence_stamp();
CREATE TRIGGER stage_30_stamp BEFORE INSERT ON ledger_recurring_cancellations FOR EACH ROW EXECUTE FUNCTION ledger_evidence_stamp();

ALTER TABLE ledger_write_offs
    ADD CHECK (fingerprint ~ '^[a-f0-9]{64}$'), ADD CHECK (amount_minor > 0), ADD CHECK (length(btrim(reason)) > 0), ADD CHECK (sequence > 0);
ALTER TABLE ledger_allowance_runs
    ADD CHECK (fingerprint ~ '^[a-f0-9]{64}$'), ADD CHECK (length(btrim(reason)) > 0),
    ADD CHECK (jsonb_typeof(matrix) = 'object'), ADD CHECK (jsonb_typeof(exposure) = 'object'),
    ADD CHECK (required_minor = trunc(required_minor) AND required_minor >= 0 AND required_minor < power(10::numeric, 38)),
    ADD CHECK (previous_minor = trunc(previous_minor) AND abs(previous_minor) < power(10::numeric, 38)),
    ADD CHECK ((required_minor = previous_minor) = (journal_id IS NULL));

CREATE OR REPLACE FUNCTION ledger_write_off_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    item ledger_postings%ROWTYPE;
    journal ledger_journals%ROWTYPE;
    allocation ledger_allocations%ROWTYPE;
    original ledger_write_offs%ROWTYPE;
    control_line ledger_postings%ROWTYPE;
    counter_line ledger_postings%ROWTYPE;
    new_item uuid;
BEGIN
    SELECT p.* INTO STRICT item FROM ledger_open_items oi JOIN ledger_postings p USING (tenant_id, book_id, journal_id, line_no)
        WHERE oi.tenant_id = NEW.tenant_id AND oi.book_id = NEW.book_id AND oi.id = NEW.item_id;
    SELECT * INTO STRICT journal FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id;
    SELECT * INTO STRICT allocation FROM ledger_allocations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.allocation_id;
    IF NEW.reverses_id IS NOT NULL THEN
        SELECT * INTO STRICT original FROM ledger_write_offs WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
        IF original.reverses_id IS NOT NULL OR (NEW.item_id, NEW.account_id, NEW.amount_minor) IS DISTINCT FROM (original.item_id, original.account_id, original.amount_minor)
           OR journal.reverses_id IS DISTINCT FROM original.journal_id OR allocation.reverses_id IS DISTINCT FROM original.allocation_id
           OR NEW.effective_on <> journal.effective_on OR NEW.effective_on <> allocation.effective_on THEN
            RAISE EXCEPTION 'A write-off reinstatement must reverse its journal and allocation';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO control_line FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id AND line_no = 1;
    SELECT * INTO counter_line FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id AND line_no = 2;
    SELECT id INTO new_item FROM ledger_open_items WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id AND line_no = 1;
    IF journal.reverses_id IS NOT NULL OR journal.closing OR jsonb_array_length(journal.postings) <> 2 OR journal.effective_on <> NEW.effective_on
       OR journal.source_type IS DISTINCT FROM 'write_off' OR journal.source_reference IS DISTINCT FROM NEW.id::text
       OR control_line.account_id <> item.account_id OR control_line.party_id IS DISTINCT FROM item.party_id OR control_line.currency_code <> item.currency_code
       OR control_line.dimensions <> item.dimensions OR control_line.side = item.side OR control_line.amount_minor <> NEW.amount_minor OR control_line.valuation
       OR counter_line.account_id <> NEW.account_id OR counter_line.party_id IS NOT NULL OR counter_line.valuation
       OR EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.account_id AND (is_control OR is_group))
       OR allocation.reverses_id IS NOT NULL OR allocation.amount_minor <> NEW.amount_minor OR allocation.effective_on <> NEW.effective_on
       OR new_item IS NULL OR (allocation.debit_item_id, allocation.credit_item_id) IS DISTINCT FROM
          (CASE WHEN item.side = 'debit' THEN NEW.item_id ELSE new_item END, CASE WHEN item.side = 'credit' THEN NEW.item_id ELSE new_item END) THEN
        RAISE EXCEPTION 'A write-off must settle its open item through one exact journal and allocation';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_allowance_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    bucket record;
    weighted numeric := 0;
    previous numeric;
    functional_code text;
    difference numeric;
BEGIN
    SELECT currency_code INTO STRICT functional_code FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id;
    IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.control_account_id AND is_control AND type = 'asset')
       OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.allowance_account_id
           AND type = 'asset' AND NOT is_control AND NOT is_group AND coalesce(currency_code, functional_code) = functional_code)
       OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.expense_account_id AND type = 'expense' AND NOT is_group) THEN
        RAISE EXCEPTION 'Allowances require a receivable control account, a functional contra-asset allowance account and an expense account';
    END IF;
    IF EXISTS (SELECT 1 FROM jsonb_object_keys(NEW.matrix) k WHERE k NOT IN ('current', '1-30', '31-60', '61-90', '91+'))
       OR (SELECT array_agg(k ORDER BY k) FROM jsonb_object_keys(NEW.matrix) k) IS DISTINCT FROM (SELECT array_agg(k ORDER BY k) FROM jsonb_object_keys(NEW.exposure) k) THEN
        RAISE EXCEPTION 'Allowance matrix and exposure must use the same aging buckets';
    END IF;
    FOR bucket IN SELECT m.key, m.value #>> '{}' AS rate, NEW.exposure->>m.key AS exposure FROM jsonb_each(NEW.matrix) m LOOP
        IF bucket.rate !~ '^(0(\.[0-9]{1,18})?|1(\.0{1,18})?)$' OR bucket.exposure !~ '^[0-9]{1,38}$' THEN
            RAISE EXCEPTION 'Allowance rates must be fractions from 0 to 1 and exposures non-negative minor units';
        END IF;
        weighted := weighted + bucket.exposure::numeric * bucket.rate::numeric;
    END LOOP;
    IF NEW.required_minor <> ledger_fraction(weighted * power(10::numeric, 18), power(10::numeric, 18), 'half_even') THEN
        RAISE EXCEPTION 'Allowance requirement does not match its matrix and exposure';
    END IF;
    SELECT coalesce(sum(CASE WHEN p.side = 'credit' THEN p.functional_minor ELSE -p.functional_minor END), 0) INTO previous
        FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
        WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.account_id = NEW.allowance_account_id AND j.effective_on <= NEW.effective_on
        AND (NEW.journal_id IS NULL OR j.id <> NEW.journal_id);
    IF NEW.previous_minor <> previous THEN
        RAISE EXCEPTION 'Allowance must start from the recorded allowance balance';
    END IF;
    difference := NEW.required_minor - NEW.previous_minor;
    IF NEW.journal_id IS NOT NULL AND ((SELECT count(*) FROM ledger_postings p WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.journal_id) <> 2
       OR NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id AND effective_on = NEW.effective_on
           AND reverses_id IS NULL AND source_type = 'allowance' AND source_reference = NEW.id::text)
       OR NOT EXISTS (SELECT 1 FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id
           AND account_id = NEW.allowance_account_id AND functional_minor = abs(difference) AND side = CASE WHEN difference > 0 THEN 'credit' ELSE 'debit' END)
       OR NOT EXISTS (SELECT 1 FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id
           AND account_id = NEW.expense_account_id AND functional_minor = abs(difference) AND side = CASE WHEN difference > 0 THEN 'debit' ELSE 'credit' END)) THEN
        RAISE EXCEPTION 'Allowance journal must move the allowance exactly to its requirement';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_source_links() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    IF (NEW.source_type LIKE 'recurring:%' AND NOT EXISTS (SELECT 1 FROM ledger_recurring_runs WHERE tenant_id = NEW.tenant_id
            AND book_id = NEW.book_id AND journal_id = NEW.id AND kind = 'post'))
       OR (NEW.source_type = 'write_off' AND NOT EXISTS (SELECT 1 FROM ledger_write_offs WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id))
       OR (NEW.source_type = 'allowance' AND NOT EXISTS (SELECT 1 FROM ledger_allowance_runs WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id))
       OR (NEW.source_type = 'restatement' AND NOT EXISTS (SELECT 1 FROM ledger_restatements WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id)) THEN
        RAISE EXCEPTION 'Journal source requires its recorded subledger event';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE CONSTRAINT TRIGGER stage_99_source_links AFTER INSERT ON ledger_journals
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_source_links();

DO $body$
DECLARE table_name text;
BEGIN
    FOREACH table_name IN ARRAY ARRAY['ledger_write_offs', 'ledger_allowance_runs'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', table_name);
        EXECUTE format('CREATE POLICY ledger_tenant ON %I USING (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid) WITH CHECK (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid)', table_name);
        EXECUTE format('CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard()', table_name);
        EXECUTE format('CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable()', table_name);
        EXECUTE format('CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_immutable()', table_name);
        EXECUTE format('CREATE TRIGGER stage_30_stamp BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_evidence_stamp()', table_name);
        EXECUTE format('CREATE TRIGGER stage_90_audit AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_audit()', table_name);
    END LOOP;
END;
$body$;
CREATE TRIGGER stage_40_validate BEFORE INSERT ON ledger_write_offs FOR EACH ROW EXECUTE FUNCTION ledger_write_off_validate();
CREATE TRIGGER stage_40_validate BEFORE INSERT ON ledger_allowance_runs FOR EACH ROW EXECUTE FUNCTION ledger_allowance_validate();

ALTER TABLE ledger_budgets
    ADD CHECK (length(btrim(name)) > 0), ADD CHECK (length(btrim(version)) > 0), ADD CHECK (fingerprint ~ '^[a-f0-9]{64}$'),
    ADD CHECK (jsonb_typeof(lines) = 'array' AND jsonb_array_length(lines) BETWEEN 1 AND 100000);

CREATE OR REPLACE FUNCTION ledger_budget_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id);
    PERFORM ledger_require_live_dimensions(NEW.tenant_id, NEW.book_id, l->'dimensions') FROM jsonb_array_elements(NEW.lines) l WHERE jsonb_typeof(l->'dimensions') = 'object';
    IF EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.lines) l WHERE jsonb_typeof(l) <> 'object' OR jsonb_typeof(l->'dimensions') <> 'object'
            OR (l->>'amount_minor') !~ '^-?[0-9]{1,38}$' OR (l->>'starts_on')::date > (l->>'ends_on')::date
            OR NOT EXISTS (SELECT 1 FROM ledger_accounts a WHERE a.tenant_id = NEW.tenant_id AND a.book_id = NEW.book_id
                AND a.id = (l->>'account_id')::uuid AND NOT a.is_group)) THEN
        RAISE EXCEPTION 'Budget lines require posting accounts, valid ranges and signed minor-unit amounts';
    END IF;
    IF EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.lines) WITH ORDINALITY a(l, i) JOIN jsonb_array_elements(NEW.lines) WITH ORDINALITY b(l, i)
            ON a.i < b.i AND a.l->>'account_id' = b.l->>'account_id' AND a.l->'dimensions' = b.l->'dimensions'
            AND (a.l->>'starts_on')::date <= (b.l->>'ends_on')::date AND (b.l->>'starts_on')::date <= (a.l->>'ends_on')::date) THEN
        RAISE EXCEPTION 'Budget lines for the same account and dimensions cannot overlap';
    END IF;
    RETURN NEW;
END;
$body$;

ALTER TABLE ledger_budgets ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger_budgets FORCE ROW LEVEL SECURITY;
CREATE POLICY ledger_tenant ON ledger_budgets USING (tenant_id = nullif(current_setting('noria.ledger_tenant', true), '')::uuid)
    WITH CHECK (tenant_id = nullif(current_setting('noria.ledger_tenant', true), '')::uuid);
CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON ledger_budgets FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard();
CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON ledger_budgets FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON ledger_budgets FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER stage_30_stamp BEFORE INSERT ON ledger_budgets FOR EACH ROW EXECUTE FUNCTION ledger_evidence_stamp();
CREATE TRIGGER stage_40_validate BEFORE INSERT ON ledger_budgets FOR EACH ROW EXECUTE FUNCTION ledger_budget_validate();
CREATE TRIGGER stage_90_audit AFTER INSERT ON ledger_budgets FOR EACH ROW EXECUTE FUNCTION ledger_audit();

ALTER TABLE ledger_journal_drafts
    ADD CHECK (fingerprint ~ '^[a-f0-9]{64}$'), ADD CHECK (length(btrim(description)) > 0), ADD CHECK (jsonb_typeof(request) = 'object'),
    ADD CHECK (supersedes_id IS NULL OR supersedes_id <> id);
ALTER TABLE ledger_draft_decisions
    ADD CHECK (decision IN ('approved', 'rejected', 'withdrawn')), ADD CHECK ((decision = 'approved') = (journal_id IS NOT NULL)),
    ADD CHECK (length(btrim(reason)) > 0);

CREATE OR REPLACE FUNCTION ledger_draft_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE draft ledger_journal_drafts%ROWTYPE; journal ledger_journals%ROWTYPE;
BEGIN
    IF TG_TABLE_NAME = 'ledger_journal_drafts' THEN
        PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id);
        IF NEW.supersedes_id IS NOT NULL AND EXISTS (SELECT 1 FROM ledger_draft_decisions WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND draft_id = NEW.supersedes_id) THEN
            RAISE EXCEPTION 'A decided draft cannot be revised';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO STRICT draft FROM ledger_journal_drafts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.draft_id;
    IF EXISTS (SELECT 1 FROM ledger_journal_drafts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND supersedes_id = NEW.draft_id) THEN
        RAISE EXCEPTION 'A superseded draft cannot be decided';
    END IF;
    IF NEW.decision = 'withdrawn' AND NEW.actor_id <> draft.actor_id THEN
        RAISE EXCEPTION 'Only the preparer can withdraw a draft';
    END IF;
    IF NEW.decision IN ('approved', 'rejected') AND NEW.actor_id = draft.actor_id THEN
        RAISE EXCEPTION 'A draft must be approved or rejected by someone other than its preparer';
    END IF;
    IF NEW.decision = 'approved' THEN
        SELECT * INTO STRICT journal FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id;
        IF journal.fingerprint <> draft.fingerprint OR journal.idempotency_key <> 'draft:' || draft.id OR journal.reverses_id IS NOT NULL THEN
            RAISE EXCEPTION 'An approved draft must post exactly its prepared journal';
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_draft_links() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    IF NEW.idempotency_key LIKE 'draft:%' AND NOT EXISTS (SELECT 1 FROM ledger_draft_decisions WHERE tenant_id = NEW.tenant_id
        AND book_id = NEW.book_id AND journal_id = NEW.id AND decision = 'approved') THEN
        RAISE EXCEPTION 'Draft journals require a recorded approval';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE CONSTRAINT TRIGGER stage_99_draft_links AFTER INSERT ON ledger_journals
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_draft_links();

DO $body$
DECLARE table_name text;
BEGIN
    FOREACH table_name IN ARRAY ARRAY['ledger_journal_drafts', 'ledger_draft_decisions'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', table_name);
        EXECUTE format('CREATE POLICY ledger_tenant ON %I USING (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid) WITH CHECK (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid)', table_name);
        EXECUTE format('CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard()', table_name);
        EXECUTE format('CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable()', table_name);
        EXECUTE format('CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_immutable()', table_name);
        EXECUTE format('CREATE TRIGGER stage_30_stamp BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_evidence_stamp()', table_name);
        EXECUTE format('CREATE TRIGGER stage_40_validate BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_draft_validate()', table_name);
        EXECUTE format('CREATE TRIGGER stage_90_audit AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_audit()', table_name);
    END LOOP;
END;
$body$;

ALTER TABLE ledger_restatements
    ADD CHECK (fingerprint ~ '^[a-f0-9]{64}$'), ADD CHECK (length(btrim(reason)) > 0),
    ADD CHECK (jsonb_typeof(attribution) = 'array' AND jsonb_array_length(attribution) BETWEEN 1 AND 10000);

CREATE OR REPLACE FUNCTION ledger_restatement_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE closure ledger_year_closures%ROWTYPE; journal ledger_journals%ROWTYPE;
BEGIN
    SELECT * INTO STRICT closure FROM ledger_year_closures WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.closure_id;
    SELECT * INTO STRICT journal FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id;
    IF (closure.journal_id IS NOT NULL AND EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND reverses_id = closure.journal_id))
       OR journal.effective_on <= closure.ends_on OR journal.closing OR journal.reverses_id IS NOT NULL
       OR journal.source_type IS DISTINCT FROM 'restatement' OR journal.source_reference IS DISTINCT FROM NEW.id::text THEN
        RAISE EXCEPTION 'A restatement must post after its active fiscal closure';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.retained_account_id AND type = 'equity' AND NOT is_group)
       OR EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
           WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.journal_id AND a.type IN ('income', 'expense'))
       OR EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.attribution) l WHERE jsonb_typeof(l) <> 'object' OR l->>'side' NOT IN ('debit', 'credit')
           OR (l->>'functional_minor') !~ '^[1-9][0-9]{0,37}$' OR jsonb_typeof(l->'dimensions') <> 'object'
           OR NOT EXISTS (SELECT 1 FROM ledger_accounts a WHERE a.tenant_id = NEW.tenant_id AND a.book_id = NEW.book_id AND a.id = (l->>'account_id')::uuid
               AND a.type IN ('income', 'expense') AND NOT a.is_group)) THEN
        RAISE EXCEPTION 'Restatements attribute income and expense to retained earnings without posting them';
    END IF;
    IF EXISTS (
        SELECT 1 FROM (
            SELECT l->'dimensions' AS dimensions, sum(CASE WHEN l->>'side' = 'debit' THEN (l->>'functional_minor')::numeric ELSE -(l->>'functional_minor')::numeric END) AS amount
            FROM jsonb_array_elements(NEW.attribution) l GROUP BY l->'dimensions') attributed
        FULL JOIN (
            SELECT p.dimensions, sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END) AS amount
            FROM ledger_postings p WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.journal_id AND p.account_id = NEW.retained_account_id
            GROUP BY p.dimensions) retained ON retained.dimensions = attributed.dimensions
        WHERE coalesce(attributed.amount, 0) <> coalesce(retained.amount, 0)) THEN
        RAISE EXCEPTION 'Restated retained earnings must equal the attributed income and expense by dimension';
    END IF;
    RETURN NEW;
END;
$body$;

ALTER TABLE ledger_restatements ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger_restatements FORCE ROW LEVEL SECURITY;
CREATE POLICY ledger_tenant ON ledger_restatements USING (tenant_id = nullif(current_setting('noria.ledger_tenant', true), '')::uuid)
    WITH CHECK (tenant_id = nullif(current_setting('noria.ledger_tenant', true), '')::uuid);
CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON ledger_restatements FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard();
CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON ledger_restatements FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON ledger_restatements FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER stage_30_stamp BEFORE INSERT ON ledger_restatements FOR EACH ROW EXECUTE FUNCTION ledger_evidence_stamp();
CREATE TRIGGER stage_40_validate BEFORE INSERT ON ledger_restatements FOR EACH ROW EXECUTE FUNCTION ledger_restatement_validate();
CREATE TRIGGER stage_90_audit AFTER INSERT ON ledger_restatements FOR EACH ROW EXECUTE FUNCTION ledger_audit();
