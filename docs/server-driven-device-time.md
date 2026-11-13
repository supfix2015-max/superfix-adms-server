# ZKTeco UA300 / Hili clock correction by the existing ADMS server

Observed 2026-11-12: physical UA300 showed approximately 12:39 PM while UAE local time was approximately 16:39, exactly 4 hours late.

## Root cause in V2 code

Until this change, every GET /iclock/cdata request returned GET OPTION FROM regardless of whether the device requested `type=time`. Under ZKTeco PUSH protocol, a clock query is:

```http
GET /iclock/cdata?SN=CEG9223661162&type=time
```

and the proper response has the server's current ISO-8601 *local* date/time with explicit offset:

```text
Time=2026-11-12T16:39:00+04:00
```

The returned date/time is dynamic. We don't hardcode that sample date.

Other bug: `TimeZone=4` is not the normal PUSH SDK offset format. Server now returns `TimeZone=+04:00` (the legacy env value 4 is normalized to +04:00).

`SyncTime` is a *device-initiated clock synchronization hint* in seconds. It does not cause the server to connect to the device. Some firmware may ignore it. Server cannot guarantee remote clock adjustment if terminal doesn't support PUSH clock sync or doesn't re-request options.

## Deploy isolated V2 only

Save variables in Coolify -> Superfix ADMS V2 -> Environment Variables:

```dotenv
ZKTECO_SYSTEM_TIMEZONE=Asia/Dubai
ZKTECO_DEVICE_TIMEZONE=Asia/Dubai
ZKTECO_OPTION_TIMEZONE=+04:00
ZKTECO_OPTION_SYNC_TIME_SECONDS=3600
ZKTECO_AUTO_TIME_SYNC_ENABLED=false
ZKTECO_COMMAND_REQUIRE_TOKEN=true
```

Redeploy the existing branch `feature/single-att2000-adms-schema-20261112`. Existing ADMS pilot/serial allowlists must remain correctly configured. Do not turn off token requirements for remote commands just to set the clock over public HTTP.

## Tests

Terminal in V2:

```bash
curl -fsS "http://127.0.0.1:8080/iclock/cdata?SN=CEG9223661162&type=time"
curl -fsS "http://at1.superfix.ae/iclock/cdata?SN=CEG9223661162&type=time"
curl -fsS "http://at1.superfix.ae/iclock/cdata?SN=CEG9223661162&options=all" | grep -E '^(TimeZone|SyncTime)='
```

Expected live response to type=time: `Time=YYYY-MM-DDTHH:MM:SS+04:00`, both internal and domain, and `TimeZone=+04:00` / `SyncTime=3600` in the option handshake.

**Important:** These curls *simulate device requests*. They verify server response but do not update the physical device clock. Check live `adms.raw_logs` for the actual terminal's time query by querying `parsed_data` for mode `time_sync`, then visually confirm the actual device display.

Possible device-side alternatives when firmware never issues time requests:
- Enable date/time automatic sync / NTP with a reachable NTP server (UDP/123). Set UTC+04:00, DST off.
- Authenticated command via `/iclock/getrequest` to set date/time. Do NOT bypass per-device token validation over public HTTP; verify firmware capability and token configuration or secure transport first.

This is prospective: **do not add four hours to historical attendance in SQL Server**. Legacy dbo tables are read-only and must remain unchanged.
