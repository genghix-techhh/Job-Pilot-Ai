# Deployment — Hostinger VPS (Ubuntu)

These steps assume a fresh **Ubuntu 22.04/24.04** Hostinger VPS with SSH access and your domain's DNS **A record** already pointing to the VPS IP. (If you chose a VPS template with a control panel such as CloudPanel, create a *PHP site* there with document root `…/public` and skip the nginx/PHP/certbot parts.)

## Fast path — one script

On the fresh VPS (as root, or with `sudo`), after pointing your domain's A record at the server:

```bash
git clone https://github.com/genghix-techhh/Job-Pilot-Ai.git /tmp/jobpilot-src
sudo DOMAIN=jobs.example.com LE_EMAIL=you@example.com bash /tmp/jobpilot-src/deploy/install-vps.sh
cd /var/www/jobpilot && sudo -u www-data php artisan app:create-owner     # you type the password
```

(If the repository is private, clone with a GitHub token or deploy key, or `scp` the folder up and run the script from it with `REPO=` pointing at your remote.)

The script installs PHP/nginx/MySQL/Node, creates the database with a random password, clones the app to `/var/www/jobpilot`, builds it, writes `.env`, sets up nginx, the cron line, the firewall and HTTPS, then tells you to create your owner login. It is safe to re-run to update (it keeps `.env` and data). Everything below is the same thing done by hand, if you prefer.

Replace `jobs.example.com` and paths as needed. Run as a sudo-capable user, not root, where possible.

## 1. System packages

```bash
sudo apt update && sudo apt -y upgrade
sudo apt -y install nginx mysql-server git unzip supervisor certbot python3-certbot-nginx software-properties-common
# PHP 8.4 (or 8.3) from the Ondřej Surý PPA
sudo add-apt-repository -y ppa:ondrej/php && sudo apt update
sudo apt -y install php8.4-fpm php8.4-cli php8.4-mysql php8.4-curl php8.4-mbstring php8.4-xml php8.4-zip php8.4-intl
php -v    # must be 8.3 or newer
# Composer
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
# Node (only to build assets; or build locally and upload public/build)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt -y install nodejs
```

## 2. Database

```bash
sudo mysql
```
```sql
CREATE DATABASE jobpilot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'jobpilot'@'localhost' IDENTIFIED BY 'CHOOSE-A-LONG-RANDOM-PASSWORD';
GRANT ALL PRIVILEGES ON jobpilot.* TO 'jobpilot'@'localhost';
FLUSH PRIVILEGES;
```

## 3. Code

```bash
sudo mkdir -p /var/www/jobpilot && sudo chown $USER:www-data /var/www/jobpilot
cd /var/www/jobpilot
# upload/clone the project here, then:
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
nano .env        # APP_URL=https://jobs.example.com, APP_ENV=production, APP_DEBUG=false, DB_*, MAIL_*, GEMINI_API_KEY ...
npm ci && npm run build
php artisan migrate --force
php artisan app:create-owner
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
php artisan optimize          # caches config, routes, views, events
```

> `.env` holds secrets: `chmod 600 .env`, never commit it, and **back up `APP_KEY`** — it encrypts stored OAuth tokens and source credentials.

## 4. nginx (document root = `public/`)

`/etc/nginx/sites-available/jobpilot`:

```nginx
server {
    listen 80;
    server_name jobs.example.com;
    root /var/www/jobpilot/public;
    index index.php;
    client_max_body_size 8m;                      # CV uploads (limit is 5 MB in the app)

    add_header X-Content-Type-Options nosniff always;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
    location ~ /\.(?!well-known).* { deny all; }  # never serve .env, .git ...
}
```
```bash
sudo ln -s /etc/nginx/sites-available/jobpilot /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d jobs.example.com      # HTTPS + auto-renewal
```
Make sure `APP_URL` is the `https://` address, then `php artisan config:cache`. Set `SESSION_SECURE_COOKIE=true` (already in `.env.example`).

## 5. Scheduler (cron) and queue

**Option A — cron only (simplest, recommended):**
```bash
sudo crontab -u www-data -e
```
```
* * * * * cd /var/www/jobpilot && php artisan schedule:run >> /dev/null 2>&1
```
With `QUEUE_CONNECTION=database` and `SCHEDULER_RUNS_QUEUE=true` the scheduler also drains the queue every minute, so source syncs, AI analyses and e-mails are processed within ~1 minute.

**Option B — supervised worker (instant processing):**
set `SCHEDULER_RUNS_QUEUE=false` in `.env`, keep the cron line above, and create `/etc/supervisor/conf.d/jobpilot-worker.conf`:
```ini
[program:jobpilot-worker]
command=php /var/www/jobpilot/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopasgroup=true
stdout_logfile=/var/www/jobpilot/storage/logs/worker.log
```
```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start jobpilot-worker
```
After each deployment run `php artisan queue:restart`.

The same cron line also drives the **daily digest** (`app:send-digest`, hourly check), interview reminders and follow-up nudges. Digest time and frequency are set under Settings → Notifications; turn the digest off there if you do not want it.

PDF export (dompdf) writes fonts/cache under `storage/app/` — it needs no extra PHP extensions beyond `mbstring` and `dom`, but `storage/` must be writable by the web user.

**No cron at all?** Everything works manually: *Run discovery now* (Dashboard/Sources), *Sync now* (Inbox). Without a worker, set `QUEUE_CONNECTION=sync` so those buttons run inline.

## 6. Updating

```bash
cd /var/www/jobpilot
git pull            # or upload the new release
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
php artisan queue:restart
```

## 7. Backups

- **Database**: nightly `mysqldump jobpilot | gzip > /backups/jobpilot-$(date +%F).sql.gz` (cron), copy off the server.
- **Files**: `storage/app/private/resumes/` (your CV files) and `.env`.
- Hostinger's VPS snapshots are a useful extra, not a replacement.

## 8. Hardening checklist

- [ ] `APP_DEBUG=false`, `APP_ENV=production` (Activity & Diagnostics warns otherwise)
- [ ] HTTPS only; `APP_URL` starts with `https://`
- [ ] `OWNER_SETUP_TOKEN` removed from `.env`
- [ ] `ufw allow OpenSSH && ufw allow 'Nginx Full' && ufw enable`; SSH keys only
- [ ] MySQL listens on localhost only
- [ ] `.env` is `chmod 600`; `storage/` and `bootstrap/cache` writable by `www-data` only
- [ ] Unattended security upgrades enabled (`sudo apt install unattended-upgrades`)

## Verify after deploying

Do these once; each exercises something this build could only test with mocks.

1. **Database** — `php artisan migrate:status` shows all migrations *Ran* on MySQL; open the dashboard.
2. **Cron/queue** — Activity & Diagnostics shows *Scheduler: running* within 2 minutes.
3. **Sources** — add a Remote OK source → *Sync now* → jobs appear (Activity shows the run).
4. **E-mail** — Settings → *Send test e-mail*; check delivery status.
5. **Gemini** — Settings → *Test Gemini connection* (one call). Then upload a CV with AI consent on and check Activity → *Recent AI calls* (status `ok`, tokens recorded, no prompts).
6. **Digest & PDF** — Settings → *Send digest now* (arrives by e-mail); open an application in the Studio and click *PDF*.
7. **Gmail (optional)** — follow [`GMAIL_SETUP.md`](GMAIL_SETUP.md), connect, *Sync now*.
7. **Private files** — as a logged-out visitor, `https://jobs.example.com/storage/resumes/...` must return 404, and `/.env` must not be downloadable.

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| 500 error, blank page | `storage/` or `bootstrap/cache` not writable; check `storage/logs/laravel.log`. |
| CSS/JS missing | `npm run build` not run (or `public/build` not uploaded). |
| "Vite manifest not found" | Same as above. |
| Jobs never appear after "Run discovery" | No worker/cron: see the banner on the Dashboard; start the worker or use `QUEUE_CONNECTION=sync`. |
| Everything redirects to `http://` | Set `APP_URL=https://…`, run `php artisan config:cache`; behind a proxy set `TRUSTED_PROXIES`. |
| AI shows "Paused" | Free-tier rate/daily limit reached; it resumes automatically (time shown). Check the key/model in Diagnostics. |
| E-mail not arriving | `MAIL_MAILER` is `log`, wrong port/scheme (465 ⇒ `MAIL_SCHEME=smtps`), or the provider blocks the From address. See delivery errors under Activity. |
| Gmail "redirect_uri_mismatch" | The redirect URI in Google Cloud must equal `GOOGLE_REDIRECT_URI` (default `APP_URL/inbox/callback`) exactly. |
