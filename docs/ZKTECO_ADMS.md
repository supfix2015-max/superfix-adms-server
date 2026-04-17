# ZKTeco ADMS Server

This project exposes ZKTeco ADMS-compatible HTTP endpoints without the `/api` prefix:

- `GET|POST /iclock/cdata`
- `GET|POST /iclock/getrequest`
- `GET|POST /iclock/devicecmd`
- `GET|POST /iclock/test`

## Protocol Notes

- `GET /iclock/cdata?SN=<serial>&options=all` is treated as the device handshake / options pull.
- `POST /iclock/cdata?SN=<serial>&table=ATTLOG&Stamp=<n>` accepts tab-delimited attendance uploads.
- `POST /iclock/cdata?SN=<serial>&table=USERINFO` accepts user/profile sync payloads.
- `GET /iclock/getrequest?SN=<serial>` returns the oldest pending command in `C:<id>:<command>` format.
- `POST /iclock/devicecmd?SN=<serial>` accepts command execution results such as `ID=12&Return=0&CMD=REBOOT`.
- Pulling data from the device is command-driven: queue `PULL_EMPLOYEES` or `PULL_ATTENDANCE`, wait for the terminal to poll `/iclock/getrequest`, and then store the resulting `USERINFO` or `ATTLOG` upload when the terminal posts back to `/iclock/cdata`.

## Data Model

The repo already uses Laravel's `users` table for the admin portal, so biometric person records are normalized into `employees` instead of replacing auth users.

- `devices`: registered terminals, heartbeat timestamps, protocol metadata, optional token
- `employees`: biometric identities from the device (`employee_id`, `uid`, `card_no`, `name`)
- `attendance_logs`: normalized attendance events plus raw payload, verify type, punch state, dedupe hash
- `device_commands`: pending/sent/executed device command queue
- `raw_logs`: every inbound ADMS request for debugging
- `error_logs`: protocol and processing failures

## Example Requests

### Handshake

Request:

```text
GET /iclock/cdata?SN=ACME001&options=all
```

Response:

```text
GET OPTION FROM: ACME001
Stamp=9999
OpStamp=9999
ErrorDelay=60
Delay=30
ResLogDay=18250
ResLogDelCount=10000
ResLogCount=50000
TransTimes=00:00;14:05
TransInterval=1
TransFlag=1111000000
TimeZone=0
Realtime=1
Encrypt=0
```

### Attendance Upload

Request:

```text
POST /iclock/cdata?SN=ACME001&table=ATTLOG&Stamp=1

1001    2026-04-17 08:30:00    0    1    0        1    ATTLOG
1002    2026-04-17 08:31:00    1    15   0        2    ATTLOG
```

Response:

```text
OK:2
```

### Command Polling

Request:

```text
GET /iclock/getrequest?SN=ACME001
```

Response:

```text
C:15:REBOOT
```

If you queue a pull command, the device may receive a payload such as:

```text
C:16:DATA QUERY USERINFO
```

or:

```text
C:17:DATA QUERY ATTLOG
```

### Command Callback

Request:

```text
POST /iclock/devicecmd?SN=ACME001

ID=15&Return=0&CMD=REBOOT
```

Response:

```text
OK
```

## Local Testing

1. Configure `.env` for MySQL.
2. Run `php artisan migrate`.
3. Start the app with `php artisan serve`.
4. Point the device ADMS / cloud server URL to `http://<host>:8000/iclock/cdata`.
5. Point the device command callback URL to `http://<host>:8000/iclock/devicecmd` if your model exposes it separately.
6. Create commands from the admin panel or insert rows into `device_commands`.
7. To pull users or attendance from the terminal, use the `Pull Employees` / `Pull Attendance` actions on the device details page or queue `PULL_EMPLOYEES` / `PULL_ATTENDANCE` from the command screen.

## Simulator / Curl Testing

Handshake:

```bash
curl "http://127.0.0.1:8000/iclock/cdata?SN=ACME001&options=all"
```

Attendance:

```bash
curl -X POST "http://127.0.0.1:8000/iclock/cdata?SN=ACME001&table=ATTLOG&Stamp=1" \
  -H "Content-Type: text/plain" \
  --data-binary $'1001\t2026-04-17 08:30:00\t0\t1\t0\t\t\t1\tATTLOG\n1002\t2026-04-17 08:31:00\t1\t15\t0\t\t\t2\tATTLOG'
```

Command result:

```bash
curl -X POST "http://127.0.0.1:8000/iclock/devicecmd?SN=ACME001" \
  -H "Content-Type: text/plain" \
  --data-binary "ID=15&Return=0&CMD=REBOOT"
```

## Real Device Notes

- If you enable token validation, set `ZKTECO_REQUIRE_TOKEN=true` and configure the device to send the token as a query string or `X-Device-Token` header.
- ZKTeco devices typically use the HTTP `Date` header plus `TimeZone` option for time alignment. The command queue therefore exposes time sync through option reload / `SET OPTION` flows instead of inventing a custom protocol.
- Some firmware variants push user updates through `USERINFO`, others mix them into operational logs. The parser treats either pattern as a biometric identity sync when it sees `PIN`, `Name`, or `Card` fields.
