# ZenHR OAuth — isolated AT2 rollout

The user created **Superfix ADMS Enterprise** in ZenHR with this exact redirect:
`https://at2.superfix.ae/admin/integrations/zenhr/callback`.

This is separate from the legacy ZenHR application named "Attendance API".
**Do not edit/revoke the old integration until the final cutover, and never import its secret into the new system.**

Official ZenHR authorization documentation: https://api-docs.zenhr.com/
- Authorization Code endpoint: `https://app.zenhr.com/oauth/authorize`
- Token endpoint: `https://app.zenhr.com/oauth/token`
- Read-only token verification: `https://app.zenhr.com/api/v3/who_am_i`
- Production request limit: 15 requests / 4 seconds

## What this implementation currently does

1. `/admin/integrations/zenhr` displays a *separate* ZenHR section in original ADMS Enterprise UI.
2. `POST /admin/integrations/zenhr/connect`: Admin-only, AT2-only, CSRF-protected entry point; creates a random 256-bit OAuth state stored in the current server session for 10 minutes; redirects the administrator to ZenHR.
3. `GET /admin/integrations/zenhr/callback`: Exact URL registered with ZenHR. Validates same authenticated administrator and constant-time state; single-use session state; exchanges code with ZenHR over HTTPS (application UID/secret read **only** from AT2 Coolify secrets). Calls `who_am_i` before accepting access token; stores access+refresh token encrypted using Laravel `Crypt` in a single isolated `adms.zenhr_connections` SQL row.
4. `POST /admin/integrations/zenhr/test`: Admin-only `who_am_i` test. If the access token is expired/nearly expired, it now refreshes and atomically stores rotated encrypted OAuth tokens first. This may write **only** the isolated OAuth connection row; it never sends attendance.
5. `php artisan adms:zenhr-readiness`: Checks exact callback, flag, credential presence, routes, table existence, and permissions without displaying credentials or accessing ZenHR.
6. No posting attendance; `attendance_sync_enabled = false` in config. This protects original Hili and old ZenHR integration while branch mapping and duplicate reconciliation remain pending.

## AT2-only Coolify configuration

In the **AT2 APP** environment (not AT1 or the SQL Server container):

```dotenv
ZENHR_ENABLED=true
ZENHR_APP_NAME="Superfix ADMS Enterprise"
ZENHR_CLIENT_ID=<new app UID from ZenHR>
ZENHR_CLIENT_SECRET=<new app secret from ZenHR>
ZENHR_REDIRECT_URI=https://at2.superfix.ae/admin/integrations/zenhr/callback
APP_URL=https://at2.superfix.ae
```

Enter secrets ONLY via protected Coolify environment inputs. Never post them in a chat, screenshot, GitHub commit, or terminal output. `APP_KEY` must remain stable across redeploys or stored encrypted tokens will be undecryptable. TLS certificate on `at2.superfix.ae` must be valid; ensure host + cookies + proxy HTTPS scheme are configured.

AT1 must NOT have `ZENHR_ENABLED=true`. The controller additionally requires the request Host `at2.superfix.ae` and exact redirect URI, so the route on another host returns 404.

## One-time database provisioning

The app's restricted SQL login cannot create tables. An authorized DBA should execute `docs/sql/adms-zenhr-oauth-storage.sql` in **Att2000_V4** with a SQL administrative account. This only creates `adms.zenhr_connections` and does not touch any historical `dbo.*` tables.

If SQL app login lacks access to this single new table, grant the AT2 application database user exactly:
```sql
-- Do not execute with the placeholder: use the actual ADMS database principal
-- observed by SELECT USER_NAME() under the AT2 Laravel SQL connection.
GRANT SELECT, INSERT, UPDATE ON [adms].[zenhr_connections] TO [YOUR_ADMS_DATABASE_USER];
```
**Do not** grant DELETE/ALTER, db_owner, or schema-wide write permissions. Protect the encrypted table and ensure its access is restricted to trusted AT2 application credentials. If AT1/AT2 share the same SQL login, use dedicated restricted login for AT2 in a future segregation step; both must not accidentally be granted OAuth privileges.

## Deployment and smoke test

Deploy the latest `feature/single-att2000-adms-schema-20261112` code to **AT2 only**. On the app container:
```bash
cd /var/www/html
php artisan optimize:clear
php artisan view:cache
php artisan route:list --path=admin/integrations/zenhr
php artisan adms:zenhr-readiness
```
To check and automatically refresh an expired stored token without exposing secrets, run `php artisan adms:zenhr-connectivity`. It checks the ZenHR `who_am_i` API and prints only company/branch IDs and granted scopes. If the refresh token is missing, expired, or revoked, use the **Connect ZenHR** button to re-authorize. Never log token responses.

After all preflights succeed, open
`https://at2.superfix.ae/admin/integrations/zenhr`.
Click **Connect ZenHR**, complete ZenHR authorization in the SAME browser session while logged in as ADMS admin, and return to AT2. Then click **Test Connection (Read-Only)**.

Do not click ZenHR's old Postman Authorize or configure that older API's credentials. If ZenHR's App Page "Authorize" redirects to the callback directly without state, it will be safely rejected. **Always initiate from our AT2 "Connect ZenHR" button** so a valid state is present.

Authorization responses and credentials are never echoed. The connection test sends no punches.

## Remaining phases (NOT implemented/active)

- Token refresh/rotation is implemented on `getUsableAccessToken()` and used by the connectivity test. Newly added vendor posting adapters must call it for each authorized request. The refresh token itself can expire or be revoked, in which case re-authorization remains necessary.
- Read-only company branches and employee mapping by original badge vs ZenHR employment number and company+branch scoped employee ID.
- Outbound attendance outbox, no duplicates, idempotent writes, retry/rate limits, historical cutover audit against old integration.
- Never send historical Hili 213 records until already-synced ZenHR data have been audited.
