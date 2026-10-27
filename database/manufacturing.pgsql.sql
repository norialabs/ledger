CREATE TABLE ledger_productions (
    tenant_id uuid NOT NULL, book_id uuid NOT NULL, id uuid NOT NULL,
    idempotency_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL, effective_on date NOT NULL,
    output_pool_id uuid NOT NULL, output_movement_id uuid NOT NULL, output_quantity numeric NOT NULL,
    output_value_minor numeric NOT NULL, conversion_minor numeric NOT NULL DEFAULT 0,
    wip_account_id uuid NOT NULL,
    inputs jsonb NOT NULL CHECK (jsonb_typeof(inputs) = 'array' AND jsonb_array_length(inputs) BETWEEN 1 AND 10000),
    reverses_id uuid, reason varchar(1024) NOT NULL,
    recorded_at timestamptz NOT NULL, sequence bigint NOT NULL, actor_id varchar(128) NOT NULL, firm_id varchar(128),
    PRIMARY KEY (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, idempotency_key), UNIQUE (tenant_id, book_id, reverses_id),
    UNIQUE (tenant_id, book_id, output_movement_id),
    FOREIGN KEY (tenant_id, book_id, output_pool_id) REFERENCES ledger_inventory_pools (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, output_movement_id) REFERENCES ledger_inventory_moves (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, wip_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, reverses_id) REFERENCES ledger_productions (tenant_id, book_id, id),
    CHECK ((reverses_id IS NULL AND output_quantity > 0 AND output_value_minor >= 0 AND conversion_minor >= 0) OR (reverses_id IS NOT NULL AND output_quantity < 0 AND output_value_minor <= 0 AND conversion_minor <= 0)),
    CHECK (output_quantity = trunc(output_quantity, 12) AND abs(output_quantity) < power(10::numeric, 26)),
    CHECK (output_value_minor = trunc(output_value_minor) AND abs(output_value_minor) < power(10::numeric, 38)),
    CHECK (conversion_minor = trunc(conversion_minor) AND abs(conversion_minor) < power(10::numeric, 38))
);

ALTER TABLE ledger_inventory_moves ADD COLUMN production_id uuid;
ALTER TABLE ledger_inventory_moves ADD CONSTRAINT ledger_inventory_production_exclusive CHECK (production_id IS NULL OR (transfer_id IS NULL AND returns_id IS NULL));
ALTER TABLE ledger_inventory_moves ADD CONSTRAINT ledger_inventory_production_fk FOREIGN KEY (tenant_id, book_id, production_id)
    REFERENCES ledger_productions (tenant_id, book_id, id) DEFERRABLE INITIALLY DEFERRED;
CREATE INDEX ledger_inventory_production_members ON ledger_inventory_moves (tenant_id, book_id, production_id) WHERE production_id IS NOT NULL;

CREATE OR REPLACE FUNCTION ledger_production_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE output ledger_inventory_moves%ROWTYPE; input_moves jsonb; input_move record; input_value numeric := 0; original ledger_productions%ROWTYPE;
    available numeric;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.wip_account_id
        AND type = 'asset' AND NOT is_group AND NOT is_control AND (NEW.reverses_id IS NOT NULL OR (active AND deleted_at IS NULL)) AND cash_flow IS DISTINCT FROM 'cash'
        AND coalesce(currency_code, (SELECT currency_code FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id)) = (SELECT currency_code FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id)) THEN
        RAISE EXCEPTION 'Production requires a functional non-cash balance-sheet WIP asset account';
    END IF;
    SELECT * INTO STRICT output FROM ledger_inventory_moves WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.output_movement_id;
    IF output.pool_id <> NEW.output_pool_id OR output.production_id IS DISTINCT FROM NEW.id OR output.quantity <> NEW.output_quantity
        OR output.value_minor <> NEW.output_value_minor OR output.effective_on <> NEW.effective_on
        OR output.counter_account_id <> NEW.wip_account_id OR output.transfer_id IS NOT NULL OR output.returns_id IS NOT NULL THEN
        RAISE EXCEPTION 'Production output must match its finished-goods receipt and WIP counter';
    END IF;
    input_moves := NEW.inputs;
    IF (SELECT count(DISTINCT value->>'movement_id') FROM jsonb_array_elements(input_moves)) <> jsonb_array_length(input_moves) THEN
        RAISE EXCEPTION 'Production component movements must be unique';
    END IF;
    IF NEW.reverses_id IS NULL THEN
        IF output.kind <> 'receipt' THEN RAISE EXCEPTION 'Production output must be a finished-goods receipt'; END IF;
    ELSE
        SELECT * INTO STRICT original FROM ledger_productions WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
        IF original.reverses_id IS NOT NULL OR NEW.output_pool_id <> original.output_pool_id OR NEW.wip_account_id <> original.wip_account_id
            OR NEW.output_quantity <> -original.output_quantity OR NEW.output_value_minor <> -original.output_value_minor
            OR NEW.conversion_minor <> -original.conversion_minor OR jsonb_array_length(NEW.inputs) <> jsonb_array_length(original.inputs)
            OR output.kind <> 'reversal' OR output.reverses_id IS DISTINCT FROM original.output_movement_id THEN
            RAISE EXCEPTION 'A production reversal must restore the original output and inputs exactly';
        END IF;
    END IF;
    FOR input_move IN SELECT value FROM jsonb_array_elements(input_moves) LOOP
        IF jsonb_typeof(input_move.value) <> 'object' OR NOT (input_move.value ?& ARRAY['movement_id', 'pool_id', 'quantity', 'value_minor']) THEN
            RAISE EXCEPTION 'Invalid production input plan';
        END IF;
        PERFORM 1 FROM ledger_inventory_moves m WHERE m.tenant_id = NEW.tenant_id AND m.book_id = NEW.book_id AND m.id = (input_move.value->>'movement_id')::uuid
            AND m.pool_id = (input_move.value->>'pool_id')::uuid AND m.quantity = (input_move.value->>'quantity')::numeric
            AND m.value_minor = (input_move.value->>'value_minor')::numeric AND m.production_id = NEW.id
            AND ((NEW.reverses_id IS NULL AND m.kind = 'issue' AND m.quantity < 0 AND m.value_minor <= 0)
                OR (NEW.reverses_id IS NOT NULL AND m.kind = 'reversal' AND m.quantity > 0 AND m.value_minor >= 0
                    AND EXISTS (SELECT 1 FROM jsonb_array_elements(original.inputs) o WHERE o->>'movement_id' = m.reverses_id::text)))
            AND m.counter_account_id = NEW.wip_account_id AND m.effective_on = NEW.effective_on AND m.transfer_id IS NULL AND m.returns_id IS NULL
            AND m.pool_id <> NEW.output_pool_id;
        IF NOT FOUND THEN RAISE EXCEPTION 'Production inputs must match their component issue movements'; END IF;
        input_value := input_value - (input_move.value->>'value_minor')::numeric;
    END LOOP;
    IF input_value + NEW.conversion_minor <> NEW.output_value_minor THEN RAISE EXCEPTION 'Production must conserve exact component and conversion costs'; END IF;
    IF NEW.conversion_minor > 0 THEN
        SELECT coalesce(sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END), 0) INTO available
            FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
            WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.account_id = NEW.wip_account_id AND j.effective_on <= NEW.effective_on;
        IF available < 0 THEN RAISE EXCEPTION 'Production conversion costs exceed recognized WIP'; END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_production_wip_links() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE previous_tenant text := coalesce(current_setting('noria.ledger_tenant', true), '');
BEGIN
    PERFORM set_config('noria.ledger_tenant', NEW.tenant_id::text, true);
    IF EXISTS (SELECT 1 FROM ledger_productions r WHERE r.tenant_id = NEW.tenant_id AND r.book_id = NEW.book_id AND r.conversion_minor > 0
        AND r.effective_on >= NEW.effective_on AND EXISTS (SELECT 1 FROM ledger_postings p WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id
            AND p.journal_id = NEW.id AND p.account_id = r.wip_account_id)
        AND (SELECT coalesce(sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END), 0)
            FROM ledger_postings p JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
            WHERE p.tenant_id = r.tenant_id AND p.book_id = r.book_id AND p.account_id = r.wip_account_id AND j.effective_on <= r.effective_on) < 0) THEN
        RAISE EXCEPTION 'WIP corrections cannot invalidate recognized production conversion costs';
    END IF;
    PERFORM set_config('noria.ledger_tenant', previous_tenant, true);
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_production_links() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE previous_tenant text := coalesce(current_setting('noria.ledger_tenant', true), '');
BEGIN
    PERFORM set_config('noria.ledger_tenant', NEW.tenant_id::text, true);
    IF NEW.production_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_productions p WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id
        AND p.id = NEW.production_id AND (p.output_movement_id = NEW.id OR p.inputs @> jsonb_build_array(jsonb_build_object('movement_id', NEW.id::text)))) THEN
        RAISE EXCEPTION 'Production movements require their matching atomic production';
    END IF;
    PERFORM set_config('noria.ledger_tenant', previous_tenant, true);
    RETURN NEW;
END;
$body$;

ALTER TABLE ledger_productions ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger_productions FORCE ROW LEVEL SECURITY;
CREATE POLICY ledger_tenant ON ledger_productions USING (tenant_id::text = nullif(current_setting('noria.ledger_tenant', true), ''))
    WITH CHECK (tenant_id::text = nullif(current_setting('noria.ledger_tenant', true), ''));
CREATE TRIGGER a_scope BEFORE INSERT OR UPDATE ON ledger_productions FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard();
CREATE TRIGGER b_immutable BEFORE UPDATE OR DELETE ON ledger_productions FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER a_no_truncate BEFORE TRUNCATE ON ledger_productions FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER c_stamp BEFORE INSERT ON ledger_productions FOR EACH ROW EXECUTE FUNCTION ledger_event_stamp();
CREATE TRIGGER d_validate BEFORE INSERT ON ledger_productions FOR EACH ROW EXECUTE FUNCTION ledger_production_validate();
CREATE TRIGGER z_audit AFTER INSERT ON ledger_productions FOR EACH ROW EXECUTE FUNCTION ledger_audit();
CREATE CONSTRAINT TRIGGER zz_ledger_production_links AFTER INSERT ON ledger_inventory_moves
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_production_links();
CREATE CONSTRAINT TRIGGER zz_ledger_production_wip_links AFTER INSERT ON ledger_journals
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_production_wip_links();
