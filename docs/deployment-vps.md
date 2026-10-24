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
#               WHISPER_TIMEOUT=300
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
recording. There is no placeholder transcript in any environment (it used
to be allowed when `APP_ENV=local`, and scored as a flawless "independent"
reading). Analyze returns 503 with a message telling the teacher the
recording was saved and to try again; the real cause is in
`storage/logs/laravel.log`.

Results that an earlier version stored from the placeholder stay on record
but are treated as not measured: they show no numbers, cannot be accepted
and do not count towards a learner's level. After upgrading, run
`php artisan learners:recompute-levels` once so any level they set is
corrected.

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

The scheduler heartbeat below is what keeps the engine safe to lose. Every minute it runs
`health:check` (records when the speech engine or model stops, and alerts the admin if it
stays down for 2 minutes) and `assessments:rescore --limit=20` (scores recordings that were
saved during the outage, oldest first, up to 3 tries each — see Phase 7 for why the limit
and the overlap lock are the numbers they are). Set `HEALTH_ALERT_EMAIL` in `.env`
for the email; with it blank the outage still shows on the admin dashboard and in the log.
Run `php artisan migrate` after pulling, and check it by hand with `php artisan health:check`
and `php artisan assessments:rescore`.

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
cd /var/www/bigkas-ai && git pull origin master
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build        # only when resources/js, resources/css or package.json changed

# Rebuild ALL THREE caches, every time — not the subset the change seems to need
php artisan optimize:clear     # config + route + view + events
php artisan optimize           # rebuilds the same three
```

Never rebuild a subset. A pull that adds a route leaves the old
`bootstrap/cache/routes-v7.php` in place, and the first view calling
`route()` on the new name throws from `View->renderContents()` — a 500 that
reads like a Blade bug and sends you looking in the wrong file. Equally,
`.env` edits do nothing at all until `config:cache` runs again.

Reading a 500 afterwards: Laravel writes each entry as one very long line, so
`tail` drops you into the middle of a stack trace with the message out of
reach. Extract it by content instead:

```bash
grep -oE '(local|production)\.(ERROR|CRITICAL):.{0,500}' storage/logs/laravel.log | tail -2
```

## Phase 7 — Batch assessment (one teacher, a whole class)

Batch assessment lets one teacher set up a reading for up to 15 children at
once, watch a board of cards while the recordings come in, and walk away while
the engine works through them. It ships **dark**: nothing changes on a server
that never turns it on, and the single-child flow is untouched either way.

### The flag — `BATCH_ASSESSMENT_ENABLED`

Off by default. With it off, every batch route answers 404 and no batch control
appears anywhere.

```
# .env — omit the line entirely to keep the feature closed
BATCH_ASSESSMENT_ENABLED=true
```

The value is **cast, not taken raw** (`config/assessment.php` reads it through
`filter_var(..., FILTER_VALIDATE_BOOLEAN)`). So an unset key, an empty
`BATCH_ASSESSMENT_ENABLED=`, and the strings `false`, `off` and `0` all mean
off. Only a genuinely true value — `true`, `1`, `on`, `yes` — opens the
feature. There is no half-open state to get wrong.

**Setting it in `.env` does nothing on its own.** `config:cache` freezes
`.env` at the moment it ran, so the flag takes effect only after the config
cache is rebuilt:

```bash
php artisan optimize:clear && php artisan optimize
```

Which is to say: **all three caches, every time** — the standing rule in
"Every future update" above applies here too, and turning this flag on is
exactly the kind of change that tempts you to rebuild only `config`. Don't.
A pull that adds the batch routes leaves the old `bootstrap/cache/routes-v7.php`
in place, and the first view that calls `route()` on a new batch name throws
from inside a Blade template — a 500 that reads like a view bug and sends you
looking in the wrong file. This project has made that exact mistake.

### The scoring lane, and why its two numbers move together

One lane, serial, oldest recording first. `routes/console.php`:

```php
Schedule::command('assessments:rescore --limit=20')->everyMinute()->withoutOverlapping(120);
```

Three numbers, one invariant:

| Number | Value | Why |
|---|---|---|
| `--limit` | 20 | A full 15-child roster must clear in **one** run, with headroom |
| tick | every minute | The board's wait estimate is computed from this tick |
| `withoutOverlapping` | 120 minutes | Must outlast the worst-case run |

The invariant: **`20 × 3 × 1.5 = 90 ≤ 120`** — twenty recordings, three
minutes each at the recorder's maximum, times a 1.5 safety margin, must fit
inside the lock.

Raise `--limit` without raising the lock and the lock expires **mid-run**. The
next minute's tick then sees no lock, starts a second run, and both runs score
the same recordings — a child's reading scored twice, two results, and a
teacher looking at numbers that changed by themselves.

`tests/Feature/RescoreScheduleTest.php` is what fails if the two drift apart.
It is not documentation; it asserts the arithmetic. (Verified: setting
`--limit=60` against a 120-minute lock fails it with
"Failed asserting that 270.0 is equal to 120 or is less than 120".)

### `schedule:run` is a prerequisite, not an optimisation

Without the crontab line from Phase 6, **nothing is ever rescored**. Recordings
sit at `queued` on the board forever — no error, no failure, just a batch that
never finishes. Confirm it before turning the flag on:

```bash
crontab -l | grep schedule:run      # must be there
php artisan schedule:list           # assessments:rescore --limit=20, every minute
php artisan assessments:rescore     # by hand, once, to see it work
```

**The board now says so, which is how you will hear about it.** When readings
are waiting and nothing anywhere has been scored for longer than one tick plus
the lane's own staleness bound (`Assessment::STALE_PROCESSING_MINUTES`, so about
sixteen minutes), the board shows a warning: *"Nothing has been scored for N
minutes, although readings are waiting. The analysis scheduler may not be
running — ask whoever looks after the server to check it."*

It is worded as an observation, not a diagnosis, because the application cannot
see your crontab — only that nothing has moved. It carries no commands, because
a teacher has no terminal. When a teacher reports it, the two causes are the two
in this section: **no `schedule:run` line**, or **a killed run still holding the
overlap lock** (below — `php artisan schedule:clear-cache`). The warning clears
itself on the next poll once something is scored, so it is also the confirmation
that the fix worked.

Locally there is no cron at all, which is where this first bit: `php artisan
schedule:work` in a second terminal, for the whole session.

### One lane, first in first out — what a teacher will think is broken

A 15-child batch holds the single scoring lane for **up to an hour**. Another
teacher's ordinary one-off assessment, created in the middle of that batch,
**waits behind it**. That is fair — oldest recording first, nobody jumps the
queue — but it is slow, and a teacher who has not been told will be certain the
system is broken.

Tell staff this before they first use it. The board shows an honest "about N
minutes" estimate that already includes the work queued ahead, so the number on
screen is the real answer; it is only surprising if nobody explained that there
is one lane.

### When a scheduled run is killed — `php artisan schedule:clear-cache`

`withoutOverlapping(120)` releases its lock on normal exit. It does **not**
release on a SIGKILL — an OOM kill, a reboot, a `systemctl stop` or a container
stop in the middle of a run. The lock then stays held for up to two hours, and
until it expires **nothing is rescored at all** — not the batch, and not
outage recovery for ordinary single-child assessments either.

**The symptom, as it looks at 7am:**

- recordings sitting at `queued`, not moving
- the speech engine demonstrably healthy — `curl -s http://127.0.0.1:5000/api/health` answers
- **no `Rescored assessment` lines in the log** since the kill

```bash
grep -c 'Rescored assessment' storage/logs/laravel.log     # stops increasing
```

Nothing is lost — the queue *is* the database, so there is no queue to drain —
but nothing moves either. The unstick command:

```bash
php artisan schedule:clear-cache
```

Then wait one minute for the next tick, and confirm the `Rescored assessment`
lines resume.

### Rolling back

- Tag **`pre-batch-assessment`** (pushed) is the state before any of this work.
- The work lives on branch **`feat/batch-assessment`**.
- The flag defaults **off**, so a deploy that includes the code but not the
  `.env` line is already rolled back in effect.
- Both migrations are **additive and reversible** —
  `create_assessment_batches_table` and `add_batch_columns_to_assessments_table`.
  Every added column is nullable (`NULL batch_id` means a single-child
  assessment, exactly as before) and `batch_id` is `nullOnDelete`, so deleting
  a batch never deletes a child's assessment or result.

Verified on real MySQL 8 (not only the suite's SQLite): `migrate` from empty →
`migrate:rollback --step` over both migrations → `migrate` again, all clean.
After the rollback the four added columns, the two indexes and the foreign key
were all gone from `assessments`, with no orphan index left behind.

```bash
php artisan migrate:rollback --step=2 --force   # removes exactly these two
```

### Known blocker — this branch cannot `route:cache` on a clean checkout

`routes/web.php` on this branch declares three committed routes:

```
GET  /users/import           → ParentImportController@form
POST /users/import/preview   → ParentImportController@preview
POST /users/import/confirm   → ParentImportController@confirm
```

`app/Http/Controllers/ParentImportController.php` and its two views
(`resources/views/admin/parents-import.blade.php`,
`resources/views/admin/parents-import-result.blade.php`) are **untracked** —
they were captured from a working tree, and the routes went in without them.
As observed on 2026-10-22, all three files are still untracked.

On a clean clone the controller class does not exist, so `php artisan
route:cache` fails and the deploy stops. **The fix is to commit those three
files**; there is nothing to do on the server. Check before deploying:

```bash
git ls-files --error-unmatch app/Http/Controllers/ParentImportController.php
```

### Before you believe the batch flow works

The automated suite cannot measure real transcription, and it cannot close a
browser. `docs/2026-10-22-batch-assessment-manual-checks.md` is the by-hand
checklist: the measured 15-child run, the close-the-browser proof, a pass over
the real screens, and a child signing in on their own device. Run it before
turning the flag on for a real class.

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
