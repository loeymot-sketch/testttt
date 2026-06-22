#!/usr/bin/env bash
# FoodKing Caisse V1 D-01 sentinel: order write paths must not persist client-supplied final totals.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR" || exit 1

REPORT="${TMPDIR:-/tmp}/fk-client-totals.$$"
: > "$REPORT" || exit 1

REQUIRE_CANONICAL=0
if [ "$#" -gt 0 ]; then
  TARGETS=("$@")
else
  REQUIRE_CANONICAL=1
  TARGETS=(
    "app/Services/OrderService.php"
    "app/Services/FrontendOrderService.php"
    "app/Http/Controllers/Admin/PosController.php"
    "app/Http/Controllers/Frontend/OrderController.php"
    "app/Http/Requests/PosOrderRequest.php"
    "app/Http/Requests/OrderRequest.php"
  )
fi

scan_file() {
  local file="$1"
  perl -ne '
    if (
      /\$\w+->(?:total|subtotal|discount|delivery_charge)\s*=\s*\$request(?:->|\[)/
      || /\$\w+\[(["\x27])(?:total|subtotal|discount|delivery_charge)\1\]\s*=\s*\$request(?:->|\[)/
      || /(["\x27])(?:total|subtotal|discount|delivery_charge)\1\s*=>\s*\$request(?:->|\[|->input\s*\()/
    ) {
      print "$ARGV:$.:$_";
    }
  ' "$file" >> "$REPORT"
}

for target in "${TARGETS[@]}"; do
  if [ -f "$target" ]; then
    scan_file "$target"
  elif [ -d "$target" ]; then
    while IFS= read -r -d '' file; do
      scan_file "$file"
    done < <(find "$target" -type f -name '*.php' \
      ! -path '*/vendor/*' \
      ! -path '*/node_modules/*' \
      ! -path '*/storage/*' \
      ! -path '*/bootstrap/cache/*' \
      -print0)
  fi
done

if [ -s "$REPORT" ]; then
  echo "[FAIL] client-supplied final total fields are written from request payload:"
  sed 's/^/  /' "$REPORT"
  rm -f "$REPORT"
  exit 1
fi

if [ "$REQUIRE_CANONICAL" -eq 1 ]; then
  if ! grep -Fq "unset(\$validated['total'], \$validated['subtotal'], \$validated['discount']);" app/Services/OrderService.php; then
    echo "[FAIL] OrderService no longer strips total/subtotal/discount from validated POS/table payloads"
    rm -f "$REPORT"
    exit 1
  fi

  if ! grep -Fq "unset(\$validatedRequest['total'], \$validatedRequest['subtotal'], \$validatedRequest['discount']);" app/Services/FrontendOrderService.php; then
    echo "[FAIL] FrontendOrderService no longer strips total/subtotal/discount from validated frontend/kiosk payloads"
    rm -f "$REPORT"
    exit 1
  fi
fi

rm -f "$REPORT"
echo "[OK] no client-supplied final total writes in order write surfaces"
