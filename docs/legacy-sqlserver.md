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

## Complete employee import workflow (including incomplete records)

The import policy now includes **every** `Att2000_V4.dbo.Employee` row, even if `BadgeNumber` or `NAME` is null/empty. The source SQL `Employee.Id` acts as a stable legacy record identifier. For missing or duplicated badges, the staging importer uses an **internal-only** `LEGACY_REVIEW_ID_<Id>` identifier in Laravel's `employees.employee_id`, **not** a genuine employee badge or device PIN. The original badge (if any), source SQL ID, and review flags are retained in `employees.raw_payload`.

Empty names get a visible fallback `Legacy employee <Id> (name unavailable)` so no employee record is dropped. Numeric badge identifiers are preserved as text, including leading zeros; nonnumeric badges are retained but remain unsyncable. Existing MySQL records are never overwritten. Existing codes may already be present and will be reported as preserved rather than inserted. **All newly imported records are `INACTIVE` and `sync_enabled=false`**; the importer sends no ADMS commands and copies no biometric templates.

For the observed 334 legacy rows (1 missing badge, 4 blank names, 0 duplicate badges and 0 matches with the 2 current MySQL rows), the expected dry-run is **334 new candidates**, for a combined count of approximately **336** after a complete staging import. A subsequent rerun should report 0 new candidates when all 334 imported rows already exist.

**Do not import into live MySQL yet.** Create a distinct staging MySQL database, restore/replicate the current ADMS MySQL schema and data from a verified backup, and use a separate staging MySQL login. Never point staging to the production DB. Use a non-public staging container without physical device ADMS ingress.

After setting `DB_CONNECTION=mysql`, `APP_ENV=staging`, `DB_DATABASE=adms_import_staging` and the remaining staging MySQL + read-only legacy SQL Server credentials:

```bash
# Read-only preview, includes incomplete records:
php artisan adms:import-legacy-employees

# Staging-only writes, limited batch for review:
php artisan adms:import-legacy-employees --apply --limit=10

# After checking staging results, import remaining eligible rows:
php artisan adms:import-legacy-employees --apply --limit=500
```

Write mode is blocked unless `APP_ENV` is staging/testing/local, the MySQL database name ends with `_staging`, and `--limit` is 1–500. This is not a substitute for least-privilege MySQL credentials and a verified backup.

**Before rolling this out to production**, review the five incomplete legacy records, reconcile historical attendance mapping, and test that changing employee status cannot trigger premature device synchronization. SQL `Employee.Id` is **not** the ADMS ZKTeco PIN, and any internal placeholder must never be sent to a device.
