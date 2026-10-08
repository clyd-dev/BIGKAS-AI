#!/usr/bin/env bash
# =============================================================================
# BIGKAS-AI  |  First-day VPS setup  |  Ubuntu 24.04 LTS (fresh server)
# Stack: Nginx + PHP-FPM + MySQL + Laravel queue worker + Flask ML (gunicorn)
#        + faster-whisper, HTTPS via Let's Encrypt on a DuckDNS subdomain
#
# HOW TO RUN (as root, on the Contabo server):
#   1. scp this file to the server, or paste it into nano
#   2. Edit the CONFIG block below
#   3. chmod +x bigkas_first_day_setup.sh && ./bigkas_first_day_setup.sh 2>&1 | tee setup.log
#
# ASSUMPTIONS (verify against your repo):
#   - Laravel app is at the repo root; the ML service is in ./ml-service
#   - ml-service/app.py exposes a Flask object named `app`
#   - ml-service/requirements.txt exists
#   - .env.example exists; seeder class set in SEEDER below
# =============================================================================
set -euo pipefail

# ----------------------------- CONFIG (EDIT ME) ------------------------------
DUCKDNS_SUBDOMAIN="bigkas-ai"                 # -> bigkas-ai.duckdns.org
DUCKDNS_TOKEN="41b298a6-9117-456b-b454-fb2bf554067a"
LETSENCRYPT_EMAIL="fredericknavarro14@gmail.com"
REPO_URL="https://github.com/clyd-dev/BIGKAS-AI.git"
# Private repo? Use: https://<USERNAME>:<PERSONAL_ACCESS_TOKEN>@github.com/USER/REPO.git
REPO_BRANCH="master"
APP_DIR="/var/www/bigkas-ai"
DB_NAME="bigkas_db"
DB_USER="bigkas_user"
SEEDERS="SystemSettingSeeder BadgeSeeder InterventionSeeder PracticeItemSeeder"  # content only; NEVER DatabaseSeeder (creates test users)
WHISPER_MODEL="small"                          # tiny | base | small | medium
OPENAI_API_KEY=""                              # optional fallback; leave empty to skip
SWAP_SIZE="2G"
# -----------------------------------------------------------------------------

DOMAIN="${DUCKDNS_SUBDOMAIN}.duckdns.org"
DB_PASS="$(openssl rand -hex 16)"
export DEBIAN_FRONTEND=noninteractive

log()  { echo -e "\n\033[1;32m==> $*\033[0m"; }
warn() { echo -e "\033[1;33m[!] $*\033[0m"; }
die()  { echo -e "\033[1;31m[x] $*\033[0m"; exit 1; }

[[ $EUID -eq 0 ]] || die "Run as root."
[[ "$DUCKDNS_TOKEN" != PASTE_* ]] || die "Set DUCKDNS_TOKEN in the CONFIG block."
[[ "$REPO_URL" != *YOUR_USER* ]] || die "Set REPO_URL in the CONFIG block."

# ----------------------------------------------------------------------------
log "1/12  System update, base tools, timezone"
apt-get update -y
apt-get upgrade -y
apt-get install -y software-properties-common curl wget git unzip zip ufw fail2ban \
  dnsutils ca-certificates gnupg ffmpeg build-essential cron
timedatectl set-timezone Asia/Manila

# ----------------------------------------------------------------------------
log "2/12  Swap file (${SWAP_SIZE}) - protects Whisper from out-of-memory kills"
if ! swapon --show | grep -q '/swapfile'; then
  fallocate -l "$SWAP_SIZE" /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  echo 'vm.swappiness=10' > /etc/sysctl.d/99-swappiness.conf
  sysctl -p /etc/sysctl.d/99-swappiness.conf
else
  warn "Swap already present, skipping."
fi

# ----------------------------------------------------------------------------
log "3/12  Firewall (SSH, HTTP, HTTPS only)"
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
systemctl enable --now fail2ban

# ----------------------------------------------------------------------------
log "4/12  Install Nginx, PHP, MySQL, Composer, Node 20, Python"
apt-get install -y nginx mysql-server \
  php-fpm php-cli php-mysql php-curl php-xml php-zip php-mbstring php-gd php-bcmath php-intl \
  python3 python3-venv python3-pip python3-dev composer certbot python3-certbot-nginx
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt-get install -y nodejs

PHP_V="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
echo "Detected PHP ${PHP_V}"
[[ "$(printf '%s\n8.2' "$PHP_V" | sort -V | head -n1)" == "8.2" ]] || die "PHP ${PHP_V} is too old for Laravel (need 8.2+)."

# ----------------------------------------------------------------------------
log "5/12  PHP upload limits (20MB audio) and MySQL database"
cat > "/etc/php/${PHP_V}/fpm/conf.d/99-bigkas.ini" <<EOF
upload_max_filesize = 20M
post_max_size = 25M
memory_limit = 256M
max_execution_time = 360
EOF
# Transcription runs synchronously inside the web request. The limits below must
# outlast WHISPER_TIMEOUT (.env, 300s) so Laravel's own limit fires first and the
# teacher gets a real message instead of a 504: 300 < 360 (php) < 390 (nginx).
cat > "/etc/php/${PHP_V}/fpm/pool.d/zz-bigkas.conf" <<EOF
[www]
request_terminate_timeout = 360s
EOF
systemctl restart "php${PHP_V}-fpm"

systemctl enable --now mysql
mysql <<EOF
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

# ----------------------------------------------------------------------------
log "6/12  DuckDNS: point ${DOMAIN} at this server"
mkdir -p /opt/duckdns
cat > /opt/duckdns/update.sh <<EOF
#!/usr/bin/env bash
curl -4 -s --max-time 20 "https://www.duckdns.org/update?domains=${DUCKDNS_SUBDOMAIN}&token=${DUCKDNS_TOKEN}&ip=" \
  -o /opt/duckdns/last.log || true
EOF
chmod 700 /opt/duckdns/update.sh
/opt/duckdns/update.sh
[[ "$(cat /opt/duckdns/last.log)" == "OK" ]] || die "DuckDNS update failed (check token/subdomain). Response: $(cat /opt/duckdns/last.log)"
{ { crontab -l 2>/dev/null || true; } | { grep -v duckdns || true; }; echo "*/5 * * * * /opt/duckdns/update.sh >/dev/null 2>&1"; } | crontab -

SERVER_IP="$(curl -4 -s --max-time 10 https://api.ipify.org || curl -4 -s --max-time 10 https://ifconfig.me || true)"
[[ -n "$SERVER_IP" ]] || { SERVER_IP="$(hostname -I | awk '{print $1}')"; warn "Could not detect public IP online; using ${SERVER_IP}"; }
echo "Server IP: ${SERVER_IP} - waiting for DNS to resolve..."
for i in {1..30}; do
  if [[ "$(dig +short "$DOMAIN" @1.1.1.1 | tail -n1 || true)" == "$SERVER_IP" ]]; then echo "DNS OK"; break; fi
  sleep 10
  if [[ $i -eq 30 ]]; then warn "DNS not resolving yet. Certbot (step 10) may fail; re-run it later."; fi
done

# ----------------------------------------------------------------------------
log "7/12  Clone app and install Laravel dependencies"
mkdir -p "$(dirname "$APP_DIR")"
if [[ -d "$APP_DIR/.git" ]]; then
  git -C "$APP_DIR" pull origin "$REPO_BRANCH"
else
  git clone -b "$REPO_BRANCH" "$REPO_URL" "$APP_DIR"
fi
cd "$APP_DIR"
git config --global --add safe.directory "$APP_DIR"

COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund 2>/dev/null || npm install --no-audit --no-fund
npm run build

[[ -f .env ]] || cp .env.example .env
set_env() {  # set_env KEY VALUE  -> replace or append in .env
  local k="$1" v="$2"
  if grep -q "^${k}=" .env; then sed -i "s|^${k}=.*|${k}=${v}|" .env; else echo "${k}=${v}" >> .env; fi
}
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "https://${DOMAIN}"
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "$DB_PASS"
set_env SESSION_SECURE_COOKIE true
set_env QUEUE_CONNECTION database
# ML / Whisper settings used by SpeechToTextService + MLClassificationService
set_env ML_API_URL "http://127.0.0.1:5000"
set_env ML_API_ENABLED true
set_env WHISPER_USE_LOCAL true
set_env APP_TIMEZONE Asia/Manila
[[ -n "$OPENAI_API_KEY" ]] && set_env OPENAI_API_KEY "$OPENAI_API_KEY"

grep -q "^APP_KEY=base64:" .env || php artisan key:generate --force   # keep key on re-runs
php artisan migrate --force
php artisan queue:table 2>/dev/null || true      # no-op if the migration already exists
php artisan migrate --force
for sd in $SEEDERS; do
  php artisan db:seed --class="$sd" --force
done
php artisan storage:link || true

chown -R www-data:www-data "$APP_DIR"
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

# ----------------------------------------------------------------------------
log "8/12  Nginx site (20MB uploads)"
cat > /etc/nginx/sites-available/bigkas-ai <<EOF
server {
    listen 80;
    server_name ${DOMAIN};
    root ${APP_DIR}/public;
    index index.php;

    client_max_body_size 20M;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PHP_V}-fpm.sock;
        fastcgi_read_timeout 390s;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
EOF
ln -sf /etc/nginx/sites-available/bigkas-ai /etc/nginx/sites-enabled/bigkas-ai
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

# ----------------------------------------------------------------------------
log "9/12  Flask ML service: venv, dependencies, Whisper model pre-download"
ML_DIR="${APP_DIR}/ml-service"
[[ -d "$ML_DIR" ]] || die "ml-service folder not found at ${ML_DIR}. Adjust ML_DIR in this script."
cd "$ML_DIR"
python3 -m venv venv
./venv/bin/pip install --upgrade pip wheel
[[ -f requirements.txt ]] && ./venv/bin/pip install -r requirements.txt
./venv/bin/pip install gunicorn
# Models were pickled with scikit-learn 1.9.0 (local); match it to avoid load errors
./venv/bin/pip install "scikit-learn==1.9.0"

mkdir -p "$ML_DIR/.cache"
chown -R www-data:www-data "$ML_DIR"
echo "Downloading Whisper '${WHISPER_MODEL}' (this can take several minutes)..."
sudo -u www-data env HF_HOME="$ML_DIR/.cache" \
  ./venv/bin/python -c "from faster_whisper import WhisperModel; WhisperModel('${WHISPER_MODEL}', device='cpu', compute_type='int8'); print('Whisper model ready')"

# If you fine-tuned a model, copy the CTranslate2 folder to ${ML_DIR}/bigkas-whisper-ct2
# and point app.py at it (WhisperModel("./bigkas-whisper-ct2", ...)).

cat > /etc/systemd/system/bigkas-ml.service <<EOF
[Unit]
Description=BIGKAS-AI ML Flask Service (RF classifier + Whisper)
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=${ML_DIR}
Environment=HF_HOME=${ML_DIR}/.cache
# 1 worker only: each worker loads its own copy of Whisper into RAM
# --timeout exceeds the app's WHISPER_TIMEOUT (300s) so Laravel gives up first
# and the worker is never killed mid-transcription (which would drop the model).
ExecStart=${ML_DIR}/venv/bin/gunicorn --workers 1 --threads 2 --timeout 420 --bind 127.0.0.1:5000 app:app
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

# ----------------------------------------------------------------------------
log "10/12  HTTPS (Let's Encrypt) - required for browser microphone access"
if certbot --nginx -d "$DOMAIN" -m "$LETSENCRYPT_EMAIL" --agree-tos --no-eff-email --redirect -n; then
  echo "HTTPS enabled: https://${DOMAIN}"
else
  warn "Certbot failed (usually DNS not propagated). Re-run later:"
  warn "  certbot --nginx -d ${DOMAIN} -m ${LETSENCRYPT_EMAIL} --agree-tos --redirect -n"
fi

# ----------------------------------------------------------------------------
log "11/12  Laravel queue worker + scheduler"
cat > /etc/systemd/system/bigkas-queue.service <<EOF
[Unit]
Description=BIGKAS-AI Laravel Queue Worker
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

echo "* * * * * www-data cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/bigkas-scheduler

systemctl daemon-reload
systemctl enable --now bigkas-ml bigkas-queue
sleep 3

# ----------------------------------------------------------------------------
log "12/12  Nightly backups (DB + uploaded audio), 7-day retention"
mkdir -p /backups
chmod 700 /backups
cat > /etc/bigkas-backup.cnf <<EOF
[client]
user=${DB_USER}
password=${DB_PASS}
EOF
chmod 600 /etc/bigkas-backup.cnf

cat > /usr/local/bin/bigkas-backup.sh <<EOF
#!/usr/bin/env bash
set -e
D=\$(date +%Y%m%d)
mysqldump --defaults-extra-file=/etc/bigkas-backup.cnf --single-transaction ${DB_NAME} | gzip > /backups/db_\${D}.sql.gz
tar -czf /backups/storage_\${D}.tar.gz -C ${APP_DIR}/storage/app public
find /backups -type f -mtime +7 -delete
EOF
chmod 700 /usr/local/bin/bigkas-backup.sh
echo "30 2 * * * root /usr/local/bin/bigkas-backup.sh" > /etc/cron.d/bigkas-backup

# Save credentials for you (root-only)
cat > /root/bigkas-credentials.txt <<EOF
URL:         https://${DOMAIN}
DB name:     ${DB_NAME}
DB user:     ${DB_USER}
DB password: ${DB_PASS}
App dir:     ${APP_DIR}
EOF
chmod 600 /root/bigkas-credentials.txt

# ----------------------------------------------------------------------------
log "Health check"
for s in nginx "php${PHP_V}-fpm" mysql bigkas-ml bigkas-queue; do
  printf "  %-16s %s\n" "$s" "$(systemctl is-active "$s")"
done
echo -n "  Flask ML health: "
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:5000/api/health || echo "unreachable"
echo -n "  Site HTTP code:  "
curl -s -o /dev/null -w "%{http_code}\n" "https://${DOMAIN}" || echo "unreachable"

cat <<EOF

=============================================================================
SETUP COMPLETE. Remaining MANUAL steps:
  1. Mail: edit ${APP_DIR}/.env  (MAIL_MAILER, MAIL_HOST, MAIL_USERNAME,
     MAIL_PASSWORD for Brevo), then: php artisan config:cache
  2. Create the admin (role is NOT mass-assignable, so set it separately):
     cd ${APP_DIR} && php artisan tinker --execute='\$u=\App\Models\User::create(["name"=>"Admin","email"=>"you@real-email.com","password"=>"StrongPass1"]); \$u->role="admin"; \$u->is_active=true; \$u->email_verified_at=now(); \$u->save();'
  3. RF model files ship with git (ml-service/). Check: curl -s http://127.0.0.1:5000/api/health
  4. Run ONE full assessment end to end and note the latency.
  5. Copy /backups off the server regularly (laptop or Google Drive).
  6. Credentials are in /root/bigkas-credentials.txt

Useful commands:
  journalctl -u bigkas-ml -f          # Flask / Whisper logs
  journalctl -u bigkas-queue -f       # queue worker logs
  tail -f ${APP_DIR}/storage/logs/laravel.log
  systemctl restart bigkas-ml bigkas-queue

Deploy updates later:
  cd ${APP_DIR} && git pull && composer install --no-dev -o && npm run build \\
    && php artisan migrate --force && php artisan config:cache \\
    && systemctl restart bigkas-queue bigkas-ml && chown -R www-data:www-data .
=============================================================================
EOF
