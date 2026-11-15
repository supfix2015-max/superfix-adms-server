# Fleet attendance cutover — 2026-11-03 onward

This is a staged rollout plan, NOT a production-deployment instruction without a backup and a verified pilot. All dates below are UAE wall-clock (Asia/Dubai). The original dbo tables must never be modified by this feature's historical importer.

## Purpose

- The 22 devices already listed in original dbo.Device may connect to HTTP at1.superfix.ae:80.
- The system requests device ATTLOG for 2026-11-03 00:00:00 through the current day. This retrospective query is opportunistic and depends on each firmware's PUSH DATA QUERY ATTLOG implementation and network connection.
- Staged history uses adms.attendance_logs, with event/payload-hash deduplication.
- Normal live (non-historical) punches can be written to dbo.Attendance only by devices explicitly allowed under LegacyAttendanceWriter. Begin with the approved Hili device, NOT every branch.
- ZenHR AT2 sends only verified punches whose *actual UAE device timestamp* is from **2026-11-15 00:00:00** onward. All events from November 3–14 are stored in SQL / review-only outbox, never posted by this sender even if old pending rows exist.

## Opt-in configuration in Coolify

Keep the previously working dual-host settings, including ADMS_PILOT_ENABLED=true, ADMS_ALLOW_HISTORICAL_DEVICES=true, ZKTECO_AUTO_REGISTER_DEVICES=false, ZKTECO_REQUIRE_TOKEN=false, ZKTECO_DISPATCH_COMMANDS=false, ZKTECO_COMMAND_REQUIRE_TOKEN=true.

Enable the fleet history query ONLY after checking one target terminal and verifying server reachability:

    ZKTECO_ATTLOG_BACKFILL_ENABLED=true
    ZKTECO_ATTLOG_BACKFILL_ALL_REGISTERED=true
    ZKTECO_ATTLOG_BACKFILL_START=2026-11-03 00:00:00
    ZKTECO_ATTLOG_BACKFILL_END=

Do not put an unknown serial or office test-only serial on the original whitelist. No new devices are auto-registered.

On the AT2-hosted instance, after checking outbox state and historical ZenHR delivery:

    ZENHR_OUTBOX_CUTOFF_LOCAL=2026-11-03 00:00:00
    ZENHR_ATTENDANCE_REVIEW_BEFORE=2026-11-15 00:00:00
    ZENHR_ATTENDANCE_SEND_FROM_LOCAL=2026-11-15 00:00:00

The old static 2026-11-12 cutoff is intentionally preserved by default when the new environment variable is absent. The collector uses forward-only cursors. Merely changing the cutoff will NOT go backward through already advanced original SQL rows.

## First history scan (dry-run)

After redeploy and with a confirmed original DB backup:

    php artisan adms:zenhr-backfill-precutover --limit=500

This scans 2026-11-03 00:00:00 inclusive through 2026-11-15 00:00:00 exclusive, independently from the current forward-only cursors.

To stage reviewed candidate records into the protected outbox (not POST to ZenHR):

    php artisan adms:zenhr-backfill-precutover --limit=500 --apply

All newly staged pre-cutover events are needs_review; neither command sends a single punch to ZenHR or inserts into original dbo.Attendance. The command prints resume positions if page caps are reached. Existing outbox rows are de-duplicated.

Do NOT blindly mass-release needs_review or re-send dates that the old BioWeb exporter may already have sent. Compare ZenHR records, local old sender export flags, duplicate keys, employee badge mapping, and historical clock offsets per device. Historical Hili punches before its clock repair are intentionally held.

## Continuous ingestion

- Devices poll /iclock/getrequest and POST /iclock/cdata to AT1.
- This feature replies to the next eligible poll with a scoped DATA QUERY ATTLOG for each registered historical terminal. A fixed end is optional; if blank, the end is the UAE day of that terminal's first successful query scheduling. The stable per-device/start cache prevents daily resends while present (14-day TTL). Manual reconciliation is still required if firmware ignores the query; a redeploy or expired cache can cause a repeat, which is deduplicated.
- Normal realtime upload remains enabled through configured ZKTECO_OPTION_REALTIME. Clock/firmware compatibility and whether the device has uploaded ALL records are verified by comparing timestamp ranges and record counts. The ZenHR sender runs at most one batch of 10 per minute; 202 status means asynchronous acceptance, not proof of ZenHR UI persistence.
- Monitor AT2 device-connectivity, /iclock/cdata rawLogs, adms.attendance_logs and dbo.Attendance; a green ONLINE heartbeat alone does not prove records reached SQL or ZenHR. Older punches can arrive today and remain quarantined; the POST date gate checks the original punch time, NOT reception time.
- Do not globally enable LEGACY_SQLSRV_ATTENDANCE_WRITE_ALL_REGISTERED until every device's clock, badge-to-employee mapping, and legacy SQL writer account have been validated, and the admin has a reversible rollback.

## Production safety checks

DO NOT deploy before manual review of the PR and a SQL backup. No per-device ZKTECO_ATTLOG_BACKFILL_SERIAL changes are required once fleet mode is enabled.


1. Back up SQL Server Att2000_V4 first.
2. Turn off any competing old BioWeb sender for the SAME records during ZenHR cutover, but preserve original SQL and device data.
3. Pilot Ain Al Fayidah (QQD4241200612), inspect command reply and cdata record_count, then manually compare device attendance count with retrieved records.
4. Confirm actual UAE local time and known employee badge codes.
5. Keep command dispatch OFF for unauthenticated HTTP devices; historical SN alone is not a cryptographic identity.
6. Confirm older events have not already been posted to ZenHR; HTTP 202 merely acknowledges asynchronous processing.
7. Roll out branches sequentially and compare daily counts.
