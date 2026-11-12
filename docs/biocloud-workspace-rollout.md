# ADMS Enterprise — operational feature rollout (not a BioCloud visual clone)

This replaces earlier BioCloud styling guidance. The user's screenshots of the old ZKTeco BioCloud console are functional references **only**. The production UI must keep the original ADMS Enterprise navy/neutral visual style, spacing and branding. The custom clone layout and CSS were removed.

## Canonical pages & full navigation

The left sidebar is grouped into five clearly labelled sections. All links are real authenticated admin routes; some original data tables may be unavailable to the limited reader and will show an explanatory notice instead of fabricating data.

- **Overview**: Dashboard and System Status
- **Employees & Organization**: Employee Directory, Departments, Areas, Positions, Holidays, Timetables, Shifts, Department Schedules, Employee Schedules, Temporary Schedules, Employee Leave, ADMS Employees, Credentials & Enrolment, Missing Credentials
- **Devices & Commands**: Device Fleet & Licenses, Device Connectivity, Original Device Registry, Register Device, Device Commands, New Device Command
- **Attendance & Reports**: Attendance Transactions, Live Attendance, First & Last, First In / Last Out, Multi In / Out, Daily Attendance, Monthly Attendance, Attendance Summary
- **System & Audit**: ADMS Request Logs, Template Metadata, Original Employee Source

**Canonical device page** is `/admin/devices`, not a separately styled BioCloud page. The existing `DeviceController@index` delegates to the original SQL + live adms combined fleet when the original SQL viewer is enabled; `/admin/fleet` redirects to the canonical URL as a compatibility alias.

Other source pages:
- `/admin/directory/employee` (and department, area, position, holiday, timetable, shift, department-schedule, employee-schedule, temporary-schedule, employee-leave)
- `/admin/legacy?tab=attendance`, `/admin/attendance-reports/daily`, `/admin/attendance-reports/monthly` etc.
- `/admin/commands`, `/admin/device-connectivity`, `/admin/logs`
- `/admin/dashboard` existing enterprise dashboard.

## Per-device license toggle

One dedicated `adms.devices.requires_license` bit per device; persisted via a guarded targeted migration. In the actual fleet table and Edit Device form, the operator can choose **Requires Edit License** or **Does not require license**, save and refresh. This is administrative metadata, NOT a vendor license activation protocol. No license keys or biometric payloads are stored or transmitted.

## Existing & new device operations

- Existing device details, operational alias and optional endpoint fields, register device, recent contact status, original serial roster matching
- Queue status, authenticated command actions including Download Employees, Download Transactions, Reboot, and Clear Attendance Data after explicit confirmation
- Clear Pending Command cancels only UNSENT `adms.device_commands` for that serial; never sent or completed commands
- Destructive unsupported `Clear All Data` remains disabled; template transfer and employee sync workflows remain behind existing verification & token requirements
- ADMS application read-only `dbo` access is separate from the per-device `adms` schema. Direct Hili attendance writer remains scoped, with least-privilege SQL login.

## Coolify deployment (V2 only)

1. Verify latest source database backup and current Hili live attendance health. Deploy the branch `feature/single-att2000-adms-schema-20261112`, not master.
2. In **V2 app container Terminal**, run this *targeted* schema migration for the license flag, then clear caches:

```bash
cd /var/www/html
php artisan migrate:status
php artisan migrate --path=database/migrations/2026_11_12_000002_add_license_requirement_to_adms_devices.php --force
php artisan optimize:clear
php artisan view:cache
php artisan route:list --path=admin
php artisan adms:biocloud-ui-check
```

`adms:biocloud-ui-check` remains the old CLI command identifier for continuity; now it validates the real ADMS Enterprise layout, canonical /admin/devices route, menu routes, original reader and license field. The Dockerfile PHP-lints new controller and command files during image build. **The code was edited on GitHub only; no current-user deployment or runtime check was performed by the assistant.**

3. Open `http://at1.superfix.ae/admin/devices` and verify the merged fleet table appears IN THE EXISTING Enterprise shell, with columns serial, device alias, last observed ADMS update (not old BioCloud "active" status), recent contact, Edit License checkbox with Save, and protected command menus. Test a checkbox on one device, refresh and ensure it persists.
4. Visit Employee Directory, Departments, Area, Attendance Transactions, Daily and Monthly Attendance. Confirm sidebar reaches all configured pages. If `dbo.Department` or `dbo.Area` SELECT is denied, show notice and have DBA grant only the needed SELECT on the actual existing table, with no source UPDATE/DELETE.
5. Verify `php artisan adms:audit-hili-backfill --serial=CEG9223661162 --from=2026-11-04 --to=2026-11-12` still reports 213 exact and zero unmatched; the user previously completed the 123-row historical import. Preserve `ZKTECO_ATTLOG_BACKFILL_ENABLED=false` after one-shot import and existing hourly device clock sync.
6. DO NOT enable `ZKTECO_DISPATCH_COMMANDS=true` on public HTTP serial-only devices until per-device token identity or trusted authenticated network connection is verified. Only the legacy time-only sync may be allowed in its existing restricted path. Do not expand Hili writer to whole fleet yet.

## Known restrictions

- Certain directory tables may have different names or missing reader permissions. These pages show explicit notices rather than guessing or altering SQL schema.
- Directory Add/Update/Delete are intentionally DISABLED for original source data; no original employee editor with safe restricted privileges has been approved.
- First & Last / Multi In-Out / Daily / Monthly are summary projections from original `dbo.Attendance`, not payroll verification or fully implemented shift-rule engines.
- Vendor Edit License **checkbox** is implemented; vendor key activation intentionally not implemented.
- If /admin/devices still shows the old table, ensure the redeployed branch and container image include the change to `DeviceController@index`, clear caches, reload page hard and check `route:list`. Merely navigating to the link does not deploy code.


## 2026-11-12: SQL Server migration failure — solved in code, DBA path if needed

User ran the target migration and SQL Server returned:
`SQLSTATE[42000]: Cannot find the object "devices" because it does not exist or you do not have permissions`.
The original migration used an UNQUALIFIED `Schema::table('devices', ...)`, while the actual operational table is specifically `Att2000_V4.adms.devices`. The new migration uses `Schema::connection('sqlsrv')->table('adms.devices', ...)` and checks the expected database/schema and exact original table object ID before any DDL.

The application SQL login is intentionally limited and may lack **ALTER**, even when it can SELECT/INSERT the operational schema. The new readiness check distinguishes missing column, exact table visibility and ALTER privilege, and never changes data.

Read-only diagnosis from V2 app Terminal:

```bash
cd /var/www/html
php artisan tinker --execute='
dump(DB::connection("sqlsrv")->selectOne("
SELECT DB_NAME() AS db_name, SCHEMA_NAME() AS default_schema,
 OBJECT_ID(N\x27adms.devices\x27, N\x27U\x27) AS table_id,
 COL_LENGTH(N\x27adms.devices\x27, N\x27requires_license\x27) AS column_bytes,
 HAS_PERMS_BY_NAME(N\x27adms.devices\x27,N\x27OBJECT\x27,N\x27ALTER\x27) AS can_alter
"));'
```

- If `table_id` is non-null and `can_alter = 1`: redeploy corrected branch, run targeted migration (see above). No broader schema migrations.
- If `table_id` is non-null and `can_alter = 0`: do NOT grant app login db_owner/db_ddladmin; a DBA must run `docs/sql/adms-devices-requires-license.sql` against the exact `Att2000_V4` database with a privileged login. It adds `adms.devices.requires_license BIT NOT NULL DEFAULT 0` if missing, never touches `dbo.Device` or any other original tables. Then rerun target migration under app user, which detects preexisting column and marks the migration `Ran` without DDL.
- If `table_id` is NULL: do not attempt a DDL fix until the actual operational table and SQL login rights are diagnosed.
- After: `php artisan migrate:status`, `php artisan optimize:clear`, `php artisan view:cache`, `php artisan adms:biocloud-ui-check` and verify the checkbox saves at `/admin/devices`.

No change to Hili historical data, live writer allowlist or hourly device time synchronization.
