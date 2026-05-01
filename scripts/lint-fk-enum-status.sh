#!/usr/bin/env bash
# FoodKing Caisse V1 sentinel: reject frontend/backend status literals 15/16/17
# outside enum and test files. Expected baseline hit: KioskWaitingComponent.vue status: 16.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR" || exit 1

TMP="${TMPDIR:-/tmp}/fk-enum-status.$$"
: > "$TMP" || exit 1

grep -RnE "status[^a-zA-Z_]*[:=][^a-zA-Z_]*1[567]" resources/js app 2>/dev/null \
  | grep -Ev '(^|/)(tests?|Enums?)/|orderStatusEnum|OrderStatus::|app/Enums/|resources/js/enums/' \
  > "$TMP" || true

if [ -s "$TMP" ]; then
  echo "[FAIL] hardcoded order status literal found; use the authoritative OrderStatus enum:"
  cat "$TMP"
  rm -f "$TMP"
  exit 1
fi

rm -f "$TMP"
echo "[OK] no hardcoded order status literals"
exit 0
