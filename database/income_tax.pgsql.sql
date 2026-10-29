ALTER TABLE ledger_income_tax_computations
    ADD CHECK (starts_on <= ends_on),
    ADD CHECK (through_sequence >= 0), ADD CHECK (length(btrim(source)) > 0),
    ADD CHECK (jsonb_typeof(inputs) = 'object' AND jsonb_typeof(policy) = 'object' AND jsonb_typeof(result) = 'object'),
    ADD CHECK (length(btrim(idempotency_key)) > 0 AND fingerprint ~ '^[a-f0-9]{64}$' AND result_hash ~ '^[a-f0-9]{64}$');
ALTER TABLE ledger_income_tax_losses
    ADD CHECK (amount_minor <> 0 AND amount_minor = trunc(amount_minor) AND abs(amount_minor) < power(10::numeric, 38)),
    ADD CHECK ((amount_minor > 0) = (reverses_id IS NULL)),
    ADD CHECK (length(btrim(source)) > 0 AND length(btrim(reference)) > 0 AND length(btrim(idempotency_key)) > 0),
    ADD CHECK (fingerprint ~ '^[a-f0-9]{64}$');
ALTER TABLE ledger_income_tax_events
    ADD CHECK (kind IN ('approve', 'reject', 'post', 'reverse')),
    ADD CHECK ((kind = 'reverse') = (reverses_id IS NOT NULL)),
    ADD CHECK (length(btrim(reason)) > 0 AND length(btrim(idempotency_key)) > 0 AND fingerprint ~ '^[a-f0-9]{64}$'),
    ADD CHECK (jsonb_typeof(evidence) = 'object');

CREATE OR REPLACE FUNCTION ledger_income_tax_minor(value jsonb, signed boolean DEFAULT false) RETURNS numeric
LANGUAGE plpgsql IMMUTABLE SET search_path FROM CURRENT AS $body$
BEGIN
    IF jsonb_typeof(value) IS DISTINCT FROM 'string' OR (value #>> '{}') !~ (CASE WHEN signed THEN '^-?[0-9]{1,38}$' ELSE '^[0-9]{1,38}$' END) THEN
        RAISE EXCEPTION 'Income tax amounts require exact bounded minor-unit strings';
    END IF;
    RETURN (value #>> '{}')::numeric;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_income_tax_policy(tenant uuid, book uuid, configuration uuid, starts date, ends date) RETURNS jsonb
LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE c ledger_configurations; rules jsonb; item record; role jsonb; account uuid; current_account uuid; payable uuid; tax_accounts jsonb; ids jsonb; resolved jsonb := '{}'; all_accounts jsonb := '[]';
BEGIN
    SELECT * INTO STRICT c FROM ledger_configurations WHERE tenant_id = tenant AND book_id = book AND id = configuration;
    rules := c.definition_json->'settings'->'income_tax';
    IF rules->'loss_extensions' = '[]'::jsonb THEN rules := jsonb_set(rules, '{loss_extensions}', '{}'); END IF;
    IF (c.definition_json->>'valid_from')::date > starts OR coalesce((c.definition_json->>'valid_until')::date, '9999-12-31') < ends
        OR jsonb_typeof(rules) IS DISTINCT FROM 'object'
        OR NOT (rules ?& ARRAY['version', 'separate_reviewer', 'loss_extensions', 'sources'])
        OR EXISTS (SELECT 1 FROM jsonb_object_keys(rules) k WHERE k NOT IN ('version', 'separate_reviewer', 'loss_extensions', 'sources'))
        OR jsonb_typeof(rules->'version') IS DISTINCT FROM 'string' OR length(btrim(rules->>'version')) NOT BETWEEN 1 AND 128
        OR jsonb_typeof(rules->'separate_reviewer') IS DISTINCT FROM 'boolean' OR jsonb_typeof(rules->'loss_extensions') IS DISTINCT FROM 'object'
        OR jsonb_typeof(rules->'sources') IS DISTINCT FROM 'object' OR rules->'sources' = '{}'::jsonb THEN
        RAISE EXCEPTION 'Income tax requires an explicit complete effective rule set';
    END IF;
    current_account := (c.roles->>'tax.income.current')::uuid; payable := (c.roles->>'tax.income.payable')::uuid;
    IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = current_account AND type = 'expense' AND NOT is_group AND NOT is_control)
        OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = payable AND type = 'liability' AND NOT is_group AND NOT is_control) THEN
        RAISE EXCEPTION 'Income tax requires current expense and payable liability posting roles';
    END IF;
    SELECT coalesce(jsonb_agg(DISTINCT a.id::text), '[]') INTO tax_accounts FROM jsonb_each_text(c.roles) r
        JOIN ledger_accounts a ON a.tenant_id = tenant AND a.book_id = book AND a.id = r.value::uuid
        WHERE starts_with(r.key, 'tax.income.') AND a.type IN ('income', 'expense');
    FOR item IN SELECT key, value FROM jsonb_each(rules->'sources') LOOP
        IF length(btrim(item.key)) NOT BETWEEN 1 AND 64 OR jsonb_typeof(item.value) IS DISTINCT FROM 'object'
            OR NOT (item.value ?& ARRAY['rate', 'rounding', 'loss_years', 'loss_sources', 'profit_roles'])
            OR EXISTS (SELECT 1 FROM jsonb_object_keys(item.value) k WHERE k NOT IN ('rate', 'rounding', 'loss_years', 'loss_sources', 'profit_roles'))
            OR jsonb_typeof(item.value->'rate') IS DISTINCT FROM 'string' OR (item.value->>'rate') !~ '^(0|1)(\.[0-9]{1,18})?$' OR (item.value->>'rate')::numeric > 1
            OR jsonb_typeof(item.value->'rounding') IS DISTINCT FROM 'string' OR (item.value->>'rounding') NOT IN ('half_even', 'half_up', 'down')
            OR jsonb_typeof(item.value->'loss_sources') IS DISTINCT FROM 'array'
            OR jsonb_typeof(item.value->'profit_roles') IS DISTINCT FROM 'array' OR jsonb_array_length(item.value->'profit_roles') NOT BETWEEN 1 AND 10000 THEN
            RAISE EXCEPTION 'Each income source requires an explicit rate, rounding, loss carry-forward, eligible loss sources and profit roles'; END IF;
        IF item.value->'loss_years' <> 'null'::jsonb AND (jsonb_typeof(item.value->'loss_years') <> 'number' OR (item.value->>'loss_years') !~ '^[0-9]{1,3}$') THEN
            RAISE EXCEPTION 'Loss carry-forward years must be an integer from zero to 999 or explicit null'; END IF;
        IF EXISTS (SELECT 1 FROM jsonb_array_elements(item.value->'loss_sources') v WHERE jsonb_typeof(v) <> 'string' OR length(btrim(v #>> '{}')) NOT BETWEEN 1 AND 64) THEN RAISE EXCEPTION 'Invalid eligible loss source'; END IF;
        ids := '[]';
        FOR role IN SELECT * FROM jsonb_array_elements(item.value->'profit_roles') LOOP
            IF jsonb_typeof(role) <> 'string' THEN RAISE EXCEPTION 'Profit roles must be strings'; END IF;
            account := (c.roles->>(role #>> '{}'))::uuid;
            IF account IS NULL OR tax_accounts ? account::text OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = account AND type IN ('income', 'expense') AND NOT is_group)
                OR all_accounts ? account::text THEN RAISE EXCEPTION 'Profit roles require distinct earnings accounts excluding income tax expense'; END IF;
            ids := ids || jsonb_build_array(account::text);
            all_accounts := all_accounts || jsonb_build_array(account::text);
        END LOOP;
        resolved := resolved || jsonb_build_object(item.key, ids);
    END LOOP;
    FOR item IN SELECT key, value FROM jsonb_each(rules->'loss_extensions') LOOP
        PERFORM item.key::uuid;
        IF item.key <> item.key::uuid::text OR jsonb_typeof(item.value) <> 'object' OR jsonb_typeof(item.value->'through') IS DISTINCT FROM 'string'
            OR (item.value->>'through') !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' OR (item.value->>'through')::date IS NULL
            OR jsonb_typeof(item.value->'reference') IS DISTINCT FROM 'string' OR length(btrim(item.value->>'reference')) NOT BETWEEN 1 AND 256 THEN
            RAISE EXCEPTION 'A loss extension requires an explicit expiry date and reviewed reference'; END IF;
    END LOOP;
    RETURN rules || jsonb_build_object('profit_accounts', resolved, 'tax_expense_accounts', tax_accounts, 'expense_account_id', current_account::text, 'payable_account_id', payable::text,
        'configuration_hash', c.definition_hash, 'configuration_name', c.name, 'configuration_version', c.version);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_income_tax_active(tenant uuid, book uuid, cutoff bigint, as_of date)
RETURNS SETOF ledger_income_tax_computations LANGUAGE sql STABLE SET search_path FROM CURRENT AS $body$
    SELECT c.* FROM ledger_income_tax_computations c JOIN ledger_income_tax_events e ON e.tenant_id = c.tenant_id AND e.book_id = c.book_id AND e.computation_id = c.id AND e.kind = 'post'
    WHERE c.tenant_id = tenant AND c.book_id = book AND e.sequence <= cutoff AND e.effective_on <= as_of
        AND NOT EXISTS (SELECT 1 FROM ledger_income_tax_events r WHERE r.tenant_id = e.tenant_id AND r.book_id = e.book_id
            AND r.reverses_id = e.id AND r.sequence <= cutoff AND r.effective_on <= as_of)
$body$;

CREATE OR REPLACE FUNCTION ledger_income_tax_profit(tenant uuid, book uuid, starts date, ends date, cutoff bigint, accounts jsonb)
RETURNS numeric LANGUAGE sql STABLE SET search_path FROM CURRENT AS $body$
    SELECT coalesce(sum(CASE WHEN p.side = 'credit' THEN p.functional_minor ELSE -p.functional_minor END), 0)
    FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
    WHERE p.tenant_id = tenant AND p.book_id = book AND accounts ? p.account_id::text AND j.sequence <= cutoff AND NOT j.closing
        AND coalesce(j.source_type, '') NOT IN ('ledger.income_tax', 'ledger.deferred_tax')
        AND NOT EXISTS (SELECT 1 FROM ledger_journals original WHERE original.tenant_id = tenant AND original.book_id = book AND original.id = j.reverses_id AND original.source_type IN ('ledger.income_tax', 'ledger.deferred_tax'))
        AND j.effective_on BETWEEN starts AND ends AND coalesce(p.reporting_movement->>'kind', '') NOT IN ('oci', 'oci_opening', 'basis_adjustment', 'opening_balance', 'opening_adjustment', 'earnings_transfer')
$body$;

CREATE OR REPLACE FUNCTION ledger_income_tax_loss_in_use(tenant uuid, book uuid, loss uuid, from_date date) RETURNS boolean
LANGUAGE sql STABLE SET search_path FROM CURRENT AS $body$
    SELECT EXISTS (
        SELECT 1 FROM ledger_income_tax_computations c JOIN ledger_income_tax_events p
            ON p.tenant_id = c.tenant_id AND p.book_id = c.book_id AND p.computation_id = c.id AND p.kind = 'post'
        LEFT JOIN ledger_income_tax_events r ON r.tenant_id = p.tenant_id AND r.book_id = p.book_id AND r.reverses_id = p.id
        WHERE c.tenant_id = tenant AND c.book_id = book AND c.inputs->'losses' ? loss::text
            AND (r.id IS NULL OR r.effective_on > greatest(from_date, p.effective_on))
    )
$body$;

CREATE OR REPLACE FUNCTION ledger_income_tax_capacity(tenant uuid, book uuid, loss uuid, credit_journal uuid, credit_line integer, amount numeric, date_arg date)
RETURNS void LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE capacity numeric;
BEGIN
    IF loss IS NOT NULL THEN SELECT amount_minor INTO STRICT capacity FROM ledger_income_tax_losses WHERE tenant_id = tenant AND book_id = book AND id = loss;
    ELSE SELECT functional_minor INTO STRICT capacity FROM ledger_postings WHERE tenant_id = tenant AND book_id = book AND journal_id = credit_journal AND line_no = credit_line;
    END IF;
    IF EXISTS (
        WITH changes AS (
            SELECT e.effective_on, CASE WHEN e.kind = 'post' THEN 1 ELSE -1 END * CASE WHEN loss IS NOT NULL THEN coalesce((c.inputs->'losses'->>loss::text)::numeric, 0)
                ELSE coalesce((SELECT sum((v->>'amount_minor')::numeric) FROM jsonb_array_elements(c.inputs->'credits') v
                    WHERE (v->>'journal_id')::uuid = credit_journal AND (v->>'line_no')::integer = credit_line), 0) END AS delta
            FROM ledger_income_tax_computations c JOIN ledger_income_tax_events e ON e.tenant_id = c.tenant_id AND e.book_id = c.book_id AND e.computation_id = c.id
            WHERE c.tenant_id = tenant AND c.book_id = book AND e.kind IN ('post', 'reverse')
            UNION ALL SELECT date_arg, amount
        ), by_date AS (SELECT effective_on, sum(delta) AS delta FROM changes GROUP BY effective_on),
        balances AS (SELECT sum(delta) OVER (ORDER BY effective_on) AS balance FROM by_date)
        SELECT 1 FROM balances WHERE balance < 0 OR balance > capacity
    ) THEN RAISE EXCEPTION 'Income tax allocation exceeds capacity at an effective-date cutoff'; END IF;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_income_tax_unmapped(tenant uuid, book uuid, starts date, ends date, cutoff bigint, rules jsonb)
RETURNS boolean LANGUAGE sql STABLE SET search_path FROM CURRENT AS $body$
    SELECT EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
        JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
        WHERE p.tenant_id = tenant AND p.book_id = book AND j.sequence <= cutoff AND j.effective_on BETWEEN starts AND ends AND NOT j.closing
            AND a.type IN ('income', 'expense') AND NOT (rules->'tax_expense_accounts' ? p.account_id::text)
            AND coalesce(j.source_type, '') NOT IN ('ledger.income_tax', 'ledger.deferred_tax')
            AND NOT EXISTS (SELECT 1 FROM ledger_journals original WHERE original.tenant_id = tenant AND original.book_id = book AND original.id = j.reverses_id AND original.source_type IN ('ledger.income_tax', 'ledger.deferred_tax'))
            AND coalesce(p.reporting_movement->>'kind', '') NOT IN ('oci', 'oci_opening', 'basis_adjustment', 'opening_balance', 'opening_adjustment', 'earnings_transfer')
            AND NOT EXISTS (SELECT 1 FROM jsonb_each(rules->'profit_accounts') x WHERE x.value ? p.account_id::text))
$body$;

CREATE OR REPLACE FUNCTION ledger_income_tax_calculate(tenant uuid, book uuid, starts date, ends date, cutoff bigint, source_arg text, rules jsonb, data jsonb)
RETURNS jsonb LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE profit numeric; adjusted numeric; permanent numeric := 0; temporary numeric := 0; allowances numeric := 0; used numeric := 0; credits numeric := 0;
    value jsonb; allocation record; amount numeric; consumed numeric; loss ledger_income_tax_losses; expiry date; extension jsonb; posting ledger_postings; journal ledger_journals;
    charge numeric; references_seen text[] := '{}'; identities text[] := '{}'; identity text; maximum_sequence bigint; source_rules jsonb;
BEGIN
    IF data->'losses' = '[]'::jsonb THEN data := jsonb_set(data, '{losses}', '{}'); END IF;
    SELECT next_sequence INTO STRICT maximum_sequence FROM ledger_books WHERE tenant_id = tenant AND id = book;
    IF cutoff < 0 OR cutoff > maximum_sequence OR starts > ends OR NOT (rules->'profit_accounts' ? source_arg) THEN RAISE EXCEPTION 'Invalid income tax year, source or knowledge cutoff'; END IF;
    source_rules := rules->'sources'->source_arg;
    IF jsonb_typeof(data) IS DISTINCT FROM 'object' OR NOT (data ?& ARRAY['adjustments', 'losses', 'credits', 'basis_reference'])
        OR EXISTS (SELECT 1 FROM jsonb_object_keys(data) k WHERE k NOT IN ('adjustments', 'losses', 'credits', 'basis_reference'))
        OR jsonb_typeof(data->'adjustments') IS DISTINCT FROM 'array' OR jsonb_array_length(data->'adjustments') > 10000
        OR jsonb_typeof(data->'losses') IS DISTINCT FROM 'object' OR jsonb_typeof(data->'credits') IS DISTINCT FROM 'array' OR jsonb_array_length(data->'credits') > 10000
        OR jsonb_typeof(data->'basis_reference') IS DISTINCT FROM 'string' OR length(btrim(data->>'basis_reference')) NOT BETWEEN 1 AND 256 THEN RAISE EXCEPTION 'Income tax inputs require bounded adjustments, losses, credits and reviewed basis'; END IF;
    IF ledger_income_tax_unmapped(tenant, book, starts, ends, cutoff, rules) THEN RAISE EXCEPTION 'Income tax profit roles leave earnings activity unmapped'; END IF;
    profit := ledger_income_tax_profit(tenant, book, starts, ends, cutoff, rules->'profit_accounts'->source_arg);
    FOR value IN SELECT * FROM jsonb_array_elements(data->'adjustments') LOOP
        IF jsonb_typeof(value) <> 'object' OR NOT (value ?& ARRAY['kind', 'reference', 'amount_minor'])
            OR EXISTS (SELECT 1 FROM jsonb_object_keys(value) k WHERE k NOT IN ('kind', 'reference', 'amount_minor'))
            OR coalesce(value->>'kind', '') NOT IN ('permanent', 'temporary', 'capital_allowance') OR jsonb_typeof(value->'reference') IS DISTINCT FROM 'string'
            OR length(btrim(value->>'reference')) NOT BETWEEN 1 AND 256 OR value->>'reference' = ANY(references_seen) THEN RAISE EXCEPTION 'Adjustments require distinct reviewed references and explicit kinds'; END IF;
        references_seen := array_append(references_seen, value->>'reference');
        amount := ledger_income_tax_minor(value->'amount_minor', true);
        IF value->>'kind' = 'permanent' THEN permanent := permanent + amount;
        ELSIF value->>'kind' = 'temporary' THEN temporary := temporary + amount;
        ELSE
            IF amount > 0 THEN RAISE EXCEPTION 'Capital allowances are deductions; use temporary adjustments for add-backs'; END IF;
            allowances := allowances + amount;
        END IF;
    END LOOP;
    adjusted := profit + permanent + temporary + allowances;
    FOR allocation IN SELECT x.key, x.value FROM jsonb_each(data->'losses') x LOOP
        amount := ledger_income_tax_minor(allocation.value);
        IF amount <= 0 THEN RAISE EXCEPTION 'Loss usage must be positive'; END IF;
        SELECT * INTO STRICT loss FROM ledger_income_tax_losses WHERE tenant_id = tenant AND book_id = book AND id = allocation.key::uuid AND sequence <= cutoff;
        IF allocation.key <> loss.id::text THEN RAISE EXCEPTION 'Loss identities must be canonical lowercase UUIDs'; END IF;
        IF loss.reverses_id IS NOT NULL OR EXISTS (SELECT 1 FROM ledger_income_tax_losses r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = loss.id) THEN RAISE EXCEPTION 'Opening tax loss has been corrected'; END IF;
        IF loss.vintage_on >= starts OR NOT (source_rules->'loss_sources' @> jsonb_build_array(loss.source)) THEN RAISE EXCEPTION 'Loss is not eligible for this vintage or income source'; END IF;
        expiry := CASE WHEN source_rules->'loss_years' = 'null'::jsonb THEN '9999-12-31'::date ELSE (loss.vintage_on + make_interval(years => (source_rules->>'loss_years')::integer))::date END;
        extension := rules->'loss_extensions'->allocation.key;
        IF extension IS NOT NULL THEN expiry := greatest(expiry, (extension->>'through')::date); END IF;
        IF ends > expiry THEN RAISE EXCEPTION 'Loss has expired under the pinned rule set'; END IF;
        IF loss.computation_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_income_tax_active(tenant, book, cutoff, '9999-12-31') c WHERE c.id = loss.computation_id) THEN RAISE EXCEPTION 'Originating tax loss has been corrected'; END IF;
        SELECT coalesce(sum((c.inputs->'losses'->>allocation.key)::numeric), 0) INTO consumed FROM ledger_income_tax_active(tenant, book, 9223372036854775807, '9999-12-31') c;
        IF consumed + amount > loss.amount_minor THEN RAISE EXCEPTION 'Tax loss usage exceeds remaining vintage balance'; END IF;
        used := used + amount;
    END LOOP;
    IF used > greatest(adjusted, 0) THEN RAISE EXCEPTION 'Loss usage exceeds positive adjusted profit'; END IF;
    FOR value IN SELECT * FROM jsonb_array_elements(data->'credits') LOOP
        IF jsonb_typeof(value) <> 'object' OR NOT (value ?& ARRAY['journal_id', 'line_no', 'amount_minor', 'kind', 'reference'])
            OR EXISTS (SELECT 1 FROM jsonb_object_keys(value) k WHERE k NOT IN ('journal_id', 'line_no', 'amount_minor', 'kind', 'reference'))
            OR coalesce(value->>'kind', '') NOT IN ('instalment', 'withholding', 'other') OR jsonb_typeof(value->'reference') IS DISTINCT FROM 'string'
            OR length(btrim(value->>'reference')) NOT BETWEEN 1 AND 256 OR jsonb_typeof(value->'line_no') IS DISTINCT FROM 'number'
            OR coalesce(value->>'line_no', '') !~ '^[1-9][0-9]{0,4}$' OR jsonb_typeof(value->'journal_id') IS DISTINCT FROM 'string'
            OR value->>'journal_id' <> (value->>'journal_id')::uuid::text THEN RAISE EXCEPTION 'Credits require exact posting identities and reviewed classifications'; END IF;
        amount := ledger_income_tax_minor(value->'amount_minor'); identity := (value->>'journal_id') || ':' || (value->>'line_no');
        IF amount <= 0 OR identity = ANY(identities) THEN RAISE EXCEPTION 'Credits must be positive and cannot repeat a posting'; END IF;
        identities := array_append(identities, identity);
        SELECT * INTO STRICT posting FROM ledger_postings WHERE tenant_id = tenant AND book_id = book AND journal_id = (value->>'journal_id')::uuid AND line_no = (value->>'line_no')::integer;
        SELECT * INTO STRICT journal FROM ledger_journals WHERE tenant_id = tenant AND book_id = book AND id = posting.journal_id;
        IF posting.account_id::text <> rules->>'payable_account_id' OR posting.side <> 'debit' OR journal.closing OR journal.reverses_id IS NOT NULL
            OR journal.sequence > cutoff OR journal.effective_on NOT BETWEEN starts AND ends OR journal.source_type = 'ledger.income_tax'
            OR EXISTS (SELECT 1 FROM ledger_journals r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = journal.id) THEN RAISE EXCEPTION 'Credit must reference an unreversed booked tax payment in the computation year'; END IF;
        SELECT coalesce(sum((v->>'amount_minor')::numeric), 0) INTO consumed FROM ledger_income_tax_active(tenant, book, 9223372036854775807, '9999-12-31') c,
            jsonb_array_elements(c.inputs->'credits') x(v) WHERE v->>'journal_id' = value->>'journal_id' AND v->>'line_no' = value->>'line_no';
        IF consumed + amount > posting.functional_minor THEN RAISE EXCEPTION 'Income tax credit exceeds unclaimed booked payment'; END IF;
        credits := credits + amount;
    END LOOP;
    charge := ledger_fraction(greatest(adjusted - used, 0) * (source_rules->>'rate')::numeric, 1, source_rules->>'rounding');
    IF greatest(abs(profit), abs(adjusted), abs(permanent), abs(temporary), abs(allowances), used, credits, charge, abs(charge - credits)) >= power(10::numeric, 38) THEN RAISE EXCEPTION 'Income tax totals exceed storage bounds'; END IF;
    RETURN jsonb_build_object('accounting_profit_minor', profit::text, 'permanent_minor', permanent::text, 'temporary_minor', temporary::text,
        'capital_allowances_minor', allowances::text, 'adjusted_profit_minor', adjusted::text, 'loss_used_minor', used::text,
        'taxable_profit_minor', greatest(adjusted - used, 0)::text, 'tax_loss_minor', greatest(-adjusted, 0)::text,
        'charge_minor', charge::text, 'credits_minor', credits::text, 'payable_minor', (charge - credits)::text);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_income_tax_prepare() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id);
    IF NEW.inputs->'losses' = '[]'::jsonb THEN NEW.inputs := jsonb_set(NEW.inputs, '{losses}', '{}'); END IF;
    NEW.policy := ledger_income_tax_policy(NEW.tenant_id, NEW.book_id, NEW.configuration_id, NEW.starts_on, NEW.ends_on);
    NEW.expense_account_id := (NEW.policy->>'expense_account_id')::uuid;
    NEW.payable_account_id := (NEW.policy->>'payable_account_id')::uuid;
    NEW.result := ledger_income_tax_calculate(NEW.tenant_id, NEW.book_id, NEW.starts_on, NEW.ends_on, NEW.through_sequence, NEW.source, NEW.policy, NEW.inputs);
    NEW.result_hash := encode(sha256(convert_to(NEW.result::text, 'UTF8')), 'hex');
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_income_tax_stamp() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    IF TG_TABLE_NAME = 'ledger_income_tax_events' AND to_jsonb(NEW)->>'journal_id' IS NOT NULL THEN
        SELECT sequence INTO STRICT NEW.sequence FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = (to_jsonb(NEW)->>'journal_id')::uuid;
    ELSIF TG_TABLE_NAME = 'ledger_income_tax_losses' AND to_jsonb(NEW)->>'computation_id' IS NOT NULL THEN
        SELECT sequence INTO STRICT NEW.sequence FROM ledger_income_tax_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND computation_id = (to_jsonb(NEW)->>'computation_id')::uuid AND kind = 'post';
    ELSE
        UPDATE ledger_books SET next_sequence = next_sequence + 1 WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id RETURNING next_sequence INTO STRICT NEW.sequence;
    END IF;
    NEW.recorded_at := clock_timestamp(); NEW.actor_id := current_setting('noria.ledger_actor'); NEW.firm_id := nullif(current_setting('noria.ledger_firm', true), '');
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_income_tax_loss_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE c ledger_income_tax_computations; original ledger_income_tax_losses;
BEGIN
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id, NEW.reverses_id IS NOT NULL);
    IF NEW.reverses_id IS NOT NULL THEN
        SELECT * INTO STRICT original FROM ledger_income_tax_losses WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
        IF original.reverses_id IS NOT NULL OR original.computation_id IS NOT NULL OR NEW.computation_id IS NOT NULL OR NEW.source <> original.source
            OR NEW.amount_minor <> -original.amount_minor OR NEW.vintage_on < original.vintage_on THEN RAISE EXCEPTION 'Opening loss correction must exactly reverse its original'; END IF;
        IF ledger_income_tax_loss_in_use(NEW.tenant_id, NEW.book_id, original.id, NEW.vintage_on) THEN
            RAISE EXCEPTION 'Correct active tax loss usage before its opening vintage'; END IF;
    END IF;
    IF NEW.computation_id IS NOT NULL THEN
        SELECT * INTO STRICT c FROM ledger_income_tax_active(NEW.tenant_id, NEW.book_id, 9223372036854775807, '9999-12-31') a WHERE a.id = NEW.computation_id;
        IF NEW.amount_minor <> (c.result->>'tax_loss_minor')::numeric OR NEW.source <> c.source OR NEW.vintage_on <> c.ends_on THEN RAISE EXCEPTION 'Generated tax loss must match its posted computation'; END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_income_tax_event_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE c ledger_income_tax_computations; original ledger_income_tax_events; j ledger_journals; current_result jsonb; cutoff bigint; line jsonb; allocation record;
BEGIN
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id, NEW.kind = 'reverse');
    SELECT * INTO STRICT c FROM ledger_income_tax_computations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.computation_id;
    IF NEW.kind IN ('approve', 'reject') THEN
        IF NEW.journal_id IS NOT NULL OR EXISTS (SELECT 1 FROM ledger_income_tax_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND computation_id = c.id) THEN RAISE EXCEPTION 'Income tax review is final and precedes posting'; END IF;
        IF (c.policy->>'separate_reviewer')::boolean AND NEW.actor_id = c.actor_id THEN RAISE EXCEPTION 'Income tax requires a separate reviewer'; END IF;
    ELSIF NEW.kind = 'post' THEN
        IF NEW.effective_on < c.ends_on OR NOT EXISTS (SELECT 1 FROM ledger_income_tax_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND computation_id = c.id AND kind = 'approve' AND effective_on <= NEW.effective_on) THEN RAISE EXCEPTION 'Income tax posting requires prior approval and cannot precede year end'; END IF;
        IF EXISTS (SELECT 1 FROM ledger_income_tax_computations a JOIN ledger_income_tax_events p ON p.tenant_id = a.tenant_id AND p.book_id = a.book_id AND p.computation_id = a.id AND p.kind = 'post'
            LEFT JOIN ledger_income_tax_events r ON r.tenant_id = p.tenant_id AND r.book_id = p.book_id AND r.reverses_id = p.id
            WHERE a.tenant_id = NEW.tenant_id AND a.book_id = NEW.book_id AND a.source = c.source AND a.starts_on <= c.ends_on AND a.ends_on >= c.starts_on
                AND (r.id IS NULL OR r.effective_on > greatest(NEW.effective_on, p.effective_on))) THEN RAISE EXCEPTION 'Income tax year and source already have an active computation at an effective-date cutoff'; END IF;
        SELECT next_sequence INTO STRICT cutoff FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id;
        current_result := ledger_income_tax_calculate(NEW.tenant_id, NEW.book_id, c.starts_on, c.ends_on, cutoff, c.source, c.policy, c.inputs);
        IF current_result <> c.result THEN RAISE EXCEPTION 'Income tax computation is stale; prepare and review a replacement'; END IF;
        FOR allocation IN SELECT x.key, x.value FROM jsonb_each_text(c.inputs->'losses') x LOOP
            IF EXISTS (SELECT 1 FROM ledger_income_tax_losses l WHERE l.tenant_id = NEW.tenant_id AND l.book_id = NEW.book_id AND l.id = allocation.key::uuid AND l.computation_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM ledger_income_tax_active(NEW.tenant_id, NEW.book_id, 9223372036854775807, NEW.effective_on) a WHERE a.id = l.computation_id)) THEN
                RAISE EXCEPTION 'A generated tax loss cannot be used before its originating computation is posted'; END IF;
            PERFORM ledger_income_tax_capacity(NEW.tenant_id, NEW.book_id, allocation.key::uuid, NULL, NULL, allocation.value::numeric, NEW.effective_on);
        END LOOP;
        FOR line IN SELECT * FROM jsonb_array_elements(c.inputs->'credits') LOOP
            PERFORM ledger_income_tax_capacity(NEW.tenant_id, NEW.book_id, NULL, (line->>'journal_id')::uuid, (line->>'line_no')::integer, (line->>'amount_minor')::numeric, NEW.effective_on);
        END LOOP;
        IF (c.result->>'charge_minor')::numeric = 0 THEN
            IF NEW.journal_id IS NOT NULL THEN RAISE EXCEPTION 'Zero current tax has no charge journal'; END IF;
        ELSE
            SELECT * INTO STRICT j FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id;
            IF j.source_type IS DISTINCT FROM 'ledger.income_tax' OR j.source_reference IS DISTINCT FROM c.id::text OR j.reverses_id IS NOT NULL OR j.closing
                OR j.effective_on <> NEW.effective_on OR jsonb_array_length(j.postings) <> 2 THEN RAISE EXCEPTION 'Current tax journal must match its computation'; END IF;
            FOR line IN SELECT * FROM jsonb_array_elements(j.postings) LOOP
                IF (line->>'functional_minor')::numeric <> (c.result->>'charge_minor')::numeric OR line->'dimensions' <> '{}'::jsonb OR line->>'party_id' IS NOT NULL
                    OR (line->>'account_id', line->>'side') NOT IN ((c.expense_account_id::text, 'debit'), (c.payable_account_id::text, 'credit')) THEN RAISE EXCEPTION 'Current tax charge postings do not match the approved computation'; END IF;
            END LOOP;
        END IF;
    ELSE
        SELECT * INTO STRICT original FROM ledger_income_tax_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
        IF original.kind <> 'post' OR original.computation_id <> c.id OR NEW.effective_on < original.effective_on THEN RAISE EXCEPTION 'Income tax correction must reverse its original posting on or after its date'; END IF;
        IF original.journal_id IS NULL THEN
            IF NEW.journal_id IS NOT NULL THEN RAISE EXCEPTION 'Zero tax correction has no journal'; END IF;
        ELSE
            SELECT * INTO STRICT j FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id;
            IF j.reverses_id IS DISTINCT FROM original.journal_id OR j.effective_on <> NEW.effective_on THEN RAISE EXCEPTION 'Income tax correction requires the exact reversing journal'; END IF;
        END IF;
        IF EXISTS (SELECT 1 FROM ledger_income_tax_losses l WHERE l.tenant_id = NEW.tenant_id AND l.book_id = NEW.book_id AND l.computation_id = c.id
            AND ledger_income_tax_loss_in_use(NEW.tenant_id, NEW.book_id, l.id, NEW.effective_on)) THEN RAISE EXCEPTION 'Correct dependent loss usage before its originating computation'; END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_income_tax_project_loss() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE c ledger_income_tax_computations;
BEGIN
    IF NEW.kind = 'post' THEN
        SELECT * INTO STRICT c FROM ledger_income_tax_computations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.computation_id;
        IF (c.result->>'tax_loss_minor')::numeric > 0 THEN
            INSERT INTO ledger_income_tax_losses (tenant_id, book_id, idempotency_key, fingerprint, source, vintage_on, amount_minor, reference, computation_id)
            VALUES (NEW.tenant_id, NEW.book_id, 'computation:' || c.id, c.fingerprint, c.source, c.ends_on, (c.result->>'tax_loss_minor')::numeric, NEW.reason, c.id);
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_income_tax_reversal() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE original ledger_income_tax_events;
BEGIN
    IF NEW.reverses_id IS NOT NULL THEN
        SELECT * INTO original FROM ledger_income_tax_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.reverses_id AND kind = 'post';
        IF FOUND THEN
            INSERT INTO ledger_income_tax_events (tenant_id, book_id, computation_id, idempotency_key, fingerprint, kind, effective_on, reason, evidence, journal_id, reverses_id)
            VALUES (NEW.tenant_id, NEW.book_id, original.computation_id, 'journal:' || NEW.id, NEW.fingerprint, 'reverse', NEW.effective_on, NEW.description, '{}', NEW.id, original.id);
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_income_tax_source_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    IF NEW.source_type = 'ledger.income_tax' AND current_setting('noria.ledger_income_tax', true) IS DISTINCT FROM NEW.source_reference THEN
        RAISE EXCEPTION 'Income tax journals are posted only by the income tax service';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_income_tax_checklist(tenant uuid, book uuid, starts date, ends date) RETURNS TABLE(item text)
LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE configuration ledger_configurations; source_arg text; c ledger_income_tax_computations; cutoff bigint;
BEGIN
    SELECT next_sequence INTO STRICT cutoff FROM ledger_books WHERE tenant_id = tenant AND id = book;
    SELECT * INTO configuration FROM ledger_configurations WHERE tenant_id = tenant AND book_id = book
        AND definition_json->'settings' ? 'income_tax' AND (definition_json->>'valid_from')::date <= starts
        AND coalesce((definition_json->>'valid_until')::date, '9999-12-31') >= ends ORDER BY sequence DESC LIMIT 1;
    IF NOT FOUND THEN RETURN; END IF;
    FOR source_arg IN SELECT jsonb_object_keys(configuration.definition_json->'settings'->'income_tax'->'sources') LOOP
        SELECT * INTO c FROM ledger_income_tax_active(tenant, book, cutoff, ends) a WHERE a.starts_on = starts AND a.ends_on = ends AND a.source = source_arg;
        IF NOT FOUND THEN item := source_arg || ':missing_reviewed_computation'; RETURN NEXT; CONTINUE; END IF;
        IF ledger_income_tax_unmapped(tenant, book, starts, ends, cutoff, c.policy) THEN item := source_arg || ':unmapped_earnings'; RETURN NEXT; END IF;
        IF ledger_income_tax_profit(tenant, book, starts, ends, cutoff, c.policy->'profit_accounts'->c.source) <> (c.result->>'accounting_profit_minor')::numeric THEN
            item := source_arg || ':stale_accounting_profit'; RETURN NEXT;
        END IF;
        IF EXISTS (SELECT 1 FROM jsonb_array_elements(c.inputs->'credits') v JOIN ledger_journals r ON r.tenant_id = tenant AND r.book_id = book
            AND r.reverses_id = (v->>'journal_id')::uuid AND r.sequence <= cutoff AND r.effective_on <= ends) THEN
            item := source_arg || ':reversed_tax_payment'; RETURN NEXT;
        END IF;
    END LOOP;
END;
$body$;

DO $body$
DECLARE name text;
BEGIN
    FOREACH name IN ARRAY ARRAY['ledger_income_tax_computations', 'ledger_income_tax_losses', 'ledger_income_tax_events'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', name);
        EXECUTE format('CREATE POLICY ledger_tenant ON %I USING (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid) WITH CHECK (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid)', name);
        EXECUTE format('CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard()', name);
        EXECUTE format('CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable()', name);
        EXECUTE format('CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_immutable()', name);
        EXECUTE format('CREATE TRIGGER stage_30_stamp BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_income_tax_stamp()', name);
        EXECUTE format('CREATE TRIGGER stage_90_audit AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_audit()', name);
    END LOOP;
END;
$body$;
CREATE TRIGGER stage_40_validate BEFORE INSERT ON ledger_income_tax_computations FOR EACH ROW EXECUTE FUNCTION ledger_income_tax_prepare();
CREATE TRIGGER stage_40_validate BEFORE INSERT ON ledger_income_tax_losses FOR EACH ROW EXECUTE FUNCTION ledger_income_tax_loss_validate();
CREATE TRIGGER stage_40_validate BEFORE INSERT ON ledger_income_tax_events FOR EACH ROW EXECUTE FUNCTION ledger_income_tax_event_validate();
CREATE TRIGGER stage_80_project_loss AFTER INSERT ON ledger_income_tax_events FOR EACH ROW EXECUTE FUNCTION ledger_income_tax_project_loss();
CREATE TRIGGER stage_80_reverse_income_tax AFTER INSERT ON ledger_journals FOR EACH ROW EXECUTE FUNCTION ledger_income_tax_reversal();
CREATE TRIGGER stage_40_income_tax_source BEFORE INSERT ON ledger_journals FOR EACH ROW EXECUTE FUNCTION ledger_income_tax_source_guard();
