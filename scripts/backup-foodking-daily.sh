#!/usr/bin/env bash
# FoodKing — Daily production backup (NF525-compliant)
# Dumps MySQL `foodking` with triggers + routines, computes SHA-256, uploads to
# OVH Object Storage Cold (S3-compatible), and rotates local copies (7 days).
# Designed to run from cron (see docs/runbooks/BACKUP_RESTORE_NF525.md).
# Exit 0 on success, non-zero on any failure — cron-monitorable.

set -Eeuo pipefail
IFS=$'\n\t'

log()  { echo "[backup-foodking $(date -u +%FT%TZ)] $*"; }
fail() { echo "[backup-foodking $(date -u +%FT%TZ)] FATAL: $*" >&2; alert "$*"; exit 2; }

alert() {
  local msg="$1"
  if [[ -n "${BACKUP_ALERT_WEBHOOK:-}" ]]; then
    curl --max-time 10 -fsS -X POST -H 'Content-Type: application/json' \
      --data "{\"text\":\"FoodKing backup FAILED: ${msg//\"/\\\"}\"}" \
      "$BACKUP_ALERT_WEBHOOK" || true
  fi
}

# --- 1. Config (env vars — cron must export or source .env first) -----------
APP_ROOT="${FOODKING_ROOT:-/var/www/foodking}"
[[ -d "$APP_ROOT" ]] || fail "APP_ROOT not found: $APP_ROOT"

# Source Laravel .env (cron has empty env by default).
if [[ -f "$APP_ROOT/.env" ]]; then
  set -a; . "$APP_ROOT/.env"; set +a
fi

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:?DB_DATABASE missing}"
DB_USERNAME="${DB_USERNAME:?DB_USERNAME missing}"
DB_PASSWORD="${DB_PASSWORD:?DB_PASSWORD missing}"

S3_BUCKET="${BACKUP_S3_BUCKET:?BACKUP_S3_BUCKET missing (e.g. s3://foodking-backups-gra)}"
LOCAL_DIR="${BACKUP_LOCAL_DIR:-/var/backups/foodking}"
RETENTION_DAYS="${BACKUP_LOCAL_RETENTION_DAYS:-7}"

mkdir -p "$LOCAL_DIR"
command -v mysqldump >/dev/null || fail "mysqldump not found"
command -v s3cmd     >/dev/null || fail "s3cmd not found (apt install s3cmd)"
command -v sha256sum >/dev/null || fail "sha256sum not found"

# --- 2. Dump (triggers + routines mandatory for NF525 immutability) ---------
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DUMP="$LOCAL_DIR/foodking-${STAMP}.sql.gz"
SHA="$DUMP.sha256"

log "dumping $DB_DATABASE -> $DUMP"
# --triggers + --routines preserve BEFORE DELETE triggers on audit_logs/z_reports
# (NF525: dropping triggers breaks immutability and corrupts HMAC chain on restore).
MYSQL_PWD="$DB_PASSWORD" mysqldump \
  --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
  --triggers --routines --single-transaction --quick --add-drop-table \
  --default-character-set=utf8mb4 \
  "$DB_DATABASE" 2> >(tee -a "$LOCAL_DIR/dump.err" >&2) \
  | gzip -9 > "$DUMP" \
  || fail "mysqldump failed (see $LOCAL_DIR/dump.err)"

[[ -s "$DUMP" ]] || fail "dump file empty: $DUMP"

# RED-team P1: gzip integrity test catches truncated/corrupt gz (e.g. disk full
# mid-stream produces a valid-looking but truncated archive that gunzip -t detects).
gunzip -t "$DUMP" || fail "gzip integrity test failed (corrupt/truncated): $DUMP"

# --- 3. Checksum ------------------------------------------------------------
( cd "$LOCAL_DIR" && sha256sum "$(basename "$DUMP")" > "$SHA" ) \
  || fail "sha256sum failed"
log "sha256: $(awk '{print $1}' "$SHA")"

# --- 4. Upload to OVH Object Storage (S3-compatible) ------------------------
# s3cmd reads credentials from ~/.s3cfg (see runbook setup section).
# RED-team P1: retry with exponential backoff (2s/4s/8s) — OVH Object Storage
# occasionally 5xx-flakes on cold-tier writes; single-shot upload fails the run.
s3_put_retry() {
  local src="$1" dst="$2" attempt=1 delay=2
  while (( attempt <= 3 )); do
    if s3cmd put "$src" "$dst" --acl-private; then
      return 0
    fi
    log "s3cmd put attempt ${attempt}/3 failed for $(basename "$src"); sleeping ${delay}s"
    sleep "$delay"
    attempt=$((attempt + 1))
    delay=$((delay * 2))
  done
  return 1
}

log "uploading to $S3_BUCKET/"
s3_put_retry "$DUMP" "$S3_BUCKET/$(basename "$DUMP")" \
  || fail "s3cmd upload (dump) failed after 3 attempts"
s3_put_retry "$SHA"  "$S3_BUCKET/$(basename "$SHA")" \
  || fail "s3cmd upload (checksum) failed after 3 attempts"

# --- 5. Local retention (keep RETENTION_DAYS days; remote retention = lifecycle policy) ---
log "rotating local copies (>${RETENTION_DAYS} days)"
find "$LOCAL_DIR" -maxdepth 1 -type f \
  \( -name 'foodking-*.sql.gz' -o -name 'foodking-*.sql.gz.sha256' \) \
  -mtime "+${RETENTION_DAYS}" -print -delete || true

log "OK — backup uploaded: $S3_BUCKET/$(basename "$DUMP")"
exit 0
