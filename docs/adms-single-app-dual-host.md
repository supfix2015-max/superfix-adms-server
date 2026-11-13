# One Coolify application, AT1 HTTP + AT2 HTTPS

This deployment has **one Laravel container**, **two Coolify domains** mapped
to internal port 8080, and **one Att2000_V4 SQL Server database**. Do not split
it into two apps. Do not set `ADMS_INSTANCE_ROLE=devices` or `console`:
those roles intentionally disable the other host.

## Single application Coolify Environment Variables

```dotenv
APP_URL=https://at2.superfix.ae
ADMS_INSTANCE_ROLE=dual
ZENHR_ENABLED=true
ZENHR_OUTBOX_AUTO_COLLECT=false
```

Preserve the **existing** SQL credentials, ZKTeco pilot settings, device
allowlist, command token policies, Hili original-attendance write allowlist,
APP_KEY, and OAuth client credentials. Never paste credentials into logs.

Keep exactly these two Coolify domain mappings:

- `http://at1.superfix.ae` -> internal port 8080; protocol redirect DISABLED.
- `https://at2.superfix.ae` -> internal port 8080; HTTP-to-HTTPS redirect.

## Host routing in dual mode

- AT1: accepts only `/iclock/*` and `/api/device/*`, returns plain-text status
  on `GET /`, responds 404 for administrative routes.
- AT2: allows the dashboard, login, API and ZenHR OAuth; blocks device-protocol
  requests at `/iclock/*` and `/api/device/*` with 404.
- An unknown Host receives 404. Local `127.0.0.1` health probes still work.
- The console may enqueue permitted commands into the same SQL store; AT1
  returns them in the device's authenticated `/iclock/getrequest` poll.
  The existing device-command security constraints remain intact.
- `APP_URL` is AT2's canonical HTTPS URL for generated links and ZenHR OAuth.

## Cutover checks (only after deploying new dual-mode code)

```bash
php artisan optimize:clear
php artisan adms:split-readiness
curl -sS -i --max-time 10 'http://at1.superfix.ae/iclock/getrequest?SN=DIAGNOSTIC-NOT-REGISTERED'
curl -sS -i --max-time 10 'https://at2.superfix.ae/iclock/getrequest?SN=DIAGNOSTIC-NOT-REGISTERED'
curl -sS -i --max-time 10 https://at2.superfix.ae/admin/dashboard
```

Expected: role `dual`, APP_URL `https://at2.superfix.ae`; AT1 fake
device rejected with `403 ADMS DEVICE DENIED` (no redirect), AT2
device endpoint `404`, AT2 dashboard login response/redirect.
Verify one real device poll and punch persists; do not assume fake-device
HTTP checks prove live ingestion.

## ZenHR November 12 backfill

The existing OAuth is configured on AT2; successful OAuth authorization must
still be confirmed inside the ZenHR panel. Provision the isolated tables
with `docs/sql/adms-zenhr-attendance-outbox.sql` using an SQL DBA.
Then run:

```bash
php artisan adms:zenhr-outbox --stats
php artisan adms:zenhr-outbox --limit=500
# after verifying preview, separately approve staging:
php artisan adms:zenhr-outbox --apply --limit=500
```

This gathers unique punches from **2026-11-12 00:00 Asia/Dubai** in
`dbo.Attendance` and `adms.attendance_logs` without modifying those
source tables. Known clock-corrupted Hili records need review.

When staging is verified, setting `ZENHR_OUTBOX_AUTO_COLLECT=true` and
redeploying starts the supervised collector on this **dual** app. It
continually **STAGES ONLY**; it **DOES NOT send** punches to ZenHR.

**External ZenHR attendance POST still requires:** confirmed correct
branch/company IDs and JSON body, checking whether the previous integration
already sent records, robust refresh-token handling, idempotency and controlled
single-punch testing. Do not enable an unverified sender.
