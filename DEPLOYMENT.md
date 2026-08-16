# DFCP COMS — Deployment & Operations Guide

The single source of truth for running DFCP in production. Covers first-time
server setup, everything that must stay running, the CI/CD pipeline, and a
troubleshooting runbook.

**Production:** `https://dms.deshfiri.com` · aaPanel VPS `164.68.123.155` (SSH port `9934`, user `khalid`) · site path `/www/wwwroot/dms`

After the one-time setup below, every push to `main` deploys automatically via
[`.github/workflows/ci-cd.yml`](.github/workflows/ci-cd.yml). You only revisit
this guide when rebuilding the server, adding a service, or debugging.

---

## 1. What has to be running

DFCP is not a single process. Five things must be alive for the full feature
set to work:

| # | Service | Purpose | If it's down |
|---|---------|---------|--------------|
| 1 | **nginx + php-fpm** | Serves the app | Site is down |
| 2 | **MySQL** | Data, sessions, cache, notifications | Site is down |
| 3 | **cron** → `schedule:run` | Meeting reminders, overdue invoices, portal deadlines, KPI snapshots, flow reminders | Silent — no reminders ever fire, nobody notices for weeks |
| 4 | **Reverb** (Supervisor) | Chat, presence dots, live notification bell | Chat silently dead; notifications still land in DB but never push live |
| 5 | **Queue worker** (Supervisor) | Background jobs | Nothing today — see §9 |

Items 3 and 4 are the ones most often forgotten. Item 4 has never been in a
deploy doc before this one.

---

## 2. Prerequisites

Install from aaPanel → App Store:

- **PHP 8.4** (minimum 8.2). Enable extensions:
  `mbstring, dom, fileinfo, curl, pdo, pdo_mysql, bcmath, gd, zip, intl, xml, openssl, tokenizer, ctype, json`
- **MySQL 8.0+** or MariaDB 10.4+
- **Nginx**
- **Composer 2.x**
- **Node.js 20+** (assets are built in CI, but needed for manual builds)
- **Supervisor Manager** — runs Reverb and the queue worker

### PHP settings (aaPanel → PHP 8.4 → Settings)

The app accepts uploads up to **100 MB** (file manager) and 50 MB (flow
attachments), so the defaults are too small:

```ini
upload_max_filesize = 100M
post_max_size       = 110M
memory_limit        = 512M
max_execution_time  = 300      ; imports and PDF/Excel exports are synchronous
```

> ⚠️ The old `DEPLOY.md` suggested `client_max_body_size 15M`. That silently
> breaks file-manager and flow uploads with a 413. Use 110M — see §6.

---

## 3. Create the site

1. **Website → Add site** → domain `dms.deshfiri.com`, PHP **8.4**. Creates `/www/wwwroot/dms`.
2. **Site Directory → Run directory → `/public`.** Laravel's `public/` must be
   the web root. Serving the project root makes `.env` and all source
   downloadable.
3. **SSL** → issue Let's Encrypt cert → enable **Force HTTPS**.
4. **Databases → Add database** → `dfcp`, note the user and password.

```sql
CREATE DATABASE dfcp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

---

## 4. First deploy (once, by hand)

```bash
ssh -p 9934 khalid@164.68.123.155
cd /www/wwwroot/dms

git clone https://github.com/deshfiri/df-cms.git .
composer install --no-dev --optimize-autoloader
npm install && npm run build
cp .env.example .env
php artisan key:generate
```

After this, CI takes over and you never run these again.

---

## 5. Configure `.env`

`.env` is **excluded from the deploy rsync** — CI never overwrites it. Edit it
by hand on the server.

```env
# ── Application ───────────────────────────────────────────────
APP_NAME=DFCP
APP_ENV=production
APP_DEBUG=false                     # never true in production
APP_URL=https://dms.deshfiri.com

# ── Database ──────────────────────────────────────────────────
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dfcp
DB_USERNAME=dfcp_user
DB_PASSWORD=<strong password>

# ── Drivers ───────────────────────────────────────────────────
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local

# ── Logging ───────────────────────────────────────────────────
LOG_CHANNEL=stack
LOG_STACK=daily                     # 'single' grows unbounded with no rotation
LOG_LEVEL=warning                   # 'debug' fills the disk on a busy site

# ── Mail (required — see §7) ──────────────────────────────────
MAIL_MAILER=smtp
MAIL_HOST=<smtp host>
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=<...>
MAIL_PASSWORD=<...>
MAIL_FROM_ADDRESS=noreply@deshfiri.com
MAIL_FROM_NAME="DFCP"

# ── Realtime / Reverb (required — see §8) ─────────────────────
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<generated>
REVERB_APP_SECRET=<generated>
REVERB_APP_KEY=<generated>

REVERB_HOST=dms.deshfiri.com        # what BROWSERS dial
REVERB_PORT=443
REVERB_SCHEME=https

REVERB_SERVER_HOST=127.0.0.1        # what the DAEMON binds to
REVERB_SERVER_PORT=8080

# ── Google Calendar / Meet (optional) ─────────────────────────
GOOGLE_CALENDAR_CREDENTIALS_PATH=
GOOGLE_CALENDAR_ID=primary
GOOGLE_CALENDAR_IMPERSONATE_EMAIL=
```

Meetings work fine with the Google vars blank — they just won't create Meet links.

### Generating Reverb credentials

`.env.example` ships them blank. Generate once:

```bash
php -r "echo 'REVERB_APP_ID='.random_int(100000,999999).PHP_EOL
        .'REVERB_APP_KEY='.bin2hex(random_bytes(16)).PHP_EOL
        .'REVERB_APP_SECRET='.bin2hex(random_bytes(16)).PHP_EOL;"
```

---

## 6. Nginx

aaPanel's PHP template includes the Laravel rewrite, but confirm it and **add
the Reverb proxy** (Website → dms.deshfiri.com → Config):

```nginx
client_max_body_size 110M;          # must exceed the 100 MB file-manager limit

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/tmp/php-cgi-84.sock;   # match your PHP version
    fastcgi_index index.php;
    include fastcgi.conf;
    fastcgi_read_timeout 300;
}

# Reverb — /app is the websocket, /apps is the HTTP API Laravel pushes through.
# Both are required; proxying only /app makes server→client events fail silently.
# Place this ABOVE the \.php$ block. The (/|$) guard keeps it from swallowing
# an application route that merely starts with "app".
location ~ ^/(app|apps)(/|$) {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Upgrade           $http_upgrade;
    proxy_set_header Connection        "Upgrade";
    # Long-lived sockets. Must exceed Reverb's 60s ping interval, or nginx
    # drops idle connections and clients reconnect in a loop.
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
    proxy_buffering    off;
}

location ~ /\.(?!well-known).* { deny all; }
```

---

## 7. Mail

Client-facing notifications route to the `mail` channel whenever the recipient
is a client rather than a staff user — meeting scheduled / rescheduled /
cancelled / reminders (see
[SendMeetingReminders.php:42](app/Console/Commands/SendMeetingReminders.php#L42)).
Staff notifications use the `database` channel and appear in the bell.

With the default `MAIL_MAILER=log`, every client email silently goes to a log
file instead. **Set real SMTP.**

> **Note:** no notification in this app implements `ShouldQueue`, so mail is
> sent **inline** during the request or cron run. A slow or unreachable SMTP
> host will stall whatever triggered it. If that becomes a problem, the fix is
> adding `implements ShouldQueue` to the mail-channel notifications — which is
> also what would finally give the queue worker something to do.

---

## 8. Reverb (realtime) — Supervisor daemon

Powers staff chat, presence ("online" dots), and live notification-bell pushes.
`MessageSent` uses `ShouldBroadcastNow`, so delivery happens inside the request
and does **not** depend on the queue worker — but it does require the Reverb
daemon to be up and reachable.

aaPanel → **Supervisor Manager → Add Daemon**:

| Field | Value |
|-------|-------|
| Name | `dfcp-reverb` |
| Run directory | `/www/wwwroot/dms` |
| Start command | `php artisan reverb:start` |
| Processes | 1 |
| User | `www` |

> **No asset rebuild is needed for realtime.** pusher-js and laravel-echo load
> from CDN in `layouts/app.blade.php`, and the Echo options are rendered
> server-side from `config('broadcasting.connections.reverb.*')`. Changing a
> `REVERB_*` value takes effect after `php artisan config:cache` — `npm run
> build` has nothing to do with it. The `VITE_REVERB_*` entries in
> `.env.example` are vestigial and unused.
>
> If the key or host is empty, the layout skips Echo entirely and logs a
> console warning naming the missing variables, rather than letting pusher-js
> fall back to its cloud default.

### ⚠️ Do not set `REVERB_PUSH_HOST` on this server

[config/broadcasting.php:39-42](config/broadcasting.php#L39-L42) describes
`REVERB_PUSH_*` as a server-side loopback override, but
[layouts/app.blade.php:2474](resources/views/layouts/app.blade.php#L2474) feeds
the same `options.host` value to the **browser's** Echo config. Setting
`REVERB_PUSH_HOST=127.0.0.1` would tell every client to open a websocket to
its own machine, breaking realtime for everyone. Leave all `REVERB_PUSH_*`
vars unset.

The consequence: server→Reverb pushes travel out to
`https://dms.deshfiri.com/apps/...` and back in through nginx. The box must be
able to resolve and reach its own domain over HTTPS. If a firewall or split
DNS blocks that, chat breaks with no error in the UI.

---

## 8b. Audio calls — WebRTC + Coturn

### Architecture

```
Caller browser ──── signalling (Reverb/WSS) ────► Laravel ──► Reverb ──► Callee browser
       │                                                                      │
       └──────────────── audio: WebRTC, peer-to-peer (TURN if NAT) ───────────┘
```

Reverb carries **only** signalling — ringing, accept/reject/busy, SDP offer and
answer, ICE candidates, hang-up. Audio never passes through Laravel, Reverb,
the queue or the database. SDP and ICE blobs are relayed verbatim and never
stored; only call *metadata* is persisted, in the `calls` table.

Every signalling event is `ShouldBroadcastNow`. A queued broadcast would put
ICE candidates behind whatever the worker is doing, and candidates that arrive
after negotiation has moved on are useless. Each event targets exactly one
private channel, `App.Models.User.{id}`.

### `.env` additions

```env
# STUN is enough when one side is directly reachable. It is NOT enough in
# production — symmetric NAT and many mobile carriers require a relay.
WEBRTC_STUN_URL=stun:stun.l.google.com:19302

WEBRTC_TURN_URLS="turn:turn.dms.deshfiri.com:3478?transport=udp,turn:turn.dms.deshfiri.com:3478?transport=tcp,turns:turn.dms.deshfiri.com:5349?transport=tcp"

# coturn shared-secret mode. The server mints a short-lived credential per
# call; the browser never receives this value.
WEBRTC_TURN_SECRET=<long random string, must match turnserver.conf>
WEBRTC_TURN_TTL=600

WEBRTC_RING_TIMEOUT=30
WEBRTC_MAX_CALL_SECONDS=14400
WEBRTC_FORCE_RELAY=false
```

Generate the secret with `openssl rand -hex 32`. Never commit it.

### Coturn on the VPS

**1. DNS** — an A record for `turn.dms.deshfiri.com` pointing at the same IPv4
as `dms.deshfiri.com` (add AAAA too if the box has IPv6).

**2. Install**

```bash
sudo apt update && sudo apt install -y coturn
sudo sed -i 's/^#TURNSERVER_ENABLED/TURNSERVER_ENABLED/' /etc/default/coturn
```

**3. `/etc/turnserver.conf`**

```ini
listening-port=3478
tls-listening-port=5349

# Both must be set. On a VPS the NIC holds the private address and coturn must
# advertise the public one, or every relay candidate is unusable.
listening-ip=0.0.0.0
external-ip=164.68.123.155

realm=turn.dms.deshfiri.com
server-name=turn.dms.deshfiri.com

# Time-limited credentials derived from a shared secret. No user database, and
# nothing reusable ever reaches the browser.
use-auth-secret
static-auth-secret=<same value as WEBRTC_TURN_SECRET>

# Keep the relay range tight; every port here must be open in the firewall.
min-port=49160
max-port=49200

# TLS for turns:. Reuse the existing Let's Encrypt certificate.
cert=/etc/letsencrypt/live/dms.deshfiri.com/fullchain.pem
pkey=/etc/letsencrypt/live/dms.deshfiri.com/privkey.pem

fingerprint
no-multicast-peers
no-cli
no-tlsv1
no-tlsv1_1

# Do not let the relay be used to reach internal services.
denied-peer-ip=10.0.0.0-10.255.255.255
denied-peer-ip=172.16.0.0-172.31.255.255
denied-peer-ip=192.168.0.0-192.168.255.255
denied-peer-ip=127.0.0.0-127.255.255.255

user-quota=12
total-quota=1200
log-file=/var/log/turnserver.log
```

If certbot renews into a path coturn cannot read, grant the `turnserver` user
read access to `/etc/letsencrypt/live` and `/archive`, or copy the cert in a
renewal hook.

**4. Start**

```bash
sudo systemctl enable coturn
sudo systemctl restart coturn
sudo systemctl status coturn --no-pager
```

**5. Firewall — only what is needed**

| Port | Proto | Why |
|------|-------|-----|
| 3478 | UDP | STUN/TURN. The normal path |
| 3478 | TCP | Fallback where UDP is blocked |
| 5349 | TCP | TURN over TLS (`turns:`) — gets through firewalls that allow only 443-style TLS |
| 49160–49200 | UDP | Relay range. Must match `min-port`/`max-port` |

```bash
sudo ufw allow 3478/udp
sudo ufw allow 3478/tcp
sudo ufw allow 5349/tcp
sudo ufw allow 49160:49200/udp
```

Do **not** open the relay range on TCP, and do not open 5349/UDP — neither is
used by this configuration.

**UDP vs TCP:** WebRTC wants UDP; audio tolerates loss far better than the
head-of-line blocking TCP imposes. TCP and TLS exist only as fallbacks for
networks that block UDP outright. If calls only work over `turns:`, UDP is
being filtered somewhere upstream.

**6. Health checks**

```bash
sudo ss -lunp | grep 3478          # coturn listening on UDP
sudo tail -f /var/log/turnserver.log
```

Then use <https://icetest.info> or Trickle ICE, entering
`turn:turn.dms.deshfiri.com:3478` with a username/credential pair taken from
`GET /calls/ice` while logged in. A `relay` candidate proves TURN works.

### Credential security

`use-auth-secret` means the app mints `username = <expiry>:<user id>` and
`credential = base64(HMAC-SHA1(secret, username))`, valid for
`WEBRTC_TURN_TTL` seconds. A leaked pair expires by itself and the shared
secret never leaves the server.

The static `WEBRTC_TURN_USERNAME`/`WEBRTC_TURN_CREDENTIAL` fallback exists but
hands every browser a permanent credential — anyone who opens DevTools can
reuse your relay indefinitely. Use it only for a short-lived test.

### Verifying a real call

Open `chrome://webrtc-internals` on both sides during a call and check:

- **selected candidate pair** — `host` (same LAN), `srflx` (STUN traversal) or
  `relay` (TURN)
- `packetsSent` / `packetsReceived` rising on both sides
- `connectionState: connected`
- jitter and packet loss

One-way audio almost always means one side produced no usable candidate.

To prove the relay path specifically, set `WEBRTC_FORCE_RELAY=true`,
`php artisan config:cache`, and make a call — the pair must be `relay/relay`.
**Turn it back off afterwards**: it routes all audio through your VPS and adds
latency.

### Runbook

| Symptom | Likely cause |
|---|---|
| Calls not ringing at all | Reverb down, or the callee's tab never subscribed — check `php artisan reverb:verify` and the browser console |
| WebSocket connected but no call signal | Listener not bound; confirm `window.DfcpCall` exists and the page loaded `calls/panel` |
| Microphone denied | Browser permission; also requires HTTPS — WebRTC will not grant a mic on plain HTTP |
| ICE stuck on `checking` | No reachable candidate pair — TURN missing or the relay range is firewalled |
| TURN authentication failed | `static-auth-secret` and `WEBRTC_TURN_SECRET` differ, or the credential TTL expired before use |
| Works on Wi-Fi, fails on mobile | Classic symptom of STUN-only. Carrier NAT needs TURN |
| One-way audio | One side has no usable candidate, or a firewall allows outbound but not inbound UDP |
| Connects but silence | Check the autoplay unblock prompt, the OS output device, and that the caller is not muted |
| "User is busy" unexpectedly | A stale `ringing` row — `calls:reconcile` runs every minute and settles these |

`calls:reconcile` must be running (it is registered in `routes/console.php` and
depends on the scheduler cron in §10). Without it, an abandoned ringing call
blocks both participants from placing another.

---

## 9. Queue worker — Supervisor daemon

aaPanel → **Supervisor Manager → Add Daemon**:

| Field | Value |
|-------|-------|
| Name | `dfcp-queue` |
| Run directory | `/www/wwwroot/dms` |
| Start command | `php artisan queue:work --sleep=3 --tries=3 --max-time=3600` |
| Processes | 1 |
| User | `www` |

> **Reality check:** there is currently no `app/Jobs/` directory, nothing
> implements `ShouldQueue`, and `MessageSent` deliberately uses
> `ShouldBroadcastNow`. **Nothing enqueues work today.** Keep the worker running
> anyway — it costs almost nothing, CI already restarts it, and it's ready the
> moment anything gets queued. Just don't expect it to be what makes
> notifications or chat work.

---

## 10. Cron — the scheduler

One entry drives all five scheduled commands in
[routes/console.php](routes/console.php):

```bash
* * * * * cd /www/wwwroot/dms && php artisan schedule:run >> /dev/null 2>&1
```

**Run it as `www`, not root.** A root-run scheduler re-creates
`storage/logs/laravel.log` as root-owned, after which php-fpm can no longer
write to it and the app starts throwing 500s on any logged error.

| Command | Schedule (UTC) | What it does |
|---------|----------------|--------------|
| `meetings:send-reminders` | every 5 min | Emails clients, notifies staff |
| `portal:mark-overdue-invoices` | daily 00:00 | Flags overdue portal invoices |
| `portal:send-deadline-reminders` | daily 00:00 | Client deadline notifications |
| `performance:snapshot --previous` | 1st of month 02:00 | Freezes last month's KPI scores |
| `flow:overdue-reminders` | daily 08:00 | Nudges owners of overdue workflow items |

> **Timezone:** `config/app.php:68` hardcodes `'timezone' => 'UTC'`, so
> "08:00" means 08:00 UTC, not local time. To shift them, edit that line to
> your zone (e.g. `'Asia/Dhaka'`) and re-run `php artisan config:cache`.

Verify with `php artisan schedule:list`.

---

## 11. Database, storage, permissions

```bash
cd /www/wwwroot/dms
php artisan migrate --force --seed     # roles, permissions, categories, admin user
php artisan storage:link
chown -R www:www /www/wwwroot/dms
chmod -R 775 storage bootstrap/cache
```

`www` is aaPanel's web user — confirm with `ps aux | grep nginx`.

**Change `admin@dfcp.com` / `password` immediately after the first login.**

### Where uploads live

All uploads go to **private** disks served through controllers (never directly
web-accessible):

| Disk | Path | Used by |
|------|------|---------|
| `local` | `storage/app/private` | Documents, portal approvals, payment proofs, corrections, support tickets, flow attachments |
| `file_manager` | `storage/app/file_manager` | File manager module |
| `public` | `storage/app/public` | Unused today (`storage:link` is harmless insurance) |

The **app logo is the exception** — `SettingController` writes it to
`public/uploads/logo/`, inside the deployed code tree. That matters for CI; see
§13.

---

## 12. Production caches — and the ritual after any `.env` change

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Once config is cached, **`.env` edits have no effect until you re-cache**. And
the Supervisor daemons hold the old config in memory:

```bash
# The full ritual after editing .env
php artisan config:cache
supervisorctl restart dfcp-queue dfcp-reverb
# then reload php-fpm from the aaPanel panel
```

Forgetting the daemon restart is the single most common "I changed it but
nothing happened" cause.

---

## 13. CI/CD

`git push origin main` triggers [`.github/workflows/ci-cd.yml`](.github/workflows/ci-cd.yml):

1. Runs the test suite (PHP 8.4, Node 24, SQLite).
2. Builds production Composer + npm assets.
3. `rsync --delete` to `/www/wwwroot/dms`, excluding `.git`, `.github`,
   `node_modules`, `tests`, `.env`, `storage`, `public/uploads`.
4. Over SSH: `migrate --force`, the four cache commands, `queue:restart`,
   `reverb:restart`, `storage:link`.

### Required GitHub secrets

`DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PORT`, `DEPLOY_SSH_KEY`, `DEPLOY_PATH`

### What the excludes protect

- **`.env`** — production config is never overwritten by a deploy.
- **`storage`** — every uploaded document, payment proof and attachment lives
  here and exists nowhere else. See §15.
- **`public/uploads`** — the app logo is written into the code tree by
  `SettingController`, not to a storage disk. Without this exclude,
  `rsync --delete` erases it on every push.

The `public/storage` symlink *is* deleted by `--delete`, but the `storage:link`
step recreates it immediately, so that one is self-healing.

Both `queue:restart` and `reverb:restart` work by setting a cache flag that the
running daemons poll, so they succeed whether or not the daemons are up — and
the daemons pick up new code without touching Supervisor.

---

## 14. Verify the deployment

```bash
curl -I https://dms.deshfiri.com          # 200
php artisan about                         # env, drivers, cached config
php artisan schedule:list                 # 5 commands, sane next-run times
supervisorctl status                      # dfcp-queue + dfcp-reverb RUNNING
php artisan reverb:verify                 # realtime, layer by layer
```

`reverb:verify` checks the whole realtime chain and names the layer that broke:
config agreement → daemon listening → direct WebSocket handshake → the same
handshake through nginx/TLS → the server-side `/apps` push leg. It expects
`101 Switching Protocols` on both handshakes and exits non-zero on failure, so
CI gates on it after every deploy. Add `--skip-public` to test only the daemon.

Then, in the browser:

- [ ] Log in at `/login` as staff (`web` guard) — dashboard charts render
- [ ] Log in at the portal (`client_portal` guard) as a client account
- [ ] Open **Chat** in two browsers → message appears instantly, presence dot green
      *(if not: Reverb — check the browser console for `Realtime (Reverb/Echo) unavailable`)*
- [ ] Upload a 30 MB file in File Manager → succeeds (not a 413)
- [ ] Trigger a client-facing meeting → confirm the email actually arrives
- [ ] `php artisan flow:overdue-reminders` by hand → bell notification appears
- [ ] Change the default admin password

---

## 15. Backups

Nightly cron as `www`:

```bash
0 2 * * * mysqldump -u dfcp_user -p'<pass>' dfcp | gzip > /www/backup/dfcp_$(date +\%F).sql.gz
30 2 * * * tar -czf /www/backup/dfcp_storage_$(date +\%F).tar.gz -C /www/wwwroot/dms storage/app
0 4 * * * find /www/backup -name 'dfcp_*' -mtime +14 -delete
```

`storage/app` is the important one — it holds every uploaded document, payment
proof, and attachment, and it is **excluded from git and from the deploy
rsync**, so nothing else has a copy. Also back up `.env` (it holds
`APP_KEY`; lose it and encrypted values are unrecoverable) and
`public/uploads/` for the logo.

Test a restore at least once. An untested backup isn't a backup.

---

## 16. Logs

| Log | Path |
|-----|------|
| Laravel | `storage/logs/laravel-YYYY-MM-DD.log` (with `LOG_STACK=daily`) |
| Queue worker | Supervisor Manager → `dfcp-queue` |
| Reverb | Supervisor Manager → `dfcp-reverb` |
| nginx | `/www/wwwlogs/dms.deshfiri.com.log` and `.error.log` |

Prune Laravel logs: `find storage/logs -name '*.log' -mtime +30 -delete`

---

## 17. Security checklist

- [ ] `APP_DEBUG=false` and `APP_ENV=production`
- [ ] Run directory is `/public` — `curl https://dms.deshfiri.com/.env` returns 404
- [ ] Default admin password changed; unused seeded accounts removed
- [ ] Force HTTPS on, certificate auto-renew enabled
- [ ] MySQL bound to `127.0.0.1`, not exposed publicly
- [ ] SSH on the non-default port with key-only auth
- [ ] `DEPLOY_SSH_KEY` is a deploy-specific key, not a personal one
- [ ] `storage/` and `bootstrap/cache` are `775 www:www`; nothing is `777`

---

## 18. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Chat sends but nothing appears for the other user | Reverb down, or nginx missing the `/apps` proxy | `supervisorctl status dfcp-reverb`; verify §6 covers **both** `/app` and `/apps` |
| Browser console: `Realtime (Reverb/Echo) unavailable` | Wrong `REVERB_HOST`/`REVERB_PORT`, or `REVERB_PUSH_HOST` is set | §8 — clients need the public domain + 443 |
| Realtime worked, broke after deploy | Reverb serving stale code | `php artisan reverb:restart` (CI does this automatically) |
| No reminder emails ever | Cron missing, or `MAIL_MAILER=log` | §10 and §7 |
| Reminders fire at the wrong hour | Schedule times are UTC | §10 timezone note |
| 500s after a cron run | Root-owned `laravel.log` | `chown -R www:www storage`; run cron as `www` |
| `.env` change had no effect | Config cached / daemons stale | The ritual in §12 |
| 413 on upload | `client_max_body_size` too low | §6 — 110M |
| 504 on import or PDF export | Synchronous work exceeding the timeout | Raise `max_execution_time` and `fastcgi_read_timeout` |
| Logo disappeared after a deploy | `public/uploads` missing from the rsync excludes | Re-upload; confirm the exclude in §13 is still present |
| Deploy failed | Check the repo's **Actions** tab | The log names the failing step (auth, rsync, or artisan) |

---

## 19. Ops quick reference

```bash
cd /www/wwwroot/dms

# Restart everything
php artisan config:cache && php artisan queue:restart && php artisan reverb:restart
supervisorctl restart dfcp-queue dfcp-reverb

# Clear all caches (debugging)
php artisan optimize:clear

# Re-cache for production
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache

# Run a scheduled command by hand
php artisan meetings:send-reminders
php artisan flow:overdue-reminders
php artisan performance:snapshot --previous

# Watch what's happening
tail -f storage/logs/laravel-$(date +%F).log

# Emergency maintenance mode
php artisan down --secret="letmein"    # bypass at /letmein
php artisan up
```
