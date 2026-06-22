#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

usage() {
  cat <<'USAGE'
FoodKing migration backup wrapper.

Fail-closed by default: the script refuses to create a backup unless
--i-understand-backup is present. It never overwrites backup artifacts.

Usage:
  bash scripts/db/backup.sh --env=staging --driver=sqlite --database=/tmp/staging.sqlite --output-dir=storage/app/migration-backups --i-understand-backup
  bash scripts/db/backup.sh --env=staging --driver=mysql --database=foodking --username=foodking --output-dir=storage/app/migration-backups --print-command

Options:
  --env=NAME                    Required. Refuses production unless guarded.
  --driver=sqlite|mysql|pgsql   Defaults to DB_CONNECTION.
  --database=VALUE              Database name or sqlite file. Defaults to DB_DATABASE.
  --host=HOST                   Defaults to DB_HOST.
  --port=PORT                   Defaults to DB_PORT.
  --username=USER               Defaults to DB_USERNAME.
  --password=PASSWORD           Defaults to DB_PASSWORD.
  --output-dir=DIR              Required backup destination directory.
  --print-command               Print the backup command and exit.
  --i-understand-backup         Required to create a backup.
  --allow-production            Production guard, requires --i-understand-production.
  --i-understand-production     Second production guard.
  -h, --help                    Show this help.
USAGE
}

fail() {
  echo "backup: $*" >&2
  exit 2
}

sha256_file() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{print $1}'
  else
    shasum -a 256 "$1" | awk '{print $1}'
  fi
}

json_string() {
  php -r 'echo json_encode($argv[1], JSON_UNESCAPED_SLASHES);' "$1"
}

write_manifest() {
  local manifest="$1"
  local env_name="$2"
  local driver="$3"
  local source="$4"
  local backup_file="$5"
  local sha="$6"
  local created_at="$7"

  cat > "$manifest" <<JSON
{
  "env": $(json_string "$env_name"),
  "driver": $(json_string "$driver"),
  "source": $(json_string "$source"),
  "backup_file": $(json_string "$backup_file"),
  "sha256": $(json_string "$sha"),
  "created_at": $(json_string "$created_at"),
  "restore_required_before_rollback": true
}
JSON
}

quote_command() {
  local item
  for item in "$@"; do
    printf '%q ' "$item"
  done
  printf '\n'
}

ENV_NAME=""
DRIVER="${DB_CONNECTION:-}"
DATABASE="${DB_DATABASE:-}"
HOST="${DB_HOST:-}"
PORT="${DB_PORT:-}"
USERNAME="${DB_USERNAME:-}"
PASSWORD="${DB_PASSWORD:-}"
OUTPUT_DIR=""
PRINT_COMMAND=0
CONFIRM=0
ALLOW_PRODUCTION=0
PRODUCTION_ACK=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env=*) ENV_NAME="${1#--env=}"; shift ;;
    --env) ENV_NAME="${2:?--env requires a value}"; shift 2 ;;
    --driver=*) DRIVER="${1#--driver=}"; shift ;;
    --driver) DRIVER="${2:?--driver requires a value}"; shift 2 ;;
    --database=*) DATABASE="${1#--database=}"; shift ;;
    --database) DATABASE="${2:?--database requires a value}"; shift 2 ;;
    --host=*) HOST="${1#--host=}"; shift ;;
    --host) HOST="${2:?--host requires a value}"; shift 2 ;;
    --port=*) PORT="${1#--port=}"; shift ;;
    --port) PORT="${2:?--port requires a value}"; shift 2 ;;
    --username=*) USERNAME="${1#--username=}"; shift ;;
    --username) USERNAME="${2:?--username requires a value}"; shift 2 ;;
    --password=*) PASSWORD="${1#--password=}"; shift ;;
    --password) PASSWORD="${2:?--password requires a value}"; shift 2 ;;
    --output-dir=*) OUTPUT_DIR="${1#--output-dir=}"; shift ;;
    --output-dir) OUTPUT_DIR="${2:?--output-dir requires a value}"; shift 2 ;;
    --print-command) PRINT_COMMAND=1; shift ;;
    --i-understand-backup) CONFIRM=1; shift ;;
    --allow-production) ALLOW_PRODUCTION=1; shift ;;
    --i-understand-production) PRODUCTION_ACK=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) fail "unknown option: $1" ;;
  esac
done

[[ -n "$ENV_NAME" ]] || fail "Refusing backup without --env."
[[ -n "$DRIVER" ]] || fail "Refusing backup without --driver or DB_CONNECTION."
[[ -n "$DATABASE" ]] || fail "Refusing backup without --database or DB_DATABASE."
[[ -n "$OUTPUT_DIR" ]] || fail "Refusing backup without --output-dir."

if [[ "$ENV_NAME" =~ ^(prod|production)$ ]]; then
  if [[ "$ALLOW_PRODUCTION" -ne 1 || "$PRODUCTION_ACK" -ne 1 ]]; then
    fail "Refusing production backup without --allow-production and --i-understand-production."
  fi
fi

if [[ "$PRINT_COMMAND" -ne 1 && "$CONFIRM" -ne 1 ]]; then
  fail "Refusing to create a backup without --i-understand-backup."
fi

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
if [[ "$PRINT_COMMAND" -ne 1 ]]; then
  mkdir -p "$OUTPUT_DIR"
fi

case "$DRIVER" in
  sqlite)
    [[ -f "$DATABASE" ]] || fail "sqlite database file not found: $DATABASE"
    backup_file="$OUTPUT_DIR/foodking-${ENV_NAME}-${timestamp}.sqlite"
    manifest="$OUTPUT_DIR/backup-${ENV_NAME}-${timestamp}.manifest.json"
    if [[ "$PRINT_COMMAND" -eq 1 ]]; then
      quote_command cp -p "$DATABASE" "$backup_file"
      exit 0
    fi
    [[ ! -e "$backup_file" ]] || fail "Refusing to overwrite backup artifact: $backup_file"
    cp -p "$DATABASE" "$backup_file"
    sha="$(sha256_file "$backup_file")"
    write_manifest "$manifest" "$ENV_NAME" "$DRIVER" "$DATABASE" "$backup_file" "$sha" "$timestamp"
    echo "$manifest"
    ;;
  mysql|mariadb)
    [[ -n "$HOST" ]] || fail "mysql backup requires --host or DB_HOST."
    [[ -n "$USERNAME" ]] || fail "mysql backup requires --username or DB_USERNAME."
    command -v mysqldump >/dev/null 2>&1 || [[ "$PRINT_COMMAND" -eq 1 ]] || fail "mysqldump not found."
    backup_file="$OUTPUT_DIR/foodking-${ENV_NAME}-${timestamp}.sql"
    manifest="$OUTPUT_DIR/backup-${ENV_NAME}-${timestamp}.manifest.json"
    cmd=(mysqldump --single-transaction --routines --triggers --events --host="$HOST" --user="$USERNAME")
    [[ -n "$PORT" ]] && cmd+=(--port="$PORT")
    cmd+=("$DATABASE")
    if [[ "$PRINT_COMMAND" -eq 1 ]]; then
      quote_command "${cmd[@]}"
      exit 0
    fi
    [[ ! -e "$backup_file" ]] || fail "Refusing to overwrite backup artifact: $backup_file"
    MYSQL_PWD="$PASSWORD" "${cmd[@]}" > "$backup_file"
    sha="$(sha256_file "$backup_file")"
    write_manifest "$manifest" "$ENV_NAME" "$DRIVER" "$DATABASE" "$backup_file" "$sha" "$timestamp"
    echo "$manifest"
    ;;
  pgsql|postgres|postgresql)
    [[ -n "$HOST" ]] || fail "pgsql backup requires --host or DB_HOST."
    [[ -n "$USERNAME" ]] || fail "pgsql backup requires --username or DB_USERNAME."
    command -v pg_dump >/dev/null 2>&1 || [[ "$PRINT_COMMAND" -eq 1 ]] || fail "pg_dump not found."
    backup_file="$OUTPUT_DIR/foodking-${ENV_NAME}-${timestamp}.dump"
    manifest="$OUTPUT_DIR/backup-${ENV_NAME}-${timestamp}.manifest.json"
    cmd=(pg_dump --format=custom --host="$HOST" --username="$USERNAME")
    [[ -n "$PORT" ]] && cmd+=(--port="$PORT")
    cmd+=("$DATABASE")
    if [[ "$PRINT_COMMAND" -eq 1 ]]; then
      quote_command "${cmd[@]}"
      exit 0
    fi
    [[ ! -e "$backup_file" ]] || fail "Refusing to overwrite backup artifact: $backup_file"
    PGPASSWORD="$PASSWORD" "${cmd[@]}" > "$backup_file"
    sha="$(sha256_file "$backup_file")"
    write_manifest "$manifest" "$ENV_NAME" "$DRIVER" "$DATABASE" "$backup_file" "$sha" "$timestamp"
    echo "$manifest"
    ;;
  *)
    fail "unsupported driver: $DRIVER"
    ;;
esac
