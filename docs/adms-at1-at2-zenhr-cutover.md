# AT1 HTTP device gateway + AT2 HTTPS console/ZenHR migration

Architecture: Both app instances use one SQL Server Att2000_V4 database. AT1 owns all /iclock/* requests and responses and approved device writes. AT2 owns admin dashboard, OAuth and vendor integration. The older ZenHR Attendance API credentials are never reused.

## AT1 configuration in Coolify

- APP_URL=http://at1.superfix.ae
- ADMS_INSTANCE_ROLE=devices
- ZENHR_ENABLED=false
- ZENHR_OUTBOX_AUTO_COLLECT=false
- Keep existing SQL reader/writer variables, ZKTeco pilot allowlist and time-only permitted commands.
- Keep Hili original SQL write allowlist serial CEG9223661162 with LEGACY_SQLSRV_ATTENDANCE_WRITE_ENABLED=true and LEGACY_SQLSRV_ATTENDANCE_WRITE_ALL_REGISTERED=false.
- Cloud Server host: at1.superfix.ae (no scheme), port 80, plain HTTP. Configure Coolify to permit port 80 without 301/308 redirects for device requests.

## AT2 configuration in Coolify

- APP_URL=https://at2.superfix.ae
- ADMS_INSTANCE_ROLE=console
- ZENHR_ENABLED=true and existing OAuth UID/Secret (keep only at AT2)
- ZENHR_REDIRECT_URI=https://at2.superfix.ae/admin/integrations/zenhr/callback
- LEGACY_SQLSRV_ATTENDANCE_WRITE_ENABLED=false
- ZKTECO_DISPATCH_COMMANDS=false
- ZENHR_OUTBOX_AUTO_COLLECT=false initially; set true after provisioning and preview.

## Critical cutover order

1. Deploy role=devices to AT1 first. Verify curl -i http://at1.superfix.ae/iclock/getrequest?SN=DIAGNOSTIC-NOT-REGISTERED returns ADMS DEVICE DENIED / 403 and NOT a redirect to HTTPS.
2. Change physical Hili ZKTeco Cloud Server hostname to at1.superfix.ae port 80 HTTP. Wait for one getrequest poll and new punch; confirm adms.devices.last_seen updated from AT1 and a dbo.Attendance row after punch.
3. Only then deploy role=console to AT2. The same /iclock URLs become 404 on AT2, while /admin dashboard still works and reads shared SQL. Do not switch AT2 earlier or punches may be lost.
4. Run DBA script docs/sql/adms-zenhr-attendance-outbox.sql once on SQL Server Att2000_V4, never with the app's limited login. It creates adms.zenhr_attendance_outbox and adms.zenhr_attendance_cursors only.
5. AT2 read-only preview: php artisan adms:zenhr-outbox --limit=500
6. AT2 stage to outbox: php artisan adms:zenhr-outbox --apply --limit=500
7. AT2 show counts: php artisan adms:zenhr-outbox --stats
8. On AT2 set ZENHR_OUTBOX_AUTO_COLLECT=true then Redeploy. A supervised once-per-minute collector stages 500 dbo and 500 adms records after the Dubai Nov 12 cutoff; it is not active on AT1.
9. Inspect https://at2.superfix.ae/admin/integrations/zenhr to distinguish queued (not delivered) and needs_review.

## User's selected cutoff and safety

Cutoff: 2026-11-12 00:00:00 Asia/Dubai. Read from BOTH original dbo.Attendance and adms.attendance_logs, unique hash of device serial, badge, local punch time, status. No local ZenHR employee matching or edits. Pre-clock-fix Hili rows before Nov 12 17:10 are marked needs_review. Original SQL dbo is never modified by the ZenHR outbox.

## Remaining blocker to actual ZenHR posting

The official docs identify POST /api/v3/branches/{branch_id}/attendance_details and the bulk_insert route, but the exact vendor JSON body and correct company/branch IDs have not been confirmed. The old integration might have already posted some Nov 12+ records. No ZenHR attendance POST is implemented or enabled: blindly posting records is unsafe. Obtain a verified example for this exact OAuth app and reconcile existing ZenHR records; then add token refresh, branch routing, careful idempotent delivery and a single-event acceptance test. OAuth Connected only confirms authorization, not attendance delivery.

Keep commands requiring authenticated device tokens OFF over the public HTTP receiver. The existing time-only path is unchanged.