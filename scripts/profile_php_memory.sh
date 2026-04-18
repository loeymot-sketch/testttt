#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

OUTPUT_FILE="${1:-reports/execution/php_memory_profile_latest.md}"
mkdir -p "$(dirname "$OUTPUT_FILE")"

BATCH_SCRIPT="$ROOT_DIR/scripts/run_php_feature_batches.sh"

{
  echo "# PHP Memory Profile"
  echo
  echo "**Date**: $(date '+%Y-%m-%d %H:%M:%S')"
  echo
  echo "## Batch Results"
  echo
  for batch in auth-security kiosk-pos-sync admin-seeders-reports; do
    echo "### ${batch}"
    echo
    START_TS=$(date +%s)
    if php -d memory_limit=512M "$BATCH_SCRIPT" "$batch" >/tmp/php_batch_output.txt 2>&1; then
      STATUS="PASS"
    else
      STATUS="FAIL"
    fi
    END_TS=$(date +%s)
    DURATION=$((END_TS - START_TS))
    echo "- Status: ${STATUS}"
    echo "- Duration seconds: ${DURATION}"
    echo "- Command: \`php -d memory_limit=512M scripts/run_php_feature_batches.sh ${batch}\`"
    echo
    sed 's/^/    /' /tmp/php_batch_output.txt
    echo
  done
} >"$OUTPUT_FILE"

echo "Memory profile written to ${OUTPUT_FILE}"
