#!/usr/bin/env bash
# FoodKing — Backup DB script
# Usage: ./scripts/ops/backup-db.sh [/path/to/backup/dir]
# Default backup dir: /var/backups/foodking
# Strategy: mysqldump --single-transaction (consistent snapshot without locking InnoDB tables)
#
# Exit codes:
#   0  success
#   1  configuration / pre-flight error
#   2  mysqldump failed
#   3  gzip integrity check failed
#
# OPS context: addresses OPS-1 (P0) of PRE_PROD_DEEP_RISK_AUDIT_2026-05-08.md
# Owner-tunable parameters at top of file.

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration block (owner-tunable)
# ---------------------------------------------------------------------------
APP_HOME="${APP_HOME:-/home/foodking/foodking}"
ENV_FILE="${ENV_FILE:-${APP_HOME}/.env}"
BACKUP_DIR="${1:-${BACKUP_DIR:-/var/backups/foodking}}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
LOG_TAG="foodking-backup"

# ---------------------------------------------------------------------------
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="${BACKUP_DIR}/foodking_db_${TIMESTAMP}.sql.gz"

mkdir -p "${BACKUP_DIR}"

[[ -f "${ENV_FILE}" ]] || { echo "[FAIL] ENV file not found: ${ENV_FILE}"; exit 1; }

# Read DB credentials from .env (Laravel convention).
# IMPORTANT: use `cut -d'=' -f2-` (trailing dash) so values containing '=' are not truncated.
read_env() {
    local key="$1"
    grep -E "^${key}=" "${ENV_FILE}" | head -n1 | cut -d'=' -f2- | tr -d '"' | tr -d "'"
}

DB_HOST=$(read_env DB_HOST)
DB_PORT=$(read_env DB_PORT)
DB_DATABASE=$(read_env DB_DATABASE)
DB_USERNAME=$(read_env DB_USERNAME)
DB_PASSWORD=$(read_env DB_PASSWORD)

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

[[ -n "${DB_DATABASE:-}" ]] || { echo "[FAIL] DB_DATABASE not set in ${ENV_FILE}"; exit 1; }
[[ -n "${DB_USERNAME:-}" ]] || { echo "[FAIL] DB_USERNAME not set in ${ENV_FILE}"; exit 1; }

echo "[BACKUP] Target file : ${BACKUP_FILE}"
echo "[BACKUP] Source DB   : ${DB_DATABASE}@${DB_HOST}:${DB_PORT}"
START_TIME=$(date +%s)

# `--protocol=tcp` forces TCP rather than the local mysqld socket — avoids the
# common gotcha where DB_HOST=localhost is interpreted as socket connection.
# `--single-transaction` gives an InnoDB-consistent snapshot without table locks.
# `--routines --triggers --events` ensures non-table objects are included.
# `--add-drop-database --databases` makes the dump self-contained and replayable.
if ! MYSQL_PWD="${DB_PASSWORD}" mysqldump \
    --protocol=tcp \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --user="${DB_USERNAME}" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --events \
    --add-drop-database \
    --set-gtid-purged=OFF \
    --databases "${DB_DATABASE}" \
    | gzip --best > "${BACKUP_FILE}"; then
    echo "[FAIL] mysqldump failed"
    rm -f "${BACKUP_FILE}"
    logger -t "${LOG_TAG}" "FAILURE: mysqldump exited non-zero for ${DB_DATABASE}"
    exit 2
fi

DURATION=$(($(date +%s) - START_TIME))
SIZE=$(du -h "${BACKUP_FILE}" | cut -f1)
echo "[BACKUP] Done: ${BACKUP_FILE} (${SIZE}) in ${DURATION}s"

# Verify backup integrity — gzip magic + tail check.
echo "[BACKUP] Verifying gzip integrity..."
if ! gunzip -t "${BACKUP_FILE}"; then
    echo "[FAIL] Backup is corrupt (gunzip -t failed)"
    logger -t "${LOG_TAG}" "FAILURE: backup corrupt ${BACKUP_FILE}"
    exit 3
fi
echo "[OK] Gzip integrity verified"

# Cleanup old backups (retention window).
echo "[BACKUP] Pruning backups older than ${RETENTION_DAYS} days..."
find "${BACKUP_DIR}" -maxdepth 1 -name "foodking_db_*.sql.gz" -type f -mtime +${RETENTION_DAYS} -print -delete | sed 's/^/[BACKUP] removed: /'

# Permissions: backups must not be world-readable (PII in delivery_webhook_events).
chmod 0600 "${BACKUP_FILE}" || true

logger -t "${LOG_TAG}" "Backup completed: ${BACKUP_FILE} (${SIZE}, ${DURATION}s)"
exit 0
