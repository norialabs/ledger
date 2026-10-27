CREATE TABLE ledger_inventory_pools (
    tenant_id uuid NOT NULL, book_id uuid NOT NULL, id uuid NOT NULL,
    reference varchar(128) NOT NULL, sku varchar(128) NOT NULL, fingerprint char(64) NOT NULL,
    account_id uuid NOT NULL, method varchar(16) NOT NULL CHECK (method IN ('fifo', 'moving_average')),
    dimensions jsonb NOT NULL CHECK (jsonb_typeof(dimensions) = 'object'),
    PRIMARY KEY (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, reference),
    FOREIGN KEY (tenant_id, book_id, account_id) REFERENCES ledger_accounts (tenant_id, book_id, id)
);

CREATE TABLE ledger_inventory_moves (
    tenant_id uuid NOT NULL, book_id uuid NOT NULL, id uuid NOT NULL, pool_id uuid NOT NULL,
    idempotency_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL, effective_on date NOT NULL,
    kind varchar(16) NOT NULL CHECK (kind IN ('receipt', 'issue', 'adjustment', 'return', 'supplier_return', 'reversal')),
    quantity numeric NOT NULL CHECK (quantity = trunc(quantity, 12) AND abs(quantity) < power(10::numeric, 26)),
    value_minor numeric NOT NULL CHECK (value_minor = trunc(value_minor) AND abs(value_minor) < power(10::numeric, 38)),
    commercial_minor numeric CHECK (commercial_minor = trunc(commercial_minor) AND abs(commercial_minor) < power(10::numeric, 38)),
    variance_account_id uuid,
    counter_account_id uuid NOT NULL, journal_id uuid, plan jsonb NOT NULL CHECK (jsonb_typeof(plan) = 'array'),
    reverses_id uuid, returns_id uuid, reason varchar(1024) NOT NULL,
    recorded_at timestamptz NOT NULL, sequence bigint NOT NULL, actor_id varchar(128) NOT NULL, firm_id varchar(128),
    PRIMARY KEY (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, idempotency_key), UNIQUE (tenant_id, book_id, journal_id), UNIQUE (tenant_id, book_id, reverses_id),
    FOREIGN KEY (tenant_id, book_id, pool_id) REFERENCES ledger_inventory_pools (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, counter_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, variance_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, journal_id) REFERENCES ledger_journals (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, reverses_id) REFERENCES ledger_inventory_moves (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, returns_id) REFERENCES ledger_inventory_moves (tenant_id, book_id, id),
    CHECK ((kind IN ('return', 'supplier_return')) = (returns_id IS NOT NULL)),
    CHECK ((commercial_minor IS NOT NULL) = (kind = 'supplier_return' OR (kind = 'reversal' AND variance_account_id IS NOT NULL))),
    CHECK ((variance_account_id IS NOT NULL) = (commercial_minor IS NOT NULL)),
    CHECK (((value_minor = 0 AND coalesce(commercial_minor, 0) = 0)) = (journal_id IS NULL))
);

CREATE INDEX ledger_inventory_pool_history ON ledger_inventory_moves (tenant_id, book_id, pool_id, sequence);

CREATE TABLE ledger_inventory_layer_entries (
    tenant_id uuid NOT NULL, book_id uuid NOT NULL, movement_id uuid NOT NULL, layer_id uuid NOT NULL,
    quantity numeric NOT NULL CHECK (quantity = trunc(quantity, 12)), value_minor numeric NOT NULL CHECK (value_minor = trunc(value_minor)),
    PRIMARY KEY (tenant_id, book_id, movement_id, layer_id),
    FOREIGN KEY (tenant_id, book_id, movement_id) REFERENCES ledger_inventory_moves (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, layer_id) REFERENCES ledger_inventory_moves (tenant_id, book_id, id)
);

CREATE INDEX ledger_inventory_layer_history ON ledger_inventory_layer_entries (tenant_id, book_id, layer_id);

CREATE OR REPLACE FUNCTION ledger_inventory_layers(tenant uuid, book uuid, pool uuid)
RETURNS TABLE (layer_id uuid, quantity numeric, value_minor numeric, sequence bigint) LANGUAGE sql STABLE SET search_path FROM CURRENT AS $body$
    SELECT m.id, m.quantity + coalesce(e.quantity, 0), m.value_minor + coalesce(e.value_minor, 0), m.sequence
    FROM ledger_inventory_moves m LEFT JOIN (
        SELECT layer_id, sum(quantity) AS quantity, sum(value_minor) AS value_minor FROM ledger_inventory_layer_entries
        WHERE tenant_id = tenant AND book_id = book GROUP BY layer_id
    ) e ON e.layer_id = m.id
    WHERE m.tenant_id = tenant AND m.book_id = book AND m.pool_id = pool AND m.kind IN ('receipt', 'return')
        AND NOT EXISTS (SELECT 1 FROM ledger_inventory_moves r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = m.id)
        AND m.quantity + coalesce(e.quantity, 0) > 0 ORDER BY m.sequence;
$body$;

CREATE OR REPLACE FUNCTION ledger_inventory_quote(tenant uuid, book uuid, pool uuid, operation text, units numeric, amount numeric)
RETURNS jsonb LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    definition ledger_inventory_pools%ROWTYPE;
    total_units numeric; total_value numeric; value numeric := 0;
    remaining numeric; take numeric; portion numeric; remaining_value numeric; weight numeric; remaining_weight numeric;
    layer record; plan jsonb := '[]';
BEGIN
    SELECT * INTO STRICT definition FROM ledger_inventory_pools WHERE tenant_id = tenant AND book_id = book AND id = pool;
    SELECT coalesce(sum(quantity), 0), coalesce(sum(value_minor), 0) INTO total_units, total_value
        FROM ledger_inventory_moves WHERE tenant_id = tenant AND book_id = book AND pool_id = pool;
    IF operation = 'receipt' THEN
        IF units <= 0 OR amount < 0 THEN RAISE EXCEPTION 'Invalid stock receipt'; END IF;
        RETURN jsonb_build_object('value_minor', amount::text, 'plan', plan);
    ELSIF operation = 'issue' THEN
        IF units >= 0 OR -units > total_units THEN RAISE EXCEPTION 'Insufficient inventory quantity'; END IF;
        IF definition.method = 'moving_average' THEN
            value := -ledger_fraction(-units * total_value, total_units, 'half_even');
        ELSE
            remaining := -units;
            FOR layer IN SELECT * FROM ledger_inventory_layers(tenant, book, pool) LOOP
                EXIT WHEN remaining = 0;
                take := least(remaining, layer.quantity);
                portion := ledger_fraction(take * layer.value_minor, layer.quantity, 'half_even');
                plan := plan || jsonb_build_array(jsonb_build_object('layer_id', layer.layer_id, 'quantity', (-take)::text, 'value_minor', (-portion)::text));
                value := value - portion;
                remaining := remaining - take;
            END LOOP;
            IF remaining <> 0 THEN RAISE EXCEPTION 'Inventory cost layers do not reconcile'; END IF;
        END IF;
    ELSIF operation = 'adjustment' THEN
        IF units <> 0 OR amount = 0 OR total_units <= 0 OR total_value + amount < 0 THEN RAISE EXCEPTION 'Invalid stock value adjustment'; END IF;
        value := amount;
        IF definition.method = 'fifo' THEN
            remaining_value := amount;
            remaining_weight := CASE WHEN total_value > 0 THEN total_value ELSE total_units END;
            FOR layer IN SELECT * FROM ledger_inventory_layers(tenant, book, pool) LOOP
                weight := CASE WHEN total_value > 0 THEN layer.value_minor ELSE layer.quantity END;
                IF weight = 0 THEN CONTINUE; END IF;
                portion := ledger_fraction(remaining_value * weight, remaining_weight, 'half_even');
                IF layer.value_minor + portion < 0 THEN RAISE EXCEPTION 'A cost layer cannot become negative'; END IF;
                plan := plan || jsonb_build_array(jsonb_build_object('layer_id', layer.layer_id, 'quantity', '0.000000000000', 'value_minor', portion::text));
                remaining_value := remaining_value - portion;
                remaining_weight := remaining_weight - weight;
            END LOOP;
            IF remaining_value <> 0 THEN RAISE EXCEPTION 'Stock adjustment must conserve its total'; END IF;
        END IF;
    ELSE
        RAISE EXCEPTION 'Unknown inventory operation';
    END IF;
    RETURN jsonb_build_object('value_minor', value::text, 'plan', plan);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_inventory_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    pool ledger_inventory_pools%ROWTYPE; original ledger_inventory_moves%ROWTYPE;
    expected jsonb; opposite jsonb; code text; account ledger_accounts%ROWTYPE; balance numeric; registered numeric; latest uuid; label record; counter_dimensions jsonb; returned_issue uuid;
    commercial numeric; variance numeric; expected_lines integer;
BEGIN
    PERFORM 1 FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id FOR UPDATE;
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id, (to_jsonb(NEW)->>'reverses_id') IS NOT NULL);
    SELECT currency_code INTO STRICT code FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id;
    IF TG_TABLE_NAME = 'ledger_inventory_pools' THEN
        PERFORM ledger_require_live_dimensions(NEW.tenant_id, NEW.book_id, NEW.dimensions);
        SELECT * INTO STRICT account FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.account_id;
        IF account.type <> 'asset' OR account.is_group OR account.is_control OR NOT account.active OR account.deleted_at IS NOT NULL OR coalesce(account.currency_code, code) <> code OR account.cash_flow = 'cash'
            OR EXISTS (SELECT 1 FROM ledger_assets WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
                AND NEW.account_id IN (cost_account_id, depreciation_account_id, impairment_account_id)) THEN
            RAISE EXCEPTION 'Inventory requires a functional-currency non-cash asset account';
        END IF;
        FOR label IN SELECT key, value FROM jsonb_each(NEW.dimensions) LOOP
            IF jsonb_typeof(label.value) <> 'string' OR NOT EXISTS (SELECT 1 FROM ledger_dimension_values d JOIN ledger_books b
                ON b.tenant_id = d.tenant_id AND b.entity_id = d.entity_id WHERE b.tenant_id = NEW.tenant_id AND b.id = NEW.book_id
                AND d.dimension = label.key AND d.value = label.value #>> '{}' AND d.deleted_at IS NULL) THEN RAISE EXCEPTION 'Unknown inventory dimension'; END IF;
        END LOOP;
        IF EXISTS (SELECT 1 FROM ledger_account_dimensions d WHERE d.tenant_id = NEW.tenant_id AND d.book_id = NEW.book_id
            AND d.account_id = NEW.account_id AND NOT (NEW.dimensions ? d.dimension)) THEN RAISE EXCEPTION 'A mandatory inventory dimension is missing'; END IF;
        SELECT coalesce(sum(CASE WHEN side = 'debit' THEN functional_minor ELSE -functional_minor END), 0) INTO balance
            FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND account_id = NEW.account_id;
        SELECT coalesce(sum(m.value_minor), 0) INTO registered FROM ledger_inventory_moves m JOIN ledger_inventory_pools p
            ON p.tenant_id = m.tenant_id AND p.book_id = m.book_id AND p.id = m.pool_id
            WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.account_id = NEW.account_id;
        IF balance <> registered THEN RAISE EXCEPTION 'Reconcile untracked inventory balances before registering a pool'; END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO STRICT pool FROM ledger_inventory_pools WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.pool_id;
    PERFORM ledger_require_live_dimensions(NEW.tenant_id, NEW.book_id, pool.dimensions, NEW.kind = 'reversal');
    SELECT * INTO STRICT account FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.counter_account_id;
    IF NEW.kind <> 'reversal' AND (NOT account.active OR account.deleted_at IS NOT NULL OR EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = pool.account_id AND (NOT active OR deleted_at IS NOT NULL))) THEN
        RAISE EXCEPTION 'Restore inactive or soft-deleted inventory accounts before new movements';
    END IF;
    IF account.is_group OR account.is_control OR coalesce(account.currency_code, code) <> code
        OR EXISTS (SELECT 1 FROM ledger_inventory_pools WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND account_id = NEW.counter_account_id) THEN
        RAISE EXCEPTION 'Inventory requires a non-control counter account outside stock valuation';
    END IF;
    IF EXISTS (SELECT 1 FROM ledger_assets WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
        AND NEW.counter_account_id IN (cost_account_id, depreciation_account_id, impairment_account_id)) THEN
        RAISE EXCEPTION 'Inventory counter accounts cannot bypass the asset register';
    END IF;
    IF EXISTS (SELECT 1 FROM ledger_inventory_moves WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND pool_id = NEW.pool_id AND effective_on > NEW.effective_on) THEN
        RAISE EXCEPTION 'Backdated stock movements require controlled reposting';
    END IF;
    IF NEW.kind = 'reversal' THEN
        SELECT * INTO STRICT original FROM ledger_inventory_moves WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
        SELECT m.id INTO latest FROM ledger_inventory_moves m WHERE m.tenant_id = NEW.tenant_id AND m.book_id = NEW.book_id AND m.pool_id = NEW.pool_id
            AND m.reverses_id IS NULL AND NOT EXISTS (SELECT 1 FROM ledger_inventory_moves r WHERE r.tenant_id = m.tenant_id AND r.book_id = m.book_id AND r.reverses_id = m.id)
            ORDER BY m.sequence DESC LIMIT 1;
        SELECT coalesce(jsonb_agg(jsonb_build_object('layer_id', value->>'layer_id', 'quantity', (-(value->>'quantity')::numeric)::numeric(38, 12)::text,
            'value_minor', (-(value->>'value_minor')::numeric)::text) ORDER BY ordinality), '[]'::jsonb) INTO opposite
            FROM jsonb_array_elements(original.plan) WITH ORDINALITY;
        IF original.id IS DISTINCT FROM latest OR original.kind = 'reversal' OR original.pool_id <> NEW.pool_id OR NEW.quantity <> -original.quantity
            OR NEW.value_minor <> -original.value_minor OR NEW.plan <> opposite OR NEW.counter_account_id <> original.counter_account_id
            OR NEW.commercial_minor IS DISTINCT FROM -original.commercial_minor OR NEW.variance_account_id IS DISTINCT FROM original.variance_account_id THEN
            RAISE EXCEPTION 'Reverse stock movements in reverse order with exact original quantities and costs';
        END IF;
        IF original.transfer_id IS NOT NULL AND NEW.transfer_id IS NULL THEN RAISE EXCEPTION 'Reverse both transfer movements together'; END IF;
        IF original.production_id IS NOT NULL AND NEW.production_id IS NULL THEN RAISE EXCEPTION 'Reverse production movements together'; END IF;
        IF EXISTS (SELECT 1 FROM ledger_inventory_moves r WHERE r.tenant_id = NEW.tenant_id AND r.book_id = NEW.book_id AND r.returns_id = original.id
            AND NOT EXISTS (SELECT 1 FROM ledger_inventory_moves x WHERE x.tenant_id = r.tenant_id AND x.book_id = r.book_id AND x.reverses_id = r.id)) THEN
            RAISE EXCEPTION 'Reverse dependent stock returns before reversing their issue';
        END IF;
        IF original.returns_id IS NOT NULL THEN PERFORM ledger_inventory_return_chronology(NEW.tenant_id, NEW.book_id, original.returns_id, NEW.effective_on); END IF;
        IF original.journal_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND id = NEW.journal_id AND reverses_id = original.journal_id) THEN RAISE EXCEPTION 'Stock reversal requires the matching journal reversal'; END IF;
    ELSE
        IF NEW.reverses_id IS NOT NULL THEN RAISE EXCEPTION 'Invalid stock reversal linkage'; END IF;
        expected := CASE WHEN NEW.kind = 'return' THEN ledger_inventory_return_quote(NEW.tenant_id, NEW.book_id, NEW.pool_id, NEW.returns_id, NEW.effective_on, NEW.quantity)
            WHEN NEW.kind = 'supplier_return' THEN ledger_inventory_supplier_return_quote(NEW.tenant_id, NEW.book_id, NEW.pool_id, NEW.returns_id, NEW.effective_on, NEW.quantity)
            ELSE ledger_inventory_quote(NEW.tenant_id, NEW.book_id, NEW.pool_id, NEW.kind, NEW.quantity, NEW.value_minor) END;
        IF NEW.kind = 'return' AND NEW.counter_account_id <> (SELECT counter_account_id FROM ledger_inventory_moves
            WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.returns_id) THEN RAISE EXCEPTION 'A stock return must restore the original issue counter account'; END IF;
        IF NEW.value_minor <> (expected->>'value_minor')::numeric OR NEW.plan <> expected->'plan' THEN
            RAISE EXCEPTION 'Stock valuation does not match its exact cost layers';
        END IF;
        IF NEW.kind = 'supplier_return' AND NEW.commercial_minor IS DISTINCT FROM (expected->>'commercial_minor')::numeric THEN
            RAISE EXCEPTION 'Supplier return commercial value must conserve the original receipt price';
        END IF;
    END IF;
    IF NEW.variance_account_id IS NOT NULL THEN
        SELECT * INTO STRICT account FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.variance_account_id;
        IF account.type NOT IN ('income', 'expense') OR account.is_group OR account.is_control OR account.id = NEW.counter_account_id
            OR coalesce(account.currency_code, code) <> code THEN RAISE EXCEPTION 'Supplier return price variance requires a distinct functional income or expense account'; END IF;
    END IF;
    counter_dimensions := pool.dimensions;
    returned_issue := CASE WHEN NEW.kind = 'return' THEN NEW.returns_id WHEN NEW.kind = 'reversal' THEN original.returns_id ELSE NULL END;
    IF returned_issue IS NOT NULL THEN
        SELECT p.dimensions INTO STRICT counter_dimensions FROM ledger_inventory_moves m JOIN ledger_inventory_pools p
            ON p.tenant_id = m.tenant_id AND p.book_id = m.book_id AND p.id = m.pool_id
            WHERE m.tenant_id = NEW.tenant_id AND m.book_id = NEW.book_id AND m.id = returned_issue;
    END IF;
    IF NEW.journal_id IS NOT NULL AND NEW.commercial_minor IS NOT NULL THEN
        commercial := -NEW.commercial_minor;
        variance := NEW.commercial_minor - NEW.value_minor;
        expected_lines := CASE WHEN NEW.value_minor <> 0 THEN 1 ELSE 0 END + CASE WHEN commercial <> 0 THEN 1 ELSE 0 END + CASE WHEN variance <> 0 THEN 1 ELSE 0 END;
        IF NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id
                AND effective_on = NEW.effective_on AND jsonb_array_length(postings) = expected_lines AND NOT closing)
            OR EXISTS (SELECT 1 FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id
                AND (currency_code <> code OR valuation OR party_id IS NOT NULL OR dimensions <> pool.dimensions
                    OR account_id NOT IN (pool.account_id, NEW.counter_account_id, NEW.variance_account_id))) THEN
            RAISE EXCEPTION 'Supplier return journal must match its stock, commercial credit, and explicit price variance';
        END IF;
        FOR label IN SELECT pool.account_id AS id, NEW.value_minor AS amount UNION ALL SELECT NEW.counter_account_id, commercial
            UNION ALL SELECT NEW.variance_account_id, variance LOOP
            SELECT coalesce(sum(CASE WHEN side = 'debit' THEN functional_minor ELSE -functional_minor END), 0) INTO balance
                FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id AND account_id = label.id;
            IF balance <> label.amount THEN RAISE EXCEPTION 'Supplier return journal amounts do not match exact valuation'; END IF;
        END LOOP;
    ELSIF NEW.journal_id IS NOT NULL THEN
        SELECT coalesce(sum(CASE WHEN side = 'debit' THEN functional_minor ELSE -functional_minor END), 0) INTO balance
            FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id AND account_id = pool.account_id AND dimensions = pool.dimensions;
        IF balance <> NEW.value_minor OR NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND id = NEW.journal_id AND effective_on = NEW.effective_on AND jsonb_array_length(postings) = 2 AND NOT closing)
            OR EXISTS (SELECT 1 FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id
                AND (currency_code <> code OR valuation OR party_id IS NOT NULL
                    OR dimensions <> CASE WHEN account_id = pool.account_id THEN pool.dimensions ELSE counter_dimensions END))
            OR NOT EXISTS (SELECT 1 FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id
                AND account_id = NEW.counter_account_id AND functional_minor = abs(NEW.value_minor)) THEN
            RAISE EXCEPTION 'Stock journal must match the inventory movement';
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_inventory_project() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE entry jsonb;
BEGIN
    FOR entry IN SELECT value FROM jsonb_array_elements(NEW.plan) LOOP
        INSERT INTO ledger_inventory_layer_entries (tenant_id, book_id, movement_id, layer_id, quantity, value_minor)
        VALUES (NEW.tenant_id, NEW.book_id, NEW.id, (entry->>'layer_id')::uuid, (entry->>'quantity')::numeric, (entry->>'value_minor')::numeric);
    END LOOP;
    RETURN NEW;
END;
$body$;

DO $body$
DECLARE table_name text;
BEGIN
    FOREACH table_name IN ARRAY ARRAY['ledger_inventory_pools', 'ledger_inventory_moves', 'ledger_inventory_layer_entries'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', table_name);
        EXECUTE format('CREATE POLICY ledger_tenant ON %I USING (tenant_id::text = nullif(current_setting(''noria.ledger_tenant'', true), '''')) WITH CHECK (tenant_id::text = nullif(current_setting(''noria.ledger_tenant'', true), ''''))', table_name);
        EXECUTE format('CREATE TRIGGER a_scope BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard()', table_name);
        EXECUTE format('CREATE TRIGGER b_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_immutable()', table_name);
        EXECUTE format('CREATE TRIGGER a_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable()', table_name);
        IF table_name <> 'ledger_inventory_layer_entries' THEN
            EXECUTE format('CREATE TRIGGER z_audit AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_audit()', table_name);
            EXECUTE format('CREATE TRIGGER d_validate BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_inventory_validate()', table_name);
        END IF;
    END LOOP;
END;
$body$;

CREATE TRIGGER b_derived BEFORE INSERT ON ledger_inventory_layer_entries FOR EACH ROW EXECUTE FUNCTION ledger_derived_insert();
CREATE TRIGGER c_stamp BEFORE INSERT ON ledger_inventory_moves FOR EACH ROW EXECUTE FUNCTION ledger_event_stamp();
CREATE TRIGGER y_project AFTER INSERT ON ledger_inventory_moves FOR EACH ROW EXECUTE FUNCTION ledger_inventory_project();
