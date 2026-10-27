CREATE TABLE ledger_revaluations (
    tenant_id uuid NOT NULL,
    id uuid NOT NULL,
    book_id uuid NOT NULL,
    item_id uuid NOT NULL,
    idempotency_key varchar(128) NOT NULL,
    fingerprint char(64) NOT NULL,
    effective_on date NOT NULL,
    delta_minor numeric NOT NULL CHECK (delta_minor = trunc(delta_minor) AND abs(delta_minor) < power(10::numeric, 38)),
    rate numeric(38, 18) NOT NULL CHECK (rate > 0),
    rate_as_of date NOT NULL, rate_source varchar(256) NOT NULL,
    rounding varchar(16) NOT NULL CHECK (rounding IN ('half_even', 'half_up', 'down')),
    journal_id uuid,
    reverses_id uuid,
    reason varchar(1024) NOT NULL,
    recorded_at timestamptz NOT NULL,
    sequence bigint NOT NULL,
    actor_id varchar(128) NOT NULL,
    firm_id varchar(128),
    PRIMARY KEY (tenant_id, book_id, id),
    UNIQUE (tenant_id, book_id, idempotency_key),
    UNIQUE (tenant_id, book_id, journal_id),
    UNIQUE (tenant_id, book_id, reverses_id),
    FOREIGN KEY (tenant_id, book_id, item_id) REFERENCES ledger_open_items (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, journal_id) REFERENCES ledger_journals (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, reverses_id) REFERENCES ledger_revaluations (tenant_id, book_id, id),
    CHECK ((delta_minor = 0) = (journal_id IS NULL))
);

CREATE INDEX ledger_revaluations_item ON ledger_revaluations (tenant_id, book_id, item_id, sequence);

CREATE TABLE ledger_revaluation_clearings (
    tenant_id uuid NOT NULL, book_id uuid NOT NULL, item_id uuid NOT NULL, id uuid NOT NULL,
    idempotency_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL, effective_on date NOT NULL, reason varchar(1024) NOT NULL,
    journal_ids jsonb NOT NULL CHECK (jsonb_typeof(journal_ids) = 'array'),
    recorded_at timestamptz NOT NULL, sequence bigint NOT NULL, actor_id varchar(128) NOT NULL, firm_id varchar(128),
    PRIMARY KEY (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, item_id, idempotency_key),
    FOREIGN KEY (tenant_id, book_id, item_id) REFERENCES ledger_open_items (tenant_id, book_id, id)
);

CREATE OR REPLACE FUNCTION ledger_revaluation_clearing_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    IF EXISTS (SELECT 1 FROM ledger_revaluations r WHERE r.tenant_id = NEW.tenant_id AND r.book_id = NEW.book_id AND r.item_id = NEW.item_id
        AND r.reverses_id IS NULL AND r.delta_minor <> 0 AND NOT EXISTS (SELECT 1 FROM ledger_revaluations x
            WHERE x.tenant_id = r.tenant_id AND x.book_id = r.book_id AND x.reverses_id = r.id))
        OR (SELECT count(DISTINCT value) FROM jsonb_array_elements_text(NEW.journal_ids)) <> jsonb_array_length(NEW.journal_ids)
        OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(NEW.journal_ids) j WHERE NOT EXISTS (SELECT 1 FROM ledger_revaluations r
            WHERE r.tenant_id = NEW.tenant_id AND r.book_id = NEW.book_id AND r.item_id = NEW.item_id AND r.reverses_id IS NOT NULL
                AND r.journal_id = j.value::uuid AND r.effective_on = NEW.effective_on)) THEN
        RAISE EXCEPTION 'Revaluation clearing must reference exact corrections and leave no active valuation';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_revaluation_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    item record;
    original ledger_revaluations%ROWTYPE;
    allocated numeric;
    allocated_functional numeric;
    adjusted numeric;
    expected numeric;
    raw_delta numeric;
    functional_code text;
    target_scale integer;
    source_scale integer;
BEGIN
    SELECT p.*, j.effective_on AS posted_on INTO STRICT item FROM ledger_open_items oi
        JOIN ledger_postings p USING (tenant_id, book_id, journal_id, line_no)
        JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
        WHERE oi.tenant_id = NEW.tenant_id AND oi.book_id = NEW.book_id AND oi.id = NEW.item_id;
    SELECT b.currency_code, c.scale INTO STRICT functional_code, target_scale FROM ledger_books b
        JOIN ledger_currencies c ON c.tenant_id = b.tenant_id AND c.code = b.currency_code WHERE b.tenant_id = NEW.tenant_id AND b.id = NEW.book_id;
    SELECT scale INTO STRICT source_scale FROM ledger_currencies WHERE tenant_id = NEW.tenant_id AND code = item.currency_code;
    IF item.currency_code = functional_code OR NEW.effective_on < item.posted_on OR NEW.rate_as_of > NEW.effective_on
        OR EXISTS (SELECT 1 FROM ledger_allocations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND (debit_item_id = NEW.item_id OR credit_item_id = NEW.item_id) AND effective_on > NEW.effective_on)
        OR EXISTS (SELECT 1 FROM ledger_revaluations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
            AND item_id = NEW.item_id AND effective_on > NEW.effective_on) THEN
        RAISE EXCEPTION 'Revaluation requires foreign currency and chronological item history';
    END IF;
    IF NEW.reverses_id IS NOT NULL THEN
        SELECT * INTO STRICT original FROM ledger_revaluations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
        IF original.reverses_id IS NOT NULL OR original.item_id <> NEW.item_id OR NEW.delta_minor <> -original.delta_minor
            OR NEW.rate <> original.rate OR NEW.rounding <> original.rounding OR NEW.rate_as_of <> original.rate_as_of OR NEW.rate_source <> original.rate_source
            OR NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
                AND id = NEW.journal_id AND reverses_id = original.journal_id AND effective_on = NEW.effective_on) THEN
            RAISE EXCEPTION 'Revaluation reversal must restore the exact original carrying adjustment';
        END IF;
        RETURN NEW;
    END IF;
    SELECT coalesce(sum(CASE WHEN reverses_id IS NULL THEN amount_minor ELSE -amount_minor END), 0),
        coalesce(sum(CASE WHEN item.side = 'debit' THEN CASE WHEN reverses_id IS NULL THEN debit_functional_minor ELSE -debit_functional_minor END
            ELSE CASE WHEN reverses_id IS NULL THEN credit_functional_minor ELSE -credit_functional_minor END END), 0)
    INTO allocated, allocated_functional FROM ledger_allocations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
        AND (debit_item_id = NEW.item_id OR credit_item_id = NEW.item_id);
    IF allocated >= item.amount_minor THEN
        RAISE EXCEPTION 'Settled open items cannot be revalued';
    END IF;
    SELECT coalesce(sum(delta_minor), 0) INTO adjusted FROM ledger_revaluations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND item_id = NEW.item_id;
    expected := ledger_fraction((item.amount_minor - allocated) * NEW.rate * power(10::numeric, target_scale), power(10::numeric, source_scale), NEW.rounding)
        - (item.functional_minor - allocated_functional + adjusted);
    IF NEW.delta_minor <> expected THEN
        RAISE EXCEPTION 'Incorrect open-item revaluation carrying amount';
    END IF;
    raw_delta := NEW.delta_minor * CASE WHEN item.side = 'debit' THEN 1 ELSE -1 END;
    IF raw_delta <> 0 AND (NOT EXISTS (SELECT 1 FROM ledger_journals WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id
        AND id = NEW.journal_id AND effective_on = NEW.effective_on AND jsonb_array_length(postings) = 2
        AND metadata->>'revaluation_id' = NEW.id::text AND reverses_id IS NULL AND NOT closing)
        OR NOT EXISTS (SELECT 1 FROM ledger_postings WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.journal_id
            AND account_id = item.account_id AND party_id = item.party_id AND currency_code = item.currency_code AND valuation
            AND dimensions = item.dimensions AND rate = NEW.rate AND rounding = NEW.rounding AND rate_as_of = NEW.rate_as_of AND rate_source = NEW.rate_source AND functional_minor = abs(raw_delta)
            AND side = CASE WHEN raw_delta > 0 THEN 'debit' ELSE 'credit' END)
        OR NOT EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
            WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.journal_id AND NOT p.valuation
            AND p.functional_minor = abs(raw_delta) AND a.type = CASE WHEN raw_delta > 0 THEN 'income' ELSE 'expense' END
            AND p.side = CASE WHEN raw_delta > 0 THEN 'credit' ELSE 'debit' END)) THEN
        RAISE EXCEPTION 'Revaluation journal does not match its carrying-value evidence';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_allocation_revaluation_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
BEGIN
    IF EXISTS (SELECT 1 FROM ledger_revaluations r WHERE r.tenant_id = NEW.tenant_id AND r.book_id = NEW.book_id
        AND r.item_id IN (NEW.debit_item_id, NEW.credit_item_id) AND r.effective_on > NEW.effective_on) THEN
        RAISE EXCEPTION 'Allocation cannot precede the item revaluation history';
    END IF;
    IF EXISTS (SELECT 1 FROM ledger_revaluations r WHERE r.tenant_id = NEW.tenant_id AND r.book_id = NEW.book_id
        AND r.item_id IN (NEW.debit_item_id, NEW.credit_item_id) AND r.reverses_id IS NULL AND r.delta_minor <> 0
        AND NOT EXISTS (SELECT 1 FROM ledger_revaluations x WHERE x.tenant_id = r.tenant_id AND x.book_id = r.book_id AND x.reverses_id = r.id)) THEN
        RAISE EXCEPTION 'Clear unrealized revaluations before allocating or releasing an item';
    END IF;
    RETURN NEW;
END;
$body$;

ALTER TABLE ledger_revaluations ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger_revaluations FORCE ROW LEVEL SECURITY;
CREATE POLICY ledger_tenant ON ledger_revaluations USING (tenant_id::text = nullif(current_setting('noria.ledger_tenant', true), ''))
    WITH CHECK (tenant_id::text = nullif(current_setting('noria.ledger_tenant', true), ''));
CREATE TRIGGER a_scope BEFORE INSERT OR UPDATE ON ledger_revaluations FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard();
CREATE TRIGGER b_immutable BEFORE UPDATE OR DELETE ON ledger_revaluations FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER a_no_truncate BEFORE TRUNCATE ON ledger_revaluations FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER c_stamp BEFORE INSERT ON ledger_revaluations FOR EACH ROW EXECUTE FUNCTION ledger_event_stamp();
CREATE TRIGGER d_validate BEFORE INSERT ON ledger_revaluations FOR EACH ROW EXECUTE FUNCTION ledger_revaluation_validate();
CREATE TRIGGER z_audit AFTER INSERT ON ledger_revaluations FOR EACH ROW EXECUTE FUNCTION ledger_audit();
CREATE TRIGGER d_revaluations BEFORE INSERT ON ledger_allocations FOR EACH ROW EXECUTE FUNCTION ledger_allocation_revaluation_guard();

ALTER TABLE ledger_revaluation_clearings ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger_revaluation_clearings FORCE ROW LEVEL SECURITY;
CREATE POLICY ledger_tenant ON ledger_revaluation_clearings USING (tenant_id::text = nullif(current_setting('noria.ledger_tenant', true), ''))
    WITH CHECK (tenant_id::text = nullif(current_setting('noria.ledger_tenant', true), ''));
CREATE TRIGGER a_scope BEFORE INSERT OR UPDATE ON ledger_revaluation_clearings FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard();
CREATE TRIGGER b_immutable BEFORE UPDATE OR DELETE ON ledger_revaluation_clearings FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER a_no_truncate BEFORE TRUNCATE ON ledger_revaluation_clearings FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER c_stamp BEFORE INSERT ON ledger_revaluation_clearings FOR EACH ROW EXECUTE FUNCTION ledger_event_stamp();
CREATE TRIGGER d_validate BEFORE INSERT ON ledger_revaluation_clearings FOR EACH ROW EXECUTE FUNCTION ledger_revaluation_clearing_validate();
CREATE TRIGGER z_audit AFTER INSERT ON ledger_revaluation_clearings FOR EACH ROW EXECUTE FUNCTION ledger_audit();
