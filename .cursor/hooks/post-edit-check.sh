#!/usr/bin/env bash
set -e

path="${1:-}"
if [[ -z "$path" ]]; then
  exit 0
fi

# Normalize: strip leading ./
path="${path#./}"

is_frozen_zone() {
  local p="$1"
  case "$p" in
    app/Services/OrderService.php|\
    app/Services/FrontendOrderService.php|\
    app/Services/PaymentService.php|\
    app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php)
      return 0
      ;;
  esac
  [[ "$p" == app/Services/Pricing/* ]] && return 0
  [[ "$p" == database/migrations/* ]] && return 0
  return 1
}

if is_frozen_zone "$path"; then
  echo "[post-edit-check] WARN: $path est en frozen zone — vérifie qu'un LOCK_*.md gate existe avant commit (docs/gates/)."
  exit 0
fi

if [[ "$path" == ".cursor/routing.md" ]]; then
  echo "[post-edit-check] WARN: routing.md modifié — interdiction mid-cycle (rappel B13)."
  exit 0
fi

case "$path" in
  memory/episodes/*.jsonl)
    echo "[post-edit-check] INFO: JSONL mémoire — \`bash scripts/after-execute-memory.sh\` (manifest+check) puis \`bin/graphiti-ingest.sh <préfixe>\` (M01)."
    exit 0
    ;;
esac

echo "[post-edit-check] OK: $path"
exit 0
