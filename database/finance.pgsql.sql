ALTER TABLE ledger_finance_contracts
    ADD CHECK (kind IN ('loan_asset', 'loan_liability', 'lease', 'revenue')),
    ADD CHECK (jsonb_typeof(inputs) = 'object'), ADD CHECK (jsonb_typeof(dimensions) = 'object'),
    ADD CHECK (opening_minor >= 0 AND opening_minor = trunc(opening_minor) AND opening_minor < power(10::numeric, 38)),
    ADD CHECK ((kind = 'loan_asset') = (allowance_account_id IS NOT NULL)), ADD CHECK ((kind = 'lease') = (asset_account_id IS NOT NULL));
ALTER TABLE ledger_finance_events
    ADD CHECK (kind IN ('period', 'ecl', 'recognize', 'writeoff', 'partial_writeoff', 'recovery', 'cancellation', 'modification', 'settlement')),
    ADD CHECK (jsonb_typeof(inputs) = 'object'),
    ADD CHECK (carrying_delta = trunc(carrying_delta) AND abs(carrying_delta) < power(10::numeric, 38)),
    ADD CHECK (allowance_delta = trunc(allowance_delta) AND abs(allowance_delta) < power(10::numeric, 38)),
    ADD CHECK (written_off_delta = trunc(written_off_delta) AND abs(written_off_delta) < power(10::numeric, 38)),
    ADD CHECK (recovered_delta = trunc(recovered_delta) AND abs(recovered_delta) < power(10::numeric, 38)),
    ADD CHECK (stage BETWEEN 1 AND 3), ADD CHECK (prior_stage BETWEEN 1 AND 3), ADD CHECK (length(btrim(reason)) > 0);

CREATE OR REPLACE FUNCTION ledger_finance_amount(value text, currency_scale integer) RETURNS numeric LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE result numeric;
BEGIN
    IF value IS NULL OR length(value) > 128 OR value !~ '^[0-9]+(\.[0-9]+)?$' THEN RAISE EXCEPTION 'Finance amounts require nonnegative plain decimal strings'; END IF;
    result := value::numeric * power(10::numeric, currency_scale);
    IF result <> trunc(result) OR result >= power(10::numeric, 38) THEN RAISE EXCEPTION 'Finance amount exceeds precision or storage bounds'; END IF;
    RETURN trunc(result);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_discounted(payments jsonb, rate numeric, currency_scale integer, start_line integer DEFAULT 0)
RETURNS numeric[] LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE numerator numeric := 0; denominator numeric; base numeric; factor numeric; count_lines integer; i integer;
BEGIN
    IF jsonb_typeof(payments) IS DISTINCT FROM 'array' OR currency_scale IS NULL OR currency_scale NOT BETWEEN 0 AND 12 OR start_line IS NULL OR start_line < 0
        OR rate IS NULL OR rate < 0 OR rate > 1 OR rate <> trunc(rate, 18) THEN RAISE EXCEPTION 'Invalid bounded finance discount plan'; END IF;
    count_lines := jsonb_array_length(payments) - start_line;
    IF count_lines < 0 OR jsonb_array_length(payments) > 600 THEN RAISE EXCEPTION 'Invalid bounded finance discount plan'; END IF;
    base := power(10::numeric, scale(rate)); factor := base + rate * base;
    denominator := power(factor, count_lines);
    FOR i IN 1..count_lines LOOP
        numerator := numerator + ledger_finance_amount(payments->(start_line + i - 1)->>'payment', currency_scale) * power(base, i) * power(factor, count_lines - i);
    END LOOP;
    RETURN ARRAY[numerator, denominator];
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_pv(payments jsonb, rate numeric, currency_scale integer, start_line integer DEFAULT 0)
RETURNS numeric LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE parts numeric[];
BEGIN
    parts := ledger_finance_discounted(payments, rate, currency_scale, start_line);
    RETURN ledger_fraction(parts[1], parts[2], 'half_even');
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_payment_plan(payments jsonb, currency_scale integer, after_date date)
RETURNS void LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE value jsonb; previous date := after_date; payment numeric; paid_interest numeric;
BEGIN
    IF jsonb_typeof(payments) IS DISTINCT FROM 'array' OR jsonb_array_length(payments) NOT BETWEEN 1 AND 600 THEN RAISE EXCEPTION 'Finance needs between one and 600 payment slots'; END IF;
    FOR value IN SELECT * FROM jsonb_array_elements(payments) LOOP
        IF jsonb_typeof(value) IS DISTINCT FROM 'object' OR EXISTS (SELECT 1 FROM jsonb_object_keys(value) key WHERE key NOT IN ('due_on', 'payment', 'interest_paid')) THEN RAISE EXCEPTION 'Unknown finance payment-plan input'; END IF;
        IF jsonb_typeof(value->'due_on') IS DISTINCT FROM 'string' OR value->>'due_on' !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
            OR (value->>'due_on')::date <= previous THEN RAISE EXCEPTION 'Finance payment dates must increase after recognition'; END IF;
        previous := (value->>'due_on')::date;
        IF jsonb_typeof(value->'payment') IS DISTINCT FROM 'string' OR jsonb_typeof(value->'interest_paid') IS DISTINCT FROM 'string' THEN RAISE EXCEPTION 'Finance plan amounts must be decimal strings'; END IF;
        payment := ledger_finance_amount(value->>'payment', currency_scale); paid_interest := ledger_finance_amount(value->>'interest_paid', currency_scale);
        IF paid_interest > payment THEN RAISE EXCEPTION 'Cash interest cannot exceed its payment'; END IF;
    END LOOP;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_slot_date(anchor date, period_months integer, slot_offset integer)
RETURNS date LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE result date;
BEGIN
    IF period_months NOT BETWEEN 1 AND 120 OR slot_offset NOT BETWEEN 0 AND 1200 THEN RAISE EXCEPTION 'Invalid finance rate-slot convention'; END IF;
    result := (anchor + make_interval(months => period_months * slot_offset))::date;
    IF anchor = (date_trunc('month', anchor) + interval '1 month - 1 day')::date THEN result := (date_trunc('month', result) + interval '1 month - 1 day')::date; END IF;
    RETURN result;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_allocations(obligations jsonb, total numeric) RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE weights numeric := 0; assigned numeric := 0; value jsonb; result jsonb := '{}'; item record; amount numeric; remainder_count integer;
BEGIN
    IF jsonb_typeof(obligations) IS DISTINCT FROM 'array' OR jsonb_array_length(obligations) NOT BETWEEN 1 AND 1000 THEN RAISE EXCEPTION 'Revenue needs bounded ordered obligations'; END IF;
    FOR value IN SELECT * FROM jsonb_array_elements(obligations) LOOP
        IF jsonb_typeof(value->'reference') IS DISTINCT FROM 'string' OR value->>'reference' !~ '^[A-Za-z][A-Za-z0-9_.-]{0,119}$'
            OR result ? (value->>'reference') OR jsonb_typeof(value->'standalone_price') IS DISTINCT FROM 'string'
            OR length(value->>'standalone_price') > 128 OR value->>'standalone_price' !~ '^[0-9]+(\.[0-9]+)?$' OR (value->>'standalone_price')::numeric <= 0 THEN RAISE EXCEPTION 'Invalid or duplicate revenue obligation'; END IF;
        weights := weights + (value->>'standalone_price')::numeric;
        result := result || jsonb_build_object(value->>'reference', '0');
    END LOOP;
    FOR value IN SELECT * FROM jsonb_array_elements(obligations) LOOP
        amount := ledger_fraction(total * (value->>'standalone_price')::numeric, weights, 'down');
        assigned := assigned + amount;
        result := jsonb_set(result, ARRAY[value->>'reference'], to_jsonb(amount::text));
    END LOOP;
    remainder_count := (total - assigned)::integer;
    FOR item IN SELECT v->>'reference' AS reference FROM jsonb_array_elements(obligations) WITH ORDINALITY o(v, n)
        ORDER BY total * (v->>'standalone_price')::numeric - (result->>(v->>'reference'))::numeric * weights DESC, n LIMIT remainder_count LOOP
        result := jsonb_set(result, ARRAY[item.reference], to_jsonb(((result->>item.reference)::numeric + 1)::text));
    END LOOP;
    RETURN result;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_initial(operation text, data jsonb, roles jsonb, currency_scale integer, date_arg date)
RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE opening numeric; commencement numeric := 0; rate numeric; carrying numeric; interest numeric; payment numeric; value jsonb;
    payments jsonb; allocations jsonb; rows jsonb; carrying_account uuid; allowance_account uuid; asset_account uuid; period_months integer;
BEGIN
    IF jsonb_typeof(data) IS DISTINCT FROM 'object' OR jsonb_typeof(data->'policy_version') IS DISTINCT FROM 'string'
        OR length(btrim(data->>'policy_version')) NOT BETWEEN 1 AND 128 OR jsonb_typeof(data->'basis_reference') IS DISTINCT FROM 'string'
        OR length(btrim(data->>'basis_reference')) NOT BETWEEN 1 AND 256 THEN RAISE EXCEPTION 'Finance registration requires policy and basis evidence'; END IF;
    IF EXISTS (SELECT 1 FROM jsonb_object_keys(data) key WHERE NOT key = ANY(CASE operation
        WHEN 'revenue' THEN ARRAY['policy_version', 'basis_reference', 'transaction_price', 'obligations']
        WHEN 'lease' THEN ARRAY['policy_version', 'basis_reference', 'rate_per_slot', 'rate_period_months', 'payments', 'interest_cash_flow', 'commencement_payment']
        ELSE ARRAY['policy_version', 'basis_reference', 'opening', 'rate_per_slot', 'rate_period_months', 'payments', 'interest_cash_flow'] END)) THEN RAISE EXCEPTION 'Unknown registered finance input'; END IF;
    IF operation = 'revenue' THEN
        opening := ledger_finance_amount(data->>'transaction_price', currency_scale);
        allocations := ledger_finance_allocations(data->'obligations', opening);
        carrying_account := (roles->>'contract.liability')::uuid;
    ELSE
        IF operation NOT IN ('loan_asset', 'loan_liability', 'lease') OR jsonb_typeof(data->'rate_per_slot') IS DISTINCT FROM 'string'
            OR length(data->>'rate_per_slot') > 32 OR data->>'rate_per_slot' !~ '^[0-9]+(\.[0-9]+)?$' THEN RAISE EXCEPTION 'Invalid finance kind or effective rate'; END IF;
        rate := (data->>'rate_per_slot')::numeric;
        IF rate < 0 OR rate > 1 OR rate <> trunc(rate, 18) THEN RAISE EXCEPTION 'Finance rates must be between zero and one with at most 18 decimals'; END IF;
        payments := data->'payments';
        PERFORM ledger_finance_payment_plan(payments, currency_scale, date_arg);
        IF data ? 'rate_period_months' THEN
            IF jsonb_typeof(data->'rate_period_months') IS DISTINCT FROM 'number' OR data->>'rate_period_months' !~ '^[1-9][0-9]{0,2}$' OR (data->>'rate_period_months')::integer NOT BETWEEN 1 AND 120 THEN RAISE EXCEPTION 'Finance rate periods must be integer months between one and 120'; END IF;
            period_months := (data->>'rate_period_months')::integer;
            IF EXISTS (SELECT 1 FROM jsonb_array_elements(payments) WITH ORDINALITY p(value, n) WHERE (p.value->>'due_on')::date <> ledger_finance_slot_date((payments->0->>'due_on')::date, period_months, (p.n - 1)::integer)) THEN RAISE EXCEPTION 'Payment dates do not match the declared finance rate convention'; END IF;
        END IF;
        IF operation = 'lease' THEN
            commencement := ledger_finance_amount(coalesce(data->>'commencement_payment', '0'), currency_scale);
            opening := ledger_finance_pv(payments, rate, currency_scale);
            carrying_account := (roles->>'lease.liability')::uuid; asset_account := (roles->>'lease.asset')::uuid;
        ELSE
            opening := ledger_finance_amount(data->>'opening', currency_scale);
            carrying_account := (roles->>'loan')::uuid;
            IF operation = 'loan_asset' THEN allowance_account := (roles->>'ecl.allowance')::uuid; END IF;
        END IF;
        carrying := opening;
        IF operation <> 'lease' THEN
            FOR value IN SELECT * FROM jsonb_array_elements(payments) LOOP
                payment := ledger_finance_amount(value->>'payment', currency_scale);
                interest := ledger_fraction(carrying * rate, 1, 'half_even');
                carrying := carrying + interest - payment;
                IF carrying < 0 THEN RAISE EXCEPTION 'Loan payments exceed carrying value'; END IF;
            END LOOP;
            IF carrying <> 0 THEN RAISE EXCEPTION 'Loan payment plan must close exactly at zero'; END IF;
        END IF;
        IF data->>'interest_cash_flow' IS NULL OR data->>'interest_cash_flow' NOT IN ('operating', 'investing', 'financing') THEN RAISE EXCEPTION 'Finance interest requires an explicit cash-flow policy'; END IF;
    END IF;
    IF opening + commencement <= 0 OR carrying_account IS NULL OR (roles->>'cash') IS NULL
        OR (operation = 'loan_asset' AND (allowance_account IS NULL OR roles->>'ecl.expense' IS NULL))
        OR (operation = 'lease' AND asset_account IS NULL) OR (operation <> 'revenue' AND roles->>'interest' IS NULL) THEN RAISE EXCEPTION 'Finance registration requires positive value and complete account roles'; END IF;
    IF operation = 'loan_asset' THEN
        rows := ledger_asset_row(carrying_account, opening) || ledger_asset_row((roles->>'cash')::uuid, -opening);
    ELSIF operation = 'lease' THEN
        rows := ledger_asset_row(asset_account, opening + commencement) || ledger_asset_row(carrying_account, -opening) || ledger_asset_row((roles->>'cash')::uuid, -commencement);
    ELSE
        rows := ledger_asset_row((roles->>'cash')::uuid, opening) || ledger_asset_row(carrying_account, -opening);
    END IF;
    RETURN jsonb_build_object('opening_minor', opening::text, 'carrying_account_id', carrying_account, 'allowance_account_id', allowance_account,
        'asset_account_id', asset_account, 'postings', rows, 'allocations', allocations,
        'cash_flow', jsonb_build_object(CASE operation WHEN 'loan_asset' THEN 'investing' WHEN 'revenue' THEN 'operating' ELSE 'financing' END,
            (CASE operation WHEN 'loan_asset' THEN -opening WHEN 'lease' THEN -commencement ELSE opening END)::text));
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_position(tenant uuid, book uuid, contract uuid, as_of date, through_sequence bigint)
RETURNS jsonb LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE definition ledger_finance_contracts%ROWTYPE; result jsonb; current_stage integer; settled boolean;
BEGIN
    SELECT * INTO STRICT definition FROM ledger_finance_contracts WHERE tenant_id = tenant AND book_id = book AND id = contract;
    IF definition.effective_on > as_of OR definition.sequence > through_sequence THEN RAISE EXCEPTION 'Finance contract was not recognized at this snapshot'; END IF;
    SELECT jsonb_build_object('carrying_minor', (definition.opening_minor + coalesce(sum(carrying_delta), 0))::text,
        'allowance_minor', coalesce(sum(allowance_delta), 0)::text, 'written_off_minor', coalesce(sum(written_off_delta), 0)::text,
        'recovered_minor', coalesce(sum(recovered_delta), 0)::text,
        'cancelled', coalesce(bool_or(kind = 'cancellation'), false)) INTO result
        FROM ledger_finance_events WHERE tenant_id = tenant AND book_id = book AND contract_id = contract AND effective_on <= as_of AND sequence <= through_sequence;
    SELECT e.stage INTO current_stage FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract
        AND e.effective_on <= as_of AND e.sequence <= through_sequence ORDER BY e.sequence DESC LIMIT 1;
    SELECT EXISTS (SELECT 1 FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.kind = 'settlement'
        AND e.reverses_id IS NULL AND e.effective_on <= as_of AND e.sequence <= through_sequence AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r
            WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id AND r.effective_on <= as_of AND r.sequence <= through_sequence)) INTO settled;
    RETURN result || jsonb_build_object('stage', coalesce(current_stage, 1), 'settled', settled);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_terms(tenant uuid, book uuid, contract uuid, as_of date, through_sequence bigint)
RETURNS jsonb LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE definition ledger_finance_contracts%ROWTYPE; amendment ledger_finance_events%ROWTYPE; payments jsonb; plan_id uuid; activation bigint; plan_date date; carrying_date date;
    next_line integer; actual_required boolean; scheduled_carrying numeric; currency_scale integer; i integer;
BEGIN
    SELECT * INTO STRICT definition FROM ledger_finance_contracts WHERE tenant_id = tenant AND book_id = book AND id = contract;
    IF definition.effective_on > as_of OR definition.sequence > through_sequence THEN RAISE EXCEPTION 'Finance contract was not recognized at this snapshot'; END IF;
    IF definition.kind = 'revenue' THEN RAISE EXCEPTION 'Revenue contracts have no payment plan'; END IF;
    SELECT e.* INTO amendment FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.kind = 'modification'
        AND e.reverses_id IS NULL AND e.effective_on <= as_of AND e.sequence <= through_sequence AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r
            WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id AND r.effective_on <= as_of AND r.sequence <= through_sequence) ORDER BY e.sequence DESC LIMIT 1;
    plan_id := coalesce(amendment.id, definition.id); activation := coalesce(amendment.sequence, definition.sequence); plan_date := coalesce(amendment.effective_on, definition.effective_on);
    payments := coalesce(amendment.inputs->'payments', definition.inputs->'payments');
    SELECT coalesce(max(e.effective_on), definition.effective_on) INTO carrying_date FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract
        AND e.kind IN ('period', 'modification', 'settlement') AND e.effective_on <= as_of AND e.sequence <= through_sequence;
    SELECT count(*) + 1, coalesce(bool_or(e.inputs ? 'paid_amount' AND (e.inputs->>'paid_amount')::numeric < (payments->((e.inputs->>'line')::integer - 1)->>'payment')::numeric), false)
        INTO next_line, actual_required FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.kind = 'period'
        AND e.sequence > activation AND e.effective_on <= as_of AND e.sequence <= through_sequence AND e.reverses_id IS NULL
        AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id AND r.effective_on <= as_of AND r.sequence <= through_sequence);
    SELECT c.scale INTO STRICT currency_scale FROM ledger_books b JOIN ledger_currencies c ON c.tenant_id = b.tenant_id AND c.code = b.currency_code WHERE b.tenant_id = tenant AND b.id = book;
    IF amendment.id IS NOT NULL OR definition.kind = 'lease' THEN
        scheduled_carrying := ledger_finance_pv(payments, (definition.inputs->>'rate_per_slot')::numeric, currency_scale, next_line - 1);
    ELSE
        scheduled_carrying := definition.opening_minor;
        FOR i IN 1..next_line - 1 LOOP
            scheduled_carrying := scheduled_carrying + ledger_fraction(scheduled_carrying * (definition.inputs->>'rate_per_slot')::numeric, 1, 'half_even')
                - ledger_finance_amount(payments->(i - 1)->>'payment', currency_scale);
        END LOOP;
    END IF;
    RETURN jsonb_build_object('plan_id', plan_id, 'plan_sequence', activation::text, 'effective_on', plan_date, 'carrying_on', carrying_date,
        'payments', payments, 'rate_per_slot', definition.inputs->>'rate_per_slot', 'rate_period_months', definition.inputs->'rate_period_months', 'next_line', next_line, 'requires_actual_payment', actual_required,
        'scheduled_carrying_minor', scheduled_carrying::text,
        'measurement', CASE WHEN amendment.id IS NOT NULL OR definition.kind = 'lease' THEN 'present_value' ELSE 'period_rounding' END);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_ecl(data jsonb, rate numeric, currency_scale integer) RETURNS numeric LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE flow jsonb; scenario jsonb; weight numeric; weights numeric := 0; weighted numeric := 0; shortfall numeric; amount numeric; receipt numeric;
    period integer; previous integer := -1; maximum integer; i integer; base numeric; factor numeric;
BEGIN
    IF jsonb_typeof(data->'contractual') IS DISTINCT FROM 'array' OR jsonb_array_length(data->'contractual') NOT BETWEEN 1 AND 600
        OR jsonb_typeof(data->'scenarios') IS DISTINCT FROM 'array' OR jsonb_array_length(data->'scenarios') NOT BETWEEN 1 AND 100 THEN RAISE EXCEPTION 'ECL needs bounded cash flows and scenarios'; END IF;
    FOR flow IN SELECT * FROM jsonb_array_elements(data->'contractual') LOOP
        IF jsonb_typeof(flow->'period') IS DISTINCT FROM 'number' OR flow->>'period' !~ '^[0-9]{1,3}$' THEN RAISE EXCEPTION 'ECL periods must be bounded integers'; END IF;
        period := (flow->>'period')::integer;
        IF period <= previous OR period > 600 THEN RAISE EXCEPTION 'ECL periods must increase between zero and 600'; END IF;
        previous := period;
    END LOOP;
    maximum := previous; base := power(10::numeric, scale(rate)); factor := base + rate * base;
    FOR scenario IN SELECT * FROM jsonb_array_elements(data->'scenarios') LOOP
        IF jsonb_typeof(scenario->'weight') IS DISTINCT FROM 'string' OR length(scenario->>'weight') > 32
            OR scenario->>'weight' !~ '^[0-9]+(\.[0-9]+)?$' OR jsonb_typeof(scenario->'receipts') IS DISTINCT FROM 'array'
            OR jsonb_array_length(scenario->'receipts') <> jsonb_array_length(data->'contractual') THEN RAISE EXCEPTION 'Invalid ECL scenario'; END IF;
        weight := (scenario->>'weight')::numeric;
        IF weight < 0 OR weight > 1 OR weight <> trunc(weight, 18) THEN RAISE EXCEPTION 'Invalid bounded ECL probability'; END IF;
        weights := weights + weight; shortfall := 0; i := 0;
        FOR flow IN SELECT * FROM jsonb_array_elements(data->'contractual') LOOP
            period := (flow->>'period')::integer;
            amount := ledger_finance_amount(flow->>'amount', currency_scale);
            receipt := ledger_finance_amount(scenario->'receipts'->>i, currency_scale);
            shortfall := shortfall + (amount - receipt) * power(base, period) * power(factor, maximum - period);
            i := i + 1;
        END LOOP;
        IF shortfall < 0 THEN RAISE EXCEPTION 'ECL receipts cannot exceed contractual present value'; END IF;
        weighted := weighted + shortfall * weight;
    END LOOP;
    IF weights <> 1 THEN RAISE EXCEPTION 'ECL probabilities must sum to exactly one'; END IF;
    RETURN ledger_fraction(weighted, power(factor, maximum), 'half_even');
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_quote(tenant uuid, book uuid, contract uuid, date_arg date, operation text, data jsonb, excluded_journal uuid DEFAULT NULL)
RETURNS jsonb LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE definition ledger_finance_contracts%ROWTYPE; roles jsonb; state jsonb; currency_scale integer; carry numeric; allowance numeric; written_off numeric; recovered numeric;
    cd numeric := 0; ad numeric := 0; wd numeric := 0; rd numeric := 0; old_stage integer; new_stage integer;
    rows jsonb := '[]'; cash_flow jsonb; index_arg integer; next_index integer; line jsonb; payment numeric; interest numeric; net_interest numeric; release numeric;
    rate numeric; paid_interest numeric; sign_arg integer; principal_category text; category text; progress numeric; prior_progress numeric; allocations jsonb; allocated numeric; prior_recognized numeric;
    terms jsonb; payments jsonb; plan_id uuid; scheduled numeric; adjustment numeric := 0; model jsonb; remeasured numeric; counter_amount numeric;
    gain_account uuid; loss_account uuid; account_arg record; functional_code text; calculation jsonb; flow jsonb; flow_index integer;
    period_months integer; slot_offset integer; anchor_date date; active_date date; discounted numeric[]; original_discounted numeric[]; remeasured_rou numeric;
BEGIN
    SELECT * INTO STRICT definition FROM ledger_finance_contracts WHERE tenant_id = tenant AND book_id = book AND id = contract;
    IF jsonb_typeof(data) IS DISTINCT FROM 'object' OR date_arg < definition.effective_on OR EXISTS (SELECT 1 FROM ledger_finance_events
        WHERE tenant_id = tenant AND book_id = book AND contract_id = contract AND effective_on > date_arg) THEN RAISE EXCEPTION 'Finance events require chronological accounting dates'; END IF;
    IF EXISTS (SELECT 1 FROM jsonb_object_keys(data) key WHERE NOT key = ANY(CASE operation
        WHEN 'period' THEN ARRAY['line', 'plan_id', 'paid_amount', 'interest_paid', 'evidence'] WHEN 'ecl' THEN ARRAY['stage', 'model_reference', 'contractual', 'scenarios', 'evidence']
        WHEN 'recognize' THEN ARRAY['reference', 'cumulative_fraction', 'evidence'] WHEN 'writeoff' THEN ARRAY['evidence'] WHEN 'partial_writeoff' THEN ARRAY['amount', 'evidence']
        WHEN 'recovery' THEN ARRAY['amount', 'evidence']
        WHEN 'modification' THEN ARRAY['payments', 'gain_account_id', 'loss_account_id', 'basis_reference', 'loss_model', 'prepayment', 'interest_paid', 'rou_carrying', 'evidence']
        WHEN 'settlement' THEN ARRAY['amount', 'interest_paid', 'gain_account_id', 'loss_account_id', 'basis_reference', 'evidence']
        ELSE ARRAY[]::text[] END)) THEN RAISE EXCEPTION 'Unknown finance event input'; END IF;
    state := ledger_finance_position(tenant, book, contract, date_arg, 9223372036854775807);
    IF (state->>'cancelled')::boolean THEN RAISE EXCEPTION 'Finance contract was cancelled'; END IF;
    IF (state->>'settled')::boolean THEN RAISE EXCEPTION 'Settled finance contracts require correction before further activity'; END IF;
    SELECT c.roles, u.scale, b.currency_code INTO STRICT roles, currency_scale, functional_code FROM ledger_configurations c JOIN ledger_books b ON b.tenant_id = c.tenant_id AND b.id = c.book_id
        JOIN ledger_currencies u ON u.tenant_id = b.tenant_id AND u.code = b.currency_code WHERE c.tenant_id = tenant AND c.book_id = book AND c.id = definition.configuration_id;
    carry := (state->>'carrying_minor')::numeric; allowance := (state->>'allowance_minor')::numeric;
    written_off := (state->>'written_off_minor')::numeric; recovered := (state->>'recovered_minor')::numeric;
    old_stage := (state->>'stage')::integer; new_stage := old_stage;
    IF operation IN ('modification', 'settlement') THEN
        IF definition.kind NOT IN ('loan_asset', 'loan_liability', 'lease') OR carry <= 0 THEN RAISE EXCEPTION 'Loan changes require an active positive loan carrying value'; END IF;
        IF operation = 'modification' AND (definition.kind = 'lease' AND (data ? 'prepayment' OR data ? 'interest_paid' OR data ? 'loss_model') OR definition.kind <> 'lease' AND data ? 'rou_carrying'
            OR (data ? 'prepayment') <> (data ? 'interest_paid')) THEN RAISE EXCEPTION 'Invalid loan or lease change inputs'; END IF;
        IF jsonb_typeof(data->'basis_reference') IS DISTINCT FROM 'string' OR length(btrim(data->>'basis_reference')) NOT BETWEEN 1 AND 256 THEN RAISE EXCEPTION 'Loan changes require reviewed carrying-basis evidence'; END IF;
        gain_account := (data->>'gain_account_id')::uuid; loss_account := (data->>'loss_account_id')::uuid;
        FOR account_arg IN SELECT * FROM (VALUES (gain_account, 'income'), (loss_account, 'expense')) a(id, type) LOOP
            IF NOT EXISTS (SELECT 1 FROM ledger_accounts a WHERE a.tenant_id = tenant AND a.book_id = book AND a.id = account_arg.id AND a.type = account_arg.type
                AND NOT a.is_group AND NOT a.is_control AND a.active AND a.deleted_at IS NULL AND coalesce(a.currency_code, functional_code) = functional_code AND a.cash_flow IS DISTINCT FROM 'cash')
                OR account_arg.id IN ((roles->>'interest')::uuid, (roles->>'ecl.expense')::uuid) THEN RAISE EXCEPTION 'Loan changes require separate compatible functional gain/loss accounts'; END IF;
            IF EXISTS (SELECT 1 FROM ledger_account_dimensions d WHERE d.tenant_id = tenant AND d.book_id = book AND d.account_id = account_arg.id AND NOT (definition.dimensions ? d.dimension)) THEN RAISE EXCEPTION 'A loan gain/loss account requires a missing dimension'; END IF;
        END LOOP;
        terms := ledger_finance_terms(tenant, book, contract, date_arg, 9223372036854775807);
        IF (terms->>'carrying_on')::date <> date_arg THEN RAISE EXCEPTION 'Loan changes must use a recognized carrying date; partial-slot interest is not inferred'; END IF;
        IF EXISTS (SELECT 1 FROM jsonb_array_elements(terms->'payments') WITH ORDINALITY p(value, n) WHERE p.n >= (terms->>'next_line')::integer AND (p.value->>'due_on')::date <= date_arg) THEN RAISE EXCEPTION 'Recognize due loan slots with actual payments before changing the loan'; END IF;
    END IF;
    IF operation = 'period' THEN
        IF definition.kind = 'revenue' OR carry <= 0 OR jsonb_typeof(data->'line') IS DISTINCT FROM 'number' OR data->>'line' !~ '^[1-9][0-9]{0,2}$' THEN RAISE EXCEPTION 'Invalid finance payment period'; END IF;
        IF written_off > 0 AND NOT data ? 'paid_amount' THEN RAISE EXCEPTION 'After a partial write-off, record the actual cash received'; END IF;
        index_arg := (data->>'line')::integer;
        terms := ledger_finance_terms(tenant, book, contract, date_arg, 9223372036854775807);
        next_index := (terms->>'next_line')::integer; plan_id := (terms->>'plan_id')::uuid; payments := terms->'payments';
        IF data ? 'plan_id' AND (data->>'plan_id')::uuid IS DISTINCT FROM plan_id OR plan_id <> definition.id AND NOT data ? 'plan_id' THEN RAISE EXCEPTION 'Finance period must identify its current payment plan'; END IF;
        line := payments->(index_arg - 1);
        IF index_arg <> next_index OR line IS NULL OR (line->>'due_on')::date > date_arg THEN RAISE EXCEPTION 'Finance periods cannot be skipped, duplicated or posted before due'; END IF;
        rate := (definition.inputs->>'rate_per_slot')::numeric;
        scheduled := ledger_finance_amount(line->>'payment', currency_scale);
        IF (data ? 'paid_amount') <> (data ? 'interest_paid') OR (terms->>'requires_actual_payment')::boolean AND NOT data ? 'paid_amount' THEN RAISE EXCEPTION 'Loan arrears require explicit actual cash and interest allocation'; END IF;
        IF data ? 'paid_amount' THEN
            IF definition.kind NOT IN ('loan_asset', 'loan_liability') THEN RAISE EXCEPTION 'Actual payment overrides are restricted to loans'; END IF;
            payment := ledger_finance_amount(data->>'paid_amount', currency_scale); paid_interest := ledger_finance_amount(data->>'interest_paid', currency_scale);
            IF payment > scheduled OR paid_interest > payment THEN RAISE EXCEPTION 'Actual loan cash cannot exceed its scheduled payment or misallocate interest'; END IF;
        ELSE
            payment := scheduled; paid_interest := ledger_finance_amount(line->>'interest_paid', currency_scale);
        END IF;
        IF terms->>'measurement' = 'present_value' THEN
            remeasured := ledger_finance_pv(payments, rate, currency_scale, index_arg - 1);
            adjustment := ledger_finance_pv(payments, rate, currency_scale, index_arg) - remeasured + scheduled - ledger_fraction(remeasured * rate, 1, 'half_even');
        END IF;
        interest := ledger_fraction(carry * rate, 1, 'half_even') + adjustment;
        IF interest < 0 OR carry + interest - payment < 0 THEN RAISE EXCEPTION 'Invalid finance carrying rollforward'; END IF;
        net_interest := CASE WHEN definition.kind = 'loan_asset' AND old_stage = 3 AND allowance > 0 THEN least(interest, ledger_fraction((carry - allowance) * rate, 1, 'half_even')) ELSE interest END;
        cd := interest - payment;
        sign_arg := CASE WHEN definition.kind = 'loan_asset' THEN 1 ELSE -1 END;
        rows := ledger_asset_row(definition.carrying_account_id, sign_arg * interest) || ledger_asset_row((roles->>'interest')::uuid, -sign_arg * net_interest);
        IF definition.kind = 'loan_asset' THEN
            ad := interest - net_interest;
            rows := rows || ledger_asset_row(definition.allowance_account_id, -ad);
            release := greatest(allowance + ad - (carry + cd), 0);
            ad := ad - release;
            rows := rows || ledger_asset_row(definition.allowance_account_id, release) || ledger_asset_row((roles->>'ecl.expense')::uuid, -release);
        END IF;
        rows := rows || ledger_asset_row(definition.carrying_account_id, -sign_arg * payment) || ledger_asset_row((roles->>'cash')::uuid, sign_arg * payment);
        principal_category := CASE WHEN sign_arg = 1 THEN 'investing' ELSE 'financing' END; category := definition.inputs->>'interest_cash_flow';
        cash_flow := jsonb_build_object(principal_category, (sign_arg * (payment - paid_interest))::text);
        cash_flow := cash_flow || jsonb_build_object(category, (sign_arg * paid_interest + coalesce((cash_flow->>category)::numeric, 0))::text);
        IF plan_id <> definition.id OR data ? 'paid_amount' THEN
            calculation := jsonb_build_object('plan_id', plan_id, 'scheduled_minor', scheduled::text, 'paid_minor', payment::text, 'gross_interest_minor', interest::text,
                'net_interest_minor', net_interest::text, 'rounding_adjustment_minor', adjustment::text, 'opening_minor', carry::text, 'closing_minor', (carry + cd)::text);
        END IF;
    ELSIF operation = 'modification' THEN
        payments := data->'payments';
        PERFORM ledger_finance_payment_plan(payments, currency_scale, date_arg);
        period_months := (terms->>'rate_period_months')::integer;
        IF period_months IS NULL THEN
            IF jsonb_array_length(payments) > jsonb_array_length(terms->'payments') - (terms->>'next_line')::integer + 1 THEN RAISE EXCEPTION 'Extending loan maturity requires a rate-period convention pinned at registration'; END IF;
            IF EXISTS (SELECT 1 FROM jsonb_array_elements(payments) WITH ORDINALITY p(value, n) WHERE p.value->>'due_on' IS DISTINCT FROM terms->'payments'->((terms->>'next_line')::integer + p.n::integer - 2)->>'due_on') THEN RAISE EXCEPTION 'Revised cash flows must preserve the original rate-slot dates'; END IF;
        ELSE
            anchor_date := (definition.inputs->'payments'->0->>'due_on')::date; active_date := (terms->'payments'->0->>'due_on')::date;
            slot_offset := ((extract(year FROM active_date)::integer - extract(year FROM anchor_date)::integer) * 12 + extract(month FROM active_date)::integer - extract(month FROM anchor_date)::integer) / period_months + (terms->>'next_line')::integer - 1;
            IF EXISTS (SELECT 1 FROM jsonb_array_elements(payments) WITH ORDINALITY p(value, n) WHERE (p.value->>'due_on')::date <> ledger_finance_slot_date(anchor_date, period_months, slot_offset + p.n::integer - 1)) THEN RAISE EXCEPTION 'Revised cash flows must preserve the original rate-slot dates'; END IF;
        END IF;
        rate := (definition.inputs->>'rate_per_slot')::numeric;
        discounted := ledger_finance_discounted(payments, rate, currency_scale);
        remeasured := ledger_fraction(discounted[1], discounted[2], 'half_even');
        IF remeasured <= 0 THEN RAISE EXCEPTION 'Use settlement for full contractual extinguishment'; END IF;
        cd := remeasured - carry;
        payment := CASE WHEN data ? 'prepayment' THEN ledger_finance_amount(data->>'prepayment', currency_scale) ELSE 0 END;
        paid_interest := CASE WHEN data ? 'interest_paid' THEN ledger_finance_amount(data->>'interest_paid', currency_scale) ELSE 0 END;
        remeasured_rou := CASE WHEN data ? 'rou_carrying' THEN ledger_finance_amount(data->>'rou_carrying', currency_scale) END;
        IF payment < 0 OR paid_interest < 0 OR paid_interest > payment OR (data ? 'prepayment' AND payment = 0) THEN RAISE EXCEPTION 'A prepayment needs positive cash with interest not exceeding it'; END IF;
        IF definition.kind = 'loan_liability' THEN
            original_discounted := ledger_finance_discounted(terms->'payments', rate, currency_scale, (terms->>'next_line')::integer - 1);
            original_discounted[1] := original_discounted[1] + (carry - (terms->>'scheduled_carrying_minor')::numeric) * original_discounted[2];
            discounted[1] := discounted[1] + payment * discounted[2];
            IF original_discounted[1] <= 0 OR abs(discounted[1] * original_discounted[2] - original_discounted[1] * discounted[2]) * 10 >= original_discounted[1] * discounted[2] THEN RAISE EXCEPTION 'Substantial liability changes require derecognition rather than this modification model'; END IF;
        END IF;
        sign_arg := CASE WHEN definition.kind = 'loan_asset' THEN 1 ELSE -1 END;
        IF definition.kind = 'lease' THEN
            IF cd < 0 AND (remeasured_rou IS NULL OR remeasured_rou > (SELECT coalesce(sum(CASE WHEN side = 'debit' THEN functional_minor ELSE -functional_minor END), 0)
                    FROM ledger_postings WHERE tenant_id = tenant AND book_id = book AND account_id = definition.asset_account_id AND dimensions = definition.dimensions
                    AND journal_id IS DISTINCT FROM excluded_journal)) THEN
                RAISE EXCEPTION 'Reducing a lease liability needs the reviewed right-of-use carrying amount';
            END IF;
            counter_amount := greatest(-cd - coalesce(remeasured_rou, 0), 0);
            rows := ledger_asset_row(definition.carrying_account_id, -cd) || ledger_asset_row(definition.asset_account_id, cd + counter_amount)
                || ledger_asset_row(gain_account, -counter_amount);
        ELSE
            counter_amount := -sign_arg * (cd + payment);
            rows := ledger_asset_row(definition.carrying_account_id, sign_arg * cd) || ledger_asset_row((roles->>'cash')::uuid, sign_arg * payment)
                || ledger_asset_row(CASE WHEN counter_amount > 0 THEN loss_account ELSE gain_account END, counter_amount);
            IF payment > 0 THEN
                principal_category := CASE WHEN sign_arg = 1 THEN 'investing' ELSE 'financing' END; category := definition.inputs->>'interest_cash_flow';
                cash_flow := jsonb_build_object(principal_category, (sign_arg * (payment - paid_interest))::text);
                cash_flow := cash_flow || jsonb_build_object(category, (sign_arg * paid_interest + coalesce((cash_flow->>category)::numeric, 0))::text);
            END IF;
        END IF;
        IF definition.kind = 'loan_asset' THEN
            model := data->'loss_model';
            IF jsonb_typeof(model) IS DISTINCT FROM 'object' OR jsonb_typeof(model->'stage') IS DISTINCT FROM 'number' OR model->>'stage' NOT IN ('1', '2', '3')
                OR jsonb_typeof(model->'model_reference') IS DISTINCT FROM 'string' OR length(btrim(model->>'model_reference')) NOT BETWEEN 1 AND 256 THEN RAISE EXCEPTION 'Loan-asset modifications require an explicit revised ECL model'; END IF;
            IF EXISTS (SELECT 1 FROM jsonb_object_keys(model) key WHERE key NOT IN ('stage', 'model_reference', 'contractual', 'scenarios', 'evidence')) THEN RAISE EXCEPTION 'Unknown revised ECL model input'; END IF;
            IF jsonb_typeof(model->'contractual') IS DISTINCT FROM 'array' OR jsonb_array_length(model->'contractual') < jsonb_array_length(payments) THEN RAISE EXCEPTION 'Modification ECL must cover the revised contractual schedule'; END IF;
            flow_index := 0;
            FOR flow IN SELECT * FROM jsonb_array_elements(model->'contractual') LOOP
                IF flow_index < jsonb_array_length(payments) THEN
                    IF (flow->>'period')::integer IS DISTINCT FROM flow_index + 1 OR ledger_finance_amount(flow->>'amount', currency_scale) <> ledger_finance_amount(payments->flow_index->>'payment', currency_scale) THEN RAISE EXCEPTION 'Modification ECL contractual flows must match the revised payments'; END IF;
                ELSIF ledger_finance_amount(flow->>'amount', currency_scale) <> 0 THEN RAISE EXCEPTION 'Additional modification ECL slots can represent only delayed recovery'; END IF;
                flow_index := flow_index + 1;
            END LOOP;
            ad := ledger_finance_ecl(model, rate, currency_scale) - allowance;
            IF allowance + ad > remeasured THEN RAISE EXCEPTION 'Revised ECL cannot exceed modified gross carrying value'; END IF;
            new_stage := (model->>'stage')::integer;
            rows := rows || ledger_asset_row((roles->>'ecl.expense')::uuid, ad) || ledger_asset_row(definition.allowance_account_id, -ad);
        ELSIF data ? 'loss_model' THEN RAISE EXCEPTION 'Loan liabilities do not use an asset ECL model'; END IF;
        calculation := jsonb_build_object('original_rate_per_slot', definition.inputs->>'rate_per_slot', 'previous_plan_id', terms->>'plan_id',
            'opening_minor', carry::text, 'closing_minor', remeasured::text, 'modification_effect_minor', (sign_arg * cd)::text, 'allowance_before_minor', allowance::text, 'allowance_after_minor', (allowance + ad)::text,
            'prepayment_minor', payment::text, 'counter_effect_minor', counter_amount::text);
    ELSIF operation = 'settlement' THEN
        payment := ledger_finance_amount(data->>'amount', currency_scale); paid_interest := ledger_finance_amount(data->>'interest_paid', currency_scale);
        IF paid_interest > payment THEN RAISE EXCEPTION 'Settlement cash interest cannot exceed total cash'; END IF;
        cd := -carry; ad := -allowance;
        sign_arg := CASE WHEN definition.kind = 'loan_asset' THEN 1 ELSE -1 END;
        counter_amount := sign_arg * (carry - payment);
        rows := ledger_asset_row((roles->>'cash')::uuid, sign_arg * payment) || ledger_asset_row(definition.carrying_account_id, -sign_arg * carry);
        IF definition.kind = 'lease' THEN
            SELECT coalesce(sum(CASE WHEN side = 'debit' THEN functional_minor ELSE -functional_minor END), 0) INTO remeasured
                FROM ledger_postings WHERE tenant_id = tenant AND book_id = book AND account_id = definition.asset_account_id AND dimensions = definition.dimensions
                AND journal_id IS DISTINCT FROM excluded_journal;
            SELECT coalesce(sum(CASE WHEN side = 'credit' THEN functional_minor ELSE -functional_minor END), 0) INTO remeasured_rou
                FROM ledger_postings WHERE tenant_id = tenant AND book_id = book AND account_id = (roles->>'lease.accumulated')::uuid AND dimensions = definition.dimensions
                AND journal_id IS DISTINCT FROM excluded_journal;
            counter_amount := counter_amount + remeasured - remeasured_rou;
            rows := rows || ledger_asset_row(definition.asset_account_id, -remeasured) || ledger_asset_row((roles->>'lease.accumulated')::uuid, remeasured_rou);
        END IF;
        rows := rows || ledger_asset_row(CASE WHEN counter_amount > 0 THEN loss_account ELSE gain_account END, counter_amount);
        IF definition.kind = 'loan_asset' THEN rows := rows || ledger_asset_row(definition.allowance_account_id, allowance) || ledger_asset_row((roles->>'ecl.expense')::uuid, -allowance); END IF;
        principal_category := CASE WHEN sign_arg = 1 THEN 'investing' ELSE 'financing' END; category := definition.inputs->>'interest_cash_flow';
        cash_flow := jsonb_build_object(principal_category, (sign_arg * (payment - paid_interest))::text);
        cash_flow := cash_flow || jsonb_build_object(category, (sign_arg * paid_interest + coalesce((cash_flow->>category)::numeric, 0))::text);
        calculation := jsonb_build_object('plan_id', terms->>'plan_id', 'carrying_minor', carry::text, 'cash_minor', payment::text, 'interest_paid_minor', paid_interest::text,
            'settlement_effect_minor', (-counter_amount)::text, 'allowance_released_minor', allowance::text);
    ELSIF operation = 'ecl' THEN
        IF definition.kind <> 'loan_asset' OR carry <= 0 OR data->>'stage' NOT IN ('1', '2', '3') OR jsonb_typeof(data->'stage') IS DISTINCT FROM 'number'
            OR jsonb_typeof(data->'model_reference') IS DISTINCT FROM 'string' OR length(btrim(data->>'model_reference')) NOT BETWEEN 1 AND 256 THEN RAISE EXCEPTION 'ECL requires a live loan exposure, reviewed stage and model evidence'; END IF;
        new_stage := (data->>'stage')::integer;
        ad := ledger_finance_ecl(data, (definition.inputs->>'rate_per_slot')::numeric, currency_scale) - allowance;
        IF allowance + ad > carry THEN RAISE EXCEPTION 'ECL allowance cannot exceed gross carrying value'; END IF;
        rows := ledger_asset_row((roles->>'ecl.expense')::uuid, ad) || ledger_asset_row(definition.allowance_account_id, -ad);
    ELSIF operation = 'recognize' THEN
        IF definition.kind <> 'revenue' OR jsonb_typeof(data->'reference') IS DISTINCT FROM 'string' OR jsonb_typeof(data->'cumulative_fraction') IS DISTINCT FROM 'string'
            OR length(data->>'cumulative_fraction') > 32 OR data->>'cumulative_fraction' !~ '^[0-9]+(\.[0-9]+)?$' THEN RAISE EXCEPTION 'Invalid revenue progress inputs'; END IF;
        allocations := ledger_finance_allocations(definition.inputs->'obligations', definition.opening_minor);
        allocated := (allocations->>(data->>'reference'))::numeric;
        progress := (data->>'cumulative_fraction')::numeric;
        IF allocated IS NULL OR progress < 0 OR progress > 1 OR progress <> trunc(progress, 18) THEN RAISE EXCEPTION 'Revenue progress must select an obligation and a fraction between zero and one'; END IF;
        SELECT e.inputs->>'cumulative_fraction' INTO prior_progress FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract
            AND e.kind = 'recognize' AND e.inputs->>'reference' = data->>'reference' AND e.reverses_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id) ORDER BY e.sequence DESC LIMIT 1;
        IF progress < coalesce(prior_progress, 0) THEN RAISE EXCEPTION 'Reverse recognition before reducing cumulative progress'; END IF;
        prior_recognized := ledger_fraction(allocated * coalesce(prior_progress, 0), 1, 'half_even');
        cd := -(ledger_fraction(allocated * progress, 1, 'half_even') - prior_recognized);
        IF -cd > carry THEN RAISE EXCEPTION 'Revenue recognition exceeds remaining consideration'; END IF;
        rows := ledger_asset_row(definition.carrying_account_id, -cd) || ledger_asset_row((roles->>('revenue.' || (data->>'reference')))::uuid, cd);
    ELSIF operation = 'writeoff' THEN
        IF definition.kind <> 'loan_asset' OR carry <= 0 THEN RAISE EXCEPTION 'Full write-off requires a live loan asset'; END IF;
        cd := -carry; ad := -allowance; wd := carry;
        rows := ledger_asset_row(definition.allowance_account_id, allowance) || ledger_asset_row((roles->>'ecl.expense')::uuid, carry - allowance) || ledger_asset_row(definition.carrying_account_id, -carry);
    ELSIF operation = 'partial_writeoff' THEN
        payment := ledger_finance_amount(data->>'amount', currency_scale);
        IF definition.kind <> 'loan_asset' OR payment IS NULL OR payment <= 0 OR payment >= carry THEN RAISE EXCEPTION 'A partial write-off removes part of a live loan asset; write off the whole balance with writeOff()'; END IF;
        release := least(payment, allowance);
        cd := -payment; ad := -release; wd := payment;
        rows := ledger_asset_row(definition.allowance_account_id, release) || ledger_asset_row((roles->>'ecl.expense')::uuid, payment - release) || ledger_asset_row(definition.carrying_account_id, -payment);
    ELSIF operation = 'recovery' THEN
        rd := ledger_finance_amount(data->>'amount', currency_scale);
        IF definition.kind <> 'loan_asset' OR rd <= 0 OR rd > written_off - recovered THEN RAISE EXCEPTION 'Recovery exceeds unrecovered written-off carrying value'; END IF;
        rows := ledger_asset_row((roles->>'cash')::uuid, rd) || ledger_asset_row((roles->>'ecl.expense')::uuid, -rd);
        cash_flow := jsonb_build_object('investing', rd::text);
    ELSIF operation = 'cancellation' THEN
        IF EXISTS (SELECT 1 FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.reverses_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id)) THEN RAISE EXCEPTION 'Reverse finance activity before cancellation'; END IF;
        cd := -definition.opening_minor;
        SELECT jsonb_agg(jsonb_build_object('account_id', account_id::text, 'side', CASE side WHEN 'debit' THEN 'credit' ELSE 'debit' END, 'amount_minor', amount_minor::text) ORDER BY line_no)
            INTO rows FROM ledger_postings WHERE tenant_id = tenant AND book_id = book AND journal_id = definition.journal_id;
    ELSE RAISE EXCEPTION 'Unknown finance event'; END IF;
    IF carry + cd < 0 OR allowance + ad < 0 OR allowance + ad > carry + cd OR written_off + wd < 0 OR recovered + rd < 0 OR recovered + rd > written_off + wd
        OR greatest(carry + cd, allowance + ad, written_off + wd, recovered + rd) >= power(10::numeric, 38) THEN RAISE EXCEPTION 'Finance balances exceed their accounting or storage limits'; END IF;
    RETURN jsonb_build_object('postings', rows, 'cash_flow', cash_flow, 'carrying_delta', cd::text, 'allowance_delta', ad::text,
        'written_off_delta', wd::text, 'recovered_delta', rd::text, 'stage', new_stage, 'prior_stage', old_stage, 'calculation', calculation);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE definition ledger_finance_contracts%ROWTYPE; original ledger_finance_events%ROWTYPE; configuration ledger_configurations%ROWTYPE;
    expected jsonb; rows jsonb; actual jsonb; functional_code text; currency_scale integer; dimensions_arg jsonb; latest uuid; account_arg uuid; role_name text; account_type text;
    protected uuid[]; value record; cash_flow jsonb; correcting boolean := false; kind_arg text;
BEGIN
    PERFORM 1 FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id FOR UPDATE;
    IF TG_TABLE_NAME = 'ledger_finance_contracts' THEN
        kind_arg := NEW.kind;
        SELECT * INTO STRICT configuration FROM ledger_configurations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.configuration_id;
        SELECT b.currency_code, c.scale INTO STRICT functional_code, currency_scale FROM ledger_books b JOIN ledger_currencies c ON c.tenant_id = b.tenant_id AND c.code = b.currency_code WHERE b.tenant_id = NEW.tenant_id AND b.id = NEW.book_id;
        IF NEW.effective_on < (configuration.definition_json->>'valid_from')::date OR NEW.effective_on > coalesce((configuration.definition_json->>'valid_until')::date, '9999-12-31') THEN RAISE EXCEPTION 'Finance policy is not effective at recognition'; END IF;
        expected := ledger_finance_initial(NEW.kind, NEW.inputs, configuration.roles, currency_scale, NEW.effective_on);
        IF NEW.opening_minor <> (expected->>'opening_minor')::numeric OR NEW.carrying_account_id IS DISTINCT FROM (expected->>'carrying_account_id')::uuid
            OR NEW.allowance_account_id IS DISTINCT FROM (expected->>'allowance_account_id')::uuid OR NEW.asset_account_id IS DISTINCT FROM (expected->>'asset_account_id')::uuid THEN RAISE EXCEPTION 'Finance registration differs from its independently measured basis'; END IF;
        protected := array_remove(ARRAY[NEW.carrying_account_id, NEW.allowance_account_id, NEW.asset_account_id], NULL);
        IF cardinality(protected) <> (SELECT count(DISTINCT id) FROM unnest(protected) id) THEN RAISE EXCEPTION 'Finance carrying, allowance and ROU accounts must be distinct'; END IF;
        FOR value IN SELECT * FROM jsonb_each_text(configuration.roles) LOOP
            role_name := value.key;
            IF role_name NOT IN ('cash', 'loan', 'interest', 'ecl.allowance', 'ecl.expense', 'lease.liability', 'lease.asset', 'contract.liability') AND role_name NOT LIKE 'revenue.%' THEN CONTINUE; END IF;
            IF role_name = 'loan' AND NEW.kind NOT IN ('loan_asset', 'loan_liability') OR role_name = 'interest' AND NEW.kind = 'revenue' OR role_name IN ('ecl.allowance', 'ecl.expense') AND NEW.kind <> 'loan_asset'
                OR role_name IN ('lease.asset', 'lease.liability') AND NEW.kind <> 'lease' OR (role_name = 'contract.liability' OR role_name LIKE 'revenue.%') AND NEW.kind <> 'revenue' THEN CONTINUE; END IF;
            account_arg := value.value::uuid;
            account_type := CASE WHEN role_name = 'cash' OR role_name IN ('lease.asset', 'ecl.allowance') OR role_name = 'loan' AND NEW.kind = 'loan_asset' THEN 'asset'
                WHEN role_name IN ('lease.liability', 'contract.liability', 'loan') THEN 'liability' WHEN role_name = 'ecl.expense' OR role_name = 'interest' AND NEW.kind <> 'loan_asset' THEN 'expense' ELSE 'income' END;
            IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = account_arg AND type = account_type AND NOT is_group AND NOT is_control AND active AND deleted_at IS NULL
                AND coalesce(currency_code, functional_code) = functional_code AND ((role_name = 'cash') = (ledger_accounts.cash_flow IS NOT DISTINCT FROM 'cash')))
                OR role_name NOT IN ('loan', 'lease.liability', 'lease.asset', 'contract.liability', 'ecl.allowance') AND account_arg = ANY(protected) THEN RAISE EXCEPTION 'Finance role has an incompatible account: %', role_name; END IF;
        END LOOP;
        IF NEW.kind = 'revenue' AND EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.inputs->'obligations') o WHERE configuration.roles->>('revenue.' || (o->>'reference')) IS NULL) THEN RAISE EXCEPTION 'Every obligation requires a configured revenue account'; END IF;
        IF EXISTS (SELECT 1 FROM ledger_finance_contracts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND dimensions = NEW.dimensions AND (carrying_account_id = ANY(protected) OR allowance_account_id = ANY(protected) OR asset_account_id = ANY(protected)))
            OR EXISTS (SELECT 1 FROM ledger_inventory_pools WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND account_id = ANY(protected))
            OR EXISTS (SELECT 1 FROM ledger_assets WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND (cost_account_id = ANY(protected) OR depreciation_account_id = ANY(protected) OR impairment_account_id = ANY(protected)))
            OR EXISTS (SELECT 1 FROM ledger_associates WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND (investment_account_id = ANY(protected) OR loss_liability_account_id = ANY(protected))) THEN RAISE EXCEPTION 'Finance accounts cannot overlap another protected register'; END IF;
        IF EXISTS (SELECT 1 FROM ledger_postings p WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.account_id = ANY(protected) AND p.journal_id <> NEW.journal_id
            AND NOT EXISTS (SELECT 1 FROM ledger_finance_contracts c WHERE c.tenant_id = p.tenant_id AND c.book_id = p.book_id AND c.dimensions = p.dimensions
                AND p.account_id IN (c.carrying_account_id, c.allowance_account_id, c.asset_account_id))) THEN RAISE EXCEPTION 'Reconcile unregistered finance account history before registration'; END IF;
        dimensions_arg := NEW.dimensions;
        rows := expected->'postings'; cash_flow := expected->'cash_flow';
    ELSE
        SELECT * INTO STRICT definition FROM ledger_finance_contracts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.contract_id;
        kind_arg := definition.kind;
        SELECT currency_code INTO STRICT functional_code FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id;
        dimensions_arg := definition.dimensions;
        IF NEW.effective_on < definition.effective_on OR EXISTS (SELECT 1 FROM ledger_finance_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND contract_id = NEW.contract_id AND effective_on > NEW.effective_on) THEN RAISE EXCEPTION 'Finance events require chronological accounting dates'; END IF;
        IF NEW.reverses_id IS NOT NULL THEN
            correcting := true;
            SELECT * INTO STRICT original FROM ledger_finance_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
            SELECT e.id INTO latest FROM ledger_finance_events e WHERE e.tenant_id = NEW.tenant_id AND e.book_id = NEW.book_id AND e.contract_id = NEW.contract_id AND e.reverses_id IS NULL
                AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r WHERE r.tenant_id = NEW.tenant_id AND r.book_id = NEW.book_id AND r.reverses_id = e.id) ORDER BY e.sequence DESC LIMIT 1;
            IF original.contract_id <> NEW.contract_id OR original.id IS DISTINCT FROM latest OR original.reverses_id IS NOT NULL OR original.kind = 'cancellation' OR original.kind <> NEW.kind
                OR NEW.inputs IS DISTINCT FROM original.inputs OR (NEW.carrying_delta, NEW.allowance_delta, NEW.written_off_delta, NEW.recovered_delta, NEW.stage, NEW.prior_stage)
                    IS DISTINCT FROM (-original.carrying_delta, -original.allowance_delta, -original.written_off_delta, -original.recovered_delta, original.prior_stage, original.stage)
                OR (NEW.journal_id IS NULL) <> (original.journal_id IS NULL) THEN RAISE EXCEPTION 'Reverse finance events in dependency order with exact original effects'; END IF;
            IF NEW.journal_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id AND reverses_id = original.journal_id AND effective_on = NEW.effective_on) THEN RAISE EXCEPTION 'Finance corrections require an exact linked journal reversal'; END IF;
        ELSE
            expected := ledger_finance_quote(NEW.tenant_id, NEW.book_id, NEW.contract_id, NEW.effective_on, NEW.kind, NEW.inputs, NEW.journal_id);
            IF (NEW.carrying_delta, NEW.allowance_delta, NEW.written_off_delta, NEW.recovered_delta, NEW.stage, NEW.prior_stage)
                IS DISTINCT FROM ((expected->>'carrying_delta')::numeric, (expected->>'allowance_delta')::numeric, (expected->>'written_off_delta')::numeric, (expected->>'recovered_delta')::numeric, (expected->>'stage')::integer, (expected->>'prior_stage')::integer) THEN RAISE EXCEPTION 'Finance event differs from its independently measured effects'; END IF;
            rows := expected->'postings'; cash_flow := expected->'cash_flow';
            IF NEW.kind = 'cancellation' THEN
                correcting := true;
                IF NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id AND reverses_id = definition.journal_id AND effective_on = NEW.effective_on) THEN RAISE EXCEPTION 'Finance cancellation requires its original funding reversal'; END IF;
            END IF;
        END IF;
    END IF;
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id, correcting);
    PERFORM ledger_require_live_dimensions(NEW.tenant_id, NEW.book_id, dimensions_arg, correcting);
    IF correcting THEN RETURN NEW; END IF;
    IF TG_TABLE_NAME = 'ledger_finance_events' THEN
        SELECT * INTO STRICT configuration FROM ledger_configurations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = definition.configuration_id;
    END IF;
    FOR value IN SELECT * FROM jsonb_each_text(configuration.roles) LOOP
        role_name := value.key;
        IF role_name NOT IN ('cash', 'loan', 'interest', 'ecl.allowance', 'ecl.expense', 'lease.liability', 'lease.asset', 'contract.liability') AND role_name NOT LIKE 'revenue.%' THEN CONTINUE; END IF;
        IF role_name = 'loan' AND kind_arg NOT IN ('loan_asset', 'loan_liability') OR role_name = 'interest' AND kind_arg = 'revenue'
            OR role_name IN ('ecl.allowance', 'ecl.expense') AND kind_arg <> 'loan_asset' OR role_name IN ('lease.asset', 'lease.liability') AND kind_arg <> 'lease'
            OR (role_name = 'contract.liability' OR role_name LIKE 'revenue.%') AND kind_arg <> 'revenue' THEN CONTINUE; END IF;
        account_arg := value.value::uuid;
        IF EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = account_arg AND (NOT active OR deleted_at IS NOT NULL)) THEN RAISE EXCEPTION 'Restore finance accounts before recording new events'; END IF;
        IF EXISTS (SELECT 1 FROM ledger_account_dimensions WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND account_id = account_arg AND NOT (dimensions_arg ? dimension)) THEN RAISE EXCEPTION 'A mandatory finance accounting dimension is missing'; END IF;
    END LOOP;
    IF (jsonb_array_length(rows) = 0) <> (NEW.journal_id IS NULL) THEN RAISE EXCEPTION 'Finance journal does not match its financial effect'; END IF;
    IF NEW.journal_id IS NOT NULL THEN
        SELECT jsonb_agg(jsonb_build_object('account_id', account_id::text, 'side', side, 'amount_minor', amount_minor::text) ORDER BY line_no) INTO actual FROM ledger_postings
            WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id;
        IF actual IS DISTINCT FROM rows OR NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id AND effective_on = NEW.effective_on AND NOT closing AND reverses_id IS NULL)
            OR EXISTS (SELECT 1 FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id AND (valuation OR party_id IS NOT NULL OR currency_code <> functional_code OR dimensions IS DISTINCT FROM dimensions_arg)) THEN RAISE EXCEPTION 'Finance journal must match its exact functional effects'; END IF;
        IF cash_flow IS NOT NULL AND cash_flow <> 'null'::jsonb AND NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id AND metadata->'cash_flow' = cash_flow) THEN RAISE EXCEPTION 'Finance cash flows must match the pinned policy'; END IF;
        IF jsonb_typeof(expected->'calculation') = 'object' AND NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id
            AND metadata->'finance_calculation' = expected->'calculation') THEN RAISE EXCEPTION 'Finance calculation evidence must match the measured event'; END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_links() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE previous_tenant text := coalesce(current_setting('noria.ledger_tenant', true), '');
BEGIN
    PERFORM set_config('noria.ledger_tenant', NEW.tenant_id::text, true);
    IF EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_finance_contracts c ON c.tenant_id = p.tenant_id AND c.book_id = p.book_id AND p.account_id IN (c.carrying_account_id, c.allowance_account_id, c.asset_account_id)
        WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.id)
        AND NOT EXISTS (SELECT 1 FROM ledger_finance_contracts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id)
        AND NOT EXISTS (SELECT 1 FROM ledger_finance_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id) THEN RAISE EXCEPTION 'Registered finance balances require linked contract evidence'; END IF;
    IF NEW.reverses_id IS NOT NULL AND (EXISTS (SELECT 1 FROM ledger_finance_contracts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.reverses_id)
        OR EXISTS (SELECT 1 FROM ledger_finance_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.reverses_id))
        AND NOT EXISTS (SELECT 1 FROM ledger_finance_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id) THEN RAISE EXCEPTION 'Finance journal reversals require exact event evidence'; END IF;
    PERFORM set_config('noria.ledger_tenant', previous_tenant, true);
    RETURN NEW;
END;
$body$;

DO $body$
DECLARE name text;
BEGIN
    FOREACH name IN ARRAY ARRAY['ledger_finance_contracts', 'ledger_finance_events'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', name);
        EXECUTE format('CREATE POLICY ledger_tenant ON %I USING (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid) WITH CHECK (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid)', name);
        EXECUTE format('CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard()', name);
        EXECUTE format('CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_immutable()', name);
        EXECUTE format('CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable()', name);
        EXECUTE format('CREATE TRIGGER stage_30_stamp BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_event_stamp()', name);
        EXECUTE format('CREATE TRIGGER stage_40_validate BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_finance_validate()', name);
        EXECUTE format('CREATE TRIGGER stage_90_audit AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_audit()', name);
    END LOOP;
END;
$body$;
