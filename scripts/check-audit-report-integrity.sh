#!/usr/bin/env bash
# ------------------------------------------------------------------------------
# [P13_AUDIT_REPORT_HYGIENE / F-VERIFY-10-02] Audit/Verify report integrity guard
# ------------------------------------------------------------------------------
# Refuse de laisser passer un rapport reports/review/AUDIT_*.md (ou VERIFY_*.md
# ou reports/audit-orchestration/*.md) à 0 octet ou quasi-vide. Pattern observé
# le 2026-04-19 : AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md commité vide.
#
# Usage:
#   bash scripts/check-audit-report-integrity.sh          # silencieux si OK
#   bash scripts/check-audit-report-integrity.sh -v       # verbeux
#
# Intégration optionnelle (utilisateur, manuel) : ajouter au pre-commit hook local.
# ------------------------------------------------------------------------------

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

VERBOSE=0
[[ "${1:-}" == "-v" || "${1:-}" == "--verbose" ]] && VERBOSE=1

if [[ -t 1 ]]; then
    RED=$'\033[0;31m'
    GREEN=$'\033[0;32m'
    YELLOW=$'\033[0;33m'
    NC=$'\033[0m'
else
    RED=""; GREEN=""; YELLOW=""; NC=""
fi

MIN_BYTES=200

shopt -s nullglob
AUDIT_FILES=()
for f in \
    reports/review/AUDIT_*.md \
    reports/review/VERIFY_*.md \
    reports/audit-orchestration/*.md
do
    [[ -f "$f" ]] && AUDIT_FILES+=("$f")
done

failures=()

for f in "${AUDIT_FILES[@]}"; do
    # BSD/macOS-safe: wc -c < file (no stat -c)
    bytes=$(wc -c < "$f" | tr -d ' ')
    if [[ "$VERBOSE" -eq 1 ]]; then
        echo "  ${f}  ${bytes} byte(s)"
    fi
    if [[ "$bytes" -lt "$MIN_BYTES" ]]; then
        failures+=("$f (${bytes} bytes < ${MIN_BYTES})")
    fi
done

if [[ ${#failures[@]} -gt 0 ]]; then
    echo "${RED}==> Audit report integrity: FAIL (${#failures[@]} file(s) under ${MIN_BYTES} bytes).${NC}" >&2
    for line in "${failures[@]}"; do
        echo "    ${line}" >&2
    done
    echo "    Reference: F-VERIFY-10-02 / P13_AUDIT_REPORT_HYGIENE" >&2
    exit 1
fi

if [[ "$VERBOSE" -eq 1 ]]; then
    echo "${GREEN}==> All audit/verify reports meet minimum size (${MIN_BYTES} bytes).${NC}"
fi

exit 0
