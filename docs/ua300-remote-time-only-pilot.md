# Office/Hili UA300 remote clock correction without exposing device management

**Problem:** UA300 screen still shows 12:52 PM at 16:52 Asia/Dubai. The previous server GET /iclock/cdata?type=time and SyncTime=3600 were tested with curl but did not cause a physical clock change because the terminal never requested server time itself.

## Fix on GitHub (this branch)
- Proper PUSH command: `C:<id>:SET OPTIONS DateTime=<OldEncodeTime>`. OldEncodeTime is ZKTeco's Appendix 5 formula, **not** Unix timestamp or printable date string.
- Example fixture `2018-02-22 14:54:54` -> `583080894`.
- Proper optional realtime clock follow-up: `GET /iclock/rtdata?SN=<serial>&type=time` -> `DateTime=<OldEncodeTime>,ServerTZ=+0400`.
- Dedicated time-only tokenless pilot: specific listed serials get **only** the device-clock command on their next /iclock/getrequest poll, with file-cache cooldown; no biometric data, administrative remote actions or queued commands are exposed. All other queued operations STILL require per-device token. The time-only acknowledgments only record request status; they NEVER change ordinary command queue rows.
- Future authenticated remote time commands also use correct numeric encoding and the plural `SET OPTIONS` syntax.
- This experimental time-only exception intentionally permits a limited unauthenticated physical clock change for the selected serial. An attacker can spoof a serial on public HTTP and consume the pilot command, preventing genuine terminal delivery. Use a controlled window and confirm actual physical display. Turn off the exception after testing or move to TLS/authenticated device networking.

## Coolify Superfix ADMS V2 only — test ONE device first

Keep:
```dotenv
ADMS_PILOT_ENABLED=true
ZKTECO_REQUIRE_TOKEN=false
ZKTECO_COMMAND_REQUIRE_TOKEN=true
ZKTECO_DISPATCH_COMMANDS=false
ZKTECO_SYSTEM_TIMEZONE=Asia/Dubai
ZKTECO_DEVICE_TIMEZONE=Asia/Dubai
ZKTECO_OPTION_TIMEZONE=+04:00
ZKTECO_OPTION_SYNC_TIME_SECONDS=3600
LEGACY_SQLSRV_ATTENDANCE_WRITE_ENABLED=false
```

Add:
```dotenv
ZKTECO_LEGACY_TIME_ONLY_ENABLED=true
ZKTECO_LEGACY_TIME_ONLY_SERIALS=BZD5261200843
ZKTECO_LEGACY_TIME_ONLY_INTERVAL_SECONDS=300
```

If the device in the photo is actually Hili serial CEG9223661162 (rather than the separately registered office test UA300), change only the serial allowlist to `CEG9223661162`. Do NOT send this clock test to all historical 22 terminals.

Redeploy. The physical terminal must poll `/iclock/getrequest`. The time-only exception returns a clock correction on the first eligible unauthenticated poll of each interval. If the hardware only polls `/iclock/cdata`, no command can be pushed through this HTTP route; use NTP or local Date Time menu.

## Read-only verification

```bash
cd /var/www/html
php artisan config:clear
php artisan tinker --execute='
$serial="BZD5261200843";
$d=\\App\\Models\\Device::where("serial_number",$serial)->firstOrFail();
dump([
 "clock_only_enabled"=>config("zkteco.legacy_time_only_enabled"),
 "allowlisted"=>in_array($serial,config("zkteco.legacy_time_only_serials",[]),true),
 "clock_now"=>app(\\App\\Services\\SystemClockService::class)->formatForDevice(),
 "latest_getrequests"=>$d->rawLogs()->where("endpoint","/iclock/getrequest")->latest("id")->limit(6)->get(["processing_status","record_count","parsed_data","created_at"])->toArray(),
 "latest_time_replies"=>$d->rawLogs()->where("endpoint","/iclock/devicecmd")->latest("id")->limit(6)->get(["processing_status","parsed_data","created_at"])->toArray()
]);
'
```

`parsed_data.mode=legacy_time_only_sent` means **a server command was returned to the requesting HTTP client, not proof of execution**. A device callback and the physical display are needed. If clock doesn't adjust, go to UA300 M/OK -> System -> Date Time, select Asia/Dubai UTC+04, DST OFF, and (if present on that firmware) enable an accessible NTP server; otherwise set time manually as a stopgap.

No migration and no changes to original dbo.Attendance. Historical logs are NOT automatically rewritten.
