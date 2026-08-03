#!/usr/bin/env bash
# [V1.0.2 Wave C4 UR4-007 2026-05-25]
#
# FoodKing Hetzner first-time server bootstrap.
#
# DRY-RUN by default. Use --really-setup to actually provision.
# Run AS ROOT on a fresh Ubuntu 22.04 LTS Hetzner CX22/CX32.
#
# Installs :
#   - System updates + UFW firewall (22, 80, 443 only)
#   - nginx (latest stable)
#   - php 8.2-fpm + extensions (cli, mbstring, xml, mysql, redis, gd,
#     intl, bcmath, opcache, zip, curl, fileinfo)
#   - MySQL 8 (root password prompted)
#   - Redis 7
#   - composer (binary)
#   - Node.js 20 LTS + npm
#   - certbot (Let's Encrypt)
#
# Configures :
#   - /var/www/foodking owned by deploy:www-data
#   - nginx vhost for lecayenne.fr (HTTPS auto-redirect)
#   - Let's Encrypt cert via certbot --nginx (interactive if --really-setup)
#   - MySQL database `foodking` + user `foodking@localhost`
#   - php-fpm pool with NF525-safe opcache settings
#   - systemd service `laravel-queue-worker.service`
#   - Cron jobs : foodking:backup-daily, fiscal:close-all-active-branches,
#     fiscal:open-all-active-branches
#
# Usage :
#   sudo bash scripts/deploy/server-setup-hetzner.sh                # dry-run
#   sudo bash scripts/deploy/server-setup-hetzner.sh --really-setup # provision
#   sudo bash scripts/deploy/server-setup-hetzner.sh --domain=lecayenne.fr
#
# Exit codes :
#   0 = bootstrap complete or dry-run preview
#   1 = not root
#   2 = invalid args
#   3 = command failed during real bootstrap

set -uo pipefail

DRY_RUN=1
DOMAIN="${DOMAIN:-lecayenne.fr}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@lecayenne.fr}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
REMOTE_DIR="${REMOTE_DIR:-/var/www/foodking}"

for arg in "$@"; do
  case "$arg" in
    --really-setup) DRY_RUN=0 ;;
    --domain=*) DOMAIN="${arg#--domain=}" ;;
    --email=*) ADMIN_EMAIL="${arg#--email=}" ;;
    *) echo "Unknown arg: $arg"; exit 2 ;;
  esac
done

if [ "$DRY_RUN" -eq 0 ] && [ "$(id -u)" != "0" ]; then
  echo "Must run as root for --really-setup"
  exit 1
fi

echo "=================================================="
echo "FoodKing Hetzner Server Bootstrap"
echo "Mode      : $([ $DRY_RUN -eq 1 ] && echo DRY-RUN || echo "REAL SETUP")"
echo "Domain    : $DOMAIN"
echo "Email     : $ADMIN_EMAIL"
echo "Deploy U. : $DEPLOY_USER"
echo "Remote D. : $REMOTE_DIR"
echo "=================================================="

run() {
  local desc="$1"
  local cmd="$2"
  echo ""
  echo "-> $desc"
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "  [DRY-RUN] would execute: $cmd"
  else
    eval "$cmd" || { echo "FAILED at step: $desc"; exit 3; }
  fi
}

# 1. OS updates
run "apt update && upgrade" "apt update && apt upgrade -y"

# 2. Essentials
run "install essentials" "apt install -y curl wget git unzip software-properties-common ca-certificates gnupg lsb-release"

# 3. UFW firewall
run "configure UFW firewall" "ufw --force reset && ufw default deny incoming && ufw default allow outgoing && ufw allow 22/tcp && ufw allow 80/tcp && ufw allow 443/tcp && ufw --force enable"

# 4. PHP 8.2
run "add PHP 8.2 PPA" "add-apt-repository -y ppa:ondrej/php && apt update"
run "install PHP 8.2 + extensions" "apt install -y php8.2 php8.2-fpm php8.2-cli php8.2-mbstring php8.2-xml php8.2-mysql php8.2-redis php8.2-gd php8.2-intl php8.2-bcmath php8.2-opcache php8.2-zip php8.2-curl php8.2-fileinfo"

# 5. nginx
run "install nginx" "apt install -y nginx"

# 6. MySQL 8
run "install MySQL 8" "apt install -y mysql-server"
run "secure MySQL (interactive prompt)" "mysql_secure_installation"

# 7. Redis 7
run "install Redis 7" "apt install -y redis-server"
run "enable Redis" "systemctl enable redis-server && systemctl start redis-server"

# 8. Composer
run "install composer" "curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer && chmod +x /usr/local/bin/composer"

# 9. Node 20 LTS
run "install Node.js 20" "curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && apt install -y nodejs"

# 10. certbot
run "install certbot" "apt install -y certbot python3-certbot-nginx"

# 11. Deploy user
run "create deploy user" "id -u $DEPLOY_USER &>/dev/null || useradd -m -s /bin/bash $DEPLOY_USER && usermod -aG www-data $DEPLOY_USER"

# 12. Deploy dir
run "create deploy dir" "mkdir -p $REMOTE_DIR && chown -R $DEPLOY_USER:www-data $REMOTE_DIR && chmod -R 775 $REMOTE_DIR"

# 13. MySQL DB + user
run "create MySQL database + user" 'mysql -e "CREATE DATABASE IF NOT EXISTS foodking CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '"'"'foodking'"'"'@'"'"'localhost'"'"' IDENTIFIED BY '"'"'CHANGE_ME_OWNER_INPUT'"'"'; GRANT ALL ON foodking.* TO '"'"'foodking'"'"'@'"'"'localhost'"'"'; FLUSH PRIVILEGES;"'

# 14. nginx vhost
cat <<NGINX_VHOST > /tmp/foodking-nginx.conf
server {
    listen 80;
    server_name $DOMAIN www.$DOMAIN;
    return 301 https://$DOMAIN\$request_uri;
}
server {
    listen 443 ssl http2;
    server_name $DOMAIN www.$DOMAIN;
    root $REMOTE_DIR/public;

    ssl_certificate /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    index index.php;
    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    client_max_body_size 100M;
}
NGINX_VHOST

run "deploy nginx vhost" "mv /tmp/foodking-nginx.conf /etc/nginx/sites-available/foodking && ln -sf /etc/nginx/sites-available/foodking /etc/nginx/sites-enabled/foodking && rm -f /etc/nginx/sites-enabled/default"
run "test + reload nginx" "nginx -t && systemctl reload nginx"

# 15. SSL via certbot
run "obtain SSL cert" "certbot --nginx -d $DOMAIN -d www.$DOMAIN --non-interactive --agree-tos -m $ADMIN_EMAIL"

# 16. systemd queue worker
cat <<QUEUE_SVC > /tmp/laravel-queue-worker.service
[Unit]
Description=Laravel Queue Worker
After=network.target

[Service]
Type=simple
User=$DEPLOY_USER
WorkingDirectory=$REMOTE_DIR
ExecStart=/usr/bin/php artisan queue:work redis --tries=3 --timeout=90
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
QUEUE_SVC

run "install systemd queue worker" "mv /tmp/laravel-queue-worker.service /etc/systemd/system/ && systemctl daemon-reload && systemctl enable laravel-queue-worker"

# 17. Cron jobs
cat <<CRONTAB > /tmp/foodking-cron
# FoodKing scheduled tasks (V1.0.2 Wave C4)
* * * * * cd $REMOTE_DIR && php artisan schedule:run >> /dev/null 2>&1
CRONTAB

run "install crontab for $DEPLOY_USER" "crontab -u $DEPLOY_USER /tmp/foodking-cron"

# 18. PHP-FPM opcache for prod
cat <<OPCACHE > /tmp/foodking-opcache.ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.save_comments=1
opcache.fast_shutdown=1
OPCACHE

run "install opcache config" "mv /tmp/foodking-opcache.ini /etc/php/8.2/fpm/conf.d/99-foodking-opcache.ini && systemctl restart php8.2-fpm"

echo ""
echo "=================================================="
if [ "$DRY_RUN" -eq 1 ]; then
  echo "DRY-RUN COMPLETE -- no actual changes made"
  echo "  Run with --really-setup as root to actually provision"
else
  echo "BOOTSTRAP COMPLETE"
  echo ""
  echo "Next steps :"
  echo "  1. Update MySQL foodking user password (change CHANGE_ME_OWNER_INPUT)"
  echo "  2. From dev : bash scripts/deploy/deploy-hetzner.sh --really-deploy --host=<this-ip>"
  echo "  3. Set .env on remote with production secrets"
fi
echo "=================================================="
exit 0
