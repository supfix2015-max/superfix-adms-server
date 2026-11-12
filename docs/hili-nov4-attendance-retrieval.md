# Hili historical ATTLOG retrieval (Nov 4 onward) & original BioCloud-style UI

## One original SQL Server database

- Old historical data: Att2000_V4.dbo.Attendance / Employee / Device.
- New staging operational data: Att2000_V4.adms.attendance_logs.
- Device matches by exact serial CEG9223661162 across the physical SN, dbo.Device.DeviceSerialNumber, adms.devices.serial_number and dbo.Attendance.DeviceSerialNumber.
- Employee matches by exact BadgeNumber in dbo.Employee; unmatched code is never inserted into original dbo.Attendance.
- Office UA300 BZD5261200843 is only registered in the operational adms schema; not automatically included in dbo-original writer.

## User goal: retrieve every available Hili event since 2026-11-04 00:00:00

Important: the device clock was four hours slow until around Nov 12 17:10 UAE time. A backfill may include events with unreliable original device timestamps; do not copy them into the official original table until times are reviewed. The new receiver preserves those records in adms.attendance_logs, but they are deliberately excluded from direct original inserts when their local event timestamp is earlier than the cutoff below. After user review, reconcile from staged data to dbo.Attendance with duplicate checks; NEVER blindly apply +4 hours to all events or overwrite existing official rows.

The server requests \`DATA QUERY ATTLOG StartTime=YYYY-MM-DD HH:MM:SS<TAB>EndTime=YYYY-MM-DD HH:MM:SS\` from Hili via the next genuine \`/iclock/getrequest\` poll. The device POSTs its responses to \`/iclock/cdata?table=ATTLOG&Stamp=0\` on supported PUSH firmware. Only one named historical serial is enabled; the ordinary admin queue remains token-protected. The device's data may not include every requested event if older events were deleted/aged out, and actual completeness is measured by reconciling counts and dates, not by a callback alone.

## Coolify: V2 ONLY, deployment required

Do not disable existing continuous all-device hourly clock syncing.

\`\`\`dotenv
ZKTECO_ATTLOG_BACKFILL_ENABLED=true
ZKTECO_ATTLOG_BACKFILL_SERIAL=CEG9223661162
ZKTECO_ATTLOG_BACKFILL_START=2026-11-04 00:00:00
ZKTECO_ATTLOG_BACKFILL_END=2026-11-12 23:59:59
ZKTECO_ATTLOG_BACKFILL_REVIEW_BEFORE=2026-11-12 17:10:00

LEGACY_SQLSRV_ATTENDANCE_WRITE_ENABLED=true
LEGACY_SQLSRV_ATTENDANCE_WRITE_SERIALS=CEG9223661162
LEGACY_SQLSRV_ATTENDANCE_WRITE_ALL_REGISTERED=false
LEGACY_SQLSRV_ATTENDANCE_TIMEZONE=Asia/Dubai

ZKTECO_COMMAND_REQUIRE_TOKEN=true
ZKTECO_DISPATCH_COMMANDS=false
\`\`\`

Server time is 12 Nov 2026 for this one-time 4–12 Nov query; if deployed later, choose end date/time as desired (strict device-local clock). The command uses cache for at most one dispatch per config window per 14 days. Do not repeatedly redeploy or clear file cache to force a repeat, because older devices can send large batches. Do not enable general data deletion or clearing commands.

The read-only status check below SHOULD show one \`legacy_attlog_backfill_sent\` / \`legacy_attlog_backfill_reply\`; a callback isn't proof records arrived: check actual POST ATTLOG, staging row counts and original count separately.

## Read-only status check

\`\`\`bash
cd /var/www/html
php artisan config:clear
php artisan tinker --execute='
$d=\App\Models\Device::where("serial_number","CEG9223661162")->firstOrFail();
$logs=$d->rawLogs()->latest("id")->limit(60)->get(["endpoint","parsed_data","processing_status","record_count","created_at"]);
dump([
 "retrieval_enabled"=>config("zkteco.attlog_backfill_enabled"),
 "retrieval_serial"=>config("zkteco.attlog_backfill_serial"),
 "retrieval_start"=>config("zkteco.attlog_backfill_start"),
 "quarantine_before"=>config("zkteco.attlog_backfill_review_before"),
 "staged_since_nov4"=>$d->attendanceLogs()->where("log_time",">=","2026-11-03 20:00:00")->count(),
 "official_since_nov4"=>DB::connection("legacy_sqlsrv")->table("dbo.Attendance")->where("DeviceSerialNumber","CEG9223661162")->where("VerifyTime",">=","2026-11-04 00:00:00")->count(),
 "recent_device_calls"=>$logs->map(fn($r)=>[
   "endpoint"=>$r->endpoint,
   "mode"=>$r->parsed_data["mode"]??null,
   "count"=>$r->record_count,
   "status"=>$r->processing_status,
   "dubai_time"=>$r->created_at?->timezone("Asia/Dubai")->format("Y-m-d H:i:s")
  ])->toArray()
]);
'
\`\`\`

If no \`legacy_attlog_backfill_sent\` appears: the device has not polled the command or a previous backfill lock exists. Check that Hili is genuinely on at1:80, flag is ON, exact serial, and the clock command is not blocking each poll. If sent but zero new rows, review the device callback Return code (not all firmware supports this query or retains data back to 4 Nov).

After a query and inspection, set \`ZKTECO_ATTLOG_BACKFILL_ENABLED=false\`. Keep \`ZKTECO_ATTLOG_BACKFILL_SERIAL=CEG9223661162\` and \`ZKTECO_ATTLOG_BACKFILL_REVIEW_BEFORE=2026-11-12 17:10:00\` in place to keep original history protected from stale device events. **Leave automatic time sync enabled.**

## Attendance page in V2

\`/admin/legacy?tab=attendance\` now renders the familiar BioCloud transaction grid:

- Employee ID, Name, Department Name, Device Name, Punch Date, Status, Verify Type, Device Serial, Remark.
- Match employee names and departments from dbo.Employee by BadgeNumber. Department labels from dbo.Department or dbo.Departments, schema introspected, with Department ID fallback.
- Match device names by original dbo.Device.DeviceSerialNumber, NOT mutable device IP or guessed branch name.
- Green filter bar: employee ID, employee name, department, device, date range. Default start 2026-11-04. Descending by VerifyTime and Id.
- Export CSV of filtered results is authenticated/read-only and limited to 31 days and first 50,000 rows.
- Pagination 50. No change to original dbo schema and no sample data or copied MySQL.

The UI shows **original dbo.Attendance only**, not quarantined staging punches. A future reconciliation workflow must validate any staged old punches before they appear in the official UI.

## Security

The tokenless legacy Hili historic-query exception enables only \`DATA QUERY ATTLOG\`, not fingerprint exports, reboot, clear log, user-change or general queued commands. Public HTTP without device authentication still permits serial spoofing and attendance injection. Harden device ingress before scaling 22 original terminals, ideally via VPN/trusted network or firmware-supported authenticated TLS.


## After retrieval: read-only exact and 4-hour offset reconciliation

New command in V2, requires redeploy:

```bash
cd /var/www/html
php artisan adms:audit-hili-backfill --serial=CEG9223661162 --from=2026-11-04 --to=2026-11-12
```

It compares each staged event (Asia/Dubai local wall clock) to official dbo.Attendance using exact badge, device serial, direction and time, with optional candidate matches at +4h and -4h. It also validates original dbo.Employee badges and reports staged duplicates or unrecognized statuses. Reports per day + examples. It uses SELECT only; will never import, adjust or rewrite the original records.

At 2026-11-12 ~21:36, Hili staged count rose 15 -> 213; staged day counts Nov 4–12 = [25,25,14,25,26,26,26,26,20], original official count within Nov 4–12 = 90. Difference 213-90 DOES NOT equal missing event count. Wait for exact-match audit before any historical import, especially given known four-hour device-clock problem before Nov 12.
