#!/usr/bin/env bash
# FoodKing — Restore from backup (NF525 chain verification mandatory)
# Usage: restore-foodking-from-backup.sh <dump.sql.gz | s3://bucket/key>
# Target DB defaults to foodking_restore (NEVER the live `foodking`).
# Calls AuditLogService + ZReportService verifyChain via tinker post-restore.

set -Eeuo pipefail
IFS=$'\n\t'

log()  { echo "[restore-foodking $(date -u +%FT%TZ)] $*"; }
fail() { echo "[restore-foodking $(date -u +%FT%TZ)] FATAL: $*" >&2; exit 2; }

[[ $# -ge 1 ]] || fail "usage: $0 <dump.sql.gz | s3://bucket/key> [target_db]"
SRC="$1"
TARGET_DB="${2:-${RESTORE_TARGET_DB:-foodking_restore}}"

APP_ROOT="${FOODKING_ROOT:-/var/www/foodking}"
[[ -f "$APP_ROOT/.env" ]] && { set -a; . "$APP_ROOT/.env"; set +a; }
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"
DB_USERNAME="${DB_USERNAME:?missing}"; DB_PASSWORD="${DB_PASSWORD:?missing}"

# Guard: never restore over production DB without explicit override.
if [[ "$TARGET_DB" == "${DB_DATABASE:-foodking}" && "${ALLOW_RESTORE_PROD:-0}" != "1" ]]; then
  fail "Refusing to restore over live DB '$TARGET_DB'. Set ALLOW_RESTORE_PROD=1 to override."
fi

# --- 1. Fetch from S3 if needed --------------------------------------------
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
if [[ "$SRC" =~ ^s3:// ]]; then
  command -v s3cmd >/dev/null || fail "s3cmd not found"
  log "downloading $SRC (cold storage may take several minutes)"
  s3cmd get "$SRC"        "$WORK/dump.sql.gz"        || fail "s3cmd get dump failed"
  s3cmd get "${SRC}.sha256" "$WORK/dump.sql.gz.sha256" || fail "s3cmd get checksum failed"
  DUMP="$WORK/dump.sql.gz"; SHA="$WORK/dump.sql.gz.sha256"
else
  [[ -f "$SRC" ]] || fail "dump not found: $SRC"
  DUMP="$SRC"; SHA="${SRC}.sha256"
  [[ -f "$SHA" ]] || fail "checksum sidecar missing: $SHA"
fi

# --- 2. Verify checksum ----------------------------------------------------
log "verifying SHA-256"
( cd "$(dirname "$DUMP")" && sha256sum -c "$(basename "$SHA")" ) \
  || fail "checksum mismatch — refusing restore"

# --- 3. Interactive confirm ------------------------------------------------
echo "RESTORE PLAN: $DUMP  ->  ${DB_HOST}:${DB_PORT}/${TARGET_DB}"
read -r -p "Type the target DB name to confirm: " CONFIRM
[[ "$CONFIRM" == "$TARGET_DB" ]] || fail "confirmation mismatch — aborting"

# --- 4. Restore ------------------------------------------------------------
log "creating target DB if missing"
MYSQL_PWD="$DB_PASSWORD" mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
  -e "CREATE DATABASE IF NOT EXISTS \`$TARGET_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

log "restoring (this preserves triggers + routines from dump)"
gunzip -c "$DUMP" | MYSQL_PWD="$DB_PASSWORD" mysql \
  --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" "$TARGET_DB" \
  || fail "mysql restore failed"

# --- 5. NF525 chain integrity (via tinker on restored DB) ------------------
log "verifying NF525 HMAC chains (audit_logs + z_reports) for branch_id=1"
# NOTE: V1 = single-resto, branch_id=1. Multi-branch must iterate Branch::pluck('id').
# Instance methods (NOT static) — null AuditLog result = chain intact.
cd "$APP_ROOT"
DB_CONNECTION=mysql DB_DATABASE="$TARGET_DB" php artisan tinker --execute='
$audit = app(\App\Services\Fiscal\AuditLogService::class)->verifyChain(1);
$z     = app(\App\Services\Fiscal\ZReportService::class)->verifyChain(1);
$auditOk = is_null($audit);
$zOk     = !empty($z["valid"]);
echo "audit_logs.verifyChain: " . ($auditOk ? "OK" : "FAIL@id=$audit") . PHP_EOL;
echo "z_reports.verifyChain:  " . ($zOk    ? "OK" : "FAIL=" . json_encode($z["errors"] ?? [])) . PHP_EOL;
exit($auditOk && $zOk ? 0 : 3);
' || fail "NF525 chain verification FAILED — restored DB is corrupted, do NOT promote"

log "OK — restore + NF525 chain verified on $TARGET_DB"
exit 0
