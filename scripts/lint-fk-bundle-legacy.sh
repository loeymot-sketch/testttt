#!/usr/bin/env bash
# FoodKing Caisse V1 sentinel: production bundle must not contain legacy kiosk/POS prototypes.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR" || exit 1

STRICT_POS_WIZARD="${FK_LEGACY_STRICT_POS_WIZARD:-0}"
ARCHIVE_REPORT="${TMPDIR:-/tmp}/fk-lint-bundle-archive.$$"
POS_REPORT="${TMPDIR:-/tmp}/fk-lint-bundle-pos.$$"

SCAN_DIRS=()
for dir in public/build public/js; do
  if [ -d "$dir" ]; then
    SCAN_DIRS+=("$dir")
  fi
done

if [ "${#SCAN_DIRS[@]}" -eq 0 ]; then
  echo "[skip] no build outputs present (public/build or public/js)"
  exit 0
fi

: > "$ARCHIVE_REPORT" || exit 1
: > "$POS_REPORT" || exit 1

for dir in "${SCAN_DIRS[@]}"; do
  find "$dir" -type f -name '*.js' ! -name '*.LICENSE.txt' \
    -exec grep -lE "kiosk_implementation|borne \\(Remix\\)" {} + >> "$ARCHIVE_REPORT" 2>/dev/null || true

  find "$dir" -type f -name '*.js' ! -name '*.LICENSE.txt' ! -path 'public/js/pos-wizard.js' \
    -exec grep -lE "pos-wizard" {} + >> "$POS_REPORT" 2>/dev/null || true
done

if [ -s "$ARCHIVE_REPORT" ]; then
  echo "[FAIL] archive legacy references found in public build outputs:"
  while IFS= read -r file; do
    printf '%s\n' "$file"
  done < "$ARCHIVE_REPORT"
  rm -f "$ARCHIVE_REPORT" "$POS_REPORT"
  exit 1
fi

if [ -s "$POS_REPORT" ]; then
  if [ "$STRICT_POS_WIZARD" = "1" ]; then
    echo "[FAIL] pos-wizard cutover references found outside public/js/pos-wizard.js:"
  else
    echo "[WARN] pos-wizard cutover references found outside public/js/pos-wizard.js; set FK_LEGACY_STRICT_POS_WIZARD=1 for release gating:"
  fi
  while IFS= read -r file; do
    printf '%s\n' "$file"
  done < "$POS_REPORT"
  rm -f "$ARCHIVE_REPORT" "$POS_REPORT"
  if [ "$STRICT_POS_WIZARD" = "1" ]; then
    exit 1
  fi
  exit 0
fi

rm -f "$ARCHIVE_REPORT" "$POS_REPORT"
echo "[OK] no legacy bundle references in public/build or public/js"
exit 0
