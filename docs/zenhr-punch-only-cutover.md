# ZenHR biometric punch bridge — employee/time based

## Confirmed by HR (13 Nov 2026)

All ZKTeco devices send raw punch events to the same SQL Server application.
An employee can clock at Hili and Mezyad on the same shift. ZenHR is
responsible for interpreting the arrival/departure sequence and shift hours.
The legacy ZKTeco event *direction* is informational only and must **not**
be transformed into a vendor In/Out decision by the ADMS integration.

**Outbound event identity** = exact employee number (preserving leading
zeros) + precise local punch timestamp (Asia/Dubai, including seconds).

Neither physical device serial, geographic branch, nor raw ZKTeco
Check-In/Check-Out status is part of outbound deduplication.

## Current production components

- One Coolify app, role `dual`: AT1 HTTP `/iclock/*` ingress, AT2
  HTTPS dashboard and ZenHR OAuth. Both names use the same container.
- Single SQL database `Att2000_V4` with `dbo.Attendance` and
  `adms.attendance_logs` sources and isolated
  `adms.zenhr_attendance_outbox` + cursors.
- Background `ZENHR_OUTBOX_AUTO_COLLECT=true` collects punches once/minute.
- Verified ZenHR company ID 11945, only accessible branch is
  **Superfix Group ID 17787**. This is a *ZenHR tenant branch* and
  is not the physical location of any ZKTeco device.
- Existing rows as of last reported check: 22 `pending`, 13
  `needs_review`. All 13 are pre-repair Hili clock readings before
  Nov 12 17:10 local. **Do not clear them automatically.**
- OAuth refresh and who_am_i GET are working, but no attendance POST
  is implemented.

## After this branch is deployed

Set only this nonsecret Coolify environment variable, keeping all
other production settings intact:

```dotenv
ZENHR_ATTENDANCE_BRANCH_ID=17787
```

Then run the read-only preflight:

```bash
cd /var/www/html
php artisan adms:zenhr-preflight
php artisan adms:zenhr-preflight --remote
php artisan adms:zenhr-outbox --stats
```

- The first preflight groups unsent outbox rows by employee + exact
  local timestamp, ignoring device and source direction.
- The remote option GETs existing ZenHR attendance records for the
  relevant date range, plus a day on each side for overnight shifts.
  It outputs aggregate overlap counts **only**, never names or tokens.
- An existing ZenHR attendance **day** is not proof that a punch was
  already imported; a missing matching day is not proof that a punch
  is safe to re-upload. Summary first-in / last-out fields do not
  expose all underlying punches.
- The new collector uses an employee/timestamp hash for *new* events
  and checks old v1 rows so cross-device and status variants don't
  create duplicate outbound entries. Existing staged rows are not
  deleted or rewritten.
- Unknown ZKTeco direction alone no longer holds a new punch.
  Missing identity, missing device, and known bad Hili clock still do.

## Still blocking production ZenHR attendance POST

1. Get the official or working company-specific JSON request example
   and confirm whether the relevant endpoint uses
   `https://aiapi.zenhr.com/api/v3` vs `app.zenhr.com/api/v3`.
   Public endpoint catalogs identify
   `POST /branches/{branch_id}/attendance_details` and
   `POST /branches/{branch_id}/attendance_details/bulk_insert`
   but **do not publish a trustworthy body schema**.
2. Verify what identifier the POST expects: physical badge/card ID,
   `employment_number`, or the internal ZenHR employee ID.
   HR described employee-number matching, but the API contract must
   confirm that it resolves employee IDs itself.
3. Confirm the endpoint accepts **raw unordered punch timestamps**
   and assigns In/Out itself. The ZenHR administrative UI for manual
   records normally asks for an explicit In/Out, which is a different
   workflow. Do not silently alternate labels in the bridge.
4. Reconcile old integration's pre-existing punches. The GET
   `attendance_records` endpoint returns daily summaries, not
   necessarily individual punch events.
5. Only then add the delivery worker with a unique employee/time
   idempotency key and a fail-closed policy. On unknown POST success,
   keep the record in `unknown`, not `failed` for blind retry.
   Pilot exactly one verified new attendance event; stop on any
   unexpected response or duplicate.

No external ZenHR attendance POST is enabled in this branch.
