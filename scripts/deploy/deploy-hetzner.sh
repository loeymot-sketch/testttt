#!/usr/bin/env bash
# [V1.0.2 Wave C3 UR4-007 2026-05-25]
#
# FoodKing Hetzner deployment automation — V1 Le Cayenne LOCAL.
#
# ⚠️  DRY-RUN by default. Use --really-deploy to actually push.
#
# Targets :
#   - Hetzner CX22 or CX32 server (Helsinki/Falkenstein/Nuremberg)
#   - Domain : lecayenne.fr (Let's Encrypt SSL via certbot)
#   - Stack : nginx + php-fpm 8.2 + MySQL 8 + Redis 7
#   - Queue : redis driver via Horizon
#
# Pre-requisites :
#   - scripts/deploy/server-setup-hetzner.sh has been run ONCE on the
#     target server (Wave C4)
#   - $HETZNER_HOST environment variable set (or --host flag)
#   - SSH key access to deploy user on Hetzner server
#   - Local git working tree clean (or --allow-dirty)
#
# Sequence :
#   1. pre-flight.sh gate (Wave C2) — refuse if any check fails
#   2. rsync code to Hetzner (exclude .git, node_modules, vendor dev deps,
#      tests/, reports/)
#   3. Remote : composer install --no-dev --optimize-autoloader
#   4. Remote : npm ci && npx mix --production
#   5. Remote : php artisan migrate --force
#   6. Remote : php artisan optimize
#   7. Remote : php artisan fiscal:assert-chain-clean
#   8. Remote : sudo systemctl restart php8.2-fpm
#   9. Remote : sudo systemctl restart laravel-queue-worker
#  10. Health check : curl https://lecayenne.fr/healthz expecting 200
#
# Usage :
#   bash scripts/deploy/deploy-hetzner.sh                 # dry-run
#   bash scripts/deploy/deploy-hetzner.sh --really-deploy # for real
#   bash scripts/deploy/deploy-hetzner.sh --host=1.2.3.4  # override
#
# Exit codes :
#   0 = deploy successful (dry-run or real)
#   1 = pre-flight failed
#   2 = invalid args
#   3 = remote command failed
#   4 = health check failed

set -uo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$PROJECT_ROOT"

DRY_RUN=1
ALLOW_DIRTY=0
HOST="${HETZNER_HOST:-}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
REMOTE_DIR="${REMOTE_DIR:-/var/www/foodking}"

for arg in "$@"; do
  case "$arg" in
    --really-deploy) DRY_RUN=0 ;;
    --allow-dirty) ALLOW_DIRTY=1 ;;
    --host=*) HOST="${arg#--host=}" ;;
    *) echo "Unknown arg: $arg"; exit 2 ;;
  esac
done

echo "=================================================="
echo "FoodKing Hetzner Deploy"
echo "Mode      : $([ $DRY_RUN -eq 1 ] && echo DRY-RUN || echo "REAL DEPLOY")"
echo "Host      : ${HOST:-NOT SET (will fail in real mode)}"
echo "User      : $DEPLOY_USER"
echo "Remote    : $REMOTE_DIR"
echo "=================================================="

run() {
  local desc="$1"
  local cmd="$2"
  echo ""
  echo "-> $desc"
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "  [DRY-RUN] would execute: $cmd"
  else
    eval "$cmd" || { echo "X FAILED at step: $desc"; exit 3; }
  fi
}

# 1. Pre-flight gate (local)
echo ""
echo "STEP 1 — Pre-flight gate"
if [ "$DRY_RUN" -eq 1 ]; then
  echo "  [DRY-RUN] would run: bash scripts/deploy/pre-flight.sh"
else
  bash scripts/deploy/pre-flight.sh || {
    echo "X PRE-FLIGHT FAILED — REFUSING TO DEPLOY"
    exit 1
  }
fi

# Validate host is set for real mode
if [ "$DRY_RUN" -eq 0 ] && [ -z "$HOST" ]; then
  echo "X HETZNER_HOST not set and --host not provided — refusing real deploy"
  exit 2
fi

# 2. Git status check
if [ "$ALLOW_DIRTY" -eq 0 ]; then
  DIRTY=$(git status --short 2>/dev/null | wc -l)
  if [ "$DIRTY" -gt 0 ]; then
    echo "X Working tree dirty ($DIRTY changes) — pass --allow-dirty to override"
    [ "$DRY_RUN" -eq 0 ] && exit 1
  fi
fi

# 3. rsync to remote
run "rsync code to remote" "rsync -avz --delete \
  --exclude='.git/' \
  --exclude='node_modules/' \
  --exclude='tests/' \
  --exclude='reports/' \
  --exclude='.env' \
  --exclude='storage/logs/' \
  --exclude='storage/framework/cache/' \
  --exclude='storage/framework/sessions/' \
  --exclude='storage/framework/views/' \
  --exclude='public/storage' \
  ./ $DEPLOY_USER@$HOST:$REMOTE_DIR/"

# 4. Composer install on remote (production)
run "composer install --no-dev (remote)" \
  "ssh $DEPLOY_USER@$HOST 'cd $REMOTE_DIR && composer install --no-dev --optimize-autoloader --no-interaction'"

# 5. NPM build on remote
run "npm ci + npx mix --production (remote)" \
  "ssh $DEPLOY_USER@$HOST 'cd $REMOTE_DIR && npm ci --omit=dev && npx mix --production'"

# 6. Migrate
run "php artisan migrate --force (remote)" \
  "ssh $DEPLOY_USER@$HOST 'cd $REMOTE_DIR && php artisan migrate --force'"

# 7. Optimize
run "php artisan optimize (remote)" \
  "ssh $DEPLOY_USER@$HOST 'cd $REMOTE_DIR && php artisan optimize'"

# 8. NF525 chain final assert
run "NF525 chain assert (remote)" \
  "ssh $DEPLOY_USER@$HOST 'cd $REMOTE_DIR && php artisan fiscal:assert-chain-clean'"

# 9. Restart PHP-FPM
run "restart php8.2-fpm (remote)" \
  "ssh $DEPLOY_USER@$HOST 'sudo systemctl restart php8.2-fpm'"

# 10. Restart queue worker
run "restart laravel-queue-worker (remote)" \
  "ssh $DEPLOY_USER@$HOST 'sudo systemctl restart laravel-queue-worker || sudo systemctl restart horizon'"

# 11. Health check
if [ "$DRY_RUN" -eq 1 ]; then
  echo ""
  echo "-> Health check"
  echo "  [DRY-RUN] would curl https://lecayenne.fr/healthz expecting 200"
else
  echo ""
  echo "-> Health check : https://lecayenne.fr/healthz"
  HEALTH_CODE=$(curl -s -o /dev/null -w "%{http_code}" https://lecayenne.fr/healthz)
  if [ "$HEALTH_CODE" = "200" ]; then
    echo "OK health 200"
  else
    echo "X health returned $HEALTH_CODE (expected 200)"
    exit 4
  fi
fi

echo ""
echo "=================================================="
if [ "$DRY_RUN" -eq 1 ]; then
  echo "OK DRY-RUN COMPLETE — no actual changes made"
  echo "  Run with --really-deploy to actually deploy"
else
  echo "OK DEPLOY SUCCESSFUL"
fi
echo "=================================================="
exit 0
