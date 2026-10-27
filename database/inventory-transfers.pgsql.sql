CREATE TABLE ledger_inventory_transfers (
    tenant_id uuid NOT NULL, book_id uuid NOT NULL, id uuid NOT NULL,
    idempotency_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL, effective_on date NOT NULL,
    source_pool_id uuid NOT NULL, destination_pool_id uuid NOT NULL, issue_id uuid NOT NULL, receipt_id uuid NOT NULL,
    quantity numeric NOT NULL CHECK (quantity = trunc(quantity, 12) AND abs(quantity) < power(10::numeric, 26) AND quantity <> 0),
    value_minor numeric NOT NULL CHECK (value_minor = trunc(value_minor) AND abs(value_minor) < power(10::numeric, 38)),
    clearing_account_id uuid NOT NULL, reverses_id uuid, reason varchar(1000) NOT NULL,
    recorded_at timestamptz NOT NULL, sequence bigint NOT NULL, actor_id varchar(128) NOT NULL, firm_id varchar(128),
    PRIMARY KEY (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, idempotency_key), UNIQUE (tenant_id, book_id, reverses_id),
    UNIQUE (tenant_id, book_id, issue_id), UNIQUE (tenant_id, book_id, receipt_id),
    FOREIGN KEY (tenant_id, book_id, source_pool_id) REFERENCES ledger_inventory_pools (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, destination_pool_id) REFERENCES ledger_inventory_pools (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, issue_id) REFERENCES ledger_inventory_moves (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, receipt_id) REFERENCES ledger_inventory_moves (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, reverses_id) REFERENCES ledger_inventory_transfers (tenant_id, book_id, id),
    FOREIGN KEY (tenant_id, book_id, clearing_account_id) REFERENCES ledger_accounts (tenant_id, book_id, id),
    CHECK (source_pool_id <> destination_pool_id), CHECK (issue_id <> receipt_id),
    CHECK ((reverses_id IS NULL AND quantity > 0 AND value_minor >= 0) OR (reverses_id IS NOT NULL AND quantity < 0 AND value_minor <= 0))
);

ALTER TABLE ledger_inventory_moves ADD COLUMN transfer_id uuid;
ALTER TABLE ledger_inventory_moves ADD CONSTRAINT ledger_inventory_transfer_fk FOREIGN KEY (tenant_id, book_id, transfer_id)
    REFERENCES ledger_inventory_transfers (tenant_id, book_id, id) DEFERRABLE INITIALLY DEFERRED;
CREATE INDEX ledger_inventory_transfer_members ON ledger_inventory_moves (tenant_id, book_id, transfer_id) WHERE transfer_id IS NOT NULL;

CREATE OR REPLACE FUNCTION ledger_inventory_transfer_validate() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE issue ledger_inventory_moves%ROWTYPE; receipt ledger_inventory_moves%ROWTYPE; original ledger_inventory_transfers%ROWTYPE;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.clearing_account_id
        AND type IN ('asset', 'liability') AND NOT is_group AND NOT is_control AND cash_flow IS DISTINCT FROM 'cash') THEN
        RAISE EXCEPTION 'Stock transfers require a non-cash balance-sheet clearing account';
    END IF;
    SELECT * INTO STRICT issue FROM ledger_inventory_moves WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.issue_id;
    SELECT * INTO STRICT receipt FROM ledger_inventory_moves WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.receipt_id;
    IF issue.transfer_id IS DISTINCT FROM NEW.id OR receipt.transfer_id IS DISTINCT FROM NEW.id
        OR issue.pool_id <> NEW.source_pool_id OR receipt.pool_id <> NEW.destination_pool_id
        OR issue.quantity <> -NEW.quantity OR receipt.quantity <> NEW.quantity OR issue.value_minor <> -NEW.value_minor OR receipt.value_minor <> NEW.value_minor
        OR issue.counter_account_id <> NEW.clearing_account_id OR receipt.counter_account_id <> NEW.clearing_account_id
        OR issue.effective_on <> NEW.effective_on OR receipt.effective_on <> NEW.effective_on
        OR (SELECT sku FROM ledger_inventory_pools WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.source_pool_id)
            IS DISTINCT FROM (SELECT sku FROM ledger_inventory_pools WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.destination_pool_id) THEN
        RAISE EXCEPTION 'A stock transfer must conserve the same SKU quantity and exact cost across both pools';
    END IF;
    IF NEW.reverses_id IS NULL THEN
        IF issue.kind <> 'issue' OR receipt.kind <> 'receipt' THEN RAISE EXCEPTION 'Invalid stock transfer movement kinds'; END IF;
    ELSE
        SELECT * INTO STRICT original FROM ledger_inventory_transfers WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND id = NEW.reverses_id;
        IF original.reverses_id IS NOT NULL OR issue.reverses_id IS DISTINCT FROM original.issue_id OR receipt.reverses_id IS DISTINCT FROM original.receipt_id
            OR NEW.source_pool_id <> original.source_pool_id OR NEW.destination_pool_id <> original.destination_pool_id
            OR NEW.quantity <> -original.quantity OR NEW.value_minor <> -original.value_minor OR NEW.clearing_account_id <> original.clearing_account_id THEN
            RAISE EXCEPTION 'A transfer reversal must restore both original movements exactly';
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_inventory_links() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE previous_tenant text := coalesce(current_setting('noria.ledger_tenant', true), '');
BEGIN
    PERFORM set_config('noria.ledger_tenant', NEW.tenant_id::text, true);
    IF NEW.transfer_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_inventory_transfers t WHERE t.tenant_id = NEW.tenant_id
        AND t.book_id = NEW.book_id AND t.id = NEW.transfer_id AND NEW.id IN (t.issue_id, t.receipt_id)) THEN
        RAISE EXCEPTION 'Stock transfer movements require their matching atomic transfer';
    END IF;
    PERFORM set_config('noria.ledger_tenant', previous_tenant, true);
    RETURN NEW;
END;
$body$;

ALTER TABLE ledger_inventory_transfers ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger_inventory_transfers FORCE ROW LEVEL SECURITY;
CREATE POLICY ledger_tenant ON ledger_inventory_transfers USING (tenant_id::text = nullif(current_setting('noria.ledger_tenant', true), ''))
    WITH CHECK (tenant_id::text = nullif(current_setting('noria.ledger_tenant', true), ''));
CREATE TRIGGER a_scope BEFORE INSERT OR UPDATE ON ledger_inventory_transfers FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard();
CREATE TRIGGER b_immutable BEFORE UPDATE OR DELETE ON ledger_inventory_transfers FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER a_no_truncate BEFORE TRUNCATE ON ledger_inventory_transfers FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER c_stamp BEFORE INSERT ON ledger_inventory_transfers FOR EACH ROW EXECUTE FUNCTION ledger_event_stamp();
CREATE TRIGGER d_validate BEFORE INSERT ON ledger_inventory_transfers FOR EACH ROW EXECUTE FUNCTION ledger_inventory_transfer_validate();
CREATE TRIGGER z_audit AFTER INSERT ON ledger_inventory_transfers FOR EACH ROW EXECUTE FUNCTION ledger_audit();
CREATE CONSTRAINT TRIGGER zz_ledger_inventory_links AFTER INSERT ON ledger_inventory_moves
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_inventory_links();
