#!/usr/bin/env bash
# FoodKing — Restore DB from backup file
# Usage: ./scripts/ops/restore-db.sh /path/to/backup.sql.gz
#
# WARNING: this DROPs the target database (the dump was generated with
# --add-drop-database --databases) and recreates it from the snapshot.
# Use only in disaster-recovery or rollback windows.
#
# Exit codes:
#   0  success
#   1  pre-flight error
#   2  user aborted
#   3  restore failed
#   4  post-restore migration check failed
#
# OPS context: addresses OPS-1 + OPS-2 (P0) of PRE_PROD_DEEP_RISK_AUDIT_2026-05-08.md

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration block (owner-tunable)
# ---------------------------------------------------------------------------
APP_HOME="${APP_HOME:-/home/foodking/foodking}"
ENV_FILE="${ENV_FILE:-${APP_HOME}/.env}"
APP_USER="${APP_USER:-foodking}"
LOG_TAG="foodking-restore"

# ---------------------------------------------------------------------------
BACKUP_FILE="${1:?Usage: $0 /path/to/backup.sql.gz}"
[[ -f "${BACKUP_FILE}" ]] || { echo "[FAIL] Backup file not found: ${BACKUP_FILE}"; exit 1; }
[[ -f "${ENV_FILE}" ]] || { echo "[FAIL] ENV file not found: ${ENV_FILE}"; exit 1; }

# Verify gzip integrity BEFORE we drop anything.
echo "[RESTORE] Pre-flight: verifying backup integrity..."
gunzip -t "${BACKUP_FILE}" || { echo "[FAIL] Backup is corrupt — refusing to restore."; exit 1; }
echo "[OK] Backup integrity verified"

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

# Confirm — interactive guard.
echo "==================================================="
echo "  RESTORE WARNING"
echo "==================================================="
echo "Backup file : ${BACKUP_FILE}"
echo "Target DB   : ${DB_DATABASE}@${DB_HOST}:${DB_PORT}"
echo "Hostname    : $(hostname)"
echo "Date        : $(date)"
echo
echo "This will DROP and RESTORE the entire database '${DB_DATABASE}'."
echo "Active connections will be terminated by the dump's CREATE DATABASE."
echo

if [[ "${RESTORE_NONINTERACTIVE:-0}" != "1" ]]; then
    read -r -p "Type 'CONFIRM' to proceed: " CONFIRM
    [[ "${CONFIRM}" == "CONFIRM" ]] || { echo "Aborted by operator."; exit 2; }
fi

echo "[RESTORE] Starting at $(date)"
START_TIME=$(date +%s)

if ! zcat "${BACKUP_FILE}" | MYSQL_PWD="${DB_PASSWORD}" mysql \
    --protocol=tcp \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --user="${DB_USERNAME}"; then
    echo "[FAIL] Restore command failed"
    logger -t "${LOG_TAG}" "FAILURE: restore aborted for ${BACKUP_FILE}"
    exit 3
fi

DURATION=$(($(date +%s) - START_TIME))
echo "[RESTORE] Done in ${DURATION}s"
logger -t "${LOG_TAG}" "Restore completed: ${BACKUP_FILE} (${DURATION}s)"

# Post-restore sanity: verify migrations table is intact.
# Run as the application user when invoked under sudo so cache + ownership are correct.
echo "[RESTORE] Running php artisan migrate:status..."
RUN_AS=""
if [[ "${EUID}" -eq 0 ]] && id "${APP_USER}" >/dev/null 2>&1; then
    RUN_AS="sudo -u ${APP_USER} -H"
fi

if ! ${RUN_AS} bash -c "cd '${APP_HOME}' && php artisan migrate:status" >/dev/null 2>&1; then
    echo "[WARN] migrate:status returned non-zero — schema may need attention"
    logger -t "${LOG_TAG}" "WARNING: migrate:status non-zero after restore"
    exit 4
fi

echo "[OK] migrate:status clean"
echo "[OK] Restore complete. Recommended next steps:"
echo "     - php artisan cache:clear && php artisan config:clear"
echo "     - php artisan queue:restart"
echo "     - Verify SOFT_DELETE_POLICY rows present"
echo "     - Run smoke test: curl -fsS http://127.0.0.1/api/health/ready"
exit 0
