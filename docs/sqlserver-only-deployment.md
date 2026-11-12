# Superfix ADMS — ONE SQL Server database (experimental branch)

**Branch:** feature/single-att2000-adms-schema-20261112

## One database, two schemas — no new databases

| Database | Schema | Purpose |
|---|---|---|
| Att2000_V4 | dbo | Existing historical Employee, Attendance, Device, EmployeeTemplate. Do not delete/alter. |
| Att2000_V4 | adms | Laravel-owned users, devices, commands, operational attendance logs, queues, etc. |

No MySQL is required. `AttendanceCloud` and `AttendanceCloudV2` are not used by this branch. Keep them unchanged for rollback until acceptance.

The default operational Laravel `sqlsrv` login is `adms_v2_app`, with DEFAULT_SCHEMA=adms and SELECT/INSERT/UPDATE/DELETE scoped only to adms schema. Historical source is read by the existing limited `legacy_sqlsrv` login. Optional historical attendance INSERT uses its separately allowlisted `legacy_sqlsrv_writer` login, **OFF by default**.

## Before deploy or migration

1. Back up `Att2000_V4` to a verifiable SQL Server .bak file, and copy off-server.
2. Run `database/sql/setup_att2000_adms_schema.sql` as sa inside original `Att2000_V4`, after inspection of its T-SQL. It only adds the adms schema and maps EXISTING `adms_v2_setup` / `adms_v2_app` server logins into it. Does not create a new database or modify old dbo tables.
3. Set the isolated Coolify V2 application env to:
```dotenv
APP_ENV=staging
APP_DEBUG=false
DB_CONNECTION=sqlsrv
ADMS_SQLSRV_HOST=superfix-attendance-sql
ADMS_SQLSRV_PORT=1433
ADMS_SQLSRV_DATABASE=Att2000_V4
ADMS_SQLSRV_SCHEMA=adms
ADMS_SQLSRV_USERNAME=adms_v2_app
ADMS_SQLSRV_PASSWORD=<existing_secret>
LEGACY_SQLSRV_DATABASE=Att2000_V4
LEGACY_SQLSRV_USERNAME=adms_legacy_reader
LEGACY_SQLSRV_PASSWORD=<existing_secret>
LEGACY_SQLSRV_BROWSER_ENABLED=true
ADMS_PILOT_ENABLED=false
LEGACY_SQLSRV_ATTENDANCE_WRITE_ENABLED=false
ZKTECO_DISPATCH_COMMANDS=false
ZKTECO_AUTO_REGISTER_DEVICES=false
ZKTECO_REQUIRE_TOKEN=true
```
4. Deploy only the isolated V2 test application, not the existing at2/master production route.
5. Run read-only `php artisan adms:sqlserver-only-audit`. It must report `Att2000_V4` with app schema `adms`, both connections on original DB, no app INSERT/UPDATE/DELETE on dbo.Attendance, and zero or the expected number of Laravel tables in adms schema.
6. With setup credentials **only for a single command**, run `php artisan migrate --database=sqlsrv --force` (NOT `migrate:fresh`). The PHP app bootstrap checks connected DB name and SQL login's DEFAULT_SCHEMA=adms BEFORE artisan can run.
7. Seed only the new adms.users app admin, not original dbo.Employee. Then switch to runtime account and test `/admin/dashboard`, login, SQL attendance browser, commands OFF and raw logs. No physical device redirection yet.

## SQL Server migration exception fixed

Migration 2026_04_17_000007 originally failed on `device_commands_device_sync_id_foreign` due to two cascade-delete paths from device to command via device_syncs. This branch replaces the problematic `ON DELETE SET NULL` edge with `NO ACTION` and scopes raw filtered unique indexes explicitly to `adms.attendance_logs` and `adms.employees`.

## Safety

The previous partially completed migration was on `AttendanceCloudV2`, NOT on `Att2000_V4`. No reset of that database is required. This branch creates a fresh isolated Laravel schema in the same original DB.

AppServiceProvider checks `DB_NAME()=Att2000_V4` and `SCHEMA_NAME()=adms` before handling requests or migrations. If either mismatches, application stops. This branch has NOT been exercised yet on the user's live VPS; treat it as experimental until migration and smoke testing succeed.

The original user requested no new databases, no MySQL, and to complete dashboard+ADMS on existing VPS before buying a new VPS. Respect that deployment order.
