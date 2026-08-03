#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

usage() {
  cat <<'USAGE'
FoodKing migration dry-run wrapper.

Non-destructive by default: this script refuses to contact a database unless
--run or --print-command is provided.

Usage:
  bash scripts/db/dry-run.sh --env=staging --connection=mysql --run
  bash scripts/db/dry-run.sh --env=staging --connection=sqlite --path=database/migrations/example.php --print-command

Options:
  --env=NAME                    Required. Refuses production unless guarded.
  --connection=NAME             Optional Laravel connection name.
  --path=PATH                   Optional migration path passed to artisan.
  --output=PATH                 Optional transcript path. Must not already exist.
  --run                         Execute php artisan migrate --pretend --force.
  --print-command               Print the command that would run and exit.
  --allow-production            Production guard, requires --i-understand-production.
  --i-understand-production     Second production guard.
  -h, --help                    Show this help.
USAGE
}

fail() {
  echo "dry-run: $*" >&2
  exit 2
}

quote_command() {
  local item
  for item in "$@"; do
    printf '%q ' "$item"
  done
  printf '\n'
}

ENV_NAME=""
CONNECTION=""
MIGRATION_PATH=""
OUTPUT_PATH=""
RUN=0
PRINT_COMMAND=0
ALLOW_PRODUCTION=0
PRODUCTION_ACK=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env=*) ENV_NAME="${1#--env=}"; shift ;;
    --env) ENV_NAME="${2:?--env requires a value}"; shift 2 ;;
    --connection=*) CONNECTION="${1#--connection=}"; shift ;;
    --connection) CONNECTION="${2:?--connection requires a value}"; shift 2 ;;
    --path=*) MIGRATION_PATH="${1#--path=}"; shift ;;
    --path) MIGRATION_PATH="${2:?--path requires a value}"; shift 2 ;;
    --output=*) OUTPUT_PATH="${1#--output=}"; shift ;;
    --output) OUTPUT_PATH="${2:?--output requires a value}"; shift 2 ;;
    --run) RUN=1; shift ;;
    --print-command) PRINT_COMMAND=1; shift ;;
    --allow-production) ALLOW_PRODUCTION=1; shift ;;
    --i-understand-production) PRODUCTION_ACK=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) fail "unknown option: $1" ;;
  esac
done

[[ -n "$ENV_NAME" ]] || fail "Refusing dry-run without --env."

if [[ "$ENV_NAME" =~ ^(prod|production)$ ]]; then
  if [[ "$ALLOW_PRODUCTION" -ne 1 || "$PRODUCTION_ACK" -ne 1 ]]; then
    fail "Refusing production dry-run without --allow-production and --i-understand-production."
  fi
fi

if [[ "$RUN" -ne 1 && "$PRINT_COMMAND" -ne 1 ]]; then
  fail "Refusing to contact a database without --run. Use --print-command for review-only output."
fi

[[ -f "$ROOT_DIR/artisan" ]] || fail "artisan not found at repo root."

cmd=(php artisan migrate --pretend --force)
[[ -n "$CONNECTION" ]] && cmd+=(--database="$CONNECTION")
[[ -n "$MIGRATION_PATH" ]] && cmd+=(--path="$MIGRATION_PATH")

if [[ "$PRINT_COMMAND" -eq 1 ]]; then
  printf 'APP_ENV=%q ' "$ENV_NAME"
  quote_command "${cmd[@]}"
  exit 0
fi

if [[ -n "$OUTPUT_PATH" ]]; then
  [[ ! -e "$OUTPUT_PATH" ]] || fail "Refusing to overwrite existing transcript: $OUTPUT_PATH"
  mkdir -p "$(dirname "$OUTPUT_PATH")"
fi

cd "$ROOT_DIR"
export APP_ENV="$ENV_NAME"

if [[ -n "$OUTPUT_PATH" ]]; then
  "${cmd[@]}" | tee "$OUTPUT_PATH"
else
  "${cmd[@]}"
fi
