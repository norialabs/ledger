CREATE TABLE ledger_tax_decisions (
    id uuid NOT NULL DEFAULT uuidv7(), tenant_id uuid NOT NULL, book_id uuid NOT NULL,
    idempotency_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL, scheme varchar(128) NOT NULL, configuration_id uuid NOT NULL,
    effective_on date NOT NULL, journal_id uuid NOT NULL, line_no integer NOT NULL CHECK (line_no > 0), tax_code varchar(64) NOT NULL,
    kind varchar(16) NOT NULL CHECK (kind IN ('claim', 'defer', 'deny', 'reconcile')),
    fraction numeric NOT NULL CHECK (fraction >= 0 AND fraction <= 1 AND fraction = trunc(fraction, 18)),
    classification varchar(64), reason varchar(1024) NOT NULL CHECK (length(btrim(reason)) > 0), evidence jsonb NOT NULL CHECK (jsonb_typeof(evidence) = 'object'),
    recorded_at timestamptz NOT NULL, sequence bigint NOT NULL, actor_id varchar(128) NOT NULL, firm_id varchar(128),
    PRIMARY KEY (id), UNIQUE (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, idempotency_key),
    FOREIGN KEY (tenant_id, book_id, journal_id) REFERENCES ledger_journals (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, configuration_id) REFERENCES ledger_configurations (tenant_id, book_id, id),
    CHECK ((kind = 'reconcile') = (classification IS NOT NULL)), CHECK (kind = 'claim' OR fraction = 0)
);
CREATE INDEX ledger_tax_decision_history ON ledger_tax_decisions (tenant_id, book_id, scheme, journal_id, line_no, tax_code, sequence);

CREATE TABLE ledger_tax_returns (
    id uuid NOT NULL DEFAULT uuidv7(), tenant_id uuid NOT NULL, book_id uuid NOT NULL,
    idempotency_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL, configuration_id uuid NOT NULL, scheme varchar(128) NOT NULL,
    starts_on date NOT NULL, effective_on date NOT NULL CHECK (effective_on >= starts_on), through_sequence bigint NOT NULL CHECK (through_sequence >= 0),
    previous_id uuid, prior_period_id uuid, opening_credit_minor numeric NOT NULL CHECK (opening_credit_minor >= 0 AND opening_credit_minor = trunc(opening_credit_minor) AND opening_credit_minor < power(10::numeric, 38)),
    opening_reference varchar(256), result_json jsonb NOT NULL CHECK (jsonb_typeof(result_json) = 'object'), result_hash char(64) NOT NULL,
    recorded_at timestamptz NOT NULL, sequence bigint NOT NULL, actor_id varchar(128) NOT NULL, firm_id varchar(128),
    PRIMARY KEY (id), UNIQUE (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, idempotency_key), UNIQUE (tenant_id, book_id, previous_id),
    FOREIGN KEY (tenant_id, book_id, configuration_id) REFERENCES ledger_configurations (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, previous_id) REFERENCES ledger_tax_returns (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, prior_period_id) REFERENCES ledger_tax_returns (tenant_id, book_id, id)
);
CREATE UNIQUE INDEX ledger_tax_original_period ON ledger_tax_returns (tenant_id, book_id, scheme, starts_on, effective_on) WHERE previous_id IS NULL;

CREATE TABLE ledger_tax_return_events (
    id uuid NOT NULL DEFAULT uuidv7(), tenant_id uuid NOT NULL, book_id uuid NOT NULL, return_id uuid NOT NULL,
    idempotency_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL, effective_on date NOT NULL,
    kind varchar(16) NOT NULL CHECK (kind IN ('finalize', 'file', 'void')),
    reason varchar(1024) NOT NULL CHECK (length(btrim(reason)) > 0), evidence jsonb NOT NULL CHECK (jsonb_typeof(evidence) = 'object'),
    recorded_at timestamptz NOT NULL, sequence bigint NOT NULL, actor_id varchar(128) NOT NULL, firm_id varchar(128),
    PRIMARY KEY (id), UNIQUE (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, idempotency_key), UNIQUE (tenant_id, book_id, return_id, kind),
    FOREIGN KEY (tenant_id, book_id, return_id) REFERENCES ledger_tax_returns (tenant_id, book_id, id)
);
CREATE UNIQUE INDEX ledger_tax_filing_receipt ON ledger_tax_return_events (tenant_id, book_id, (evidence->>'receipt_reference')) WHERE kind = 'file';

CREATE OR REPLACE FUNCTION ledger_tax_minor(value jsonb, target_scale integer, rate numeric DEFAULT 1, mode text DEFAULT 'half_even')
RETURNS numeric LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE source_scale integer; result numeric;
BEGIN
    IF jsonb_typeof(value) IS DISTINCT FROM 'object' OR value->>'scale' IS NULL OR value->>'scale' !~ '^[0-9]{1,2}$' OR (value->>'scale')::integer NOT BETWEEN 0 AND 12
        OR rate IS NULL OR rate <= 0 OR rate >= power(10::numeric, 20) THEN RAISE EXCEPTION 'Invalid tax money evidence'; END IF;
    source_scale := (value->>'scale')::integer;
    result := ledger_fraction(ledger_finance_amount(value->>'amount', source_scale) * rate * power(10::numeric, target_scale), power(10::numeric, source_scale), mode);
    IF result >= power(10::numeric, 38) THEN RAISE EXCEPTION 'Converted tax amount exceeds monetary storage bounds'; END IF;
    RETURN result;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_tax_bases(line jsonb, source_rounding text, target_scale integer, rate numeric, mode text)
RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $body$
DECLARE rule jsonb; field text; numerator numeric; included numeric := 1; coefficients numeric := 0; factor numeric; coefficient numeric; source_scale integer;
    factors jsonb := '{}'; rates jsonb := '{}'; result jsonb := '{}'; item record; base numeric; expected numeric;
BEGIN
    IF jsonb_typeof(line->'rules') IS DISTINCT FROM 'array' OR source_rounding IS NULL OR source_rounding NOT IN ('half_even', 'half_up', 'down') THEN RAISE EXCEPTION 'Invalid tax calculation evidence'; END IF;
    FOREACH field IN ARRAY ARRAY['price', 'quantity', 'discount_fraction'] LOOP
        IF line->'input'->>field IS NULL OR length(line->'input'->>field) > 128 OR line->'input'->>field !~ '^[0-9]+(\.[0-9]+)?$' THEN RAISE EXCEPTION 'Invalid exact taxable-base inputs'; END IF;
    END LOOP;
    IF (line->'input'->>'discount_fraction')::numeric > 1 THEN RAISE EXCEPTION 'Invalid tax discount fraction'; END IF;
    source_scale := (line->'result'->'net'->>'scale')::integer;
    numerator := (line->'input'->>'price')::numeric * (line->'input'->>'quantity')::numeric * (1 - (line->'input'->>'discount_fraction')::numeric);
    FOR rule IN SELECT * FROM jsonb_array_elements(line->'rules') LOOP
        IF rule->>'code' IS NULL OR factors ? (rule->>'code') OR rule->>'rate' IS NULL OR length(rule->>'rate') > 128 OR rule->>'rate' !~ '^[0-9]+(\.[0-9]+)?$' THEN RAISE EXCEPTION 'Invalid ordered tax rule evidence'; END IF;
        factor := CASE WHEN (rule->>'compound')::boolean THEN 1 + coefficients ELSE 1 END;
        coefficient := factor * (rule->>'rate')::numeric;
        factors := factors || jsonb_build_object(rule->>'code', factor::text);
        rates := rates || jsonb_build_object(rule->>'code', (rule->>'rate'));
        coefficients := coefficients + coefficient;
        IF (rule->>'inclusive')::boolean THEN included := included + coefficient; END IF;
    END LOOP;
    IF ledger_finance_amount(line->'result'->'net'->>'amount', source_scale) <> ledger_fraction(numerator * power(10::numeric, source_scale), included, source_rounding) THEN RAISE EXCEPTION 'Tax base does not match exact source inputs'; END IF;
    FOR item IN SELECT * FROM jsonb_each_text(factors) LOOP
        factor := item.value::numeric;
        expected := ledger_fraction(numerator * factor * (rates->>item.key)::numeric * power(10::numeric, source_scale), included, source_rounding);
        IF ledger_finance_amount(line->'result'->'taxes'->item.key->>'amount', source_scale) <> expected THEN RAISE EXCEPTION 'Tax component does not match exact source inputs'; END IF;
        base := ledger_fraction(numerator * factor * power(10::numeric, source_scale), included, source_rounding);
        base := ledger_fraction(base * rate * power(10::numeric, target_scale), power(10::numeric, source_scale), mode);
        IF base >= power(10::numeric, 38) THEN RAISE EXCEPTION 'Taxable base exceeds monetary storage bounds'; END IF;
        result := result || jsonb_build_object(item.key, base::text);
    END LOOP;
    RETURN result;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_tax_register(tenant uuid, book uuid, as_of date, through_sequence bigint)
RETURNS TABLE (journal_id uuid, origin_id uuid, effective_on date, sequence bigint, line_no integer, tax_code text, kind text,
    configuration_id uuid, account_id uuid, base_minor numeric, tax_minor numeric, ledger_minor numeric, claimable boolean, requires_payment boolean, is_reversal boolean,
    source_type text, source_reference text, party_ids jsonb)
LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE item record; source jsonb; request_arg jsonb; line jsonb; tax record; roles jsonb; config jsonb; category text; role_name text;
    document_kind text; currency_scale integer; functional_code text; direction integer; rate numeric; mode text; ordinal integer; bases jsonb; fraction numeric;
BEGIN
    SELECT b.currency_code, c.scale INTO STRICT functional_code, currency_scale FROM ledger_books b JOIN ledger_currencies c ON c.tenant_id = b.tenant_id AND c.code = b.currency_code WHERE b.tenant_id = tenant AND b.id = book;
    FOR item IN SELECT j.id, j.effective_on, j.sequence, j.reverses_id, coalesce(s.id, j.id) AS origin_id, coalesce(s.sequence, j.sequence) AS origin_sequence, coalesce(s.metadata, j.metadata) AS source,
        coalesce(s.source_type, j.source_type) AS source_type, coalesce(s.source_reference, j.source_reference) AS source_reference, coalesce(s.effective_on, j.effective_on) AS origin_effective_on
        FROM ledger_journals j LEFT JOIN ledger_journals s ON s.tenant_id = j.tenant_id AND s.book_id = j.book_id AND s.id = j.reverses_id
        WHERE j.tenant_id = tenant AND j.book_id = book AND j.effective_on <= as_of AND j.sequence <= through_sequence
            AND (coalesce(s.metadata, j.metadata) ? 'tax_document' OR coalesce(s.metadata, j.metadata) ? 'tax_treatment')
        ORDER BY j.sequence LOOP
        journal_id := item.id; origin_id := item.origin_id; effective_on := item.effective_on; sequence := item.sequence;
        source_type := item.source_type; source_reference := item.source_reference;
        is_reversal := item.reverses_id IS NOT NULL; direction := CASE WHEN is_reversal THEN -1 ELSE 1 END;
        IF item.source ? 'tax_document' THEN
            source := item.source->'tax_document'; request_arg := source->'request';
            SELECT coalesce(jsonb_agg(DISTINCT o->>'party_id' ORDER BY o->>'party_id'), '[]'::jsonb) INTO party_ids FROM jsonb_array_elements(request_arg->'obligations') o WHERE o->>'party_id' IS NOT NULL;
            configuration_id := (request_arg->>'configuration_id')::uuid;
            SELECT c.roles, c.definition_json INTO STRICT roles, config FROM ledger_configurations c WHERE c.tenant_id = tenant AND c.book_id = book AND c.id = configuration_id AND c.sequence <= item.origin_sequence;
            document_kind := request_arg->>'kind';
            IF document_kind NOT IN ('sales_invoice', 'sales_credit', 'purchase_bill', 'purchase_credit') OR jsonb_typeof(source->'lines') IS DISTINCT FROM 'array' THEN RAISE EXCEPTION 'Malformed taxed-document evidence'; END IF;
            IF request_arg->>'book_id' IS DISTINCT FROM book::text OR (request_arg->>'effective_on')::date IS DISTINCT FROM item.origin_effective_on THEN RAISE EXCEPTION 'Tax document evidence must preserve its journal identity'; END IF;
            IF document_kind IN ('sales_credit', 'purchase_credit') THEN direction := -direction; END IF;
            category := CASE WHEN document_kind IN ('sales_invoice', 'sales_credit') THEN 'output' ELSE 'input' END;
            rate := coalesce((request_arg->'exchange_rate'->>'rate')::numeric, 1);
            mode := coalesce(request_arg->'exchange_rate'->>'rounding', 'half_even');
            ordinal := 0;
            FOR line IN SELECT * FROM jsonb_array_elements(source->'lines') LOOP
                ordinal := ordinal + 1; line_no := ordinal; kind := category;
                IF line->'result'->'net'->>'currency' IS DISTINCT FROM coalesce(request_arg->'exchange_rate'->'from'->>'code', functional_code)
                    OR coalesce(request_arg->'exchange_rate'->'to'->>'code', functional_code) <> functional_code THEN RAISE EXCEPTION 'Tax calculation currencies do not match the original rate evidence'; END IF;
                base_minor := direction * ledger_tax_minor(line->'result'->'net', currency_scale, rate, mode);
                requires_payment := false;
                IF line->'result'->'taxes' IN ('{}'::jsonb, '[]'::jsonb) THEN
                    tax_code := ''; account_id := NULL; tax_minor := 0; ledger_minor := 0; claimable := false; RETURN NEXT;
                    CONTINUE;
                END IF;
                IF jsonb_typeof(line->'result'->'taxes') IS DISTINCT FROM 'object' THEN RAISE EXCEPTION 'Malformed document tax components'; END IF;
                IF EXISTS (SELECT 1 FROM jsonb_array_elements(line->'rules') r WHERE NOT (config->'tax_rules') @> jsonb_build_array(r)
                    OR (r->>'valid_from')::date > item.origin_effective_on OR (r->>'valid_until')::date < item.origin_effective_on) THEN RAISE EXCEPTION 'Tax calculation does not match its pinned effective rules'; END IF;
                bases := ledger_tax_bases(line, request_arg->>'rounding', currency_scale, rate, mode);
                FOR tax IN SELECT * FROM jsonb_each(line->'result'->'taxes') LOOP
                    IF (tax.value->>'currency', tax.value->>'scale') IS DISTINCT FROM (line->'result'->'net'->>'currency', line->'result'->'net'->>'scale') THEN RAISE EXCEPTION 'Tax components must retain their source currency and scale'; END IF;
                    tax_code := tax.key; role_name := request_arg->'tax_roles'->>tax.key; account_id := (roles->>role_name)::uuid;
                    IF account_id IS NULL OR NOT bases ? tax.key THEN RAISE EXCEPTION 'Tax evidence requires a configured tax account and calculation basis'; END IF;
                    base_minor := direction * (bases->>tax.key)::numeric;
                    tax_minor := direction * ledger_tax_minor(tax.value, currency_scale, rate, mode);
                    ledger_minor := CASE WHEN kind = 'output' THEN -tax_minor ELSE tax_minor END;
                    SELECT a.type = 'asset' INTO STRICT claimable FROM ledger_accounts a WHERE a.tenant_id = tenant AND a.book_id = book AND a.id = account_id;
                    claimable := kind = 'input' AND claimable;
                    RETURN NEXT;
                END LOOP;
            END LOOP;
        ELSE
            source := item.source->'tax_treatment'; request_arg := item.source->'treatment'->'request';
            party_ids := CASE WHEN request_arg->'inputs'->>'party_id' IS NULL THEN '[]'::jsonb ELSE jsonb_build_array(request_arg->'inputs'->>'party_id') END;
            configuration_id := (request_arg->>'configuration_id')::uuid;
            SELECT c.roles, c.definition_json INTO STRICT roles, config FROM ledger_configurations c WHERE c.tenant_id = tenant AND c.book_id = book AND c.id = configuration_id AND c.sequence <= item.origin_sequence;
            IF NOT (config->'tax_rules') @> jsonb_build_array(source->'rule') OR (source->'rule'->>'valid_from')::date > item.origin_effective_on
                OR (source->'rule'->>'valid_until')::date < item.origin_effective_on OR (source->'rule'->>'inclusive')::boolean OR (source->'rule'->>'compound')::boolean THEN RAISE EXCEPTION 'Tax treatment does not match its pinned effective rule'; END IF;
            tax_code := source->'rule'->>'code'; line_no := 1; base_minor := direction * ledger_tax_minor(source->'base', currency_scale);
            tax_minor := direction * ledger_tax_minor(source->'tax', currency_scale); requires_payment := false; claimable := false;
            IF source->'base'->>'currency' IS DISTINCT FROM functional_code OR source->'tax'->>'currency' IS DISTINCT FROM functional_code
                OR abs(tax_minor) <> ledger_fraction(abs(base_minor) * (source->'rule'->>'rate')::numeric, 1, request_arg->'inputs'->>'rounding') THEN RAISE EXCEPTION 'Tax treatment amounts do not match the pinned calculation'; END IF;
            IF source->>'operation' = 'withholding.payment' THEN
                kind := 'withholding_payable'; account_id := (roles->>'tax.withholding.payable')::uuid; ledger_minor := -tax_minor; RETURN NEXT;
            ELSIF source->>'operation' = 'withholding.receipt' THEN
                kind := 'withholding_credit'; account_id := (roles->>'tax.withholding.receivable')::uuid; ledger_minor := tax_minor; claimable := true; RETURN NEXT;
            ELSIF source->>'operation' = 'reverse_charge' THEN
                fraction := (request_arg->'inputs'->>'recoverable_fraction')::numeric;
                IF fraction IS NULL OR fraction < 0 OR fraction > 1 OR ledger_tax_minor(source->'recoverable', currency_scale) <> ledger_fraction(abs(tax_minor) * fraction, 1, request_arg->'inputs'->>'rounding')
                    OR ledger_tax_minor(source->'recoverable', currency_scale) + ledger_tax_minor(source->'irrecoverable', currency_scale) <> abs(tax_minor) THEN RAISE EXCEPTION 'Reverse-charge recoverability must conserve the assessed tax'; END IF;
                kind := 'reverse_charge'; account_id := (roles->>'tax.reverse_charge.payable')::uuid; ledger_minor := -tax_minor; RETURN NEXT;
                line_no := 2; kind := 'input'; account_id := coalesce(roles->>'tax.reverse_charge.input', roles->>'tax.input')::uuid;
                tax_minor := direction * ledger_tax_minor(source->'recoverable', currency_scale); ledger_minor := tax_minor; claimable := true;
                requires_payment := coalesce((config->'settings'->>'import_input_requires_payment_and_claim_review')::boolean, false); RETURN NEXT;
            ELSE RAISE EXCEPTION 'Unknown tax treatment evidence'; END IF;
        END IF;
    END LOOP;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_tax_policy(tenant uuid, book uuid, configuration uuid, scheme_arg text) RETURNS jsonb LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE definition ledger_configurations%ROWTYPE; policy jsonb; role_name text; account uuid; result jsonb := '{}'; term record; purpose record;
BEGIN
    SELECT * INTO STRICT definition FROM ledger_configurations WHERE tenant_id = tenant AND book_id = book AND id = configuration;
    policy := definition.definition_json->'settings'->'tax_returns'->scheme_arg;
    IF jsonb_typeof(policy) IS DISTINCT FROM 'object' OR jsonb_typeof(policy->'accounts') IS DISTINCT FROM 'array' OR jsonb_array_length(policy->'accounts') = 0
        OR jsonb_typeof(policy->'codes') IS DISTINCT FROM 'object' OR jsonb_typeof(policy->'payable_terms') IS DISTINCT FROM 'object'
        OR policy->'payable_terms' = '{}'::jsonb OR jsonb_typeof(policy->'reconciliations') IS DISTINCT FROM 'object' THEN RAISE EXCEPTION 'Tax returns require a complete pinned scheme policy'; END IF;
    IF policy ? 'period' AND (jsonb_typeof(policy->'period') IS DISTINCT FROM 'string' OR policy->>'period' NOT IN ('calendar_month', 'explicit')) THEN RAISE EXCEPTION 'Invalid tax return period policy'; END IF;
    FOR role_name IN SELECT jsonb_array_elements_text(policy->'accounts') LOOP
        account := (definition.roles->>role_name)::uuid;
        IF account IS NULL OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = account AND NOT is_group AND NOT is_control AND type IN ('asset', 'liability')) THEN RAISE EXCEPTION 'Tax scope requires configured non-control balance-sheet accounts'; END IF;
        IF result ? account::text THEN RAISE EXCEPTION 'Tax return scope accounts must be distinct'; END IF;
        result := result || jsonb_build_object(account::text, role_name);
    END LOOP;
    FOR term IN SELECT * FROM jsonb_each_text(policy->'payable_terms') LOOP
        IF term.key NOT IN ('output_tax', 'input_claimed', 'withholding_claimed', 'reverse_charge_tax', 'withholding_payable') OR term.value !~ '^-?[01]$' THEN RAISE EXCEPTION 'Invalid tax return formula'; END IF;
    END LOOP;
    FOR purpose IN SELECT * FROM jsonb_each(policy->'reconciliations') LOOP
        IF jsonb_typeof(purpose.value->'roles') IS DISTINCT FROM 'array' OR purpose.value->>'coefficient' IS NULL OR purpose.value->>'coefficient' !~ '^-?[01]$' THEN RAISE EXCEPTION 'Invalid tax reconciliation policy'; END IF;
        FOR role_name IN SELECT jsonb_array_elements_text(purpose.value->'roles') LOOP
            IF definition.roles->>role_name IS NULL OR NOT result ? (definition.roles->>role_name) THEN RAISE EXCEPTION 'Reconciliation roles must belong to tax return scope'; END IF;
        END LOOP;
    END LOOP;
    RETURN policy || jsonb_build_object('account_roles', result, 'configuration_hash', definition.definition_hash);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_tax_payment_valid(tenant uuid, book uuid, entry_journal uuid, payment uuid, date_arg date, through_sequence bigint)
RETURNS boolean LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE entry record;
BEGIN
    SELECT * INTO entry FROM ledger_tax_register(tenant, book, date_arg, through_sequence) r WHERE r.journal_id = entry_journal AND r.kind = 'reverse_charge' AND NOT r.is_reversal;
    IF NOT FOUND OR payment IS NULL THEN RETURN false; END IF;
    IF NOT EXISTS (SELECT 1 FROM ledger_journals j WHERE j.tenant_id = tenant AND j.book_id = book AND j.id = payment AND j.effective_on <= date_arg AND j.sequence <= through_sequence AND j.reverses_id IS NULL)
        OR EXISTS (SELECT 1 FROM ledger_journals r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = payment AND r.effective_on <= date_arg AND r.sequence <= through_sequence) THEN RETURN false; END IF;
    RETURN (SELECT count(*) = 2 AND count(*) FILTER (WHERE p.account_id = entry.account_id AND p.side = 'debit' AND p.functional_minor = entry.tax_minor AND NOT p.valuation) = 1
        AND count(*) FILTER (WHERE a.cash_flow = 'cash' AND p.side = 'credit' AND p.functional_minor = entry.tax_minor AND NOT p.valuation) = 1
        FROM ledger_postings p JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
        WHERE p.tenant_id = tenant AND p.book_id = book AND p.journal_id = payment);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_tax_return_result(tenant uuid, book uuid, configuration uuid, scheme_arg text, starts date, ends date, cutoff bigint, opening numeric)
RETURNS jsonb LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE policy jsonb; entry record; decision record; movement record; balance record; term record; items jsonb := '[]'; claims jsonb := '[]'; reconciliations jsonb := '[]';
    unresolved jsonb := '[]'; unmapped jsonb := '[]'; invalid_claims jsonb := '[]'; unexplained jsonb := '[]'; explained jsonb := '[]'; bases jsonb := '{}'; totals jsonb := '{"output_tax":"0","input_claimed":"0","withholding_claimed":"0","reverse_charge_tax":"0","withholding_payable":"0"}';
    key_arg text; amount numeric; expected numeric; actual numeric; net numeric := -opening; adjustment numeric := 0; account_arg text;
BEGIN
    policy := ledger_tax_policy(tenant, book, configuration, scheme_arg);
    FOR entry IN SELECT * FROM ledger_tax_register(tenant, book, ends, cutoff) r WHERE r.effective_on >= starts
        AND (policy->'account_roles' ? r.account_id::text OR r.account_id IS NULL) ORDER BY r.sequence, r.line_no, r.tax_code LOOP
        items := items || jsonb_build_array(jsonb_build_object('journal_id', entry.journal_id, 'origin_id', entry.origin_id, 'effective_on', entry.effective_on, 'sequence', entry.sequence::text,
            'line_no', entry.line_no, 'tax_code', entry.tax_code, 'kind', entry.kind, 'configuration_id', entry.configuration_id, 'account_id', entry.account_id,
            'source_type', entry.source_type, 'source_reference', entry.source_reference, 'party_ids', entry.party_ids,
            'base_minor', entry.base_minor::text, 'tax_minor', entry.tax_minor::text, 'ledger_minor', entry.ledger_minor::text, 'claimable', entry.claimable,
            'requires_payment', entry.requires_payment, 'is_reversal', entry.is_reversal));
        IF NOT (policy->'codes') ? entry.tax_code THEN unmapped := unmapped || jsonb_build_array(jsonb_build_object('journal_id', entry.journal_id, 'line_no', entry.line_no, 'tax_code', entry.tax_code)); END IF;
        key_arg := entry.kind || ':' || entry.tax_code;
        bases := bases || jsonb_build_object(key_arg, (coalesce((bases->>key_arg)::numeric, 0) + entry.base_minor)::text);
        key_arg := CASE entry.kind WHEN 'output' THEN 'output_tax' WHEN 'reverse_charge' THEN 'reverse_charge_tax' WHEN 'withholding_payable' THEN 'withholding_payable' ELSE NULL END;
        IF key_arg IS NOT NULL THEN totals := jsonb_set(totals, ARRAY[key_arg], to_jsonb(((totals->>key_arg)::numeric + entry.tax_minor)::text)); END IF;
        IF entry.claimable AND NOT entry.is_reversal AND entry.tax_minor <> 0 AND NOT EXISTS (SELECT 1 FROM ledger_tax_decisions d WHERE d.tenant_id = tenant AND d.book_id = book AND d.scheme = scheme_arg
            AND d.journal_id = entry.journal_id AND d.line_no = entry.line_no AND d.tax_code = entry.tax_code AND d.kind <> 'reconcile' AND d.effective_on <= ends AND d.sequence <= cutoff)
            AND NOT EXISTS (SELECT 1 FROM ledger_journals r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = entry.journal_id AND r.effective_on <= ends AND r.sequence <= cutoff) THEN
            unresolved := unresolved || jsonb_build_array(jsonb_build_object('journal_id', entry.journal_id, 'line_no', entry.line_no, 'tax_code', entry.tax_code));
        END IF;
    END LOOP;
    FOR movement IN WITH entries AS MATERIALIZED (SELECT * FROM ledger_tax_register(tenant, book, ends, cutoff)), decisions AS (
        SELECT d.*, lag(d.fraction, 1, 0::numeric) OVER (PARTITION BY d.journal_id, d.line_no, d.tax_code ORDER BY d.sequence) AS prior_fraction
            FROM ledger_tax_decisions d WHERE d.tenant_id = tenant AND d.book_id = book AND d.scheme = scheme_arg AND d.kind <> 'reconcile' AND d.effective_on <= ends AND d.sequence <= cutoff
    ) SELECT d.id, d.effective_on, d.sequence, e.journal_id, e.line_no, e.tax_code, e.kind,
        ledger_fraction(e.tax_minor * d.fraction, 1, 'half_even') - ledger_fraction(e.tax_minor * d.prior_fraction, 1, 'half_even') AS amount
        FROM decisions d JOIN entries e ON e.journal_id = d.journal_id AND e.line_no = d.line_no AND e.tax_code = d.tax_code AND NOT e.is_reversal
        WHERE d.effective_on >= starts AND policy->'account_roles' ? e.account_id::text
    UNION ALL SELECT NULL::uuid, e.effective_on, e.sequence, e.journal_id, e.line_no, e.tax_code, e.kind,
        ledger_fraction(e.tax_minor * coalesce((SELECT d.fraction FROM ledger_tax_decisions d WHERE d.tenant_id = tenant AND d.book_id = book AND d.scheme = scheme_arg
            AND d.journal_id = e.origin_id AND d.line_no = e.line_no AND d.tax_code = e.tax_code AND d.kind <> 'reconcile' AND d.effective_on <= e.effective_on AND d.sequence <= cutoff ORDER BY d.sequence DESC LIMIT 1), 0), 1, 'half_even')
        FROM entries e WHERE e.is_reversal AND e.claimable AND e.effective_on >= starts AND policy->'account_roles' ? e.account_id::text
        ORDER BY sequence, line_no, tax_code LOOP
        IF NOT (policy->'codes') ? movement.tax_code THEN
            unmapped := unmapped || jsonb_build_array(jsonb_build_object('decision_id', movement.id, 'tax_code', movement.tax_code));
        END IF;
        key_arg := CASE WHEN movement.kind = 'withholding_credit' THEN 'withholding_claimed' ELSE 'input_claimed' END;
        totals := jsonb_set(totals, ARRAY[key_arg], to_jsonb(((totals->>key_arg)::numeric + movement.amount)::text));
        claims := claims || jsonb_build_array(jsonb_build_object('decision_id', movement.id, 'journal_id', movement.journal_id, 'line_no', movement.line_no, 'tax_code', movement.tax_code,
            'effective_on', movement.effective_on, 'amount_minor', movement.amount::text));
    END LOOP;
    FOR decision IN SELECT DISTINCT ON (d.journal_id, d.line_no, d.tax_code) d.* FROM ledger_tax_decisions d WHERE d.tenant_id = tenant AND d.book_id = book AND d.scheme = scheme_arg
        AND d.kind <> 'reconcile' AND d.effective_on <= ends AND d.sequence <= cutoff ORDER BY d.journal_id, d.line_no, d.tax_code, d.sequence DESC LOOP
        IF decision.fraction > 0 AND EXISTS (SELECT 1 FROM ledger_tax_register(tenant, book, ends, cutoff) e WHERE e.journal_id = decision.journal_id AND e.line_no = decision.line_no AND e.tax_code = decision.tax_code AND e.requires_payment)
            AND NOT EXISTS (SELECT 1 FROM ledger_journals r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = decision.journal_id AND r.effective_on <= ends AND r.sequence <= cutoff)
            AND NOT ledger_tax_payment_valid(tenant, book, decision.journal_id, (decision.evidence->>'payment_journal_id')::uuid, ends, cutoff) THEN
            invalid_claims := invalid_claims || jsonb_build_array(decision.id);
        END IF;
    END LOOP;
    FOR account_arg IN SELECT jsonb_object_keys(policy->'account_roles') LOOP
        SELECT coalesce(sum(r.ledger_minor), 0) INTO expected FROM ledger_tax_register(tenant, book, ends, cutoff) r WHERE r.effective_on >= starts AND r.account_id::text = account_arg;
        FOR decision IN SELECT DISTINCT ON (d.journal_id, d.line_no) d.* FROM ledger_tax_decisions d WHERE d.tenant_id = tenant AND d.book_id = book AND d.scheme = scheme_arg AND d.kind = 'reconcile'
            AND d.sequence <= cutoff AND d.effective_on <= ends ORDER BY d.journal_id, d.line_no, d.sequence DESC LOOP
            SELECT p.functional_minor * CASE p.side WHEN 'debit' THEN 1 ELSE -1 END AS amount INTO balance FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
                WHERE p.tenant_id = tenant AND p.book_id = book AND p.journal_id = decision.journal_id AND p.line_no = decision.line_no AND p.account_id::text = account_arg AND j.effective_on BETWEEN starts AND ends AND j.sequence <= cutoff;
            IF FOUND THEN
                IF NOT (policy->'reconciliations') ? decision.classification OR NOT (policy->'reconciliations'->decision.classification->'roles') ? (policy->'account_roles'->>account_arg) THEN
                    unmapped := unmapped || jsonb_build_array(jsonb_build_object('decision_id', decision.id, 'classification', decision.classification));
                    CONTINUE;
                END IF;
                expected := expected + balance.amount;
                adjustment := adjustment + balance.amount * (policy->'reconciliations'->decision.classification->>'coefficient')::numeric;
                explained := explained || jsonb_build_array(jsonb_build_object('decision_id', decision.id, 'journal_id', decision.journal_id, 'line_no', decision.line_no,
                    'classification', decision.classification, 'amount_minor', balance.amount::text));
            END IF;
        END LOOP;
        SELECT coalesce(sum(p.functional_minor * CASE p.side WHEN 'debit' THEN 1 ELSE -1 END), 0) INTO actual FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
            WHERE p.tenant_id = tenant AND p.book_id = book AND p.account_id::text = account_arg AND j.effective_on BETWEEN starts AND ends AND j.sequence <= cutoff;
        reconciliations := reconciliations || jsonb_build_array(jsonb_build_object('account_id', account_arg, 'role', policy->'account_roles'->>account_arg,
            'ledger_minor', actual::text, 'explained_minor', expected::text, 'difference_minor', (actual - expected)::text));
    END LOOP;
    SELECT coalesce(jsonb_agg(jsonb_build_object('journal_id', p.journal_id, 'line_no', p.line_no, 'account_id', p.account_id) ORDER BY j.sequence, p.line_no), '[]'::jsonb) INTO unexplained
        FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
        WHERE p.tenant_id = tenant AND p.book_id = book AND j.effective_on BETWEEN starts AND ends AND j.sequence <= cutoff AND policy->'account_roles' ? p.account_id::text
        AND NOT EXISTS (SELECT 1 FROM ledger_tax_register(tenant, book, ends, cutoff) r WHERE r.journal_id = p.journal_id AND r.account_id = p.account_id)
        AND NOT EXISTS (SELECT 1 FROM ledger_tax_decisions d WHERE d.tenant_id = tenant AND d.book_id = book AND d.scheme = scheme_arg AND d.kind = 'reconcile'
            AND d.journal_id = p.journal_id AND d.line_no = p.line_no AND d.sequence <= cutoff AND d.effective_on <= ends);
    FOR term IN SELECT * FROM jsonb_each_text(policy->'payable_terms') LOOP net := net + (totals->>term.key)::numeric * term.value::numeric; END LOOP;
    net := net + adjustment;
    IF abs(net) >= power(10::numeric, 38) OR abs(adjustment) >= power(10::numeric, 38)
        OR EXISTS (SELECT 1 FROM jsonb_each_text(totals || bases) t WHERE abs(t.value::numeric) >= power(10::numeric, 38)) THEN RAISE EXCEPTION 'Tax return exceeds monetary storage bounds'; END IF;
    RETURN jsonb_build_object('entries', items, 'claims', claims, 'bases', bases, 'totals', totals, 'reconciliation', reconciliations, 'reconciliation_decisions', explained, 'unreconciled', unexplained,
        'unreviewed', unresolved, 'unmapped', unmapped, 'invalid_claims', invalid_claims, 'opening_credit_minor', opening::text, 'adjustments_minor', adjustment::text,
        'net_minor', net::text, 'credit_carried_minor', greatest(-net, 0)::text, 'configuration_hash', policy->>'configuration_hash',
        'currency', (SELECT currency_code FROM ledger_books WHERE tenant_id = tenant AND id = book));
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_tax_stamp() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id);
    UPDATE ledger_books SET next_sequence = next_sequence + 1 WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id RETURNING next_sequence INTO NEW.sequence;
    NEW.recorded_at := clock_timestamp(); NEW.actor_id := current_setting('noria.ledger_actor'); NEW.firm_id := nullif(current_setting('noria.ledger_firm', true), '');
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_tax_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE policy jsonb; entry record; definition ledger_tax_returns%ROWTYPE; previous ledger_tax_returns%ROWTYPE; expected jsonb; state text; role_name text;
BEGIN
    PERFORM 1 FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id FOR UPDATE;
    IF TG_TABLE_NAME = 'ledger_tax_decisions' THEN
        policy := ledger_tax_policy(NEW.tenant_id, NEW.book_id, NEW.configuration_id, NEW.scheme);
        IF NOT EXISTS (SELECT 1 FROM ledger_configurations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.configuration_id
            AND (definition_json->>'valid_from')::date <= NEW.effective_on AND coalesce((definition_json->>'valid_until')::date, '9999-12-31') >= NEW.effective_on) THEN RAISE EXCEPTION 'Tax decision policy must cover its effective date'; END IF;
        IF EXISTS (SELECT 1 FROM ledger_tax_decisions d WHERE d.tenant_id = NEW.tenant_id AND d.book_id = NEW.book_id AND d.scheme = NEW.scheme AND d.journal_id = NEW.journal_id
            AND d.line_no = NEW.line_no AND d.tax_code = NEW.tax_code AND (d.kind = 'reconcile') = (NEW.kind = 'reconcile') AND d.effective_on > NEW.effective_on) THEN RAISE EXCEPTION 'Tax decisions require chronological effective dates'; END IF;
        IF NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id AND effective_on <= NEW.effective_on) THEN RAISE EXCEPTION 'Tax decision cannot precede its source'; END IF;
        IF NEW.kind = 'reconcile' THEN
            SELECT p.* INTO STRICT entry FROM ledger_postings p WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.journal_id AND p.line_no = NEW.line_no;
            role_name := policy->'account_roles'->>entry.account_id::text;
            IF role_name IS NULL OR NOT (policy->'reconciliations') ? NEW.classification OR NOT (policy->'reconciliations'->NEW.classification->'roles') ? role_name OR NEW.tax_code <> ''
                OR EXISTS (SELECT 1 FROM ledger_tax_register(NEW.tenant_id, NEW.book_id, NEW.effective_on, NEW.sequence) r WHERE r.journal_id = NEW.journal_id) THEN RAISE EXCEPTION 'Tax reconciliation must identify an unregistered scoped posting and approved purpose'; END IF;
        ELSE
            SELECT * INTO STRICT entry FROM ledger_tax_register(NEW.tenant_id, NEW.book_id, NEW.effective_on, NEW.sequence) r WHERE r.journal_id = NEW.journal_id AND r.line_no = NEW.line_no AND r.tax_code = NEW.tax_code AND NOT r.is_reversal;
            IF NOT entry.claimable OR NOT (policy->'account_roles') ? entry.account_id::text OR NOT (policy->'codes') ? entry.tax_code
                OR EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND reverses_id = NEW.journal_id AND effective_on <= NEW.effective_on) THEN RAISE EXCEPTION 'Claim decision requires a live scoped input-tax entitlement'; END IF;
            IF NEW.fraction > 0 AND entry.requires_payment THEN
                IF NOT ledger_tax_payment_valid(NEW.tenant_id, NEW.book_id, NEW.journal_id, (NEW.evidence->>'payment_journal_id')::uuid, NEW.effective_on, NEW.sequence)
                    OR EXISTS (SELECT 1 FROM ledger_tax_decisions WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND kind = 'claim' AND fraction > 0 AND journal_id <> NEW.journal_id AND evidence->>'payment_journal_id' = NEW.evidence->>'payment_journal_id') THEN
                    RAISE EXCEPTION 'Imported input claims require an exclusive unreversed full tax payment';
                END IF;
            END IF;
        END IF;
    ELSIF TG_TABLE_NAME = 'ledger_tax_returns' THEN
        policy := ledger_tax_policy(NEW.tenant_id, NEW.book_id, NEW.configuration_id, NEW.scheme);
        IF NEW.through_sequence >= NEW.sequence THEN RAISE EXCEPTION 'Tax return cannot use a future knowledge cutoff'; END IF;
        IF policy->>'period' = 'calendar_month' AND (NEW.starts_on <> date_trunc('month', NEW.starts_on)::date OR NEW.effective_on <> (date_trunc('month', NEW.starts_on) + interval '1 month - 1 day')::date) THEN RAISE EXCEPTION 'This tax scheme requires a complete calendar-month return'; END IF;
        IF NOT EXISTS (SELECT 1 FROM ledger_configurations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.configuration_id
            AND (definition_json->>'valid_from')::date <= NEW.starts_on AND coalesce((definition_json->>'valid_until')::date, '9999-12-31') >= NEW.effective_on) THEN RAISE EXCEPTION 'Tax policy must cover the return period'; END IF;
        IF NEW.previous_id IS NOT NULL THEN
            SELECT * INTO STRICT previous FROM ledger_tax_returns WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.previous_id;
            IF (NEW.scheme, NEW.starts_on, NEW.effective_on) IS DISTINCT FROM (previous.scheme, previous.starts_on, previous.effective_on) OR NEW.through_sequence < previous.through_sequence THEN RAISE EXCEPTION 'A tax amendment must preserve its scheme and period without going backwards in knowledge'; END IF;
        ELSIF EXISTS (SELECT 1 FROM ledger_tax_returns WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND scheme = NEW.scheme AND starts_on <= NEW.effective_on AND effective_on >= NEW.starts_on) THEN RAISE EXCEPTION 'Tax return periods cannot overlap; prepare a linked amendment'; END IF;
        IF NEW.prior_period_id IS NOT NULL THEN
            SELECT * INTO STRICT previous FROM ledger_tax_returns WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.prior_period_id;
            IF previous.scheme <> NEW.scheme OR previous.effective_on + 1 <> NEW.starts_on OR NEW.opening_credit_minor <> (previous.result_json->>'credit_carried_minor')::numeric
                OR EXISTS (SELECT 1 FROM ledger_tax_returns r JOIN ledger_tax_return_events e ON e.tenant_id = r.tenant_id AND e.book_id = r.book_id AND e.return_id = r.id AND e.kind = 'file'
                    WHERE r.tenant_id = NEW.tenant_id AND r.book_id = NEW.book_id AND r.scheme = previous.scheme AND r.starts_on = previous.starts_on AND r.effective_on = previous.effective_on AND r.sequence > previous.sequence)
                OR NOT EXISTS (SELECT 1 FROM ledger_tax_return_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND return_id = previous.id AND kind = 'file') THEN RAISE EXCEPTION 'Opening credit must come from the current filed preceding return'; END IF;
        ELSE
            SELECT r.* INTO previous FROM ledger_tax_returns r JOIN ledger_tax_return_events e ON e.tenant_id = r.tenant_id AND e.book_id = r.book_id AND e.return_id = r.id AND e.kind = 'file'
                WHERE r.tenant_id = NEW.tenant_id AND r.book_id = NEW.book_id AND r.scheme = NEW.scheme AND r.effective_on + 1 = NEW.starts_on ORDER BY r.sequence DESC LIMIT 1;
            IF FOUND AND ((previous.result_json->>'credit_carried_minor')::numeric > 0 OR NEW.opening_credit_minor > 0) THEN RAISE EXCEPTION 'Link the preceding filed tax credit instead of importing or omitting it'; END IF;
            IF NEW.opening_credit_minor > 0 AND length(btrim(coalesce(NEW.opening_reference, ''))) = 0 THEN RAISE EXCEPTION 'Imported opening tax credits require explicit evidence'; END IF;
        END IF;
        expected := ledger_tax_return_result(NEW.tenant_id, NEW.book_id, NEW.configuration_id, NEW.scheme, NEW.starts_on, NEW.effective_on, NEW.through_sequence, NEW.opening_credit_minor);
        IF NEW.result_json IS DISTINCT FROM expected THEN RAISE EXCEPTION 'Tax return result differs from its independently computed evidence'; END IF;
        NEW.result_hash := encode(sha256(convert_to(NEW.result_json::text, 'UTF8')), 'hex');
    ELSE
        SELECT * INTO STRICT definition FROM ledger_tax_returns WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.return_id;
        SELECT kind INTO state FROM ledger_tax_return_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND return_id = NEW.return_id ORDER BY sequence DESC LIMIT 1;
        state := coalesce(state, 'prepare');
        IF NEW.effective_on < definition.effective_on OR EXISTS (SELECT 1 FROM ledger_tax_return_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND return_id = NEW.return_id AND effective_on > NEW.effective_on)
            OR NEW.kind <> 'file' AND EXISTS (SELECT 1 FROM ledger_tax_returns WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND previous_id = definition.id) THEN RAISE EXCEPTION 'Use the current tax return version in chronological order'; END IF;
        IF NEW.kind = 'finalize' THEN
            IF state <> 'prepare' THEN RAISE EXCEPTION 'Only a prepared return can be finalized'; END IF;
            expected := ledger_tax_return_result(NEW.tenant_id, NEW.book_id, definition.configuration_id, definition.scheme, definition.starts_on, definition.effective_on, NEW.sequence - 1, definition.opening_credit_minor);
            IF expected IS DISTINCT FROM definition.result_json THEN RAISE EXCEPTION 'Tax return is stale; prepare a new linked version'; END IF;
            IF expected->'unreviewed' <> '[]'::jsonb OR expected->'unmapped' <> '[]'::jsonb OR expected->'invalid_claims' <> '[]'::jsonb
                OR expected->'unreconciled' <> '[]'::jsonb OR EXISTS (SELECT 1 FROM jsonb_array_elements(expected->'reconciliation') r WHERE (r->>'difference_minor')::numeric <> 0) THEN RAISE EXCEPTION 'Tax return has unresolved claims, mappings or ledger reconciliation'; END IF;
        ELSIF NEW.kind = 'file' THEN
            IF state <> 'finalize' OR jsonb_typeof(NEW.evidence->'receipt_reference') IS DISTINCT FROM 'string' OR length(btrim(NEW.evidence->>'receipt_reference')) NOT BETWEEN 1 AND 256 THEN RAISE EXCEPTION 'Filing requires a finalized return and an external receipt reference'; END IF;
        ELSIF state NOT IN ('prepare', 'finalize') THEN RAISE EXCEPTION 'Filed or voided tax returns cannot be voided'; END IF;
        IF definition.prior_period_id IS NOT NULL AND NEW.kind = 'finalize' THEN
            SELECT * INTO STRICT previous FROM ledger_tax_returns WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = definition.prior_period_id;
            IF EXISTS (SELECT 1 FROM ledger_tax_returns r JOIN ledger_tax_return_events e ON e.tenant_id = r.tenant_id AND e.book_id = r.book_id AND e.return_id = r.id AND e.kind = 'file'
                WHERE r.tenant_id = NEW.tenant_id AND r.book_id = NEW.book_id AND r.scheme = previous.scheme AND r.starts_on = previous.starts_on AND r.effective_on = previous.effective_on AND r.sequence > previous.sequence) THEN RAISE EXCEPTION 'The opening-credit return was amended; prepare a new linked version'; END IF;
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

DO $body$
DECLARE name text;
BEGIN
    FOREACH name IN ARRAY ARRAY['ledger_tax_decisions', 'ledger_tax_returns', 'ledger_tax_return_events'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', name);
        EXECUTE format('CREATE POLICY ledger_tenant ON %I USING (tenant_id::text = nullif(current_setting(''noria.ledger_tenant'', true), '''')) WITH CHECK (tenant_id::text = nullif(current_setting(''noria.ledger_tenant'', true), ''''))', name);
        EXECUTE format('CREATE TRIGGER a_scope BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard()', name);
        EXECUTE format('CREATE TRIGGER b_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_immutable()', name);
        EXECUTE format('CREATE TRIGGER a_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable()', name);
        EXECUTE format('CREATE TRIGGER c_stamp BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_tax_stamp()', name);
        EXECUTE format('CREATE TRIGGER d_validate BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_tax_validate()', name);
        EXECUTE format('CREATE TRIGGER z_audit AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_audit()', name);
    END LOOP;
END;
$body$;
