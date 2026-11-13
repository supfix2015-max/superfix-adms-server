# BioWeb CustomizedService.dll: verified ZenHR legacy protocol

## Evidence from user's actual Windows DLL (static IL analysis, no execution)

File: \`C:\zkcloud\BioWeb\CustomizedService.dll\`.
The service connecting to ZenHR was confirmed by Windows process/DNS
observations: \`C:\zkcloud\BioWeb\BioCoreService.exe\`.

Key IL method locations:
- \`MyModuleInitializer.Initialize\`: reads \`settings.json\` and
  \`appsettings.json\`, saves \`MySettings.AttendanceId\` as transaction ID,
  sets a timer (initial 10 seconds, interval 5 seconds).
- \`<DoWorkAsync>d__17.MoveNext\`: refreshes access token as needed,
  fetches employees and SQL attendance not yet exported; splits
  into **10 attendance entries per bulk POST**, sleeps 1000 ms
  between requests, and handles special failed exports.
- \`AttendanceCustomService.GetNonExportedAttendance\`: SQL
  \`SELECT * FROM Attendance WHERE Exported IS NULL ORDER BY Id\`.
- \`<HTTPPost>d__20.MoveNext\`: resolves each ZKTeco
  \`Attendance.BadgeNumber\` to ZenHR employee by exact equality
  with \`employment_number\`. Fetches employee list again on cache miss.
  If not found, logs error and skips that record.
  Builds payload \`attendance_details\`:
  - \`request_time\`: original \`VerifyTime\` formatted as
    \`yyyy-MM-dd HH:mm:ss \` + configured \`TimezoneOffset\`
    (**Dubai should be \`+0400\`**).
  - \`request_type\`: **\`Int32.Parse(att.StatusId)\`**, NOT
    order-based alternating, NOT a computed check-in/out.
  - \`employee_id\`: ZenHR API's internal employee \`id\`.
  - \`attendance_transaction_id\`: configured \`AttendanceId\`.
  - \`added_by\`: 2 (machine).
  - Optional GPS is parsed locally but is **not assigned in
    the inspected object initializer**, thus not transmitted
    in that path.
- Uses \`HttpClient.PostAsync(MySettings.AttendanceUrl, JSON)\`.
  Original \`AttendanceUrl\`:
  \`https://aiapi.zenhr.com/api/v3/branches/17787/attendance_details/bulk_insert\`.
- \`<HTTPPost>d__20.MoveNext\`: **HTTP status 202** is the
  acceptance check; response body is logged, not interpreted
  for individual punch success.
- \`<ExportHTTPPost>d__19.MoveNext\`: on accepted batch,
  \`AttendanceCustomService.SetExported(ids)\` calls:
  \`UPDATE Attendance SET Exported=GETDATE() WHERE Id IN (...)\`.
  On failed batch, uses \`markFailedTemperorly\` and remarks;
  query paths include year 2000 and sentinel dates.
  The new Laravel bridge must NOT modify the old
  \`dbo.Attendance.Exported\` tracking column.
- \`GetEmployees\` on the old service appears to fetch a
  single employees page. New Laravel code scans all pages.

## Important interpretation

HR says ZenHR computes the employee's daily arrival/departure from
the ordered punches. The old service **nevertheless includes the raw
ZKTeco \`StatusId\` as \`request_type\`**; it does not itself alternate
In/Out. Thus to preserve legacy behavior in Laravel, resolve the
existing **original SQL \`StatusId\`** (not the normalized
\`status\`/direction label in the ADMS outbox), pass it unchanged
when it is one of 0 or 1, and let ZenHR do shift calculation.

## Safer transition / manual pilot

A new branch provides \`ZenHrLegacyAttendanceSender\` and command
\`php artisan adms:zenhr-send-legacy\`.

**No automatic ZenHR POST is enabled.** The command
previews candidates by default. POST requires **both**
\`--apply\` AND \`ZENHR_ATTENDANCE_POST_ENABLED=true\`.
The existing \`attendance_sync_enabled=false\` remains unchanged.

1. On old Windows server, safely read nonsecret configuration values:

   \`\`\`powershell
   $s=(Get-Content 'C:\zkcloud\BioWeb\settings.json' -Raw | ConvertFrom-Json).MySettings; [pscustomobject]@{AttendanceId=$s.AttendanceId;TimezoneOffset=$s.TimezoneOffset;BranchId=$s.BranchId} | Format-List
   \`\`\`

   Do **not** show \`RefreshToken\`, \`appsettings.json\`, OAuth
   access tokens, connection strings or raw request logging.

2. In Coolify **after review**, select
   \`feature/zenhr-legacy-bioweb-sender-20261113\`; keep AT1 HTTP and AT2
   HTTPS, \`ADMS_INSTANCE_ROLE=dual\`, and the shared SQL DB. Set:
   \`\`\`dotenv
   ZENHR_ATTENDANCE_BRANCH_ID=17787
   ZENHR_ATTENDANCE_TRANSACTION_ID=<the nonsecret numeric old AttendanceId>
   ZENHR_ATTENDANCE_POST_ENABLED=false
   \`\`\`

3. Check PHP syntax and inspect candidates:
   \`\`\`bash
   php -l app/Services/ZenHrLegacyAttendanceSender.php
   php -l app/Console/Commands/SendZenHrLegacyAttendanceCommand.php
   php artisan adms:split-readiness
   php artisan adms:zenhr-send-legacy
   php artisan adms:zenhr-outbox --stats
   \`\`\`

4. When agreed, set \`ZENHR_ATTENDANCE_POST_ENABLED=true\`,
   redeploy, then send **exactly one** pilot event:
   \`\`\`bash
   php artisan adms:zenhr-send-legacy --limit=1 --apply
   \`\`\`
   Check HTTP 202 and the staff's actual ZenHR attendance history.
   ZenHR API replies may not guarantee individual row processing.
   Keep automated sending OFF until the pilot and old integration
   coexistence are reviewed.

5. The new sender only updates the isolated \`adms\` outbox:
   pending → inflight → sent (HTTP 202), or unknown on
   any unsuccessful/uncertain response. No automatic retry of
   unknown records; no modification of \`dbo.Attendance\`.
   Bad Hili clock events remain \`needs_review\`. Unmatched
   employees are skipped. The original SQL status must be
   exactly 0 or 1; unknown statuses are skipped.

## Current database diagnostics from user

22 local \`pending\`, 13 \`needs_review\`.
19/22 match one of 275 ZenHR employees by
\`employment_number\`, three do not match.
Existing ZenHR daily records are NOT an exact raw-punch
deduplication oracle. User explicitly accepts temporary
duplicates or missing records during the migration,
with later HR correction if needed.

## Values verified on the old Windows server (2026-11-13)

User ran a values-only PowerShell read from `BioWeb/settings.json`:
- `MySettings.AttendanceId = 18166`
- `MySettings.BranchId = 17787`
- `MySettings.TimezoneOffset = ""` (empty in legacy config)

Use `ZENHR_ATTENDANCE_TRANSACTION_ID=18166` in Coolify, and `ZENHR_ATTENDANCE_BRANCH_ID=17787`. **Do not inherit the empty offset** into new bulk requests: punch timestamps originate in UAE local time, and the documented ZenHR bulk endpoint interprets missing timezone as UTC. Explicit `+0400` is a deliberate normalization to check during the one-record pilot.

Keep `ZENHR_ATTENDANCE_POST_ENABLED=false` during dry run. This branch does not yet start an unattended sender.

## Migrated SQL StatusId=NULL fix (13 Nov 2026)

The user queried the live shared SQL Server:
- \`StatusId = NULL, Status = Check-In\`: 18 attendance rows after 12 Nov cutoff
- \`StatusId = NULL, Status = Check-Out\`: 15 rows
- No separate numeric StatusId in these 33 source records.

Old BioWeb read \`StatusId\` directly. On **this migrated SQL database** the sender must instead:
- Prefer known explicit \`StatusId=0\` or \`1\` when present.
- If absent/null, map original \`dbo.Attendance.Status\` \`Check-In → 0\`,
  \`Check-Out → 1\`.
- For an ADMS-only punch not yet mirrored in \`dbo.Attendance\`, use its
  normalized outbox direction when it is \`check-in\` or \`check-out\`.
- Keep unsupported/ambiguous breaks, overtime or custom statuses
  unsent for review. Do not alternate in/out or rewrite source SQL.

Changes are in \`app/Services/ZenHrLegacyRequestType.php\` and
\`app/Services/ZenHrLegacyAttendanceSender.php\`, with unit tests.
The \`adms:zenhr-send-legacy\` command remains dry-run-only unless
both \`--apply\` and \`ZENHR_ATTENDANCE_POST_ENABLED=true\` are set.

Redeploy the **same branch**, then run:
\`\`\`bash
php -l app/Services/ZenHrLegacyRequestType.php
php -l app/Services/ZenHrLegacyAttendanceSender.php
php artisan adms:zenhr-connectivity
php artisan adms:zenhr-send-legacy
\`\`\`
A new nonzero eligible count is expected, but must be read from
the live command; no successful vendor attendance POST yet.


## 2026-11-13: First live ZenHR POST accepted and automatic sender prepared

First manual command after the migrated SQL StatusId fallback was deployed:
\`\`\`text
php artisan adms:zenhr-send-legacy --limit=1 --apply
Outbox pending: 23; valid employee/status/timezone: 20;
unmatched employee: 3; submitted: 1; HTTP: 202;
new outbox state: sent.
\`\`\`

This confirms **HTTP acceptance**, not individual row visibility in ZenHR
HR admin UI. Verify one named punch in ZenHR first if possible.

New independent automatic sending control:
\`\`\`dotenv
ZENHR_OUTBOX_AUTO_COLLECT=true
ZENHR_ATTENDANCE_POST_ENABLED=true
ZENHR_ATTENDANCE_AUTO_SEND=false
\`\`\`
A deployment with \`AUTO_SEND=false\` continues staging and preserves
manual sending ability. After confirming the pilot, explicitly switch
\`AUTO_SEND=true\`, then redeploy the SAME application/branch. The
existing outbox worker will stage up to 500 records/minute and send
one ZenHR bulk request up to 10 verified records/minute. Do not
change AT1 HTTP, AT2 HTTPS, SQL credentials, or role \`dual\`.

Check:
\`\`\`bash
php -l app/Console/Commands/AutoSendZenHrLegacyAttendanceCommand.php
php artisan adms:zenhr-outbox --stats
php artisan adms:zenhr-send-legacy
\`\`\`
The sender stops automatically upon a failed or unknown request in the
active worker, and the SQL guard blocks future sending whenever any
\`unknown\`, \`inflight\`, or \`failed\` states remain. Missing employees
remain pending; Hili clock anomalies remain needs_review.

To stop *new automatic* sending, change
\`ZENHR_ATTENDANCE_AUTO_SEND=false\` in Coolify and redeploy.
Manual outbound posting remains guarded independently by
\`ZENHR_ATTENDANCE_POST_ENABLED\`.

Do not confuse \`HTTP 202\` with verified individual punch delivery;
vendor may handle bulk messages asynchronously.
