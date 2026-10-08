# BIGKAS-AI — VPS Deployment Runbook (Ubuntu 24.04, IP-first)

Validated against the repo. Corrections folded in from review:
php.ini upload limits, storage backup, committed requirements.txt,
Whisper pre-download, 4 GB guidance with local Whisper.

## Phase 0 — What you need

- VPS with **2 GB RAM minimum** (4 GB if running local Whisper — see Phase 5),
  Ubuntu 24.04, SSH access. Prefer **Singapore/Tokyo region** for PH latency.
- Brevo SMTP key. No domain required to launch (IP-first); HTTPS comes later.

## Phase 1 — Server provisioning (SSH as root)

```bash
apt update && apt upgrade -y
apt install -y nginx mysql-server python3-venv python3-pip ffmpeg \
  php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-bcmath \
  php8.3-curl php8.3-zip php8.3-gd unzip git ufw
```

(`ffmpeg` is required by faster-whisper's `av` dependency. Ubuntu 24.04
ships PHP 8.3, which satisfies Laravel 12's `^8.2` requirement.)

```bash
# Composer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php

# Node 20 (Vite frontend build)
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs

# Firewall
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

# MySQL: answer YES to all prompts, set a root password
mysql_secure_installation

# App database + user (invent a strong DB_PASSWORD, save it)
mysql -u root -p -e "CREATE DATABASE bigkas_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'bigkas'@'localhost' IDENTIFIED BY '<DB_PASSWORD>'; GRANT ALL ON bigkas_prod.* TO 'bigkas'@'localhost'; FLUSH PRIVILEGES;"
```

## Phase 2 — Deploy the app

```bash
cd /var/www
git clone https://github.com/clyd-dev/BIGKAS-AI.git bigkas
cd bigkas
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

Edit `.env` (`nano .env`) — production values:

```
APP_NAME="BIGKAS-AI"
APP_ENV=local            # NOT production yet (no HTTPS) — see Phase 4
APP_DEBUG=false          # MUST be false on the internet
APP_URL=http://<SERVER-IP>

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=bigkas_prod
DB_USERNAME=bigkas
DB_PASSWORD=<DB_PASSWORD>

SESSION_DRIVER=database
SESSION_ENCRYPT=false
# Do NOT set SESSION_SECURE_COOKIE. Unset, the cookie is Secure on HTTPS and
# plain over HTTP, so this file needs no edit when the certificate arrives.
# Setting it true over plain HTTP makes the browser throw the session cookie
# away, and every login/logout dies with "419 Page Expired".

MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=<brevo login email>
MAIL_PASSWORD=<brevo SMTP key>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=<verified Brevo sender>

ML_API_ENABLED=false     # Flask comes in Phase 5; rule-based fallback covers you
```

Then:

```bash
php artisan key:generate   # ALWAYS fresh per server — never copy a local APP_KEY
php artisan migrate --force
# Seed CONTENT only — never the full DatabaseSeeder (it creates test users):
php artisan db:seed --class=SystemSettingSeeder
php artisan db:seed --class=BadgeSeeder
php artisan db:seed --class=InterventionSeeder
php artisan db:seed --class=PracticeItemSeeder

# Create YOUR admin account (registration can't make admins):
php artisan tinker --execute='$u = \App\Models\User::create(["name" => "Admin", "email" => "you@real-email.com", "password" => "ChooseAStrongPass1"]); $u->role = "admin"; $u->is_active = true; $u->email_verified_at = now(); $u->save(); echo "admin id: {$u->id}\n";'

# Frontend build + storage + permissions + caches
npm ci && npm run build
php artisan storage:link   # makes storage/app/public reachable (audio 404s without it)
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache   # without this: silent upload failures + 500s
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Phase 3 — Nginx + PHP upload limits (BOTH places)

PHP rejects uploads over 2 MB / 8 MB by default — Nginx alone is not
enough. Set both:

```ini
# /etc/php/8.3/fpm/php.ini
upload_max_filesize = 20M
post_max_size = 25M
# Transcription runs synchronously inside the web request and is slower
# than real time on a small VPS. These must outlast WHISPER_TIMEOUT (.env,
# 300s) so Laravel's own limit fires first and the teacher gets a real
# message instead of a 504. Budget: 300 (app) < 360 (php) < 390 (nginx).
max_execution_time = 360
```

```ini
# /etc/php/8.3/fpm/pool.d/www.conf (add at the end)
request_terminate_timeout = 360s
```

```bash
systemctl restart php8.3-fpm
```

`/etc/nginx/sites-available/bigkas`:

```nginx
server {
    listen 80;
    server_name <SERVER-IP>;
    root /var/www/bigkas/public;
    index index.php;

    client_max_body_size 20M;  # audio uploads (UPLOAD_MAX_SIZE is 10MB)

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        # Outermost limit in the chain (see max_execution_time above)
        fastcgi_read_timeout 390s;
    }

    location ~ /\.ht { deny all; }
}
```

```bash
ln -s /etc/nginx/sites-available/bigkas /etc/nginx/sites-enabled/
rm /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

Open `http://<SERVER-IP>` — landing page should load. Test: register
(Brevo code email) → verify → login → admin settings save.

## Phase 4 — HTTPS later (when you have a domain)

Point the domain's A-record at the server IP, then:

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d your-domain.com   # accept the HTTP→HTTPS redirect when offered
```

If certbot doesn't add it, redirect manually so the app isn't served on
both (mixed serving confuses session cookies):

```nginx
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$host$request_uri;
}
```

Then flip two lines in `.env` and rebuild caches:

```
APP_ENV=production
APP_URL=https://your-domain.com
```

Session cookies become Secure on their own once requests arrive over HTTPS.
Pin `SESSION_SECURE_COOKIE=true` only if HTTP is fully closed off — it blocks
any remaining plain-HTTP access from holding a session at all.

```bash
php artisan config:cache
```

This activates the HTTPS redirect, secure cookies, and full production
posture.

## Phase 5 — ML sidecar (optional, anytime)

The app runs on rule-based fallback without this — enable only when ready.
`ml-service/requirements.txt` is committed in the repo (top-level deps;
never freeze a Windows venv into it).

```bash
cd /var/www/bigkas/ml-service
python3 -m venv venv
./venv/bin/pip install -r requirements.txt
# Pre-download Whisper weights NOW, not at first inference (~500MB,
# spikes RAM — on 2GB servers do this with nothing else running):
./venv/bin/python -c "from faster_whisper import WhisperModel; WhisperModel('small', device='cpu', compute_type='int8')"
```

`/etc/systemd/system/bigkas-ml.service` (persists across reboots/SSH drops):

```ini
[Unit]
Description=BIGKAS-AI ML microservice
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/bigkas/ml-service
ExecStart=/var/www/bigkas/ml-service/venv/bin/gunicorn --workers 1 --threads 2 --timeout 420 --bind 127.0.0.1:5000 app:app
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload && systemctl enable --now bigkas-ml
# then in .env: ML_API_ENABLED=true, ML_API_URL=http://127.0.0.1:5000
#               WHISPER_TIMEOUT=300, STT_ALLOW_MOCK=false
php artisan config:cache
```

Model files: `ml-service/bigkas_rf_model.pkl` ships with git and Flask
loads it automatically as its fallback model. (The newer
`weakness_classifier.joblib` + `feature_scaler.joblib` are NOT in the
repo — if you want them served, copy both files into `ml-service/` on
the server before starting the service; otherwise the `.pkl` serves.)
Verify with: `curl -s http://127.0.0.1:5000/api/health`.

RAM guidance: 2 GB is fine with `ML_API_ENABLED=false`; run local
Whisper on 4 GB, or keep 2 GB with the OpenAI fallback
(`WHISPER_USE_LOCAL=false`).

### No speech engine = no assessment

If no engine transcribes the audio, the app refuses to score the
recording rather than fall back to its placeholder transcript ("I have a
dog his name is Max..."). Analyze returns 503 with a message telling the
teacher the recording was saved and to try again; the real cause is in
`storage/logs/laravel.log`. Scoring the placeholder would store an
assessment, a reading level and an intervention plan that describe
nothing, so this is deliberate.

`STT_ALLOW_MOCK=true` re-enables the placeholder. Use it only for a demo
with no speech engine, never on a server collecting real assessments.
Results already stored from the placeholder stay flagged as "not a
measurement of the learner" on the results page.

### Transcription timeout budget

Transcription is synchronous, slower than real time on a small VPS, and
the recorder allows up to 3 minutes of audio. Each limit must outlast the
one inside it, so the app's own limit fires first and the teacher gets a
real message instead of a dead gateway:

| Limit | Where | Value |
|---|---|---|
| `WHISPER_TIMEOUT` | `.env` (Laravel waits on Flask) | 300s |
| `max_execution_time`, `request_terminate_timeout` | php-fpm | 360s |
| `fastcgi_read_timeout` | nginx | 390s |
| `--timeout` | gunicorn (`bigkas-ml.service`) | 420s |

Gunicorn is highest on purpose: Laravel gives up first, so the worker is
never killed mid-transcription (which would drop the loaded model and
make the next assessment slow).

No queue worker is needed: all notifications
(`NewInterventionAssigned`, `NewAssessmentCompleted`,
`NewMessageReceived`) send synchronously via the `database` channel.

## Phase 6 — Ops

```bash
# Laravel scheduler heartbeat
(crontab -l 2>/dev/null; echo "* * * * * cd /var/www/bigkas && php artisan schedule:run >> /dev/null 2>&1") | crontab -

# Nightly backups: database AND uploaded audio (DB dump alone
# doesn't recover .wav files). Keep 7 days.
mkdir -p /root/backups
(crontab -l 2>/dev/null; echo '0 2 * * * mysqldump -u bigkas -p"<DB_PASSWORD>" bigkas_prod | gzip > /root/backups/bigkas_$(date +\%F).sql.gz && tar -czf /root/backups/storage_$(date +\%F).tar.gz /var/www/bigkas/storage/app/public/ && find /root/backups -mtime +7 -delete') | crontab -

# Security updates
apt install -y unattended-upgrades && dpkg-reconfigure -plow unattended-upgrades
```

**Every future update:**

```bash
cd /var/www/bigkas && git pull origin master
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Troubleshooting — "419 Page Expired" and sessions that won't end

A 419 means the form's CSRF token didn't match the session behind it. Almost
always the session cookie never came back from the browser, so the POST landed
on a brand-new session. In order of likelihood:

1. **`SESSION_SECURE_COOKIE=true` while the site is served over HTTP.** The
   browser accepts the `Set-Cookie` and then discards it, silently. Comment the
   line out (unset = Secure only on HTTPS) and rebuild the config cache.
2. **A config cache built before the `.env` edit.** `php artisan config:cache`
   freezes `.env`; editing `.env` afterwards changes nothing until you run it
   again. When in doubt: `php artisan config:clear` and re-cache.
3. **`APP_KEY` changed.** Session payloads are encrypted, so a new key
   invalidates every live session at once. Never regenerate it on a running
   server.
4. **Reaching the app by two hostnames** (IP and domain, or with and without
   `www`). Cookies are per-host, so logging in on one and posting from the
   other cannot work. Pick one and 301 the rest.

One paste that shows which of those is live — run it on the server:

```bash
cd /var/www/bigkas-ai

# What the app actually believes (reads the cached config, like the site does)
php artisan tinker --execute='foreach (["app.url","app.env","session.driver","session.secure","session.encrypt","session.domain","session.lifetime","session.same_site"] as $k) printf("%-18s %s\n", $k, var_export(config($k), true));'

# What the browser is really told — look for "Secure" on a http:// URL
curl -sI http://localhost/login -H 'Host: <SERVER-IP>' | grep -i '^set-cookie\|^cache-control'

# Sessions are being written?
mysql -u bigkas -p bigkas_prod -e 'SELECT COUNT(*) AS rows_, FROM_UNIXTIME(MAX(last_activity)) AS newest FROM sessions;'

# Recent token failures
grep -ci 'TokenMismatch\|419' storage/logs/laravel.log
```

`session.secure` should print `NULL` on an HTTP-only server and the `set-cookie`
line must **not** contain `Secure`. If it does, that is your 419.

Sessions that come back after logout are a different fault and are fixed in the
app, not the server: every logout now clears the whole session (staff guard,
learner PIN key and CSRF token together), and every page is sent with
`Cache-Control: no-store` so the back button cannot resurrect a signed-in page.
If an old page still appears after deploying, hard-refresh once — the browser is
showing you a copy it stored before the fix landed.

## Pre-deploy checklist (local machine)

- [ ] `git push` finished cleanly — the server clones from GitHub
- [ ] No audio/video blobs in the push (`git status` shows no `audio_files/`)
- [ ] `.env` secrets (Brevo key, DB password) ready to paste on the server
