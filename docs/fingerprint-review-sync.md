# Fingerprint review — AT2 admin / AT1 device ingress

This feature receives existing uploaded fingerprints; it does not request all templates from the fleet. Review the exact employee badge, source device, finger slot and new version before selecting targets. No template bytes are shown in the admin page or duplicated in the approval ledger. The existing inbound application storage and logs are unchanged.

## Deploy and provision

1. Redeploy the operational branch in Coolify.
2. In the app container, run only the targeted migration (not all migrations):

       php artisan migrate --path=database/migrations/2026_11_19_000001_create_adms_fingerprint_sync_reviews.php --force

   If the application login is DML-only, a DBA must run `docs/sql/adms-fingerprint-sync-reviews.sql` on Att2000_V4, then rerun the targeted migration. No changes to dbo tables or ZenHR.
3. In Coolify, for AT1 or the dual application set:

       ZKTECO_FINGERPRINT_REVIEW_DISPATCH=true
       ZKTECO_DISPATCH_COMMANDS=false
       ZKTECO_COMMAND_REQUIRE_TOKEN=true

   Keep existing attendance settings, including HTTP. For a terminal that cannot provide its assigned device token, separately verify and bind its real, stable network source. Example placeholders (replace with actual values, not proxy IP):

       ZKTECO_FINGERPRINT_TRUSTED_SOURCES=SERIAL_A=192.0.2.10,SERIAL_B=192.0.2.11

   When a trusted reverse proxy is in front of the app, explicitly configure its actual address(es), after verifying it appends or sanitizes X-Forwarded-For. Never use the proxy address as the device source. Do not enable a wildcard or infer identities from public serial or previous heartbeat. Do not expose the app container directly if relying on proxy trust.

       ZKTECO_FINGERPRINT_TRUSTED_PROXIES=10.0.0.2

   Values above are illustrative; actual verified addresses are not known. HTTP is unencrypted and can expose biometric templates, so use private or VPN transport where available. An IP mapping is not encryption.
4. Redeploy after setting the environment. Open `https://at2.superfix.ae/admin/biometrics/review`. Check one authorized online target first. Keep general command dispatch off.
5. Select the real employee, device(s), and optional user record update. USERINFO sends PIN/name/normal privilege only; it can update an existing user. Verify a person's actual fingerprint works on the test target. Device ACK is not a physical authentication test.
6. Only then select further targets. Offline authorized targets wait. Conflicting known slots, stale source versions, incompatible known algorithms and unverified delivery are held. Unknown/failed delivery needs operator investigation; no automatic retry/replacement.

## Legacy serial-only compatibility (opt-in, high risk)

For firmware that cannot transmit its assigned token, an operator can explicitly enable the exception below. Prefer token-authenticated, encrypted/private transport whenever practical. A serial is not a secret: an impersonator may retrieve pending biometric templates or spoof acknowledgements. HTTP does not protect them in transit.

       ZKTECO_FINGERPRINT_REVIEW_SERIAL_ONLY=true
       ZKTECO_FINGERPRINT_REVIEW_SERIAL_ONLY_SERIALS=REPLACE_WITH_ONE_ORIGINAL_SERIAL

Use exact, comma-separated original roster serials, never a wildcard. Start with one terminal; existing approved pending jobs for that serial become eligible. The exception only covers the AT1 host and the ADMS getrequest/devicecmd paths. A configured IP binding cannot fall back to serial-only; requests supplying an invalid credential also cannot fall back. No arbitrary commands, no automatic employee registration, and no new-device authorization are granted. Keep ZKTECO_COMMAND_REQUIRE_TOKEN=true and ZKTECO_DISPATCH_COMMANDS=false. Raw request processing metadata identifies serial_only_unverified. Disable the flag to revoke the exception.

## Operational notes

- The old automatic replay listener and legacy UDP distribution jobs are held, including face templates from that mixed path. Generic fingerprint queue dispatch is also held; other ordinary commands retain their existing settings. This does not enable command dispatch globally or change the inbound ATTLOG/ZenHR flow.
- Source uploads on legacy HTTP may be unauthenticated. An approval is an HR operator's verification, not proof of the device's identity.
- Test after deployment: review route exists, table exists, approval queue states progress, device ACK is correlated to the selected target. Test fixtures and code review do not establish firmware interoperability.
