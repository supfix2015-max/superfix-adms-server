# Direct ZKTeco attendance to original Att2000_V4 (single-database V2)

## Current V2 topology
- All existing original historical records: Att2000_V4.dbo.*.
- Laravel app operational objects: Att2000_V4.adms.* (same SQL Server database, NOT a new DB and NOT MySQL).
- Physical devices POST `/iclock/cdata?SN=<device>&table=ATTLOG`. Application parses payload in ZKTecoService::storeAttendanceLogs.
- Default: stores received punches in `adms.attendance_logs`; **does not** write to original `dbo.Attendance` until explicitly enabled.
- Optional protected `LegacyAttendanceWriter` writes **first** to dbo.Attendance using dedicated SQL login (SELECT/INSERT only), checks original BadgeNumber via SELECT-only reader, serial gate, status/verify mapping, local Asia/Dubai wall-clock and deduplicates by serial, badge, local time and direction under `sp_getapplock`. Then also writes local adms log. If SQL write fails, request fails and device can retry rather than receiving false OK.
- IMPORTANT: existing 8 test punches already in adms.attendance_logs are NOT automatically backfilled by turning this feature on. Review dates and known BadgeNumber before any explicit historical reconciliation; physical device clock was recently four hours wrong.

## Mandatory preparation (no writes)

1. Fresh backup/snapshot of original Att2000_V4, preserving old server intact.
2. Confirm current target device is configured for `at1.superfix.ae` HTTP 80 and real physical requests reach V2. There are 23 registered serials but only Hili and office test were seen live on 12 Nov 2026.
3. Confirm correct physical clock and `ZKTECO_DEVICE_TIMEZONE=Asia/Dubai` to prevent bad local `VerifyTime`.
4. Coolify V2 environment:
```dotenv
LEGACY_SQLSRV_DATABASE=Att2000_V4
LEGACY_SQLSRV_WRITE_USERNAME=adms_attendance_writer
LEGACY_SQLSRV_WRITE_PASSWORD=<set as secret via Coolify, never in chat>
LEGACY_SQLSRV_ATTENDANCE_TIMEZONE=Asia/Dubai
LEGACY_SQLSRV_ATTENDANCE_WRITE_SERIALS=CEG9223661162
LEGACY_SQLSRV_ATTENDANCE_WRITE_ALL_REGISTERED=false
LEGACY_SQLSRV_ATTENDANCE_WRITE_ENABLED=false
```
Use existing dedicated least-privilege SQL login, **not** sa/adms_v2_setup/adms_v2_app. Verify Writer TLS settings follow existing SQL Server certificate setup.

5. Deploy V2 and run:
```bash
cd /var/www/html
php artisan config:clear
php artisan adms:legacy-write-readiness --serial=CEG9223661162 --check-writer
php artisan tinker --execute='
$d=\App\Models\Device::where("serial_number","CEG9223661162")->firstOrFail();
$codes=$d->attendanceLogs()->whereNotNull("employee_code")->distinct()->pluck("employee_code")->map(fn($x)=>trim((string)$x))->unique()->values();
$known=DB::connection("legacy_sqlsrv")->table("dbo.Employee")->whereIn("BadgeNumber",$codes->all())->pluck("BadgeNumber")->map(fn($x)=>trim((string)$x))->unique();
dump(["device"=>$d->name,"app_punch_count"=>$d->attendanceLogs()->count(),"known_original_badges"=>$known->all(),"missing_original_badges"=>$codes->diff($known)->values()->all()]);
'
```
Do not activate writes until the readiness command succeeds, the existing employee badge map is confirmed, backup done and the clock is correct.

## Hili single-device direct-write pilot

Only after prerequisite checks, set `LEGACY_SQLSRV_ATTENDANCE_WRITE_ENABLED=true`, preserving `LEGACY_SQLSRV_ATTENDANCE_WRITE_SERIALS=CEG9223661162` and `LEGACY_SQLSRV_ATTENDANCE_WRITE_ALL_REGISTERED=false`. Save/Redeploy V2. Do NOT point other physical terminals to V2 yet. Make **one new real, authorized check-in** with a known original badge and read latest rows:

```bash
php artisan tinker --execute='
$sn="CEG9223661162";
dump([
  "latest_source"=>DB::connection("legacy_sqlsrv")->table("dbo.Attendance")->where("DeviceSerialNumber",$sn)->orderByDesc("Id")->limit(5)->get(["Id","BadgeNumber","VerifyTime","Status","VerifyType","InsertDate"])->toArray(),
  "latest_app"=>\App\Models\AttendanceLog::whereHas("device",fn($q)=>$q->where("serial_number",$sn))->latest("id")->limit(3)->get(["employee_code","log_time","status"])->toArray(),
]);
'
```

Check exact badge, status, original local time, no duplicate after retry and no `failed` recent /iclock/cdata. A fresh original row proves live write; successfully received adms punches alone do NOT. If invalid badge blocks a batch, do not issue an OK artificially; reconcile with dbo.Employee first.

## Future all-original fleet mode

Once Hili has proven live original writes and other devices have corrected clocks / connectivity:
```dotenv
LEGACY_SQLSRV_ATTENDANCE_WRITE_ALL_REGISTERED=true
LEGACY_SQLSRV_ATTENDANCE_WRITE_SERIALS=CEG9223661162
LEGACY_SQLSRV_ATTENDANCE_WRITE_ENABLED=true
```
The fleet switch dynamically checks each serial in original dbo.Device; unknown and office-only UA300 are not automatically allowed. Do not enable it for all 22 at once until tested/observed. The normal ADMS receiver still requires devices to be repointed individually to at1:80; the writer cannot reach a device behind NAT unprompted.

## Security and integrity

Public HTTP + raw serial admission is NOT device authentication, and a spoofed original serial plus known badge could forge punches once original writes are enabled. Use a restricted/trusted device ingress network, network-level authentication, or firmware-supported token and encrypted transport for real-world attendance integrity. Existing ZKTECO_COMMAND_REQUIRE_TOKEN=true protects ordinary commands but DOES NOT authenticate arbitrary attendance POSTs if ZKTECO_REQUIRE_TOKEN=false. Limit pilot to one serial, monitor and cut over with care.

**Distinct meaning of biometric data:** This workflow synchronizes *attendance punches* (timestamps and check-in/out). It does not copy or change `dbo.EmployeeTemplate` fingerprint template binaries. Template migration is a separate operation with format compatibility tests and explicit authorization.
