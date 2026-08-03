#!/usr/bin/env bash
# P3 — Non-blocking audit: recent execution reports should contain EXECUTE_DELEGATION:.
#
# Only scans RUN_*.md modified in the last MAX_AGE_DAYS (default 14) to avoid
# noise on historical reports predating run-cycle Step 2 enforcement.
#
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAX_AGE_DAYS="${MAX_AGE_DAYS:-14}"

cd "${REPO_ROOT}"
dir="reports/execution"
[[ -d "$dir" ]] || { echo "No ${dir}; skip."; exit 0; }

missing=()
while IFS= read -r -d '' f; do
  if ! grep -q 'EXECUTE_DELEGATION:' "$f"; then
    missing+=("$f")
  fi
done < <(find "$dir" -name 'RUN_*.md' -mtime "-${MAX_AGE_DAYS}" -print0 2>/dev/null || true)

n="${#missing[@]}"
if [[ "$n" -gt 0 ]]; then
  echo "::warning::${n} recent execution report(s) (last ${MAX_AGE_DAYS}d) lack EXECUTE_DELEGATION: — see run-cycle.md Step 2/4"
  printf '%s\n' "${missing[@]}" | head -25
  exit 0
fi
echo "OK: all recent RUN_*.md in ${dir} contain EXECUTE_DELEGATION: (or none matched -mtime)."
exit 0
