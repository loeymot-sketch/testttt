#!/usr/bin/env bash
# =============================================================================
# server-setup.sh
# -----------------------------------------------------------------------------
# Purpose:
#   Initial Hetzner CX22 (Ubuntu 22.04 LTS) server setup for the FoodKing
#   Le Cayenne V1 LOCAL single-restaurant FR production deployment.
#
#   Installs PHP 8.4, Composer 2, Node 18, MySQL 8.0, Redis 7, Nginx, Soketi,
#   Supervisor, Certbot, UFW, Fail2ban, and creates NF525-compliant backup
#   directory layout for 6-year fiscal retention.
#
# Idempotency:
#   This script is RE-RUNNABLE. Each step checks current state and skips
#   already-applied changes (apt-get install is naturally idempotent;
#   custom logic guards Composer, Node, MySQL DB/user, systemd services).
#
# Usage:
#   sudo bash server-setup.sh
#   (or as root: bash server-setup.sh)
#
# NF525 Production Boot Guards (documented — enforced by app/Providers/
#   AppServiceProvider.php at boot, NOT by this script):
#     - POS_SIMULATION_HARDWARE must be false in .env (cash-trail integrity)
#     - IDEMPOTENCY_MIDDLEWARE_ENABLED must be true (duplicate POST protection)
#     - APP_DEBUG must be false (no stack/SQL/secret leaks)
#     - APP_URL must be non-empty (Sanctum + webhook signing)
#     - CACHE_DRIVER must NOT be array/null (cross-worker audit-chain lock)
#   This script prepares the OS layer; deploy.sh sets the .env values; the
#   application boot layer enforces them with a RuntimeException if violated.
#
# DOES NOT:
#   - Issue SSL certificates (deploy.sh post-DNS).
#   - Clone the application repo (deploy.sh).
#   - Enable UFW firewall (manual gate — see Section 14 instructions).
#   - Write supervisor program configs (separate supervisor.conf.template).
# =============================================================================

set -euo pipefail

# -----------------------------------------------------------------------------
# Globals
# -----------------------------------------------------------------------------
SCRIPT_NAME="$(basename "$0")"
LOG_PREFIX="[server-setup]"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
APP_DB_NAME="${APP_DB_NAME:-lecayenne_prod}"
APP_DB_USER="${APP_DB_USER:-lecayenne_app}"
BACKUP_ROOT="/var/backups/lecayenne"

# -----------------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------------
log()  { echo "${LOG_PREFIX} ==> $*"; }
ok()   { echo "${LOG_PREFIX}     [OK] $*"; }
warn() { echo "${LOG_PREFIX}     [WARN] $*" >&2; }
fail() { echo "${LOG_PREFIX}     [FAIL] $*" >&2; exit 1; }

cmd_exists() { command -v "$1" >/dev/null 2>&1; }

apt_install() {
    # Idempotent apt-get install (skips already-installed packages silently).
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "$@"
}

# =============================================================================
# 2. Pre-flight checks
# =============================================================================
preflight_checks() {
    log "Pre-flight checks"

    # Root / sudo
    if [[ $EUID -ne 0 ]]; then
        fail "Must be run as root or via sudo (current EUID=${EUID})"
    fi
    ok "Running as root"

    # Ubuntu 22.04 LTS
    if [[ ! -f /etc/os-release ]]; then
        fail "/etc/os-release missing — cannot verify OS"
    fi
    # shellcheck disable=SC1091
    . /etc/os-release
    if [[ "${ID:-}" != "ubuntu" ]] || [[ "${VERSION_ID:-}" != "22.04" ]]; then
        fail "Requires Ubuntu 22.04 LTS, found ID='${ID:-?}' VERSION_ID='${VERSION_ID:-?}'"
    fi
    ok "Ubuntu 22.04 LTS confirmed"

    # Internet connectivity
    if ! ping -c 1 -W 3 8.8.8.8 >/dev/null 2>&1; then
        fail "No internet connectivity (cannot ping 8.8.8.8)"
    fi
    ok "Internet connectivity confirmed"

    # Architecture sanity (Hetzner CX22 is amd64)
    local arch
    arch="$(dpkg --print-architecture)"
    if [[ "${arch}" != "amd64" ]]; then
        warn "Architecture '${arch}' — expected amd64 for Hetzner CX22; continuing anyway"
    else
        ok "Architecture amd64 confirmed"
    fi
}

# =============================================================================
# 3. System updates + essentials
# =============================================================================
system_updates() {
    log "System updates + essential packages"

    apt-get update -y
    DEBIAN_FRONTEND=noninteractive apt-get upgrade -y

    apt_install \
        curl \
        wget \
        git \
        unzip \
        software-properties-common \
        ca-certificates \
        lsb-release \
        gnupg \
        apt-transport-https \
        debian-keyring \
        debian-archive-keyring

    ok "System updated + essentials installed"
}

# =============================================================================
# 4. Timezone + locale (NF525 FR requirement)
# =============================================================================
timezone_locale() {
    log "Timezone + locale (Europe/Paris, fr_FR.UTF-8)"

    # Timezone
    timedatectl set-timezone Europe/Paris
    ok "Timezone set to Europe/Paris ($(date +%Z))"

    # Locale: ensure fr_FR.UTF-8 generated and set as default
    apt_install locales
    if ! locale -a 2>/dev/null | grep -qi "fr_FR\.utf8"; then
        sed -i 's/^# *\(fr_FR.UTF-8 UTF-8\)/\1/' /etc/locale.gen || true
        if ! grep -q "^fr_FR.UTF-8 UTF-8" /etc/locale.gen; then
            echo "fr_FR.UTF-8 UTF-8" >> /etc/locale.gen
        fi
        locale-gen fr_FR.UTF-8
    fi
    update-locale LANG=fr_FR.UTF-8 LC_ALL=fr_FR.UTF-8
    ok "Locale fr_FR.UTF-8 configured"
}

# =============================================================================
# 5. PHP 8.4 + extensions
# =============================================================================
php_install() {
    log "PHP 8.4 + extensions (Ondrej Sury PPA)"

    if ! apt-cache policy | grep -q "ondrej/php"; then
        add-apt-repository -y ppa:ondrej/php
        apt-get update -y
        ok "Added ppa:ondrej/php"
    else
        ok "ppa:ondrej/php already present"
    fi

    apt_install \
        php8.4 \
        php8.4-cli \
        php8.4-fpm \
        php8.4-mysql \
        php8.4-redis \
        php8.4-gd \
        php8.4-curl \
        php8.4-mbstring \
        php8.4-xml \
        php8.4-bcmath \
        php8.4-zip \
        php8.4-imagick \
        php8.4-intl \
        php8.4-soap \
        php8.4-readline \
        php8.4-opcache

    # Set php8.4 as default `php` alternative
    if [[ -x /usr/bin/php8.4 ]]; then
        update-alternatives --set php /usr/bin/php8.4 || \
            update-alternatives --install /usr/bin/php php /usr/bin/php8.4 100
    fi
    ok "PHP $(php -r 'echo PHP_VERSION;') is default"

    # Enable + start php8.4-fpm
    systemctl enable --now php8.4-fpm
    ok "php8.4-fpm enabled + started"
}

# =============================================================================
# 6. Composer 2 (verify hash, idempotent on version >= 2.7)
# =============================================================================
composer_install() {
    log "Composer 2"

    if cmd_exists composer; then
        local current_ver
        current_ver="$(composer --version 2>/dev/null | awk '{print $3}' || echo '0.0.0')"
        # Compare major.minor against 2.7
        if dpkg --compare-versions "${current_ver}" ge "2.7.0"; then
            ok "Composer ${current_ver} already installed (>= 2.7), skipping"
            return 0
        fi
        warn "Composer ${current_ver} present but < 2.7; upgrading"
    fi

    local tmp_installer="/tmp/composer-installer.php"
    php -r "copy('https://getcomposer.org/installer', '${tmp_installer}');"

    # Verify installer signature against official hash file
    local expected_sig actual_sig
    expected_sig="$(php -r "copy('https://composer.github.io/installer.sig', 'php://stdout');")"
    actual_sig="$(php -r "echo hash_file('sha384', '${tmp_installer}');")"

    if [[ "${expected_sig}" != "${actual_sig}" ]]; then
        rm -f "${tmp_installer}"
        fail "Composer installer hash mismatch (expected '${expected_sig}', got '${actual_sig}')"
    fi
    ok "Composer installer signature verified"

    php "${tmp_installer}" --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f "${tmp_installer}"
    chmod +x /usr/local/bin/composer
    ok "Composer $(composer --version --no-ansi 2>/dev/null | awk '{print $3}') installed"
}

# =============================================================================
# 7. Node 18 + npm (NodeSource setup_18.x)
# =============================================================================
node_install() {
    log "Node 18 + npm"

    if cmd_exists node; then
        local node_major
        node_major="$(node -v | sed 's/^v//' | cut -d. -f1)"
        if [[ "${node_major}" == "18" ]]; then
            ok "Node $(node -v) already installed"
            return 0
        fi
        warn "Node $(node -v) present but not v18; replacing"
    fi

    curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
    apt_install nodejs
    ok "Node $(node -v) + npm $(npm -v) installed"
}

# =============================================================================
# 8. MySQL 8.0 (with NF525 GRANT/REVOKE on audit_logs + z_reports)
# =============================================================================
mysql_install() {
    log "MySQL 8.0 + lecayenne_prod database setup"

    # Pre-seed root password if provided via env, else generate random and
    # write to a root-only file for owner to read after install.
    local mysql_root_pw_file="/root/.mysql_root_password"
    local app_db_pw_file="/root/.mysql_${APP_DB_USER}_password"

    apt_install mysql-server-8.0

    # Generate + persist root password if not already present
    local mysql_root_pw
    if [[ ! -f "${mysql_root_pw_file}" ]]; then
        if [[ -n "${MYSQL_ROOT_PASSWORD:-}" ]]; then
            mysql_root_pw="${MYSQL_ROOT_PASSWORD}"
        else
            mysql_root_pw="$(openssl rand -base64 32 | tr -d '/+=' | head -c 32)"
        fi
        umask 077
        printf '%s\n' "${mysql_root_pw}" > "${mysql_root_pw_file}"
        chmod 600 "${mysql_root_pw_file}"
        ok "MySQL root password written to ${mysql_root_pw_file}"
    else
        mysql_root_pw="$(cat "${mysql_root_pw_file}")"
        ok "MySQL root password already provisioned at ${mysql_root_pw_file}"
    fi

    # Generate + persist app-user password
    local app_db_pw
    if [[ ! -f "${app_db_pw_file}" ]]; then
        if [[ -n "${APP_DB_PASSWORD:-}" ]]; then
            app_db_pw="${APP_DB_PASSWORD}"
        else
            app_db_pw="$(openssl rand -base64 32 | tr -d '/+=' | head -c 32)"
        fi
        umask 077
        printf '%s\n' "${app_db_pw}" > "${app_db_pw_file}"
        chmod 600 "${app_db_pw_file}"
        ok "App DB password written to ${app_db_pw_file}"
    else
        app_db_pw="$(cat "${app_db_pw_file}")"
        ok "App DB password already provisioned at ${app_db_pw_file}"
    fi

    # Ensure mysqld is up
    systemctl enable --now mysql

    # Helper: run SQL as root (uses auth_socket on fresh Ubuntu install if
    # caching_sha2_password not yet set; otherwise uses password)
    mysql_root() {
        if mysql --protocol=socket -uroot -e "SELECT 1" >/dev/null 2>&1; then
            mysql --protocol=socket -uroot "$@"
        else
            mysql -uroot -p"${mysql_root_pw}" "$@"
        fi
    }

    # Set root password (auth_socket -> caching_sha2_password) — idempotent
    if mysql --protocol=socket -uroot -e "SELECT 1" >/dev/null 2>&1; then
        mysql --protocol=socket -uroot <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '${mysql_root_pw}';
FLUSH PRIVILEGES;
SQL
        ok "MySQL root authentication switched to password"
    fi

    # mysql_secure_installation (non-interactive equivalent)
    mysql_root <<SQL
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
SQL
    ok "MySQL hardened (anonymous + test DB removed, root remote disabled)"

    # Create application database + user
    mysql_root <<SQL
CREATE DATABASE IF NOT EXISTS \`${APP_DB_NAME}\`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${APP_DB_USER}'@'localhost'
    IDENTIFIED WITH caching_sha2_password BY '${app_db_pw}';
ALTER USER '${APP_DB_USER}'@'localhost'
    IDENTIFIED WITH caching_sha2_password BY '${app_db_pw}';

-- Standard app privileges
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES,
      CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW,
      CREATE ROUTINE, ALTER ROUTINE, TRIGGER
    ON \`${APP_DB_NAME}\`.*
    TO '${APP_DB_USER}'@'localhost';

FLUSH PRIVILEGES;
SQL
    ok "Database '${APP_DB_NAME}' + user '${APP_DB_USER}' created"

    # NF525 compliance — REVOKE destructive privileges on audit_logs + z_reports
    # so the application cannot DROP/ALTER them at runtime. DELETE is still
    # blocked by the BEFORE DELETE trigger configured in app migrations; we
    # additionally REVOKE TRUNCATE (which bypasses triggers) via GRANT-level
    # restriction. See Ansible task CVP0-1 / commit f840c3ef5.
    #
    # NOTE: tables may not exist yet at first run — REVOKE on non-existent
    # table errors silently if we use `IF EXISTS`-style guards. We guard
    # by checking table existence first.
    log "Applying NF525 GRANT/REVOKE on audit_logs + z_reports"
    for tbl in audit_logs z_reports; do
        local exists
        exists="$(mysql_root -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${APP_DB_NAME}' AND table_name='${tbl}';" 2>/dev/null || echo 0)"
        if [[ "${exists}" == "1" ]]; then
            mysql_root <<SQL
REVOKE DROP, ALTER ON \`${APP_DB_NAME}\`.\`${tbl}\` FROM '${APP_DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
            ok "REVOKE DROP/ALTER applied on ${APP_DB_NAME}.${tbl} (NF525)"
        else
            warn "Table ${APP_DB_NAME}.${tbl} does not exist yet — REVOKE deferred to post-migration"
            warn "  Re-run server-setup.sh after `php artisan migrate` to apply NF525 GRANT/REVOKE"
        fi
    done
}

# =============================================================================
# 9. Redis 7
# =============================================================================
redis_install() {
    log "Redis 7"

    apt_install redis-server

    # Ensure systemd manages it + autostart on boot
    systemctl enable --now redis-server
    if redis-cli ping 2>/dev/null | grep -q PONG; then
        ok "Redis $(redis-server --version | awk '{print $3}' | cut -d= -f2) up (PONG)"
    else
        fail "Redis installed but PING failed"
    fi
}

# =============================================================================
# 10. Nginx
# =============================================================================
nginx_install() {
    log "Nginx"

    apt_install nginx
    systemctl enable --now nginx

    if systemctl is-active --quiet nginx; then
        ok "Nginx $(nginx -v 2>&1 | awk -F/ '{print $2}') active"
    else
        fail "Nginx installed but not active"
    fi
}

# =============================================================================
# 11. Soketi (websocket server) + systemd unit
# =============================================================================
soketi_install() {
    log "Soketi (websocket server)"

    if ! cmd_exists soketi; then
        npm install -g @soketi/soketi
        ok "Soketi $(soketi --version 2>/dev/null || echo '?') installed via npm"
    else
        ok "Soketi already installed ($(soketi --version 2>/dev/null || echo '?'))"
    fi

    # Systemd unit (created idempotently — only written if missing or differs)
    local unit_path="/etc/systemd/system/soketi.service"
    local desired_content
    desired_content=$(cat <<'UNIT'
[Unit]
Description=Soketi WebSocket Server (FoodKing Le Cayenne)
Documentation=https://docs.soketi.app
After=network.target redis-server.service
Wants=redis-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/lecayenne
EnvironmentFile=-/etc/soketi/soketi.env
ExecStart=/usr/bin/soketi start --config=/etc/soketi/soketi.json
Restart=always
RestartSec=5
StandardOutput=append:/var/log/soketi.log
StandardError=append:/var/log/soketi.log
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
UNIT
)

    if [[ ! -f "${unit_path}" ]] || ! diff -q <(printf '%s\n' "${desired_content}") "${unit_path}" >/dev/null 2>&1; then
        printf '%s\n' "${desired_content}" > "${unit_path}"
        chmod 644 "${unit_path}"
        systemctl daemon-reload
        ok "Wrote ${unit_path}"
    else
        ok "${unit_path} already up-to-date"
    fi

    mkdir -p /etc/soketi
    touch /var/log/soketi.log
    chown www-data:www-data /var/log/soketi.log

    # Do NOT start yet — config file (/etc/soketi/soketi.json) is provisioned by deploy.sh
    systemctl enable soketi || true
    warn "Soketi unit installed + enabled but NOT started (config /etc/soketi/soketi.json"
    warn "  must be provisioned by deploy.sh before 'systemctl start soketi')"
}

# =============================================================================
# 12. Supervisor
# =============================================================================
supervisor_install() {
    log "Supervisor"

    apt_install supervisor
    systemctl enable --now supervisor

    if systemctl is-active --quiet supervisor; then
        ok "Supervisor active"
    else
        fail "Supervisor installed but not active"
    fi

    # Programs (queue:work, horizon, etc.) are NOT written here. They are
    # provisioned by deploy.sh from scripts/deploy/supervisor.conf.template.
    log "Note: Laravel queue worker programs provisioned later via supervisor.conf.template"
}

# =============================================================================
# 13. Certbot + nginx plugin (via snap)
# =============================================================================
certbot_install() {
    log "Certbot + nginx plugin"

    if ! cmd_exists snap; then
        apt_install snapd
        systemctl enable --now snapd.socket
    fi

    # snap core baseline
    snap install core 2>/dev/null || true
    snap refresh core 2>/dev/null || true

    if ! cmd_exists certbot; then
        snap install --classic certbot
        ln -sf /snap/bin/certbot /usr/bin/certbot
        ok "Certbot $(certbot --version 2>&1 | awk '{print $2}') installed"
    else
        ok "Certbot already installed ($(certbot --version 2>&1 | awk '{print $2}'))"
    fi

    log "Note: SSL certificate issuance deferred — run from deploy.sh AFTER DNS A record points to this server:"
    log "  certbot --nginx -d lecayenne.fr -d www.lecayenne.fr --non-interactive --agree-tos -m <owner-email>"
}

# =============================================================================
# 14. UFW firewall (configured but NOT enabled — manual gate)
# =============================================================================
ufw_configure() {
    log "UFW firewall rules (NOT enabled — manual gate)"

    apt_install ufw

    # Set defaults
    ufw default deny incoming  >/dev/null
    ufw default allow outgoing >/dev/null

    # Allow rules
    ufw allow OpenSSH    >/dev/null
    ufw allow 80/tcp     >/dev/null
    ufw allow 443/tcp    >/dev/null

    ok "UFW rules configured (default deny incoming, OpenSSH + 80 + 443 allowed)"
    log "UFW current status:"
    ufw status verbose || true

    echo ""
    echo "${LOG_PREFIX} ============================================================="
    echo "${LOG_PREFIX} UFW is INSTALLED + CONFIGURED but NOT ENABLED."
    echo "${LOG_PREFIX}"
    echo "${LOG_PREFIX} To enable AFTER confirming SSH still works from a separate"
    echo "${LOG_PREFIX} terminal session (keep this one open as fallback):"
    echo "${LOG_PREFIX}"
    echo "${LOG_PREFIX}     sudo ufw enable"
    echo "${LOG_PREFIX}     sudo ufw status verbose"
    echo "${LOG_PREFIX}"
    echo "${LOG_PREFIX} If SSH stops working post-enable, log in via Hetzner web"
    echo "${LOG_PREFIX} console and run: sudo ufw disable"
    echo "${LOG_PREFIX} ============================================================="
    echo ""
}

# =============================================================================
# 15. Fail2ban (SSH jail enabled)
# =============================================================================
fail2ban_install() {
    log "Fail2ban + SSH jail"

    apt_install fail2ban

    # Local jail config — enable sshd jail if not already enabled
    local jail_local="/etc/fail2ban/jail.local"
    if [[ ! -f "${jail_local}" ]]; then
        cat > "${jail_local}" <<'JAIL'
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5
backend  = systemd

[sshd]
enabled = true
port    = ssh
filter  = sshd
logpath = %(sshd_log)s
JAIL
        ok "Wrote ${jail_local} with SSH jail enabled"
    else
        ok "${jail_local} already present, skipping"
    fi

    systemctl enable --now fail2ban
    if systemctl is-active --quiet fail2ban; then
        ok "Fail2ban active"
    else
        fail "Fail2ban installed but not active"
    fi
}

# =============================================================================
# 16. Backup directory layout (NF525 6-year retention)
# =============================================================================
backup_dirs() {
    log "Backup directory layout (NF525 6-year retention)"

    mkdir -p "${BACKUP_ROOT}/db-daily"
    mkdir -p "${BACKUP_ROOT}/db-weekly"
    mkdir -p "${BACKUP_ROOT}/db-monthly"
    mkdir -p "${BACKUP_ROOT}/db-quarterly"
    mkdir -p "${BACKUP_ROOT}/files"
    mkdir -p "${BACKUP_ROOT}/logs"

    # Ensure deploy user owns it (or fall back to root if user not yet created;
    # deploy.sh creates the deploy user and may re-chown later).
    if id -u "${DEPLOY_USER}" >/dev/null 2>&1; then
        chown -R "${DEPLOY_USER}:${DEPLOY_USER}" "${BACKUP_ROOT}"
        ok "Backup dirs owned by ${DEPLOY_USER}"
    else
        chown -R root:root "${BACKUP_ROOT}"
        warn "Deploy user '${DEPLOY_USER}' does not exist yet — backup dirs owned by root"
        warn "  deploy.sh will re-chown after creating the user"
    fi

    chmod 750 "${BACKUP_ROOT}"
    ok "Backup tree ready at ${BACKUP_ROOT} (daily/weekly/monthly/quarterly + files + logs)"
}

# =============================================================================
# 17. Final summary
# =============================================================================
final_summary() {
    echo ""
    echo "${LOG_PREFIX} ============================================================="
    echo "${LOG_PREFIX} server-setup.sh — SUMMARY"
    echo "${LOG_PREFIX} ============================================================="
    echo "${LOG_PREFIX} OS                 : $(. /etc/os-release; echo "${PRETTY_NAME}")"
    echo "${LOG_PREFIX} Timezone           : $(timedatectl show -p Timezone --value)"
    echo "${LOG_PREFIX} Locale             : $(locale | grep '^LANG=' | cut -d= -f2)"
    echo "${LOG_PREFIX} PHP                : $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo 'MISSING')"
    echo "${LOG_PREFIX} Composer           : $(composer --version --no-ansi 2>/dev/null | awk '{print $3}' || echo 'MISSING')"
    echo "${LOG_PREFIX} Node               : $(node -v 2>/dev/null || echo 'MISSING')"
    echo "${LOG_PREFIX} npm                : $(npm -v 2>/dev/null || echo 'MISSING')"
    echo "${LOG_PREFIX} MySQL              : $(mysql --version 2>/dev/null | awk '{print $3}' || echo 'MISSING')"
    echo "${LOG_PREFIX} Redis              : $(redis-server --version 2>/dev/null | awk '{print $3}' | cut -d= -f2 || echo 'MISSING')"
    echo "${LOG_PREFIX} Nginx              : $(nginx -v 2>&1 | awk -F/ '{print $2}' || echo 'MISSING')"
    echo "${LOG_PREFIX} Soketi             : $(soketi --version 2>/dev/null || echo 'MISSING')"
    echo "${LOG_PREFIX} Supervisor         : $(supervisord -v 2>/dev/null || echo 'MISSING')"
    echo "${LOG_PREFIX} Certbot            : $(certbot --version 2>&1 | awk '{print $2}' || echo 'MISSING')"
    echo "${LOG_PREFIX} UFW                : configured (NOT enabled — manual gate)"
    echo "${LOG_PREFIX} Fail2ban           : $(fail2ban-client --version 2>/dev/null | head -1 || echo 'MISSING')"
    echo "${LOG_PREFIX} Backup root        : ${BACKUP_ROOT}"
    echo "${LOG_PREFIX} App database       : ${APP_DB_NAME}"
    echo "${LOG_PREFIX} App DB user        : ${APP_DB_USER}"
    echo "${LOG_PREFIX} MySQL root pw file : /root/.mysql_root_password (0600)"
    echo "${LOG_PREFIX} App DB pw file     : /root/.mysql_${APP_DB_USER}_password (0600)"
    echo "${LOG_PREFIX} ============================================================="
    echo "${LOG_PREFIX}"
    echo "${LOG_PREFIX} NEXT STEPS:"
    echo "${LOG_PREFIX}"
    echo "${LOG_PREFIX}   1. Create deploy user if not yet:"
    echo "${LOG_PREFIX}        sudo adduser --disabled-password --gecos '' ${DEPLOY_USER}"
    echo "${LOG_PREFIX}        sudo usermod -aG sudo,www-data ${DEPLOY_USER}"
    echo "${LOG_PREFIX}        sudo mkdir -p /home/${DEPLOY_USER}/.ssh"
    echo "${LOG_PREFIX}        # Add SSH public key to /home/${DEPLOY_USER}/.ssh/authorized_keys"
    echo "${LOG_PREFIX}"
    echo "${LOG_PREFIX}   2. Clone the app repository to /var/www/lecayenne:"
    echo "${LOG_PREFIX}        sudo mkdir -p /var/www/lecayenne && sudo chown ${DEPLOY_USER}: /var/www/lecayenne"
    echo "${LOG_PREFIX}        sudo -u ${DEPLOY_USER} git clone <repo-url> /var/www/lecayenne"
    echo "${LOG_PREFIX}"
    echo "${LOG_PREFIX}   3. Run the app deploy script:"
    echo "${LOG_PREFIX}        sudo bash /var/www/lecayenne/scripts/deploy/deploy.sh"
    echo "${LOG_PREFIX}"
    echo "${LOG_PREFIX}   4. AFTER DNS A record points to this server, issue SSL cert:"
    echo "${LOG_PREFIX}        sudo certbot --nginx -d lecayenne.fr -d www.lecayenne.fr \\"
    echo "${LOG_PREFIX}                     --non-interactive --agree-tos -m <owner-email>"
    echo "${LOG_PREFIX}"
    echo "${LOG_PREFIX}   5. After confirming SSH still works from a SEPARATE session:"
    echo "${LOG_PREFIX}        sudo ufw enable"
    echo "${LOG_PREFIX}"
    echo "${LOG_PREFIX}   6. After `php artisan migrate` runs (creates audit_logs +"
    echo "${LOG_PREFIX}      z_reports), re-run this script to apply NF525 GRANT/REVOKE:"
    echo "${LOG_PREFIX}        sudo bash $(realpath "$0")"
    echo "${LOG_PREFIX}"
    echo "${LOG_PREFIX} ============================================================="
}

# =============================================================================
# main
# =============================================================================
main() {
    log "Starting ${SCRIPT_NAME} on $(hostname) at $(date -Iseconds)"
    preflight_checks
    system_updates
    timezone_locale
    php_install
    composer_install
    node_install
    mysql_install
    redis_install
    nginx_install
    soketi_install
    supervisor_install
    certbot_install
    ufw_configure
    fail2ban_install
    backup_dirs
    final_summary
    log "DONE — ${SCRIPT_NAME} completed successfully at $(date -Iseconds)"
}

main "$@"
