#!/usr/bin/env bash
# FoodKing Caisse V1 sentinel: branch_id must be exact, never LIKE/substr.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

TMP="${TMPDIR:-/tmp}/fk-branch-isolation.$$"
trap 'rm -f "$TMP"' EXIT
: > "$TMP"

grep -RInE \
  "(where|orWhere)\([[:space:]]*['\"]branch_id['\"][[:space:]]*,[[:space:]]*['\"]like['\"]" \
  --include='*.php' \
  --exclude-dir=vendor \
  --exclude-dir=node_modules \
  --exclude-dir=storage \
  --exclude-dir=bootstrap/cache \
  app 2>/dev/null >> "$TMP" || true

if [ -s "$TMP" ]; then
  echo "[FAIL] branch_id LIKE filter found:"
  cat "$TMP"
  exit 1
fi

echo "[OK] no branch_id LIKE filters"
