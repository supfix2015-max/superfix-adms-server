# Production Deployment Guide - ZKTeco ADMS Server

## Server Requirements
```
Ubuntu 22.04+ / CentOS 8+ / Debian 11+
PHP 8.3+ (fpm, mysql, redis, mbstring, xml, zip, gd, curl)
MySQL 8.0+ / MariaDB 10.6+
Nginx 1.20+ / Apache 2.4+
Redis 6+ (optional, fallback to DB queue)
Composer 2+
Supervisor 4+
Git / Unzip
```

## Step-by-Step VPS Deployment

### 1. Server Setup (Ubuntu/Debian)
```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install PHP 8.3 + extensions
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.3 php8.3-fpm php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml php8.3-zip php8.3-curl php8.3-gd unzip nginx mysql-server redis-server supervisor composer

# Secure MySQL
sudo mysql_secure_installation
```

### 2. Create Deployment Directory
```bash
sudo mkdir -p /var/www/adms-server
sudo chown $USER:$USER /var/www/adms-server
cd /var/www/adms-server
```

### 3. Upload Project
```bash
# Option 1: Git
git clone https://github.com/your-repo/ADMS-server-ZKTeco-main .
# Option 2: Upload ZIP
unzip ADMS-server-ZKTeco-main.zip
cd ADMS-server-ZKTeco-main
```

### 4. Install Dependencies
```bash
composer install --optimize-autoloader --no-dev --no-scripts
sudo chown -R www-data:www-data vendor storage bootstrap/cache
```

### 5. Environment Setup
```bash
cp .env.example .env
nano .env
```

**Critical .env settings**:
```
APP_NAME="ADMS Server"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://adms.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306  
DB_DATABASE=adms_server
DB_USERNAME=adms_user
DB_PASSWORD=your_secure_password

QUEUE_CONNECTION=database  # or redis
CACHE_DRIVER=file
SESSION_DRIVER=file
```

### 6. Database Setup
```bash
# Create DB
sudo mysql -u root -p
CREATE DATABASE adms_server CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'adms_user'@'localhost' IDENTIFIED BY 'secure_password123';
GRANT ALL ON adms_server.* TO 'adms_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;

php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
```

### 7. Laravel Optimization
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:table  # if using DB queue
php artisan migrate --force
```

### 8. Nginx Configuration
```bash
sudo nano /etc/nginx/sites-available/adms-server
```

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name adms.yourdomain.com;
    root /var/www/adms-server/public;
    index index.php;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;
    add_header Content-Security-Policy "default-src 'self' http: https: data: blob: 'unsafe-inline'" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    # Cache static assets
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/adms-server /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 9. Queue Worker (Supervisor)
```bash
sudo nano /etc/supervisor/conf.d/adms-queue.conf
```

```ini
[program:adms-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/adms-server/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/adms-queue-%(process_num)02d.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start adms-queue-worker:*
```

### 10. Cron Jobs
```bash
sudo crontab -u www-data -e
```
```
# Laravel scheduler
* * * * * cd /var/www/adms-server && php artisan schedule:run >> /dev/null 2>&1

# Queue restart (weekly)
0 2 * * 0 cd /var/www/adms-server && php artisan queue:restart >> /dev/null 2>&1
```

### 11. Permissions & Finalize
```bash
sudo chown -R www-data:www-data /var/www/adms-server
sudo chmod -R 755 /var/www/adms-server
sudo chmod -R 775 /var/www/adms-server/storage /var/www/adms-server/bootstrap/cache
```

## 🧪 Post-Deploy Verification

```bash
# Check services
sudo supervisorctl status
sudo systemctl status nginx php8.3-fpm redis-server mysql

# Laravel status
php artisan route:list | wc -l  # ~100 routes
php artisan view:clear && php artisan config:clear  # dev only

# Test endpoints
curl -I https://adms.yourdomain.com/health
curl -X POST https://adms.yourdomain.com/iclock/cdata -d 'SN=TEST'
```

## 📈 Monitoring & Scaling

**Supervisor Logs**: `/var/log/adms-queue-*.log`
**Laravel Logs**: `storage/logs/laravel.log`
**Queue Monitor**: `/admin/dashboard` (real-time stats)

**Scale Up**:
```
# More queue workers
numprocs=4  # in supervisor.conf

# Redis queue (faster)
QUEUE_CONNECTION=redis

# DB Optimize (10M+ records)
# Add to .env: DB_CONNECTION=mysql
# Indexes already optimized for writes
```

## 🔧 Common Issues

| Issue | Solution |
|-------|----------|
| 500 Error | `php artisan config:clear && php artisan cache:clear` |
| Queue stuck | `php artisan queue:restart && sudo supervisorctl restart all` |
| Permissions | `sudo chown -R www-data storage bootstrap/cache` |
| ADMS no sync | Check device network/firewall + `/admin/logs` |
| Slow admin | `php artisan view:cache` + Redis session |

## 📱 ZKTeco Device Setup

1. **Network** → Server IP: `your-vps-ip`, Port: `80`
2. **ADMS** → Server IP/Port: `your-vps-ip:80`
3. **Comm** → Upload Type: `ADMS`
4. **Time** → Auto Sync: `Enabled`
5. **Test**: Device → Menu → Comm → Real Time Data Sync → Success!

**Success = Attendance logs appear in `/admin/attendance`**

---

**Live Demo**: http://adms.yourdomain.com/admin/dashboard  
**Support**: Check logs + GitHub issues
