# ADMS Protocol Technical Reference

## Overview

ADMS (Automatic Device Management System) uses plain-text, line-based communication with ZKTeco devices.

**Critical Point**: All responses must be plain-text, NOT JSON.

---

## Payload Format

### Basic Structure

```
KEY1=VALUE1
KEY2=VALUE2
KEY3=VALUE3
```

Line separator: `\r\n` (CRLF - Windows style)

### Example Attendance Log

```
SN=SK172018010001
PIN=EMP001
UserID=1
LogID=12345
Time=2024-01-15 09:30:25
VerifyFace=1
Temperature=36.5
EventType=0
```

---

## Parsing Logic (ADMSPayloadService)

### Step 1: Split by \r\n

```php
$lines = explode("\r\n", $payload);
// ['SN=SK172018010001', 'PIN=EMP001', 'Time=2024-01-15 09:30:25', ...]
```

### Step 2: Parse key=value pairs

```php
foreach ($lines as $line) {
    if (empty($line)) continue;
    [$key, $value] = explode('=', $line, 2);
    $data[trim($key)] = trim($value);
}
```

### Step 3: Extract attendance records

```php
$logs = [];
$logs[] = [
    'device_id' => $device->id,
    'device_log_id' => $data['LogID'] ?? null,
    'employee_code' => $data['PIN'],
    'log_time' => Carbon::createFromFormat('Y-m-d H:i:s', $data['Time']),
    'log_type' => $this->determineLogType($data['EventType'] ?? 0),
    'verify_type' => $this->determineVerifyType($data),
    'temperature' => $data['Temperature'] ?? null,
    'status' => 'pending'
];
```

---

## Response Format

### Success Response

```
OK
```

### Error Response

```
ERROR
```

or with message:

```
ERROR=Device not found
```

### With Data (Command Response)

```
CMD=sync
DeviceID=1
Parameters={"syncType": "attendance"}
```

---

## Field Mappings

### Event Types (EventType field)

| Value | Meaning |
|-------|---------|
| 0 | Check-in |
| 1 | Check-out |
| 2 | Break start |
| 3 | Break end |
| 4 | Overtime start |
| 5 | Overtime end |
| -1 | Unknown |

### Verify Types (Multiple fields possible)

| Field | Type |
|-------|------|
| VerifyFace=1 | Face recognition |
| VerifyFP=1 | Fingerprint |
| VerifyID=1 | Card/RFID |
| VerifyPW=1 | Password |
| VerifyTemp=1 | Temperature (thermal) |

### Device Capabilities

| Field | Meaning |
|-------|---------|
| Face=1 | Has face recognition |
| Fingerprint=1 | Has fingerprint reader |
| RFID=1 | Has RFID/card reader |
| Thermal=1 | Has thermal camera |

---

## Required Fields

### For Device Ping

```
SN=SK172018010001
```

### For Attendance Log

```
SN=SK172018010001          [REQUIRED] Device serial
PIN=EMP001                 [REQUIRED] Employee code
Time=2024-01-15 09:30:25   [REQUIRED] Log timestamp (Y-m-d H:i:s)
```

### Optional but Recommended

```
UserID=1
LogID=12345
VerifyFace=1
VerifyFP=1
VerifyID=1
Temperature=36.5
EventType=0
```

---

## Implementation in Code

### Receiving Payload

```php
// In ADMSController.php
public function logs(Request $request)
{
    $rawPayload = $request->getContent();  // Get raw POST body
    
    // Parse and validate
    $parsed = $this->payloadService->parsePayload($rawPayload);
    
    // Store raw for debugging
    StoreRawLogJob::dispatch($device->id, $rawPayload, 'logs');
    
    // Process asynchronously
    ProcessAttendanceLogJob::dispatch($device->id, $parsed);
    
    // Return ADMS format response
    return $this->generateResponse('OK');
}

private function generateResponse($status, $message = null)
{
    $response = $status;
    if ($message) {
        $response .= "=$message";
    }
    return response($response . "\r\n", 200, [
        'Content-Type' => 'text/plain; charset=UTF-8'
    ]);
}
```

### Parsing Payload

```php
// In ADMSPayloadService.php
public function parsePayload($rawPayload)
{
    $data = [];
    $lines = explode("\r\n", $rawPayload);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        if (strpos($line, '=') === false) continue;
        
        [$key, $value] = explode('=', $line, 2);
        $data[trim($key)] = trim($value);
    }
    
    // Validate required fields
    $this->validatePayload($data);
    
    return $data;
}

private function validatePayload($data)
{
    if (empty($data['SN'])) {
        throw new InvalidArgumentException('Missing device serial (SN)');
    }
    if (empty($data['PIN']) && empty($data['UserID'])) {
        throw new InvalidArgumentException('Missing employee identifier (PIN or UserID)');
    }
    if (empty($data['Time'])) {
        throw new InvalidArgumentException('Missing timestamp');
    }
}
```

### Processing Attendance Record

```php
public function processAttendanceRecord($data)
{
    // Determine log type from event code
    $logType = $this->determineLogType($data['EventType'] ?? 0);
    
    // Determine verify type from fields present
    $verifyType = $this->determineVerifyType($data);
    
    // Parse timestamp
    $logTime = Carbon::createFromFormat(
        'Y-m-d H:i:s',
        $data['Time']
    );
    
    // Create attendance log
    return AttendanceLog::create([
        'device_id' => $data['device_id'],
        'device_log_id' => $data['LogID'] ?? null,
        'employee_code' => $data['PIN'],
        'log_type' => $logType,
        'verify_type' => $verifyType,
        'log_time' => $logTime,
        'temperature' => $data['Temperature'] ?? null,
        'status' => 'pending',
    ]);
}

private function determineLogType($eventTypeCode)
{
    return match((int)$eventTypeCode) {
        0 => 'checkin',
        1 => 'checkout',
        2, 3 => 'break',
        4, 5 => 'overtime',
        default => 'unknown',
    };
}

private function determineVerifyType($data)
{
    $types = [];
    if (!empty($data['VerifyFace'])) $types[] = 'face';
    if (!empty($data['VerifyFP'])) $types[] = 'fingerprint';
    if (!empty($data['VerifyID'])) $types[] = 'rfid';
    if (!empty($data['Temperature'])) $types[] = 'thermal';
    if (!empty($data['VerifyPW'])) $types[] = 'password';
    
    return implode(',', $types) ?: 'unknown';
}
```

---

## Testing Payloads

### Test 1: Simple Ping

```
SN=SK172018010001
```

Response:
```
OK
```

### Test 2: Device Registration

```
SN=SK172018010001
IPAddress=192.168.1.100
FirmwareVersion=V2.1.12
HardwareVersion=V3.0
Capacity=50
Face=1
Fingerprint=1
```

Response:
```
OK
```

### Test 3: Single Attendance Record

```
SN=SK172018010001
PIN=EMP001
UserID=1
LogID=12345
Time=2024-01-15 09:30:25
VerifyFace=1
EventType=0
```

Response:
```
OK
```

### Test 4: Multiple Records (batched)

```
SN=SK172018010001
PIN=EMP001
Time=2024-01-15 09:30:25
VerifyFace=1
EventType=0
---
PIN=EMP002
Time=2024-01-15 09:31:10
VerifyFP=1
EventType=0
---
PIN=EMP003
Time=2024-01-15 09:32:45
VerifyID=1
EventType=0
```

Response:
```
OK
```

---

## Testing with cURL

### Ping Test

```bash
curl -X POST https://your-domain.com/api/iclock/test \
  -d "SN=SK172018010001"
```

### Device Registration

```bash
curl -X POST https://your-domain.com/api/iclock/cdata \
  -d "SN=SK172018010001
IPAddress=192.168.1.100
FirmwareVersion=V2.1.12
HardwareVersion=V3.0
Capacity=50
Face=1"
```

### Upload Attendance Log

```bash
curl -X POST https://your-domain.com/api/iclock/logs \
  -d "SN=SK172018010001
PIN=EMP001
Time=2024-01-15 09:30:25
VerifyFace=1
EventType=0"
```

---

## Troubleshooting

### Device Responds with ERROR

**Check:**
1. Device serial (SN) registered in database
2. Device status is 'active'
3. All required fields present

**Debug:**
1. Check `raw_logs` table for stored payload
2. Review `laravel.log` for processing errors
3. Check `error_logs` table for severity

### Employee Not Matched

**Issue:** Attendance recorded but employee_id is NULL

**Solutions:**
1. Verify employee_code (PIN) matches Employee table
2. Check if employee is active (status=1)
3. Check if biometric ID needs mapping
4. Review `unmatched_logs` in database

### Temperature Reading Issues

**For thermal devices:**
- Temperature field is optional
- Format: Decimal number (e.g., 36.5)
- Unit is Celsius
- Abnormal temp (>38) can trigger alerts

---

## Security Considerations

1. **Validate Source IP** - Check device IP matches registered IP
2. **Validate Request Size** - Limit payload to 1MB
3. **Validate Format** - Ensure only expected fields present
4. **Rate Limiting** - Max 10 requests/second per device
5. **Log Everything** - Store raw payloads for audit trail

---

## Performance Tips

1. **Batch Uploads**: Send multiple records in single request
2. **Async Processing**: Use queues to avoid blocking
3. **Composite Indexes**: database migrations include device_id + timestamp
4. **Pagination**: Admin views paginate 50 records per page
5. **Caching**: Cache active device list (expires 5 minutes)

---

## Device Configuration

### ZKTeco Device Setup

```
Network → Server Settings
├─ Server Address: your-domain.com
├─ Protocol: HTTP (or HTTPS if configured)
├─ Port: 80 or 443
├─ Endpoints:
│  ├─ Device Info: /api/iclock/cdata
│  ├─ Heartbeat: /api/iclock/test
│  ├─ Logs Upload: /api/iclock/logs or /transaction
│  ├─ Command Fetch: /api/iclock/getrequest
│  └─ Interval: 30 seconds (adjust as needed)
└─ Sync: Every 12 hours or on demand
```

---

## Related Files

- `app/Services/ADMSPayloadService.php` - Parsing implementation
- `app/Http/Controllers/Api/ADMSController.php` - Endpoint handlers
- `app/Jobs/ProcessAttendanceLogJob.php` - Queue job for processing
- `database/migrations/2024_12_01_000004_create_raw_logs_table.php` - Raw payload storage

---

**Last Updated:** January 15, 2024  
**Version:** 1.0.0
