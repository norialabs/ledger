CREATE TABLE ledger_inventory_pools (
    id uuid NOT NULL DEFAULT uuidv7(), tenant_id uuid NOT NULL, book_id uuid NOT NULL,
    reference varchar(128) NOT NULL, sku varchar(128) NOT NULL, fingerprint char(64) NOT NULL,
    account_id uuid NOT NULL, method varchar(16) NOT NULL CHECK (method IN ('fifo', 'moving_average')),
    dimensions jsonb NOT NULL CHECK (jsonb_typeof(dimensions) = 'object'),
    PRIMARY KEY (id), UNIQUE (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, reference),
    FOREIGN KEY (tenant_id, book_id, account_id) REFERENCES ledger_accounts (tenant_id, book_id, id)
);

CREATE TABLE ledger_inventory_moves (
    id uuid NOT NULL DEFAULT uuidv7(), tenant_id uuid NOT NULL, book_id uuid NOT NULL, pool_id uuid NOT NULL,
    idempotency_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL, effective_on date NOT NULL,
    kind varchar(16) NOT NULL CHECK (kind IN ('receipt', 'issue', 'adjustment', 'return', 'supplier_return', 'reversal')),
    quantity numeric NOT NULL CHECK (quantity = trunc(quantity, 12) AND abs(quantity) < power(10::numeric, 26)),
    value_minor numeric NOT NULL CHECK (value_minor = trunc(value_minor) AND abs(value_minor) < power(10::numeric, 38)),
    commercial_minor numeric CHECK (commercial_minor = trunc(commercial_minor) AND abs(commercial_minor) < power(10::numeric, 38)),
    variance_account_id uuid,
    counter_account_id uuid NOT NULL, journal_id uuid, plan jsonb NOT NULL CHECK (jsonb_typeof(plan) = 'array'),
    reverses_id uuid, returns_id uuid, transfer_id uuid, production_id uuid, reason varchar(1024) NOT NULL,
    recorded_at timestamptz NOT NULL, sequence bigint NOT NULL, actor_id varchar(128) NOT NULL, firm_id varchar(128),
    PRIMARY KEY (id), UNIQUE (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, idempotency_key), UNIQUE (tenant_id, book_id, journal_id), UNIQUE (tenant_id, book_id, reverses_id),
    FOREIGN KEY (tenant_id, book_id, pool_id) REFERENCES ledger_inventory_pools (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, counter_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, variance_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, journal_id) REFERENCES ledger_journals (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, reverses_id) REFERENCES ledger_inventory_moves (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, returns_id) REFERENCES ledger_inventory_moves (tenant_id, book_id, id),
    CHECK ((kind IN ('return', 'supplier_return')) = (returns_id IS NOT NULL)),
    CHECK ((commercial_minor IS NOT NULL) = (kind = 'supplier_return' OR (kind = 'reversal' AND variance_account_id IS NOT NULL))),
    CHECK ((variance_account_id IS NOT NULL) = (commercial_minor IS NOT NULL)),
    CHECK (((value_minor = 0 AND coalesce(commercial_minor, 0) = 0)) = (journal_id IS NULL)),
    CONSTRAINT ledger_inventory_production_exclusive CHECK (production_id IS NULL OR (transfer_id IS NULL AND returns_id IS NULL))
);

CREATE INDEX ledger_inventory_pool_history ON ledger_inventory_moves (tenant_id, book_id, pool_id, sequence);

CREATE TABLE ledger_inventory_layer_entries (
    id uuid NOT NULL DEFAULT uuidv7(),
    tenant_id uuid NOT NULL, book_id uuid NOT NULL, movement_id uuid NOT NULL, layer_id uuid NOT NULL,
    quantity numeric NOT NULL CHECK (quantity = trunc(quantity, 12)), value_minor numeric NOT NULL CHECK (value_minor = trunc(value_minor)),
    PRIMARY KEY (id), UNIQUE (tenant_id, book_id, movement_id, layer_id),
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
                AND NEW.account_id IN (cost_account_id, depreciation_account_id, impairment_account_id))
            OR EXISTS (SELECT 1 FROM ledger_associates WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND NEW.account_id IN (investment_account_id, loss_liability_account_id)) THEN
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

CREATE INDEX ledger_inventory_return_history ON ledger_inventory_moves (tenant_id, book_id, returns_id) WHERE returns_id IS NOT NULL;

CREATE OR REPLACE FUNCTION ledger_inventory_return_chronology(tenant uuid, book uuid, issue uuid, date_arg date)
RETURNS void LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
BEGIN
    IF EXISTS (SELECT 1 FROM ledger_inventory_moves r LEFT JOIN ledger_inventory_moves x
        ON x.tenant_id = r.tenant_id AND x.book_id = r.book_id AND x.reverses_id = r.id
        WHERE r.tenant_id = tenant AND r.book_id = book AND r.returns_id = issue AND greatest(r.effective_on, x.effective_on) > date_arg) THEN
        RAISE EXCEPTION 'Stock return dates must follow the original issue return history';
    END IF;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_inventory_supplier_return_quote(tenant uuid, book uuid, pool uuid, receipt uuid, date_arg date, units numeric)
RETURNS jsonb LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE original ledger_inventory_moves%ROWTYPE; definition ledger_inventory_pools%ROWTYPE; returned_units numeric; returned_value numeric;
    commercial numeric; layer record; quote jsonb; portion numeric;
BEGIN
    SELECT * INTO STRICT original FROM ledger_inventory_moves WHERE tenant_id = tenant AND book_id = book AND id = receipt;
    SELECT * INTO STRICT definition FROM ledger_inventory_pools WHERE tenant_id = tenant AND book_id = book AND id = pool;
    IF original.kind <> 'receipt' OR original.transfer_id IS NOT NULL OR original.production_id IS NOT NULL OR original.pool_id <> pool OR date_arg < original.effective_on OR units >= 0
        OR EXISTS (SELECT 1 FROM ledger_inventory_moves WHERE tenant_id = tenant AND book_id = book AND reverses_id = receipt) THEN
        RAISE EXCEPTION 'A supplier return requires an active purchase receipt in the same valuation pool';
    END IF;
    PERFORM ledger_inventory_return_chronology(tenant, book, receipt, date_arg);
    SELECT coalesce(sum(r.quantity), 0), coalesce(sum(r.commercial_minor), 0) INTO returned_units, returned_value
        FROM ledger_inventory_moves r WHERE r.tenant_id = tenant AND r.book_id = book AND r.returns_id = receipt
        AND NOT EXISTS (SELECT 1 FROM ledger_inventory_moves x WHERE x.tenant_id = tenant AND x.book_id = book AND x.reverses_id = r.id);
    IF -units > original.quantity + returned_units THEN RAISE EXCEPTION 'Supplier return exceeds the original receipt returnable quantity'; END IF;
    commercial := -ledger_fraction(-units * (original.value_minor + returned_value), original.quantity + returned_units, 'half_even');
    IF definition.method = 'fifo' THEN
        SELECT * INTO layer FROM ledger_inventory_layers(tenant, book, pool) WHERE layer_id = receipt;
        IF layer.layer_id IS NULL OR -units > layer.quantity THEN RAISE EXCEPTION 'Supplier return exceeds the original receipt remaining FIFO layer'; END IF;
        portion := -ledger_fraction(-units * layer.value_minor, layer.quantity, 'half_even');
        quote := jsonb_build_object('value_minor', portion::text, 'plan', jsonb_build_array(jsonb_build_object('layer_id', receipt,
            'quantity', units::numeric(38, 12)::text, 'value_minor', portion::text)));
    ELSE
        quote := ledger_inventory_quote(tenant, book, pool, 'issue', units, 0);
    END IF;
    RETURN quote || jsonb_build_object('commercial_minor', commercial::text);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_inventory_return_quote(tenant uuid, book uuid, pool uuid, issue uuid, date_arg date, units numeric)
RETURNS jsonb LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE original ledger_inventory_moves%ROWTYPE; returned_units numeric; returned_value numeric; value numeric;
BEGIN
    SELECT * INTO STRICT original FROM ledger_inventory_moves WHERE tenant_id = tenant AND book_id = book AND id = issue;
    IF original.kind <> 'issue' OR original.transfer_id IS NOT NULL OR original.production_id IS NOT NULL OR date_arg < original.effective_on OR units <= 0
        OR EXISTS (SELECT 1 FROM ledger_inventory_moves WHERE tenant_id = tenant AND book_id = book AND reverses_id = issue)
        OR (SELECT sku FROM ledger_inventory_pools WHERE tenant_id = tenant AND book_id = book AND id = original.pool_id)
            IS DISTINCT FROM (SELECT sku FROM ledger_inventory_pools WHERE tenant_id = tenant AND book_id = book AND id = pool) THEN
        RAISE EXCEPTION 'A stock return requires an active original issue and a compatible SKU';
    END IF;
    PERFORM ledger_inventory_return_chronology(tenant, book, issue, date_arg);
    SELECT coalesce(sum(r.quantity), 0), coalesce(sum(r.value_minor), 0) INTO returned_units, returned_value
        FROM ledger_inventory_moves r WHERE r.tenant_id = tenant AND r.book_id = book AND r.returns_id = issue
        AND NOT EXISTS (SELECT 1 FROM ledger_inventory_moves x WHERE x.tenant_id = tenant AND x.book_id = book AND x.reverses_id = r.id);
    IF units > -original.quantity - returned_units THEN RAISE EXCEPTION 'Stock return exceeds the original issue returnable quantity'; END IF;
    value := ledger_fraction(units * (-original.value_minor - returned_value), -original.quantity - returned_units, 'half_even');
    RETURN jsonb_build_object('value_minor', value::text, 'plan', '[]'::jsonb);
END;
$body$;

CREATE TABLE ledger_inventory_transfers (
    id uuid NOT NULL DEFAULT uuidv7(), tenant_id uuid NOT NULL, book_id uuid NOT NULL,
    idempotency_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL, effective_on date NOT NULL,
    source_pool_id uuid NOT NULL, destination_pool_id uuid NOT NULL, issue_id uuid NOT NULL, receipt_id uuid NOT NULL,
    quantity numeric NOT NULL CHECK (quantity = trunc(quantity, 12) AND abs(quantity) < power(10::numeric, 26) AND quantity <> 0),
    value_minor numeric NOT NULL CHECK (value_minor = trunc(value_minor) AND abs(value_minor) < power(10::numeric, 38)),
    clearing_account_id uuid NOT NULL, reverses_id uuid, reason varchar(1024) NOT NULL,
    recorded_at timestamptz NOT NULL, sequence bigint NOT NULL, actor_id varchar(128) NOT NULL, firm_id varchar(128),
    PRIMARY KEY (id), UNIQUE (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, idempotency_key), UNIQUE (tenant_id, book_id, reverses_id),
    UNIQUE (tenant_id, book_id, issue_id), UNIQUE (tenant_id, book_id, receipt_id),
    FOREIGN KEY (tenant_id, book_id, source_pool_id) REFERENCES ledger_inventory_pools (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, destination_pool_id) REFERENCES ledger_inventory_pools (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, issue_id) REFERENCES ledger_inventory_moves (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, receipt_id) REFERENCES ledger_inventory_moves (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, reverses_id) REFERENCES ledger_inventory_transfers (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, clearing_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    CHECK (source_pool_id <> destination_pool_id), CHECK (issue_id <> receipt_id),
    CHECK ((reverses_id IS NULL AND quantity > 0 AND value_minor >= 0) OR (reverses_id IS NOT NULL AND quantity < 0 AND value_minor <= 0))
);

ALTER TABLE ledger_inventory_moves ADD CONSTRAINT ledger_inventory_transfer_fk FOREIGN KEY (tenant_id, book_id, transfer_id)
    REFERENCES ledger_inventory_transfers (tenant_id, book_id, id) DEFERRABLE INITIALLY DEFERRED;
CREATE INDEX ledger_inventory_transfer_members ON ledger_inventory_moves (tenant_id, book_id, transfer_id) WHERE transfer_id IS NOT NULL;

CREATE OR REPLACE FUNCTION ledger_inventory_transfer_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE issue ledger_inventory_moves%ROWTYPE; receipt ledger_inventory_moves%ROWTYPE; original ledger_inventory_transfers%ROWTYPE;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.clearing_account_id
        AND type IN ('asset', 'liability') AND NOT is_group AND NOT is_control AND cash_flow IS DISTINCT FROM 'cash') THEN
        RAISE EXCEPTION 'Stock transfers require a non-cash balance-sheet clearing account';
    END IF;
    SELECT * INTO STRICT issue FROM ledger_inventory_moves WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.issue_id;
    SELECT * INTO STRICT receipt FROM ledger_inventory_moves WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.receipt_id;
    IF issue.transfer_id IS DISTINCT FROM NEW.id OR receipt.transfer_id IS DISTINCT FROM NEW.id
        OR issue.pool_id <> NEW.source_pool_id OR receipt.pool_id <> NEW.destination_pool_id
        OR issue.quantity <> -NEW.quantity OR receipt.quantity <> NEW.quantity OR issue.value_minor <> -NEW.value_minor OR receipt.value_minor <> NEW.value_minor
        OR issue.counter_account_id <> NEW.clearing_account_id OR receipt.counter_account_id <> NEW.clearing_account_id
        OR issue.effective_on <> NEW.effective_on OR receipt.effective_on <> NEW.effective_on
        OR (SELECT sku FROM ledger_inventory_pools WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.source_pool_id)
            IS DISTINCT FROM (SELECT sku FROM ledger_inventory_pools WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.destination_pool_id) THEN
        RAISE EXCEPTION 'A stock transfer must conserve the same SKU quantity and exact cost across both pools';
    END IF;
    IF NEW.reverses_id IS NULL THEN
        IF issue.kind <> 'issue' OR receipt.kind <> 'receipt' THEN RAISE EXCEPTION 'Invalid stock transfer movement kinds'; END IF;
    ELSE
        SELECT * INTO STRICT original FROM ledger_inventory_transfers WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
        IF original.reverses_id IS NOT NULL OR issue.reverses_id IS DISTINCT FROM original.issue_id OR receipt.reverses_id IS DISTINCT FROM original.receipt_id
            OR NEW.source_pool_id <> original.source_pool_id OR NEW.destination_pool_id <> original.destination_pool_id
            OR NEW.quantity <> -original.quantity OR NEW.value_minor <> -original.value_minor OR NEW.clearing_account_id <> original.clearing_account_id THEN
            RAISE EXCEPTION 'A transfer reversal must restore both original movements exactly';
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_inventory_links() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE previous_tenant text := coalesce(current_setting('noria.ledger_tenant', true), '');
BEGIN
    PERFORM set_config('noria.ledger_tenant', NEW.tenant_id::text, true);
    IF NEW.transfer_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_inventory_transfers t WHERE t.tenant_id = NEW.tenant_id
        AND t.book_id = NEW.book_id AND t.id = NEW.transfer_id AND NEW.id IN (t.issue_id, t.receipt_id)) THEN
        RAISE EXCEPTION 'Stock transfer movements require their matching atomic transfer';
    END IF;
    PERFORM set_config('noria.ledger_tenant', previous_tenant, true);
    RETURN NEW;
END;
$body$;

ALTER TABLE ledger_inventory_transfers ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger_inventory_transfers FORCE ROW LEVEL SECURITY;
CREATE POLICY ledger_tenant ON ledger_inventory_transfers USING (tenant_id::text = nullif(current_setting('noria.ledger_tenant', true), ''))
    WITH CHECK (tenant_id::text = nullif(current_setting('noria.ledger_tenant', true), ''));
CREATE TRIGGER a_scope BEFORE INSERT OR UPDATE ON ledger_inventory_transfers FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard();
CREATE TRIGGER b_immutable BEFORE UPDATE OR DELETE ON ledger_inventory_transfers FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER a_no_truncate BEFORE TRUNCATE ON ledger_inventory_transfers FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER c_stamp BEFORE INSERT ON ledger_inventory_transfers FOR EACH ROW EXECUTE FUNCTION ledger_event_stamp();
CREATE TRIGGER d_validate BEFORE INSERT ON ledger_inventory_transfers FOR EACH ROW EXECUTE FUNCTION ledger_inventory_transfer_validate();
CREATE TRIGGER z_audit AFTER INSERT ON ledger_inventory_transfers FOR EACH ROW EXECUTE FUNCTION ledger_audit();
CREATE CONSTRAINT TRIGGER zz_ledger_inventory_links AFTER INSERT ON ledger_inventory_moves
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_inventory_links();

CREATE TABLE ledger_productions (
    id uuid NOT NULL DEFAULT uuidv7(), tenant_id uuid NOT NULL, book_id uuid NOT NULL,
    idempotency_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL, effective_on date NOT NULL,
    output_pool_id uuid NOT NULL, output_movement_id uuid NOT NULL, output_quantity numeric NOT NULL,
    output_value_minor numeric NOT NULL, conversion_minor numeric NOT NULL DEFAULT 0,
    wip_account_id uuid NOT NULL,
    inputs jsonb NOT NULL CHECK (jsonb_typeof(inputs) = 'array' AND jsonb_array_length(inputs) BETWEEN 1 AND 10000),
    reverses_id uuid, reason varchar(1024) NOT NULL,
    recorded_at timestamptz NOT NULL, sequence bigint NOT NULL, actor_id varchar(128) NOT NULL, firm_id varchar(128),
    PRIMARY KEY (id), UNIQUE (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, idempotency_key), UNIQUE (tenant_id, book_id, reverses_id),
    UNIQUE (tenant_id, book_id, output_movement_id),
    FOREIGN KEY (tenant_id, book_id, output_pool_id) REFERENCES ledger_inventory_pools (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, output_movement_id) REFERENCES ledger_inventory_moves (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, wip_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, reverses_id) REFERENCES ledger_productions (tenant_id, book_id, id),
    CHECK ((reverses_id IS NULL AND output_quantity > 0 AND output_value_minor >= 0 AND conversion_minor >= 0) OR (reverses_id IS NOT NULL AND output_quantity < 0 AND output_value_minor <= 0 AND conversion_minor <= 0)),
    CHECK (output_quantity = trunc(output_quantity, 12) AND abs(output_quantity) < power(10::numeric, 26)),
    CHECK (output_value_minor = trunc(output_value_minor) AND abs(output_value_minor) < power(10::numeric, 38)),
    CHECK (conversion_minor = trunc(conversion_minor) AND abs(conversion_minor) < power(10::numeric, 38))
);

ALTER TABLE ledger_inventory_moves ADD CONSTRAINT ledger_inventory_production_fk FOREIGN KEY (tenant_id, book_id, production_id)
    REFERENCES ledger_productions (tenant_id, book_id, id) DEFERRABLE INITIALLY DEFERRED;
CREATE INDEX ledger_inventory_production_members ON ledger_inventory_moves (tenant_id, book_id, production_id) WHERE production_id IS NOT NULL;

CREATE OR REPLACE FUNCTION ledger_production_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE output ledger_inventory_moves%ROWTYPE; input_moves jsonb; input_move record; input_value numeric := 0; original ledger_productions%ROWTYPE;
    available numeric;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.wip_account_id
        AND type = 'asset' AND NOT is_group AND NOT is_control AND (NEW.reverses_id IS NOT NULL OR (active AND deleted_at IS NULL)) AND cash_flow IS DISTINCT FROM 'cash'
        AND coalesce(currency_code, (SELECT currency_code FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id)) = (SELECT currency_code FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id)) THEN
        RAISE EXCEPTION 'Production requires a functional non-cash balance-sheet WIP asset account';
    END IF;
    SELECT * INTO STRICT output FROM ledger_inventory_moves WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.output_movement_id;
    IF output.pool_id <> NEW.output_pool_id OR output.production_id IS DISTINCT FROM NEW.id OR output.quantity <> NEW.output_quantity
        OR output.value_minor <> NEW.output_value_minor OR output.effective_on <> NEW.effective_on
        OR output.counter_account_id <> NEW.wip_account_id OR output.transfer_id IS NOT NULL OR output.returns_id IS NOT NULL THEN
        RAISE EXCEPTION 'Production output must match its finished-goods receipt and WIP counter';
    END IF;
    input_moves := NEW.inputs;
    IF (SELECT count(DISTINCT value->>'movement_id') FROM jsonb_array_elements(input_moves)) <> jsonb_array_length(input_moves) THEN
        RAISE EXCEPTION 'Production component movements must be unique';
    END IF;
    IF NEW.reverses_id IS NULL THEN
        IF output.kind <> 'receipt' THEN RAISE EXCEPTION 'Production output must be a finished-goods receipt'; END IF;
    ELSE
        SELECT * INTO STRICT original FROM ledger_productions WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
        IF original.reverses_id IS NOT NULL OR NEW.output_pool_id <> original.output_pool_id OR NEW.wip_account_id <> original.wip_account_id
            OR NEW.output_quantity <> -original.output_quantity OR NEW.output_value_minor <> -original.output_value_minor
            OR NEW.conversion_minor <> -original.conversion_minor OR jsonb_array_length(NEW.inputs) <> jsonb_array_length(original.inputs)
            OR output.kind <> 'reversal' OR output.reverses_id IS DISTINCT FROM original.output_movement_id THEN
            RAISE EXCEPTION 'A production reversal must restore the original output and inputs exactly';
        END IF;
    END IF;
    FOR input_move IN SELECT value FROM jsonb_array_elements(input_moves) LOOP
        IF jsonb_typeof(input_move.value) <> 'object' OR NOT (input_move.value ?& ARRAY['movement_id', 'pool_id', 'quantity', 'value_minor']) THEN
            RAISE EXCEPTION 'Invalid production input plan';
        END IF;
        PERFORM 1 FROM ledger_inventory_moves m WHERE m.tenant_id = NEW.tenant_id AND m.book_id = NEW.book_id AND m.id = (input_move.value->>'movement_id')::uuid
            AND m.pool_id = (input_move.value->>'pool_id')::uuid AND m.quantity = (input_move.value->>'quantity')::numeric
            AND m.value_minor = (input_move.value->>'value_minor')::numeric AND m.production_id = NEW.id
            AND ((NEW.reverses_id IS NULL AND m.kind = 'issue' AND m.quantity < 0 AND m.value_minor <= 0)
                OR (NEW.reverses_id IS NOT NULL AND m.kind = 'reversal' AND m.quantity > 0 AND m.value_minor >= 0
                    AND EXISTS (SELECT 1 FROM jsonb_array_elements(original.inputs) o WHERE o->>'movement_id' = m.reverses_id::text)))
            AND m.counter_account_id = NEW.wip_account_id AND m.effective_on = NEW.effective_on AND m.transfer_id IS NULL AND m.returns_id IS NULL
            AND m.pool_id <> NEW.output_pool_id;
        IF NOT FOUND THEN RAISE EXCEPTION 'Production inputs must match their component issue movements'; END IF;
        input_value := input_value - (input_move.value->>'value_minor')::numeric;
    END LOOP;
    IF input_value + NEW.conversion_minor <> NEW.output_value_minor THEN RAISE EXCEPTION 'Production must conserve exact component and conversion costs'; END IF;
    IF NEW.conversion_minor > 0 THEN
        SELECT coalesce(sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END), 0) INTO available
            FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
            WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.account_id = NEW.wip_account_id AND j.effective_on <= NEW.effective_on;
        IF available < 0 THEN RAISE EXCEPTION 'Production conversion costs exceed recognized WIP'; END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_production_wip_links() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE previous_tenant text := coalesce(current_setting('noria.ledger_tenant', true), '');
BEGIN
    PERFORM set_config('noria.ledger_tenant', NEW.tenant_id::text, true);
    IF EXISTS (SELECT 1 FROM ledger_productions r WHERE r.tenant_id = NEW.tenant_id AND r.book_id = NEW.book_id AND r.conversion_minor > 0
        AND r.effective_on >= NEW.effective_on AND EXISTS (SELECT 1 FROM ledger_postings p WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id
            AND p.journal_id = NEW.id AND p.account_id = r.wip_account_id)
        AND (SELECT coalesce(sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END), 0)
            FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
            WHERE p.tenant_id = r.tenant_id AND p.book_id = r.book_id AND p.account_id = r.wip_account_id AND j.effective_on <= r.effective_on) < 0) THEN
        RAISE EXCEPTION 'WIP corrections cannot invalidate recognized production conversion costs';
    END IF;
    PERFORM set_config('noria.ledger_tenant', previous_tenant, true);
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_production_links() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE previous_tenant text := coalesce(current_setting('noria.ledger_tenant', true), '');
BEGIN
    PERFORM set_config('noria.ledger_tenant', NEW.tenant_id::text, true);
    IF NEW.production_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_productions p WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id
        AND p.id = NEW.production_id AND (p.output_movement_id = NEW.id OR p.inputs @> jsonb_build_array(jsonb_build_object('movement_id', NEW.id::text)))) THEN
        RAISE EXCEPTION 'Production movements require their matching atomic production';
    END IF;
    PERFORM set_config('noria.ledger_tenant', previous_tenant, true);
    RETURN NEW;
END;
$body$;

ALTER TABLE ledger_productions ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger_productions FORCE ROW LEVEL SECURITY;
CREATE POLICY ledger_tenant ON ledger_productions USING (tenant_id::text = nullif(current_setting('noria.ledger_tenant', true), ''))
    WITH CHECK (tenant_id::text = nullif(current_setting('noria.ledger_tenant', true), ''));
CREATE TRIGGER a_scope BEFORE INSERT OR UPDATE ON ledger_productions FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard();
CREATE TRIGGER b_immutable BEFORE UPDATE OR DELETE ON ledger_productions FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER a_no_truncate BEFORE TRUNCATE ON ledger_productions FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER c_stamp BEFORE INSERT ON ledger_productions FOR EACH ROW EXECUTE FUNCTION ledger_event_stamp();
CREATE TRIGGER d_validate BEFORE INSERT ON ledger_productions FOR EACH ROW EXECUTE FUNCTION ledger_production_validate();
CREATE TRIGGER z_audit AFTER INSERT ON ledger_productions FOR EACH ROW EXECUTE FUNCTION ledger_audit();
CREATE CONSTRAINT TRIGGER zz_ledger_production_links AFTER INSERT ON ledger_inventory_moves
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_production_links();
CREATE CONSTRAINT TRIGGER zz_ledger_production_wip_links AFTER INSERT ON ledger_journals
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_production_wip_links();
