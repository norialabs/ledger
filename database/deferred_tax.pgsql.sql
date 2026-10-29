ALTER TABLE ledger_deferred_tax_computations
    ADD CHECK (through_sequence >= 0 AND length(btrim(source)) > 0 AND length(btrim(idempotency_key)) > 0),
    ADD CHECK (fingerprint ~ '^[a-f0-9]{64}$' AND result_hash ~ '^[a-f0-9]{64}$'),
    ADD CHECK (jsonb_typeof(inputs) = 'object' AND jsonb_typeof(policy) = 'object' AND jsonb_typeof(result) = 'object');
ALTER TABLE ledger_deferred_tax_events
    ADD CHECK (kind IN ('approve', 'reject', 'post', 'reverse')),
    ADD CHECK ((kind = 'reverse') = (reverses_id IS NOT NULL)),
    ADD CHECK (length(btrim(reason)) > 0 AND length(btrim(idempotency_key)) > 0 AND fingerprint ~ '^[a-f0-9]{64}$'),
    ADD CHECK (jsonb_typeof(evidence) = 'object');

CREATE OR REPLACE FUNCTION ledger_deferred_tax_active(tenant uuid, book uuid, cutoff bigint, as_of date)
RETURNS SETOF ledger_deferred_tax_computations LANGUAGE sql STABLE SET search_path FROM CURRENT AS $body$
    SELECT c.* FROM ledger_deferred_tax_computations c JOIN ledger_deferred_tax_events e
        ON e.tenant_id = c.tenant_id AND e.book_id = c.book_id AND e.computation_id = c.id AND e.kind = 'post'
    WHERE c.tenant_id = tenant AND c.book_id = book AND e.sequence <= cutoff AND e.effective_on <= as_of
        AND NOT EXISTS (SELECT 1 FROM ledger_deferred_tax_events r WHERE r.tenant_id = tenant AND r.book_id = book
            AND r.reverses_id = e.id AND r.sequence <= cutoff AND r.effective_on <= as_of)
$body$;

CREATE OR REPLACE FUNCTION ledger_deferred_tax_policy(tenant uuid, book uuid, configuration uuid, date_arg date, cutoff bigint) RETURNS jsonb
LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE c ledger_configurations; rules jsonb; item record; account uuid; resolved jsonb := '{}'; class record; rule jsonb; previous_end date;
BEGIN
    SELECT * INTO STRICT c FROM ledger_configurations WHERE tenant_id = tenant AND book_id = book AND id = configuration;
    rules := c.definition_json->'settings'->'deferred_tax';
    IF rules->'asset_classes' = '[]'::jsonb THEN rules := jsonb_set(rules, '{asset_classes}', '{}'); END IF;
    IF rules->'attributions' = '[]'::jsonb THEN rules := jsonb_set(rules, '{attributions}', '{}'); END IF;
    IF (c.definition_json->>'valid_from')::date > date_arg OR coalesce((c.definition_json->>'valid_until')::date, '9999-12-31') < date_arg
        OR jsonb_typeof(rules) IS DISTINCT FROM 'object' OR NOT (rules ?& ARRAY['version', 'rates', 'rounding', 'separate_reviewer', 'asset_classes', 'attributions'])
        OR EXISTS (SELECT 1 FROM jsonb_object_keys(rules) k WHERE k NOT IN ('version', 'rates', 'rounding', 'separate_reviewer', 'asset_classes', 'attributions'))
        OR jsonb_typeof(rules->'version') IS DISTINCT FROM 'string' OR length(btrim(rules->>'version')) NOT BETWEEN 1 AND 128
        OR jsonb_typeof(rules->'rates') IS DISTINCT FROM 'object' OR rules->'rates' = '{}'::jsonb
        OR EXISTS (SELECT 1 FROM jsonb_each(rules->'rates') r WHERE length(btrim(r.key)) NOT BETWEEN 1 AND 64 OR jsonb_typeof(r.value) <> 'string'
            OR (r.value #>> '{}') !~ '^(0|1)(\.[0-9]{1,18})?$' OR (r.value #>> '{}')::numeric > 1)
        OR coalesce(rules->>'rounding', '') NOT IN ('half_even', 'half_up', 'down')
        OR jsonb_typeof(rules->'separate_reviewer') IS DISTINCT FROM 'boolean'
        OR jsonb_typeof(rules->'asset_classes') IS DISTINCT FROM 'object' OR jsonb_typeof(rules->'attributions') IS DISTINCT FROM 'object' THEN
        RAISE EXCEPTION 'Deferred tax requires explicit effective recognition, recovery-manner rates, rounding and allowance policies';
    END IF;
    IF c.sequence > cutoff OR ledger_tax_configuration_superseded(tenant, book, configuration, 'deferred_tax', date_arg, cutoff) THEN
        RAISE EXCEPTION 'Deferred tax must use the latest effective configuration known at the financial cutoff';
    END IF;
    FOR item IN SELECT key, value FROM jsonb_each_text(c.roles) WHERE key IN ('tax.deferred.asset', 'tax.deferred.liability') LOOP
        account := item.value::uuid;
        IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = account AND NOT is_group AND NOT is_control
            AND type = CASE item.key WHEN 'tax.deferred.asset' THEN 'asset' ELSE 'liability' END) THEN RAISE EXCEPTION 'Deferred tax requires non-control asset and liability roles'; END IF;
        resolved := resolved || jsonb_build_object(item.key, account::text);
    END LOOP;
    IF NOT (resolved ?& ARRAY['tax.deferred.asset', 'tax.deferred.liability']) THEN RAISE EXCEPTION 'Missing deferred tax asset or liability role'; END IF;
    FOR item IN SELECT key, value FROM jsonb_each(rules->'attributions') LOOP
        IF item.key NOT IN ('profit', 'oci_recyclable', 'oci_non_recyclable', 'equity_contribution', 'equity_distribution', 'opening_adjustment', 'opening')
            OR jsonb_typeof(item.value) <> 'string' THEN RAISE EXCEPTION 'Unknown deferred tax attribution'; END IF;
        account := (c.roles->>(item.value #>> '{}'))::uuid;
        IF account IS NULL OR resolved @> jsonb_build_object('tax.deferred.asset', account::text) OR resolved @> jsonb_build_object('tax.deferred.liability', account::text)
            OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = account AND NOT is_group AND NOT is_control
                AND type = CASE WHEN item.key = 'profit' THEN 'expense' ELSE 'equity' END)
            OR (item.key = 'profit' AND NOT starts_with(item.value #>> '{}', 'tax.income.')) THEN
            RAISE EXCEPTION 'Tax attribution requires income-tax expense or explicit equity posting roles'; END IF;
        resolved := resolved || jsonb_build_object(item.key, account::text);
    END LOOP;
    IF EXISTS (SELECT 1 FROM ledger_account_dimensions d JOIN jsonb_each_text(resolved) r ON d.account_id = r.value::uuid WHERE d.tenant_id = tenant AND d.book_id = book) THEN
        RAISE EXCEPTION 'Deferred tax posts book-level movements; its posting accounts cannot require dimensions';
    END IF;
    FOR class IN SELECT key, value FROM jsonb_each(rules->'asset_classes') LOOP
        IF length(btrim(class.key)) NOT BETWEEN 1 AND 64 OR jsonb_typeof(class.value) <> 'array' OR jsonb_array_length(class.value) = 0 THEN RAISE EXCEPTION 'Allowance classes require dated rules'; END IF;
        previous_end := NULL;
        FOR rule IN SELECT * FROM jsonb_array_elements(class.value) LOOP
            IF jsonb_typeof(rule) <> 'object' OR NOT (rule ?& ARRAY['from', 'until', 'method', 'rate', 'initial_rate'])
                OR EXISTS (SELECT 1 FROM jsonb_object_keys(rule) k WHERE k NOT IN ('from', 'until', 'method', 'rate', 'initial_rate'))
                OR ledger_tax_date(rule->'from') IS NULL OR ledger_tax_date(rule->'until') IS NULL
                OR ledger_tax_date(rule->'from') > ledger_tax_date(rule->'until') OR ledger_tax_date(rule->'from') <= previous_end
                OR coalesce(rule->>'method', '') NOT IN ('straight_line', 'reducing_balance')
                OR jsonb_typeof(rule->'rate') IS DISTINCT FROM 'string' OR (rule->>'rate') !~ '^(0|1)(\.[0-9]{1,18})?$' OR (rule->>'rate')::numeric > 1
                OR jsonb_typeof(rule->'initial_rate') IS DISTINCT FROM 'string' OR (rule->>'initial_rate') !~ '^(0|1)(\.[0-9]{1,18})?$' OR (rule->>'initial_rate')::numeric > 1 THEN
                RAISE EXCEPTION 'Allowance rules require ordered nonoverlapping dates, method and exact rates'; END IF;
            previous_end := (rule->>'until')::date;
        END LOOP;
    END LOOP;
    RETURN rules || jsonb_build_object('accounts', resolved, 'configuration_hash', c.definition_hash, 'configuration_name', c.name, 'configuration_version', c.version);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_deferred_tax_row(account uuid, amount numeric) RETURNS jsonb LANGUAGE sql IMMUTABLE AS $body$
    SELECT CASE WHEN amount = 0 THEN '[]'::jsonb ELSE jsonb_build_array(jsonb_build_object('account_id', account::text, 'minor', amount::text, 'reporting_movement', NULL)) END;
$body$;

CREATE OR REPLACE FUNCTION ledger_deferred_tax_loss_basis(tenant uuid, book uuid, loss_arg uuid, cutoff bigint, as_of date) RETURNS numeric
LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE loss ledger_income_tax_losses; rules jsonb;
BEGIN
    SELECT * INTO STRICT loss FROM ledger_income_tax_losses WHERE tenant_id = tenant AND book_id = book AND id = loss_arg AND sequence <= cutoff AND vintage_on <= as_of AND reverses_id IS NULL;
    IF EXISTS (SELECT 1 FROM ledger_income_tax_losses r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = loss.id AND r.sequence <= cutoff AND r.vintage_on <= as_of)
        OR loss.computation_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_income_tax_active(tenant, book, cutoff, as_of) c WHERE c.id = loss.computation_id) THEN RETURN 0; END IF;
    rules := ledger_income_tax_effective_rules(tenant, book, cutoff, as_of, true);
    IF rules IS NULL THEN RAISE EXCEPTION 'Loss deferred tax requires an effective income tax configuration to determine expiry'; END IF;
    IF ledger_income_tax_loss_usable_until(rules, loss) <= as_of THEN RETURN 0; END IF;
    RETURN loss.amount_minor - coalesce((SELECT sum((c.inputs->'losses'->>loss.id::text)::numeric) FROM ledger_income_tax_active(tenant, book, cutoff, as_of) c), 0);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_deferred_tax_calculate(tenant uuid, book uuid, source_arg text, date_arg date, cutoff bigint, rules jsonb, data jsonb) RETURNS jsonb
LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE prior ledger_deferred_tax_computations; carry numeric; basis numeric; difference numeric; potential numeric; recognized numeric; liability numeric; allowance numeric := 0;
    opening_asset numeric := 0; opening_liability numeric := 0; charge numeric; allocated numeric := 0; part jsonb; amount numeric; account uuid; rows jsonb := '[]';
    movement jsonb; state jsonb; rule jsonb; capital jsonb; initial numeric := 0; qualifying numeric; initial_used boolean := false; maximum bigint; rate numeric;
BEGIN
    SELECT next_sequence INTO STRICT maximum FROM ledger_books WHERE tenant_id = tenant AND id = book;
    IF cutoff < 0 OR cutoff > maximum OR jsonb_typeof(data) IS DISTINCT FROM 'object'
        OR NOT (data ?& ARRAY['kind', 'carrying_minor', 'tax_base_minor', 'recognize_minor', 'recognition_reference', 'exempt', 'basis_reference', 'opening', 'attributions', 'recovery_manner'])
        OR EXISTS (SELECT 1 FROM jsonb_object_keys(data) k WHERE k NOT IN ('kind', 'carrying_minor', 'tax_base_minor', 'recognize_minor', 'recognition_reference', 'exempt', 'basis_reference', 'opening', 'attributions', 'recovery_manner', 'asset_id', 'loss_id', 'capital_allowance', 'allowance_minor'))
        OR coalesce(data->>'kind', '') NOT IN ('asset', 'liability', 'loss', 'temporary_asset')
        OR jsonb_typeof(data->'exempt') IS DISTINCT FROM 'boolean' OR jsonb_typeof(data->'opening') IS DISTINCT FROM 'boolean'
        OR jsonb_typeof(data->'recognition_reference') IS DISTINCT FROM 'string' OR length(btrim(data->>'recognition_reference')) NOT BETWEEN 1 AND 256
        OR jsonb_typeof(data->'basis_reference') IS DISTINCT FROM 'string' OR length(btrim(data->>'basis_reference')) NOT BETWEEN 1 AND 256
        OR jsonb_typeof(data->'attributions') IS DISTINCT FROM 'array' OR jsonb_array_length(data->'attributions') > 100 THEN
        RAISE EXCEPTION 'Deferred tax requires complete reviewed basis, exemption, recognition and attribution inputs'; END IF;
    IF jsonb_typeof(data->'recovery_manner') IS DISTINCT FROM 'string' OR NOT (rules->'rates' ? (data->>'recovery_manner')) THEN
        RAISE EXCEPTION 'Deferred tax recovery manner must select a configured rate'; END IF;
    rate := (rules->'rates'->>(data->>'recovery_manner'))::numeric;
    SELECT * INTO prior FROM ledger_deferred_tax_active(tenant, book, cutoff, date_arg) c WHERE c.source = source_arg ORDER BY c.sequence DESC LIMIT 1;
    IF prior.id IS NOT NULL THEN
        IF prior.inputs->>'kind' <> data->>'kind' OR prior.inputs->'asset_id' IS DISTINCT FROM data->'asset_id' OR prior.inputs->'loss_id' IS DISTINCT FROM data->'loss_id'
            OR date_arg <= prior.effective_on OR (data->>'opening')::boolean THEN RAISE EXCEPTION 'Deferred tax source identity and chronology must be preserved'; END IF;
        opening_asset := (prior.result->>'asset_minor')::numeric; opening_liability := (prior.result->>'liability_minor')::numeric;
        initial_used := (prior.result->>'initial_used')::boolean;
        IF prior.policy->'accounts'->>'tax.deferred.asset' IS DISTINCT FROM rules->'accounts'->>'tax.deferred.asset'
            OR prior.policy->'accounts'->>'tax.deferred.liability' IS DISTINCT FROM rules->'accounts'->>'tax.deferred.liability' THEN
            RAISE EXCEPTION 'Correct the source before moving its deferred tax balances to other accounts'; END IF;
    ELSIF (data->>'opening')::boolean AND (NOT EXISTS (SELECT 1 FROM ledger_periods WHERE tenant_id = tenant AND book_id = book AND starts_on = date_arg)
        OR EXISTS (SELECT 1 FROM ledger_deferred_tax_computations o JOIN ledger_deferred_tax_events e ON e.tenant_id = o.tenant_id AND e.book_id = o.book_id AND e.computation_id = o.id AND e.kind = 'post'
            WHERE o.tenant_id = tenant AND o.book_id = book AND e.sequence <= cutoff
                AND ((o.inputs->>'opening')::boolean AND o.effective_on <> date_arg OR NOT (o.inputs->>'opening')::boolean AND o.effective_on < date_arg))) THEN
        RAISE EXCEPTION 'Opening deferred tax is measured on one adoption date at the start of an accounting period, before any other measurement';
    END IF;
    IF data->>'kind' = 'asset' THEN
        IF source_arg IS DISTINCT FROM 'asset:' || (data->>'asset_id')::uuid::text OR data ? 'loss_id' THEN RAISE EXCEPTION 'Asset tax bases require a canonical asset source'; END IF;
        state := ledger_asset_quote(tenant, book, (data->>'asset_id')::uuid, date_arg, cutoff);
        carry := (state->>'carrying_minor')::numeric;
        IF data->'carrying_minor' <> 'null'::jsonb THEN RAISE EXCEPTION 'Registered asset carrying value is database derived'; END IF;
    ELSIF data->>'kind' = 'loss' THEN
        IF source_arg IS DISTINCT FROM 'loss:' || (data->>'loss_id')::uuid::text OR data ? 'asset_id' OR data->'carrying_minor' <> 'null'::jsonb OR data->'tax_base_minor' <> 'null'::jsonb THEN RAISE EXCEPTION 'Loss assets require a canonical loss source and derived availability'; END IF;
        basis := ledger_deferred_tax_loss_basis(tenant, book, (data->>'loss_id')::uuid, cutoff, date_arg);
        carry := 0;
    ELSE
        IF NOT starts_with(source_arg, 'temporary:') OR data ?| ARRAY['asset_id', 'loss_id'] THEN RAISE EXCEPTION 'Other differences require a distinct temporary source'; END IF;
        carry := ledger_income_tax_minor(data->'carrying_minor');
    END IF;
    IF data ? 'capital_allowance' THEN
        capital := data->'capital_allowance';
        IF data->>'kind' <> 'asset' OR prior.id IS NULL OR data->'tax_base_minor' <> 'null'::jsonb OR jsonb_typeof(capital) <> 'object'
            OR NOT (capital ?& ARRAY['class', 'starts_on', 'qualifying_minor', 'initial_eligible', 'fraction'])
            OR EXISTS (SELECT 1 FROM jsonb_object_keys(capital) k WHERE k NOT IN ('class', 'starts_on', 'qualifying_minor', 'qualifying_reference', 'initial_eligible', 'fraction'))
            OR jsonb_typeof(capital->'class') IS DISTINCT FROM 'string'
            OR ledger_tax_date(capital->'starts_on') IS NULL OR ledger_tax_date(capital->'starts_on') <= prior.effective_on OR ledger_tax_date(capital->'starts_on') > date_arg
            OR jsonb_typeof(capital->'initial_eligible') IS DISTINCT FROM 'boolean'
            OR jsonb_typeof(capital->'fraction') IS DISTINCT FROM 'string' OR (capital->>'fraction') !~ '^(0|1)(\.[0-9]{1,18})?$' OR (capital->>'fraction')::numeric > 1 THEN
            RAISE EXCEPTION 'Capital allowances require an asset opening basis and explicit nonoverlapping period and fraction'; END IF;
        IF NOT (rules->'asset_classes' ? (capital->>'class')) THEN RAISE EXCEPTION 'Unknown capital allowance class'; END IF;
        SELECT value INTO rule FROM jsonb_array_elements(rules->'asset_classes'->(capital->>'class'))
            WHERE (value->>'from')::date <= (capital->>'starts_on')::date AND (value->>'until')::date >= date_arg;
        IF rule IS NULL THEN RAISE EXCEPTION 'No single allowance rule covers the claim period; measure at the rule boundary'; END IF;
        IF capital->'qualifying_minor' = 'null'::jsonb THEN
            IF capital ? 'qualifying_reference' THEN RAISE EXCEPTION 'A registered qualifying cost needs no override reference'; END IF;
            qualifying := (state->>'cost_minor')::numeric;
        ELSE
            IF jsonb_typeof(capital->'qualifying_reference') IS DISTINCT FROM 'string' OR length(btrim(capital->>'qualifying_reference')) NOT BETWEEN 1 AND 256 THEN
                RAISE EXCEPTION 'A qualifying cost that overrides the asset register requires a reviewed reference'; END IF;
            qualifying := ledger_income_tax_minor(capital->'qualifying_minor');
        END IF;
        basis := (prior.result->>'tax_base_minor')::numeric;
        IF (capital->>'initial_eligible')::boolean THEN
            IF initial_used THEN RAISE EXCEPTION 'Initial capital allowance has already been used'; END IF;
            initial := least(basis, ledger_fraction(qualifying * (rule->>'initial_rate')::numeric, 1, rules->>'rounding'));
            initial_used := true;
        END IF;
        allowance := initial + least(basis - initial, ledger_fraction(CASE WHEN rule->>'method' = 'straight_line' THEN qualifying ELSE basis - initial END
            * (rule->>'rate')::numeric * (capital->>'fraction')::numeric, 1, rules->>'rounding'));
        basis := basis - allowance;
    ELSIF data->>'kind' <> 'loss' THEN
        basis := ledger_income_tax_minor(data->'tax_base_minor');
    END IF;
    IF data ? 'allowance_minor' THEN
        IF data ? 'capital_allowance' OR data->>'kind' NOT IN ('asset', 'temporary_asset') OR prior.id IS NULL THEN
            RAISE EXCEPTION 'A declared allowance belongs to a reviewed replacement tax base of an asset after its opening measurement'; END IF;
        allowance := ledger_income_tax_minor(data->'allowance_minor');
    END IF;
    difference := CASE WHEN data->>'kind' = 'liability' THEN basis - carry ELSE carry - basis END;
    potential := CASE WHEN (data->>'exempt')::boolean THEN 0 ELSE ledger_fraction(greatest(-difference, 0) * rate, 1, rules->>'rounding') END;
    recognized := ledger_income_tax_minor(data->'recognize_minor');
    IF recognized > potential THEN RAISE EXCEPTION 'Recognized deferred tax asset exceeds eligible deductible difference'; END IF;
    liability := CASE WHEN (data->>'exempt')::boolean THEN 0 ELSE ledger_fraction(greatest(difference, 0) * rate, 1, rules->>'rounding') END;
    charge := liability - opening_liability - recognized + opening_asset;
    rows := ledger_deferred_tax_row((rules->'accounts'->>'tax.deferred.asset')::uuid, recognized - opening_asset)
        || ledger_deferred_tax_row((rules->'accounts'->>'tax.deferred.liability')::uuid, opening_liability - liability);
    FOR part IN SELECT * FROM jsonb_array_elements(data->'attributions') LOOP
        IF jsonb_typeof(part) <> 'object' OR NOT (part ?& ARRAY['category', 'amount_minor', 'reference'])
            OR EXISTS (SELECT 1 FROM jsonb_object_keys(part) k WHERE k NOT IN ('category', 'amount_minor', 'reference'))
            OR jsonb_typeof(part->'category') IS DISTINCT FROM 'string' OR NOT (rules->'accounts' ? (part->>'category'))
            OR part->>'category' IN ('tax.deferred.asset', 'tax.deferred.liability')
            OR jsonb_typeof(part->'reference') IS DISTINCT FROM 'string' OR length(btrim(part->>'reference')) NOT BETWEEN 1 AND 256
            OR ((data->>'opening')::boolean IS DISTINCT FROM (part->>'category' = 'opening')) THEN RAISE EXCEPTION 'Deferred tax movement requires an explicit profit, OCI, equity or opening attribution'; END IF;
        amount := ledger_income_tax_minor(part->'amount_minor', true); allocated := allocated + amount;
        account := (rules->'accounts'->>(part->>'category'))::uuid;
        movement := CASE WHEN part->>'category' = 'profit' THEN NULL ELSE jsonb_build_object('kind', CASE part->>'category' WHEN 'oci_recyclable' THEN 'oci' WHEN 'oci_non_recyclable' THEN 'oci'
                WHEN 'equity_contribution' THEN 'contribution' WHEN 'equity_distribution' THEN 'distribution' WHEN 'opening_adjustment' THEN 'opening_adjustment' ELSE 'opening_balance' END,
            'policy_version', rules->>'version', 'reference', part->>'reference', 'oci_category', CASE part->>'category' WHEN 'oci_recyclable' THEN 'recyclable' WHEN 'oci_non_recyclable' THEN 'non_recyclable' END,
            'source_journal_id', NULL, 'source_line_no', NULL) END;
        IF amount <> 0 THEN rows := rows || jsonb_build_array(jsonb_build_object('account_id', account::text, 'minor', amount::text, 'reporting_movement', movement)); END IF;
    END LOOP;
    IF allocated <> charge THEN RAISE EXCEPTION 'Deferred tax source attributions must conserve the exact movement'; END IF;
    IF greatest(carry, basis, abs(difference), potential, recognized, liability, abs(charge), allowance) >= power(10::numeric, 38) THEN RAISE EXCEPTION 'Deferred tax exceeds exact storage bounds'; END IF;
    RETURN jsonb_build_object('prior_id', prior.id, 'carrying_minor', carry::text, 'tax_base_minor', basis::text, 'allowance_minor', allowance::text, 'qualifying_minor', qualifying::text, 'initial_used', initial_used,
        'difference_minor', difference::text, 'unrecognized_asset_minor', (potential - recognized)::text, 'opening_asset_minor', opening_asset::text, 'opening_liability_minor', opening_liability::text,
        'asset_minor', recognized::text, 'liability_minor', liability::text, 'charge_minor', CASE WHEN (data->>'opening')::boolean THEN '0' ELSE charge::text END,
        'opening_movement_minor', CASE WHEN (data->>'opening')::boolean THEN charge::text ELSE '0' END, 'postings', rows);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_deferred_tax_stamp() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    IF TG_TABLE_NAME = 'ledger_deferred_tax_events' AND to_jsonb(NEW)->>'journal_id' IS NOT NULL THEN
        SELECT sequence INTO STRICT NEW.sequence FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = (to_jsonb(NEW)->>'journal_id')::uuid;
    ELSE
        UPDATE ledger_books SET next_sequence = next_sequence + 1 WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id RETURNING next_sequence INTO STRICT NEW.sequence;
    END IF;
    NEW.recorded_at := clock_timestamp(); NEW.actor_id := current_setting('noria.ledger_actor'); NEW.firm_id := nullif(current_setting('noria.ledger_firm', true), '');
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_deferred_tax_prepare() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id);
    NEW.policy := ledger_deferred_tax_policy(NEW.tenant_id, NEW.book_id, NEW.configuration_id, NEW.effective_on, NEW.through_sequence);
    NEW.result := ledger_deferred_tax_calculate(NEW.tenant_id, NEW.book_id, NEW.source, NEW.effective_on, NEW.through_sequence, NEW.policy, NEW.inputs);
    NEW.result_hash := encode(sha256(convert_to(NEW.result::text, 'UTF8')), 'hex');
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_deferred_tax_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE c ledger_deferred_tax_computations; original ledger_deferred_tax_events; j ledger_journals; cutoff bigint; expected jsonb; actual jsonb; latest uuid;
BEGIN
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id, NEW.kind = 'reverse');
    SELECT * INTO STRICT c FROM ledger_deferred_tax_computations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.computation_id;
    IF NEW.kind IN ('approve', 'reject') THEN
        IF NEW.journal_id IS NOT NULL OR NEW.effective_on < c.effective_on OR EXISTS (SELECT 1 FROM ledger_deferred_tax_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND computation_id = c.id) THEN RAISE EXCEPTION 'Deferred tax review is final and precedes posting'; END IF;
        IF (c.policy->>'separate_reviewer')::boolean AND NEW.actor_id = c.actor_id THEN RAISE EXCEPTION 'Deferred tax requires a separate reviewer'; END IF;
    ELSIF NEW.kind = 'post' THEN
        IF NEW.effective_on <> c.effective_on OR NOT EXISTS (SELECT 1 FROM ledger_deferred_tax_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND computation_id = c.id AND kind = 'approve' AND effective_on <= NEW.effective_on) THEN RAISE EXCEPTION 'Deferred tax posting requires approval on its measurement date'; END IF;
        SELECT next_sequence INTO STRICT cutoff FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id;
        IF EXISTS (SELECT 1 FROM ledger_deferred_tax_events e JOIN ledger_deferred_tax_computations p ON p.tenant_id = e.tenant_id AND p.book_id = e.book_id AND p.id = e.computation_id
            WHERE e.tenant_id = NEW.tenant_id AND e.book_id = NEW.book_id AND p.source = c.source AND e.kind IN ('post', 'reverse') AND e.effective_on > NEW.effective_on) THEN RAISE EXCEPTION 'Deferred tax cannot backdate before known source history'; END IF;
        expected := ledger_deferred_tax_calculate(NEW.tenant_id, NEW.book_id, c.source, c.effective_on, cutoff, c.policy, c.inputs);
        IF ledger_tax_configuration_superseded(NEW.tenant_id, NEW.book_id, c.configuration_id, 'deferred_tax', c.effective_on, 9223372036854775807) THEN
            RAISE EXCEPTION 'Deferred tax measurement uses a superseded configuration; prepare and review a replacement'; END IF;
        IF expected <> c.result THEN RAISE EXCEPTION 'Deferred tax computation is stale; prepare and review a replacement'; END IF;
        IF c.result->'postings' = '[]'::jsonb THEN
            IF NEW.journal_id IS NOT NULL THEN RAISE EXCEPTION 'Zero deferred tax movement has no journal'; END IF;
            PERFORM ledger_tax_require_open_period(NEW.tenant_id, NEW.book_id, NEW.effective_on);
        ELSE
            SELECT * INTO STRICT j FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id;
            IF j.source_type IS DISTINCT FROM 'ledger.deferred_tax' OR j.source_reference IS DISTINCT FROM c.id::text OR j.reverses_id IS NOT NULL OR j.closing OR j.effective_on <> NEW.effective_on THEN RAISE EXCEPTION 'Deferred tax journal must match its measurement'; END IF;
            SELECT jsonb_agg(jsonb_build_object('account_id', p->>'account_id', 'minor', ((p->>'functional_minor')::numeric * CASE WHEN p->>'side' = 'debit' THEN 1 ELSE -1 END)::text, 'reporting_movement', p->'reporting_movement') ORDER BY p->>'account_id', p->>'reporting_movement', p->>'side') INTO actual FROM jsonb_array_elements(j.postings) p;
            SELECT jsonb_agg(jsonb_build_object('account_id', p->>'account_id', 'minor', p->>'minor', 'reporting_movement', p->'reporting_movement') ORDER BY p->>'account_id', p->>'reporting_movement', CASE WHEN (p->>'minor')::numeric > 0 THEN 'debit' ELSE 'credit' END) INTO expected FROM jsonb_array_elements(c.result->'postings') p;
            IF actual <> expected OR EXISTS (SELECT 1 FROM jsonb_array_elements(j.postings) p WHERE p->'dimensions' <> '{}'::jsonb OR p->>'party_id' IS NOT NULL OR (p->>'amount_minor')::numeric IS DISTINCT FROM (p->>'functional_minor')::numeric
                OR p->>'currency_code' IS DISTINCT FROM (SELECT currency_code FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id)) THEN RAISE EXCEPTION 'Deferred tax postings must exactly conserve the approved source and attribution'; END IF;
        END IF;
    ELSE
        SELECT * INTO STRICT original FROM ledger_deferred_tax_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
        IF original.kind <> 'post' OR original.computation_id <> c.id OR NEW.effective_on < original.effective_on THEN RAISE EXCEPTION 'Deferred tax correction must reverse its original posting'; END IF;
        IF EXISTS (SELECT 1 FROM ledger_deferred_tax_computations d JOIN ledger_deferred_tax_events p ON p.tenant_id = d.tenant_id AND p.book_id = d.book_id AND p.computation_id = d.id AND p.kind = 'post'
            LEFT JOIN ledger_deferred_tax_events r ON r.tenant_id = p.tenant_id AND r.book_id = p.book_id AND r.reverses_id = p.id
            WHERE d.tenant_id = NEW.tenant_id AND d.book_id = NEW.book_id AND d.result->>'prior_id' = c.id::text AND (r.id IS NULL OR r.effective_on > greatest(NEW.effective_on, p.effective_on))) THEN RAISE EXCEPTION 'Correct dependent deferred tax measurements first'; END IF;
        IF original.journal_id IS NULL THEN
            IF NEW.journal_id IS NOT NULL THEN RAISE EXCEPTION 'Zero tax correction has no journal'; END IF;
            PERFORM ledger_tax_require_open_period(NEW.tenant_id, NEW.book_id, NEW.effective_on);
        ELSE
            SELECT * INTO STRICT j FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id;
            IF j.reverses_id IS DISTINCT FROM original.journal_id OR j.effective_on <> NEW.effective_on THEN RAISE EXCEPTION 'Deferred tax correction requires the exact reversing journal'; END IF;
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_deferred_tax_reversal() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE original ledger_deferred_tax_events;
BEGIN
    IF NEW.reverses_id IS NOT NULL THEN
        SELECT * INTO original FROM ledger_deferred_tax_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.reverses_id AND kind = 'post';
        IF FOUND THEN
            INSERT INTO ledger_deferred_tax_events (tenant_id, book_id, computation_id, idempotency_key, fingerprint, kind, effective_on, reason, evidence, journal_id, reverses_id)
            VALUES (NEW.tenant_id, NEW.book_id, original.computation_id, 'journal:' || NEW.id, NEW.fingerprint, 'reverse', NEW.effective_on, NEW.description, '{}', NEW.id, original.id);
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_deferred_tax_source_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE corrected text;
BEGIN
    IF NEW.source_type = 'ledger.deferred_tax' AND current_setting('noria.ledger_deferred_tax', true) IS DISTINCT FROM NEW.source_reference THEN RAISE EXCEPTION 'Deferred tax journals are posted only by the income tax service'; END IF;
    IF NEW.reverses_id IS NOT NULL THEN
        SELECT source_reference INTO corrected FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id AND source_type = 'ledger.deferred_tax';
        IF FOUND THEN
            IF current_setting('noria.ledger_deferred_tax', true) IS DISTINCT FROM corrected THEN RAISE EXCEPTION 'Deferred tax journals are corrected only by the income tax service'; END IF;
            RETURN NEW;
        END IF;
    END IF;
    IF NEW.source_type IS DISTINCT FROM 'ledger.deferred_tax' AND EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.postings) p JOIN ledger_configurations c ON c.tenant_id = NEW.tenant_id AND c.book_id = NEW.book_id
        WHERE p->>'account_id' IN (c.roles->>'tax.deferred.asset', c.roles->>'tax.deferred.liability')) THEN
        RAISE EXCEPTION 'Deferred tax control balances require a linked reviewed measurement';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_deferred_tax_configuration_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    IF EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
        WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.account_id::text IN (NEW.roles->>'tax.deferred.asset', NEW.roles->>'tax.deferred.liability')
            AND j.source_type IS DISTINCT FROM 'ledger.deferred_tax'
            AND NOT EXISTS (SELECT 1 FROM ledger_journals original WHERE original.tenant_id = j.tenant_id AND original.book_id = j.book_id AND original.id = j.reverses_id
                AND original.source_type = 'ledger.deferred_tax')) THEN
        RAISE EXCEPTION 'Deferred tax balance accounts must be dedicated: use accounts without postings from other journals and bring balances in through opening measurements';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_deferred_tax_in_use(tenant uuid, book uuid, starts date, ends date, cutoff bigint) RETURNS boolean
LANGUAGE sql STABLE SET search_path FROM CURRENT AS $body$
    SELECT EXISTS (SELECT 1 FROM ledger_configurations WHERE tenant_id = tenant AND book_id = book AND sequence <= cutoff AND definition_json->'settings' ? 'deferred_tax'
            AND (definition_json->>'valid_from')::date <= ends AND coalesce((definition_json->>'valid_until')::date, '9999-12-31') >= starts)
        OR EXISTS (SELECT 1 FROM ledger_deferred_tax_active(tenant, book, cutoff, ends))
$body$;

CREATE OR REPLACE FUNCTION ledger_deferred_tax_checklist(tenant uuid, book uuid, starts date, ends date) RETURNS TABLE(item text)
LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE configuration jsonb; latest uuid; c ledger_deferred_tax_computations; cutoff bigint; basis numeric;
BEGIN
    SELECT next_sequence INTO STRICT cutoff FROM ledger_books WHERE tenant_id = tenant AND id = book;
    IF NOT ledger_deferred_tax_in_use(tenant, book, starts, ends, cutoff) THEN RETURN; END IF;
    SELECT id INTO latest FROM ledger_configurations WHERE tenant_id = tenant AND book_id = book AND sequence <= cutoff
        AND definition_json->'settings' ? 'deferred_tax' AND (definition_json->>'valid_from')::date <= ends
        AND coalesce((definition_json->>'valid_until')::date, '9999-12-31') >= ends ORDER BY sequence DESC LIMIT 1;
    IF latest IS NOT NULL THEN
        BEGIN
            configuration := ledger_deferred_tax_policy(tenant, book, latest, ends, cutoff);
        EXCEPTION WHEN raise_exception OR no_data_found THEN
            item := 'deferred_tax:invalid_configuration'; RETURN NEXT;
            RETURN;
        END;
    END IF;
    IF configuration IS NULL AND EXISTS (SELECT 1 FROM (SELECT DISTINCT ON (a.source) a.result, a.inputs FROM ledger_deferred_tax_active(tenant, book, cutoff, ends) a ORDER BY a.source, a.sequence DESC) latest
        WHERE (latest.result->>'difference_minor')::numeric <> 0 AND NOT (latest.inputs->>'exempt')::boolean) THEN
        item := 'deferred_tax:missing_configuration'; RETURN NEXT;
    END IF;
    FOR c IN SELECT DISTINCT ON (a.source) * FROM ledger_deferred_tax_active(tenant, book, cutoff, ends) a ORDER BY a.source, a.sequence DESC LOOP
        CONTINUE WHEN (c.inputs->>'exempt')::boolean;
        IF c.inputs->>'kind' = 'asset' AND (ledger_asset_quote(tenant, book, (c.inputs->>'asset_id')::uuid, ends, cutoff)->>'carrying_minor')::numeric
            <> (c.result->>'carrying_minor')::numeric THEN item := c.source || ':stale_carrying'; RETURN NEXT; END IF;
        IF c.inputs->>'kind' = 'loss' THEN
            BEGIN
                basis := ledger_deferred_tax_loss_basis(tenant, book, (c.inputs->>'loss_id')::uuid, cutoff, ends);
                IF basis <> (c.result->>'tax_base_minor')::numeric THEN item := c.source || ':stale_loss_basis'; RETURN NEXT; END IF;
            EXCEPTION WHEN raise_exception OR no_data_found THEN
                item := c.source || ':loss_basis_unavailable'; RETURN NEXT;
            END;
        END IF;
        CONTINUE WHEN (c.result->>'difference_minor')::numeric = 0;
        IF c.inputs->>'kind' IN ('liability', 'temporary_asset') AND c.effective_on < starts THEN item := c.source || ':not_remeasured'; RETURN NEXT; END IF;
        IF configuration IS NOT NULL AND configuration->'rates'->>(c.inputs->>'recovery_manner') IS DISTINCT FROM c.policy->'rates'->>(c.inputs->>'recovery_manner') THEN
            item := c.source || ':rate_changed'; RETURN NEXT;
        END IF;
    END LOOP;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_deferred_tax_allowance_reconciliation(tenant uuid, book uuid, starts date, ends date) RETURNS TABLE(item text)
LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE cutoff bigint; allowances numeric; claimed numeric;
BEGIN
    SELECT next_sequence INTO STRICT cutoff FROM ledger_books WHERE tenant_id = tenant AND id = book;
    IF NOT ledger_deferred_tax_in_use(tenant, book, starts, ends, cutoff) THEN RETURN; END IF;
    SELECT coalesce(sum((a.result->>'allowance_minor')::numeric), 0) INTO allowances FROM ledger_deferred_tax_active(tenant, book, cutoff, ends) a
        WHERE a.effective_on BETWEEN starts AND ends;
    SELECT coalesce(-sum((v->>'amount_minor')::numeric), 0) INTO claimed FROM ledger_income_tax_active(tenant, book, cutoff, ends) t
        CROSS JOIN LATERAL jsonb_array_elements(t.inputs->'adjustments') v WHERE t.starts_on >= starts AND t.ends_on <= ends AND v->>'kind' = 'capital_allowance';
    IF allowances <> claimed THEN item := 'deferred:' || allowances::text || ':current:' || claimed::text; RETURN NEXT; END IF;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_deferred_tax_link() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE previous_tenant text := coalesce(current_setting('noria.ledger_tenant', true), '');
BEGIN
    PERFORM set_config('noria.ledger_tenant', NEW.tenant_id::text, true);
    IF NEW.source_type = 'ledger.deferred_tax' AND NOT EXISTS (SELECT 1 FROM ledger_deferred_tax_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id AND kind = 'post' AND computation_id::text = NEW.source_reference) THEN RAISE EXCEPTION 'Deferred tax journal requires its linked measurement'; END IF;
    PERFORM set_config('noria.ledger_tenant', previous_tenant, true);
    RETURN NULL;
END;
$body$;

DO $body$
DECLARE name text;
BEGIN
    FOREACH name IN ARRAY ARRAY['ledger_deferred_tax_computations', 'ledger_deferred_tax_events'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', name);
        EXECUTE format('CREATE POLICY ledger_tenant ON %I USING (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid) WITH CHECK (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid)', name);
        EXECUTE format('CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard()', name);
        EXECUTE format('CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable()', name);
        EXECUTE format('CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_immutable()', name);
        EXECUTE format('CREATE TRIGGER stage_30_stamp BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_deferred_tax_stamp()', name);
        EXECUTE format('CREATE TRIGGER stage_90_audit AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_audit()', name);
    END LOOP;
END;
$body$;
CREATE TRIGGER stage_40_validate BEFORE INSERT ON ledger_deferred_tax_computations FOR EACH ROW EXECUTE FUNCTION ledger_deferred_tax_prepare();
CREATE TRIGGER stage_40_validate BEFORE INSERT ON ledger_deferred_tax_events FOR EACH ROW EXECUTE FUNCTION ledger_deferred_tax_validate();
CREATE TRIGGER stage_40_deferred_tax_source BEFORE INSERT ON ledger_journals FOR EACH ROW EXECUTE FUNCTION ledger_deferred_tax_source_guard();
CREATE TRIGGER stage_50_deferred_tax_accounts BEFORE INSERT ON ledger_configurations FOR EACH ROW EXECUTE FUNCTION ledger_deferred_tax_configuration_guard();
CREATE TRIGGER stage_80_reverse_deferred_tax AFTER INSERT ON ledger_journals FOR EACH ROW EXECUTE FUNCTION ledger_deferred_tax_reversal();
CREATE CONSTRAINT TRIGGER stage_99_deferred_tax_link AFTER INSERT ON ledger_journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_deferred_tax_link();
