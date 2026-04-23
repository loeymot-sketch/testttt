#!/usr/bin/env bash
# scripts/check-execute-delegation.sh
# Item B05 méga-checklist — mesure conformité EXECUTE_DELEGATION sentinel sur RUN_*.md
# Mode warn-only ; sera passé en strict (exit 1) après remontée à 80%+

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

has_sentinel() {
  local f="$1"
  if command -v rg >/dev/null 2>&1; then
    rg -q '^EXECUTE_DELEGATION:' "$f" 2>/dev/null && return 0
    return 1
  fi
  grep -qE '^EXECUTE_DELEGATION:' "$f" 2>/dev/null && return 0
  return 1
}

all_run=()
while IFS= read -r f; do
  [[ -n "$f" ]] && all_run+=("$f")
done < <(find reports -type f -name 'RUN_*.md' 2>/dev/null | sort || true)
total=${#all_run[@]}

with=0
for f in "${all_run[@]}"; do
  if has_sentinel "$f"; then
    with=$((with + 1))
  fi
done

if [[ "$total" -eq 0 ]]; then
  echo "EXECUTE_DELEGATION sentinel coverage : 0/0 (N/A — aucun RUN_*.md sous reports/)"
  exit 0
fi

pct=$((with * 100 / total))
echo "EXECUTE_DELEGATION sentinel coverage : $with/$total ($pct%)"

if [[ "$pct" -lt 50 ]]; then
  echo "WARN: coverage < 50% — jusqu'à 5 derniers RUN_*.md (mtime) sans sentinel :" >&2
  lines=()
  for f in "${all_run[@]}"; do
    if has_sentinel "$f"; then
      continue
    fi
    m=$(stat -f %m "$f" 2>/dev/null || stat -c %Y "$f" 2>/dev/null || echo 0)
    lines+=("$m|$f")
  done
  if ((${#lines[@]} > 0)); then
    printf '%s\n' "${lines[@]}" | sort -t'|' -k1,1nr | head -5 | while IFS='|' read -r _ fp; do
      echo "  $fp" >&2
    done
  fi
fi

exit 0
