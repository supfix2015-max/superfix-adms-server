# Continuous UAE server clock synchronization — all registered devices

## Scope

Target **every device serial from the original** `Att2000_V4.dbo.Device` **plus office UA300** `BZD5261200843` already registered in `adms.devices`.

No manually maintained list of 22 serials. `ZKTECO_LEGACY_TIME_ONLY_ALL_REGISTERED=true` selects original devices by read-only `dbo.Device` lookup (with short cache) and office UA300 by explicit serial. The device must also exist in non-deleted `adms.devices`. This is **not** a wildcard accepting unknown devices.

## Configure only the isolated Superfix ADMS V2 application in Coolify

Set in Environment Variables:

```dotenv
ADMS_PILOT_ENABLED=true
ADMS_ALLOW_HISTORICAL_DEVICES=true
ADMS_PILOT_SERIALS=BZD5261200843

ZKTECO_LEGACY_TIME_ONLY_ENABLED=true
ZKTECO_LEGACY_TIME_ONLY_ALL_REGISTERED=true
ZKTECO_LEGACY_TIME_ONLY_SERIALS=
ZKTECO_LEGACY_TIME_ONLY_INTERVAL_SECONDS=3600

ZKTECO_SYSTEM_TIMEZONE=Asia/Dubai
ZKTECO_DEVICE_TIMEZONE=Asia/Dubai
ZKTECO_OPTION_TIMEZONE=+04:00
ZKTECO_OPTION_SYNC_TIME_SECONDS=3600

ZKTECO_REQUIRE_TOKEN=false
ZKTECO_COMMAND_REQUIRE_TOKEN=true
ZKTECO_DISPATCH_COMMANDS=false
ZKTECO_AUTO_REGISTER_DEVICES=false
LEGACY_SQLSRV_ATTENDANCE_WRITE_ENABLED=false
```

Save and **Redeploy V2 only**. This is continuous hourly synchronization, not a one-time administrative command, and does NOT require a queue worker, cron job, new database, migration or MySQL. `ZKTECO_AUTO_TIME_SYNC_ENABLED` is a different mechanism for token-authenticated queued SET_TIMEZONE / UPDATE_TIME commands; the legacy-time-only hourly synchronization above works independently of it.

When the physical terminal polls `/iclock/getrequest`, if registered and eligible, the server returns **only**
`C:<id>:SET OPTIONS DateTime=<encoded Dubai time>`, at most once per cooldown interval per serial. Some firmware follows with `/iclock/rtdata?type=time` and receives `DateTime=<encoded UTC time>,ServerTZ=+0400`. All other commands remain blocked on tokenless HTTP.

A device that is disconnected, points to an old VPS, or never polls `/iclock/getrequest` cannot be adjusted by these server responses. Not all ZKTeco firmware responds identically. Verify actual clock display for at least one device per firmware family. NTP at device level is an option when the firmware supports it.

## Read-only verification from V2 terminal

```bash
cd /var/www/html
php artisan config:clear

php artisan tinker --execute='
$devices = \\App\\Models\\Device::query()->orderBy("name")->get();
$report = $devices->map(function ($d) {
    $last = $d->rawLogs()
        ->where("endpoint", "/iclock/getrequest")
        ->where("record_count", 1)
        ->latest("id")->limit(10)
        ->get(["created_at", "parsed_data"])
        ->first(fn ($r) => ($r->parsed_data["mode"] ?? null) === "legacy_time_only_sent");
    return [
        "name" => $d->name,
        "serial" => $d->serial_number,
        "last_clock_command_dubai" => $last?->created_at?->copy()->timezone("Asia/Dubai")->format("Y-m-d H:i:s"),
    ];
});
dump([
    "original_historical_device_rows" => DB::connection("legacy_sqlsrv")->table("dbo.Device")->count(),
    "all_registered_clock_sync" => config("zkteco.legacy_time_only_all_registered"),
    "clock_sync_enabled" => config("zkteco.legacy_time_only_enabled"),
    "timezone" => config("zkteco.system_timezone"),
    "cooldown_seconds" => config("zkteco.legacy_time_only_interval_seconds"),
    "app_devices" => $devices->count(),
    "devices_with_recent_clock_command" => $report->whereNotNull("last_clock_command_dubai")->count(),
    "devices" => $report->toArray(),
]);
'
```

This is a snapshot, not proof that *all* hardware clocks are accurate. A `legacy_time_only_sent` record proves the server returned a clock command to a requester claiming that serial; `legacy_time_only_reply` proves a callback was processed. Public HTTP+serials alone cannot authenticate sender identity.

## Security and data integrity

- Existing HTTP without device tokens permits serial spoofing; someone could consume an hourly time command or spoof incoming punches. Command-only clock corrections are limited to previously registered serials, and **never** expose other queued commands without per-device tokens.
- Protect the administrative website via HTTPS separately. Move device HTTP to a trusted channel / enable authentication when firmware supports it.
- Keep original `dbo.Attendance` and `dbo.Employee` read-only and avoid any correction of historical times. UTC data is displayed as Asia/Dubai by conversion, not by changing stored UTC values.
- If a firmware has an incorrect time zone, configure **UTC+04:00**, DST off once on the device, or its supported NTP settings. Don't assume 22 different terminals automatically process the same server command.
