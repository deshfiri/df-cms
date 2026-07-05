# DFCP COMS — Deployment Guide

## Server Requirements
- PHP 8.2+ with extensions: pdo_mysql, mbstring, openssl, tokenizer, xml, ctype, json, bcmath, gd, zip
- MySQL 8.0+
- Composer 2.x
- Nginx or Apache
- Node.js (for asset compilation, optional — CDN assets used)

## 1. Clone & Install

```bash
git clone <repo> /var/www/dfcp-coms
cd /var/www/dfcp-coms
composer install --no-dev --optimize-autoloader
```

## 2. Environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env`:
```env
APP_NAME="DFCP COMS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dfcp_coms
DB_USERNAME=dfcp_user
DB_PASSWORD=strong_password_here

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

## 3. Database

```bash
# Create database
mysql -u root -p -e "CREATE DATABASE dfcp_coms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE USER 'dfcp_user'@'localhost' IDENTIFIED BY 'strong_password_here';"
mysql -u root -p -e "GRANT ALL PRIVILEGES ON dfcp_coms.* TO 'dfcp_user'@'localhost'; FLUSH PRIVILEGES;"

# Run migrations and seed
php artisan migrate --force
php artisan db:seed --force
```

## 4. Permissions

```bash
chown -R www-data:www-data /var/www/dfcp-coms
chmod -R 755 /var/www/dfcp-coms
chmod -R 775 /var/www/dfcp-coms/storage
chmod -R 775 /var/www/dfcp-coms/bootstrap/cache
```

## 5. Nginx Configuration

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/dfcp-coms/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht { deny all; }

    client_max_body_size 15M;
}
```

## 6. Queue Worker (Supervisor)

```ini
# /etc/supervisor/conf.d/dfcp-worker.conf
[program:dfcp-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/dfcp-coms/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/dfcp-worker.log
```

```bash
supervisorctl reread
supervisorctl update
supervisorctl start dfcp-worker:*
```

## 7. Cron (Laravel Scheduler)

```bash
# Add to crontab: crontab -e
* * * * * cd /var/www/dfcp-coms && php artisan schedule:run >> /dev/null 2>&1
```

## 8. Optimize for Production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

## 9. Admin Credentials

After seeding:
- **Super Admin**: admin@dfcp.com / password  ← **CHANGE THIS IMMEDIATELY**

## 10. Backup

```bash
# Database backup
mysqldump -u dfcp_user -p dfcp_coms > backup_$(date +%Y%m%d).sql

# Files backup
tar -czf dfcp_storage_$(date +%Y%m%d).tar.gz /var/www/dfcp-coms/storage/app/
```
