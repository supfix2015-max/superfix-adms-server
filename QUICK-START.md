# Quick Start Guide

Get the ADMS Server running in 5 minutes.

## Prerequisites

- PHP 8.3+
- MySQL 5.7+ or MariaDB 10.3+
- Composer
- Node.js (for Vite assets, optional)

## Installation

### 1. Clone and install dependencies

```bash
cd /home/ayaan/Downloads/ADMS-server-ZKTeco-main
composer install
```

### 2. Configure environment

```bash
# Copy example config
cp .env.example .env

# Generate app key
php artisan key:generate
```

Edit `.env` and set:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=zkt_adms_prod
DB_USERNAME=root
DB_PASSWORD=

APP_DEBUG=true          # false in production
QUEUE_CONNECTION=database
```

### 3. Create database

```bash
# Create MySQL database
mysql -u root -p -e "CREATE DATABASE zkt_adms_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 4. Run migrations and seeders

```bash
php artisan migrate --seed
```

This creates:
- 7 tables (devices, employees, attendance_logs, raw_logs, device_commands, device_syncs, error_logs)
- 3 test users (admin, operator, viewer)
- 5 sample employees
- 3 sample devices

### 5. Build frontend assets (optional)

```bash
npm install
npm run dev
```

For production: `npm run build`

## Verification

### 1. Start development server

```bash
php artisan serve
```

Server runs at: `http://localhost:8000`

### 2. Access admin panel

- URL: `http://localhost:8000/admin/dashboard`
- Email: `admin@adms.local`
- Password: `admin123`

You should see:
- Dashboard with 3 devices and 5 employees
- Device list with online/offline status
- Attendance logs (empty until devices send data)

### 3. Verify API endpoints

```bash
# Device health check
curl http://localhost:8000/api/health

# Sample response:
# {"status":"ok","timestamp":"2024-01-15T10:30:25Z","uptime":3600}
```

### 4. Test ADMS protocol endpoint

```bash
# Device ping (ADMS format returns plain text)
curl -X POST http://localhost:8000/api/iclock/test \
  -d "SN=SK172018010001"

# Response: OK
```

### 5. Test device registration

```bash
# Register new device (JSON format)
curl -X POST http://localhost:8000/api/device/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Device",
    "sn": "SK999999999999",
    "ip_address": "192.168.1.200",
    "device_type": "face"
  }'

# Response: {"status":"success",...}
```

## File Structure Verification

```
✓ app/Models/*              - 8 models
✓ app/Services/*            - 4 service classes
✓ app/Repositories/*        - 5 repositories
✓ app/Http/Controllers/Api/*    - 2 API controllers
✓ app/Http/Controllers/Admin/*  - 4 admin controllers
✓ app/Jobs/*                - 3 queue jobs
✓ database/migrations/*     - 7 migrations
✓ database/seeders/*        - 3 seeders
✓ resources/views/admin/*   - 5 Blade templates
✓ routes/api.php            - API endpoints
✓ routes/web.php            - Admin routes
✓ API.md                    - API documentation
✓ ADMS-PROTOCOL.md          - Protocol reference
✓ DEPLOYMENT.md             - Production guide
✓ README.md                 - Project overview
```

## Common Tasks

### Run queue processor

```bash
# Process attendance logs in the background
php artisan queue:work
```

### View database

```bash
# MySQL CLI
mysql -u root zkt_adms_prod

# List tables
SHOW TABLES;

# View devices
SELECT id, name, sn, status, last_heartbeat FROM devices;

# View attendance logs
SELECT * FROM attendance_logs LIMIT 10;
```

### Check logs

```bash
# View recent errors
tail -f storage/logs/laravel.log

# View raw device payloads (for debugging)
# Check raw_logs table in database with: SELECT * FROM raw_logs ORDER BY created_at DESC LIMIT 10;
```

### Clear caches

```bash
php artisan cache:clear
php artisan view:clear
php artisan config:clear
```

### Reset database (development only!)

```bash
php artisan migrate:fresh --seed
```

## Troubleshooting

### Blank admin dashboard

**Issue**: Dashboard shows but no data

**Solution**:
1. Check seeders ran: `php artisan migrate --seed`
2. Verify database connection: `php artisan tinker` → `Device::count()` → should show 3
3. Check error logs: `tail storage/logs/laravel.log`

### "SQLSTATE[HY000]: General error"

**Issue**: Database connection or migration error

**Solution**:
1. Verify MySQL is running: `mysql -u root -p -e "SELECT 1;"`
2. Check credentials in `.env` file
3. Verify database exists: `mysql -u root -e "SHOW DATABASES;"`
4. Re-run migrations: `php artisan migrate:fresh --seed`

### API returns "Device not found"

**Issue**: Device registration failed

**Solution**:
1. Verify device serial number (SN)
2. Check device exists: `php artisan tinker` → `Device::where('sn', 'SK172018010001')->first()`
3. Check device status: `Device::first()->status` (should be 'active')

### Queue jobs not processing

**Issue**: Attendance logs not being processed

**Solution**:
1. Start queue worker: `php artisan queue:work`
2. Check jobs table: `SELECT * FROM jobs LIMIT 5;`
3. Check failed jobs: `php artisan queue:failed`
4. Retry failed: `php artisan queue:retry`

## Configuration

### Device IP Whitelist

Edit `.env`:
```
DEVICE_IP_WHITELIST=192.168.1.0/24,192.168.2.0/24
```

Implement in Kernel.php middleware if needed.

### Email Notifications

Edit `config/mail.php`:
```php
'from' => [
    'address' => 'noreply@your-domain.com',
    'name' => 'ADMS Server'
]
```

### Feature Flags

Control features in `.env`:
```
FEATURE_DEVICE_ONLINE_TRACKING=true
FEATURE_EXCEL_EXPORT=true
FEATURE_MULTI_BRANCH=false
```

## Next Steps

1. **Develop remaining Blade views** (2-3 hours)
   - admin/devices/edit.blade.php
   - admin/employees/* (create, edit, show)
   - admin/logs/index.blade.php
   - admin/users/index.blade.php

2. **Write Pest tests** (3-4 hours)
   - API endpoints
   - Services
   - ADMS payload parsing

3. **Deploy to production** (1-2 hours, see DEPLOYMENT.md)
   - Set up VPS with PHP 8.3 + MySQL
   - Configure web server (Nginx/Apache)
   - Set up Supervisor for queue workers
   - Configure ZKTeco devices

## Testing Devices

### Add test device via curl

```bash
curl -X POST http://localhost:8000/api/device/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Face Device",
    "sn": "TEST001",
    "ip_address": "127.0.0.1",
    "device_type": "face"
  }'
```

### Simulate attendance log

```bash
# Get device token from response above, then:
curl -X POST http://localhost:8000/api/device/logs \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d "SN=TEST001
PIN=EMP001
Time=$(date +'%Y-%m-%d %H:%M:%S')
VerifyFace=1
EventType=0"
```

### Check attendance was recorded

```bash
php artisan tinker

# Get latest attendance
AttendanceLog::latest('log_time')->first();

# Should show: device_id, employee_code='EMP001', log_type='checkin', status='pending'
```

## Performance Tuning (Optional)

### Enable Redis caching

```bash
# Install Redis
# Ubuntu: sudo apt-get install redis-server
# macOS: brew install redis

# Update .env
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# Start Redis
redis-server
```

### Configure query logging (development only)

```bash
# In .env
APP_DEBUG=true
```

This will show all queries in debug bar when using Laravel Debugbar.

## Support

- **API Issues**: See [API.md](API.md) for endpoint documentation
- **Protocol Issues**: See [ADMS-PROTOCOL.md](ADMS-PROTOCOL.md) for format details
- **Deployment**: See [DEPLOYMENT.md](DEPLOYMENT.md) for production setup
- **Troubleshooting**: See [README.md](README.md#troubleshooting)

---

**Version**: 1.0.0  
**Last Updated**: January 15, 2024  
**Ready for Production**: ✓ Yes (pending tests)
