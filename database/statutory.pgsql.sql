ALTER TABLE ledger_statutory_submissions
    ADD CHECK (kind IN ('invoice', 'credit_note', 'tax_return')), ADD CHECK (length(btrim(channel)) > 0),
    ADD CHECK (environment IN ('sandbox', 'production')), ADD CHECK (length(btrim(adapter)) > 0),
    ADD CHECK (length(btrim(adapter_version)) > 0), ADD CHECK (length(btrim(request_reference)) > 0),
    ADD CHECK (content_type IN ('application/json', 'application/xml', 'text/csv')),
    ADD CHECK (octet_length(payload) BETWEEN 1 AND 10485760), ADD CHECK (source_scale BETWEEN 0 AND 12),
    ADD CHECK (gross_minor >= 0 AND gross_minor = trunc(gross_minor) AND gross_minor < power(10::numeric, 38)),
    ADD CHECK (max_attempts BETWEEN 1 AND 1000),
    ADD CHECK ((journal_id IS NOT NULL) <> (return_id IS NOT NULL)), ADD CHECK ((kind = 'tax_return') = (return_id IS NOT NULL)),
    ADD CHECK ((kind = 'credit_note') = (original_id IS NOT NULL));
ALTER TABLE ledger_statutory_events
    ADD CHECK (kind IN ('claim', 'accepted', 'rejected', 'retry', 'uncertain', 'not_found', 'cancelled')),
    ADD CHECK (delay_seconds BETWEEN 0 AND 86400), ADD CHECK (jsonb_typeof(evidence) = 'object'), ADD CHECK (length(btrim(reason)) > 0),
    ADD CHECK ((kind = 'claim') = (mode IS NOT NULL)), ADD CHECK (mode IS NULL OR mode IN ('send', 'lookup')),
    ADD CHECK ((kind = 'claim') = (lease_seconds IS NOT NULL)), ADD CHECK (lease_seconds IS NULL OR lease_seconds BETWEEN 1 AND 900),
    ADD CHECK ((kind = 'claim') = (lease_expires_at IS NOT NULL)), ADD CHECK ((kind IN ('claim', 'cancelled')) = (claim_id IS NULL)),
    ADD CHECK (kind IN ('retry', 'uncertain', 'not_found') OR delay_seconds = 0),
    ADD CHECK ((kind = 'accepted') = (receipt_reference IS NOT NULL)), ADD CHECK (receipt_reference IS NULL OR length(btrim(receipt_reference)) > 0);
CREATE UNIQUE INDEX ledger_statutory_receipt ON ledger_statutory_events (tenant_id, book_id, channel, environment, receipt_reference) WHERE kind = 'accepted';

CREATE OR REPLACE FUNCTION ledger_statutory_source(tenant uuid, book uuid, journal uuid, tax_return uuid)
RETURNS jsonb LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE source ledger_journals%ROWTYPE; base ledger_journals%ROWTYPE; declaration ledger_tax_returns%ROWTYPE; data jsonb; obligations jsonb; obligation jsonb;
    currency_arg text; scale_arg integer; amount numeric := 0; kind_arg text; document_kind text;
BEGIN
    IF (journal IS NULL) = (tax_return IS NULL) THEN RAISE EXCEPTION 'A statutory submission requires exactly one source'; END IF;
    IF journal IS NOT NULL THEN
        SELECT * INTO STRICT source FROM ledger_journals WHERE tenant_id = tenant AND book_id = book AND id = journal;
        base := source;
        IF source.reverses_id IS NOT NULL THEN
            SELECT * INTO STRICT base FROM ledger_journals WHERE tenant_id = tenant AND book_id = book AND id = source.reverses_id;
        END IF;
        document_kind := coalesce(base.metadata->'tax_document'->'request'->>'kind', base.metadata->'document'->>'kind');
        IF document_kind = 'sales_invoice' THEN kind_arg := CASE WHEN source.reverses_id IS NULL THEN 'invoice' ELSE 'credit_note' END;
        ELSIF document_kind = 'sales_credit' AND source.reverses_id IS NULL THEN kind_arg := 'credit_note';
        ELSE RAISE EXCEPTION 'Statutory invoice delivery requires a sales document or its exact reversal'; END IF;
        obligations := coalesce(base.metadata->'tax_document'->'request'->'obligations', base.metadata->'document'->'obligations');
        IF jsonb_typeof(obligations) IS DISTINCT FROM 'array' OR jsonb_array_length(obligations) = 0 THEN RAISE EXCEPTION 'Statutory sources require retained document obligations'; END IF;
        currency_arg := obligations->0->'amount'->>'currency'; scale_arg := (obligations->0->'amount'->>'scale')::integer;
        IF NOT EXISTS (SELECT 1 FROM ledger_currencies WHERE tenant_id = tenant AND code = currency_arg AND scale = scale_arg) THEN RAISE EXCEPTION 'Invalid statutory source currency'; END IF;
        FOR obligation IN SELECT * FROM jsonb_array_elements(obligations) LOOP
            IF (obligation->'amount'->>'currency', (obligation->'amount'->>'scale')::integer) IS DISTINCT FROM (currency_arg, scale_arg) THEN RAISE EXCEPTION 'Statutory document obligations must share a currency'; END IF;
            amount := amount + ledger_finance_amount(obligation->'amount'->>'amount', scale_arg);
        END LOOP;
        IF amount <= 0 OR amount >= power(10::numeric, 38) THEN RAISE EXCEPTION 'Statutory document gross amount is outside monetary bounds'; END IF;
        IF (SELECT coalesce(sum(p.amount_minor), 0) FROM ledger_postings p JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
            WHERE p.tenant_id = tenant AND p.book_id = book AND p.journal_id = journal AND a.is_control AND p.currency_code = currency_arg) <> amount
            OR EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
                WHERE p.tenant_id = tenant AND p.book_id = book AND p.journal_id = journal AND a.is_control
                    AND (p.currency_code <> currency_arg OR a.type <> 'asset' OR p.side <> CASE WHEN kind_arg = 'invoice' THEN 'debit' ELSE 'credit' END)) THEN RAISE EXCEPTION 'Statutory gross must match the actual document control postings'; END IF;
        data := jsonb_build_object('kind', kind_arg, 'journal_id', journal, 'effective_on', source.effective_on, 'source_type', base.source_type, 'source_reference', base.source_reference,
            'postings', source.postings, 'metadata', base.metadata, 'reverses_id', source.reverses_id, 'currency', currency_arg, 'scale', scale_arg, 'gross_minor', amount::text);
    ELSE
        SELECT * INTO STRICT declaration FROM ledger_tax_returns WHERE tenant_id = tenant AND book_id = book AND id = tax_return;
        SELECT b.currency_code, c.scale INTO STRICT currency_arg, scale_arg FROM ledger_books b JOIN ledger_currencies c ON c.tenant_id = b.tenant_id AND c.code = b.currency_code WHERE b.tenant_id = tenant AND b.id = book;
        data := jsonb_build_object('kind', 'tax_return', 'return_id', tax_return, 'starts_on', declaration.starts_on, 'ends_on', declaration.effective_on,
            'through_sequence', declaration.through_sequence::text, 'configuration_id', declaration.configuration_id, 'result_hash', declaration.result_hash,
            'result', declaration.result_json, 'currency', currency_arg, 'scale', scale_arg, 'gross_minor', '0');
    END IF;
    RETURN data;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_statutory_state(tenant uuid, book uuid, submission uuid)
RETURNS jsonb LANGUAGE plpgsql VOLATILE SET search_path FROM CURRENT AS $body$
DECLARE definition ledger_statutory_submissions%ROWTYPE; latest ledger_statutory_events%ROWTYPE; attempts integer; status_arg text; due timestamptz;
BEGIN
    SELECT * INTO STRICT definition FROM ledger_statutory_submissions WHERE tenant_id = tenant AND book_id = book AND id = submission;
    SELECT * INTO latest FROM ledger_statutory_events WHERE tenant_id = tenant AND book_id = book AND submission_id = submission ORDER BY sequence DESC LIMIT 1;
    SELECT count(*) INTO attempts FROM ledger_statutory_events WHERE tenant_id = tenant AND book_id = book AND submission_id = submission AND kind = 'claim' AND mode = 'send';
    status_arg := coalesce(latest.kind, 'queued'); due := coalesce(latest.available_at, definition.recorded_at);
    IF status_arg = 'claim' THEN
        IF latest.lease_expires_at > clock_timestamp() THEN status_arg := 'inflight'; ELSE status_arg := 'uncertain'; END IF;
        due := latest.lease_expires_at;
    ELSIF status_arg IN ('retry', 'not_found', 'queued') THEN
        IF attempts >= definition.max_attempts THEN status_arg := 'exhausted'; ELSIF status_arg = 'not_found' THEN status_arg := 'retry'; END IF;
    END IF;
    RETURN jsonb_build_object('status', status_arg, 'latest_event_id', latest.id, 'available_at', due,
        'attempts', attempts, 'receipt_reference', latest.receipt_reference, 'ready', due <= clock_timestamp() AND status_arg IN ('queued', 'retry', 'uncertain'),
        'mode', CASE WHEN status_arg = 'uncertain' THEN 'lookup' ELSE 'send' END);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_statutory_stamp() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    UPDATE ledger_books SET next_sequence = next_sequence + 1 WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id RETURNING next_sequence INTO NEW.sequence;
    NEW.recorded_at := clock_timestamp(); NEW.actor_id := current_setting('noria.ledger_actor'); NEW.firm_id := nullif(current_setting('noria.ledger_firm', true), '');
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_statutory_sendable(tenant uuid, book uuid, submission uuid)
RETURNS boolean LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE definition ledger_statutory_submissions%ROWTYPE; declaration ledger_tax_returns%ROWTYPE; preceding ledger_tax_returns%ROWTYPE; return_state text; cutoff bigint;
BEGIN
    SELECT * INTO STRICT definition FROM ledger_statutory_submissions WHERE tenant_id = tenant AND book_id = book AND id = submission;
    IF NOT EXISTS (SELECT 1 FROM ledger_books WHERE tenant_id = tenant AND id = book AND deleted_at IS NULL) THEN RETURN false; END IF;
    IF definition.original_id IS NOT NULL AND ledger_statutory_state(tenant, book, definition.original_id)->>'status' <> 'accepted' THEN RETURN false; END IF;
    IF definition.journal_id IS NOT NULL AND EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = tenant AND book_id = book AND reverses_id = definition.journal_id) THEN RETURN false; END IF;
    IF definition.return_id IS NOT NULL THEN
        SELECT kind INTO return_state FROM ledger_tax_return_events WHERE tenant_id = tenant AND book_id = book AND return_id = definition.return_id ORDER BY sequence DESC LIMIT 1;
        IF return_state IS DISTINCT FROM 'finalize' OR EXISTS (SELECT 1 FROM ledger_tax_returns WHERE tenant_id = tenant AND book_id = book AND previous_id = definition.return_id) THEN RETURN false; END IF;
        SELECT * INTO STRICT declaration FROM ledger_tax_returns WHERE tenant_id = tenant AND book_id = book AND id = definition.return_id;
        SELECT next_sequence INTO STRICT cutoff FROM ledger_books WHERE tenant_id = tenant AND id = book;
        IF ledger_tax_return_result(tenant, book, declaration.configuration_id, declaration.scheme, declaration.starts_on, declaration.effective_on, cutoff, declaration.opening_credit_minor) IS DISTINCT FROM declaration.result_json THEN RETURN false; END IF;
        IF declaration.prior_period_id IS NOT NULL THEN
            SELECT * INTO STRICT preceding FROM ledger_tax_returns WHERE tenant_id = tenant AND book_id = book AND id = declaration.prior_period_id;
            IF EXISTS (SELECT 1 FROM ledger_tax_returns r JOIN ledger_tax_return_events e ON e.tenant_id = r.tenant_id AND e.book_id = r.book_id AND e.return_id = r.id AND e.kind = 'file'
                WHERE r.tenant_id = tenant AND r.book_id = book AND r.scheme = preceding.scheme AND r.starts_on = preceding.starts_on AND r.effective_on = preceding.effective_on AND r.sequence > preceding.sequence) THEN RETURN false; END IF;
        END IF;
    END IF;
    RETURN true;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_statutory_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE definition ledger_statutory_submissions%ROWTYPE; original ledger_statutory_submissions%ROWTYPE; replaced ledger_statutory_submissions%ROWTYPE;
    claim ledger_statutory_events%ROWTYPE; source jsonb; state jsonb; reserved numeric; return_state text;
BEGIN
    PERFORM 1 FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id FOR UPDATE;
    IF TG_TABLE_NAME = 'ledger_statutory_submissions' THEN
        PERFORM ledger_require_live_book(NEW.tenant_id, NEW.book_id);
        source := ledger_statutory_source(NEW.tenant_id, NEW.book_id, NEW.journal_id, NEW.return_id);
        IF NEW.kind IS DISTINCT FROM source->>'kind' THEN RAISE EXCEPTION 'Statutory kind does not match its immutable source'; END IF;
        IF NEW.content_type = 'application/json' AND jsonb_typeof(NEW.payload::jsonb) <> 'object' THEN RAISE EXCEPTION 'JSON statutory payloads must be objects'; END IF;
        NEW.payload_hash := encode(sha256(convert_to(NEW.payload, 'UTF8')), 'hex');
        NEW.source_hash := encode(sha256(convert_to(source::text, 'UTF8')), 'hex');
        NEW.source_currency := source->>'currency'; NEW.source_scale := (source->>'scale')::integer; NEW.gross_minor := (source->>'gross_minor')::numeric;
        IF NEW.kind = 'invoice' AND EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND reverses_id = NEW.journal_id) THEN RAISE EXCEPTION 'Do not queue an already reversed invoice'; END IF;
        IF NEW.kind = 'tax_return' THEN
            SELECT kind INTO return_state FROM ledger_tax_return_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND return_id = NEW.return_id ORDER BY sequence DESC LIMIT 1;
            IF return_state IS DISTINCT FROM 'finalize' OR EXISTS (SELECT 1 FROM ledger_tax_returns WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND previous_id = NEW.return_id) THEN RAISE EXCEPTION 'Queue the current finalized tax return version'; END IF;
        END IF;
        IF NEW.replaces_id IS NOT NULL THEN
            SELECT * INTO STRICT replaced FROM ledger_statutory_submissions WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.replaces_id;
            state := ledger_statutory_state(NEW.tenant_id, NEW.book_id, replaced.id);
            IF state->>'status' NOT IN ('rejected', 'cancelled', 'exhausted') OR (NEW.kind, NEW.journal_id, NEW.return_id, NEW.channel, NEW.environment, NEW.adapter)
                IS DISTINCT FROM (replaced.kind, replaced.journal_id, replaced.return_id, replaced.channel, replaced.environment, replaced.adapter) THEN RAISE EXCEPTION 'Replace only a definitively unaccepted submission for the same source and route'; END IF;
        ELSIF EXISTS (SELECT 1 FROM ledger_statutory_submissions WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND channel = NEW.channel AND environment = NEW.environment
            AND journal_id IS NOT DISTINCT FROM NEW.journal_id AND return_id IS NOT DISTINCT FROM NEW.return_id) THEN RAISE EXCEPTION 'Source already has a statutory submission; use its retry or replacement identity'; END IF;
        IF NEW.kind = 'credit_note' THEN
            SELECT * INTO STRICT original FROM ledger_statutory_submissions WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.original_id;
            IF original.kind <> 'invoice' OR ledger_statutory_state(NEW.tenant_id, NEW.book_id, original.id)->>'status' <> 'accepted' OR (NEW.channel, NEW.environment, NEW.adapter, NEW.source_currency, NEW.source_scale)
                IS DISTINCT FROM (original.channel, original.environment, original.adapter, original.source_currency, original.source_scale)
                OR (source->>'reverses_id' IS NOT NULL AND (source->>'reverses_id')::uuid <> original.journal_id) THEN RAISE EXCEPTION 'Credit note must reference its invoice on the same statutory route and currency'; END IF;
            IF NEW.replaces_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_statutory_submissions WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = replaced.original_id AND journal_id = original.journal_id) THEN RAISE EXCEPTION 'A replacement credit cannot change its original financial invoice'; END IF;
            IF EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
                WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.journal_id AND a.is_control AND NOT EXISTS
                    (SELECT 1 FROM ledger_postings o WHERE o.tenant_id = p.tenant_id AND o.book_id = p.book_id AND o.journal_id = original.journal_id AND o.account_id = p.account_id AND o.party_id = p.party_id)) THEN RAISE EXCEPTION 'Credit counterparty is absent from the referenced invoice'; END IF;
            SELECT coalesce(sum(c.gross_minor), 0) INTO reserved FROM ledger_statutory_submissions c JOIN ledger_statutory_submissions p ON p.tenant_id = c.tenant_id AND p.book_id = c.book_id AND p.id = c.original_id
                WHERE c.tenant_id = NEW.tenant_id AND c.book_id = NEW.book_id AND p.journal_id = original.journal_id AND c.channel = NEW.channel AND c.environment = NEW.environment
                    AND ledger_statutory_state(c.tenant_id, c.book_id, c.id)->>'status' NOT IN ('rejected', 'cancelled', 'exhausted');
            IF reserved + NEW.gross_minor > original.gross_minor THEN RAISE EXCEPTION 'Statutory credits exceed the referenced invoice gross amount'; END IF;
        END IF;
    ELSE
        SELECT * INTO STRICT definition FROM ledger_statutory_submissions WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.submission_id;
        state := ledger_statutory_state(NEW.tenant_id, NEW.book_id, definition.id);
        NEW.channel := definition.channel; NEW.environment := definition.environment;
        NEW.available_at := NEW.recorded_at + make_interval(secs => NEW.delay_seconds);
        IF NEW.kind = 'claim' THEN
            IF NOT (state->>'ready')::boolean OR NEW.mode IS DISTINCT FROM state->>'mode' THEN RAISE EXCEPTION 'Submission is not ready for this worker claim'; END IF;
            IF NEW.lease_seconds IS NULL OR NEW.lease_seconds NOT BETWEEN 1 AND 900 THEN RAISE EXCEPTION 'Invalid statutory worker lease'; END IF;
            NEW.lease_expires_at := NEW.recorded_at + make_interval(secs => NEW.lease_seconds);
            IF NEW.mode = 'send' THEN
                IF NOT ledger_statutory_sendable(NEW.tenant_id, NEW.book_id, definition.id) THEN RAISE EXCEPTION 'Statutory source is not currently sendable'; END IF;
            END IF;
        ELSIF NEW.kind = 'cancelled' THEN
            IF state->>'status' NOT IN ('queued', 'retry', 'exhausted') THEN RAISE EXCEPTION 'Only definitively unsent or unaccepted delivery can be cancelled'; END IF;
        ELSE
            IF NEW.claim_id IS NULL THEN RAISE EXCEPTION 'Statutory outcomes require their current worker claim'; END IF;
            SELECT * INTO STRICT claim FROM ledger_statutory_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.claim_id;
            IF claim.submission_id <> NEW.submission_id OR claim.kind <> 'claim' OR claim.id IS DISTINCT FROM (state->>'latest_event_id')::uuid THEN RAISE EXCEPTION 'Statutory worker claim was superseded; reject the stale outcome'; END IF;
            IF NEW.kind = 'not_found' AND claim.mode <> 'lookup' OR NEW.kind = 'retry' AND claim.mode <> 'send' THEN RAISE EXCEPTION 'Outcome is incompatible with the claimed delivery operation'; END IF;
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

DO $body$
DECLARE name text;
BEGIN
    FOREACH name IN ARRAY ARRAY['ledger_statutory_submissions', 'ledger_statutory_events'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', name);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', name);
        EXECUTE format('CREATE POLICY ledger_tenant ON %I USING (tenant_id::text = nullif(current_setting(''noria.ledger_tenant'', true), '''')) WITH CHECK (tenant_id::text = nullif(current_setting(''noria.ledger_tenant'', true), ''''))', name);
        EXECUTE format('CREATE TRIGGER stage_10_scope BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard()', name);
        EXECUTE format('CREATE TRIGGER stage_20_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION ledger_immutable()', name);
        EXECUTE format('CREATE TRIGGER stage_10_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable()', name);
        EXECUTE format('CREATE TRIGGER stage_30_stamp BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_statutory_stamp()', name);
        EXECUTE format('CREATE TRIGGER stage_40_validate BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_statutory_validate()', name);
        EXECUTE format('CREATE TRIGGER stage_90_audit AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_audit()', name);
    END LOOP;
END;
$body$;
