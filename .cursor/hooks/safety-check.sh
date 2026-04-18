#!/usr/bin/env bash
# FoodKing – manual safety check
# Run manually before every execution phase.
# Not auto-invoked. No environment variable dependencies.
# Extend FROZEN_ZONES list as the team defines new frozen files.

set -euo pipefail

FROZEN_ZONES=(
  "app/Services/OrderService.php"
  "app/Services/FrontendOrderService.php"
)

STAGED=$(git diff --cached --name-only 2>/dev/null || echo "")

echo "[safety-check] Checking frozen zones..."
for zone in "${FROZEN_ZONES[@]}"; do
  if echo "$STAGED" | grep -q "$zone"; then
    echo "[HALT] Frozen zone staged: $zone — gate clearance required. See docs/gates/"
    exit 1
  fi
done
echo "[safety-check] Frozen zones: OK"

echo "[safety-check] Checking PHP syntax..."
PHP_STAGED=$(echo "$STAGED" | grep '\.php$' || true)
if [[ -n "$PHP_STAGED" ]]; then
  while IFS= read -r file; do
    [[ -f "$file" ]] && php -l "$file" || { echo "[HALT] PHP syntax error: $file"; exit 1; }
  done <<< "$PHP_STAGED"
  echo "[safety-check] PHP syntax: OK"
else
  echo "[safety-check] No staged PHP files."
fi

echo "[safety-check] Passed. Proceed with execution."
exit 0
