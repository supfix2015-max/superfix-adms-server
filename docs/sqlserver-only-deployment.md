# SQL Server-only operational ADMS branch

Branch: feature/sqlserver-only-application-20261112. NOT DEPLOYED OR PRODUCTION-READY.

Use two databases in ONE SQL Server container:
- Att2000_V4: original historical data, read-only, never migrate or alter.
- AttendanceCloud: Laravel users, devices, commands, raw_logs, attendance_logs, jobs, app metadata.

SQL Server-only code: default sqlsrv, old mysql connection and pdo_mysql removed, queue DB SQL Server, SQL Server-compatible enum/status migrations, filtered unique indexes for nullable hashes/UIDs, and guarded startup. This is code preparation, NOT a validated production cutover.

Do not redeploy the existing at2 application with this new branch. Create an ISOLATED Coolify app, using a NEW or verified-empty operational SQL Server database and a separate app SQL login. Keep legacy database separate and read-only. Secure database credentials as Coolify secrets; do not publish them.

Config for ISOLATED app:
DB_CONNECTION=sqlsrv
ADMS_SQLSRV_HOST=superfix-attendance-sql
ADMS_SQLSRV_PORT=1433
ADMS_SQLSRV_DATABASE=AttendanceCloud
ADMS_SQLSRV_USERNAME=<separate_app_sql_login>
ADMS_SQLSRV_PASSWORD=<Coolify_secret>
ADMS_SQLSRV_ENCRYPT=yes
ADMS_SQLSRV_TRUST_SERVER_CERTIFICATE=true # internal isolated test only
LEGACY_SQLSRV_HOST=superfix-attendance-sql
LEGACY_SQLSRV_DATABASE=Att2000_V4
LEGACY_SQLSRV_USERNAME=adms_legacy_reader
LEGACY_SQLSRV_PASSWORD=<existing_read_only_secret>
LEGACY_SQLSRV_BROWSER_ENABLED=true
ADMS_PILOT_ENABLED=false
LEGACY_SQLSRV_ATTENDANCE_WRITE_ENABLED=false
ZKTECO_DISPATCH_COMMANDS=false
ZKTECO_AUTO_REGISTER_DEVICES=false
ZKTECO_REQUIRE_TOKEN=true

BEFORE any migrate: inspect AttendanceCloud for existing tables, back it up off-server, and verify schema target is NOT Att2000_V4. If not empty or unclear, use a NEW operational database instead of overwriting.

Read-only verification: php artisan adms:sqlserver-only-audit
Migration on a VERIFIED EMPTY dedicated operational SQL Server DB ONLY: php artisan migrate --force
Provision a new admin after migration with UserSeeder and INITIAL_ADMIN_EMAIL / INITIAL_ADMIN_PASSWORD in secured deployment variables. The MySQL admin session/login does not transfer automatically.

Do NOT delete MySQL databases yet; stopping app dependency does not mean their recovery snapshots are unnecessary. Confirm application login, route auth, queue tables, dashboard, ADMS ingestion, and idempotency on SQL Server before any deletion.

Device CEG9223661162: source has one historical registration, last known SQL punch 2026-11-04 08:00:31. Existing staging pilot is off and serial not allowlisted. No recent upload has been proven. Leave physical devices unchanged until SQLServer-only app passes testing.

Certificate trust exceptions must be replaced by proper TLS validation before production. No public SQL Server port.
