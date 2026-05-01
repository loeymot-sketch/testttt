#!/usr/bin/env bash
# FoodKing legacy route monitor.
# Informative only: findings do not fail this cycle.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR" || exit 1

ROUTES_FILE="routes/api.php"
FOUND=0
REPORT="${TMPDIR:-/tmp}/fk-legacy-routes.$$"

: > "$REPORT" || exit 1

if [ ! -r "$ROUTES_FILE" ]; then
  echo "[FAIL] routes/api.php is not readable" >&2
  rm -f "$REPORT"
  exit 1
fi

first_frontend_match() {
  needle1="$1"
  needle2="$2"
  needle3="$3"
  awk -v needle1="$needle1" -v needle2="$needle2" -v needle3="$needle3" '
    index($0, "Route::prefix('\''frontend'\'')") || index($0, "Route::prefix(\"frontend\")") { in_frontend=1; next }
    in_frontend {
      if ($0 == "});") { exit }
      hit=0
      if (needle1 != "" && index($0, needle1)) { hit=1 }
      if (needle2 != "" && index($0, needle2)) { hit=1 }
      if (needle3 != "" && index($0, needle3)) { hit=1 }
      if (hit) { print NR ":" $0; exit }
    }
  ' "$ROUTES_FILE"
}

record_route() {
  route="$1"
  needle1="$2"
  needle2="$3"
  needle3="$4"
  line="$(first_frontend_match "$needle1" "$needle2" "$needle3")"

  if [ -n "$line" ]; then
    FOUND=1
    lineno="${line%%:*}"
    printf '  %s:%s — %s\n' "$ROUTES_FILE" "$lineno" "$route" >> "$REPORT"
  fi
}

record_route "frontend/item-category" "frontend/item-category" "Route::prefix('item-category')" "Route::prefix(\"item-category\")"
record_route "frontend/item/kiosk-upsell" "frontend/item/kiosk-upsell" "Route::get('/kiosk-upsell')" "Route::get(\"/kiosk-upsell\")"
record_route "frontend/item" "Route::prefix('item')" "Route::prefix(\"item\")" ""

if [ "$FOUND" -eq 1 ]; then
  echo "[INFO] Legacy frontend routes still present:"
  cat "$REPORT"
else
  echo "[INFO] No legacy frontend routes detected in routes/api.php"
fi

rm -f "$REPORT"
exit 0
