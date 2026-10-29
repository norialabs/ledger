CREATE OR REPLACE FUNCTION ledger_journal_links() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE
    previous_tenant text := coalesce(current_setting('noria.ledger_tenant', true), '');
BEGIN
    PERFORM set_config('noria.ledger_tenant', NEW.tenant_id::text, true);
    IF NEW.reverses_id IS NOT NULL AND EXISTS (SELECT 1 FROM ledger_batch_members original WHERE original.tenant_id = NEW.tenant_id
        AND original.book_id = NEW.book_id AND original.journal_id = NEW.reverses_id
        AND NOT EXISTS (SELECT 1 FROM ledger_batch_members corrected JOIN ledger_batches b ON b.tenant_id = corrected.tenant_id AND b.id = corrected.batch_id
            WHERE corrected.tenant_id = NEW.tenant_id AND corrected.book_id = NEW.book_id AND corrected.journal_id = NEW.id AND b.reverses_id = original.batch_id)) THEN
        RAISE EXCEPTION 'Batch journals require their matching atomic batch reversal';
    END IF;
    IF EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
        WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.id AND p.valuation AND a.is_control)
       AND NOT EXISTS (SELECT 1 FROM ledger_allocations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND fx_journal_id = NEW.id)
       AND NOT EXISTS (SELECT 1 FROM ledger_revaluations WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id) THEN
        RAISE EXCEPTION 'Control-account valuations require a corresponding settlement or revaluation';
    END IF;
    IF EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_inventory_pools i ON i.tenant_id = p.tenant_id AND i.book_id = p.book_id AND i.account_id = p.account_id
        WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.id)
       AND NOT EXISTS (SELECT 1 FROM ledger_inventory_moves WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id) THEN
        RAISE EXCEPTION 'Inventory accounts require a matching stock valuation movement';
    END IF;
    IF EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_assets a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id
        AND p.account_id IN (a.cost_account_id, a.depreciation_account_id, a.impairment_account_id)
        WHERE p.tenant_id = NEW.tenant_id AND p.book_id = NEW.book_id AND p.journal_id = NEW.id)
        AND NOT EXISTS (SELECT 1 FROM ledger_assets WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id)
        AND NOT EXISTS (SELECT 1 FROM ledger_asset_events WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND journal_id = NEW.id) THEN
        RAISE EXCEPTION 'Asset valuation accounts require a corresponding asset event';
    END IF;
    IF NEW.closing AND NEW.reverses_id IS NULL AND NOT EXISTS (SELECT 1 FROM ledger_year_closures WHERE tenant_id = NEW.tenant_id
        AND book_id = NEW.book_id AND journal_id = NEW.id) THEN
        RAISE EXCEPTION 'Closing journals require a corresponding fiscal closure';
    END IF;
    IF NEW.closing AND NEW.reverses_id IS NULL AND EXISTS (SELECT 1 FROM ledger_year_closures c
        JOIN ledger_journals j ON j.tenant_id = c.tenant_id AND j.book_id = c.book_id AND j.effective_on <= c.ends_on
        JOIN ledger_postings p ON p.tenant_id = j.tenant_id AND p.book_id = j.book_id AND p.journal_id = j.id
        JOIN ledger_accounts a ON a.tenant_id = p.tenant_id AND a.book_id = p.book_id AND a.id = p.account_id
        WHERE c.tenant_id = NEW.tenant_id AND c.book_id = NEW.book_id AND c.journal_id = NEW.id AND a.type IN ('income', 'expense')
        GROUP BY p.account_id, p.dimensions HAVING sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END) <> 0) THEN
        RAISE EXCEPTION 'Fiscal closure must leave earnings cleared when the transaction commits';
    END IF;
    PERFORM set_config('noria.ledger_tenant', previous_tenant, true);
    RETURN NEW;
END;
$body$;

CREATE OR REPLACE FUNCTION ledger_finance_account_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $body$
DECLARE data jsonb := to_jsonb(NEW); account_ids uuid[];
BEGIN
    PERFORM 1 FROM ledger_books WHERE tenant_id = NEW.tenant_id AND id = NEW.book_id FOR UPDATE;
    SELECT array_agg(value::uuid) INTO account_ids FROM jsonb_each_text(data) WHERE key LIKE '%account_id' AND value IS NOT NULL;
    IF EXISTS (SELECT 1 FROM ledger_finance_contracts WHERE tenant_id = NEW.tenant_id AND book_id = NEW.book_id AND
        (carrying_account_id = ANY(account_ids) OR allowance_account_id = ANY(account_ids) OR asset_account_id = ANY(account_ids) OR liability_account_id = ANY(account_ids) OR refund_account_id = ANY(account_ids))) THEN RAISE EXCEPTION 'Registered finance accounts cannot enter another valuation register'; END IF;
    RETURN NEW;
END;
$body$;

CREATE CONSTRAINT TRIGGER stage_99_integrity_journal_links AFTER INSERT ON ledger_journals
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_journal_links();
CREATE CONSTRAINT TRIGGER stage_99_integrity_production_wip_links AFTER INSERT ON ledger_journals
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_production_wip_links();
CREATE CONSTRAINT TRIGGER stage_99_integrity_associate_links AFTER INSERT ON ledger_journals
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_associate_links();
CREATE CONSTRAINT TRIGGER stage_99_integrity_monetary_links AFTER INSERT ON ledger_journals
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_monetary_links();
CREATE CONSTRAINT TRIGGER stage_99_integrity_finance_links AFTER INSERT ON ledger_journals
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_finance_links();
CREATE TRIGGER stage_40_revaluations BEFORE INSERT ON ledger_allocations FOR EACH ROW EXECUTE FUNCTION ledger_allocation_revaluation_guard();

DO $body$
DECLARE name text;
BEGIN
    FOREACH name IN ARRAY ARRAY['ledger_assets', 'ledger_inventory_pools', 'ledger_associates', 'ledger_monetary_accounts', 'ledger_allowance_runs'] LOOP
        EXECUTE format('CREATE TRIGGER stage_21_finance_accounts BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION ledger_finance_account_guard()', name);
    END LOOP;
END;
$body$;
