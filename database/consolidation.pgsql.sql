CREATE TABLE ledger_consolidation_runs (
    tenant_id uuid NOT NULL, book_id uuid NOT NULL, id uuid NOT NULL,
    idempotency_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL,
    effective_on date NOT NULL, inputs jsonb NOT NULL CHECK (jsonb_typeof(inputs) = 'object'), result jsonb NOT NULL CHECK (jsonb_typeof(result) = 'object'),
    result_hash text NOT NULL,
    recorded_at timestamptz NOT NULL, sequence bigint NOT NULL, actor_id varchar(128) NOT NULL, firm_id varchar(128),
    PRIMARY KEY (tenant_id, book_id, id), UNIQUE (tenant_id, book_id, idempotency_key),
    FOREIGN KEY (tenant_id, book_id) REFERENCES ledger_books (tenant_id, id)
);

CREATE OR REPLACE FUNCTION ledger_consolidation_stamp() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    NEW.result_hash := encode(sha256(convert_to(NEW.result::text, 'UTF8')), 'hex');
    RETURN NEW;
END $$;

ALTER TABLE ledger_consolidation_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger_consolidation_runs FORCE ROW LEVEL SECURITY;
CREATE POLICY ledger_tenant ON ledger_consolidation_runs USING (tenant_id::text = nullif(current_setting('noria.ledger_tenant', true), ''))
    WITH CHECK (tenant_id::text = nullif(current_setting('noria.ledger_tenant', true), ''));
CREATE TRIGGER a_scope BEFORE INSERT OR UPDATE ON ledger_consolidation_runs FOR EACH ROW EXECUTE FUNCTION ledger_scope_guard();
CREATE TRIGGER b_immutable BEFORE UPDATE OR DELETE ON ledger_consolidation_runs FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER a_no_truncate BEFORE TRUNCATE ON ledger_consolidation_runs FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable();
CREATE TRIGGER c_stamp BEFORE INSERT ON ledger_consolidation_runs FOR EACH ROW EXECUTE FUNCTION ledger_evidence_stamp();
CREATE TRIGGER d_hash BEFORE INSERT ON ledger_consolidation_runs FOR EACH ROW EXECUTE FUNCTION ledger_consolidation_stamp();
CREATE TRIGGER z_audit AFTER INSERT ON ledger_consolidation_runs FOR EACH ROW EXECUTE FUNCTION ledger_audit();
