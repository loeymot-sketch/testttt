#!/usr/bin/env bash
# =============================================================================
# FoodKing Le Cayenne — Production Deploy Script (Hetzner / lecayenne.fr)
# -----------------------------------------------------------------------------
# Agent D.2 — Phase D — Owner D7=prepare-now
# Idempotent deploy of the Laravel app to /var/www/lecayenne.
#
# USAGE
#   sudo LECAYENNE_REPO_URL=<git-url> [LECAYENNE_BRANCH=main] \
#        bash scripts/deploy/deploy.sh
#
# DESIGN
#   - Idempotent — re-runs MUST be safe (no destructive ops on existing state).
#   - Discovery > assumptions — verifies host has php8.4/composer/mysql/nginx/
#     redis/node BEFORE touching anything, exits early with a clear list of
#     missing components (the contract is "server-setup ran"; we check the
#     outcomes, not the file).
#   - Production boot-guards aware — refuses to continue if .env still carries
#     dev/staging flags that AppServiceProvider would throw on at boot.
#   - NF525 fiscal chain — verifies CHAIN OK across all branches before
#     returning success; aborts on any tamper.
#
# CALLER CONTEXT
#   - Spec asks the caller to set LECAYENNE_REPO_URL before invocation.
#   - Spec asks the caller to manually edit /var/www/lecayenne/.env with
#     production DB credentials + secrets BEFORE re-running. The first run
#     creates the .env scaffold then exits with instructions.
#
# DEVIATIONS FROM SPEC (justified)
#   - npm uses `npm ci` (NOT `npm install --production`): Laravel Mix lives in
#     devDependencies, so `--production` would skip the build toolchain and
#     `npx mix --production` would then fail. `npm ci` installs the full
#     toolchain reproducibly; we optionally `npm prune --production` after the
#     Mix build to shrink the deploy footprint.
#   - Health check hits port 80 (nginx), NOT 8000 (php artisan serve is not
#     started in production). curl follows redirects in case nginx 80→443
#     redirect is active.
# =============================================================================

set -euo pipefail

# ---------- 0. Config ---------------------------------------------------------

APP_DIR="${APP_DIR:-/var/www/lecayenne}"
APP_USER="${APP_USER:-www-data}"
APP_GROUP="${APP_GROUP:-www-data}"
LECAYENNE_BRANCH="${LECAYENNE_BRANCH:-main}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1/api/health}"

# Required env from caller.
: "${LECAYENNE_REPO_URL:?ERROR: set LECAYENNE_REPO_URL=<git-url> before running}"

# ---------- 1. Pre-flight -----------------------------------------------------

echo "[1/12] Pre-flight: verifying server-setup.sh outcomes..."

REQUIRED_BINS=(php composer mysql nginx redis-cli node npm npx supervisorctl)
MISSING=()
for bin in "${REQUIRED_BINS[@]}"; do
    if ! command -v "$bin" >/dev/null 2>&1; then
        MISSING+=("$bin")
    fi
done

# PHP must be 8.4+
if command -v php >/dev/null 2>&1; then
    PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "0.0")"
    PHP_OK="$(php -r 'echo (PHP_VERSION_ID >= 80400) ? "1" : "0";' 2>/dev/null || echo "0")"
    if [[ "$PHP_OK" != "1" ]]; then
        MISSING+=("php8.4+ (found PHP $PHP_VER)")
    fi
fi

if [[ ${#MISSING[@]} -gt 0 ]]; then
    echo "ERROR: required binaries missing or wrong version:"
    printf '  - %s\n' "${MISSING[@]}"
    echo
    echo "Run scripts/deploy/server-setup.sh first, or install:"
    echo "  apt install -y php8.4-{cli,fpm,mysql,redis,mbstring,xml,curl,zip,intl,bcmath,gd}"
    echo "  apt install -y composer mysql-server nginx redis-server nodejs npm supervisor"
    exit 1
fi

echo "  OK — php $(php -r 'echo PHP_VERSION;'), $(node --version), nginx, mysql, redis present."

# ---------- 2. Repo clone / update -------------------------------------------

echo "[2/12] Cloning/updating repo at ${APP_DIR}..."

if [[ ! -d "$APP_DIR/.git" ]]; then
    # First clone — ensure parent exists.
    mkdir -p "$(dirname "$APP_DIR")"
    git clone "$LECAYENNE_REPO_URL" "$APP_DIR"
    chown -R "$APP_USER:$APP_GROUP" "$APP_DIR"
fi

cd "$APP_DIR"

# Warn on local changes — `git reset --hard` would discard operator hot-patches.
if [[ -n "$(git -C "$APP_DIR" status --porcelain 2>/dev/null)" ]]; then
    echo "  WARNING: local changes detected at ${APP_DIR}. Reset will discard them."
    echo "  Stash them first if needed: git -C ${APP_DIR} stash"
    echo "  Continuing in 5s — Ctrl-C to abort..."
    sleep 5
fi

git -C "$APP_DIR" fetch origin
git -C "$APP_DIR" reset --hard "origin/${LECAYENNE_BRANCH}"
echo "  OK — at $(git -C "$APP_DIR" rev-parse --short HEAD) on ${LECAYENNE_BRANCH}"

# [CI ownership fix] git ran as root, so any file it (re)wrote — e.g. public/*
# bundles reverted from a prior `mix --production` build, or .git internals — is
# now root-owned. The composer/npm/artisan steps below run as $APP_USER (www-data)
# and must be able to overwrite them (mix rewrites public/mix-manifest.json).
# Re-own the tree to $APP_USER before those steps to avoid EACCES on re-deploys.
chown -R "${APP_USER}:${APP_GROUP}" "$APP_DIR"

# [CI real-time fix] soketi.json carries the Pusher app keys but is git-tracked
# with placeholder keys ("app-key"); the reset above reverts the live file, so
# soketi would then reject the real key the frontend bundle uses (-> KDS/borne
# fall back to polling). Regenerate it from .env PUSHER_* (the source of truth)
# so soketi and the browser agree on the key. .env is gitignored => preserved.
if [[ -f "$APP_DIR/.env" ]]; then
    _pid=$(grep -E '^PUSHER_APP_ID='     "$APP_DIR/.env" | cut -d= -f2-)
    _pkey=$(grep -E '^PUSHER_APP_KEY='    "$APP_DIR/.env" | cut -d= -f2-)
    _psec=$(grep -E '^PUSHER_APP_SECRET=' "$APP_DIR/.env" | cut -d= -f2-)
    if [[ -n "$_pkey" ]]; then
        cat > "$APP_DIR/soketi.json" <<SOKETI
{
  "debug": false,
  "host": "127.0.0.1",
  "port": 6001,
  "appManager.driver": "array",
  "appManager.array.apps": [
    { "id": "${_pid}", "key": "${_pkey}", "secret": "${_psec}", "maxConnections": 200, "enableClientMessages": false, "enabled": true }
  ]
}
SOKETI
        chown "${APP_USER}:${APP_GROUP}" "$APP_DIR/soketi.json"
        chmod 640 "$APP_DIR/soketi.json"
        echo "  OK — soketi.json regenerated from .env PUSHER_* (frontend/soketi key match)."
    fi
fi

# ---------- 3. .env scaffold --------------------------------------------------

echo "[3/12] .env setup..."

if [[ ! -f "$APP_DIR/.env" ]]; then
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    # `key:generate` only on first scaffold — re-keying invalidates encrypted
    # sessions / Sanctum tokens / cookies.
    php "$APP_DIR/artisan" key:generate --force
    echo
    echo "  ============================================================"
    echo "  FIRST-RUN .env SCAFFOLD CREATED at ${APP_DIR}/.env"
    echo "  Edit it with production values BEFORE re-running this script:"
    echo "    APP_ENV=production"
    echo "    APP_DEBUG=false"
    echo "    APP_URL=https://lecayenne.fr"
    echo "    DB_DATABASE / DB_USERNAME / DB_PASSWORD"
    echo "    CACHE_DRIVER=redis"
    echo "    QUEUE_CONNECTION=redis"
    echo "    BROADCAST_DRIVER=pusher       (soketi-compatible)"
    echo "    POS_SIMULATION_HARDWARE=false"
    echo "    IDEMPOTENCY_MIDDLEWARE_ENABLED=true"
    echo "    LOYALTY_QR_SECRET=<64-char hex>"
    echo "    PUSHER_APP_ID / PUSHER_APP_KEY / PUSHER_APP_SECRET / PUSHER_HOST"
    echo "  ============================================================"
    exit 2
else
    # APP_KEY must be non-empty — generate it ONLY if missing.
    if ! grep -qE '^APP_KEY=base64:' "$APP_DIR/.env"; then
        php "$APP_DIR/artisan" key:generate --force
    fi
    echo "  OK — .env present, APP_KEY set."
fi

# ---------- 4. Production .env guards -----------------------------------------

echo "[4/12] Verifying production .env guards..."

env_get() {
    grep -E "^${1}=" "$APP_DIR/.env" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true
}

declare -A REQUIRED=(
    [APP_ENV]="production"
    [APP_DEBUG]="false"
    [POS_SIMULATION_HARDWARE]="false"
    [IDEMPOTENCY_MIDDLEWARE_ENABLED]="true"
    [CACHE_DRIVER]="redis"
    [QUEUE_CONNECTION]="redis"
    [BROADCAST_DRIVER]="pusher"
)

GUARD_FAIL=0
for key in "${!REQUIRED[@]}"; do
    actual="$(env_get "$key")"
    expected="${REQUIRED[$key]}"
    if [[ "$actual" != "$expected" ]]; then
        echo "  FAIL: $key=$actual (expected: $expected)"
        GUARD_FAIL=1
    fi
done

# APP_URL must be non-empty AND start with https://
APP_URL_VAL="$(env_get APP_URL)"
if [[ -z "$APP_URL_VAL" || "$APP_URL_VAL" != https://* ]]; then
    echo "  FAIL: APP_URL=$APP_URL_VAL (expected non-empty https:// URL)"
    GUARD_FAIL=1
fi

# LOYALTY_QR_SECRET must be set (Loyalty QR signing).
LOYALTY_SECRET_VAL="$(env_get LOYALTY_QR_SECRET)"
if [[ -z "$LOYALTY_SECRET_VAL" ]]; then
    echo "  FAIL: LOYALTY_QR_SECRET empty (V1.0.1 loyalty QR signing)"
    GUARD_FAIL=1
fi

if [[ $GUARD_FAIL -eq 1 ]]; then
    echo
    echo "ERROR: .env guards failed. Edit ${APP_DIR}/.env and re-run."
    echo "These checks mirror AppServiceProvider.php boot guards — without"
    echo "them passing, php artisan would throw RuntimeException at boot."
    exit 3
fi
echo "  OK — production guards pass."

# ---------- 5. Composer ------------------------------------------------------

echo "[5/12] composer install --no-dev..."
cd "$APP_DIR"
sudo -u "$APP_USER" composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist

# ---------- 6. Node + Mix ----------------------------------------------------

echo "[6/12] npm ci + npx mix --production..."
# npm ci installs FULL dependencies (incl. laravel-mix in devDependencies);
# `--production` on install would skip the build toolchain.
sudo -u "$APP_USER" npm ci --no-audit --no-fund
sudo -u "$APP_USER" npx mix --production
# Shrink node_modules to runtime-only after Mix is built (soketi runtime needs prod deps).
sudo -u "$APP_USER" npm prune --production

# ---------- 7. Storage link --------------------------------------------------

echo "[7/12] storage:link..."
sudo -u "$APP_USER" php "$APP_DIR/artisan" storage:link || true

# ---------- 8. Migrations ----------------------------------------------------

echo "[8/12] php artisan migrate --force..."

# [GOAL-H2-HEAL-03 2026-05-24] Pre-migrate backup safety net (Phase H.4 REC-1).
# Phase H.4 audit caught this step running `migrate --force` with NO prior
# backup, even though scripts/db/backup.sh exists and RunDailyBackup.php
# docblock explicitly designates it as "the pre-migration safety net".
# Backup failure ABORTS the deploy (set -e + explicit exit 1) — no
# partial-state risk. Credentials are read from .env via env_get() (above
# at L149) since `sudo -u` does not export the shell's environment, and
# the production guards (--allow-production + --i-understand-production)
# are required because deploy.sh enforces APP_ENV=production (L153-157).
echo "  ==> Pre-migrate safety backup (scripts/db/backup.sh)..."
BACKUP_DIR="$APP_DIR/storage/app/migration-backups"
sudo -u "$APP_USER" mkdir -p "$BACKUP_DIR"
sudo -u "$APP_USER" bash "$APP_DIR/scripts/db/backup.sh" \
    --env=production \
    --driver="$(env_get DB_CONNECTION)" \
    --database="$(env_get DB_DATABASE)" \
    --host="$(env_get DB_HOST)" \
    --port="$(env_get DB_PORT)" \
    --username="$(env_get DB_USERNAME)" \
    --password="$(env_get DB_PASSWORD)" \
    --output-dir="$BACKUP_DIR" \
    --i-understand-backup \
    --allow-production \
    --i-understand-production \
    || { echo "  FATAL: pre-migrate backup failed, ABORTING deploy"; exit 1; }
echo "  ==> Backup OK, proceeding to migrate..."

sudo -u "$APP_USER" php "$APP_DIR/artisan" migrate --force

# ---------- 9. Caches (config / route / view) --------------------------------

echo "[9/12] config:cache + route:cache + view:cache..."
sudo -u "$APP_USER" php "$APP_DIR/artisan" config:cache
sudo -u "$APP_USER" php "$APP_DIR/artisan" route:cache
sudo -u "$APP_USER" php "$APP_DIR/artisan" view:cache

# ---------- 10. Fiscal chain verification ------------------------------------

echo "[10/12] Verifying NF525 fiscal chain integrity..."
# Use exit code as primary signal; grep as belt-and-braces. The success line is:
#   "SWEEP COMPLETE — CHAIN OK on every active branch (N total)"
set +e
FISCAL_OUT="$(sudo -u "$APP_USER" php "$APP_DIR/artisan" fiscal:verify-chain --all 2>&1)"
FISCAL_RC=$?
set -e
echo "$FISCAL_OUT"
if [[ $FISCAL_RC -ne 0 ]] || ! grep -qE 'SWEEP COMPLETE.*CHAIN OK' <<<"$FISCAL_OUT"; then
    echo
    echo "ERROR: fiscal chain verification FAILED. Aborting deploy."
    echo "Investigate audit_logs / z_reports tamper before retrying."
    exit 4
fi
echo "  OK — NF525 chain intact across all branches."

# ---------- 11. Permissions ---------------------------------------------------

echo "[11/12] Setting permissions on storage/ + bootstrap/cache/..."
chown -R "$APP_USER:$APP_GROUP" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
find "$APP_DIR/storage" -type d -exec chmod 775 {} \;
find "$APP_DIR/storage" -type f -exec chmod 664 {} \;
find "$APP_DIR/bootstrap/cache" -type d -exec chmod 775 {} \;
find "$APP_DIR/bootstrap/cache" -type f -exec chmod 664 {} \;

# ---------- 12. Restart workers + reload nginx + health check -----------------

echo "[12/12] Restart supervisor + reload nginx + health check..."
supervisorctl reread || true
supervisorctl update || true
supervisorctl restart lecayenne-queue-worker:* 2>/dev/null || \
    echo "  WARN: lecayenne-queue-worker not yet registered (install supervisor.conf.template first)"
supervisorctl restart lecayenne-soketi 2>/dev/null || \
    echo "  WARN: lecayenne-soketi not yet registered (install supervisor.conf.template first)"

nginx -t && systemctl reload nginx

# [GOAL-L2.2 / I3-RISK-02 2026-05-24] Flush OPcache so the new .php files
# emitted by `git reset --hard` (L108) + `composer install` (L200) actually
# replace the bytecode cached in shared memory. `systemctl reload php8.4-fpm`
# is the graceful-reload signal — masters re-exec workers cleanly, no
# dropped requests. Without this step, if the operator ever sets
# `opcache.validate_timestamps=0` for production perf (Phase I.3 risk
# scenario), new code would NEVER load until php-fpm is manually
# restarted. See `reports/test-e2e/goal-2026-05-23/phase-i/I3-hidden-caching-findings.json`
# entry "I3-RISK-02". The reload is a no-op cost (~50ms) on the
# default validate_timestamps=1 setup, so always safe to ship.
systemctl reload php8.4-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || \
    echo "  WARN: php-fpm reload skipped (service not found — verify OPcache fresh after deploy)"

# Hit nginx on loopback. Follow redirects (-L) in case 80→443 is wired.
# Use --resolve if needed; here we trust 127.0.0.1 maps via /etc/hosts or
# nginx default_server.
sleep 2
if curl -fsSL --max-time 10 "$HEALTH_URL" -o /tmp/lecayenne-health.json; then
    echo "  OK — /api/health returned 200."
    cat /tmp/lecayenne-health.json
    echo
else
    echo
    echo "ERROR: health check failed at $HEALTH_URL"
    echo "Inspect: journalctl -u nginx -n 50 && tail -n 50 ${APP_DIR}/storage/logs/laravel.log"
    exit 5
fi

echo
echo "============================================================"
echo "DEPLOY OK — Le Cayenne live at $(env_get APP_URL)"
echo "HEAD: $(git -C "$APP_DIR" rev-parse --short HEAD) on ${LECAYENNE_BRANCH}"
echo "Next: monitor storage/logs/laravel.log + supervisorctl status"
echo "============================================================"
exit 0
