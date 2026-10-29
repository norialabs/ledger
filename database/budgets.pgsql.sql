ALTER TABLE ledger_budget_policies ADD CHECK (length(btrim(version)) > 0);
ALTER TABLE ledger_commitments
    ADD CHECK (line_no > 0), ADD CHECK (kind IN ('commitment', 'fulfilment', 'cancellation', 'reversal')),
    ADD CHECK (amount_minor <> 0 AND amount_minor = trunc(amount_minor) AND abs(amount_minor) < power(10::numeric, 38)),
    ADD CHECK (length(btrim(idempotency_key)) > 0), ADD CHECK (fingerprint ~ '^[a-f0-9]{64}$'), ADD CHECK (length(btrim(reason)) > 0),
    ADD CHECK ((kind = 'commitment') = (commitment_id IS NULL)), ADD CHECK ((kind = 'reversal') = (reverses_id IS NOT NULL));

CREATE OR REPLACE FUNCTION ledger_budget_journal_stamp() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE policy ledger_budget_policies;
BEGIN
    SELECT * INTO policy FROM ledger_budget_policies WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id ORDER BY sequence DESC LIMIT 1;
    NEW.budget_policy_id := policy.id;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_commitment_stamp() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id, NEW.kind = 'reversal');
    IF NEW.journal_id IS NULL THEN
        UPDATE ledger_books SET next_sequence = next_sequence + 1 WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id RETURNING next_sequence INTO NEW.sequence;
    ELSE
        SELECT sequence INTO STRICT NEW.sequence FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id;
    END IF;
    NEW.recorded_at := clock_timestamp();
    NEW.actor_id := current_setting('noria.ledger_actor');
    NEW.firm_id := nullif(current_setting('noria.ledger_firm', true), '');
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_budget_actual(tenant uuid, book uuid, line jsonb, cutoff bigint, through_date date)
RETURNS numeric LANGUAGE sql STABLE SET search_path FROM CURRENT AS $body$
    SELECT coalesce(sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END), 0)
    FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
    LEFT JOIN ledger_journals original ON original.tenant_id = j.tenant_id AND original.book_id = j.book_id AND original.id = j.reverses_id
    WHERE p.tenant_id = tenant AND p.book_id = book AND p.account_id = (line->>'account_id')::uuid
        AND p.dimensions @> (line->'dimensions') AND NOT j.closing AND j.sequence <= cutoff
        AND coalesce(original.effective_on, j.effective_on) BETWEEN (line->>'starts_on')::date AND (line->>'ends_on')::date
        AND j.effective_on <= through_date
$body$;

CREATE OR REPLACE FUNCTION ledger_budget_check(tenant uuid, book uuid, budget uuid, touched bigint[]) RETURNS void
LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE policy ledger_budget_policies; line jsonb; n bigint; used numeric; reserved numeric;
BEGIN
    SELECT * INTO policy FROM ledger_budget_policies WHERE tenant_id = tenant AND book_id = book ORDER BY sequence DESC LIMIT 1;
    IF policy.budget_id IS DISTINCT FROM budget OR NOT policy.reject_over_budget THEN RETURN; END IF;
    FOR line, n IN SELECT b.lines->(t.n - 1)::integer, t.n FROM ledger_budgets b
        CROSS JOIN LATERAL unnest(coalesce(touched, ARRAY(SELECT generate_series(1::bigint, jsonb_array_length(b.lines))))) t(n)
        WHERE b.tenant_id = tenant AND b.book_id = book AND b.id = budget LOOP
        used := ledger_budget_actual(tenant, book, line, 9223372036854775807, '9999-12-31');
        SELECT coalesce(sum(amount_minor), 0) INTO reserved FROM ledger_commitments
            WHERE tenant_id = tenant AND book_id = book AND budget_id = budget AND line_no = n;
        IF used + reserved > (line->>'amount_minor')::numeric THEN RAISE EXCEPTION 'Budget exceeded on line %', n; END IF;
    END LOOP;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_budget_policy_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE lines jsonb; previous uuid;
BEGIN
    PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id);
    SELECT budget_id INTO previous FROM ledger_budget_policies WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id ORDER BY sequence DESC LIMIT 1;
    IF previous IS DISTINCT FROM NEW.budget_id AND EXISTS (SELECT 1 FROM ledger_commitments WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
        GROUP BY budget_id, line_no HAVING sum(amount_minor) <> 0) THEN RAISE EXCEPTION 'Release open commitments before changing budget version'; END IF;
    SELECT b.lines INTO STRICT lines FROM ledger_budgets b WHERE b.tenant_id = NEW.tenant_id AND b.book_id = NEW.book_id AND b.id = NEW.budget_id;
    PERFORM ledger_require_live_dimensions(NEW.tenant_id, NEW.book_id, l->'dimensions') FROM jsonb_array_elements(lines) l;
    IF EXISTS (SELECT 1 FROM jsonb_array_elements(lines) l WHERE NOT (l ?& ARRAY['account_id', 'starts_on', 'ends_on', 'amount_minor', 'dimensions'])
        OR jsonb_typeof(l->'dimensions') IS DISTINCT FROM 'object' OR jsonb_typeof(l->'amount_minor') IS DISTINCT FROM 'string'
        OR (l->>'amount_minor') !~ '^[0-9]{1,38}$' OR (l->>'starts_on') IS NULL OR (l->>'ends_on') IS NULL
        OR (l->>'starts_on')::date > (l->>'ends_on')::date
        OR NOT EXISTS (SELECT 1 FROM ledger_accounts a WHERE a.tenant_id = NEW.tenant_id AND a.book_id = NEW.book_id
            AND a.id = (l->>'account_id')::uuid AND a.type = 'expense' AND NOT a.is_group AND a.deleted_at IS NULL)) THEN
        RAISE EXCEPTION 'Commitment budgets require nonnegative expense lines';
    END IF;
    IF EXISTS (SELECT 1 FROM jsonb_array_elements(lines) WITH ORDINALITY a(l,i) JOIN jsonb_array_elements(lines) WITH ORDINALITY b(l,i)
        ON a.i < b.i AND a.l->>'account_id' = b.l->>'account_id' AND (a.l->>'starts_on')::date <= (b.l->>'ends_on')::date
        AND (b.l->>'starts_on')::date <= (a.l->>'ends_on')::date
        AND ((a.l->'dimensions') || (b.l->'dimensions')) = ((b.l->'dimensions') || (a.l->'dimensions'))) THEN
        RAISE EXCEPTION 'Commitment budget dimensions must have unambiguous coverage';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_commitment_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE root ledger_commitments; original ledger_commitments; policy ledger_budget_policies; journal ledger_journals; line jsonb; open_minor numeric;
BEGIN
    SELECT * INTO STRICT policy FROM ledger_budget_policies WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id ORDER BY sequence DESC LIMIT 1;
    NEW.policy_id := policy.id;
    SELECT lines->(NEW.line_no - 1) INTO line FROM ledger_budgets WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.budget_id;
    IF line IS NULL THEN RAISE EXCEPTION 'Unknown budget line'; END IF;
    IF NEW.kind = 'commitment' THEN
        IF policy.budget_id <> NEW.budget_id THEN RAISE EXCEPTION 'Commitment requires the selected budget version'; END IF;
        PERFORM ledger_require_live_dimensions(NEW.tenant_id, NEW.book_id, line->'dimensions');
        IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND id = (line->>'account_id')::uuid AND deleted_at IS NULL) THEN RAISE EXCEPTION 'Restore the commitment account before new activity'; END IF;
        IF NEW.amount_minor <= 0 OR NEW.journal_id IS NOT NULL OR NEW.effective_on NOT BETWEEN (line->>'starts_on')::date AND (line->>'ends_on')::date THEN
            RAISE EXCEPTION 'Commitments require a positive amount within the budget range'; END IF;
    ELSE
        SELECT * INTO STRICT root FROM ledger_commitments WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.commitment_id;
        IF root.kind <> 'commitment' OR root.budget_id <> NEW.budget_id OR root.line_no <> NEW.line_no THEN RAISE EXCEPTION 'Invalid commitment root'; END IF;
        IF EXISTS (SELECT 1 FROM ledger_commitments WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND reverses_id = root.id) THEN RAISE EXCEPTION 'Commitment has been reversed'; END IF;
        IF NEW.kind = 'reversal' THEN
            SELECT * INTO STRICT original FROM ledger_commitments WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
            IF original.kind = 'reversal' OR coalesce(original.commitment_id, original.id) <> root.id OR NEW.amount_minor <> -original.amount_minor
                OR NEW.effective_on < original.effective_on THEN RAISE EXCEPTION 'Commitment correction must exactly reverse the original'; END IF;
            IF (original.journal_id IS NOT NULL) <> (NEW.journal_id IS NOT NULL) THEN RAISE EXCEPTION 'Reverse fulfilment through its journal'; END IF;
        ELSIF NEW.amount_minor >= 0 THEN RAISE EXCEPTION 'Releases require negative amounts';
        END IF;
        IF NOT (NEW.kind = 'reversal' AND NEW.journal_id IS NOT NULL) AND EXISTS (SELECT 1 FROM ledger_commitments WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND (id = root.id OR commitment_id = root.id) AND effective_on > NEW.effective_on) THEN RAISE EXCEPTION 'Commitment events must be chronological'; END IF;
        IF NEW.kind = 'fulfilment' AND NEW.journal_id IS NULL THEN RAISE EXCEPTION 'Fulfilment requires a journal'; END IF;
        IF NEW.kind = 'cancellation' AND NEW.journal_id IS NOT NULL THEN RAISE EXCEPTION 'Cancellation cannot have a journal'; END IF;
        IF NEW.journal_id IS NOT NULL THEN
            SELECT * INTO STRICT journal FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.journal_id;
            IF NEW.kind = 'fulfilment' AND (journal.reverses_id IS NOT NULL OR journal.effective_on <> NEW.effective_on
                    OR (journal.metadata->'budget_commitments'->>root.id::text)::numeric IS DISTINCT FROM -NEW.amount_minor)
                OR NEW.kind = 'reversal' AND (journal.reverses_id IS DISTINCT FROM original.journal_id OR journal.effective_on <> NEW.effective_on) THEN
                RAISE EXCEPTION 'Fulfilment evidence is generated by its journal';
            END IF;
        END IF;
        SELECT coalesce(sum(amount_minor), 0) INTO open_minor FROM ledger_commitments WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND (id = root.id OR commitment_id = root.id);
        IF open_minor + NEW.amount_minor NOT BETWEEN 0 AND root.amount_minor THEN RAISE EXCEPTION 'Commitment release or restoration exceeds its balance'; END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_budget_policy_check() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    PERFORM ledger_budget_check(NEW.tenant_id, NEW.book_id, NEW.budget_id, NULL);
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_commitment_check() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    IF NEW.journal_id IS NULL THEN
        PERFORM ledger_budget_check(NEW.tenant_id, NEW.book_id, NEW.budget_id, ARRAY[NEW.line_no::bigint]);
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_budget_journal() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE policy ledger_budget_policies; root ledger_commitments; event ledger_commitments; allocation record; line jsonb; spent numeric;
BEGIN
    SELECT * INTO policy FROM ledger_budget_policies WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.budget_policy_id;
    IF NEW.reverses_id IS NOT NULL THEN
        FOR event IN SELECT * FROM ledger_commitments WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.reverses_id LOOP
            INSERT INTO ledger_commitments (tenant_id, book_id, budget_id, line_no, commitment_id, reverses_id, journal_id, kind, amount_minor, effective_on, idempotency_key, fingerprint, reason, policy_id)
            VALUES (NEW.tenant_id, NEW.book_id, event.budget_id, event.line_no, event.commitment_id, event.id, NEW.id, 'reversal', -event.amount_minor, NEW.effective_on,
                'journal:' || NEW.id || ':' || event.commitment_id, NEW.fingerprint, NEW.description, event.policy_id);
        END LOOP;
    ELSE
        IF NEW.metadata ? 'budget_commitments' AND (jsonb_typeof(NEW.metadata->'budget_commitments') <> 'object' OR NEW.closing) THEN RAISE EXCEPTION 'Invalid commitment allocations'; END IF;
        FOR allocation IN SELECT key, value FROM jsonb_each_text(coalesce(NEW.metadata->'budget_commitments', '{}'::jsonb)) LOOP
            IF jsonb_typeof(NEW.metadata->'budget_commitments'->allocation.key) IS DISTINCT FROM 'string'
                OR allocation.value !~ '^[0-9]{1,38}$' OR allocation.value::numeric <= 0 THEN RAISE EXCEPTION 'Invalid fulfilment amount'; END IF;
            SELECT * INTO STRICT root FROM ledger_commitments WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = allocation.key::uuid AND kind = 'commitment';
            SELECT lines->(root.line_no - 1) INTO STRICT line FROM ledger_budgets WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = root.budget_id;
            IF NEW.effective_on NOT BETWEEN (line->>'starts_on')::date AND (line->>'ends_on')::date THEN RAISE EXCEPTION 'Fulfilment falls outside budget line'; END IF;
            SELECT coalesce(sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END), 0) INTO spent
                FROM ledger_postings p WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.id
                AND p.account_id = (line->>'account_id')::uuid AND p.dimensions @> (line->'dimensions');
            IF (SELECT coalesce(sum(-amount_minor), 0) FROM ledger_commitments WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id
                AND budget_id = root.budget_id AND line_no = root.line_no) + allocation.value::numeric > spent THEN
                RAISE EXCEPTION 'Fulfilment exceeds matching invoice expenditure'; END IF;
            INSERT INTO ledger_commitments (tenant_id, book_id, budget_id, line_no, commitment_id, journal_id, kind, amount_minor, effective_on, idempotency_key, fingerprint, reason, policy_id)
            VALUES (NEW.tenant_id, NEW.book_id, root.budget_id, root.line_no, root.id, NEW.id, 'fulfilment', -allocation.value::numeric, NEW.effective_on,
                'journal:' || NEW.id || ':' || root.id, NEW.fingerprint, NEW.description, root.policy_id);
        END LOOP;
    END IF;
    IF policy.id IS NULL THEN RETURN NEW; END IF;
    IF policy.reject_unbudgeted AND NOT NEW.closing AND NEW.reverses_id IS NULL AND EXISTS (
        SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
        WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.id AND a.type = 'expense' AND p.side = 'debit'
        AND NOT EXISTS (SELECT 1 FROM ledger_budgets b, jsonb_array_elements(b.lines) l WHERE b.tenant_id = NEW.tenant_id AND b.book_id = NEW.book_id AND b.id = policy.budget_id
            AND p.account_id = (l->>'account_id')::uuid AND p.dimensions @> (l->'dimensions') AND NEW.effective_on BETWEEN (l->>'starts_on')::date AND (l->>'ends_on')::date)) THEN
        RAISE EXCEPTION 'Expense posting has no selected budget line'; END IF;
    IF policy.reject_over_budget AND (EXISTS (SELECT 1 FROM ledger_commitments WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id)
        OR EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
            WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.id AND a.type = 'expense')) THEN
        PERFORM ledger_budget_check(NEW.tenant_id, NEW.book_id, policy.budget_id, ARRAY(
            SELECT x.i FROM ledger_budgets b CROSS JOIN LATERAL jsonb_array_elements(b.lines) WITH ORDINALITY x(l, i)
            WHERE b.tenant_id = NEW.tenant_id AND b.book_id = NEW.book_id AND b.id = policy.budget_id
                AND EXISTS (SELECT 1 FROM ledger_postings p WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.id
                    AND p.account_id = (x.l->>'account_id')::uuid AND p.dimensions @> (x.l->'dimensions'))
            UNION SELECT line_no FROM ledger_commitments WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id AND budget_id = policy.budget_id));
    END IF;
    RETURN NEW;
END;
$body$;

DO $body$
DECLARE table_name text;
BEGIN
    FOREACH table_name IN ARRAY ARRAY['ledger_budget_policies', 'ledger_commitments'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', table_name);
        EXECUTE format('CREATE POLICY ledger_tenant ON %I USING (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid) WITH CHECK (tenant_id = nullif(current_setting(''noria.ledger_tenant'', true), '''')::uuid)', table_name);
        EXECUTE format('CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard()', table_name);
        EXECUTE format('CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable()', table_name);
        EXECUTE format('CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_immutable()', table_name);
        EXECUTE format('CREATE TRIGGER stage_90_audit AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_audit()', table_name);
    END LOOP;
END;
$body$;
CREATE TRIGGER stage_30_stamp BEFORE INSERT ON ledger_budget_policies FOR EACH ROW EXECUTE FUNCTION ledger_evidence_stamp();
CREATE TRIGGER stage_30_stamp BEFORE INSERT ON ledger_commitments FOR EACH ROW EXECUTE FUNCTION ledger_commitment_stamp();
CREATE TRIGGER stage_40_validate BEFORE INSERT ON ledger_budget_policies FOR EACH ROW EXECUTE FUNCTION ledger_budget_policy_validate();
CREATE TRIGGER stage_40_validate BEFORE INSERT ON ledger_commitments FOR EACH ROW EXECUTE FUNCTION ledger_commitment_validate();
CREATE TRIGGER stage_80_budget AFTER INSERT ON ledger_budget_policies FOR EACH ROW EXECUTE FUNCTION ledger_budget_policy_check();
CREATE TRIGGER stage_80_budget AFTER INSERT ON ledger_commitments FOR EACH ROW EXECUTE FUNCTION ledger_commitment_check();
CREATE TRIGGER stage_40_budget_policy BEFORE INSERT ON ledger_journals FOR EACH ROW EXECUTE FUNCTION ledger_budget_journal_stamp();
CREATE TRIGGER stage_80_release_commitments AFTER INSERT ON ledger_journals FOR EACH ROW EXECUTE FUNCTION ledger_budget_journal();
