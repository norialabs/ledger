CREATE TABLE ledger_assets (
    tenant_id uuid NOT NULL, book_id uuid NOT NULL, id uuid NOT NULL,
    idempotency_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL, reference varchar(128) NOT NULL,
    effective_on date NOT NULL, available_on date NOT NULL,
    cost_minor numeric NOT NULL CHECK (cost_minor > 0 AND cost_minor = trunc(cost_minor) AND cost_minor < power(10::numeric, 38)),
    residual_minor numeric NOT NULL CHECK (residual_minor >= 0 AND residual_minor = trunc(residual_minor)),
    opening_depreciation_minor numeric NOT NULL DEFAULT 0 CHECK (opening_depreciation_minor >= 0 AND opening_depreciation_minor = trunc(opening_depreciation_minor)),
    opening_impairment_minor numeric NOT NULL DEFAULT 0 CHECK (opening_impairment_minor >= 0 AND opening_impairment_minor = trunc(opening_impairment_minor)),
    cost_account_id uuid NOT NULL, depreciation_account_id uuid NOT NULL, impairment_account_id uuid NOT NULL,
    expense_account_id uuid NOT NULL, counter_account_id uuid NOT NULL, journal_id uuid NOT NULL,
    plan jsonb NOT NULL CHECK (jsonb_typeof(plan) = 'array'), dimensions jsonb NOT NULL CHECK (jsonb_typeof(dimensions) = 'object'),
    stub_convention varchar(32) NOT NULL CHECK (stub_convention IN ('actual_days', 'completed_periods', 'full_period')),
    policy_version varchar(128) NOT NULL, recorded_at timestamptz NOT NULL, sequence bigint NOT NULL, actor_id varchar(128) NOT NULL, firm_id varchar(128),
    PRIMARY KEY (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, reference), UNIQUE (tenant_id, book_id, idempotency_key), UNIQUE (tenant_id, book_id, journal_id),
    FOREIGN KEY (tenant_id, book_id, journal_id) REFERENCES ledger_journals (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, cost_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, depreciation_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, impairment_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, expense_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, counter_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    CHECK (cost_minor >= residual_minor + opening_depreciation_minor + opening_impairment_minor),
    CHECK (cost_account_id <> depreciation_account_id AND cost_account_id <> impairment_account_id AND depreciation_account_id <> impairment_account_id)
);

CREATE TABLE ledger_asset_events (
    tenant_id uuid NOT NULL, book_id uuid NOT NULL, id uuid NOT NULL, asset_id uuid NOT NULL,
    idempotency_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL, effective_on date NOT NULL,
    kind varchar(16) NOT NULL CHECK (kind IN ('depreciation', 'impairment', 'estimate', 'addition', 'revaluation', 'partial_disposal', 'disposal', 'cancellation', 'reversal')),
    amount_minor numeric NOT NULL DEFAULT 0 CHECK (amount_minor = trunc(amount_minor) AND abs(amount_minor) < power(10::numeric, 38)),
    cost_delta numeric NOT NULL DEFAULT 0 CHECK (cost_delta = trunc(cost_delta) AND abs(cost_delta) < power(10::numeric, 38)),
    fraction numeric CHECK (fraction > 0 AND fraction < 1 AND fraction = trunc(fraction, 18)),
    depreciation_minor numeric NOT NULL CHECK (depreciation_minor = trunc(depreciation_minor) AND abs(depreciation_minor) < power(10::numeric, 38)),
    impairment_minor numeric NOT NULL CHECK (impairment_minor = trunc(impairment_minor) AND abs(impairment_minor) < power(10::numeric, 38)),
    disposal_delta smallint NOT NULL CHECK (disposal_delta BETWEEN -1 AND 1),
    counter_account_id uuid, gain_account_id uuid, loss_account_id uuid,
    plan jsonb CHECK (jsonb_typeof(plan) = 'array'), residual_minor numeric CHECK (residual_minor = trunc(residual_minor) AND residual_minor >= 0 AND residual_minor < power(10::numeric, 38)), plan_id uuid, line_no integer,
    journal_id uuid, reverses_id uuid, policy_version varchar(128) NOT NULL, reason varchar(1024) NOT NULL,
    recorded_at timestamptz NOT NULL, sequence bigint NOT NULL, actor_id varchar(128) NOT NULL, firm_id varchar(128),
    PRIMARY KEY (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, idempotency_key), UNIQUE (tenant_id, book_id, journal_id), UNIQUE (tenant_id, book_id, reverses_id),
    FOREIGN KEY (tenant_id, book_id, asset_id) REFERENCES ledger_assets (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, journal_id) REFERENCES ledger_journals (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, reverses_id) REFERENCES ledger_asset_events (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, counter_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, gain_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, loss_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    CHECK ((kind IN ('estimate', 'impairment', 'addition', 'revaluation', 'partial_disposal')) = (plan IS NOT NULL)),
    CHECK ((kind IN ('estimate', 'impairment', 'addition', 'revaluation', 'partial_disposal')) = (residual_minor IS NOT NULL)),
    CHECK ((kind = 'depreciation') = (plan_id IS NOT NULL)),
    CHECK ((kind = 'depreciation') = (line_no IS NOT NULL)),
    CHECK (line_no > 0),
    CHECK ((kind IN ('impairment', 'disposal', 'addition', 'revaluation', 'partial_disposal')) = (counter_account_id IS NOT NULL)),
    CHECK ((kind IN ('disposal', 'partial_disposal')) = (gain_account_id IS NOT NULL)),
    CHECK ((kind IN ('disposal', 'partial_disposal')) = (loss_account_id IS NOT NULL)),
    CHECK ((kind = 'partial_disposal') = (fraction IS NOT NULL)),
    CHECK ((kind = 'reversal') = (reverses_id IS NOT NULL)),
    CHECK (kind IN ('impairment', 'disposal', 'addition', 'revaluation', 'partial_disposal') OR amount_minor = 0),
    CHECK (kind IN ('addition', 'revaluation', 'partial_disposal', 'reversal') OR cost_delta = 0)
);

CREATE INDEX ledger_asset_history ON ledger_asset_events (tenant_id, book_id, asset_id, sequence);

CREATE OR REPLACE FUNCTION ledger_asset_row(account uuid, amount numeric) RETURNS jsonb LANGUAGE sql IMMUTABLE AS $body$
    SELECT CASE WHEN amount = 0 THEN '[]'::jsonb ELSE jsonb_build_array(jsonb_build_object('account_id', account::text,
        'side', CASE WHEN amount > 0 THEN 'debit' ELSE 'credit' END, 'amount_minor', trunc(abs(amount))::text)) END;
$body$;

CREATE OR REPLACE FUNCTION ledger_asset_plan_check(plan jsonb, starts_on date, expected numeric) RETURNS boolean LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE line jsonb; previous date; total numeric := 0;
BEGIN
    IF plan IS NULL OR expected IS NULL OR starts_on IS NULL OR jsonb_typeof(plan) <> 'array' OR jsonb_array_length(plan) > 10000 OR expected < 0 THEN RAISE EXCEPTION 'Invalid asset plan'; END IF;
    FOR line IN SELECT value FROM jsonb_array_elements(plan) LOOP
        IF jsonb_typeof(line) <> 'object' OR line->>'amount_minor' IS NULL OR (line->>'amount_minor') !~ '^[0-9]{1,38}$'
            OR line->>'due_on' IS NULL OR (line->>'due_on') !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' OR (line->>'due_on')::date < starts_on
            OR (previous IS NOT NULL AND (line->>'due_on')::date <= previous) THEN RAISE EXCEPTION 'Invalid asset plan dates or amounts'; END IF;
        previous := (line->>'due_on')::date;
        total := total + (line->>'amount_minor')::numeric;
    END LOOP;
    IF total <> expected THEN RAISE EXCEPTION 'Asset plan must conserve the remaining depreciable value'; END IF;
    RETURN true;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_asset_quote(tenant uuid, book uuid, asset_id_arg uuid, as_of date, through_sequence bigint)
RETURNS jsonb LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE
    asset ledger_assets%ROWTYPE; revision record; plan jsonb; active_plan_id uuid; residual numeric; dep numeric; impairment numeric;
    disposed boolean; carrying numeric; cost numeric; starts_on date; period_start date; line jsonb; ordinal bigint;
    pending jsonb := '[]'; pending_value numeric := 0; stub numeric := 0; completed boolean; policy text;
BEGIN
    SELECT * INTO STRICT asset FROM ledger_assets WHERE tenant_id = tenant AND book_id = book AND id = asset_id_arg;
    IF asset.effective_on > as_of OR asset.sequence > through_sequence THEN RAISE EXCEPTION 'Asset was not recognized at this snapshot'; END IF;
    SELECT asset.opening_depreciation_minor + coalesce(sum(depreciation_minor), 0), asset.opening_impairment_minor + coalesce(sum(impairment_minor), 0), coalesce(sum(disposal_delta), 0) > 0,
        asset.cost_minor + coalesce(sum(cost_delta), 0)
        INTO dep, impairment, disposed, cost FROM ledger_asset_events WHERE tenant_id = tenant AND book_id = book AND asset_id = asset_id_arg
        AND effective_on <= as_of AND sequence <= through_sequence;
    SELECT e.id, e.plan, e.residual_minor, e.effective_on, e.policy_version INTO revision FROM ledger_asset_events e
        WHERE e.tenant_id = tenant AND e.book_id = book AND e.asset_id = asset_id_arg AND e.plan IS NOT NULL AND e.reverses_id IS NULL
        AND e.effective_on <= as_of AND e.sequence <= through_sequence AND NOT EXISTS (SELECT 1 FROM ledger_asset_events r
            WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id AND r.effective_on <= as_of AND r.sequence <= through_sequence)
        ORDER BY e.sequence DESC LIMIT 1;
    plan := coalesce(revision.plan, asset.plan);
    active_plan_id := coalesce(revision.id, asset.id);
    residual := coalesce(revision.residual_minor, asset.residual_minor);
    starts_on := greatest(coalesce(revision.effective_on + 1, asset.effective_on), asset.available_on);
    policy := coalesce(revision.policy_version, asset.policy_version);
    carrying := CASE WHEN disposed THEN 0 ELSE cost - dep - impairment END;
    period_start := starts_on;
    IF NOT disposed THEN
        FOR line, ordinal IN SELECT value, ordinality FROM jsonb_array_elements(plan) WITH ORDINALITY LOOP
            SELECT EXISTS (SELECT 1 FROM ledger_asset_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.asset_id = asset_id_arg
                AND e.kind = 'depreciation' AND e.plan_id = active_plan_id AND e.line_no = ordinal AND e.effective_on <= as_of AND e.sequence <= through_sequence
                AND NOT EXISTS (SELECT 1 FROM ledger_asset_events r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id
                    AND r.effective_on <= as_of AND r.sequence <= through_sequence)) INTO completed;
            IF (line->>'due_on')::date <= as_of THEN
                IF NOT completed THEN
                    pending := pending || jsonb_build_array(jsonb_build_object('line_no', ordinal::text, 'due_on', line->>'due_on'));
                    pending_value := pending_value + (line->>'amount_minor')::numeric;
                END IF;
                period_start := (line->>'due_on')::date + 1;
            ELSE
                IF as_of >= period_start THEN
                    stub := CASE asset.stub_convention WHEN 'actual_days' THEN
                        ledger_fraction((line->>'amount_minor')::numeric * (as_of - period_start + 1), (line->>'due_on')::date - period_start + 1, 'half_even')
                        WHEN 'full_period' THEN (line->>'amount_minor')::numeric ELSE 0 END;
                END IF;
                EXIT;
            END IF;
        END LOOP;
    END IF;
    RETURN jsonb_build_object('cost_minor', cost::text, 'depreciation_minor', dep::text, 'impairment_minor', impairment::text,
        'carrying_minor', carrying::text, 'residual_minor', residual::text, 'disposed', disposed, 'plan', plan, 'plan_id', active_plan_id::text,
        'policy_version', policy, 'pending', pending, 'pending_minor', pending_value::text, 'stub_minor', stub::text, 'due_minor', (pending_value + stub)::text);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_asset_event_quote(tenant uuid, book uuid, asset_arg uuid, operation text, date_arg date, amount numeric,
    counter uuid, gain uuid, loss uuid, residual numeric, replacement jsonb, selected_plan uuid, selected_line integer, fraction_arg numeric DEFAULT NULL)
RETURNS jsonb LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    asset ledger_assets%ROWTYPE; state jsonb; line jsonb; dep numeric := 0; impairment numeric := 0; disposal integer := 0;
    carrying numeric; stub numeric; rows jsonb := '[]'; functional_code text; cost_change numeric := 0;
    removed_cost numeric; removed_dep numeric; removed_imp numeric; removed_carrying numeric;
BEGIN
    SELECT * INTO STRICT asset FROM ledger_assets WHERE tenant_id = tenant AND book_id = book AND id = asset_arg;
    IF EXISTS (SELECT 1 FROM ledger_asset_events WHERE tenant_id = tenant AND book_id = book AND asset_id = asset_arg AND effective_on > date_arg) THEN
        RAISE EXCEPTION 'Asset events must follow their existing accounting history';
    END IF;
    state := ledger_asset_quote(tenant, book, asset_arg, date_arg, 9223372036854775807);
    IF (state->>'disposed')::boolean THEN RAISE EXCEPTION 'Asset has already been disposed or cancelled'; END IF;
    carrying := (state->>'carrying_minor')::numeric;
    stub := (state->>'stub_minor')::numeric;
    SELECT currency_code INTO STRICT functional_code FROM ledger_books WHERE tenant_id = tenant AND id = book;
    IF counter IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = counter
        AND NOT is_group AND NOT is_control AND coalesce(currency_code, functional_code) = functional_code AND id NOT IN (asset.cost_account_id, asset.depreciation_account_id, asset.impairment_account_id)) THEN
        RAISE EXCEPTION 'Invalid asset counter account';
    END IF;
    IF counter IS NOT NULL AND (EXISTS (SELECT 1 FROM ledger_assets WHERE tenant_id = tenant AND book_id = book
        AND counter IN (cost_account_id, depreciation_account_id, impairment_account_id))
        OR EXISTS (SELECT 1 FROM ledger_inventory_pools WHERE tenant_id = tenant AND book_id = book AND account_id = counter)) THEN
        RAISE EXCEPTION 'Asset counter accounts cannot bypass valuation registers';
    END IF;
    IF operation = 'depreciation' THEN
        line := state->'plan'->(selected_line - 1);
        IF line IS NULL OR selected_plan IS DISTINCT FROM (state->>'plan_id')::uuid OR (line->>'due_on')::date > date_arg
            OR NOT EXISTS (SELECT 1 FROM jsonb_array_elements(state->'pending') p WHERE (p->>'line_no')::integer = selected_line) THEN
            RAISE EXCEPTION 'Depreciation must match an unposted due plan line';
        END IF;
        dep := (line->>'amount_minor')::numeric;
        IF dep > carrying - (state->>'residual_minor')::numeric THEN RAISE EXCEPTION 'Depreciation cannot consume residual value'; END IF;
    ELSE
        IF (state->>'pending_minor')::numeric <> 0 AND operation <> 'cancellation' THEN RAISE EXCEPTION 'Post due depreciation before changing the asset'; END IF;
        dep := CASE WHEN operation = 'cancellation' THEN 0 ELSE stub END;
    END IF;
    rows := ledger_asset_row(asset.expense_account_id, dep) || ledger_asset_row(asset.depreciation_account_id, -dep);
    IF operation IN ('estimate', 'impairment', 'addition') THEN
        IF operation = 'impairment' THEN
            IF amount <= 0 OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = counter AND type = 'expense') THEN
                RAISE EXCEPTION 'Impairment requires a positive loss and an expense account';
            END IF;
            impairment := amount;
            rows := rows || ledger_asset_row(counter, amount) || ledger_asset_row(asset.impairment_account_id, -amount);
        ELSIF operation = 'addition' THEN
            IF amount <= 0 OR counter IS NULL OR (state->>'cost_minor')::numeric + amount >= power(10::numeric, 38) THEN
                RAISE EXCEPTION 'Asset additions require positive bounded cost and an explicit counter account';
            END IF;
            cost_change := amount;
            rows := rows || ledger_asset_row(asset.cost_account_id, amount) || ledger_asset_row(counter, -amount);
        ELSIF amount <> 0 THEN RAISE EXCEPTION 'An estimate change cannot add unrecognized cost';
        END IF;
        IF residual IS NULL OR residual < 0 OR replacement IS NULL THEN RAISE EXCEPTION 'Asset changes require a residual and prospective plan'; END IF;
        PERFORM ledger_asset_plan_check(replacement, greatest(date_arg + 1, asset.available_on), carrying + cost_change - dep - impairment - residual);
    ELSIF operation = 'revaluation' THEN
        IF amount <= 0 OR counter IS NULL OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = counter
            AND type = 'equity' AND NOT is_group AND NOT is_control AND deleted_at IS NULL AND coalesce(currency_code, functional_code) = functional_code) THEN
            RAISE EXCEPTION 'Upward revaluation requires a positive uplift and a functional-currency equity surplus account';
        END IF;
        cost_change := amount - (state->>'depreciation_minor')::numeric - (state->>'impairment_minor')::numeric - dep;
        IF (state->>'cost_minor')::numeric + cost_change <= 0 OR abs(cost_change) >= power(10::numeric, 38) THEN
            RAISE EXCEPTION 'Revaluation gross cost must remain positive and bounded';
        END IF;
        IF residual IS NULL OR residual < 0 OR replacement IS NULL THEN RAISE EXCEPTION 'Revaluation requires a residual and prospective plan'; END IF;
        PERFORM ledger_asset_plan_check(replacement, greatest(date_arg + 1, asset.available_on), (state->>'cost_minor')::numeric + cost_change - residual);
        rows := rows || ledger_asset_row(asset.depreciation_account_id, (state->>'depreciation_minor')::numeric + dep)
            || ledger_asset_row(asset.impairment_account_id, (state->>'impairment_minor')::numeric)
            || ledger_asset_row(asset.cost_account_id, cost_change)
            || ledger_asset_row(counter, -amount);
        dep := -(state->>'depreciation_minor')::numeric;
        impairment := -(state->>'impairment_minor')::numeric;
    ELSIF operation IN ('disposal', 'partial_disposal') THEN
        IF amount < 0 OR counter IS NULL OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = gain AND type = 'income')
            OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = loss AND type = 'expense') THEN
            RAISE EXCEPTION 'Disposal requires valid proceeds, gain and loss accounts';
        END IF;
        IF operation = 'partial_disposal' THEN
            IF fraction_arg IS NULL OR fraction_arg <= 0 OR fraction_arg >= 1 OR fraction_arg <> trunc(fraction_arg, 18) THEN RAISE EXCEPTION 'Partial disposal requires an exact fraction strictly between zero and one'; END IF;
            removed_cost := ledger_fraction((state->>'cost_minor')::numeric * fraction_arg, 1, 'half_even');
            removed_dep := ledger_fraction(((state->>'depreciation_minor')::numeric + dep) * fraction_arg, 1, 'half_even');
            removed_imp := ledger_fraction((state->>'impairment_minor')::numeric * fraction_arg, 1, 'half_even');
            removed_carrying := removed_cost - removed_dep - removed_imp;
            IF removed_cost <= 0 OR removed_cost >= (state->>'cost_minor')::numeric OR removed_carrying < 0 THEN
                RAISE EXCEPTION 'Partial disposal must remove a positive proper portion of cost with nonnegative carrying value';
            END IF;
            cost_change := -removed_cost;
            impairment := -removed_imp;
            IF residual IS NULL OR residual < 0 OR replacement IS NULL THEN RAISE EXCEPTION 'Partial disposal requires a residual and replacement plan'; END IF;
            PERFORM ledger_asset_plan_check(replacement, greatest(date_arg + 1, asset.available_on), carrying - dep - removed_carrying - residual);
        ELSE
            disposal := 1;
            removed_cost := (state->>'cost_minor')::numeric;
            removed_dep := (state->>'depreciation_minor')::numeric + dep;
            removed_imp := (state->>'impairment_minor')::numeric;
            removed_carrying := carrying - dep;
        END IF;
        rows := rows || ledger_asset_row(counter, amount) || ledger_asset_row(asset.depreciation_account_id, removed_dep)
            || ledger_asset_row(asset.impairment_account_id, removed_imp) || ledger_asset_row(asset.cost_account_id, -removed_cost);
        IF amount > removed_carrying THEN rows := rows || ledger_asset_row(gain, removed_carrying - amount);
        ELSE rows := rows || ledger_asset_row(loss, removed_carrying - amount); END IF;
        IF operation = 'partial_disposal' THEN dep := dep - removed_dep; END IF;
    ELSIF operation = 'cancellation' THEN
        IF EXISTS (SELECT 1 FROM ledger_asset_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.asset_id = asset_arg AND e.reverses_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM ledger_asset_events r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id)) THEN
            RAISE EXCEPTION 'Reverse downstream asset events before cancelling capitalization';
        END IF;
        disposal := 1;
        rows := ledger_asset_row(asset.cost_account_id, -asset.cost_minor) || ledger_asset_row(asset.depreciation_account_id, asset.opening_depreciation_minor)
            || ledger_asset_row(asset.impairment_account_id, asset.opening_impairment_minor)
            || ledger_asset_row(asset.counter_account_id, asset.cost_minor - asset.opening_depreciation_minor - asset.opening_impairment_minor);
    ELSIF operation <> 'depreciation' THEN RAISE EXCEPTION 'Unknown asset event';
    END IF;
    RETURN jsonb_build_object('postings', rows, 'depreciation_minor', dep::text, 'impairment_minor', impairment::text, 'disposal_delta', disposal::text, 'cost_delta', cost_change::text);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_asset_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    asset ledger_assets%ROWTYPE; original ledger_asset_events%ROWTYPE; expected jsonb; rows jsonb; actual jsonb; latest uuid; functional_code text;
BEGIN
    PERFORM 1 FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id FOR UPDATE;
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id, (to_jsonb(NEW)->>'reverses_id') IS NOT NULL);
    SELECT currency_code INTO STRICT functional_code FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id;
    IF TG_TABLE_NAME = 'ledger_assets' THEN
        PERFORM ledger_require_live_dimensions(NEW.tenant_id, NEW.book_id, NEW.dimensions);
        IF (SELECT count(*) FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND id IN (NEW.cost_account_id, NEW.depreciation_account_id, NEW.impairment_account_id) AND type = 'asset'
            AND NOT is_group AND NOT is_control AND deleted_at IS NULL AND coalesce(currency_code, functional_code) = functional_code AND cash_flow IS DISTINCT FROM 'cash') <> 3
            OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.expense_account_id AND type = 'expense' AND NOT is_group AND deleted_at IS NULL)
            OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.counter_account_id AND NOT is_group AND NOT is_control
                AND coalesce(currency_code, functional_code) = functional_code AND id NOT IN (NEW.cost_account_id, NEW.depreciation_account_id, NEW.impairment_account_id))
            OR EXISTS (SELECT 1 FROM ledger_inventory_pools WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
                AND account_id IN (NEW.cost_account_id, NEW.depreciation_account_id, NEW.impairment_account_id)) THEN
            RAISE EXCEPTION 'Invalid functional-currency asset account mappings';
        END IF;
        IF EXISTS (SELECT 1 FROM ledger_assets WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND NEW.counter_account_id IN (cost_account_id, depreciation_account_id, impairment_account_id))
            OR EXISTS (SELECT 1 FROM ledger_inventory_pools WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND account_id = NEW.counter_account_id) THEN
            RAISE EXCEPTION 'Capitalization counter accounts cannot bypass valuation registers';
        END IF;
        IF EXISTS (SELECT 1 FROM ledger_assets WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND (cost_account_id IN (NEW.depreciation_account_id, NEW.impairment_account_id)
                OR depreciation_account_id IN (NEW.cost_account_id, NEW.impairment_account_id)
                OR impairment_account_id IN (NEW.cost_account_id, NEW.depreciation_account_id))) THEN
            RAISE EXCEPTION 'Shared asset accounts must retain the same valuation role';
        END IF;
        PERFORM ledger_asset_plan_check(NEW.plan, greatest(NEW.effective_on, NEW.available_on), NEW.cost_minor - NEW.residual_minor - NEW.opening_depreciation_minor - NEW.opening_impairment_minor);
        IF EXISTS (SELECT 1 FROM ledger_postings p WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id
            AND p.account_id IN (NEW.cost_account_id, NEW.depreciation_account_id, NEW.impairment_account_id) AND p.journal_id <> NEW.journal_id
            AND NOT EXISTS (SELECT 1 FROM ledger_assets a WHERE a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.journal_id = p.journal_id)
            AND NOT EXISTS (SELECT 1 FROM ledger_asset_events e WHERE e.tenant_id = p.tenant_id AND e.book_id = p.book_id AND e.journal_id = p.journal_id)) THEN
            RAISE EXCEPTION 'Reconcile untracked asset balances before registration';
        END IF;
        rows := ledger_asset_row(NEW.cost_account_id, NEW.cost_minor) || ledger_asset_row(NEW.depreciation_account_id, -NEW.opening_depreciation_minor)
            || ledger_asset_row(NEW.impairment_account_id, -NEW.opening_impairment_minor)
            || ledger_asset_row(NEW.counter_account_id, -NEW.cost_minor + NEW.opening_depreciation_minor + NEW.opening_impairment_minor);
    ELSE
        SELECT * INTO STRICT asset FROM ledger_assets WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.asset_id;
        PERFORM ledger_require_live_dimensions(NEW.tenant_id, NEW.book_id, asset.dimensions, NEW.kind = 'reversal');
        IF NEW.kind <> 'reversal' AND EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND id IN (asset.cost_account_id, asset.depreciation_account_id, asset.impairment_account_id, asset.expense_account_id) AND deleted_at IS NOT NULL) THEN
            RAISE EXCEPTION 'Restore soft-deleted asset accounts before new activity';
        END IF;
        IF NEW.kind = 'reversal' THEN
            SELECT * INTO STRICT original FROM ledger_asset_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
            SELECT e.id INTO latest FROM ledger_asset_events e WHERE e.tenant_id = NEW.tenant_id AND e.book_id = NEW.book_id AND e.asset_id = NEW.asset_id
                AND e.reverses_id IS NULL AND NOT EXISTS (SELECT 1 FROM ledger_asset_events r WHERE r.tenant_id = e.tenant_id AND r.book_id = e.book_id AND r.reverses_id = e.id)
                ORDER BY e.sequence DESC LIMIT 1;
            IF latest IS DISTINCT FROM original.id OR original.kind IN ('cancellation', 'reversal') OR original.asset_id <> NEW.asset_id
                OR NEW.depreciation_minor <> -original.depreciation_minor OR NEW.impairment_minor <> -original.impairment_minor OR NEW.disposal_delta <> -original.disposal_delta
                OR NEW.cost_delta <> -original.cost_delta
                OR EXISTS (SELECT 1 FROM ledger_asset_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND asset_id = NEW.asset_id AND effective_on > NEW.effective_on)
                OR (original.journal_id IS NULL) <> (NEW.journal_id IS NULL) THEN
                RAISE EXCEPTION 'Reverse asset events in reverse order with exact carrying effects';
            END IF;
            IF original.journal_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
                AND id = NEW.journal_id AND reverses_id = original.journal_id AND effective_on = NEW.effective_on) THEN
                RAISE EXCEPTION 'Asset reversal must reverse its original journal';
            END IF;
            RETURN NEW;
        END IF;
        expected := ledger_asset_event_quote(NEW.tenant_id, NEW.book_id, NEW.asset_id, NEW.kind, NEW.effective_on, NEW.amount_minor,
            NEW.counter_account_id, NEW.gain_account_id, NEW.loss_account_id, NEW.residual_minor, NEW.plan, NEW.plan_id, NEW.line_no, NEW.fraction);
        IF NEW.depreciation_minor <> (expected->>'depreciation_minor')::numeric OR NEW.impairment_minor <> (expected->>'impairment_minor')::numeric
            OR NEW.disposal_delta <> (expected->>'disposal_delta')::integer OR NEW.cost_delta <> (expected->>'cost_delta')::numeric
            OR NEW.reverses_id IS NOT NULL THEN RAISE EXCEPTION 'Incorrect asset carrying effects'; END IF;
        rows := expected->'postings';
    END IF;
    IF (jsonb_array_length(rows) = 0) <> (NEW.journal_id IS NULL) THEN RAISE EXCEPTION 'Asset journal does not match its financial effect'; END IF;
    IF NEW.journal_id IS NOT NULL THEN
        SELECT jsonb_agg(jsonb_build_object('account_id', account_id::text, 'side', side, 'amount_minor', amount_minor::text) ORDER BY line_no)
        INTO actual FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id;
        IF actual IS DISTINCT FROM rows OR NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND id = NEW.journal_id AND effective_on = NEW.effective_on AND NOT closing) OR EXISTS (SELECT 1 FROM ledger_postings WHERE tenant_id = NEW.tenant_id
                AND book_id = NEW.book_id AND journal_id = NEW.journal_id AND (party_id IS NOT NULL OR valuation OR currency_code <> functional_code
                    OR dimensions <> CASE WHEN TG_TABLE_NAME = 'ledger_assets' THEN to_jsonb(NEW)->'dimensions' ELSE asset.dimensions END)) THEN
            RAISE EXCEPTION 'Asset journal does not match its exact event postings';
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

DO $body$
DECLARE table_name text;
BEGIN
    FOREACH table_name IN ARRAY ARRAY['ledger_assets', 'ledger_asset_events'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', table_name);
        EXECUTE format('CREATE POLICY ledger_tenant ON %I USING (tenant_id::text = nullif(current_setting(''noria.ledger_tenant'', true), '''')) WITH CHECK (tenant_id::text = nullif(current_setting(''noria.ledger_tenant'', true), ''''))', table_name);
        EXECUTE format('CREATE TRIGGER a_scope BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard()', table_name);
        EXECUTE format('CREATE TRIGGER b_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_immutable()', table_name);
        EXECUTE format('CREATE TRIGGER a_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable()', table_name);
        EXECUTE format('CREATE TRIGGER c_stamp BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_event_stamp()', table_name);
        EXECUTE format('CREATE TRIGGER d_validate BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_asset_validate()', table_name);
        EXECUTE format('CREATE TRIGGER z_audit AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_audit()', table_name);
    END LOOP;
END;
$body$;
