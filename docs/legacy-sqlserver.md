# Original Att2000_V4 on SQL Server — direct read-only access

No SQL Server-to-MySQL data conversion is required.

The **original** `Att2000_V4` SQL Server 2025 database stays untouched on Hostinger, with its tables `Employee`, `Attendance`, `Device`, and `EmployeeTemplate`. New ADMS device uploads and all control-plane tables **continue to use MySQL**, while the admin can browse the original historical records directly over the Docker `coolify` network. There is no bi-directional sync in this release.

## What was implemented

- Microsoft ODBC Driver 18, PHP `pdo_sqlsrv`, `sqlsrv` in the application's Dockerfile.
- Independent `legacy_sqlsrv` connection using a `SELECT`-only SQL login.
- An opt-in, admin-only `/admin/legacy` page, with four tabs:
  - Employees: `Employee.Id`, `BadgeNumber`, `NAME`, `DepartmentId`, `CardNo`, `HiredDay`
  - Attendance: `Attendance.Id`, `BadgeNumber`, `VerifyTime`, `VerifyType`, `Status`, `DeviceSerialNumber`, with date and badge/device filters
  - Devices: serial, alias, model, historical status, IP, push protocol. **Historical registrations are not proof the devices are online.**
  - Template metadata: fingerprint template type/index/version/format only; **never fetches or displays the binary `Template` column**
- Links from existing dashboard, Employees, Attendance, and Devices screens, shown only when the feature is enabled.
- No SQL Server migrations, writes, imports, fingerprint replay, or device command dispatches in these routes.
- Existing ADMS `/iclock` endpoints and MySQL models remain unchanged.

## Test deployment configuration

Deploy the draft branch to a **separate non-public test environment** before merging. The original app/database must keep running.

Use Coolify environment variables (never commit secrets):

```dotenv
APP_ENV=staging
DB_CONNECTION=mysql
# Keep existing operational MySQL DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD
LEGACY_SQLSRV_HOST=superfix-attendance-sql
LEGACY_SQLSRV_PORT=1433
LEGACY_SQLSRV_DATABASE=Att2000_V4
LEGACY_SQLSRV_USERNAME=adms_legacy_reader
LEGACY_SQLSRV_PASSWORD=<enter-in-Coolify-secrets>
LEGACY_SQLSRV_ENCRYPT=yes
LEGACY_SQLSRV_TRUST_SERVER_CERTIFICATE=false
LEGACY_SQLSRV_BROWSER_ENABLED=true
```

Both the SQL Server container and the test Laravel container must be attached to the `coolify` Docker network. SQL Server should **not** expose TCP/1433 publicly.

**TLS:** ODBC Driver 18 verifies certificates by default. The correct production solution is a trusted server certificate matching the container's DNS name. The earlier `LEGACY_SQLSRV_TRUST_SERVER_CERTIFICATE=true` workaround was only for short internal tests; do not use that insecure bypass for production. Keep `LEGACY_SQLSRV_BROWSER_ENABLED=false` until TLS and access controls are verified.

**Database user:** `adms_legacy_reader` is granted SELECT privileges only on the four legacy tables. Avoid `sa` in Laravel; do not enable write permissions.

## Verification

After rebuilding and configuring on staging:

```bash
php -m | grep -Ei '^(pdo_sqlsrv|sqlsrv)$'
php artisan route:list --path=admin/legacy
php artisan config:clear
php artisan adms:inspect-legacy-sqlserver
php artisan adms:audit-legacy-attendance
```

Then sign in as an authorized admin and open `/admin/legacy`. Compare the counts with the original SQL Server result: about 334 employees, 584,196 historical attendance rows, 22 registered devices, and 771 template records (snapshot from 2026-11-10). New incoming records may still land in MySQL; this is **not** a single-database cutover.

## Next phase — device cutover (NOT implemented)

Before reconnecting every ZKTeco device, decide where new attendance punches must be stored. To write to original SQL Server, a separate, tested adapter must implement `Attendance` INSERT semantics and duplicate protections, and reconcile `BadgeNumber`/device serial. This phase must not run while the legacy SQL login is read-only. Templates need explicit compatibility checks before being replayed to devices. The existing ADMS pilot serial allowlist should remain enabled, with devices moved **one by one**, beginning with UA300.

No legacy database write, full network cutover, or SQL Server schema modification has been performed by this branch.
