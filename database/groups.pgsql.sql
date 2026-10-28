ALTER TABLE ledger_associates
    ADD CHECK (ownership_fraction > 0 AND ownership_fraction < 1 AND ownership_fraction = trunc(ownership_fraction, 18)),
    ADD CHECK (cost_minor >= 0 AND cost_minor = trunc(cost_minor) AND cost_minor < power(10::numeric, 38)),
    ADD CHECK (fair_value_net_assets_minor = trunc(fair_value_net_assets_minor) AND abs(fair_value_net_assets_minor) < power(10::numeric, 38)),
    ADD CHECK (carrying_minor >= 0 AND carrying_minor = trunc(carrying_minor) AND carrying_minor < power(10::numeric, 38)),
    ADD CHECK (goodwill_minor >= 0 AND goodwill_minor = trunc(goodwill_minor) AND goodwill_minor < power(10::numeric, 38)),
    ADD CHECK (bargain_minor >= 0 AND bargain_minor = trunc(bargain_minor) AND bargain_minor < power(10::numeric, 38)),
    ADD CHECK (length(btrim(influence_basis)) > 0), ADD CHECK (dividend_cash_flow IN ('operating', 'investing')),
    ADD CHECK (jsonb_typeof(dimensions) = 'object');
ALTER TABLE ledger_associate_events
    ADD CHECK (kind IN ('profit', 'oci', 'dividend', 'impairment', 'recovery', 'obligation', 'settlement', 'cancellation', 'disposal', 'held_for_sale', 'increase')),
    ADD CHECK ((kind IN ('disposal', 'increase')) = (fraction IS NOT NULL)), ADD CHECK (fraction > 0 AND fraction <= 1 AND fraction = trunc(fraction, 18)),
    ADD CHECK ((kind = 'increase') = (net_assets_minor IS NOT NULL)), ADD CHECK (net_assets_minor = trunc(net_assets_minor) AND abs(net_assets_minor) < power(10::numeric, 38)),
    ADD CHECK ((retained_account_id IS NULL) = (retained_fair_value_minor IS NULL)),
    ADD CHECK (retained_fair_value_minor >= 0 AND retained_fair_value_minor = trunc(retained_fair_value_minor) AND retained_fair_value_minor < power(10::numeric, 38)),
    ADD CHECK (oci_release_minor = trunc(oci_release_minor) AND abs(oci_release_minor) < power(10::numeric, 38)),
    ADD CHECK (source_minor = trunc(source_minor) AND abs(source_minor) < power(10::numeric, 38)),
    ADD CHECK (share_minor = trunc(share_minor) AND abs(share_minor) < power(10::numeric, 38)),
    ADD CHECK (carrying_delta = trunc(carrying_delta) AND abs(carrying_delta) < power(10::numeric, 38)),
    ADD CHECK (liability_delta = trunc(liability_delta) AND abs(liability_delta) < power(10::numeric, 38)),
    ADD CHECK (unrecognized_profit_delta = trunc(unrecognized_profit_delta) AND abs(unrecognized_profit_delta) < power(10::numeric, 38)),
    ADD CHECK (unrecognized_oci_delta = trunc(unrecognized_oci_delta) AND abs(unrecognized_oci_delta) < power(10::numeric, 38)),
    ADD CHECK (impairment_delta = trunc(impairment_delta) AND abs(impairment_delta) < power(10::numeric, 38)),
    ADD CHECK (obligation_limit_minor >= 0 AND obligation_limit_minor = trunc(obligation_limit_minor) AND obligation_limit_minor < power(10::numeric, 38)),
    ADD CHECK (prior_limit_minor >= 0 AND prior_limit_minor = trunc(prior_limit_minor)),
    ADD CHECK (unimpaired_minor >= 0 AND unimpaired_minor = trunc(unimpaired_minor) AND unimpaired_minor < power(10::numeric, 38)),
    ADD CHECK (jsonb_typeof(evidence) = 'object'),
    ADD CHECK ((kind = 'recovery') = (unimpaired_minor IS NOT NULL)),
    ADD CHECK ((kind IN ('settlement', 'disposal', 'increase')) = (counter_account_id IS NOT NULL)), ADD CHECK (kind <> 'cancellation' OR source_minor = 0);

CREATE OR REPLACE FUNCTION ledger_associate_position(tenant uuid, book uuid, associate uuid, as_of date, through_sequence bigint)
RETURNS jsonb LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE definition ledger_associates%ROWTYPE; result jsonb; capacity numeric; ownership numeric; regime bigint := 0; disposal record; disposed boolean := false; held boolean := false; oci numeric;
BEGIN
    SELECT * INTO STRICT definition FROM ledger_associates WHERE tenant_id = tenant AND book_id = book AND id = associate;
    IF definition.effective_on > as_of OR definition.sequence > through_sequence THEN RAISE EXCEPTION 'Associate was not recognized at this snapshot'; END IF;
    SELECT jsonb_build_object('carrying_minor', (definition.carrying_minor + coalesce(sum(carrying_delta), 0))::text,
        'liability_minor', coalesce(sum(liability_delta), 0)::text, 'unrecognized_profit_minor', coalesce(sum(unrecognized_profit_delta), 0)::text,
        'unrecognized_oci_minor', coalesce(sum(unrecognized_oci_delta), 0)::text, 'impairment_minor', coalesce(sum(impairment_delta), 0)::text,
        'goodwill_minor', definition.goodwill_minor::text, 'bargain_minor', definition.bargain_minor::text,
        'cancelled', coalesce(bool_or(kind = 'cancellation' AND reverses_id IS NULL), false)) INTO result
        FROM ledger_associate_events WHERE tenant_id = tenant AND book_id = book AND associate_id = associate AND effective_on <= as_of AND sequence <= through_sequence;
    SELECT e.obligation_limit_minor INTO capacity FROM ledger_associate_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.associate_id = associate
        AND e.effective_on <= as_of AND e.sequence <= through_sequence AND e.reverses_id IS NULL AND NOT EXISTS (SELECT 1 FROM ledger_associate_events r
            WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id AND r.effective_on <= as_of AND r.sequence <= through_sequence)
        ORDER BY e.sequence DESC LIMIT 1;
    ownership := definition.ownership_fraction;
    FOR disposal IN SELECT e.kind, e.fraction, e.sequence FROM ledger_associate_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.associate_id = associate
        AND e.kind IN ('disposal', 'increase', 'held_for_sale') AND e.reverses_id IS NULL AND e.effective_on <= as_of AND e.sequence <= through_sequence
        AND NOT EXISTS (SELECT 1 FROM ledger_associate_events r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id AND r.effective_on <= as_of AND r.sequence <= through_sequence)
        ORDER BY e.sequence LOOP
        IF disposal.kind = 'held_for_sale' THEN
            held := true;
            CONTINUE;
        END IF;
        ownership := CASE WHEN disposal.kind = 'increase' THEN ownership + disposal.fraction ELSE ownership * (1 - disposal.fraction) END;
        regime := disposal.sequence;
        disposed := disposed OR (disposal.kind = 'disposal' AND disposal.fraction = 1);
    END LOOP;
    SELECT coalesce(sum(CASE WHEN p.side = 'credit' THEN p.functional_minor ELSE -p.functional_minor END), 0) INTO oci
        FROM ledger_associate_events e JOIN ledger_journals j ON j.tenant_id = e.tenant_id AND j.book_id = e.book_id AND j.id = e.journal_id
        JOIN ledger_postings p ON p.tenant_id = j.tenant_id AND p.book_id = j.book_id AND p.journal_id = j.id AND p.account_id = definition.oci_account_id
        WHERE e.tenant_id = tenant AND e.book_id = book AND e.associate_id = associate AND j.effective_on <= as_of AND j.sequence <= through_sequence;
    RETURN result || jsonb_build_object('obligation_limit_minor', coalesce(capacity, 0)::text, 'ownership_fraction', ownership::text,
        'regime_sequence', regime::text, 'disposed', disposed, 'held_for_sale', held, 'oci_minor', oci::text);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_associate_quote(tenant uuid, book uuid, associate uuid, date_arg date, operation text, amount numeric,
    obligation_limit numeric DEFAULT NULL, unimpaired numeric DEFAULT NULL, counter_arg uuid DEFAULT NULL, terms jsonb DEFAULT NULL)
RETURNS jsonb LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE definition ledger_associates%ROWTYPE; state jsonb; carry numeric; liability numeric; unprofit numeric; unoci numeric; impaired numeric; capacity numeric;
    net numeric; share numeric := 0; cd numeric := 0; ld numeric := 0; pd numeric := 0; od numeric := 0; idelta numeric := 0;
    total_source numeric; total_shares numeric; new_unrecognized numeric; difference numeric; used numeric; reverse_loss numeric := 0;
    rows jsonb := '[]'; counter uuid; functional_code text; portion numeric; retained_value numeric; removed numeric; release numeric := 0; outcome numeric;
BEGIN
    SELECT * INTO STRICT definition FROM ledger_associates WHERE tenant_id = tenant AND book_id = book AND id = associate;
    IF EXISTS (SELECT 1 FROM ledger_associate_events WHERE tenant_id = tenant AND book_id = book AND associate_id = associate AND effective_on > date_arg) THEN RAISE EXCEPTION 'Associate events must follow accounting-date order'; END IF;
    state := ledger_associate_position(tenant, book, associate, date_arg, 9223372036854775807);
    IF (state->>'cancelled')::boolean THEN RAISE EXCEPTION 'Associate registration was cancelled'; END IF;
    IF (state->>'disposed')::boolean THEN RAISE EXCEPTION 'Associate was disposed'; END IF;
    IF (state->>'held_for_sale')::boolean AND operation IN ('profit', 'oci', 'dividend', 'obligation', 'increase') THEN
        RAISE EXCEPTION 'Equity accounting stops while the associate is held for sale';
    END IF;
    carry := (state->>'carrying_minor')::numeric; liability := (state->>'liability_minor')::numeric;
    unprofit := (state->>'unrecognized_profit_minor')::numeric; unoci := (state->>'unrecognized_oci_minor')::numeric;
    impaired := (state->>'impairment_minor')::numeric;
    capacity := coalesce(obligation_limit, (state->>'obligation_limit_minor')::numeric);
    IF capacity < 0 OR (capacity > 0 AND definition.loss_liability_account_id IS NULL) THEN RAISE EXCEPTION 'Associate loss obligations require a nonnegative limit and an explicit liability account'; END IF;
    IF operation <> 'settlement' AND capacity < liability THEN RAISE EXCEPTION 'Settle recognized associate obligations before reducing their limit'; END IF;
    net := carry - liability - unprofit - unoci;
    IF operation IN ('profit', 'oci', 'dividend') THEN
        SELECT coalesce(sum(source_minor), 0), coalesce(sum(share_minor), 0) INTO total_source, total_shares FROM ledger_associate_events
            WHERE tenant_id = tenant AND book_id = book AND associate_id = associate AND kind = operation AND sequence > (state->>'regime_sequence')::bigint;
        share := ledger_fraction((total_source + amount) * (state->>'ownership_fraction')::numeric, 1, 'half_even') - total_shares;
        IF operation = 'dividend' THEN
            IF amount < 0 OR share > carry THEN RAISE EXCEPTION 'Associate distributions must be nonnegative and cannot exceed carrying value'; END IF;
            cd := -share;
            rows := ledger_asset_row(definition.dividend_account_id, share) || ledger_asset_row(definition.investment_account_id, -share);
        ELSE
            net := net + share;
        END IF;
    ELSIF operation = 'impairment' THEN
        IF amount < 0 OR amount >= carry THEN RAISE EXCEPTION 'Associate impairment requires a lower nonnegative recoverable amount'; END IF;
        idelta := carry - amount;
        cd := -idelta;
        rows := ledger_asset_row(definition.impairment_account_id, idelta) || ledger_asset_row(definition.investment_account_id, -idelta);
    ELSIF operation = 'recovery' THEN
        IF amount < 0 OR unimpaired IS NULL OR unimpaired < 0 OR impaired <= 0 THEN RAISE EXCEPTION 'Associate recovery requires recoverable and unimpaired values and prior impairment'; END IF;
        reverse_loss := least(impaired, least(amount, unimpaired, greatest(net + impaired, 0)) - net);
        IF reverse_loss <= 0 OR least(amount, unimpaired) <= carry THEN RAISE EXCEPTION 'Associate recovery must increase value within its unimpaired ceiling'; END IF;
        idelta := -reverse_loss;
        net := net + reverse_loss;
    ELSIF operation = 'obligation' THEN
        IF amount <> capacity THEN RAISE EXCEPTION 'Associate obligation amount must equal its explicit limit'; END IF;
    ELSIF operation = 'settlement' THEN
        IF amount <= 0 OR amount > liability THEN RAISE EXCEPTION 'Associate obligation settlement exceeds the recognized liability'; END IF;
        capacity := liability - amount;
        ld := -amount;
        counter := counter_arg;
        SELECT currency_code INTO STRICT functional_code FROM ledger_books WHERE tenant_id = tenant AND id = book;
        IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = counter AND type IN ('asset', 'liability') AND active
            AND NOT is_group AND NOT is_control AND deleted_at IS NULL AND coalesce(currency_code, functional_code) = functional_code
            AND id NOT IN (definition.investment_account_id, coalesce(definition.loss_liability_account_id, definition.investment_account_id))) THEN RAISE EXCEPTION 'Associate settlements require an explicit functional payment counter account'; END IF;
        rows := ledger_asset_row(definition.loss_liability_account_id, amount) || ledger_asset_row(counter, -amount);
    ELSIF operation = 'disposal' THEN
        portion := (terms->>'fraction')::numeric;
        retained_value := (terms->>'retained_fair_value_minor')::numeric;
        SELECT currency_code INTO STRICT functional_code FROM ledger_books WHERE tenant_id = tenant AND id = book;
        IF portion IS NULL OR portion <= 0 OR portion > 1 OR amount < 0 OR (retained_value IS NOT NULL AND (portion <> 1 OR retained_value < 0))
           OR (retained_value IS NULL) <> (terms->>'retained_account_id' IS NULL) THEN
            RAISE EXCEPTION 'Associate disposals sell a fraction of the holding; only a full derecognition retains an interest at fair value';
        END IF;
        IF liability <> 0 OR (portion < 1 AND (unprofit <> 0 OR unoci <> 0)) THEN
            RAISE EXCEPTION 'Settle associate obligations, and absorb suspended losses before a partial disposal';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = counter_arg AND type = 'asset' AND active AND NOT is_group
               AND NOT is_control AND deleted_at IS NULL AND coalesce(currency_code, functional_code) = functional_code AND id <> definition.investment_account_id)
           OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = (terms->>'gain_account_id')::uuid AND type = 'income' AND NOT is_group)
           OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = (terms->>'loss_account_id')::uuid AND type = 'expense' AND NOT is_group)
           OR (retained_value IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = (terms->>'retained_account_id')::uuid
               AND type = 'asset' AND NOT is_group AND NOT is_control AND cash_flow IS DISTINCT FROM 'cash' AND id NOT IN (definition.investment_account_id, counter_arg))) THEN
            RAISE EXCEPTION 'Associate disposals require functional proceeds, gain, loss and retained-interest accounts';
        END IF;
        removed := CASE WHEN portion = 1 THEN carry ELSE ledger_fraction(carry * portion, 1, 'half_even') END;
        idelta := -(CASE WHEN portion = 1 THEN impaired ELSE ledger_fraction(impaired * portion, 1, 'half_even') END);
        cd := -removed;
        IF portion = 1 THEN pd := -unprofit; od := -unoci; END IF;
        release := ledger_fraction((state->>'oci_minor')::numeric * portion, 1, 'half_even');
        IF release <> 0 AND NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = (terms->>'oci_transfer_account_id')::uuid
            AND type IN ('income', 'expense', 'equity') AND NOT is_group AND id <> definition.oci_account_id) THEN
            RAISE EXCEPTION 'Releasing the associate OCI share requires a reclassification or retained-earnings account';
        END IF;
        outcome := amount + coalesce(retained_value, 0) - removed;
        rows := ledger_asset_row(counter_arg, amount) || ledger_asset_row((terms->>'retained_account_id')::uuid, coalesce(retained_value, 0))
            || ledger_asset_row(definition.investment_account_id, -removed) || ledger_asset_row(definition.oci_account_id, release)
            || ledger_asset_row((terms->>'oci_transfer_account_id')::uuid, -release)
            || ledger_asset_row(CASE WHEN outcome > 0 THEN (terms->>'gain_account_id')::uuid ELSE (terms->>'loss_account_id')::uuid END, -outcome);
    ELSIF operation = 'held_for_sale' THEN
        IF (state->>'held_for_sale')::boolean THEN RAISE EXCEPTION 'Associate is already held for sale'; END IF;
    ELSIF operation = 'increase' THEN
        portion := (terms->>'fraction')::numeric;
        IF portion IS NULL OR portion <= 0 OR (state->>'ownership_fraction')::numeric + portion >= 1 OR amount < 0 OR (terms->>'net_assets_minor') IS NULL
           OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = tenant AND book_id = book AND id = counter_arg AND type IN ('asset', 'liability', 'equity')
               AND NOT is_group AND NOT is_control AND id <> definition.investment_account_id) THEN
            RAISE EXCEPTION 'An additional stake keeps ownership below control and needs reviewed cost, net assets and a funding account';
        END IF;
        IF liability <> 0 OR unprofit <> 0 OR unoci <> 0 THEN RAISE EXCEPTION 'Absorb suspended losses before increasing an associate holding'; END IF;
        outcome := ledger_fraction(amount - (terms->>'net_assets_minor')::numeric * portion, 1, 'half_even');
        cd := amount + greatest(-outcome, 0);
        rows := ledger_asset_row(definition.investment_account_id, cd) || ledger_asset_row(counter_arg, -amount) || ledger_asset_row(definition.profit_account_id, -greatest(-outcome, 0));
    ELSIF operation = 'cancellation' THEN
        IF EXISTS (SELECT 1 FROM ledger_associate_events e WHERE e.tenant_id = tenant AND e.book_id = book AND e.associate_id = associate AND e.reverses_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM ledger_associate_events r WHERE r.tenant_id = tenant AND r.book_id = book AND r.reverses_id = e.id)) THEN RAISE EXCEPTION 'Reverse associate events before cancelling its acquisition'; END IF;
        cd := -definition.carrying_minor;
        rows := ledger_asset_row(definition.investment_account_id, -definition.carrying_minor) || ledger_asset_row(definition.funding_account_id, definition.cost_minor)
            || ledger_asset_row(definition.profit_account_id, definition.bargain_minor);
    ELSE RAISE EXCEPTION 'Unknown associate event';
    END IF;
    IF operation IN ('profit', 'oci', 'recovery', 'obligation') THEN
        cd := greatest(net, 0) - carry;
        ld := least(greatest(-net, 0), capacity) - liability;
        new_unrecognized := greatest(-net - capacity, 0);
        difference := new_unrecognized - unprofit - unoci;
        IF difference >= 0 THEN
            IF operation = 'oci' THEN od := difference; ELSE pd := difference; END IF;
        ELSIF operation = 'oci' THEN
            used := least(-difference, unoci); od := -used; pd := difference + used;
        ELSE
            used := least(-difference, unprofit); pd := -used; od := difference + used;
        END IF;
        rows := ledger_asset_row(definition.investment_account_id, cd) || ledger_asset_row(definition.loss_liability_account_id, -ld);
        IF operation = 'recovery' THEN
            rows := rows || ledger_asset_row(definition.profit_account_id, -pd) || ledger_asset_row(definition.oci_account_id, -od)
                || ledger_asset_row(definition.impairment_account_id, -reverse_loss);
        ELSIF operation = 'obligation' THEN
            rows := rows || ledger_asset_row(definition.profit_account_id, -pd) || ledger_asset_row(definition.oci_account_id, -od);
        ELSE
            rows := rows || ledger_asset_row(CASE WHEN operation = 'oci' THEN definition.oci_account_id ELSE definition.profit_account_id END, -(cd - ld));
        END IF;
    END IF;
    IF EXISTS (SELECT 1 FROM unnest(ARRAY[carry + cd, liability + ld, unprofit + pd, unoci + od, impaired + idelta]) value WHERE value < 0 OR value >= power(10::numeric, 38)) THEN
        RAISE EXCEPTION 'Associate balances must remain nonnegative within the 38-digit limit';
    END IF;
    RETURN jsonb_build_object('postings', rows, 'share_minor', share::text, 'carrying_delta', cd::text, 'liability_delta', ld::text,
        'unrecognized_profit_delta', pd::text, 'unrecognized_oci_delta', od::text, 'impairment_delta', idelta::text,
        'obligation_limit_minor', capacity::text, 'prior_limit_minor', state->>'obligation_limit_minor', 'oci_release_minor', release::text);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_associate_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE definition ledger_associates%ROWTYPE; original ledger_associate_events%ROWTYPE; latest uuid; expected jsonb; rows jsonb; actual jsonb;
    functional_code text; portion numeric; all_accounts uuid[]; cash_account uuid; cash_amount numeric; cash_category text := 'investing';
    reporting jsonb; reporting_entry record;
BEGIN
    PERFORM 1 FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id FOR UPDATE;
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id, (to_jsonb(NEW)->>'reverses_id') IS NOT NULL);
    SELECT currency_code INTO STRICT functional_code FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id;
    IF TG_TABLE_NAME = 'ledger_associates' THEN
        all_accounts := ARRAY[NEW.investment_account_id, NEW.funding_account_id, NEW.profit_account_id, NEW.oci_account_id, NEW.dividend_account_id, NEW.impairment_account_id, NEW.loss_liability_account_id];
        PERFORM ledger_require_live_dimensions(NEW.tenant_id, NEW.book_id, NEW.dimensions);
        IF EXISTS (SELECT 1 FROM ledger_account_dimensions WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND account_id = ANY(all_accounts) AND NOT (NEW.dimensions ? dimension)) THEN RAISE EXCEPTION 'A mandatory associate accounting dimension is missing'; END IF;
        IF NOT EXISTS (SELECT 1 FROM ledger_entities e JOIN ledger_books b ON b.tenant_id = e.tenant_id AND b.id = NEW.book_id
            WHERE e.tenant_id = NEW.tenant_id AND e.id = NEW.investee_entity_id AND e.id <> b.entity_id AND e.deleted_at IS NULL) THEN RAISE EXCEPTION 'Associate requires a distinct live investee entity'; END IF;
        IF EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = ANY(all_accounts)
            AND (is_group OR is_control OR NOT active OR deleted_at IS NOT NULL OR coalesce(currency_code, functional_code) <> functional_code))
            OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.investment_account_id AND type = 'asset' AND cash_flow IS DISTINCT FROM 'cash')
            OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.profit_account_id AND type = 'income')
            OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.oci_account_id AND type = 'equity')
            OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.dividend_account_id AND type = 'asset')
            OR NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.impairment_account_id AND type = 'expense')
            OR (NEW.loss_liability_account_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.loss_liability_account_id AND type = 'liability'))
            OR NEW.investment_account_id IN (NEW.funding_account_id, NEW.dividend_account_id)
            OR NEW.funding_account_id IN (NEW.profit_account_id, NEW.oci_account_id, NEW.impairment_account_id, coalesce(NEW.loss_liability_account_id, NEW.investment_account_id)) THEN RAISE EXCEPTION 'Invalid functional associate posting accounts'; END IF;
        IF EXISTS (SELECT 1 FROM ledger_inventory_pools WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND account_id = ANY(all_accounts))
            OR EXISTS (SELECT 1 FROM ledger_assets WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND (cost_account_id = ANY(all_accounts) OR depreciation_account_id = ANY(all_accounts) OR impairment_account_id = ANY(all_accounts)))
            OR EXISTS (SELECT 1 FROM ledger_associates WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND (investment_account_id = ANY(all_accounts) OR loss_liability_account_id = ANY(all_accounts))) THEN RAISE EXCEPTION 'Associate accounts cannot bypass other valuation registers'; END IF;
        IF EXISTS (SELECT 1 FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND account_id IN (NEW.investment_account_id, NEW.loss_liability_account_id) AND journal_id IS DISTINCT FROM NEW.journal_id) THEN RAISE EXCEPTION 'Reconcile untracked associate balances before registration'; END IF;
        portion := NEW.cost_minor - NEW.fair_value_net_assets_minor * NEW.ownership_fraction;
        IF NEW.carrying_minor <> NEW.cost_minor + greatest(ledger_fraction(-portion, 1, 'half_even'), 0)
            OR NEW.goodwill_minor <> greatest(ledger_fraction(portion, 1, 'half_even'), 0) OR NEW.bargain_minor <> greatest(ledger_fraction(-portion, 1, 'half_even'), 0) THEN RAISE EXCEPTION 'Associate acquisition must match reviewed cost and fair-value net assets'; END IF;
        rows := ledger_asset_row(NEW.investment_account_id, NEW.carrying_minor) || ledger_asset_row(NEW.funding_account_id, -NEW.cost_minor) || ledger_asset_row(NEW.profit_account_id, -NEW.bargain_minor);
        cash_account := NEW.funding_account_id; cash_amount := -NEW.cost_minor;
    ELSE
        SELECT * INTO STRICT definition FROM ledger_associates WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.associate_id;
        PERFORM ledger_require_live_dimensions(NEW.tenant_id, NEW.book_id, definition.dimensions, NEW.reverses_id IS NOT NULL);
        IF EXISTS (SELECT 1 FROM ledger_associate_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND associate_id = NEW.associate_id AND effective_on > NEW.effective_on) THEN RAISE EXCEPTION 'Associate events require chronological accounting dates'; END IF;
        IF NEW.reverses_id IS NOT NULL THEN
            SELECT * INTO STRICT original FROM ledger_associate_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
            SELECT e.id INTO latest FROM ledger_associate_events e WHERE e.tenant_id = NEW.tenant_id AND e.book_id = NEW.book_id AND e.associate_id = NEW.associate_id AND e.reverses_id IS NULL
                AND NOT EXISTS (SELECT 1 FROM ledger_associate_events r WHERE r.tenant_id = NEW.tenant_id AND r.book_id = NEW.book_id AND r.reverses_id = e.id) ORDER BY e.sequence DESC LIMIT 1;
            IF original.id IS DISTINCT FROM latest OR original.reverses_id IS NOT NULL OR original.kind = 'cancellation' OR original.associate_id <> NEW.associate_id OR original.kind <> NEW.kind
                OR (NEW.source_minor, NEW.share_minor, NEW.carrying_delta, NEW.liability_delta, NEW.unrecognized_profit_delta, NEW.unrecognized_oci_delta, NEW.impairment_delta)
                    IS DISTINCT FROM (-original.source_minor, -original.share_minor, -original.carrying_delta, -original.liability_delta, -original.unrecognized_profit_delta, -original.unrecognized_oci_delta, -original.impairment_delta)
                OR NEW.obligation_limit_minor <> original.prior_limit_minor OR NEW.prior_limit_minor <> original.obligation_limit_minor
                OR NEW.counter_account_id IS DISTINCT FROM original.counter_account_id OR NEW.unimpaired_minor IS DISTINCT FROM original.unimpaired_minor
                OR NEW.net_assets_minor IS DISTINCT FROM original.net_assets_minor OR (NEW.fraction, NEW.gain_account_id, NEW.loss_account_id, NEW.oci_transfer_account_id, NEW.retained_account_id, NEW.retained_fair_value_minor, NEW.oci_release_minor)
                    IS DISTINCT FROM (original.fraction, original.gain_account_id, original.loss_account_id, original.oci_transfer_account_id, original.retained_account_id, original.retained_fair_value_minor, -original.oci_release_minor)
                OR (NEW.journal_id IS NULL) <> (original.journal_id IS NULL) THEN RAISE EXCEPTION 'Reverse associate events in dependency order with exact original effects'; END IF;
            IF NEW.journal_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id AND reverses_id = original.journal_id AND effective_on = NEW.effective_on) THEN RAISE EXCEPTION 'Associate corrections require their exact journal reversal'; END IF;
            RETURN NEW;
        END IF;
        IF EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id IN (definition.investment_account_id, definition.profit_account_id, definition.oci_account_id, definition.impairment_account_id, definition.loss_liability_account_id)
            AND (NOT active OR deleted_at IS NOT NULL)) THEN RAISE EXCEPTION 'Restore inactive or soft-deleted associate accounts before new events'; END IF;
        expected := ledger_associate_quote(NEW.tenant_id, NEW.book_id, NEW.associate_id, NEW.effective_on, NEW.kind, NEW.source_minor, NEW.obligation_limit_minor, NEW.unimpaired_minor, NEW.counter_account_id,
            CASE WHEN NEW.kind = 'increase' THEN jsonb_build_object('fraction', NEW.fraction, 'net_assets_minor', NEW.net_assets_minor)
                WHEN NEW.kind = 'disposal' THEN jsonb_build_object('fraction', NEW.fraction, 'gain_account_id', NEW.gain_account_id, 'loss_account_id', NEW.loss_account_id,
                'oci_transfer_account_id', NEW.oci_transfer_account_id, 'retained_account_id', NEW.retained_account_id, 'retained_fair_value_minor', NEW.retained_fair_value_minor) END);
        IF NEW.oci_release_minor <> (expected->>'oci_release_minor')::numeric THEN RAISE EXCEPTION 'Associate disposal must release the exact OCI share'; END IF;
        IF (NEW.share_minor, NEW.carrying_delta, NEW.liability_delta, NEW.unrecognized_profit_delta, NEW.unrecognized_oci_delta, NEW.impairment_delta, NEW.obligation_limit_minor, NEW.prior_limit_minor)
            IS DISTINCT FROM ((expected->>'share_minor')::numeric, (expected->>'carrying_delta')::numeric, (expected->>'liability_delta')::numeric,
                (expected->>'unrecognized_profit_delta')::numeric, (expected->>'unrecognized_oci_delta')::numeric, (expected->>'impairment_delta')::numeric, (expected->>'obligation_limit_minor')::numeric, (expected->>'prior_limit_minor')::numeric) THEN RAISE EXCEPTION 'Associate event does not match exact ownership and loss-limit effects'; END IF;
        rows := expected->'postings';
        IF NEW.kind = 'cancellation' AND definition.journal_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id AND reverses_id = definition.journal_id) THEN
            RAISE EXCEPTION 'Associate cancellation must reverse its original acquisition journal';
        END IF;
        IF NEW.kind = 'dividend' THEN cash_account := definition.dividend_account_id; cash_amount := NEW.share_minor; cash_category := definition.dividend_cash_flow;
        ELSIF NEW.kind = 'settlement' THEN cash_account := NEW.counter_account_id; cash_amount := -NEW.source_minor;
        ELSIF NEW.kind = 'cancellation' THEN cash_account := definition.funding_account_id; cash_amount := definition.cost_minor;
        ELSIF NEW.kind = 'disposal' THEN cash_account := NEW.counter_account_id; cash_amount := NEW.source_minor;
        ELSIF NEW.kind = 'increase' THEN cash_account := NEW.counter_account_id; cash_amount := -NEW.source_minor;
        END IF;
        IF NEW.kind = 'dividend' AND EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = definition.dividend_account_id AND (NOT active OR deleted_at IS NOT NULL)) THEN RAISE EXCEPTION 'Restore the associate dividend account before new distributions'; END IF;
    END IF;
    IF (jsonb_array_length(rows) = 0) <> (NEW.journal_id IS NULL) THEN RAISE EXCEPTION 'Associate journal does not match its financial effect'; END IF;
    IF NEW.journal_id IS NOT NULL THEN
        SELECT jsonb_agg(jsonb_build_object('account_id', account_id::text, 'side', side, 'amount_minor', amount_minor::text) ORDER BY line_no) INTO actual
            FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id;
        IF actual IS DISTINCT FROM rows OR NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id AND effective_on = NEW.effective_on AND NOT closing)
            OR EXISTS (SELECT 1 FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id AND (valuation OR party_id IS NOT NULL OR currency_code <> functional_code
                OR dimensions IS DISTINCT FROM CASE WHEN TG_TABLE_NAME = 'ledger_associates' THEN to_jsonb(NEW)->'dimensions' ELSE definition.dimensions END)) THEN RAISE EXCEPTION 'Associate journal does not match its exact financial effects'; END IF;
        IF cash_amount <> 0 AND EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = cash_account AND cash_flow = 'cash')
            AND NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id AND metadata->'cash_flow' = jsonb_build_object(cash_category, cash_amount::text)) THEN
            RAISE EXCEPTION 'Associate cash flows must match the explicit acquisition or distribution policy';
        END IF;
        IF TG_TABLE_NAME = 'ledger_associate_events' THEN
            reporting := coalesce(NEW.evidence->'reporting_movements', '{}'::jsonb);
            IF reporting = '[]'::jsonb THEN reporting := '{}'::jsonb; END IF;
            IF jsonb_typeof(reporting) <> 'object' THEN RAISE EXCEPTION 'Associate reporting evidence must map account IDs'; END IF;
            FOR reporting_entry IN SELECT key, value FROM jsonb_each(reporting) LOOP
                IF NOT EXISTS (SELECT 1 FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id
                    AND account_id = reporting_entry.key::uuid AND reporting_movement = reporting_entry.value) THEN
                    RAISE EXCEPTION 'Associate reporting evidence must match its journal attribution';
                END IF;
            END LOOP;
            IF EXISTS (SELECT 1 FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id
                AND reporting_movement IS NOT NULL AND reporting_movement IS DISTINCT FROM reporting->account_id::text) THEN
                RAISE EXCEPTION 'Associate journal attribution must retain its reviewed source evidence';
            END IF;
            IF NEW.kind = 'oci' AND EXISTS (SELECT 1 FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id
                AND account_id = definition.oci_account_id AND reporting_movement IS NOT NULL AND reporting_movement->>'kind' <> 'oci') THEN
                RAISE EXCEPTION 'Associate OCI source attribution must identify OCI';
            END IF;
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_associate_links() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE previous_tenant text := coalesce(current_setting('noria.ledger_tenant', true), '');
BEGIN
    PERFORM set_config('noria.ledger_tenant', NEW.tenant_id::text, true);
    IF EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_associates a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND p.account_id IN (a.investment_account_id, a.loss_liability_account_id)
        WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.id)
        AND NOT EXISTS (SELECT 1 FROM ledger_associates WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id)
        AND NOT EXISTS (SELECT 1 FROM ledger_associate_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id) THEN RAISE EXCEPTION 'Associate balances require corresponding equity-method evidence'; END IF;
    IF EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_associate_events s ON s.tenant_id = p.tenant_id AND s.book_id = p.book_id
        AND s.journal_id = (p.reporting_movement->>'source_journal_id')::uuid
        WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.id AND p.reporting_movement->>'kind' IN ('recycling', 'basis_adjustment')
            AND NOT EXISTS (SELECT 1 FROM ledger_associate_events e WHERE e.tenant_id = p.tenant_id AND e.book_id = p.book_id
                AND e.associate_id = s.associate_id AND e.journal_id = NEW.id AND e.kind = 'disposal')) THEN
        RAISE EXCEPTION 'Associate OCI recycling requires its linked disposal event';
    END IF;
    PERFORM set_config('noria.ledger_tenant', previous_tenant, true);
    RETURN NEW;
END;
$body$;

DO $body$
DECLARE name text;
BEGIN
    FOREACH name IN ARRAY ARRAY['ledger_associates', 'ledger_associate_events'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', name);
        EXECUTE format('CREATE POLICY ledger_tenant ON %I USING (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid) WITH CHECK (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid)', name);
        EXECUTE format('CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard()', name);
        EXECUTE format('CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_immutable()', name);
        EXECUTE format('CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable()', name);
        EXECUTE format('CREATE TRIGGER stage_30_stamp BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_event_stamp()', name);
        EXECUTE format('CREATE TRIGGER stage_40_validate BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_associate_validate()', name);
        EXECUTE format('CREATE TRIGGER stage_90_audit AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_audit()', name);
    END LOOP;
END;
$body$;

ALTER TABLE ledger_consolidation_runs ADD CHECK (jsonb_typeof(inputs) = 'object'), ADD CHECK (jsonb_typeof(result) = 'object');

CREATE OR REPLACE FUNCTION ledger_consolidation_stamp() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    NEW.result_hash := encode(sha256(convert_to(NEW.result::text, 'UTF8')), 'hex');
    RETURN NEW;
END $$;

ALTER TABLE ledger_consolidation_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger_consolidation_runs FORCE ROW LEVEL SECURITY;
CREATE POLICY ledger_tenant ON ledger_consolidation_runs USING (tenant_id = nullif(current_setting('noria.ledger_tenant', true), '')::uuid)
    WITH CHECK (tenant_id = nullif(current_setting('noria.ledger_tenant', true), '')::uuid);
CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON ledger_consolidation_runs FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard();
CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON ledger_consolidation_runs FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON ledger_consolidation_runs FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER stage_30_stamp BEFORE INSERT ON ledger_consolidation_runs FOR EACH ROW EXECUTE FUNCTION ledger_evidence_stamp();
CREATE TRIGGER stage_40_hash BEFORE INSERT ON ledger_consolidation_runs FOR EACH ROW EXECUTE FUNCTION ledger_consolidation_stamp();
CREATE TRIGGER stage_90_audit AFTER INSERT ON ledger_consolidation_runs FOR EACH ROW EXECUTE FUNCTION ledger_audit();
