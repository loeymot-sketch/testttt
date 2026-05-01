#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

usage() {
  cat <<'USAGE'
FoodKing migration rehearsal wrapper.

Staging-only and fail-closed. A rehearsal mutates staging only when --apply,
--i-understand-rehearsal-mutates-db, and --run are all present.
The full rehearsal sequence is: php artisan migrate, php artisan
migrate:rollback, then php artisan migrate again.

Usage:
  bash scripts/db/rehearsal.sh --env=staging --connection=mysql --backup-manifest=storage/app/migration-backups/backup-staging.manifest.json --step=1 --apply --i-understand-rehearsal-mutates-db --run
  bash scripts/db/rehearsal.sh --env=staging --backup-manifest=/tmp/manifest.json --step=1 --print-command

Options:
  --env=NAME                              Required. Must not be production.
  --connection=NAME                       Optional Laravel connection name.
  --path=PATH                             Optional migration path.
  --backup-manifest=PATH                  Required manifest from backup.sh.
  --step=N                                Rollback step count. Defaults to 1.
  --dry-run-only                          Run dry-run only and exit.
  --apply                                 Permit staging Up/Down/Up rehearsal.
  --run                                   Execute commands.
  --print-command                         Print commands and exit.
  --i-understand-rehearsal-mutates-db     Required with --apply.
  -h, --help                              Show this help.
USAGE
}

fail() {
  echo "rehearsal: $*" >&2
  exit 2
}

quote_command() {
  local item
  for item in "$@"; do
    printf '%q ' "$item"
  done
  printf '\n'
}

artisan_command() {
  local subcommand="$1"
  shift
  local cmd=(php artisan "$subcommand" "$@")
  [[ -n "$CONNECTION" ]] && cmd+=(--database="$CONNECTION")
  [[ -n "$MIGRATION_PATH" ]] && cmd+=(--path="$MIGRATION_PATH")
  quote_command "${cmd[@]}"
}

ENV_NAME=""
CONNECTION=""
MIGRATION_PATH=""
BACKUP_MANIFEST=""
ROLLBACK_STEP=1
DRY_RUN_ONLY=0
APPLY=0
RUN=0
PRINT_COMMAND=0
CONFIRM_MUTATION=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env=*) ENV_NAME="${1#--env=}"; shift ;;
    --env) ENV_NAME="${2:?--env requires a value}"; shift 2 ;;
    --connection=*) CONNECTION="${1#--connection=}"; shift ;;
    --connection) CONNECTION="${2:?--connection requires a value}"; shift 2 ;;
    --path=*) MIGRATION_PATH="${1#--path=}"; shift ;;
    --path) MIGRATION_PATH="${2:?--path requires a value}"; shift 2 ;;
    --backup-manifest=*) BACKUP_MANIFEST="${1#--backup-manifest=}"; shift ;;
    --backup-manifest) BACKUP_MANIFEST="${2:?--backup-manifest requires a value}"; shift 2 ;;
    --step=*) ROLLBACK_STEP="${1#--step=}"; shift ;;
    --step) ROLLBACK_STEP="${2:?--step requires a value}"; shift 2 ;;
    --dry-run-only) DRY_RUN_ONLY=1; shift ;;
    --apply) APPLY=1; shift ;;
    --run) RUN=1; shift ;;
    --print-command) PRINT_COMMAND=1; shift ;;
    --i-understand-rehearsal-mutates-db) CONFIRM_MUTATION=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) fail "unknown option: $1" ;;
  esac
done

[[ -n "$ENV_NAME" ]] || fail "Refusing rehearsal without --env."
[[ "$ENV_NAME" =~ ^(prod|production)$ ]] && fail "Refusing production rehearsal. Rehearsal is staging-only."
[[ -n "$BACKUP_MANIFEST" ]] || fail "Refusing rehearsal without --backup-manifest."
[[ -f "$BACKUP_MANIFEST" ]] || fail "backup manifest not found: $BACKUP_MANIFEST"
[[ "$ROLLBACK_STEP" =~ ^[1-9][0-9]*$ ]] || fail "--step must be a positive integer."

if [[ "$RUN" -ne 1 && "$PRINT_COMMAND" -ne 1 ]]; then
  fail "Refusing to contact a database without --run. Use --print-command for review-only output."
fi

dry_run_cmd=(bash scripts/db/dry-run.sh --env="$ENV_NAME" --run)
[[ -n "$CONNECTION" ]] && dry_run_cmd+=(--connection="$CONNECTION")
[[ -n "$MIGRATION_PATH" ]] && dry_run_cmd+=(--path="$MIGRATION_PATH")

if [[ "$PRINT_COMMAND" -eq 1 ]]; then
  echo "# backup manifest: $BACKUP_MANIFEST"
  quote_command "${dry_run_cmd[@]}"
  artisan_command migrate --force
  artisan_command migrate:rollback --step="$ROLLBACK_STEP" --force
  artisan_command migrate --force
  exit 0
fi

cd "$ROOT_DIR"
export APP_ENV="$ENV_NAME"

"${dry_run_cmd[@]}"

if [[ "$DRY_RUN_ONLY" -eq 1 ]]; then
  exit 0
fi

if [[ "$APPLY" -ne 1 || "$CONFIRM_MUTATION" -ne 1 ]]; then
  fail "Refusing staging mutation without --apply and --i-understand-rehearsal-mutates-db."
fi

up_cmd=(php artisan migrate --force)
down_cmd=(php artisan migrate:rollback --step="$ROLLBACK_STEP" --force)
reup_cmd=(php artisan migrate --force)
[[ -n "$CONNECTION" ]] && up_cmd+=(--database="$CONNECTION") && down_cmd+=(--database="$CONNECTION") && reup_cmd+=(--database="$CONNECTION")
[[ -n "$MIGRATION_PATH" ]] && up_cmd+=(--path="$MIGRATION_PATH") && down_cmd+=(--path="$MIGRATION_PATH") && reup_cmd+=(--path="$MIGRATION_PATH")

"${up_cmd[@]}"
"${down_cmd[@]}"
"${reup_cmd[@]}"
