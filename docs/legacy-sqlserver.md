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

## Verified source schema (from SQL Server metadata, 2026-11-10)

The user confirmed the following column mappings in `Att2000_V4`:

| Legacy table and column | Laravel target / intended use |
| --- | --- |
| `Employee.BadgeNumber` | `employees.employee_id` (external employee code; check for duplicates) |
| `Employee.NAME` | `employees.name` |
| `Employee.CardNo` | `employees.card_no` |
| `Attendance.BadgeNumber` | `attendance_logs.employee_code` |
| `Attendance.VerifyTime` | `attendance_logs.log_time` (confirm timezone and source semantics) |
| `Attendance.DeviceSerialNumber` | link to `devices.serial_number` |
| `Device.DeviceSerialNumber` | `devices.serial_number` (verify physical device ownership and ADMS protocol) |
| `EmployeeTemplate.BadgeNumber` | link template to employee by code, not by internal numeric PK |
| `EmployeeTemplate.Template` (`image`) | binary template; format and compatibility need validation before sync |

The source contained 334 `Employee` records, 584,196 `Attendance` records, 771 `EmployeeTemplate` records, and 22 `Device` records when checked. Counts may change and do not prove all physical devices are online. The `ShiftedAttendance` table must not be merged blindly with `Attendance`; it may represent a derived view of punches rather than independent physical events.

For a **read-only** connection/schema/data-quality audit of these four verified tables, run:

```bash
php artisan adms:audit-legacy-attendance
```

This checks required columns, approximate counts, missing/duplicated employee badge numbers, latest legacy punch timestamp, and counts grouped by template type/format. It does not read template payloads or write records. Ensure the database login is read-only. The live fingerprint/employee importer and bulk device sync are **not** part of this change.

## Reconciliation result (2026-11-10)

Read-only comparison against the existing MySQL application found: 334 SQL Server employee rows, 2 existing MySQL rows, 1 missing badge, 4 missing names, no duplicate trimmed badge identifiers, no nonnumeric badge identifiers, and 329 eligible new employees. No source employees match MySQL employee identifiers as currently stored.

## Safe employee import workflow

An additional command is available:

```bash
php artisan adms:import-legacy-employees
```

This is always a **dry-run** unless `--apply` is explicitly provided. It skips missing/duplicate/nonnumeric badge identifiers, empty names, and employees already in MySQL. It never updates existing employees. Incoming badges are preserved exactly, including leading zeroes.

**Do not import into the live MySQL database yet.** Create a dedicated staging MySQL database and restore a **verified backup** of the current ADMS MySQL schema/data into it. Use a separate staging database login with permissions limited to that staging database, and a container with `APP_ENV=staging` and `DB_DATABASE=adms_import_staging`. Do not copy the production application's `APP_KEY`, service access tokens, or device secrets unnecessarily into this staging environment. Keep the staging HTTP ADMS endpoints inaccessible to physical devices.

Only on isolated staging, the limited **write** command is:

```bash
php artisan adms:import-legacy-employees --apply --limit=10
```

Write mode is blocked unless `APP_ENV` is `staging`, `testing` or `local`, and the configured target MySQL database name **ends in `_staging`**, with a limit between 1 and 500.

New records are inserted with `status=INACTIVE`, `sync_enabled=false`, and no `sync_uid` or device association. Neither fingerprint templates nor historical attendance logs are imported by this command. An import may be repeated safely: existing employee codes are preserved.

Review sample records in staging, and ensure no unauthorized device commands are queued or executed. After these checks, design a separate, deduplicated, incremental attendance import and a read-only biometric compatibility report.
