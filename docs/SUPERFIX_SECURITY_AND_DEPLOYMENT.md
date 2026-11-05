# Superfix ADMS — Security-gated pilot

This is a fork of Mazbaul-kamal/ADMS_lib and must **not** be considered production secure without independent testing.

## What the initial patch changes
- Admin pages (including legacy attendance routes) require an authenticated **active administrator**.
- Anonymous sign-up and anonymous JSON device registration are disabled.
- Login attempts throttled. Admin dashboard API requires a Sanctum-authenticated active administrator.
- Insecure GET device-token rotation replaced with CSRF-protected POST.
- Static demo account passwords removed; seeding creates only the configured initial administrator.
- `public/pma.php` removed.
- Device ADMS routes denied unless pilot is explicitly enabled **and** its serial and source-IP allowlists match.
- Disables automatic device registration, automatic employee-pull-on-contact and anonymous ADMS by default.

## Important limitations
1. All `/admin` functionality is for testing until reviewed, including destructive commands and fingerprint template handling.
2. ADMS device serial and IP **are not sufficient authentication**. Proxy source-IP behavior needs verification. Do not enable the pilot against real devices yet.
3. Set separate MySQL database. Never point it at SQL Server `Att2000_V4` or `AttendanceCloud`.
4. Restrict the admin routes behind identity-aware access or VPN, enable MFA. HTTPS alone does not secure a public-facing admin panel.
5. Only after testing readiness and a UA300 pilot should you enable commands or connect other terminals.
6. Existing demo installations that already seeded `admin123` / `operator123` / `viewer123` must remove/rotate those accounts. The patch does not clean existing data.
7. Protect secrets in Coolify runtime variables; avoid placing passwords in GitHub or screenshots.
8. Do not run `migrate:fresh` on any database with real data.

## Planned test deployment
- Create new Coolify application `superfix-adms-test` in a separate project with `Dockerfile` build, app port 8080.
- Create a **separate MySQL 8** container and persistent storage (NOT the existing MSSQL service).
- Set `APP_KEY` (generated securely), `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://adms-test.superfix.ae`, `DB_CONNECTION=mysql`, `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE`, `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=database`.
- Set `ADMS_PILOT_ENABLED=false`, `ZKTECO_AUTO_REGISTER_DEVICES=false`, `ZKTECO_AUTO_PULL_EMPLOYEES_ON_CONTACT=false`, `INITIAL_ADMIN_EMAIL` and 20+ character initial admin password.
- With backups in place, run `php artisan migrate --force` and `php artisan db:seed --class=UserSeeder --force` once in application terminal. Remove `INITIAL_ADMIN_PASSWORD` after account creation.
- Do not add a public domain or direct a terminal until authentication / access-control testing is complete.
