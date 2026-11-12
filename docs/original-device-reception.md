# Enabling original ZKTeco device reception and device commands

## Current status

- 22 original devices are registered in Att2000_V4.dbo.Device; last punch dates are historic.
- Office UA300 BZD5261200843 has been registered in Att2000_V4.adms.devices with local ID 1.
- SQL-only Laravel V2 is deployed with historical dashboard and adms operational schema.
- Physical devices have NOT all connected to this new application. Do not infer live connectivity from original SQL roster.
- All state-changing features below are OPT-IN settings, not secretly enabled by a GitHub commit.

## 1 — Create adms device records (no data imported)

In isolated Coolify V2 Terminal with pilot and dispatch OFF:

```bash
cd /var/www/html
php artisan config:clear
php artisan adms:provision-legacy-devices --dry-run
php artisan adms:provision-legacy-devices
php artisan tinker --execute='dump(["app_devices"=>App\\Models\\Device::count(),"office"=>App\\Models\\Device::where("sn","BZD5261200843")->exists()]);'
```

Creates only missing adms.devices rows for valid unique historical serials. Preserves office device and any existing API token, status, notes and last contact. Does NOT copy source employees, attendance or biometrics.

## 2 — Temporary HTTP receiver settings after stage 1 succeeds

Only on **V2 test application** (do not change old server, DNS or master):

```dotenv
ADMS_PILOT_ENABLED=true
ADMS_ALLOW_HISTORICAL_DEVICES=true
ADMS_PILOT_SERIALS=BZD5261200843
ADMS_PILOT_ALLOWED_IPS=
ZKTECO_ALLOWED_SERIALS=
ZKTECO_AUTO_REGISTER_DEVICES=false
ZKTECO_AUTO_PULL_EMPLOYEES_ON_CONTACT=false
ZKTECO_REQUIRE_TOKEN=false
ZKTECO_DISPATCH_COMMANDS=true
ZKTECO_COMMAND_REQUIRE_TOKEN=true
LEGACY_SQLSRV_ATTENDANCE_WRITE_ENABLED=false
ZKTECO_DEVICE_TIMEZONE=Asia/Dubai
```

The source dbo.Device becomes a read-only eligibility list plus office serial. An HTTP sender can claim a listed serial and generate a fake recent-request/punch record when generic token checks are disabled. THIS IS NOT DEVICE AUTHENTICATION. Use HTTPS or a device-private tunnel, firmware-supported token headers or limited IP/network access before moving to production. NEVER send app admin login/password over public HTTP if avoidable. Restrict the HTTP host to /iclock endpoints with reverse proxy eventually.

Commands are **visible and queueable** in Command Center, but a valid per-device API token remains mandatory for actual command dispatch and command replies. This protects against a spoofed HTTP serial receiving REBOOT/CLEAR LOG / biometric commands. DO NOT set ZKTECO_COMMAND_REQUIRE_TOKEN=false on public HTTP. A terminal unable to authenticate itself can report attendance via the temporary legacy exception but CANNOT safely receive remote commands.

Changing env requires Redeploy; verify deployment uses updated branch. Keep staging queue worker disabled until its jobs and biometric operations are audited.

## 3 — Check routes and live operations

```bash
php artisan route:list --path=device-connectivity
php artisan route:list --path=commands
php artisan route:list --path=iclock
php artisan tinker --execute='dump(["pilot"=>config("adms_pilot.enabled"),"legacy_roster"=>config("adms_pilot.allow_historical"),"office_allowed"=>in_array("BZD5261200843",config("adms_pilot.serials",[]),true),"commands"=>config("zkteco.dispatch_commands"),"commands_need_token"=>config("zkteco.command_require_token"),"historical_sql_write"=>config("legacy_attendance.attendance_write_enabled")]);'
```

- /admin/device-connectivity — historical devices plus app-only office devices
- /admin/connectivity — alternative route to same screen
- /admin/commands — command queue and acknowledgments
- /admin/commands/create — supported ZKTeco command catalog and manual queue composer
- /admin/dashboard — historical source counts and direct shortcut to command and connectivity screens

Accepted polls and punches appear in adms.devices, adms.raw_logs and adms.attendance_logs. The original dbo.Attendance remains read-only and its historic dashboard count WILL NOT automatically increase until a separately approved, limited SQL writer cutover.

Use a known test device first, then confirm exact device HTTP logs and last_seen timestamps before repointing historical terminals en masse. GET /iclock/getrequest sends only a prequeued operation and only to a token-authenticated terminal. NEVER bulk queue destructive operations.
