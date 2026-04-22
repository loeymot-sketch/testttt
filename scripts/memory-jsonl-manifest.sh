#!/usr/bin/env bash
# P4 — Memory drift guard: SHA256 of each memory/episodes/*.jsonl → JSON manifest.
#
# Usage:
#   bash scripts/memory-jsonl-manifest.sh              # write reports/memory/jsonl_manifest.json
#   bash scripts/memory-jsonl-manifest.sh --check FILE # exit 1 if current hashes differ from FILE
#
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EP="${REPO_ROOT}/memory/episodes"
OUT="${REPO_ROOT}/reports/memory/jsonl_manifest.json"
mkdir -p "$(dirname "${OUT}")"

if command -v shasum >/dev/null 2>&1; then
  HASH() { shasum -a 256 "$1" | awk '{print $1}'; }
elif command -v sha256sum >/dev/null 2>&1; then
  HASH() { sha256sum "$1" | awk '{print $1}'; }
else
  echo "Need shasum or sha256sum" >&2
  exit 2
fi

build_json() {
  echo '{'
  echo '  "generated_at": "'"$(date -u +%Y-%m-%dT%H:%M:%SZ)"'",'
  echo '  "repo_relative": "memory/episodes/",'
  echo '  "files": {'
  local first=1
  for f in "${EP}"/*.jsonl; do
    [[ -f "$f" ]] || continue
    bn=$(basename "$f")
    h=$(HASH "$f")
    wc=$(wc -l < "$f" | tr -d ' ')
    if [[ $first -eq 0 ]]; then echo ','; fi
    first=0
    printf '    %s: {"sha256": "%s", "lines": %s}' "\"${bn}\"" "${h}" "${wc}"
  done
  echo ''
  echo '  }'
  echo '}'
}

if [[ "${1:-}" == "--check" && -n "${2:-}" ]]; then
  cur=$(mktemp)
  build_json > "${cur}"
  if cmp -s "${cur}" "$2"; then
    echo "OK: manifest matches $2"
    rm -f "${cur}"
    exit 0
  fi
  echo "DIFF: current episodes hashes differ from $2" >&2
  diff -u "$2" "${cur}" >&2 || true
  rm -f "${cur}"
  exit 1
fi

build_json > "${OUT}"
echo "Wrote ${OUT}"
