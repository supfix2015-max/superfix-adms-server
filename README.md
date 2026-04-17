# ZKTeco ADMS Server - Laravel 12 Production Edition

[![Laravel 12](https://img.shields.io/badge/Laravel-12-green?logo=laravel)](https://laravel.com)
[![PHP 8.3](https://img.shields.io/badge/PHP-8.3-blue)](https://php.net)
[![License MIT](https://img.shields.io/badge/license-MIT-black)](LICENSE)

Production-ready **Automatic Device Management System** for ZKTeco biometric devices. 
**No Docker** - deployable on any VPS/shared hosting.

## 🎯 Features

- ✅ **Full ZKTeco ADMS Protocol**: `/iclock/cdata`, `/test`, `/getrequest`, `/logs`
- ✅ **JSON API**: `/api/device/register`, `/ping`, `/logs`, `/command`
- ✅ **Async Queue Processing**: Millions of logs scalable
- ✅ **Blade Admin Panel**: Dashboard, CRUD, Reports, Commands
- ✅ **CSV Export**, Device online/offline, Error tracking
- ✅ **Clean Architecture**: Services/Repositories/Jobs

## 🚀 Quick Start (Development)

```bash
git clone https://github.com/your-repo/ADMS-server-ZKTeco-main
cd ADMS-server-ZKTeco-main
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

**Admin**: `/admin/dashboard`
**API Test**: `curl -X POST http://127.0.0.1:8000/iclock/cdata -d 'SN=TEST123&Name=Test'`

## ☁️ Production Deployment (VPS/CentOS/Ubuntu)

### 1. Prerequisites
```bash
# PHP 8.3+, MySQL 8+, Composer, Redis (optional)
sudo apt update && sudo apt install php8.3 php8.3-fpm php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip unzip nginx mysql-server redis-server supervisor composer
```

### 2. Deploy
```bash
cd /var/www
sudo unzip ADMS-server-ZKTeco-main.zip
cd ADMS-server-ZKTeco-main
sudo composer install --optimize-autoloader --no-dev
sudo cp .env.example .env
sudo php artisan key:generate
sudo nano .env  # APP_URL, DB_*, QUEUE_CONNECTION=database/redis
sudo php artisan migrate --force
sudo php artisan db:seed --force
sudo php artisan config:cache
sudo php artisan route:cache  
sudo php artisan view:cache
sudo chmod -R 775 storage bootstrap/cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

### 3. Nginx Config
```nginx
server {
    listen 80;
    server_name adms.example.com;
    root /var/www/ADMS-server-ZKTeco-main/public;
    
    index index.php index.html;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 4. Queue Worker (Supervisor)
```bash
# /etc/supervisor/conf.d/adms-queue.conf
[program:adms-queue]
command=php /var/www/ADMS-server-ZKTeco-main/artisan queue:work --tries=3 --timeout=90
directory=/var/www/ADMS-server-ZKTeco-main
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/adms-queue.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start adms-queue:*
```

### 5. Cron Jobs
```bash
# /etc/crontab
* * * * * www-data cd /var/www/ADMS-server-ZKTeco-main && php artisan schedule:run >> /dev/null 2>&1
```

## ⚙️ ZKTeco Device Configuration

**Network Settings**:
```
Server IP: your-vps-ip
Server Port: 80
ADMS Server: http://your-vps-ip
ADMS Upload: Enabled  
Real Time Clock Sync: Enabled
Auto Time Sync: Enabled
```

**Test Connection**: Device → Attendance → Real-time Data Sync

## 🔧 API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/iclock/cdata` | Device registration/handshake |
| POST | `/iclock/test` | Heartbeat ping |
| GET | `/iclock/getrequest` | Poll pending commands |
| POST | `/iclock/logs` | Upload attendance logs |
| POST | `/api/device/register` | JSON device register |
| POST | `/api/device/ping` | JSON heartbeat |
| POST | `/api/device/logs` | JSON log upload |
| GET | `/health` | Server health |

## 📊 Admin Panel
```
 /admin/dashboard → Stats dashboard
 /admin/devices → Device CRUD + API tokens
 /admin/employees → Employee management  
 /admin/attendance → Logs + CSV export
 /admin/commands → Send REBOOT/SYNC/CLEAR etc.
 /admin/logs → Raw payloads + errors
```

## 🛡️ Security
- Laravel Sanctum API tokens per device
- Admin-only routes (user->is_admin)
- Rate limiting, input validation
- Raw payloads stored for debugging

## 📈 Performance
- **Composite indexes** on high-write columns
- **Queue async** processing (never blocks API)
- **Eager loading** relationships
- **Pagination** everywhere
- **View/Route caching** production-ready

## 🧪 Testing
```bash
php artisan test
# PHPUnit feature tests: API + Admin routes
```

## 📄 License
MIT - Free for commercial use.

**Questions?** Check MODERNIZATION_GUIDE.md or open issue.

