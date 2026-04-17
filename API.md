# ADMS API Documentation

## Overview

Complete API documentation for the ADMS Server. These endpoints are used by ZKTeco devices to:
1. Register and send device information
2. Upload attendance logs
3. Fetch pending commands
4. Check system health

---

## Authentication

### Device Token Authentication

All JSON API endpoints require device authentication:

```http
Authorization: Bearer your-device-token
```

Or include token in request body:

```
token=your-device-token
```

### ADMS Protocol

ADMS endpoints expect plain-text payloads with device serial number:

```
SN=SK172018010001
```

---

## 1. Device Registration

### POST `/api/device/register`

Register a new device or update existing device info.

**Request:**
```json
{
    "name": "Main Entrance",
    "sn": "SK172018010001",
    "ip_address": "192.168.1.100",
    "port": 8080,
    "device_type": "face",
    "firmware_version": "V2.1.12",
    "hardware_version": "V3.0",
    "capacity": 50
}
```

**Response (Success):**
```json
{
    "status": "success",
    "message": "Device registered successfully",
    "device": {
        "id": 1,
        "name": "Main Entrance",
        "sn": "SK172018010001",
        "api_token": "abc123...",
        "status": "active",
        "created_at": "2024-01-15T10:30:00Z"
    }
}
```

**Response (Exists):**
```json
{
    "status": "success",
    "message": "Device updated",
    "device": { ... }
}
```

---

## 2. Device Heartbeat/Ping

### POST `/api/device/ping`

Send periodic heartbeat to mark device as online.

**Request:**
```json
{
    "token": "your-api-token"
}
```

**Response:**
```json
{
    "status": "ok",
    "timestamp": "2024-01-15T10:30:25Z"
}
```

---

## 3. Upload Attendance Logs

### POST `/api/device/logs`

Upload attendance records from device.

**Request:**
```
Headers:
  Authorization: Bearer your-api-token
  Content-Type: application/x-www-form-urlencoded

Body (plain-text):
SN=SK172018010001
PIN=EMP001
Time=2024-01-15 09:30:25
VerifyFace=1
Temperature=36.5
```

**Response:**
```json
{
    "status": "ok",
    "message": "Logs received",
    "count": 1
}
```

---

## 4. Fetch Pending Commands

### POST `/api/device/command`

Fetch commands pending for the device.

**Request:**
```json
{
    "token": "your-api-token"
}
```

**Response (Command Pending):**
```json
{
    "status": "ok",
    "command": {
        "type": "sync",
        "id": "cmd_123"
    }
}
```

**Response (No Command):**
```json
{
    "status": "ok",
    "message": "No commands pending"
}
```

---

## 5. ADMS Protocol Endpoints

### Plain-Text Communication

ZKTeco devices communicate via plain-text ADMS protocol.

#### Device Information Upload
```http
POST /api/iclock/cdata

Request:
SN=SK172018010001
IPAddress=192.168.1.100
FirmwareVersion=V2.1.12
HardwareVersion=V3.0
Capacity=50
Face=1
Fingerprint=1

Response:
OK
```

#### Heartbeat/Test
```http
POST /api/iclock/test

Request:
SN=SK172018010001

Response:
OK
```

#### Upload Logs
```http
POST /api/iclock/logs or /api/iclock/transaction

Request:
SN=SK172018010001
PIN=EMP001
Time=2024-01-15 09:30:25
VerifyFace=1
Temperature=36.5

Response:
OK
```

#### Fetch Commands
```http
POST /api/iclock/getrequest

Request:
SN=SK172018010001

Response (with command):
CMD=sync
DeviceID=1
Parameters=...

Response (no command):
OK
```

---

## 6. Health Check

### GET `/api/health`

Check system availability.

**Response:**
```json
{
    "status": "ok",
    "timestamp": "2024-01-15T10:30:25Z",
    "uptime": 3600
}
```

---

## ADMS Payload Format

### Attendance Log Record

```
SN=SK172018010001              # Device serial number [REQUIRED]
PIN=EMP001                     # Employee code [REQUIRED]
UserID=1                       # Employee ID on device
LogID=12345                    # Log ID on device
Time=2024-01-15 09:30:25       # Timestamp [REQUIRED]
VerifyFace=1                   # Recognized by face
VerifyFP=1                     # Recognized by fingerprint
VerifyID=1                     # Recognized by card/RFID
Temperature=36.5               # Temperature (thermal devices)
EventType=0                    # 0=checkin, 1=checkout, 2=break
DeviceType=1                   # Device type code
```

### Device Information Payload

```
SN=SK172018010001              # Serial number
IPAddress=192.168.1.100        # Device IP address
Port=8080                      # Device port
FirmwareVersion=V2.1.12        # Firmware version
HardwareVersion=V3.0           # Hardware version
Capacity=50                    # Employee capacity
Face=1                         # Has face recognition
Fingerprint=1                  # Has fingerprint
RFID=1                         # Has RFID reader
Thermal=1                      # Has thermal camera
```

---

## Response Codes

| Code | Meaning |
|------|---------|
| `OK` | Success |
| `ERROR` | General error |
| `ERROR=message` | Specific error |

### HTTP Status Codes

| Status | Meaning |
|--------|---------|
| `200` | Success |
| `201` | Created |
| `400` | Bad request |
| `401` | Unauthorized/Invalid token |
| `422` | Validation error |
| `500` | Server error |

---

## Example Integration

### Python

```python
import requests
import json
from datetime import datetime

ADMS_URL = "https://your-domain.com/api"
DEVICE_TOKEN = "your-api-token"

# 1. Register device
response = requests.post(f"{ADMS_URL}/device/register", json={
    "name": "Main Entrance",
    "sn": "SK172018010001",
    "ip_address": "192.168.1.100",
    "device_type": "face"
})
print(response.json())

# 2. Send heartbeat
response = requests.post(f"{ADMS_URL}/device/ping", json={
    "token": DEVICE_TOKEN
})
print(response.json())

# 3. Upload attendance
attendance_data = f"""SN=SK172018010001
PIN=EMP001
Time={datetime.now().strftime('%Y-%m-%d %H:%M:%S')}
VerifyFace=1
"""

response = requests.post(
    f"{ADMS_URL}/device/logs",
    data=attendance_data,
    headers={"Authorization": f"Bearer {DEVICE_TOKEN}"}
)
print(response.json())
```

### cURL

```bash
# 1. Register device
curl -X POST https://your-domain.com/api/device/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Main Entrance",
    "sn": "SK172018010001",
    "ip_address": "192.168.1.100",
    "device_type": "face"
  }'

# 2. Heartbeat
curl -X POST https://your-domain.com/api/device/ping \
  -H "Content-Type: application/json" \
  -d '{"token": "your-api-token"}'

# 3. Upload logs (ADMS format)
curl -X POST https://your-domain.com/api/iclock/logs \
  -d "SN=SK172018010001&PIN=EMP001&Time=2024-01-15%2009:30:25&VerifyFace=1"

# 4. Health check
curl https://your-domain.com/api/health
```

### NodeJS

```javascript
const axios = require('axios');

const ADMS_URL = 'https://your-domain.com/api';
const DEVICE_TOKEN = 'your-api-token';

// Register device
axios.post(`${ADMS_URL}/device/register`, {
    name: 'Main Entrance',
    sn: 'SK172018010001',
    ip_address: '192.168.1.100',
    device_type: 'face'
}).then(res => console.log(res.data));

// Heartbeat
axios.post(`${ADMS_URL}/device/ping`, {
    token: DEVICE_TOKEN
}).then(res => console.log(res.data));

// Upload logs
const attendanceData = `SN=SK172018010001\nPIN=EMP001\nTime=2024-01-15 09:30:25\nVerifyFace=1`;
axios.post(`${ADMS_URL}/device/logs`, attendanceData, {
    headers: {
        'Authorization': `Bearer ${DEVICE_TOKEN}`,
        'Content-Type': 'text/plain'
    }
}).then(res => console.log(res.data));
```

---

## Error Handling

### Common Errors

**Invalid Token**
```json
{
    "status": "error",
    "message": "Invalid device token"
}
```

**Validation Error**
```json
{
    "status": "error",
    "message": "Invalid payload",
    "errors": [
        "Missing device serial number",
        "Missing timestamp"
    ]
}
```

**Database Error**
```json
{
    "status": "error",
    "message": "Failed to store attendance log"
}
```

---

## Rate Limiting

- Default: 10 requests/second per device
- Burst: 20 requests allowed
- Applies to `/api/iclock/*` endpoints

Configure in Nginx/Apache (see DEPLOYMENT.md)

---

## Timestamps

All timestamps use ISO 8601 format (UTC):
```
2024-01-15T10:30:25Z
```

Device payloads use local format:
```
2024-01-15 09:30:25
```

---

## Webhook Events (Future)

Planned for future versions:
- Device online/offline
- Unmatched attendance
- High error rate
- Command acknowledgment

---

## Support

For API issues:
- Check ADMS logs: `storage/logs/laravel.log`
- Review raw payloads: Check `raw_logs` table in database
- Test connectivity: `curl https://your-domain.com/api/health`

---

**Last Updated:** January 15, 2024  
**Version:** 1.0.0
