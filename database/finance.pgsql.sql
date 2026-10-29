ALTER TABLE ledger_finance_contracts
    ADD CHECK (kind IN ('loan_asset', 'loan_liability', 'lease', 'revenue', 'contract_revenue')),
    ADD CHECK (jsonb_typeof(inputs) = 'object'), ADD CHECK (jsonb_typeof(dimensions) = 'object'),
    ADD CHECK (opening_minor >= 0 AND opening_minor = trunc(opening_minor) AND opening_minor < power(10::numeric, 38)),
    ADD CHECK ((kind IN ('loan_asset', 'contract_revenue')) = (allowance_account_id IS NOT NULL)), ADD CHECK ((kind = 'lease') = (asset_account_id IS NOT NULL)),
    ADD CHECK ((kind = 'contract_revenue') = (liability_account_id IS NOT NULL)), ADD CHECK ((kind = 'contract_revenue') = (refund_account_id IS NOT NULL)),
    ADD CHECK ((kind = 'contract_revenue') = (journal_id IS NULL));
ALTER TABLE ledger_finance_events
    ADD CHECK (kind IN ('period', 'ecl', 'recognize', 'writeoff', 'partial_writeoff', 'recovery', 'cancellation', 'modification', 'settlement', 'invoice', 'credit', 'revenue_terms', 'rate_reset', 'replacement', 'credit_assess')),
    ADD CHECK (jsonb_typeof(inputs) = 'object'),
    ADD CHECK (carrying_delta = trunc(carrying_delta) AND abs(carrying_delta) < power(10::numeric, 38)),
    ADD CHECK (allowance_delta = trunc(allowance_delta) AND abs(allowance_delta) < power(10::numeric, 38)),
    ADD CHECK (written_off_delta = trunc(written_off_delta) AND abs(written_off_delta) < power(10::numeric, 38)),
    ADD CHECK (recovered_delta = trunc(recovered_delta) AND abs(recovered_delta) < power(10::numeric, 38)),
    ADD CHECK (stage BETWEEN 1 AND 3), ADD CHECK (prior_stage BETWEEN 1 AND 3), ADD CHECK (length(btrim(reason)) > 0);

CREATE UNIQUE INDEX ledger_revenue_invoice_identity ON ledger_finance_events (tenant_id, book_id, contract_id, (inputs->>'reference'))
    WHERE kind IN ('invoice', 'credit') AND reverses_id IS NULL;
CREATE UNIQUE INDEX ledger_revenue_separate_identity ON ledger_finance_contracts (tenant_id, book_id, (inputs->>'separate_from'), (inputs->>'modification_reference'))
    WHERE kind = 'contract_revenue' AND inputs->>'separate_from' IS NOT NULL;
CREATE UNIQUE INDEX ledger_finance_reset_identity ON ledger_finance_events (tenant_id, book_id, contract_id, (inputs->>'basis_reference')) WHERE kind = 'rate_reset' AND reverses_id IS NULL;
CREATE UNIQUE INDEX ledger_finance_replacement_identity ON ledger_finance_events (tenant_id, book_id, contract_id, (inputs->'derecognition'->>'reference')) WHERE kind = 'replacement' AND reverses_id IS NULL;

CREATE OR REPLACE FUNCTION ledger_finance_amount(value text, currency_scale integer) RETURNS numeric LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE result numeric;
BEGIN
    IF value IS NULL OR length(value) > 128 OR value !~ '^[0-9]+(\.[0-9]+)?$' THEN RAISE EXCEPTION 'Finance amounts require nonnegative plain decimal strings'; END IF;
    result := value::numeric * power(10::numeric, currency_scale);
    IF result <> trunc(result) OR result >= power(10::numeric, 38) THEN RAISE EXCEPTION 'Finance amount exceeds precision or storage bounds'; END IF;
    RETURN trunc(result);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_rate(value text) RETURNS numeric LANGUAGE plpgsql IMMUTABLE AS $body$
BEGIN
    IF value IS NULL OR value !~ '^(0(\.[0-9]{1,18})?|1(\.0{1,18})?)$' THEN RAISE EXCEPTION 'Finance rates require exact fractions between zero and one'; END IF;
    RETURN value::numeric;
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

CREATE OR REPLACE FUNCTION ledger_revenue_signed(value text, currency_scale integer) RETURNS numeric LANGUAGE plpgsql IMMUTABLE AS $body$
BEGIN
    IF coalesce(value, '') !~ '^-?[0-9]+(\.[0-9]+)?$' THEN RAISE EXCEPTION 'Contract revenue requires explicit fixed, estimated/constrained variable consideration and reviewed constraint evidence'; END IF;
    RETURN ledger_finance_amount(ltrim(value, '-'), currency_scale) * CASE WHEN value LIKE '-%' THEN -1 ELSE 1 END;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_revenue_economics(data jsonb) RETURNS jsonb LANGUAGE sql IMMUTABLE AS $body$
    SELECT jsonb_build_object('fixed', to_jsonb(trim_scale((data->>'fixed')::numeric)),
        'variable', coalesce((SELECT jsonb_agg(component ORDER BY component::text) FROM (SELECT jsonb_build_object('expected', to_jsonb(trim_scale((c->>'expected')::numeric)),
                'included', to_jsonb(trim_scale((c->>'included')::numeric)), 'limit', to_jsonb(trim_scale((c->>'limit')::numeric)), 'billable', to_jsonb(trim_scale((c->>'billable')::numeric))) AS component
            FROM jsonb_array_elements(CASE jsonb_typeof(data->'variable') WHEN 'object' THEN jsonb_build_array(data->'variable') ELSE data->'variable' END) c
            WHERE (c->>'expected')::numeric <> 0 OR (c->>'included')::numeric <> 0 OR coalesce((c->>'limit')::numeric, 0) <> 0 OR coalesce((c->>'billable')::numeric, 0) <> 0) components), '[]'::jsonb),
        'obligations', (SELECT jsonb_object_agg(o->>'reference', to_jsonb(trim_scale((o->>'standalone_price')::numeric))) FROM jsonb_array_elements(data->'obligations') o))
$body$;

CREATE OR REPLACE FUNCTION ledger_revenue_terms(data jsonb, currency_scale integer, frozen jsonb) RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE fixed numeric; expected numeric; included numeric; bound numeric; variable jsonb := data->'variable'; components jsonb; component jsonb;
    included_total numeric := 0; billable numeric; price numeric; result jsonb; seen text[] := ARRAY[]::text[]; remaining jsonb; frozen_total numeric; downward numeric := 0;
BEGIN
    components := CASE jsonb_typeof(variable) WHEN 'object' THEN jsonb_build_array(variable) WHEN 'array' THEN variable END;
    IF jsonb_typeof(data) IS DISTINCT FROM 'object' OR EXISTS (SELECT 1 FROM jsonb_object_keys(data) k WHERE k NOT IN ('fixed', 'variable', 'obligations'))
        OR jsonb_typeof(data->'fixed') IS DISTINCT FROM 'string' OR components IS NULL OR jsonb_array_length(components) NOT BETWEEN 1 AND 100 THEN
        RAISE EXCEPTION 'Contract revenue requires explicit fixed, estimated/constrained variable consideration and reviewed constraint evidence'; END IF;
    fixed := ledger_finance_amount(data->>'fixed', currency_scale); billable := fixed;
    FOR component IN SELECT value FROM jsonb_array_elements(components) LOOP
        IF jsonb_typeof(component) IS DISTINCT FROM 'object'
            OR EXISTS (SELECT 1 FROM jsonb_object_keys(component) k WHERE k NOT IN ('reference', 'expected', 'included', 'limit', 'billable', 'estimation_method', 'constraint_reference', 'highly_probable'))
            OR (jsonb_typeof(variable) = 'array' AND (jsonb_typeof(component->'reference') IS DISTINCT FROM 'string' OR component->>'reference' !~ '^[A-Za-z][A-Za-z0-9_.:-]{0,119}$'
                OR component->>'reference' = ANY(seen)))
            OR (jsonb_typeof(variable) = 'object' AND component ? 'reference')
            OR coalesce(component->>'estimation_method', '') NOT IN ('expected_value', 'most_likely_amount')
            OR jsonb_typeof(component->'expected') IS DISTINCT FROM 'string' OR jsonb_typeof(component->'included') IS DISTINCT FROM 'string'
            OR jsonb_typeof(component->'highly_probable') IS DISTINCT FROM 'boolean' OR jsonb_typeof(component->'constraint_reference') IS DISTINCT FROM 'string'
            OR length(btrim(component->>'constraint_reference')) NOT BETWEEN 1 AND 1024 THEN
            RAISE EXCEPTION 'Contract revenue requires explicit fixed, estimated/constrained variable consideration and reviewed constraint evidence'; END IF;
        seen := array_append(seen, coalesce(component->>'reference', ''));
        expected := ledger_revenue_signed(component->>'expected', currency_scale); included := ledger_revenue_signed(component->>'included', currency_scale);
        IF expected < 0 THEN
            IF component ? 'billable' THEN RAISE EXCEPTION 'Only upward variable consideration can be billed ahead of its constraint'; END IF;
            IF jsonb_typeof(component->'limit') IS DISTINCT FROM 'string' THEN RAISE EXCEPTION 'A downward variable estimate requires its reviewed contractual limit'; END IF;
            bound := ledger_revenue_signed(component->>'limit', currency_scale);
            IF bound > expected OR included > expected OR included < bound OR (included = expected AND NOT (component->>'highly_probable')::boolean) THEN
                RAISE EXCEPTION 'Constrained consideration must be supported, bounded by the estimate and leave a nonnegative price'; END IF;
        ELSIF component ? 'limit' OR included < 0 OR included > expected OR (included > 0 AND NOT (component->>'highly_probable')::boolean) THEN
            RAISE EXCEPTION 'Constrained consideration must be supported, bounded by the estimate and leave a nonnegative price'; END IF;
        IF component ? 'billable' THEN
            IF jsonb_typeof(component->'billable') IS DISTINCT FROM 'string' OR ledger_revenue_signed(component->>'billable', currency_scale) < included
                OR ledger_revenue_signed(component->>'billable', currency_scale) > expected THEN
                RAISE EXCEPTION 'Billable variable consideration lies between the included amount and the unconstrained estimate'; END IF;
            billable := billable + ledger_revenue_signed(component->>'billable', currency_scale);
        ELSE billable := billable + greatest(included, 0); END IF;
        included_total := included_total + included; downward := downward - least(included, 0);
    END LOOP;
    price := fixed + included_total;
    IF price < 0 OR billable >= power(10::numeric, 38) THEN RAISE EXCEPTION 'Constrained consideration must be supported, bounded by the estimate and leave a nonnegative price'; END IF;
    IF EXISTS (SELECT 1 FROM jsonb_array_elements(data->'obligations') o WHERE EXISTS (SELECT 1 FROM jsonb_object_keys(o) k WHERE k NOT IN ('reference', 'standalone_price'))) THEN RAISE EXCEPTION 'Unknown revenue obligation input'; END IF;
    result := ledger_finance_allocations(data->'obligations', price);
    IF jsonb_typeof(frozen) IS DISTINCT FROM 'object' OR EXISTS (SELECT 1 FROM jsonb_object_keys(frozen) k WHERE NOT result ? k) THEN
        RAISE EXCEPTION 'Satisfied revenue obligations must remain in the contract'; END IF;
    IF frozen <> '{}'::jsonb THEN
        SELECT jsonb_agg(o ORDER BY n) INTO remaining FROM jsonb_array_elements(data->'obligations') WITH ORDINALITY x(o, n) WHERE NOT frozen ? (o->>'reference');
        SELECT sum(value::numeric) INTO frozen_total FROM jsonb_each_text(frozen);
        IF remaining IS NULL OR frozen_total > price THEN RAISE EXCEPTION 'Revised consideration cannot reallocate revenue already earned on satisfied obligations'; END IF;
        result := ledger_finance_allocations(remaining, price - frozen_total) || frozen;
    END IF;
    RETURN jsonb_build_object('price_minor', price::text, 'billable_minor', billable::text, 'downward_minor', downward::text, 'allocations', result)
        || CASE WHEN frozen = '{}'::jsonb THEN '{}'::jsonb ELSE jsonb_build_object('frozen', frozen) END;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_revenue_initial(data jsonb, roles jsonb, currency_scale integer) RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE terms jsonb;
BEGIN
    IF jsonb_typeof(data) IS DISTINCT FROM 'object' OR EXISTS (SELECT 1 FROM jsonb_object_keys(data) k WHERE k NOT IN ('policy_version', 'basis_reference', 'party_id', 'terms', 'separate_from', 'modification_reference', 'distinct', 'standalone_priced'))
        OR jsonb_typeof(data->'policy_version') IS DISTINCT FROM 'string' OR length(btrim(data->>'policy_version')) NOT BETWEEN 1 AND 128
        OR jsonb_typeof(data->'basis_reference') IS DISTINCT FROM 'string' OR length(btrim(data->>'basis_reference')) NOT BETWEEN 1 AND 256
        OR data->>'party_id' IS NULL OR roles->>'contract.asset' IS NULL OR roles->>'contract.liability' IS NULL OR roles->>'contract.refund' IS NULL
        OR roles->>'contract.allowance' IS NULL OR roles->>'contract.impairment' IS NULL OR roles->>'receivable' IS NULL THEN
        RAISE EXCEPTION 'Contract revenue requires a reviewed policy, customer and asset/liability/refund/allowance/impairment/receivable roles'; END IF;
    IF data ? 'separate_from' THEN
        IF data->'distinct' IS DISTINCT FROM 'true'::jsonb OR data->'standalone_priced' IS DISTINCT FROM 'true'::jsonb
            OR jsonb_typeof(data->'modification_reference') IS DISTINCT FROM 'string' OR length(btrim(data->>'modification_reference')) NOT BETWEEN 1 AND 256 THEN
            RAISE EXCEPTION 'A separate revenue modification requires reviewed distinct goods at standalone selling prices'; END IF;
    ELSIF data ?| ARRAY['modification_reference', 'distinct', 'standalone_priced'] THEN RAISE EXCEPTION 'Separate-contract evidence requires its original contract'; END IF;
    terms := ledger_revenue_terms(data->'terms', currency_scale, '{}'::jsonb);
    IF EXISTS (SELECT 1 FROM jsonb_object_keys(terms->'allocations') ref WHERE roles->>('revenue.' || ref) IS NULL) THEN RAISE EXCEPTION 'Every obligation requires a configured revenue account'; END IF;
    RETURN jsonb_build_object('opening_minor', '0', 'carrying_account_id', roles->>'contract.asset', 'allowance_account_id', roles->>'contract.allowance',
        'asset_account_id', NULL, 'liability_account_id', roles->>'contract.liability', 'refund_account_id', roles->>'contract.refund', 'postings', '[]'::jsonb, 'cash_flow', NULL, 'calculation', terms);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_revenue_position(tenant uuid, book uuid, contract uuid, on_date date, cutoff bigint) RETURNS jsonb
LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE definition ledger_finance_contracts; amendment ledger_finance_events; event ledger_finance_events; scale_arg integer; terms jsonb; measured jsonb;
    earned jsonb := '{}'::jsonb; progress jsonb; amount numeric; billed numeric := 0; total numeric := 0; item record; direction integer; allowance numeric := 0; within numeric; refunded numeric := 0;
BEGIN
    SELECT * INTO STRICT definition FROM ledger_finance_contracts WHERE tenant_id = tenant AND book_id = book AND id = contract AND kind = 'contract_revenue';
    IF definition.effective_on > on_date OR definition.sequence > cutoff THEN RAISE EXCEPTION 'Revenue contract was not known at this snapshot'; END IF;
    SELECT c.scale INTO STRICT scale_arg FROM ledger_books b JOIN ledger_currencies c ON c.tenant_id = b.tenant_id AND c.code = b.currency_code WHERE b.tenant_id = tenant AND b.id = book;
    SELECT e.* INTO amendment FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.kind = 'revenue_terms'
        AND e.sequence <= cutoff AND e.effective_on <= on_date AND e.reverses_id IS NULL AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r
            WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id AND r.sequence <= cutoff AND r.effective_on <= on_date) ORDER BY e.sequence DESC LIMIT 1;
    terms := coalesce(amendment.inputs->'terms', definition.inputs->'terms');
    measured := ledger_revenue_terms(terms, scale_arg, coalesce(amendment.calculation->'frozen', '{}'::jsonb));
    FOR item IN SELECT key FROM jsonb_each(measured->'allocations') LOOP earned := earned || jsonb_build_object(item.key, '0'); END LOOP;
    progress := earned;
    FOR event IN SELECT * FROM ledger_finance_events WHERE tenant_id = tenant AND book_id = book AND contract_id = contract AND sequence <= cutoff AND effective_on <= on_date ORDER BY sequence LOOP
        direction := CASE WHEN event.reverses_id IS NULL THEN 1 ELSE -1 END;
        billed := billed + coalesce((event.calculation->>'billed_delta_minor')::numeric, 0) * direction;
        IF event.inputs->>'purpose' = 'refund' THEN refunded := refunded - (event.calculation->>'billed_delta_minor')::numeric * direction; END IF;
        allowance := allowance + event.allowance_delta;
        FOR item IN SELECT * FROM jsonb_each_text(event.calculation->'revenue_deltas') LOOP
            amount := (earned->>item.key)::numeric + item.value::numeric * direction;
            earned := jsonb_set(earned, ARRAY[item.key], to_jsonb(amount::text));
        END LOOP;
    END LOOP;
    SELECT coalesce(sum(value::numeric), 0) INTO total FROM jsonb_each_text(earned);
    SELECT e.calculation->'progress' INTO progress FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.reverses_id IS NULL
        AND e.calculation ? 'progress' AND e.sequence <= cutoff AND e.effective_on <= on_date AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r
            WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id AND r.sequence <= cutoff AND r.effective_on <= on_date) ORDER BY e.sequence DESC LIMIT 1;
    within := least(billed, (measured->>'price_minor')::numeric);
    RETURN measured || jsonb_build_object('terms_id', coalesce(amendment.id, definition.id), 'terms', terms, 'earned', earned, 'progress', coalesce(progress, (SELECT jsonb_object_agg(key, '0'::text) FROM jsonb_each(earned))),
        'earned_minor', total::text, 'billed_minor', billed::text, 'asset_minor', greatest(total - within, 0)::text, 'liability_minor', greatest(within - total, 0)::text,
        'refund_minor', (billed - within)::text, 'allowance_minor', allowance::text, 'refunded_minor', refunded::text);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_revenue_quote(tenant uuid, book uuid, contract uuid, on_date date, operation text, data jsonb) RETURNS jsonb
LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE definition ledger_finance_contracts; state jsonb; measured jsonb; terms jsonb; roles jsonb; progress jsonb; deltas jsonb := '{}'::jsonb; rows jsonb := '[]'::jsonb;
    scale_arg integer; ref text; fraction numeric; target numeric; delta numeric := 0; amount numeric := 0; billed numeric; net numeric; asset numeric; liability numeric;
    calculation jsonb; original ledger_finance_events; used numeric; item record; frozen jsonb; price numeric; within numeric; refund numeric; allowance numeric; allowance_delta numeric := 0;
BEGIN
    SELECT * INTO STRICT definition FROM ledger_finance_contracts WHERE tenant_id = tenant AND book_id = book AND id = contract;
    IF on_date < definition.effective_on OR EXISTS (SELECT 1 FROM ledger_finance_events WHERE tenant_id = tenant AND book_id = book AND contract_id = contract AND effective_on > on_date)
        OR EXISTS (SELECT 1 FROM ledger_finance_events WHERE tenant_id = tenant AND book_id = book AND contract_id = contract AND kind = 'cancellation') THEN RAISE EXCEPTION 'Revenue events require a live contract and chronological dates'; END IF;
    SELECT c.roles, u.scale INTO STRICT roles, scale_arg FROM ledger_configurations c JOIN ledger_books b ON b.tenant_id = c.tenant_id AND b.id = c.book_id
        JOIN ledger_currencies u ON u.tenant_id = b.tenant_id AND u.code = b.currency_code WHERE c.tenant_id = tenant AND c.book_id = book AND c.id = definition.configuration_id;
    IF jsonb_typeof(data) IS DISTINCT FROM 'object' OR EXISTS (SELECT 1 FROM jsonb_object_keys(data) k WHERE NOT k = ANY(CASE operation
        WHEN 'recognize' THEN ARRAY['reference', 'cumulative_fraction', 'terms_id', 'evidence']
        WHEN 'revenue_terms' THEN ARRAY['terms', 'change', 'progress', 'distinct_remaining', 'basis_reference', 'terms_id', 'evidence']
        WHEN 'invoice' THEN ARRAY['amount', 'reference', 'due_on', 'basis_reference', 'evidence']
        WHEN 'credit' THEN ARRAY['amount', 'reference', 'invoice_id', 'purpose', 'basis_reference', 'evidence']
        WHEN 'ecl' THEN ARRAY['allowance', 'model_reference', 'evidence'] ELSE ARRAY[]::text[] END)) THEN RAISE EXCEPTION 'Unknown revenue event input'; END IF;
    state := ledger_revenue_position(tenant, book, contract, on_date, 9223372036854775807);
    SELECT jsonb_object_agg(key, '0'::text) INTO deltas FROM jsonb_each(state->'allocations');
    terms := state->'terms'; progress := state->'progress'; measured := state; allowance := (state->>'allowance_minor')::numeric;
    IF operation IN ('recognize', 'revenue_terms') THEN
        IF data ? 'terms_id' AND data->>'terms_id' IS DISTINCT FROM state->>'terms_id' OR state->>'terms_id' <> contract::text AND NOT data ? 'terms_id' THEN RAISE EXCEPTION 'Revenue progress must identify its current terms'; END IF;
        IF operation = 'recognize' THEN
            ref := data->>'reference';
            IF NOT (progress ? ref) OR jsonb_typeof(data->'cumulative_fraction') IS DISTINCT FROM 'string' OR coalesce(data->>'cumulative_fraction', '') !~ '^(0(\.[0-9]{1,18})?|1(\.0{1,18})?)$' THEN RAISE EXCEPTION 'Revenue progress requires a known obligation and bounded cumulative fraction'; END IF;
            fraction := (data->>'cumulative_fraction')::numeric;
            IF fraction < (progress->>ref)::numeric THEN RAISE EXCEPTION 'Correct revenue recognition before reducing progress'; END IF;
            progress := jsonb_set(progress, ARRAY[ref], to_jsonb(data->>'cumulative_fraction'));
        ELSE
            IF jsonb_typeof(data->'basis_reference') IS DISTINCT FROM 'string' OR length(btrim(data->>'basis_reference')) NOT BETWEEN 1 AND 256 THEN RAISE EXCEPTION 'Revenue reassessment requires reviewed basis evidence'; END IF;
            IF (SELECT array_agg(key ORDER BY key) FROM jsonb_each(ledger_revenue_terms(data->'terms', scale_arg, '{}'::jsonb)->'allocations')) IS DISTINCT FROM (SELECT array_agg(key ORDER BY key) FROM jsonb_each(state->'allocations')) THEN RAISE EXCEPTION 'Cumulative catch-up retains integrated obligation identities'; END IF;
            frozen := coalesce(state->'frozen', '{}'::jsonb);
            IF data->>'change' = 'variable_reassessment' THEN
                IF (ledger_revenue_economics(data->'terms')->'fixed', ledger_revenue_economics(data->'terms')->'obligations') IS DISTINCT FROM (ledger_revenue_economics(terms)->'fixed', ledger_revenue_economics(terms)->'obligations')
                    OR data ?| ARRAY['progress', 'distinct_remaining'] THEN RAISE EXCEPTION 'Variable reassessment retains fixed consideration, allocation basis and progress'; END IF;
            ELSIF data->>'change' = 'cumulative_catch_up' THEN
                IF ledger_revenue_economics(data->'terms') = ledger_revenue_economics(terms) THEN RAISE EXCEPTION 'A cumulative catch-up needs a reviewed change to the contract terms; record progress with recognize()'; END IF;
                IF data->'distinct_remaining' IS DISTINCT FROM 'false'::jsonb OR jsonb_typeof(data->'progress') IS DISTINCT FROM 'object'
                    OR (SELECT array_agg(key ORDER BY key) FROM jsonb_each(data->'progress')) IS DISTINCT FROM (SELECT array_agg(key ORDER BY key) FROM jsonb_each(progress)) THEN
                    RAISE EXCEPTION 'Cumulative catch-up requires reviewed non-distinct remaining goods and complete revised progress'; END IF;
                FOR item IN SELECT * FROM jsonb_each_text(progress) WHERE value::numeric = 1 LOOP
                    IF jsonb_typeof(data->'progress'->item.key) IS DISTINCT FROM 'string' OR coalesce(data->'progress'->>item.key, '') !~ '^1(\.0{1,18})?$'
                        OR (SELECT (o->>'standalone_price')::numeric FROM jsonb_array_elements(data->'terms'->'obligations') o WHERE o->>'reference' = item.key)
                            IS DISTINCT FROM (SELECT (o->>'standalone_price')::numeric FROM jsonb_array_elements(terms->'obligations') o WHERE o->>'reference' = item.key) THEN
                        RAISE EXCEPTION 'A cumulative catch-up retains the price, progress and earned revenue of satisfied obligations'; END IF;
                    frozen := frozen || jsonb_build_object(item.key, state->'allocations'->>item.key);
                END LOOP;
                progress := data->'progress';
            ELSE RAISE EXCEPTION 'Select variable reassessment or an eligible cumulative catch-up explicitly'; END IF;
            measured := ledger_revenue_terms(data->'terms', scale_arg, frozen);
        END IF;
        FOR item IN SELECT * FROM jsonb_each_text(progress) LOOP
            IF jsonb_typeof(progress->item.key) <> 'string' OR item.value !~ '^(0(\.[0-9]{1,18})?|1(\.0{1,18})?)$' THEN RAISE EXCEPTION 'Revenue progress must be exact bounded fractions'; END IF;
            target := ledger_fraction((measured->'allocations'->>item.key)::numeric * item.value::numeric, 1, 'half_even');
            amount := target - (state->'earned'->>item.key)::numeric; delta := delta + amount;
            deltas := deltas || jsonb_build_object(item.key, amount::text);
            rows := rows || ledger_asset_row((roles->>('revenue.' || item.key))::uuid, -amount);
        END LOOP;
        amount := 0;
    ELSIF operation IN ('invoice', 'credit') THEN
        IF jsonb_typeof(data->'reference') IS DISTINCT FROM 'string' OR length(btrim(data->>'reference')) NOT BETWEEN 1 AND 128
            OR jsonb_typeof(data->'basis_reference') IS DISTINCT FROM 'string' OR length(btrim(data->>'basis_reference')) NOT BETWEEN 1 AND 256 THEN RAISE EXCEPTION 'Billing requires unconditional-right or credit evidence and a unique reference'; END IF;
        amount := ledger_finance_amount(data->>'amount', scale_arg);
        IF amount <= 0 THEN RAISE EXCEPTION 'Revenue bill or credit amounts must be positive'; END IF;
        IF operation = 'credit' THEN
            IF coalesce(data->>'purpose', '') NOT IN ('refund', 'billing_correction') THEN RAISE EXCEPTION 'A revenue credit must state whether it refunds reduced consideration or corrects billing'; END IF;
            SELECT * INTO STRICT original FROM ledger_finance_events WHERE tenant_id = tenant AND book_id = book AND contract_id = contract AND id = (data->>'invoice_id')::uuid AND kind = 'invoice' AND reverses_id IS NULL;
            IF EXISTS (SELECT 1 FROM ledger_finance_events WHERE tenant_id = tenant AND book_id = book AND reverses_id = original.id) THEN RAISE EXCEPTION 'Cannot credit a corrected invoice'; END IF;
            SELECT coalesce(sum((e.calculation->>'billed_delta_minor')::numeric * CASE WHEN e.reverses_id IS NULL THEN -1 ELSE 1 END), 0) INTO used
                FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.kind = 'credit' AND e.inputs->>'invoice_id' = original.id::text;
            IF amount + used > (original.calculation->>'billed_delta_minor')::numeric THEN RAISE EXCEPTION 'Credits exceed the linked original invoice'; END IF;
            IF data->>'purpose' = 'refund' AND amount > (state->>'refund_minor')::numeric THEN RAISE EXCEPTION 'A refund credit cannot exceed the refund liability from reduced consideration'; END IF;
            amount := -amount;
        ELSIF (state->>'billed_minor')::numeric + amount > greatest((state->>'price_minor')::numeric, (state->>'billable_minor')::numeric - least((state->>'refunded_minor')::numeric, (state->>'downward_minor')::numeric)) THEN
            RAISE EXCEPTION 'Invoice exceeds the reviewed contract consideration'; END IF;
        rows := jsonb_build_array(jsonb_build_object('account_id', roles->>'receivable', 'side', CASE WHEN amount > 0 THEN 'debit' ELSE 'credit' END,
            'amount_minor', abs(amount)::text, 'party_id', definition.inputs->>'party_id', 'due_on', CASE WHEN operation = 'invoice' THEN data->>'due_on' END));
        IF operation = 'invoice' AND (coalesce(data->>'due_on', '') !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' OR (data->>'due_on')::date < on_date) THEN RAISE EXCEPTION 'Invoice due date must be explicit and cannot precede billing'; END IF;
    ELSIF operation = 'ecl' THEN
        IF jsonb_typeof(data->'model_reference') IS DISTINCT FROM 'string' OR length(btrim(data->>'model_reference')) NOT BETWEEN 1 AND 256 OR jsonb_typeof(data->'allowance') IS DISTINCT FROM 'string' THEN
            RAISE EXCEPTION 'Contract-asset impairment requires a reviewed lifetime loss and model evidence'; END IF;
        allowance_delta := ledger_finance_amount(data->>'allowance', scale_arg) - allowance;
        rows := ledger_asset_row((roles->>'contract.impairment')::uuid, allowance_delta) || ledger_asset_row(definition.allowance_account_id, -allowance_delta);
    ELSIF operation = 'cancellation' THEN
        IF EXISTS (SELECT 1 FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.reverses_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id)) THEN RAISE EXCEPTION 'Correct revenue activity before cancellation'; END IF;
        IF EXISTS (SELECT 1 FROM ledger_finance_contracts c WHERE c.tenant_id = tenant AND c.book_id = book AND c.kind = 'contract_revenue' AND c.inputs->>'separate_from' = contract::text
            AND NOT EXISTS (SELECT 1 FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = c.id AND e.kind = 'cancellation')) THEN
            RAISE EXCEPTION 'Cancel separate contract additions before their original contract'; END IF;
    ELSE RAISE EXCEPTION 'Unsupported contract revenue event'; END IF;
    price := (measured->>'price_minor')::numeric;
    billed := (state->>'billed_minor')::numeric + amount;
    within := least(billed, price); refund := billed - within;
    net := (state->>'earned_minor')::numeric + delta - within;
    IF billed < 0 OR greatest(abs(net), billed, (state->>'earned_minor')::numeric + delta) >= power(10::numeric, 38) THEN RAISE EXCEPTION 'Revenue balances exceed their accounting bounds'; END IF;
    asset := greatest(net, 0); liability := greatest(-net, 0);
    IF allowance + allowance_delta < 0 OR allowance + allowance_delta > asset THEN RAISE EXCEPTION 'Remeasure contract-asset impairment before reducing the contract asset below its loss allowance'; END IF;
    rows := rows || ledger_asset_row(definition.carrying_account_id, asset - (state->>'asset_minor')::numeric)
        || ledger_asset_row(definition.liability_account_id, (state->>'liability_minor')::numeric - liability)
        || ledger_asset_row(definition.refund_account_id, (state->>'refund_minor')::numeric - refund);
    calculation := jsonb_build_object('terms_id', state->>'terms_id', 'price_minor', measured->>'price_minor', 'billable_minor', measured->>'billable_minor', 'allocations', measured->'allocations',
        'progress', progress, 'revenue_deltas', deltas, 'billed_delta_minor', amount::text, 'earned_delta_minor', delta::text,
        'asset_minor', asset::text, 'liability_minor', liability::text, 'refund_minor', refund::text, 'allowance_minor', (allowance + allowance_delta)::text)
        || CASE WHEN measured ? 'frozen' THEN jsonb_build_object('frozen', measured->'frozen') ELSE '{}'::jsonb END;
    RETURN jsonb_build_object('postings', rows, 'carrying_delta', (delta - amount)::text, 'allowance_delta', allowance_delta::text, 'written_off_delta', '0', 'recovered_delta', '0', 'stage', 1, 'prior_stage', 1, 'calculation', calculation);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_coupon_plan(payments jsonb, principal numeric, coupon numeric, currency_scale integer, amortization text, reference jsonb)
RETURNS void LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE outstanding numeric := principal; paid numeric; interest numeric; item record; slots integer := jsonb_array_length(payments); level numeric;
    unit numeric; growth numeric := 1; discount numeric := 1; coupon_units numeric; i integer;
BEGIN
    IF principal <= 0 OR amortization IS NULL OR amortization NOT IN ('fixed_principal', 'level_payment') THEN
        RAISE EXCEPTION 'Floating coupons require positive outstanding principal and a fixed-principal or level-payment amortization convention'; END IF;
    IF amortization = 'level_payment' AND slots > 1 THEN
        unit := power(10::numeric, scale(coupon)); coupon_units := coupon * unit;
        FOR i IN 1..slots LOOP growth := growth * (unit + coupon_units); discount := discount * unit; END LOOP;
        level := CASE WHEN coupon = 0 THEN ledger_fraction(principal, slots, 'half_even') ELSE ledger_fraction(principal * coupon_units * growth, unit * (growth - discount), 'half_even') END;
    END IF;
    FOR item IN SELECT value, n FROM jsonb_array_elements(payments) WITH ORDINALITY p(value, n) LOOP
        paid := ledger_finance_amount(item.value->>'payment', currency_scale); interest := ledger_finance_amount(item.value->>'interest_paid', currency_scale);
        IF interest <> ledger_fraction(outstanding * coupon, 1, 'half_even') THEN
            RAISE EXCEPTION 'Floating cash interest must equal the benchmark plus contractual spread on outstanding principal'; END IF;
        IF level IS NOT NULL AND item.n < slots AND paid <> level THEN RAISE EXCEPTION 'A level-payment floating instrument pays its recomputed annuity in every slot but the last'; END IF;
        IF amortization = 'fixed_principal' AND reference IS NOT NULL
            AND paid - interest <> ledger_finance_amount(reference->(item.n::integer - 1)->>'payment', currency_scale) - ledger_finance_amount(reference->(item.n::integer - 1)->>'interest_paid', currency_scale) THEN
            RAISE EXCEPTION 'A fixed-principal floating reset keeps every remaining principal repayment'; END IF;
        outstanding := outstanding - (paid - interest);
        IF outstanding < 0 THEN RAISE EXCEPTION 'Floating principal repayments must retire exactly the outstanding principal'; END IF;
    END LOOP;
    IF outstanding <> 0 THEN RAISE EXCEPTION 'Floating principal repayments must retire exactly the outstanding principal'; END IF;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_initial(operation text, data jsonb, roles jsonb, currency_scale integer, date_arg date)
RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE opening numeric; commencement numeric := 0; rate numeric; carrying numeric; interest numeric; payment numeric; value jsonb;
    payments jsonb; allocations jsonb; rows jsonb; carrying_account uuid; allowance_account uuid; asset_account uuid; period_months integer;
    origination jsonb; principal numeric; fee numeric := 0; costs numeric := 0; flow jsonb; item record; calculation jsonb; sign_arg integer;
BEGIN
    IF operation = 'contract_revenue' THEN RETURN ledger_revenue_initial(data, roles, currency_scale); END IF;
    IF jsonb_typeof(data) IS DISTINCT FROM 'object' OR jsonb_typeof(data->'policy_version') IS DISTINCT FROM 'string'
        OR length(btrim(data->>'policy_version')) NOT BETWEEN 1 AND 128 OR jsonb_typeof(data->'basis_reference') IS DISTINCT FROM 'string'
        OR length(btrim(data->>'basis_reference')) NOT BETWEEN 1 AND 256 THEN RAISE EXCEPTION 'Finance registration requires policy and basis evidence'; END IF;
    IF EXISTS (SELECT 1 FROM jsonb_object_keys(data) key WHERE NOT key = ANY(CASE operation
        WHEN 'revenue' THEN ARRAY['policy_version', 'basis_reference', 'transaction_price', 'obligations']
        WHEN 'lease' THEN ARRAY['policy_version', 'basis_reference', 'rate_per_slot', 'rate_period_months', 'payments', 'interest_cash_flow', 'commencement_payment']
        ELSE ARRAY['policy_version', 'basis_reference', 'opening', 'rate_per_slot', 'rate_period_months', 'payments', 'interest_cash_flow', 'origination', 'floating'] END)) THEN RAISE EXCEPTION 'Unknown registered finance input'; END IF;
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
            IF data ? 'origination' THEN
                origination := data->'origination';
                IF jsonb_typeof(origination) IS DISTINCT FROM 'object' OR EXISTS (SELECT 1 FROM jsonb_object_keys(origination) k WHERE k NOT IN ('principal', 'integral_fee', 'transaction_cost', 'fee_reference', 'cost_reference', 'rate_reference', 'fee_cash_flow', 'cost_cash_flow'))
                    OR jsonb_typeof(origination->'fee_reference') IS DISTINCT FROM 'string' OR length(btrim(origination->>'fee_reference')) NOT BETWEEN 1 AND 256
                    OR jsonb_typeof(origination->'cost_reference') IS DISTINCT FROM 'string' OR length(btrim(origination->>'cost_reference')) NOT BETWEEN 1 AND 256
                    OR jsonb_typeof(origination->'rate_reference') IS DISTINCT FROM 'string' OR length(btrim(origination->>'rate_reference')) NOT BETWEEN 1 AND 256
                    OR coalesce(origination->>'fee_cash_flow', '') NOT IN ('operating', 'investing', 'financing') OR coalesce(origination->>'cost_cash_flow', '') NOT IN ('operating', 'investing', 'financing') THEN
                    RAISE EXCEPTION 'Integral fees and directly attributable costs require explicit reviewed amounts, EIR and cash-flow evidence'; END IF;
                principal := ledger_finance_amount(origination->>'principal', currency_scale); fee := ledger_finance_amount(origination->>'integral_fee', currency_scale); costs := ledger_finance_amount(origination->>'transaction_cost', currency_scale);
                IF principal <= 0 OR opening <> principal - fee + (CASE WHEN operation = 'loan_asset' THEN costs ELSE -costs END) THEN RAISE EXCEPTION 'Loan amortized cost must include integral fees and directly attributable costs with the correct asset/liability signs'; END IF;
            END IF;
            IF data ? 'floating' THEN
                IF jsonb_typeof(data->'floating') IS DISTINCT FROM 'object' OR EXISTS (SELECT 1 FROM jsonb_object_keys(data->'floating') k WHERE k NOT IN ('policy_version', 'reference', 'benchmark_rate', 'contractual_spread', 'amortization'))
                    OR jsonb_typeof(data->'floating'->'policy_version') IS DISTINCT FROM 'string' OR length(btrim(data->'floating'->>'policy_version')) NOT BETWEEN 1 AND 128
                    OR jsonb_typeof(data->'floating'->'reference') IS DISTINCT FROM 'string' OR length(btrim(data->'floating'->>'reference')) NOT BETWEEN 1 AND 256 THEN RAISE EXCEPTION 'Floating instruments require a versioned benchmark and spread policy'; END IF;
                PERFORM ledger_finance_rate(data->'floating'->>'benchmark_rate'); PERFORM ledger_finance_rate(data->'floating'->>'contractual_spread');
                PERFORM ledger_finance_coupon_plan(payments, coalesce(principal, opening), (data->'floating'->>'benchmark_rate')::numeric + (data->'floating'->>'contractual_spread')::numeric,
                    currency_scale, data->'floating'->>'amortization', NULL);
                IF fee + costs = 0 AND rate <> (data->'floating'->>'benchmark_rate')::numeric + (data->'floating'->>'contractual_spread')::numeric THEN
                    RAISE EXCEPTION 'A fee-free floating instrument has an effective rate equal to its benchmark plus contractual spread'; END IF;
            END IF;
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
        OR (operation = 'lease' AND (asset_account IS NULL OR roles->>'lease.accumulated' IS NULL)) OR (operation <> 'revenue' AND roles->>'interest' IS NULL) THEN RAISE EXCEPTION 'Finance registration requires positive value and complete account roles'; END IF;
    IF operation = 'loan_asset' THEN
        rows := ledger_asset_row(carrying_account, opening) || ledger_asset_row((roles->>'cash')::uuid, -opening);
    ELSIF operation = 'lease' THEN
        rows := ledger_asset_row(asset_account, opening + commencement) || ledger_asset_row(carrying_account, -opening) || ledger_asset_row((roles->>'cash')::uuid, -commencement);
    ELSE
        rows := ledger_asset_row((roles->>'cash')::uuid, opening) || ledger_asset_row(carrying_account, -opening);
    END IF;
    flow := jsonb_build_object(CASE operation WHEN 'loan_asset' THEN 'investing' WHEN 'revenue' THEN 'operating' ELSE 'financing' END,
        (CASE operation WHEN 'loan_asset' THEN -opening WHEN 'lease' THEN -commencement ELSE opening END)::text);
    IF origination IS NOT NULL THEN
        sign_arg := CASE WHEN operation = 'loan_asset' THEN 1 ELSE -1 END;
        rows := ledger_asset_row(carrying_account, sign_arg * opening) || ledger_asset_row((roles->>'cash')::uuid, -sign_arg * principal)
            || ledger_asset_row((roles->>'cash')::uuid, sign_arg * fee) || ledger_asset_row((roles->>'cash')::uuid, -costs);
        flow := jsonb_build_object(CASE WHEN operation = 'loan_asset' THEN 'investing' ELSE 'financing' END, (-sign_arg * principal)::text);
        FOR item IN SELECT * FROM (VALUES (origination->>'fee_cash_flow', sign_arg * fee), (origination->>'cost_cash_flow', -costs)) v(category, amount) LOOP
            flow := flow || jsonb_build_object(item.category, (coalesce((flow->>item.category)::numeric, 0) + item.amount)::text);
        END LOOP;
        calculation := jsonb_build_object('principal_minor', principal::text, 'integral_fee_minor', fee::text, 'transaction_cost_minor', costs::text,
            'amortized_cost_minor', opening::text, 'effective_rate_per_slot', data->>'rate_per_slot', 'origination', origination);
    END IF;
    RETURN jsonb_build_object('opening_minor', opening::text, 'carrying_account_id', carrying_account, 'allowance_account_id', allowance_account,
        'asset_account_id', asset_account, 'liability_account_id', NULL, 'refund_account_id', NULL, 'postings', rows, 'allocations', allocations,
        'cash_flow', flow, 'calculation', calculation);
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
    next_line integer; actual_required boolean; scheduled_carrying numeric; currency_scale integer; i integer; rate_change ledger_finance_events; replacement ledger_finance_events;
    current_rate text; benchmark text; instrument uuid; anchor date;
BEGIN
    SELECT * INTO STRICT definition FROM ledger_finance_contracts WHERE tenant_id = tenant AND book_id = book AND id = contract;
    IF definition.effective_on > as_of OR definition.sequence > through_sequence THEN RAISE EXCEPTION 'Finance contract was not recognized at this snapshot'; END IF;
    IF definition.kind IN ('revenue', 'contract_revenue') THEN RAISE EXCEPTION 'Revenue contracts have no payment plan'; END IF;
    SELECT e.* INTO amendment FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.kind IN ('modification', 'rate_reset', 'replacement')
        AND e.reverses_id IS NULL AND e.effective_on <= as_of AND e.sequence <= through_sequence AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r
            WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id AND r.effective_on <= as_of AND r.sequence <= through_sequence) ORDER BY e.sequence DESC LIMIT 1;
    plan_id := coalesce(amendment.id, definition.id); activation := coalesce(amendment.sequence, definition.sequence); plan_date := coalesce(amendment.effective_on, definition.effective_on);
    payments := coalesce(amendment.inputs->'payments', definition.inputs->'payments');
    SELECT e.* INTO rate_change FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract
        AND (e.kind IN ('rate_reset', 'replacement') OR e.kind = 'modification' AND e.inputs ? 'revised_rate_per_slot')
        AND e.reverses_id IS NULL AND e.effective_on <= as_of AND e.sequence <= through_sequence AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r
            WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id AND r.effective_on <= as_of AND r.sequence <= through_sequence) ORDER BY e.sequence DESC LIMIT 1;
    current_rate := coalesce(rate_change.inputs->>'rate_per_slot', rate_change.inputs->>'revised_rate_per_slot', definition.inputs->>'rate_per_slot');
    SELECT e.* INTO replacement FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.kind = 'replacement'
        AND e.reverses_id IS NULL AND e.effective_on <= as_of AND e.sequence <= through_sequence AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r
            WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id AND r.effective_on <= as_of AND r.sequence <= through_sequence) ORDER BY e.sequence DESC LIMIT 1;
    instrument := coalesce(replacement.id, definition.id); anchor := coalesce((replacement.inputs->'payments'->0->>'due_on')::date, (definition.inputs->'payments'->0->>'due_on')::date);
    SELECT e.inputs->>'benchmark_rate' INTO benchmark FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.kind = 'rate_reset'
        AND e.reverses_id IS NULL AND e.effective_on <= as_of AND e.sequence <= through_sequence AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r
            WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id AND r.effective_on <= as_of AND r.sequence <= through_sequence) ORDER BY e.sequence DESC LIMIT 1;
    SELECT coalesce(max(CASE WHEN e.kind = 'period' THEN (coalesce((SELECT a.inputs->'payments' FROM ledger_finance_events a WHERE a.tenant_id = tenant AND a.book_id = book AND a.id = (e.inputs->>'plan_id')::uuid),
            definition.inputs->'payments')->((e.inputs->>'line')::integer - 1)->>'due_on')::date ELSE e.effective_on END), definition.effective_on)
        INTO carrying_date FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract
        AND e.kind IN ('period', 'modification', 'settlement', 'rate_reset', 'replacement') AND e.reverses_id IS NULL AND e.effective_on <= as_of AND e.sequence <= through_sequence
        AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id AND r.effective_on <= as_of AND r.sequence <= through_sequence);
    SELECT count(*) + 1, coalesce(bool_or(e.inputs ? 'paid_amount' AND (e.inputs->>'paid_amount')::numeric < (payments->((e.inputs->>'line')::integer - 1)->>'payment')::numeric), false)
        INTO next_line, actual_required FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.kind = 'period'
        AND e.sequence > activation AND e.effective_on <= as_of AND e.sequence <= through_sequence AND e.reverses_id IS NULL
        AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id AND r.effective_on <= as_of AND r.sequence <= through_sequence);
    SELECT c.scale INTO STRICT currency_scale FROM ledger_books b JOIN ledger_currencies c ON c.tenant_id = b.tenant_id AND c.code = b.currency_code WHERE b.tenant_id = tenant AND b.id = book;
    IF (amendment.id IS NOT NULL AND amendment.kind <> 'rate_reset') OR definition.kind = 'lease' THEN
        scheduled_carrying := ledger_finance_pv(payments, current_rate::numeric, currency_scale, next_line - 1);
    ELSE
        scheduled_carrying := coalesce((amendment.calculation->>'preserved_carrying_minor')::numeric, definition.opening_minor);
        FOR i IN 1..next_line - 1 LOOP
            scheduled_carrying := scheduled_carrying + ledger_fraction(scheduled_carrying * current_rate::numeric, 1, 'half_even')
                - ledger_finance_amount(payments->(i - 1)->>'payment', currency_scale);
        END LOOP;
    END IF;
    RETURN jsonb_build_object('plan_id', plan_id, 'plan_sequence', activation::text, 'instrument_sequence', coalesce(replacement.sequence, definition.sequence)::text, 'effective_on', plan_date, 'carrying_on', carrying_date,
        'payments', payments, 'rate_per_slot', current_rate, 'rate_period_months', definition.inputs->'rate_period_months', 'next_line', next_line, 'requires_actual_payment', actual_required,
        'instrument_id', instrument, 'anchor_date', anchor, 'benchmark_rate', coalesce(benchmark, definition.inputs->'floating'->>'benchmark_rate'),
        'scheduled_carrying_minor', scheduled_carrying::text,
        'measurement', CASE WHEN (amendment.id IS NOT NULL AND amendment.kind <> 'rate_reset') OR definition.kind = 'lease' THEN 'present_value' ELSE 'period_rounding' END);
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

CREATE OR REPLACE FUNCTION ledger_finance_stage_loss(data jsonb, rate numeric, currency_scale integer) RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE assessment jsonb := data->'assessment'; scenario jsonb; event jsonb; flow jsonb; stage integer; horizon integer; lifetime integer;
    months integer; previous integer := -1; slot integer; max_period integer; i integer; weights numeric := 0; probability numeric; probabilities numeric; weight numeric;
    shortfall numeric; weighted numeric := 0; base numeric; factor numeric; amount numeric; receipt numeric;
BEGIN
    IF jsonb_typeof(assessment) IS DISTINCT FROM 'object' OR EXISTS (SELECT 1 FROM jsonb_object_keys(assessment) k WHERE k NOT IN
        ('policy_version', 'reference', 'credit_impaired', 'defaulted', 'significant_increase', 'low_credit_risk', 'days_past_due', 'sicr_backstop_days', 'default_backstop_days', 'backstop_rebuttal_reference', 'default_rebuttal_reference', 'slot_months', 'stage1_horizon_slots', 'lifetime_slots'))
        OR jsonb_typeof(assessment->'policy_version') IS DISTINCT FROM 'string' OR length(btrim(assessment->>'policy_version')) NOT BETWEEN 1 AND 128
        OR jsonb_typeof(assessment->'reference') IS DISTINCT FROM 'string' OR length(btrim(assessment->>'reference')) NOT BETWEEN 1 AND 256
        OR jsonb_typeof(assessment->'credit_impaired') IS DISTINCT FROM 'boolean' OR jsonb_typeof(assessment->'significant_increase') IS DISTINCT FROM 'boolean'
        OR jsonb_typeof(assessment->'low_credit_risk') IS DISTINCT FROM 'boolean'
        OR jsonb_typeof(assessment->'defaulted') IS DISTINCT FROM 'boolean' OR assessment->'sicr_backstop_days' IS DISTINCT FROM '30'::jsonb
        OR assessment->'default_backstop_days' IS DISTINCT FROM '90'::jsonb
        OR jsonb_typeof(assessment->'days_past_due') IS DISTINCT FROM 'number' OR coalesce(assessment->>'days_past_due', '') !~ '^[0-9]{1,5}$'
        OR jsonb_typeof(assessment->'slot_months') IS DISTINCT FROM 'number' OR coalesce(assessment->>'slot_months', '') !~ '^[1-9][0-9]{0,2}$'
        OR jsonb_typeof(assessment->'stage1_horizon_slots') IS DISTINCT FROM 'number' OR coalesce(assessment->>'stage1_horizon_slots', '') !~ '^[1-9][0-9]{0,2}$'
        OR jsonb_typeof(assessment->'lifetime_slots') IS DISTINCT FROM 'number' OR coalesce(assessment->>'lifetime_slots', '') !~ '^[1-9][0-9]{0,2}$' THEN
        RAISE EXCEPTION 'Credit staging requires explicit versioned risk assessments and default-horizon conventions'; END IF;
    months := (assessment->>'slot_months')::integer; horizon := (assessment->>'stage1_horizon_slots')::integer; lifetime := (assessment->>'lifetime_slots')::integer;
    IF months NOT BETWEEN 1 AND 120 OR lifetime > 600 OR horizon <> least(lifetime, greatest(1, 12 / months)) OR (months > 12 AND lifetime > 0)
        OR (mod(12, months) <> 0 AND lifetime * months > 12) THEN RAISE EXCEPTION 'Stage-1 default horizon must cover twelve months or shorter remaining life on the explicit slot grid'; END IF;
    IF (assessment->>'low_credit_risk')::boolean AND ((assessment->>'significant_increase')::boolean OR (assessment->>'credit_impaired')::boolean) THEN RAISE EXCEPTION 'A low-credit-risk election cannot contradict observed deterioration or impairment'; END IF;
    IF assessment->>'backstop_rebuttal_reference' IS NOT NULL AND (jsonb_typeof(assessment->'backstop_rebuttal_reference') <> 'string' OR length(btrim(assessment->>'backstop_rebuttal_reference')) NOT BETWEEN 1 AND 256) THEN RAISE EXCEPTION 'Rebutting the thirty-day SICR presumption needs reviewed evidence'; END IF;
    IF assessment->>'default_rebuttal_reference' IS NOT NULL AND (jsonb_typeof(assessment->'default_rebuttal_reference') <> 'string' OR length(btrim(assessment->>'default_rebuttal_reference')) NOT BETWEEN 1 AND 256) THEN RAISE EXCEPTION 'Rebutting the ninety-day default presumption needs reviewed evidence'; END IF;
    stage := CASE WHEN (assessment->>'credit_impaired')::boolean OR (assessment->>'defaulted')::boolean OR (assessment->>'days_past_due')::integer >= 90 AND assessment->>'default_rebuttal_reference' IS NULL THEN 3 WHEN (assessment->>'significant_increase')::boolean
        OR (assessment->>'days_past_due')::integer > 30 AND assessment->>'backstop_rebuttal_reference' IS NULL THEN 2 ELSE 1 END;
    IF stage > 1 AND (assessment->>'low_credit_risk')::boolean THEN RAISE EXCEPTION 'A low-credit-risk election conflicts with unrebutted deterioration or default'; END IF;
    IF jsonb_typeof(data->'contractual') IS DISTINCT FROM 'array' OR jsonb_array_length(data->'contractual') NOT BETWEEN 1 AND 600
        OR jsonb_typeof(data->'scenarios') IS DISTINCT FROM 'array' OR jsonb_array_length(data->'scenarios') NOT BETWEEN 1 AND 100 THEN RAISE EXCEPTION 'Default-horizon models require bounded contractual flows and scenarios'; END IF;
    FOR flow IN SELECT value FROM jsonb_array_elements(data->'contractual') LOOP
        IF jsonb_typeof(flow) IS DISTINCT FROM 'object' OR EXISTS (SELECT 1 FROM jsonb_object_keys(flow) k WHERE k NOT IN ('period', 'amount')) OR jsonb_typeof(flow->'amount') IS DISTINCT FROM 'string' THEN RAISE EXCEPTION 'Default-time contractual flows require exact named inputs'; END IF;
        IF jsonb_typeof(flow->'period') IS DISTINCT FROM 'number' OR coalesce(flow->>'period', '') !~ '^[0-9]{1,3}$' THEN RAISE EXCEPTION 'Credit cash-flow periods must be integers'; END IF;
        slot := (flow->>'period')::integer;
        IF slot <= previous OR slot > 600 THEN RAISE EXCEPTION 'Credit cash-flow periods must increase and stay bounded'; END IF;
        previous := slot;
    END LOOP;
    max_period := previous; base := power(10::numeric, scale(rate)); factor := base + rate * base;
    FOR scenario IN SELECT value FROM jsonb_array_elements(data->'scenarios') LOOP
        IF jsonb_typeof(scenario) IS DISTINCT FROM 'object' OR EXISTS (SELECT 1 FROM jsonb_object_keys(scenario) k WHERE k NOT IN ('weight', 'defaults')) OR jsonb_typeof(scenario->'weight') IS DISTINCT FROM 'string' THEN RAISE EXCEPTION 'Default scenarios require exact weights and named default cases'; END IF;
        weight := ledger_finance_rate(scenario->>'weight'); weights := weights + weight; probabilities := 0;
        IF jsonb_typeof(scenario->'defaults') IS DISTINCT FROM 'array' OR jsonb_array_length(scenario->'defaults') NOT BETWEEN 1 AND 600 THEN RAISE EXCEPTION 'Scenarios require mutually exclusive default-time cases'; END IF;
        previous := -1;
        FOR event IN SELECT value FROM jsonb_array_elements(scenario->'defaults') LOOP
            IF jsonb_typeof(event) IS DISTINCT FROM 'object' OR EXISTS (SELECT 1 FROM jsonb_object_keys(event) k WHERE k NOT IN ('period', 'probability', 'receipts')) OR jsonb_typeof(event->'probability') IS DISTINCT FROM 'string'
                OR EXISTS (SELECT 1 FROM jsonb_array_elements(event->'receipts') r WHERE jsonb_typeof(r) <> 'string') THEN RAISE EXCEPTION 'Conditional default cases require exact probabilities and receipt strings'; END IF;
            IF jsonb_typeof(event->'period') IS DISTINCT FROM 'number' OR coalesce(event->>'period', '') !~ '^[0-9]{1,3}$' THEN RAISE EXCEPTION 'Default periods must be integers'; END IF;
            slot := (event->>'period')::integer; probability := ledger_finance_rate(event->>'probability'); probabilities := probabilities + probability;
            IF slot <= previous OR slot > lifetime OR (stage = 3 AND (slot <> 0 OR probability <> 1)) OR (stage <> 3 AND slot = 0)
                OR jsonb_typeof(event->'receipts') IS DISTINCT FROM 'array' OR jsonb_array_length(event->'receipts') <> jsonb_array_length(data->'contractual') THEN RAISE EXCEPTION 'Default-time cases must be ordered, within life and consistent with the reviewed stage'; END IF;
            previous := slot; shortfall := 0; i := 0;
            FOR flow IN SELECT value FROM jsonb_array_elements(data->'contractual') LOOP
                amount := ledger_finance_amount(flow->>'amount', currency_scale); receipt := ledger_finance_amount(event->'receipts'->>i, currency_scale);
                IF (flow->>'period')::integer < slot AND receipt <> amount THEN RAISE EXCEPTION 'Conditional default losses cannot precede their default time'; END IF;
                shortfall := shortfall + (amount - receipt) * power(base, (flow->>'period')::integer) * power(factor, max_period - (flow->>'period')::integer); i := i + 1;
            END LOOP;
            IF shortfall < 0 THEN RAISE EXCEPTION 'Conditional default receipts exceed contractual present value'; END IF;
            IF stage <> 1 OR slot <= horizon THEN weighted := weighted + shortfall * probability * weight; END IF;
        END LOOP;
        IF probabilities > 1 OR (stage = 3 AND probabilities <> 1) THEN RAISE EXCEPTION 'Mutually exclusive default probabilities cannot exceed one'; END IF;
    END LOOP;
    IF weights <> 1 THEN RAISE EXCEPTION 'Credit scenario weights must sum exactly to one'; END IF;
    RETURN jsonb_build_object('stage', stage, 'allowance_minor', ledger_fraction(weighted, power(factor, max_period), 'half_even')::text,
        'default_horizon_slots', CASE WHEN stage = 1 THEN horizon ELSE lifetime END, 'lifetime_slots', lifetime, 'assessment', assessment);
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
    new_rate numeric; fair_value numeric; fee_paid numeric; fee_received numeric; transaction_cost numeric; review jsonb; threshold boolean; net_old numeric; item record;
    coupon numeric; principal_due numeric; initial_cost numeric; arrears numeric := 0;
BEGIN
    SELECT * INTO STRICT definition FROM ledger_finance_contracts WHERE tenant_id = tenant AND book_id = book AND id = contract;
    IF definition.kind = 'contract_revenue' THEN RETURN ledger_revenue_quote(tenant, book, contract, date_arg, operation, data); END IF;
    IF jsonb_typeof(data) IS DISTINCT FROM 'object' OR date_arg < definition.effective_on OR EXISTS (SELECT 1 FROM ledger_finance_events
        WHERE tenant_id = tenant AND book_id = book AND contract_id = contract AND effective_on > date_arg) THEN RAISE EXCEPTION 'Finance events require chronological accounting dates'; END IF;
    IF EXISTS (SELECT 1 FROM jsonb_object_keys(data) key WHERE NOT key = ANY(CASE operation
        WHEN 'period' THEN ARRAY['line', 'plan_id', 'paid_amount', 'interest_paid', 'evidence'] WHEN 'ecl' THEN ARRAY['stage', 'model_reference', 'contractual', 'scenarios', 'evidence']
        WHEN 'credit_assess' THEN ARRAY['assessment', 'contractual', 'scenarios', 'evidence']
        WHEN 'recognize' THEN ARRAY['reference', 'cumulative_fraction', 'evidence'] WHEN 'writeoff' THEN ARRAY['evidence'] WHEN 'partial_writeoff' THEN ARRAY['amount', 'evidence']
        WHEN 'recovery' THEN ARRAY['amount', 'evidence']
        WHEN 'modification' THEN ARRAY['payments', 'gain_account_id', 'loss_account_id', 'basis_reference', 'loss_model', 'prepayment', 'interest_paid', 'rou_carrying', 'revised_rate_per_slot', 'rate_revision_reference', 'evidence']
        WHEN 'rate_reset' THEN ARRAY['payments', 'rate_per_slot', 'benchmark_rate', 'market_only', 'basis_reference', 'evidence']
        WHEN 'replacement' THEN ARRAY['payments', 'rate_per_slot', 'fair_value', 'gain_account_id', 'loss_account_id', 'basis_reference', 'derecognition', 'loss_model', 'fees_paid', 'fees_received', 'transaction_cost', 'fee_cash_flow', 'cost_cash_flow', 'evidence']
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
    IF operation IN ('modification', 'settlement', 'rate_reset', 'replacement') THEN
        IF definition.kind NOT IN ('loan_asset', 'loan_liability', 'lease') OR carry <= 0 THEN RAISE EXCEPTION 'Loan changes require an active positive loan carrying value'; END IF;
        IF operation = 'modification' AND (definition.kind = 'lease' AND (data ? 'prepayment' OR data ? 'interest_paid' OR data ? 'loss_model') OR definition.kind <> 'lease' AND data ? 'rou_carrying'
            OR definition.kind <> 'lease' AND data ?| ARRAY['revised_rate_per_slot', 'rate_revision_reference']
            OR (data ? 'prepayment') <> (data ? 'interest_paid')) THEN RAISE EXCEPTION 'Invalid loan or lease change inputs'; END IF;
        IF jsonb_typeof(data->'basis_reference') IS DISTINCT FROM 'string' OR length(btrim(data->>'basis_reference')) NOT BETWEEN 1 AND 256 THEN RAISE EXCEPTION 'Loan changes require reviewed carrying-basis evidence'; END IF;
        IF operation <> 'rate_reset' THEN
          gain_account := (data->>'gain_account_id')::uuid; loss_account := (data->>'loss_account_id')::uuid;
          FOR account_arg IN SELECT * FROM (VALUES (gain_account, 'income'), (loss_account, 'expense')) a(id, type) LOOP
            IF NOT EXISTS (SELECT 1 FROM ledger_accounts a WHERE a.tenant_id = tenant AND a.book_id = book AND a.id = account_arg.id AND a.type = account_arg.type
                AND NOT a.is_group AND NOT a.is_control AND a.active AND a.deleted_at IS NULL AND coalesce(a.currency_code, functional_code) = functional_code AND a.cash_flow IS DISTINCT FROM 'cash')
                OR account_arg.id IN ((roles->>'interest')::uuid, (roles->>'ecl.expense')::uuid) THEN RAISE EXCEPTION 'Loan changes require separate compatible functional gain/loss accounts'; END IF;
            IF EXISTS (SELECT 1 FROM ledger_account_dimensions d WHERE d.tenant_id = tenant AND d.book_id = book AND d.account_id = account_arg.id AND NOT (definition.dimensions ? d.dimension)) THEN RAISE EXCEPTION 'A loan gain/loss account requires a missing dimension'; END IF;
          END LOOP;
        END IF;
        terms := ledger_finance_terms(tenant, book, contract, date_arg, 9223372036854775807);
        IF (terms->>'carrying_on')::date <> date_arg THEN RAISE EXCEPTION 'Loan changes must use a recognized carrying date; partial-slot interest is not inferred'; END IF;
        IF EXISTS (SELECT 1 FROM jsonb_array_elements(terms->'payments') WITH ORDINALITY p(value, n) WHERE p.n >= (terms->>'next_line')::integer AND (p.value->>'due_on')::date <= date_arg) THEN RAISE EXCEPTION 'Recognize due loan slots with actual payments before changing the loan'; END IF;
    END IF;
    IF operation = 'period' THEN
        IF definition.kind = 'revenue' OR carry <= 0 OR jsonb_typeof(data->'line') IS DISTINCT FROM 'number' OR data->>'line' !~ '^[1-9][0-9]{0,2}$' THEN RAISE EXCEPTION 'Invalid finance payment period'; END IF;
        index_arg := (data->>'line')::integer;
        terms := ledger_finance_terms(tenant, book, contract, date_arg, 9223372036854775807);
        IF NOT data ? 'paid_amount' AND EXISTS (SELECT 1 FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract
            AND e.kind = 'partial_writeoff' AND e.reverses_id IS NULL AND e.sequence > (terms->>'instrument_sequence')::bigint
            AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id)) THEN
            RAISE EXCEPTION 'After a partial write-off, record the actual cash received'; END IF;
        next_index := (terms->>'next_line')::integer; plan_id := (terms->>'plan_id')::uuid; payments := terms->'payments';
        IF data ? 'plan_id' AND (data->>'plan_id')::uuid IS DISTINCT FROM plan_id OR plan_id <> definition.id AND NOT data ? 'plan_id' THEN RAISE EXCEPTION 'Finance period must identify its current payment plan'; END IF;
        line := payments->(index_arg - 1);
        IF index_arg <> next_index OR line IS NULL OR (line->>'due_on')::date > date_arg THEN RAISE EXCEPTION 'Finance periods cannot be skipped, duplicated or posted before due'; END IF;
        rate := (terms->>'rate_per_slot')::numeric;
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
    ELSIF operation = 'rate_reset' THEN
        IF definition.kind NOT IN ('loan_asset', 'loan_liability') OR definition.inputs->'floating' IS NULL OR data->'market_only' IS DISTINCT FROM 'true'::jsonb
            OR terms->>'instrument_id' <> definition.id::text THEN RAISE EXCEPTION 'A market-rate reset requires the registered floating instrument'; END IF;
        IF (terms->>'requires_actual_payment')::boolean OR carry > (terms->>'scheduled_carrying_minor')::numeric THEN
            RAISE EXCEPTION 'A market fixing cannot defer arrears; reschedule overdue cash with a reviewed modification first'; END IF;
        IF carry < (terms->>'scheduled_carrying_minor')::numeric THEN RAISE EXCEPTION 'After a partial write-off, re-estimate the floating instrument with a reviewed modification instead of a market fixing'; END IF;
        new_rate := ledger_finance_rate(data->>'rate_per_slot');
        rate := ledger_finance_rate(data->>'benchmark_rate');
        coupon := rate + (definition.inputs->'floating'->>'contractual_spread')::numeric;
        IF coalesce((definition.inputs->'origination'->>'integral_fee')::numeric, 0) + coalesce((definition.inputs->'origination'->>'transaction_cost')::numeric, 0) = 0
            AND new_rate <> coupon THEN RAISE EXCEPTION 'A fee-free market-only reset has an effective rate equal to the new benchmark plus contractual spread'; END IF;
        payments := data->'payments'; PERFORM ledger_finance_payment_plan(payments, currency_scale, date_arg);
        SELECT coalesce(sum(ledger_finance_amount(p.value->>'payment', currency_scale) - ledger_finance_amount(p.value->>'interest_paid', currency_scale)), 0) INTO principal_due
            FROM jsonb_array_elements(terms->'payments') WITH ORDINALITY p(value, n) WHERE p.n >= (terms->>'next_line')::integer;
        PERFORM ledger_finance_coupon_plan(payments, principal_due, coupon, currency_scale, definition.inputs->'floating'->>'amortization',
            (SELECT jsonb_agg(p.value ORDER BY p.n) FROM jsonb_array_elements(terms->'payments') WITH ORDINALITY p(value, n) WHERE p.n >= (terms->>'next_line')::integer));
        IF jsonb_array_length(payments) <> jsonb_array_length(terms->'payments') - (terms->>'next_line')::integer + 1
            OR EXISTS (SELECT 1 FROM jsonb_array_elements(payments) WITH ORDINALITY p(value, n) WHERE p.value->>'due_on' IS DISTINCT FROM terms->'payments'->((terms->>'next_line')::integer + p.n::integer - 2)->>'due_on')
            THEN RAISE EXCEPTION 'Floating resets preserve remaining dates and amortized cost without a modification gain'; END IF;
        remeasured := carry;
        FOR line IN SELECT value FROM jsonb_array_elements(payments) LOOP
            remeasured := remeasured + ledger_fraction(remeasured * new_rate, 1, 'half_even') - ledger_finance_amount(line->>'payment', currency_scale);
            IF remeasured < 0 THEN EXIT; END IF;
        END LOOP;
        IF remeasured <> 0 THEN RAISE EXCEPTION 'Floating resets preserve remaining dates and amortized cost without a modification gain'; END IF;
        calculation := jsonb_build_object('previous_plan_id', terms->>'plan_id', 'old_rate_per_slot', terms->>'rate_per_slot', 'rate_per_slot', data->>'rate_per_slot',
            'old_benchmark_rate', terms->>'benchmark_rate', 'benchmark_rate', data->>'benchmark_rate', 'contractual_spread', definition.inputs->'floating'->>'contractual_spread',
            'old_coupon_rate', ((terms->>'benchmark_rate')::numeric + (definition.inputs->'floating'->>'contractual_spread')::numeric)::text,
            'new_coupon_rate', coupon::text, 'outstanding_principal_minor', principal_due::text,
            'preserved_carrying_minor', carry::text);
    ELSIF operation = 'modification' THEN
        payments := data->'payments';
        PERFORM ledger_finance_payment_plan(payments, currency_scale, date_arg);
        period_months := (terms->>'rate_period_months')::integer;
        IF period_months IS NULL THEN
            IF jsonb_array_length(payments) > jsonb_array_length(terms->'payments') - (terms->>'next_line')::integer + 1 THEN RAISE EXCEPTION 'Extending loan maturity requires a rate-period convention pinned at registration'; END IF;
            IF EXISTS (SELECT 1 FROM jsonb_array_elements(payments) WITH ORDINALITY p(value, n) WHERE p.value->>'due_on' IS DISTINCT FROM terms->'payments'->((terms->>'next_line')::integer + p.n::integer - 2)->>'due_on') THEN RAISE EXCEPTION 'Revised cash flows must preserve the original rate-slot dates'; END IF;
        ELSE
            anchor_date := (terms->>'anchor_date')::date; active_date := (terms->'payments'->0->>'due_on')::date;
            slot_offset := ((extract(year FROM active_date)::integer - extract(year FROM anchor_date)::integer) * 12 + extract(month FROM active_date)::integer - extract(month FROM anchor_date)::integer) / period_months + (terms->>'next_line')::integer - 1;
            IF EXISTS (SELECT 1 FROM jsonb_array_elements(payments) WITH ORDINALITY p(value, n) WHERE (p.value->>'due_on')::date <> ledger_finance_slot_date(anchor_date, period_months, slot_offset + p.n::integer - 1)) THEN RAISE EXCEPTION 'Revised cash flows must preserve the original rate-slot dates'; END IF;
        END IF;
        rate := (terms->>'rate_per_slot')::numeric;
        IF definition.inputs->'floating' IS NOT NULL AND terms->>'instrument_id' = definition.id::text THEN
            PERFORM ledger_finance_coupon_plan(payments, (SELECT sum(ledger_finance_amount(p->>'payment', currency_scale) - ledger_finance_amount(p->>'interest_paid', currency_scale)) FROM jsonb_array_elements(payments) p),
                (terms->>'benchmark_rate')::numeric + (definition.inputs->'floating'->>'contractual_spread')::numeric, currency_scale, definition.inputs->'floating'->>'amortization', NULL);
        END IF;
        IF data ? 'revised_rate_per_slot' OR data ? 'rate_revision_reference' THEN
            IF definition.kind <> 'lease' OR jsonb_typeof(data->'rate_revision_reference') IS DISTINCT FROM 'string'
                OR length(btrim(data->>'rate_revision_reference')) NOT BETWEEN 1 AND 256 THEN RAISE EXCEPTION 'Revised lease discount rates require reviewed reassessment evidence'; END IF;
            rate := ledger_finance_rate(data->>'revised_rate_per_slot');
        END IF;
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
                    FROM ledger_postings p WHERE p.tenant_id = tenant AND p.book_id = book AND p.account_id IN (definition.asset_account_id, (roles->>'lease.accumulated')::uuid) AND p.dimensions = definition.dimensions
                    AND p.journal_id IS DISTINCT FROM excluded_journal AND EXISTS (SELECT 1 FROM ledger_journals j WHERE j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id AND j.effective_on <= date_arg))) THEN
                RAISE EXCEPTION 'Reducing a lease liability needs the reviewed right-of-use carrying amount net of accumulated depreciation';
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
            IF new_stage <> old_stage AND EXISTS (SELECT 1 FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.kind = 'credit_assess'
                AND e.reverses_id IS NULL AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id)) THEN
                RAISE EXCEPTION 'After a reviewed staging assessment, change the credit stage through assessCredit'; END IF;
            rows := rows || ledger_asset_row((roles->>'ecl.expense')::uuid, ad) || ledger_asset_row(definition.allowance_account_id, -ad);
        ELSIF data ? 'loss_model' THEN RAISE EXCEPTION 'Loan liabilities do not use an asset ECL model'; END IF;
        calculation := jsonb_build_object('original_rate_per_slot', terms->>'rate_per_slot', 'revised_rate_per_slot', rate::text, 'previous_plan_id', terms->>'plan_id',
            'opening_minor', carry::text, 'closing_minor', remeasured::text, 'modification_effect_minor', (sign_arg * cd)::text, 'allowance_before_minor', allowance::text, 'allowance_after_minor', (allowance + ad)::text,
            'prepayment_minor', payment::text, 'counter_effect_minor', counter_amount::text);
    ELSIF operation = 'replacement' THEN
        review := data->'derecognition';
        IF definition.kind NOT IN ('loan_asset', 'loan_liability') OR jsonb_typeof(review) IS DISTINCT FROM 'object'
            OR EXISTS (SELECT 1 FROM jsonb_object_keys(review) k WHERE k NOT IN ('policy_version', 'reference', 'qualitative_substantial', 'asset_derecognition', 'credit_impaired_at_recognition'))
            OR jsonb_typeof(review->'policy_version') IS DISTINCT FROM 'string' OR length(btrim(review->>'policy_version')) NOT BETWEEN 1 AND 128
            OR jsonb_typeof(review->'reference') IS DISTINCT FROM 'string' OR length(btrim(review->>'reference')) NOT BETWEEN 1 AND 256
            OR jsonb_typeof(review->'qualitative_substantial') IS DISTINCT FROM 'boolean' THEN RAISE EXCEPTION 'Instrument replacement requires an explicit reviewed derecognition decision'; END IF;
        payments := data->'payments'; PERFORM ledger_finance_payment_plan(payments, currency_scale, date_arg);
        new_rate := ledger_finance_rate(data->>'rate_per_slot'); fair_value := ledger_finance_amount(data->>'fair_value', currency_scale);
        period_months := (terms->>'rate_period_months')::integer;
        IF period_months IS NULL THEN
            IF jsonb_array_length(payments) > jsonb_array_length(terms->'payments') - (terms->>'next_line')::integer + 1
                OR EXISTS (SELECT 1 FROM jsonb_array_elements(payments) WITH ORDINALITY p(value, n) WHERE p.value->>'due_on' IS DISTINCT FROM terms->'payments'->((terms->>'next_line')::integer + p.n::integer - 2)->>'due_on') THEN
                RAISE EXCEPTION 'Replacement cash flows require the original discount-slot dates or a registered calendar convention'; END IF;
        ELSE
            anchor_date := (terms->>'anchor_date')::date; active_date := (terms->'payments'->0->>'due_on')::date;
            slot_offset := ((extract(year FROM active_date)::integer - extract(year FROM anchor_date)::integer) * 12 + extract(month FROM active_date)::integer - extract(month FROM anchor_date)::integer) / period_months + (terms->>'next_line')::integer - 1;
            IF EXISTS (SELECT 1 FROM jsonb_array_elements(payments) WITH ORDINALITY p(value, n) WHERE (p.value->>'due_on')::date <> ledger_finance_slot_date(anchor_date, period_months, slot_offset + p.n::integer - 1)) THEN RAISE EXCEPTION 'Replacement cash flows must preserve discount time through explicit zero-payment slots'; END IF;
        END IF;
        fee_paid := ledger_finance_amount(data->>'fees_paid', currency_scale); fee_received := ledger_finance_amount(data->>'fees_received', currency_scale);
        transaction_cost := ledger_finance_amount(data->>'transaction_cost', currency_scale);
        initial_cost := CASE WHEN definition.kind = 'loan_asset' THEN fair_value - fee_received + fee_paid + transaction_cost ELSE fair_value END;
        IF fair_value <= 0 OR initial_cost <= 0 OR initial_cost <> ledger_finance_pv(payments, new_rate, currency_scale) THEN
            RAISE EXCEPTION 'A replacement instrument requires its reviewed positive fair value and an effective-rate plan conserving its initial amortized cost'; END IF;
        IF coalesce(data->>'fee_cash_flow', '') NOT IN ('operating', 'investing', 'financing') OR coalesce(data->>'cost_cash_flow', '') NOT IN ('operating', 'investing', 'financing') THEN RAISE EXCEPTION 'Replacement fees and costs require explicit cash-flow policies'; END IF;
        rate := (terms->>'rate_per_slot')::numeric;
        IF definition.kind = 'loan_liability' THEN
            original_discounted := ledger_finance_discounted(terms->'payments', rate, currency_scale, (terms->>'next_line')::integer - 1);
            original_discounted[1] := original_discounted[1] + (carry - (terms->>'scheduled_carrying_minor')::numeric) * original_discounted[2];
            discounted := ledger_finance_discounted(payments, rate, currency_scale);
            discounted[1] := discounted[1] + (fee_paid - fee_received) * discounted[2];
            threshold := abs(discounted[1] * original_discounted[2] - original_discounted[1] * discounted[2]) * 10 >= original_discounted[1] * discounted[2];
            IF NOT threshold AND NOT (review->>'qualitative_substantial')::boolean THEN RAISE EXCEPTION 'A liability replacement requires the ten-percent test or reviewed qualitative substantiality'; END IF;
            IF data ? 'loss_model' OR review ?| ARRAY['asset_derecognition', 'credit_impaired_at_recognition'] THEN RAISE EXCEPTION 'Liability derecognition does not use asset credit judgments'; END IF;
        ELSIF review->'asset_derecognition' IS DISTINCT FROM 'true'::jsonb THEN RAISE EXCEPTION 'Asset derecognition requires its own reviewed assessment, not a liability ten-percent test';
        ELSIF jsonb_typeof(review->'credit_impaired_at_recognition') IS DISTINCT FROM 'boolean' OR (review->>'credit_impaired_at_recognition')::boolean THEN
            RAISE EXCEPTION 'A replacement asset needs a reviewed credit-impaired-at-recognition decision; purchased or originated credit-impaired assets are not supported'; END IF;
        sign_arg := CASE WHEN definition.kind = 'loan_asset' THEN 1 ELSE -1 END;
        cd := initial_cost - carry; ad := -allowance; net_old := carry - allowance;
        counter_amount := CASE WHEN sign_arg = 1 THEN net_old - fair_value ELSE fair_value - carry + fee_paid - fee_received + transaction_cost END;
        rows := ledger_asset_row(definition.carrying_account_id, -sign_arg * carry) || ledger_asset_row(definition.carrying_account_id, sign_arg * initial_cost)
            || ledger_asset_row(definition.allowance_account_id, allowance) || ledger_asset_row((roles->>'cash')::uuid, fee_received - fee_paid - transaction_cost)
            || ledger_asset_row(CASE WHEN counter_amount > 0 THEN loss_account ELSE gain_account END, counter_amount);
        new_stage := 1;
        IF definition.kind = 'loan_asset' THEN
            model := data->'loss_model';
            IF model->'stage' IS DISTINCT FROM '1'::jsonb OR jsonb_typeof(model->'model_reference') IS DISTINCT FROM 'string'
                OR length(btrim(model->>'model_reference')) NOT BETWEEN 1 AND 256 THEN RAISE EXCEPTION 'A new non-POCI loan asset needs reviewed initial stage-one ECL'; END IF;
            IF EXISTS (SELECT 1 FROM jsonb_object_keys(model) key WHERE key NOT IN ('stage', 'model_reference', 'contractual', 'scenarios', 'evidence')) THEN RAISE EXCEPTION 'Unknown replacement ECL model input'; END IF;
            IF jsonb_array_length(model->'contractual') < jsonb_array_length(payments) THEN RAISE EXCEPTION 'Replacement ECL must cover the replacement payments'; END IF;
            flow_index := 0;
            FOR flow IN SELECT value FROM jsonb_array_elements(model->'contractual') LOOP
                IF flow_index < jsonb_array_length(payments) AND ((flow->>'period')::integer IS DISTINCT FROM flow_index + 1 OR ledger_finance_amount(flow->>'amount', currency_scale) <> ledger_finance_amount(payments->flow_index->>'payment', currency_scale))
                    OR flow_index >= jsonb_array_length(payments) AND ledger_finance_amount(flow->>'amount', currency_scale) <> 0 THEN RAISE EXCEPTION 'Replacement ECL contractual flows must match new payments'; END IF;
                flow_index := flow_index + 1;
            END LOOP;
            remeasured := ledger_finance_ecl(model, new_rate, currency_scale);
            ad := ad + remeasured;
            rows := rows || ledger_asset_row((roles->>'ecl.expense')::uuid, remeasured) || ledger_asset_row(definition.allowance_account_id, -remeasured);
        END IF;
        cash_flow := jsonb_build_object(data->>'fee_cash_flow', (fee_received - fee_paid)::text);
        cash_flow := cash_flow || jsonb_build_object(data->>'cost_cash_flow', (coalesce((cash_flow->>(data->>'cost_cash_flow'))::numeric, 0) - transaction_cost)::text);
        calculation := jsonb_build_object('previous_instrument_id', terms->>'instrument_id', 'previous_plan_id', terms->>'plan_id', 'derecognized_gross_minor', carry::text,
            'derecognized_allowance_minor', allowance::text, 'replacement_fair_value_minor', fair_value::text, 'initial_amortized_cost_minor', initial_cost::text, 'old_rate_per_slot', terms->>'rate_per_slot', 'new_rate_per_slot', data->>'rate_per_slot',
            'ten_percent_test', threshold, 'counterparty_fees_net_minor', (fee_paid - fee_received)::text, 'third_party_cost_minor', transaction_cost::text, 'derecognition_loss_minor', counter_amount::text);
    ELSIF operation = 'settlement' THEN
        payment := ledger_finance_amount(data->>'amount', currency_scale); paid_interest := ledger_finance_amount(data->>'interest_paid', currency_scale);
        IF paid_interest > payment THEN RAISE EXCEPTION 'Settlement cash interest cannot exceed total cash'; END IF;
        cd := -carry; ad := -allowance;
        sign_arg := CASE WHEN definition.kind = 'loan_asset' THEN 1 ELSE -1 END;
        counter_amount := sign_arg * (carry - payment);
        rows := ledger_asset_row((roles->>'cash')::uuid, sign_arg * payment) || ledger_asset_row(definition.carrying_account_id, -sign_arg * carry);
        IF definition.kind = 'lease' THEN
            SELECT coalesce(sum(CASE WHEN side = 'debit' THEN functional_minor ELSE -functional_minor END), 0) INTO remeasured
                FROM ledger_postings p WHERE p.tenant_id = tenant AND p.book_id = book AND p.account_id = definition.asset_account_id AND p.dimensions = definition.dimensions
                AND p.journal_id IS DISTINCT FROM excluded_journal AND EXISTS (SELECT 1 FROM ledger_journals j WHERE j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id AND j.effective_on <= date_arg);
            SELECT coalesce(sum(CASE WHEN side = 'credit' THEN functional_minor ELSE -functional_minor END), 0) INTO remeasured_rou
                FROM ledger_postings p WHERE p.tenant_id = tenant AND p.book_id = book AND p.account_id = (roles->>'lease.accumulated')::uuid AND p.dimensions = definition.dimensions
                AND p.journal_id IS DISTINCT FROM excluded_journal AND EXISTS (SELECT 1 FROM ledger_journals j WHERE j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id AND j.effective_on <= date_arg);
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
        IF new_stage <> old_stage AND EXISTS (SELECT 1 FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.kind = 'credit_assess'
            AND e.reverses_id IS NULL AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id)) THEN
            RAISE EXCEPTION 'After a reviewed staging assessment, change the credit stage through assessCredit'; END IF;
        terms := ledger_finance_terms(tenant, book, contract, date_arg, 9223372036854775807);
        ad := ledger_finance_ecl(data, (terms->>'rate_per_slot')::numeric, currency_scale) - allowance;
        IF allowance + ad > carry THEN RAISE EXCEPTION 'ECL allowance cannot exceed gross carrying value'; END IF;
        rows := ledger_asset_row((roles->>'ecl.expense')::uuid, ad) || ledger_asset_row(definition.allowance_account_id, -ad);
    ELSIF operation = 'credit_assess' THEN
        IF definition.kind <> 'loan_asset' OR carry <= 0 THEN RAISE EXCEPTION 'Stage assessment requires a live loan asset'; END IF;
        terms := ledger_finance_terms(tenant, book, contract, date_arg, 9223372036854775807);
        IF (terms->>'carrying_on')::date <> date_arg THEN RAISE EXCEPTION 'Default-time assessment requires a recognized carrying date'; END IF;
        IF terms->>'rate_period_months' IS NULL THEN RAISE EXCEPTION 'Default horizons require a registered calendar rate convention'; END IF;
        payments := terms->'payments'; next_index := (terms->>'next_line')::integer;
        arrears := carry - (terms->>'scheduled_carrying_minor')::numeric;
        IF EXISTS (SELECT 1 FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.kind = 'partial_writeoff' AND e.reverses_id IS NULL
            AND e.sequence > (terms->>'instrument_sequence')::bigint AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id)) THEN
            arrears := greatest(arrears, 0);
        ELSIF arrears < 0 OR (arrears > 0) <> (terms->>'requires_actual_payment')::boolean THEN RAISE EXCEPTION 'Default-time assessment requires the remaining plan to explain the carrying amount'; END IF;
        IF (data->'assessment'->>'days_past_due')::integer < date_arg - (SELECT min((payments->((e.inputs->>'line')::integer - 1)->>'due_on')::date)
            FROM ledger_finance_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.contract_id = contract AND e.kind = 'period' AND e.inputs ? 'paid_amount'
                AND ledger_finance_amount(e.inputs->>'paid_amount', currency_scale) < ledger_finance_amount(payments->((e.inputs->>'line')::integer - 1)->>'payment', currency_scale)
                AND e.reverses_id IS NULL AND e.sequence > (terms->>'plan_sequence')::bigint
                AND NOT EXISTS (SELECT 1 FROM ledger_finance_events r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id)) THEN
            RAISE EXCEPTION 'Reviewed days past due cannot be fewer than the ledger shows since the oldest unpaid scheduled payment'; END IF;
        active_date := (payments->(next_index - 1)->>'due_on')::date;
        anchor_date := ledger_finance_slot_date(date_arg, (terms->>'rate_period_months')::integer, 1);
        IF active_date IS DISTINCT FROM anchor_date AND NOT (date_arg = date_trunc('month', date_arg)::date AND active_date = anchor_date - 1) THEN
            RAISE EXCEPTION 'Default-time horizons cannot infer stub periods; supply an explicitly reviewed cash-flow ECL model'; END IF;
        IF (data->'assessment'->>'lifetime_slots')::integer IS DISTINCT FROM jsonb_array_length(payments) - next_index + 1
            OR terms->>'rate_period_months' IS NOT NULL AND data->'assessment'->>'slot_months' IS DISTINCT FROM terms->>'rate_period_months' THEN RAISE EXCEPTION 'Default horizons must match the remaining contractual lifetime and rate convention'; END IF;
        flow_index := CASE WHEN arrears > 0 THEN -1 ELSE 0 END;
        IF jsonb_array_length(data->'contractual') < jsonb_array_length(payments) - next_index + 1 - flow_index THEN RAISE EXCEPTION 'Default-time cash flows must cover remaining contractual payments'; END IF;
        FOR flow IN SELECT value FROM jsonb_array_elements(data->'contractual') LOOP
            IF flow_index = -1 THEN
                IF (flow->>'period')::integer IS DISTINCT FROM 0 OR ledger_finance_amount(flow->>'amount', currency_scale) <> arrears THEN RAISE EXCEPTION 'Recorded arrears are an immediately due contractual cash flow at period zero'; END IF;
            ELSIF flow_index < jsonb_array_length(payments) - next_index + 1 THEN
                IF (flow->>'period')::integer IS DISTINCT FROM flow_index + 1 OR ledger_finance_amount(flow->>'amount', currency_scale) <> ledger_finance_amount(payments->(next_index + flow_index - 1)->>'payment', currency_scale) THEN RAISE EXCEPTION 'Default-time cash flows differ from remaining contractual payments'; END IF;
            ELSIF ledger_finance_amount(flow->>'amount', currency_scale) <> 0 THEN RAISE EXCEPTION 'Extra cash-flow slots can represent delayed recovery only'; END IF;
            flow_index := flow_index + 1;
        END LOOP;
        calculation := ledger_finance_stage_loss(data, (terms->>'rate_per_slot')::numeric, currency_scale);
        new_stage := (calculation->>'stage')::integer; ad := (calculation->>'allowance_minor')::numeric - allowance;
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
            OR NEW.allowance_account_id IS DISTINCT FROM (expected->>'allowance_account_id')::uuid OR NEW.asset_account_id IS DISTINCT FROM (expected->>'asset_account_id')::uuid
            OR NEW.liability_account_id IS DISTINCT FROM (expected->>'liability_account_id')::uuid OR NEW.refund_account_id IS DISTINCT FROM (expected->>'refund_account_id')::uuid THEN RAISE EXCEPTION 'Finance registration differs from its independently measured basis'; END IF;
        IF NEW.kind = 'contract_revenue' THEN
            IF NOT EXISTS (SELECT 1 FROM ledger_parties p JOIN ledger_books b ON b.tenant_id = p.tenant_id AND b.entity_id = p.entity_id
                WHERE p.tenant_id = NEW.tenant_id AND b.id = NEW.book_id AND p.id = (NEW.inputs->>'party_id')::uuid AND p.deleted_at IS NULL) THEN RAISE EXCEPTION 'Revenue customer must belong to this book entity'; END IF;
            IF NEW.inputs ? 'separate_from' AND NOT EXISTS (SELECT 1 FROM ledger_finance_contracts c WHERE c.tenant_id = NEW.tenant_id AND c.book_id = NEW.book_id
                AND c.id = (NEW.inputs->>'separate_from')::uuid AND c.kind = 'contract_revenue' AND c.inputs->>'party_id' = NEW.inputs->>'party_id' AND c.effective_on <= NEW.effective_on
                AND NOT EXISTS (SELECT 1 FROM ledger_finance_events e WHERE e.tenant_id = c.tenant_id AND e.book_id = c.book_id AND e.contract_id = c.id AND e.kind = 'cancellation')) THEN RAISE EXCEPTION 'Separate revenue modification requires its live original customer contract'; END IF;
        END IF;
        protected := array_remove(ARRAY[NEW.carrying_account_id, NEW.allowance_account_id, NEW.asset_account_id, NEW.liability_account_id, NEW.refund_account_id], NULL);
        IF cardinality(protected) <> (SELECT count(DISTINCT id) FROM unnest(protected) id) THEN RAISE EXCEPTION 'Finance carrying, allowance and ROU accounts must be distinct'; END IF;
        FOR value IN SELECT * FROM jsonb_each_text(configuration.roles) LOOP
            role_name := value.key;
            IF role_name NOT IN ('cash', 'loan', 'interest', 'ecl.allowance', 'ecl.expense', 'lease.liability', 'lease.asset', 'lease.accumulated', 'contract.liability', 'contract.asset', 'contract.refund', 'contract.allowance', 'contract.impairment', 'receivable') AND role_name NOT LIKE 'revenue.%' THEN CONTINUE; END IF;
            IF role_name = 'loan' AND NEW.kind NOT IN ('loan_asset', 'loan_liability') OR role_name = 'interest' AND NEW.kind IN ('revenue', 'contract_revenue') OR role_name IN ('ecl.allowance', 'ecl.expense') AND NEW.kind <> 'loan_asset'
                OR role_name IN ('lease.asset', 'lease.liability', 'lease.accumulated') AND NEW.kind <> 'lease' OR (role_name = 'contract.liability' OR role_name LIKE 'revenue.%') AND NEW.kind NOT IN ('revenue', 'contract_revenue')
                OR role_name IN ('contract.asset', 'contract.refund', 'contract.allowance', 'contract.impairment', 'receivable') AND NEW.kind <> 'contract_revenue' THEN CONTINUE; END IF;
            account_arg := value.value::uuid;
            account_type := CASE WHEN role_name = 'cash' OR role_name IN ('lease.asset', 'lease.accumulated', 'ecl.allowance', 'contract.asset', 'contract.allowance', 'receivable') OR role_name = 'loan' AND NEW.kind = 'loan_asset' THEN 'asset'
                WHEN role_name IN ('lease.liability', 'contract.liability', 'contract.refund', 'loan') THEN 'liability' WHEN role_name IN ('ecl.expense', 'contract.impairment') OR role_name = 'interest' AND NEW.kind <> 'loan_asset' THEN 'expense' ELSE 'income' END;
            IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = account_arg AND type = account_type AND NOT is_group AND is_control = (role_name = 'receivable') AND active AND deleted_at IS NULL
                AND (role_name NOT IN ('lease.accumulated', 'ecl.allowance', 'contract.allowance') OR normal_side = 'credit')
                AND coalesce(currency_code, functional_code) = functional_code AND ((role_name = 'cash') = (ledger_accounts.cash_flow IS NOT DISTINCT FROM 'cash')))
                OR role_name NOT IN ('loan', 'lease.liability', 'lease.asset', 'contract.liability', 'contract.asset', 'contract.refund', 'contract.allowance', 'ecl.allowance') AND account_arg = ANY(protected) THEN RAISE EXCEPTION 'Finance role has an incompatible account: %', role_name; END IF;
        END LOOP;
        IF NEW.kind = 'revenue' AND EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.inputs->'obligations') o WHERE configuration.roles->>('revenue.' || (o->>'reference')) IS NULL) THEN RAISE EXCEPTION 'Every obligation requires a configured revenue account'; END IF;
        IF EXISTS (SELECT 1 FROM ledger_finance_contracts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND dimensions = NEW.dimensions AND (carrying_account_id = ANY(protected) OR allowance_account_id = ANY(protected) OR asset_account_id = ANY(protected) OR liability_account_id = ANY(protected) OR refund_account_id = ANY(protected)))
            OR EXISTS (SELECT 1 FROM ledger_inventory_pools WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND account_id = ANY(protected))
            OR EXISTS (SELECT 1 FROM ledger_assets WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND (cost_account_id = ANY(protected) OR depreciation_account_id = ANY(protected) OR impairment_account_id = ANY(protected)))
            OR EXISTS (SELECT 1 FROM ledger_associates WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND (investment_account_id = ANY(protected) OR loss_liability_account_id = ANY(protected)))
            OR EXISTS (SELECT 1 FROM ledger_monetary_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND account_id = ANY(protected))
            OR EXISTS (SELECT 1 FROM ledger_allowance_runs WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND allowance_account_id = ANY(protected)) THEN RAISE EXCEPTION 'Finance accounts cannot overlap another protected register'; END IF;
        IF EXISTS (SELECT 1 FROM ledger_postings p WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.account_id = ANY(protected) AND p.journal_id IS DISTINCT FROM NEW.journal_id
            AND NOT EXISTS (SELECT 1 FROM ledger_finance_contracts c WHERE c.tenant_id = p.tenant_id AND c.book_id = p.book_id AND c.dimensions = p.dimensions
                AND p.account_id IN (c.carrying_account_id, c.allowance_account_id, c.asset_account_id, c.liability_account_id, c.refund_account_id))) THEN RAISE EXCEPTION 'Reconcile unregistered finance account history before registration'; END IF;
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
                OR NEW.inputs IS DISTINCT FROM original.inputs OR NEW.calculation IS DISTINCT FROM original.calculation OR (NEW.carrying_delta, NEW.allowance_delta, NEW.written_off_delta, NEW.recovered_delta, NEW.stage, NEW.prior_stage)
                    IS DISTINCT FROM (-original.carrying_delta, -original.allowance_delta, -original.written_off_delta, -original.recovered_delta, original.prior_stage, original.stage)
                OR (NEW.journal_id IS NULL) <> (original.journal_id IS NULL) THEN RAISE EXCEPTION 'Reverse finance events in dependency order with exact original effects'; END IF;
            IF NEW.journal_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id AND reverses_id = original.journal_id AND effective_on = NEW.effective_on) THEN RAISE EXCEPTION 'Finance corrections require an exact linked journal reversal'; END IF;
        ELSE
            expected := ledger_finance_quote(NEW.tenant_id, NEW.book_id, NEW.contract_id, NEW.effective_on, NEW.kind, NEW.inputs, NEW.journal_id);
            IF (NEW.carrying_delta, NEW.allowance_delta, NEW.written_off_delta, NEW.recovered_delta, NEW.stage, NEW.prior_stage)
                IS DISTINCT FROM ((expected->>'carrying_delta')::numeric, (expected->>'allowance_delta')::numeric, (expected->>'written_off_delta')::numeric, (expected->>'recovered_delta')::numeric, (expected->>'stage')::integer, (expected->>'prior_stage')::integer) THEN RAISE EXCEPTION 'Finance event differs from its independently measured effects'; END IF;
            rows := expected->'postings'; cash_flow := expected->'cash_flow';
            IF NEW.calculation IS DISTINCT FROM nullif(expected->'calculation', 'null'::jsonb) THEN RAISE EXCEPTION 'Finance calculation evidence differs from independently measured inputs'; END IF;
            IF NEW.kind = 'cancellation' THEN
                correcting := true;
                IF (definition.journal_id IS NULL) IS DISTINCT FROM (NEW.journal_id IS NULL) OR (definition.journal_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id AND reverses_id = definition.journal_id AND effective_on = NEW.effective_on)) THEN RAISE EXCEPTION 'Finance cancellation requires its original funding reversal'; END IF;
            END IF;
        END IF;
    END IF;
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id, correcting);
    PERFORM ledger_require_live_dimensions(NEW.tenant_id, NEW.book_id, dimensions_arg, correcting);
    IF correcting THEN RETURN NEW; END IF;
    IF TG_TABLE_NAME = 'ledger_finance_events' THEN
        SELECT * INTO STRICT configuration FROM ledger_configurations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = definition.configuration_id;
        IF definition.kind = 'contract_revenue' AND NOT EXISTS (SELECT 1 FROM ledger_parties WHERE tenant_id = NEW.tenant_id AND id = (definition.inputs->>'party_id')::uuid AND deleted_at IS NULL) THEN
            RAISE EXCEPTION 'Restore the revenue customer before recording new events'; END IF;
    END IF;
    FOR value IN SELECT * FROM jsonb_each_text(configuration.roles) LOOP
        role_name := value.key;
        IF role_name NOT IN ('cash', 'loan', 'interest', 'ecl.allowance', 'ecl.expense', 'lease.liability', 'lease.asset', 'lease.accumulated', 'contract.liability', 'contract.asset', 'contract.refund', 'contract.allowance', 'contract.impairment', 'receivable') AND role_name NOT LIKE 'revenue.%' THEN CONTINUE; END IF;
        IF role_name = 'loan' AND kind_arg NOT IN ('loan_asset', 'loan_liability') OR role_name = 'interest' AND kind_arg IN ('revenue', 'contract_revenue')
            OR role_name IN ('ecl.allowance', 'ecl.expense') AND kind_arg <> 'loan_asset' OR role_name IN ('lease.asset', 'lease.liability', 'lease.accumulated') AND kind_arg <> 'lease'
            OR (role_name = 'contract.liability' OR role_name LIKE 'revenue.%') AND kind_arg NOT IN ('revenue', 'contract_revenue')
            OR role_name IN ('contract.asset', 'contract.refund', 'contract.allowance', 'contract.impairment', 'receivable') AND kind_arg <> 'contract_revenue' THEN CONTINUE; END IF;
        account_arg := value.value::uuid;
        IF EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = account_arg AND (NOT active OR deleted_at IS NOT NULL)) THEN RAISE EXCEPTION 'Restore finance accounts before recording new events'; END IF;
        IF EXISTS (SELECT 1 FROM ledger_account_dimensions WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND account_id = account_arg AND NOT (dimensions_arg ? dimension)) THEN RAISE EXCEPTION 'A mandatory finance accounting dimension is missing'; END IF;
    END LOOP;
    IF (jsonb_array_length(rows) = 0) <> (NEW.journal_id IS NULL) THEN RAISE EXCEPTION 'Finance journal does not match its financial effect'; END IF;
    IF NEW.journal_id IS NOT NULL THEN
        SELECT jsonb_agg((jsonb_build_object('account_id', account_id::text, 'side', side, 'amount_minor', amount_minor::text)
            || CASE WHEN party_id IS NOT NULL THEN jsonb_build_object('party_id', party_id::text, 'due_on', due_on) ELSE '{}'::jsonb END) ORDER BY line_no) INTO actual FROM ledger_postings
            WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id;
        IF actual IS DISTINCT FROM rows OR NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id AND effective_on = NEW.effective_on AND NOT closing AND reverses_id IS NULL AND source_type = 'finance.register' AND source_reference = NEW.id::text)
            OR EXISTS (SELECT 1 FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id AND (valuation OR kind_arg <> 'contract_revenue' AND party_id IS NOT NULL OR currency_code <> functional_code OR dimensions IS DISTINCT FROM dimensions_arg)) THEN RAISE EXCEPTION 'Finance journal must match its exact functional effects'; END IF;
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
    IF EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_finance_contracts c ON c.tenant_id = p.tenant_id AND c.book_id = p.book_id AND p.account_id IN (c.carrying_account_id, c.allowance_account_id, c.asset_account_id, c.liability_account_id, c.refund_account_id)
        WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.id)
        AND NOT EXISTS (SELECT 1 FROM ledger_finance_contracts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id)
        AND NOT EXISTS (SELECT 1 FROM ledger_finance_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id) THEN RAISE EXCEPTION 'Registered finance balances require linked contract evidence'; END IF;
    IF NEW.reverses_id IS NOT NULL AND (EXISTS (SELECT 1 FROM ledger_finance_contracts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.reverses_id)
        OR EXISTS (SELECT 1 FROM ledger_finance_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.reverses_id))
        AND NOT EXISTS (SELECT 1 FROM ledger_finance_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id) THEN RAISE EXCEPTION 'Finance journal reversals require exact event evidence'; END IF;
    IF NEW.source_type = 'finance.register'
        AND NOT EXISTS (SELECT 1 FROM ledger_finance_contracts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id AND id::text = NEW.source_reference)
        AND NOT EXISTS (SELECT 1 FROM ledger_finance_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id AND id::text = NEW.source_reference) THEN RAISE EXCEPTION 'Finance source requires its matching registered evidence'; END IF;
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
