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
  # On ne compare que `.files` : `generated_at` change à chaque seconde sinon --check serait bruit.
  cur=$(mktemp)
  build_json > "${cur}"
  if python3 - "$2" "$cur" <<'PY'
import json, sys
p_committed, p_fresh = sys.argv[1], sys.argv[2]
with open(p_committed) as f:
    a = json.load(f).get("files", {})
with open(p_fresh) as f:
    b = json.load(f).get("files", {})
sys.exit(0 if a == b else 1)
PY
  then
    echo "OK: manifest .files (SHA JSONL) matches $2"
    rm -f "${cur}"
    exit 0
  fi
  echo "DIFF: episode hashes/lines in .files differ from $2" >&2
  diff -u "$2" "${cur}" >&2 | head -n 40 || true
  rm -f "${cur}"
  exit 1
fi

tmpf=$(mktemp)
build_json > "${tmpf}"
if [[ -f "$OUT" ]]; then
  if python3 - "$OUT" "$tmpf" <<'PY'
import json, sys
with open(sys.argv[1]) as a, open(sys.argv[2]) as b:
    if json.load(a).get("files") == json.load(b).get("files"):
        sys.exit(0)
sys.exit(1)
PY
  then
    rm -f "$tmpf"
    echo "OK: ${OUT} (SHA JSONL inchangé — génération ignorée, pas de bruit git sur generated_at)"
    exit 0
  fi
fi
mv -f "$tmpf" "${OUT}"
echo "Wrote ${OUT}"
