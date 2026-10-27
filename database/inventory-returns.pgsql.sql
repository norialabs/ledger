CREATE INDEX ledger_inventory_return_history ON ledger_inventory_moves (tenant_id, book_id, returns_id) WHERE returns_id IS NOT NULL;

CREATE OR REPLACE FUNCTION ledger_inventory_return_chronology(tenant uuid, book uuid, issue uuid, date_arg date)
RETURNS void LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
BEGIN
    IF EXISTS (SELECT 1 FROM ledger_inventory_moves r LEFT JOIN ledger_inventory_moves x
        ON x.tenant_id = r.tenant_id AND x.book_id = r.book_id AND x.reverses_id = r.id
        WHERE r.tenant_id = tenant AND r.book_id = book AND r.returns_id = issue AND greatest(r.effective_on, x.effective_on) > date_arg) THEN
        RAISE EXCEPTION 'Stock return dates must follow the original issue return history';
    END IF;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_inventory_supplier_return_quote(tenant uuid, book uuid, pool uuid, receipt uuid, date_arg date, units numeric)
RETURNS jsonb LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE original ledger_inventory_moves%ROWTYPE; definition ledger_inventory_pools%ROWTYPE; returned_units numeric; returned_value numeric;
    commercial numeric; layer record; quote jsonb; portion numeric;
BEGIN
    SELECT * INTO STRICT original FROM ledger_inventory_moves WHERE tenant_id = tenant AND book_id = book AND id = receipt;
    SELECT * INTO STRICT definition FROM ledger_inventory_pools WHERE tenant_id = tenant AND book_id = book AND id = pool;
    IF original.kind <> 'receipt' OR original.transfer_id IS NOT NULL OR original.pool_id <> pool OR date_arg < original.effective_on OR units >= 0
        OR EXISTS (SELECT 1 FROM ledger_inventory_moves WHERE tenant_id = tenant AND book_id = book AND reverses_id = receipt) THEN
        RAISE EXCEPTION 'A supplier return requires an active purchase receipt in the same valuation pool';
    END IF;
    PERFORM ledger_inventory_return_chronology(tenant, book, receipt, date_arg);
    SELECT coalesce(sum(r.quantity), 0), coalesce(sum(r.commercial_minor), 0) INTO returned_units, returned_value
        FROM ledger_inventory_moves r WHERE r.tenant_id = tenant AND r.book_id = book AND r.returns_id = receipt
        AND NOT EXISTS (SELECT 1 FROM ledger_inventory_moves x WHERE x.tenant_id = tenant AND x.book_id = book AND x.reverses_id = r.id);
    IF -units > original.quantity + returned_units THEN RAISE EXCEPTION 'Supplier return exceeds the original receipt returnable quantity'; END IF;
    commercial := -ledger_fraction(-units * (original.value_minor + returned_value), original.quantity + returned_units, 'half_even');
    IF definition.method = 'fifo' THEN
        SELECT * INTO layer FROM ledger_inventory_layers(tenant, book, pool) WHERE layer_id = receipt;
        IF layer.layer_id IS NULL OR -units > layer.quantity THEN RAISE EXCEPTION 'Supplier return exceeds the original receipt remaining FIFO layer'; END IF;
        portion := -ledger_fraction(-units * layer.value_minor, layer.quantity, 'half_even');
        quote := jsonb_build_object('value_minor', portion::text, 'plan', jsonb_build_array(jsonb_build_object('layer_id', receipt,
            'quantity', units::numeric(38, 12)::text, 'value_minor', portion::text)));
    ELSE
        quote := ledger_inventory_quote(tenant, book, pool, 'issue', units, 0);
    END IF;
    RETURN quote || jsonb_build_object('commercial_minor', commercial::text);
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_inventory_return_quote(tenant uuid, book uuid, pool uuid, issue uuid, date_arg date, units numeric)
RETURNS jsonb LANGUAGE plpgsql STABLE SET search_path FROM CURRENT AS $body$
DECLARE original ledger_inventory_moves%ROWTYPE; returned_units numeric; returned_value numeric; value numeric;
BEGIN
    SELECT * INTO STRICT original FROM ledger_inventory_moves WHERE tenant_id = tenant AND book_id = book AND id = issue;
    IF original.kind <> 'issue' OR original.transfer_id IS NOT NULL OR date_arg < original.effective_on OR units <= 0
        OR EXISTS (SELECT 1 FROM ledger_inventory_moves WHERE tenant_id = tenant AND book_id = book AND reverses_id = issue)
        OR (SELECT sku FROM ledger_inventory_pools WHERE tenant_id = tenant AND book_id = book AND id = original.pool_id)
            IS DISTINCT FROM (SELECT sku FROM ledger_inventory_pools WHERE tenant_id = tenant AND book_id = book AND id = pool) THEN
        RAISE EXCEPTION 'A stock return requires an active original issue and a compatible SKU';
    END IF;
    PERFORM ledger_inventory_return_chronology(tenant, book, issue, date_arg);
    SELECT coalesce(sum(r.quantity), 0), coalesce(sum(r.value_minor), 0) INTO returned_units, returned_value
        FROM ledger_inventory_moves r WHERE r.tenant_id = tenant AND r.book_id = book AND r.returns_id = issue
        AND NOT EXISTS (SELECT 1 FROM ledger_inventory_moves x WHERE x.tenant_id = tenant AND x.book_id = book AND x.reverses_id = r.id);
    IF units > -original.quantity - returned_units THEN RAISE EXCEPTION 'Stock return exceeds the original issue returnable quantity'; END IF;
    value := ledger_fraction(units * (-original.value_minor - returned_value), -original.quantity - returned_units, 'half_even');
    RETURN jsonb_build_object('value_minor', value::text, 'plan', '[]'::jsonb);
END;
$body$;
