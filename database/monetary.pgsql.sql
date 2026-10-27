CREATE TABLE ledger_monetary_accounts (
    tenant_id uuid NOT NULL, book_id uuid NOT NULL, account_id uuid NOT NULL, id uuid NOT NULL, policy_version varchar(128) NOT NULL,
    PRIMARY KEY (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, account_id),
    FOREIGN KEY (tenant_id, book_id, account_id) REFERENCES ledger_accounts (tenant_id, book_id, id)
);

CREATE TABLE ledger_monetary_valuations (
    tenant_id uuid NOT NULL, book_id uuid NOT NULL, account_id uuid NOT NULL, id uuid NOT NULL,
    idempotency_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL, effective_on date NOT NULL,
    dimensions jsonb NOT NULL CHECK (jsonb_typeof(dimensions) = 'object'),
    source_minor numeric NOT NULL CHECK (source_minor = trunc(source_minor) AND abs(source_minor) < power(10::numeric, 38)),
    before_minor numeric NOT NULL CHECK (before_minor = trunc(before_minor) AND abs(before_minor) < power(10::numeric, 38)),
    delta_minor numeric NOT NULL CHECK (delta_minor = trunc(delta_minor) AND abs(delta_minor) < power(10::numeric, 38)),
    rate numeric NOT NULL CHECK (rate > 0 AND rate = trunc(rate, 18) AND rate < power(10::numeric, 20)),
    rate_as_of date NOT NULL, rate_source varchar(256) NOT NULL, rounding varchar(16) NOT NULL CHECK (rounding IN ('half_even', 'half_up', 'down')),
    journal_id uuid, reverses_id uuid, reason varchar(1024) NOT NULL,
    recorded_at timestamptz NOT NULL, sequence bigint NOT NULL, actor_id varchar(128) NOT NULL, firm_id varchar(128),
    PRIMARY KEY (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, idempotency_key), UNIQUE (tenant_id, book_id, journal_id), UNIQUE (tenant_id, book_id, reverses_id),
    FOREIGN KEY (tenant_id, book_id, account_id) REFERENCES ledger_monetary_accounts (tenant_id, book_id, account_id),
    FOREIGN KEY (tenant_id, book_id, journal_id) REFERENCES ledger_journals (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, reverses_id) REFERENCES ledger_monetary_valuations (tenant_id, book_id, id),
    CHECK ((delta_minor = 0) = (journal_id IS NULL)), CHECK (abs(before_minor + delta_minor) < power(10::numeric, 38))
);

CREATE INDEX ledger_monetary_history ON ledger_monetary_valuations (tenant_id, book_id, account_id, sequence);

CREATE OR REPLACE FUNCTION ledger_monetary_quote(tenant uuid, book uuid, account uuid, date_arg date, dimensions_arg jsonb, excluded_journal uuid)
RETURNS jsonb LANGUAGE sql STABLE SET search_path FROM CURRENT AS $body$
    SELECT jsonb_build_object('source_minor', coalesce(sum(CASE WHEN p.side = 'debit' THEN p.amount_minor ELSE -p.amount_minor END), 0)::text,
        'before_minor', coalesce(sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END), 0)::text)
    FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
    WHERE p.tenant_id = tenant AND p.book_id = book AND p.account_id = account AND p.dimensions = dimensions_arg
        AND j.effective_on <= date_arg AND j.id IS DISTINCT FROM excluded_journal;
$body$;

CREATE OR REPLACE FUNCTION ledger_monetary_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    account ledger_accounts%ROWTYPE; original ledger_monetary_valuations%ROWTYPE; latest uuid;
    functional_code text; target_scale integer; source_scale integer; quote jsonb; expected numeric; label record; functional_amount numeric;
BEGIN
    PERFORM 1 FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id FOR UPDATE;
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id, (to_jsonb(NEW)->>'reverses_id') IS NOT NULL);
    SELECT * INTO STRICT account FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.account_id;
    IF (NOT account.active OR account.deleted_at IS NOT NULL) AND (to_jsonb(NEW)->>'reverses_id') IS NULL THEN RAISE EXCEPTION 'Restore the inactive or soft-deleted monetary account before new activity'; END IF;
    SELECT b.currency_code, c.scale INTO STRICT functional_code, target_scale FROM ledger_books b JOIN ledger_currencies c
        ON c.tenant_id = b.tenant_id AND c.code = b.currency_code WHERE b.tenant_id = NEW.tenant_id AND b.id = NEW.book_id;
    IF account.type NOT IN ('asset', 'liability') OR account.is_group OR account.is_control OR coalesce(account.currency_code, functional_code) = functional_code THEN
        RAISE EXCEPTION 'Monetary registration requires a foreign non-control asset or liability';
    END IF;
    IF TG_TABLE_NAME = 'ledger_monetary_accounts' THEN RETURN NEW; END IF;
    PERFORM ledger_require_live_dimensions(NEW.tenant_id, NEW.book_id, NEW.dimensions, NEW.reverses_id IS NOT NULL);
    IF NEW.rate_as_of > NEW.effective_on OR EXISTS (SELECT 1 FROM ledger_monetary_valuations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
        AND account_id = NEW.account_id AND dimensions = NEW.dimensions AND effective_on > NEW.effective_on) THEN
        RAISE EXCEPTION 'Monetary valuation requires chronological history and an approved past or current rate';
    END IF;
    FOR label IN SELECT key, value FROM jsonb_each(NEW.dimensions) LOOP
        IF jsonb_typeof(label.value) <> 'string' OR NOT EXISTS (SELECT 1 FROM ledger_dimension_values d JOIN ledger_books b
            ON b.tenant_id = d.tenant_id AND b.entity_id = d.entity_id WHERE b.tenant_id = NEW.tenant_id AND b.id = NEW.book_id
                AND d.dimension = label.key AND d.value = label.value #>> '{}' AND (d.deleted_at IS NULL OR NEW.reverses_id IS NOT NULL)) THEN RAISE EXCEPTION 'Unknown monetary valuation dimension'; END IF;
    END LOOP;
    IF EXISTS (SELECT 1 FROM ledger_account_dimensions d WHERE d.tenant_id = NEW.tenant_id AND d.book_id = NEW.book_id
        AND d.account_id = NEW.account_id AND NOT (NEW.dimensions ? d.dimension)) THEN RAISE EXCEPTION 'A mandatory monetary valuation dimension is missing'; END IF;
    quote := ledger_monetary_quote(NEW.tenant_id, NEW.book_id, NEW.account_id, NEW.effective_on, NEW.dimensions, NEW.journal_id);
    IF NEW.source_minor <> (quote->>'source_minor')::numeric OR NEW.before_minor <> (quote->>'before_minor')::numeric THEN
        RAISE EXCEPTION 'Monetary valuation must retain exact source and functional carrying balances';
    END IF;
    IF NEW.reverses_id IS NOT NULL THEN
        SELECT * INTO STRICT original FROM ledger_monetary_valuations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
        SELECT v.id INTO latest FROM ledger_monetary_valuations v WHERE v.tenant_id = NEW.tenant_id AND v.book_id = NEW.book_id
            AND v.account_id = NEW.account_id AND v.dimensions = NEW.dimensions AND v.reverses_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM ledger_monetary_valuations r WHERE r.tenant_id = v.tenant_id AND r.book_id = v.book_id AND r.reverses_id = v.id)
            ORDER BY v.sequence DESC LIMIT 1;
        IF latest IS DISTINCT FROM original.id OR original.account_id <> NEW.account_id OR original.dimensions <> NEW.dimensions OR original.reverses_id IS NOT NULL
            OR NEW.delta_minor <> -original.delta_minor OR NEW.rate <> original.rate OR NEW.rounding <> original.rounding
            OR NEW.rate_as_of <> original.rate_as_of OR NEW.rate_source <> original.rate_source
            OR (original.journal_id IS NULL) <> (NEW.journal_id IS NULL) THEN RAISE EXCEPTION 'Reverse monetary valuations in dependency order with exact evidence'; END IF;
        IF NEW.journal_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND id = NEW.journal_id AND reverses_id = original.journal_id AND effective_on = NEW.effective_on) THEN
            RAISE EXCEPTION 'Monetary reversal must reverse the corresponding journal';
        END IF;
        IF NEW.journal_id IS NOT NULL AND account.cash_flow = 'cash' AND NOT EXISTS (SELECT 1 FROM ledger_journals
            WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id
                AND metadata->'cash_flow' = jsonb_build_object('exchange', NEW.delta_minor::text)) THEN
            RAISE EXCEPTION 'Monetary reversals must restore the exact cash exchange classification';
        END IF;
        RETURN NEW;
    END IF;
    SELECT scale INTO STRICT source_scale FROM ledger_currencies WHERE tenant_id = NEW.tenant_id AND code = account.currency_code;
    expected := ledger_fraction(NEW.source_minor * NEW.rate * power(10::numeric, target_scale), power(10::numeric, source_scale), NEW.rounding) - NEW.before_minor;
    IF NEW.delta_minor <> expected THEN RAISE EXCEPTION 'Incorrect monetary valuation adjustment'; END IF;
    functional_amount := abs(NEW.delta_minor);
    IF functional_amount <> 0 AND (NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id
        AND effective_on = NEW.effective_on AND reverses_id IS NULL AND NOT closing AND jsonb_array_length(postings) = 2
        AND metadata->>'monetary_valuation_id' = NEW.id::text)
        OR NOT EXISTS (SELECT 1 FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id
            AND account_id = NEW.account_id AND dimensions = NEW.dimensions AND valuation AND party_id IS NULL
            AND currency_code = account.currency_code AND rate = NEW.rate AND rate_as_of = NEW.rate_as_of AND rate_source = NEW.rate_source AND rounding = NEW.rounding
            AND functional_minor = functional_amount AND side = CASE WHEN NEW.delta_minor > 0 THEN 'debit' ELSE 'credit' END)
        OR NOT EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
            WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.journal_id AND NOT p.valuation AND p.party_id IS NULL
                AND p.currency_code = functional_code AND p.dimensions = NEW.dimensions AND p.functional_minor = functional_amount
                AND a.type = CASE WHEN NEW.delta_minor > 0 THEN 'income' ELSE 'expense' END AND p.side = CASE WHEN NEW.delta_minor > 0 THEN 'credit' ELSE 'debit' END)
        OR (account.cash_flow = 'cash' AND NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id
            AND metadata->'cash_flow' = jsonb_build_object('exchange', NEW.delta_minor::text)))) THEN
        RAISE EXCEPTION 'Monetary valuation journal must match the exact adjustment and cash exchange effect';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_monetary_links() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE previous_tenant text := coalesce(current_setting('noria.ledger_tenant', true), '');
BEGIN
    PERFORM set_config('noria.ledger_tenant', NEW.tenant_id::text, true);
    IF EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_monetary_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.account_id = p.account_id
        WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.id AND p.valuation)
        AND NOT EXISTS (SELECT 1 FROM ledger_monetary_valuations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id) THEN
        RAISE EXCEPTION 'Registered monetary valuations require corresponding valuation evidence';
    END IF;
    IF EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_monetary_valuations v ON v.tenant_id = p.tenant_id AND v.book_id = p.book_id
        AND v.account_id = p.account_id AND v.dimensions = p.dimensions WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.id
        AND v.sequence < NEW.sequence AND v.effective_on > NEW.effective_on) THEN RAISE EXCEPTION 'Posting cannot silently backdate before monetary valuation history'; END IF;
    PERFORM set_config('noria.ledger_tenant', previous_tenant, true);
    RETURN NEW;
END;
$body$;

DO $body$
DECLARE table_name text;
BEGIN
    FOREACH table_name IN ARRAY ARRAY['ledger_monetary_accounts', 'ledger_monetary_valuations'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', table_name);
        EXECUTE format('CREATE POLICY ledger_tenant ON %I USING (tenant_id::text = nullif(current_setting(''noria.ledger_tenant'', true), '''')) WITH CHECK (tenant_id::text = nullif(current_setting(''noria.ledger_tenant'', true), ''''))', table_name);
        EXECUTE format('CREATE TRIGGER a_scope BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard()', table_name);
        EXECUTE format('CREATE TRIGGER b_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_immutable()', table_name);
        EXECUTE format('CREATE TRIGGER a_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable()', table_name);
        EXECUTE format('CREATE TRIGGER d_validate BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_monetary_validate()', table_name);
        EXECUTE format('CREATE TRIGGER z_audit AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_audit()', table_name);
    END LOOP;
END;
$body$;

CREATE TRIGGER c_stamp BEFORE INSERT ON ledger_monetary_valuations FOR EACH ROW EXECUTE FUNCTION ledger_event_stamp();
CREATE CONSTRAINT TRIGGER zz_ledger_monetary_links AFTER INSERT ON ledger_journals
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_monetary_links();
