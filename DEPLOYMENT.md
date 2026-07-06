# Deploying DFCP to dms.deshfiri.com (aaPanel VPS)

This is a one-time server setup guide. Once these steps are done, every push to
`main` is deployed automatically by `.github/workflows/ci-cd.yml` (build →
test → rsync code to the server → run migrations/cache commands). You only
need to repeat anything here if you rebuild the server or add a new
requirement (e.g. Redis).

Server: `164.68.123.155` (aaPanel), site path: `/www/wwwroot/dms`

## 1. Prerequisites in aaPanel

Install these from the aaPanel "App Store" if not already present:

- **PHP 8.4** with these extensions enabled (aaPanel → PHP settings → extensions):
  `mbstring, dom, fileinfo, curl, pdo, pdo_mysql, bcmath, gd, zip, intl, xml, openssl, tokenize, ctype, json`
- **MySQL** (5.7+/8.0) or MariaDB
- **Nginx**
- Composer (aaPanel App Store → "Composer" panel, or install manually — see step 3)
- Node.js 20+ (aaPanel App Store → "Node.js Version Manager", or install manually)
- Supervisor (App Store → "Supervisor Manager") — keeps the queue worker running

## 2. Create the site in aaPanel

1. Website → Add site → domain `dms.deshfiri.com`, PHP version **8.4**.
2. This creates `/www/wwwroot/dms`. That's the `DEPLOY_PATH` used by CI.
3. **Important:** Website → dms.deshfiri.com → Site Directory → set
   "Run directory" (运行目录) to `/public`. Laravel's `public/` folder must be
   the web root — never serve the project root directly, or `.env` and
   application source become downloadable.
4. Website → dms.deshfiri.com → SSL → issue a free Let's Encrypt certificate,
   enable "Force HTTPS".

## 3. First-time code + dependencies

The very first deploy needs to happen manually once (afterwards CI takes
over). SSH in as `khalid`:

```bash
ssh -p 9934 khalid@164.68.123.155
cd /www/wwwroot/dms
```

Either let the next CI run populate this directory (push to `main` after
finishing this guide), or clone once by hand:

```bash
git clone https://github.com/deshfiri/df-cms.git .
composer install --no-dev --optimize-autoloader
npm install && npm run build
```

## 4. Configure `.env`

Copy the example and fill in production values — this file is **not**
overwritten by CI (it's excluded from the deploy rsync on purpose):

```bash
cp .env.example .env
php artisan key:generate
nano .env
```

Set at minimum:

```
APP_NAME=DFCP
APP_ENV=production
APP_DEBUG=false
APP_URL=https://dms.deshfiri.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dfcp
DB_USERNAME=<create this DB + user in aaPanel → Databases>
DB_PASSWORD=<...>

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=smtp
MAIL_HOST=<your mail host — dfmail suggests one already runs on this box>
MAIL_PORT=587
MAIL_USERNAME=<...>
MAIL_PASSWORD=<...>
MAIL_FROM_ADDRESS=noreply@deshfiri.com
```

Leave the `GOOGLE_CALENDAR_*` vars blank unless you're wiring up the
Meet/Calendar integration — meetings work fine without them.

## 5. Database, storage, permissions

```bash
cd /www/wwwroot/dms
php artisan migrate --force --seed   # seeds roles + default admin@dfcp.com/password — change this password immediately
php artisan storage:link
chown -R www:www /www/wwwroot/dms
chmod -R 775 storage bootstrap/cache
```

(`www` is aaPanel's default web server user; check `ps aux | grep nginx` if
your panel uses a different one.)

## 6. Queue worker (Supervisor)

The app uses the `database` queue driver, so a worker process must run
continuously. In aaPanel → Supervisor Manager → Add Daemon:

- Name: `dfcp-queue`
- Run directory: `/www/wwwroot/dms`
- Start command:
  ```
  php artisan queue:work --sleep=3 --tries=3 --max-time=3600
  ```
- Number of processes: 1 (increase only if the queue backs up)

CI already calls `php artisan queue:restart` after every deploy so workers
pick up new code without you touching Supervisor again.

## 7. Scheduler (cron)

Add one cron job (aaPanel → Cron Tasks → Shell script, every minute):

```bash
* * * * * php /www/wwwroot/dms/artisan schedule:run >> /dev/null 2>&1
```

## 8. Nginx — Laravel rewrite rules

aaPanel's default PHP site template already includes the standard Laravel
`try_files` rewrite, but confirm the site's Nginx config (Website →
dms.deshfiri.com → Config) contains:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/tmp/php-cgi-84.sock;   # matches your PHP 8.4 install
    fastcgi_index index.php;
    include fastcgi.conf;
}

location ~ /\.(?!well-known).* {
    deny all;
}
```

## 9. Verify

```bash
curl -I https://dms.deshfiri.com
php artisan about   # sanity-check env, drivers, queue connection
```

Log in with `admin@dfcp.com` / `password` and change the password
immediately.

## 10. Ongoing deploys

From here on, `git push origin main` (from your dev machine) triggers
`.github/workflows/ci-cd.yml`, which:

1. Runs the test suite.
2. Builds production Composer/npm assets.
3. `rsync`s the code to `/www/wwwroot/dms` (never touches `.env` or `storage/`).
4. Runs `migrate --force`, cache commands, and `queue:restart` over SSH.

If a deploy fails, check the run under the repo's **Actions** tab first —
the log shows exactly which step failed (auth, rsync, or an artisan command).
