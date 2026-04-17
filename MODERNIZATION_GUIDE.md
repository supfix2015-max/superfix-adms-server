# ADMS Server ZKTeco - Laravel 12 Modernization Guide

## Project Status

This document outlines the complete modernization of the ADMS Server for ZKTeco biometric devices into a production-ready Laravel 12 application.

## Phase 1: Database & Models ✅ COMPLETED

### Migrations Created
- `2026_04_16_100000_recreate_devices_table.php` - Devices with performance indexes
- `2026_04_16_100001_recreate_employees_table.php` - Employees with fulltext search
- `2026_04_16_100002_recreate_attendance_logs_table.php` - Attendance logs with composite indexes
- `2026_04_16_100003_recreate_raw_logs_table.php` - Raw device payloads
- `2026_04_16_100004_recreate_device_commands_table.php` - Device commands queue
- `2026_04_16_100005_create_device_activity_logs_table.php` - Device online/offline tracking
- `2026_04_16_100006_recreate_error_logs_table.php` - Error logging and tracking
- `2026_04_16_100007_create_api_tokens_table.php` - Device API authentication

### Performance Optimizations
- **Composite Indexes**: Attendance logs optimized for common query patterns
- **Partial Indexes**: Status and online/offline tracking
- **Full-text Search**: Employee name/ID search
- **Soft Deletes**: All core tables support soft deletion

### Run Migrations
```bash
php artisan migrate:fresh --seed
```

## Phase 2: Services Layer - TO DO

### Files to Create

#### 1. `app/Services/ADMSPayloadService.php`
```php
// Responsibilities:
// - Parse ADMS protocol payload (key=value format)
// - Convert device response to ADMS protocol format
// - Handle payload validation and error responses
```

#### 2. `app/Services/AttendanceService.php`
```php
// Responsibilities:
// - Match raw logs to employees
// - Create normalized attendance records
// - Process attendance statistics
// - Handle unmatched records
```

#### 3. `app/Services/DeviceService.php`
```php
// Responsibilities:
// - Device registration and validation
// - API token generation and management
// - Device heartbeat processing
// - Device synchronization status
// - Command queue management
```

#### 4. `app/Services/EmployeeService.php`
```php
// Responsibilities:
// - Employee CRUD operations
// - Biometric data management
// - Department/position management
// - Search and filtering
```

#### 5. `app/Services/LogProcessingService.php` (NEW)
```php
// Responsibilities:
// - Process raw device logs asynchronously
// - Handle large log batches
// - Database transaction management
// - Error recovery and retries
```

## Phase 3: API Controllers - TO DO

### Files to Create/Enhance

#### 1. `app/Http/Controllers/Api/DeviceApiController.php`
```php
// Endpoints:
// POST /api/device/register - Device registration
// POST /api/device/ping - Heartbeat
// POST /api/device/logs - Log submission
// POST /api/device/command - Command response
// GET /api/health - Health check
```

#### 2. `app/Http/Controllers/Api/ADMSController.php`
```php
// ADMS Protocol Endpoints (Plain-text responses):
// POST /api/iclock/cdata - Attendance data
// POST /api/iclock/test - Connection test
// POST /api/iclock/getrequest - Get pending commands
// GET /api/health - Health endpoint
```

## Phase 4: Queue Jobs - TO DO

### Files to Create

#### 1. `app/Jobs/ProcessAttendanceLogJob.php`
```php
// - Accepts raw device payload
// - Validates and normalizes data
// - Matches to employees
// - Creates attendance records
// - Retries on failure
```

#### 2. `app/Jobs/StoreRawLogJob.php`
```php
// - Stores raw device payload
// - Logs submission timestamp
// - Records parsing status
// - Handles large payloads
```

#### 3. `app/Jobs/SendDeviceCommandJob.php`
```php
// - Sends queued commands to devices
// - Handles retries
// - Tracks command status
// - Logs responses
```

## Phase 5: Admin Panel - TO DO

### Blade Template Structure

```
resources/views/
├── layouts/
│   ├── app.blade.php (Main layout)
│   ├── sidebar.blade.php
│   └── header.blade.php
├── components/
│   ├── alert.blade.php
│   ├── badge.blade.php
│   ├── pagination.blade.php
│   ├── form-group.blade.php
│   └── delete-confirmation.blade.php
├── dashboard/
│   ├── index.blade.php
│   ├── statistics.blade.php
│   └── charts.blade.php
├── devices/
│   ├── index.blade.php
│   ├── show.blade.php
│   ├── create.blade.php
│   ├── edit.blade.php
│   └── activity.blade.php
├── employees/
│   ├── index.blade.php
│   ├── show.blade.php
│   ├── create.blade.php
│   └── edit.blade.php
├── attendance/
│   ├── index.blade.php
│   ├── filters.blade.php
│   └── export.blade.php
├── commands/
│   ├── index.blade.php
│   ├── send.blade.php
│   └── history.blade.php
└── logs/
    ├── errors.blade.php
    ├── device-activity.blade.php
    └── api-logs.blade.php
```

### Admin Controllers to Create
- `app/Http/Controllers/Admin/DashboardController.php`
- `app/Http/Controllers/Admin/DeviceController.php`
- `app/Http/Controllers/Admin/EmployeeController.php`
- `app/Http/Controllers/Admin/AttendanceController.php`
- `app/Http/Controllers/Admin/CommandController.php`
- `app/Http/Controllers/Admin/LogController.php`

## Phase 6: Authentication & Authorization

### Already Implemented (from Laravel Breeze)
- Login/Register pages
- Password reset
- Email verification

### To Add
- Role-based authorization (Admin, Manager, Viewer)
- Policy classes for resource authorization
- Device API token authentication

### Policies to Create
- `app/Policies/DevicePolicy.php`
- `app/Policies/EmployeePolicy.php`
- `app/Policies/AttendancePolicy.php`

## Phase 7: Configuration Files

### Environment Configuration (.env)
```
# ADMS Configuration
ADMS_HEARTBEAT_TIMEOUT=300
ADMS_LOG_RETENTION_DAYS=90
ADMS_MAX_BATCH_SIZE=1000

# Queue Configuration
QUEUE_CONNECTION=redis # or database
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# API Configuration
API_RATE_LIMIT=100
DEVICE_API_TIMEOUT=30
```

### queue.php Configuration
```php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        // ...
    ],
    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
    ],
],
```

## Phase 8: Testing Structure

### Test Files to Create
```
tests/
├── Feature/
│   ├── Api/DeviceApiTest.php
│   ├── Api/ADMSControllerTest.php
│   ├── Admin/DeviceControllerTest.php
│   └── Admin/AttendanceControllerTest.php
├── Unit/
│   ├── Services/ADMSPayloadServiceTest.php
│   ├── Services/AttendanceServiceTest.php
│   └── Services/LogProcessingServiceTest.php
└── Fixtures/
    ├── sample_adms_payload.txt
    └── device_responses.json
```

## Phase 9: Deployment

### VPS Setup Instructions

#### 1. Server Requirements
```
- Ubuntu 22.04 LTS
- PHP 8.3+ (with extensions: bcmath, ctype, curl, mbstring, json, pdo_mysql)
- MySQL 8.0+
- Nginx or Apache
- Redis (optional but recommended)
- Supervisor (for queue workers)
```

#### 2. Installation Steps
```bash
# 1. Clone repository
git clone <repo> /var/www/adms
cd /var/www/adms

# 2. Install dependencies
composer install --optimize-autoloader --no-dev

# 3. Copy .env and configure
cp .env.example .env
nano .env  # Configure database, Redis, etc.

# 4. Generate app key
php artisan key:generate

# 5. Run migrations
php artisan migrate --seed

# 6. Cache configuration
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7. Set permissions
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data /var/www/adms

# 8. Setup cron job
* * * * * cd /var/www/adms && php artisan schedule:run >> /dev/null 2>&1

# 9. Setup supervisor for queue worker
# See: config/supervisor/adms-queue.conf
```

#### 3. Nginx Configuration (at `/etc/nginx/sites-available/adms`)
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/adms/public;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    index index.html index.htm index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

#### 4. Supervisor Configuration (`config/supervisor/adms-queue.conf`)
```ini
[program:adms-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/adms/artisan queue:work redis --sleep=3 --tries=3
autostart=true
autorestart=true
stderr_logfile=/var/log/adms-queue.err.log
stdout_logfile=/var/log/adms-queue.out.log
numprocs=2
```

## Phase 10: API Documentation

### Device Registration
```
POST /api/device/register
Content-Type: application/json

{
    "serial_number": "ZK100001",
    "name": "Main Entrance",
    "device_type": "ZK100",
    "ip_address": "192.168.1.100",
    "port": 80
}

Response:
{
    "success": true,
    "device_id": 1,
    "api_token": "token_xxx",
    "api_url": "https://your-domain.com/api"
}
```

### Device Heartbeat
```
POST /api/device/ping
Headers: Authorization: Bearer {api_token}

Response: {success: true, timestamp: "2026-04-16T10:30:00Z"}
```

### Submit Attendance Logs
```
POST /api/device/logs
Headers: Authorization: Bearer {api_token}
Content-Type: application/json

{
    "logs": [
        {
            "employee_code": "E001",
            "timestamp": "2026-04-16T10:30:00Z",
            "log_type": "checkin",
            "verify_type": "fingerprint"
        }
    ]
}
```

## Next Steps

1. **Create Service Classes** - Implement business logic
2. **Build API Controllers** - ADMS protocol compliance
3. **Implement Queue Jobs** - Asynchronous processing
4. **Create Admin Panel Views** - Blade templates
5. **Add Tests** - Ensure reliability
6. **Deploy** - Follow VPS instructions
7. **Monitor** - Setup logging and monitoring

## Performance Targets

- **Log Processing**: <100ms per log (with queue)
- **API Response Time**: <500ms
- **Database Queries**: <5 per page load (with eager loading)
- **Memory Usage**: <100MB per queue worker
- **Uptime**: 99.9%

## Security Considerations

1. **API Authentication**: Token-based for device endpoints
2. **Input Validation**: All inputs validated server-side
3. **Rate Limiting**: Prevent abuse of API endpoints
4. **CORS**: Disabled for non-SPA applications
5. **HTTPS**: Required for production
6. **Database**: Prepared statements, no raw SQL
7. **Logging**: No sensitive data in logs

## Support & Debugging

### Enable Debug Mode (Development Only)
```
APP_DEBUG=true
APP_ENV=local
```

### Check Queue Status
```bash
php artisan queue:failed
php artisan queue:retry all
```

### Clear Caches
```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Monitor Logs
```bash
tail -f storage/logs/laravel.log
```

---

**Last Updated**: April 16, 2026
**Laravel Version**: 12.0+
**PHP Version**: 8.3+
**Status**: Framework Complete, Phase 3+ Pending
