#!/usr/bin/env bash
# FoodKing legacy import guard.
# No set -e: this script reports all configured findings before returning.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR" || exit 1

TARGET_DIR="resources"
FOUND=0

if [ ! -d "$TARGET_DIR" ]; then
  echo "[OK] no legacy imports"
  exit 0
fi

scan_pattern() {
  pattern="$1"
  label="$2"
  tmp="${TMPDIR:-/tmp}/fk-legacy-imports.$$"

  : > "$tmp" || exit 1

  if command -v rg >/dev/null 2>&1; then
    rg -n --no-heading \
      --glob '*.js' \
      --glob '*.vue' \
      --glob '*.ts' \
      --glob '!tests/**' \
      --glob '!public/**' \
      --glob '!node_modules/**' \
      -e "$pattern" "$TARGET_DIR" > "$tmp" 2>/dev/null
    status=$?
    if [ "$status" -gt 1 ]; then
      echo "[FAIL] scanner error — $label" >&2
      rm -f "$tmp"
      exit 1
    fi
  else
    find "$TARGET_DIR" \
      \( -path '*/tests/*' -o -path '*/public/*' -o -path '*/node_modules/*' \) -prune -o \
      \( -name '*.js' -o -name '*.vue' -o -name '*.ts' \) -type f \
      -exec grep -nE "$pattern" {} + > "$tmp" 2>/dev/null
  fi

  if [ -s "$tmp" ]; then
    FOUND=1
    while IFS= read -r line; do
      file="${line%%:*}"
      rest="${line#*:}"
      lineno="${rest%%:*}"
      printf '[FAIL] %s:%s — %s\n' "$file" "$lineno" "$label"
    done < "$tmp"
  fi

  rm -f "$tmp"
}

scan_pattern "from ['\"](.*kiosk_implementation|.*borne \\(Remix\\)|.*pos-wizard)" "legacy import from kiosk_implementation / borne (Remix) / pos-wizard"
scan_pattern "require\\([[:space:]]*['\"](.*kiosk_implementation|.*borne \\(Remix\\)|.*pos-wizard)" "legacy require from kiosk_implementation / borne (Remix) / pos-wizard"

if [ "$FOUND" -eq 1 ]; then
  exit 1
fi

echo "[OK] no legacy imports"
exit 0
