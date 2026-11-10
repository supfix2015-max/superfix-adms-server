# Optional legacy SQL Server connection (Att2000_V4)

This branch prepares **read-only schema inspection** for a legacy SQL Server attendance database. It does not import any rows, change employees, write fingerprint templates, or change the ADMS production database.

## Separation of concerns

- **MySQL** remains the primary database for the Laravel ADMS app and ZKTeco device uploads.
- **SQL Server** is a separate connection named `legacy_sqlsrv`, used for reading metadata to inform an audited, repeatable import plan.
- No tables are migrated automatically and no device is enrolled or resynchronized.

## Configuration

The Dockerfile now installs Microsoft ODBC Driver 18 and the PHP `sqlsrv` / `pdo_sqlsrv` extensions.

Use **Coolify environment variables**, never commit credentials:

```dotenv
DB_CONNECTION=mysql
LEGACY_SQLSRV_HOST=<private-address-or-vpn-hostname>
LEGACY_SQLSRV_PORT=1433
LEGACY_SQLSRV_DATABASE=Att2000_V4
LEGACY_SQLSRV_USERNAME=<read-only-username>
LEGACY_SQLSRV_PASSWORD=<secret>
LEGACY_SQLSRV_ENCRYPT=yes
LEGACY_SQLSRV_TRUST_SERVER_CERTIFICATE=false
```

If SQL Server is hosted on a private office network, connect the VPS through a VPN or private network. Do **not** expose TCP 1433 publicly. Use a SQL Server login with SELECT permissions only, scoped to the legacy attendance database; avoid sysadmin or db_owner grants.

ODBC Driver 18 enforces encryption by default. Use a trusted TLS certificate; do not disable validation in production.

## Discovery commands

After rebuilding the container and configuring the legacy connection:

```bash
php -m | grep -E 'sqlsrv|pdo_sqlsrv'
php artisan config:clear
php artisan adms:inspect-legacy-sqlserver
php artisan adms:inspect-legacy-sqlserver --table=dbo.USERINFO
php artisan adms:inspect-legacy-sqlserver --table=dbo.CHECKINOUT
```

The commands query only `INFORMATION_SCHEMA.TABLES` and `INFORMATION_SCHEMA.COLUMNS`; they do not read employee rows or biometric payloads. Table names shown above are examples, **not yet verified** against the actual `Att2000_V4` database.

## Next phase (not part of this PR)

1. Inspect actual employee, punch, and biometric template columns and keys.
2. Design an explicit mapping to the existing Laravel `employees`, `attendance_logs`, `employee_biometrics` / `biometric_templates` tables.
3. Introduce an idempotent, dry-run-first importer with audit counts, deduplication, and conflict reporting.
4. Verify template encoding and ZKTeco device compatibility before any attempted fingerprint synchronization.
5. Test on backup databases, cut over only after data reconciliation, and keep a rollback plan.

**Never** run `php artisan migrate` with `DB_CONNECTION=legacy_sqlsrv`. That would target the old database.
