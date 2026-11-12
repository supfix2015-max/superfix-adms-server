# BioCloud-style workspace — deployment and safety checklist

Branch: `feature/single-att2000-adms-schema-20261112`, draft PR #3.
**DO NOT merge into master or deploy to production without a backup and UI smoke test.**
The previous Hili attendance backfill was completed and audited (213 staged exact, 0 missing). This UI rollout does not re-fetch Hili, clear data, or change the original dbo schema.

## Scope implemented

- Green top navigation **Home / Employee / Devices / Attendance / System**, charcoal BioCloud-style sidebar, responsive tables.
- Employee directory: Employee, Department, Area, Position, Holiday, Timetable, Shift, Department Schedule, Employee Schedule, Temporary Schedule, Employee Leave. Each table is queried from original SQL Server dbo by a hardcoded allowlist (safely introspected), with harmless unavailable/permissions notice for missing legacy tables. Main employee view supports badge/name/department search; **original employee records are read-only**; Add/Edit/Delete shown disabled until a scoped original writer is designed. No biometric payloads/credentials are selected.
- Devices fleet matches the 22 original registrations and any extra adms-only terminals by exact serial. "Recent contact" is from real adms.requests/last_seen and **NOT proof of token-authenticated identity on current legacy HTTP mode**.
- **Requires Edit License** is a persistent per-device checkbox. Only the new `adms.devices.requires_license` BIT field is changed. It does not activate a vendor license, store license keys or touch dbo.Device.
- Edit Device form (existing operational devices) supports changing adms alias, optional IP/port and licensing checkbox; no original serial change.
- Device menu: Edit Device, Read Information, Reboot, Clear Pending Command (cancels *unsent* adms.device_commands only), Command log. Data transfer supports Download Employees (`PULL_EMPLOYEES`), Download Transactions (`PULL_ATTENDANCE`) via the existing authenticated queue. Clear Data supports `CLEAR_LOGS` only after confirmation; **Clear All Data and unsupported transfers are disabled**. No remote command is sent while `ZKTECO_DISPATCH_COMMANDS=false`.
- Attendance Transactions: original dbo.Attendance, original dbo.Employee and dbo.Device exact-key joins; filters badge/name/department/device/date, 20/50/100 rows, total count and CSV export.
- Attendance subreports: First & Last, First in last out, Multi In Out, Daily Attendance, Monthly Attendance, Attendance Summary. Each groups real original dbo.Attendance event records and shows the first/last event, number of check-ins/check-outs, transaction count; **not payroll-ready working hours**. Max 93-day report range.
- System page shows connection/command state and links to logs, queue, connectivity and non-sensitive biometric template metadata.

## On Coolify / V2 after selecting the branch

1. Confirm the latest verified backup of original `Att2000_V4`. Keep the original `dbo` tables untouched and retain the Hili live receiver and 3600s automatic clock synchronization environment.
2. Redeploy the app from this branch, NOT master. The Dockerfile will PHP-lint new controller files.
3. Open the **V2 application terminal**, run:

```bash
cd /var/www/html
php artisan migrate:status
php artisan migrate --path=database/migrations/2026_11_12_000002_add_license_requirement_to_adms_devices.php --force
php artisan optimize:clear
php artisan view:cache
php artisan adms:biocloud-ui-check
```

**Why a targeted migration?** It adds only the required BIT checkbox column to `adms.devices` after checking the SQL Server login's database and default schema. `dbo.Device` and `dbo.Employee` are unaffected. Do not run a blind full `migrate` until inspecting all other pending migrations first.

4. Open `http://at1.superfix.ae/admin/biocloud`, then:
   - Employee `/admin/directory/employee`
   - Department `/admin/directory/department`
   - Area `/admin/directory/area`
   - Devices `/admin/fleet` — toggle **Requires Edit License** on a known terminal, click Save, refresh, verify persistent; toggle back if testing
   - Attendance `/admin/legacy?tab=attendance` — filter Hili serial and 4–12 Nov; confirm original records
   - First & Last `/admin/attendance-reports/first-last`, Daily `/admin/attendance-reports/daily`, Monthly `/admin/attendance-reports/monthly`
   - System `/admin/biocloud/system` — confirm command dispatch remains OFF for unauthenticated public HTTP devices
5. The restricted legacy SQL reader may not have SELECT on `dbo.Department` and `dbo.Area`; in that case the corresponding directory displays a warning. A SQL DBA can independently verify those real table names and grant **only SELECT** to `adms_legacy_reader` if authorized. Do NOT grant UPDATE/DELETE or template reading just to enable UI labels.
6. **Do not** enable whole-fleet SQL original writer or remote command dispatch at this stage. In the next step test per-device token identity/trusted ingress, do controlled one-device attendance uploads, verify exact serial/badge/time/status against originals, then onboard remaining devices gradually. Device IP changes don't affect SN matching.

## Safety notes

- `ZKTECO_ATTLOG_BACKFILL_ENABLED=false` after successful one-off Hili recovery. Preserve `ZKTECO_ATTLOG_BACKFILL_SERIAL=CEG9223661162` and the historic review cutoff so pre-clock-correction retransmissions are not blindly inserted into official dbo.Attendance.
- Preserve `ZKTECO_LEGACY_TIME_ONLY_ENABLED=true` and `ZKTECO_LEGACY_TIME_ONLY_ALL_REGISTERED=true`, interval 3600s, UAE timezone.
- A current SN over HTTP is spoofable. An operator-only UI can't fix device ingress authentication; do not activate remote data-deleting/reboot commands on public unverified terminals.
- The vendor-specific `Edit License` feature in old BioCloud may require proprietary device activation protocol. The checkbox records **whether a device requires licensing only**; we do not accept/store the screenshot license key, and no unsupported activation command is sent.
