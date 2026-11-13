# SQL Server ATTLOG retry fix and device clock synchronization — 12 Nov 2026

## Observed failures

Hili serial CEG9223661162 was reaching /iclock/cdata every ~5 seconds and failing with:

> This database engine does not support inserting while ignoring errors.

That exception originated in ZKTecoService::storeAttendanceLogs, which called DB::table('attendance_logs')->insertOrIgnore on MS SQL Server. Laravel SQL Server grammar does not support that statement.

## Fix

- Replace insertOrIgnore with normal transactional INSERT into the isolated adms.attendance_logs table.
- Deduplicate records by payload_hash both within each POST and against already saved attendance. The filtered unique SQL Server index on adms.attendance_logs.payload_hash remains the final safeguard for parallel writes.
- Batch SQL Server inserts/employee upserts to 50 rows per statement to avoid >2,100 bound parameters per SQL Server statement.
- ACK valid previously ingested events as well as newly inserted events with OK:<accepted count> to stop unnecessary retransmissions.
- On an actual database insert/constraint failure, transaction still throws and the terminal retries. This patch does not silently acknowledge failed writes.
- No change to dbo.Attendance, dbo.Employee, original biometric data or the existing schema; original attendance writing stays OFF.

### Post-deploy verification (read-only)

```bash
cd /var/www/html
php artisan config:clear
php artisan tinker --execute='
foreach (["CEG9223661162","BZD5261200843"] as $sn) {
  $d=\\App\\Models\\Device::where("serial_number",$sn)->first();
  dump([
    "serial"=>$sn,
    "latest_cdata"=>$d?->rawLogs()->where("endpoint","/iclock/cdata")->latest("id")->limit(4)->get(["processing_status","record_count","error_message","created_at"])->toArray(),
    "attendance_rows"=>$d?->attendanceLogs()->count(),
  ]);
}'
```

If a new, distinct SQL Server error appears in failed raw logs, diagnose that next; no schema reset or mass replay.

## Clock settings

Setting the server time zone affects commands and historical interpretations, NOT retroactive correction of wrong physical terminal punch timestamps.

Recommended Coolify V2 variables:

```dotenv
ZKTECO_SYSTEM_TIMEZONE=Asia/Dubai
ZKTECO_DEVICE_TIMEZONE=Asia/Dubai
ZKTECO_OPTION_TIMEZONE=4
# Optional protocol hint for models that honor server-directed time sync:
ZKTECO_OPTION_SYNC_TIME_SECONDS=3600
# Scheduled automatic command-based clock correction:
ZKTECO_AUTO_TIME_SYNC_ENABLED=false
ZKTECO_AUTO_TIME_SYNC_INTERVAL_HOURS=24
```

Protocol options include TimeZone and optional SyncTime, but not all firmware honors them. Test actual on-device displayed clock after receiving options. Do NOT claim physical time is fixed merely from a processed GET cdata.

For supported firmware, prefer device-side NTP: enable Automatic date/time or NTP Server on terminal, configure an allowed NTP host/router and Asia/Dubai UTC+04:00, DST OFF. The device needs working NTP (normally UDP/123), and if network forbids it select an accessible local/router NTP source.

### Authenticated automatic clock corrections

The Laravel receiver can periodically queue SET_TIMEZONE and UPDATE_TIME on authenticated /iclock/getrequest polls with `ZKTECO_AUTO_TIME_SYNC_ENABLED=true`. Max once per configured interval, one device at a time. The UPDATE_TIME formatter computes current time at actual dispatch, so dormant queues do not send yesterday's timestamp. This requires:
- ADMS pilot enabled
- Command dispatch enabled
- Device **must** provide correct API token on each poll
- ZKTECO_COMMAND_REQUIRE_TOKEN=true
- Reliable, suitably protected connection; public HTTP exposes bearer tokens to interception

Current historical/UA300 terminals on tokenless HTTP do not qualify for command-based time sync; the option handshake/NTP path may still work depending on firmware. DO NOT disable per-device command token authentication on public HTTP.

No mass-reset of clocks. Test clock sync on the office UA300 first, then expand to older terminals that support it. When a clock has been wrong, do not rewrite old attendance timestamps automatically without independent verification.
