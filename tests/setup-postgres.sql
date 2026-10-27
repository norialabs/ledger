DO $body$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'noria_ledger_test') THEN
        CREATE ROLE noria_ledger_test LOGIN PASSWORD 'ledger_test_only' NOSUPERUSER NOBYPASSRLS;
    END IF;
END;
$body$;

SELECT 'CREATE DATABASE noria_ledger_test OWNER noria_ledger_test'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'noria_ledger_test')\gexec
