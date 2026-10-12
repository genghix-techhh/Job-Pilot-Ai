#!/usr/bin/env bash
# JobPilot AI — one-shot installer for a fresh Ubuntu 22.04/24.04 VPS (e.g. Hostinger).
#
# Run as root (or with sudo) on the server:
#
#   sudo DOMAIN=jobs.example.com LE_EMAIL=you@example.com bash deploy/install-vps.sh
#
# Variables (all optional):
#   DOMAIN      domain whose DNS A record already points at this server. Empty = plain HTTP on the server IP.
#   LE_EMAIL    e-mail for Let's Encrypt expiry notices (needed for HTTPS).
#   REPO        git URL to clone         (default: the project's GitHub repo)
#   BRANCH      branch                   (default: main)
#   APP_DIR     install directory        (default: /var/www/jobpilot)
#   PHP_VERSION 8.4 or 8.3               (default: 8.4)
#   TZ_NAME     display time zone        (default: Asia/Karachi)
#
# Re-running is safe: an existing .env, database and owner are kept; code is updated.
# It does NOT create the owner account (you type the password yourself) and never puts secrets on the command line.
set -euo pipefail

DOMAIN="${DOMAIN:-}"
LE_EMAIL="${LE_EMAIL:-}"
REPO="${REPO:-https://github.com/genghix-techhh/Job-Pilot-Ai.git}"
BRANCH="${BRANCH:-main}"
APP_DIR="${APP_DIR:-/var/www/jobpilot}"
PHP_VERSION="${PHP_VERSION:-8.4}"
TZ_NAME="${TZ_NAME:-Asia/Karachi}"
DB_NAME="jobpilot"
DB_USER="jobpilot"
CRED_FILE="/root/jobpilot-credentials.txt"

log() { printf '\n\033[1;33m==> %s\033[0m\n' "$*"; }
die() { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run as root: sudo bash deploy/install-vps.sh"
. /etc/os-release
case "${VERSION_ID:-}" in 22.04|24.04) ;; *) die "Ubuntu 22.04 or 24.04 expected (found ${PRETTY_NAME:-unknown})." ;; esac
export DEBIAN_FRONTEND=noninteractive

if [ -n "$DOMAIN" ]; then
  SERVER_IP="$(curl -fsS https://api.ipify.org || true)"
  RESOLVED="$(getent hosts "$DOMAIN" | awk '{print $1; exit}' || true)"
  if [ -n "$SERVER_IP" ] && [ -n "$RESOLVED" ] && [ "$SERVER_IP" != "$RESOLVED" ]; then
    echo "WARNING: $DOMAIN resolves to $RESOLVED but this server is $SERVER_IP — HTTPS issuance will fail until DNS is fixed."
  fi
fi

log "1/9  System packages"
apt-get update -y
apt-get install -y nginx mysql-server git unzip curl supervisor ufw certbot python3-certbot-nginx software-properties-common
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-curl" \
  "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-intl"

if ! command -v composer >/dev/null; then
  log "     Composer"
  EXPECTED="$(curl -fsS https://composer.github.io/installer.sig)"
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
  [ "$EXPECTED" = "$ACTUAL" ] || die "Composer installer checksum mismatch."
  php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
if ! command -v node >/dev/null || [ "$(node -p 'process.versions.node.split(".")[0]')" -lt 20 ]; then
  log "     Node.js 22 (only used to build the front end)"
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt-get install -y nodejs
fi

log "2/9  MySQL database"
systemctl enable --now mysql
if [ -f "$CRED_FILE" ] && grep -q '^DB_PASSWORD=' "$CRED_FILE"; then
  DB_PASS="$(grep '^DB_PASSWORD=' "$CRED_FILE" | cut -d= -f2-)"
else
  DB_PASS="$(openssl rand -hex 24)"
  umask 077
  printf 'DB_PASSWORD=%s\n' "$DB_PASS" > "$CRED_FILE"
fi
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

log "3/9  Code ($REPO @ $BRANCH)"
mkdir -p "$(dirname "$APP_DIR")"
git config --global --add safe.directory "$APP_DIR"   # the tree is owned by www-data after the first run
if [ -d "$APP_DIR/.git" ]; then
  git -C "$APP_DIR" fetch --quiet origin "$BRANCH"
  git -C "$APP_DIR" checkout --quiet "$BRANCH"
  git -C "$APP_DIR" reset --hard --quiet "origin/$BRANCH"
else
  [ -z "$(ls -A "$APP_DIR" 2>/dev/null)" ] || die "$APP_DIR exists and is not an empty git checkout."
  git clone --quiet --branch "$BRANCH" "$REPO" "$APP_DIR"
fi
cd "$APP_DIR"

log "4/9  PHP dependencies and front-end build"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund
npm run build

log "5/9  Environment file"
if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate --force --no-interaction
  FIRST_INSTALL=1
else
  FIRST_INSTALL=0
  echo "     .env already exists — keeping your values (only the database password is refreshed)."
fi
setenv() { # setenv KEY VALUE  (values here never contain sed-special characters)
  if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else printf '%s=%s\n' "$1" "$2" >> .env; fi
}
setenv DB_PASSWORD "$DB_PASS"
if [ "$FIRST_INSTALL" = 1 ]; then
  if [ -n "$DOMAIN" ]; then
    setenv APP_URL "https://${DOMAIN}"
    setenv SESSION_SECURE_COOKIE true
  else
    IP="$(curl -fsS https://api.ipify.org || hostname -I | awk '{print $1}')"
    setenv APP_URL "http://${IP}"
    setenv SESSION_SECURE_COOKIE false
  fi
  setenv APP_ENV production
  setenv APP_DEBUG false
  setenv JOBPILOT_TIMEZONE "$TZ_NAME"
fi
chmod 600 .env

log "6/9  Database migration and permissions"
php artisan migrate --force --no-interaction
chown -R www-data:www-data "$APP_DIR"
chmod -R ug+rwX storage bootstrap/cache
chmod 600 .env
sudo -u www-data php artisan storage:link --no-interaction >/dev/null 2>&1 || true
sudo -u www-data php artisan optimize --no-interaction

log "7/9  nginx"
SERVER_NAME="${DOMAIN:-_}"
cat > /etc/nginx/sites-available/jobpilot <<NGINX
server {
    listen 80;
    server_name ${SERVER_NAME};
    root ${APP_DIR}/public;
    index index.php;
    client_max_body_size 8m;

    add_header X-Content-Type-Options nosniff always;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
ln -sf /etc/nginx/sites-available/jobpilot /etc/nginx/sites-enabled/jobpilot
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now "php${PHP_VERSION}-fpm" nginx
systemctl reload nginx

log "8/9  Scheduler (cron) and firewall"
CRON_LINE="* * * * * cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1"
( crontab -u www-data -l 2>/dev/null | grep -vF "artisan schedule:run" || true; echo "$CRON_LINE" ) | crontab -u www-data -
ufw allow OpenSSH >/dev/null
ufw allow 'Nginx Full' >/dev/null
ufw --force enable >/dev/null

log "9/9  HTTPS"
if [ -n "$DOMAIN" ] && [ -n "$LE_EMAIL" ]; then
  certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$LE_EMAIL" --redirect \
    || echo "WARNING: certbot failed (check that the DNS A record points here), then re-run: certbot --nginx -d $DOMAIN"
elif [ -n "$DOMAIN" ]; then
  echo "Skipped: set LE_EMAIL=you@example.com and re-run, or run:  certbot --nginx -d $DOMAIN"
else
  echo "No DOMAIN given — serving plain HTTP on the server IP. Add a domain + HTTPS before using real data."
fi

OWNER_EXISTS="$(sudo -u www-data php artisan tinker --execute='echo \App\Models\User::count();' 2>/dev/null | tail -1 || echo 0)"
cat <<DONE

Done. Next steps:
  1. Create your login (you type the password; 12+ characters):
       cd ${APP_DIR} && sudo -u www-data php artisan app:create-owner
     (owner accounts found: ${OWNER_EXISTS:-0})
  2. Open $(grep '^APP_URL=' .env | cut -d= -f2-)/login
  3. Optional secrets (Gemini key, SMTP, Google OAuth) go in ${APP_DIR}/.env, then:
       sudo -u www-data php artisan optimize
  4. Verify (Activity & Diagnostics shows "Scheduler: running" within ~2 minutes).
  The database password is saved in ${CRED_FILE} (root only). Back up .env/APP_KEY and a nightly mysqldump.
  To update later: re-run this script (it keeps .env and data), or follow docs/DEPLOYMENT.md §6.
DONE
