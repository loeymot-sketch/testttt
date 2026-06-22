#!/usr/bin/env bash
# tools/check-codebase-drift.sh — Daily anti-drift check cross-codebase
# Crontab example: 0 9 * * * /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tools/check-codebase-drift.sh >> /tmp/codebase-drift.log
# Phase 1.3 ultraplan Le Cayenne — monitoring drift quotidien.
set -euo pipefail

REPO_TESTTTT="/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt"
REPO_WEB="/Users/1millnonstop/Downloads/web"
REPORT_DIR="$REPO_TESTTTT/reports/drift-watch"
DATE=$(date +%Y-%m-%d)
REPORT="$REPORT_DIR/$DATE.md"

mkdir -p "$REPORT_DIR"

{
  echo "# Drift watch — $DATE"
  echo ""
  echo "Generated: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo ""
  echo "## Mobile data/menu.js"
  if [ -f "$REPO_TESTTTT/mobile/data/menu.js" ]; then
    stat -f "mtime: %Sm size: %z bytes" "$REPO_TESTTTT/mobile/data/menu.js"
    wc -l "$REPO_TESTTTT/mobile/data/menu.js"
  else
    echo "MISSING: $REPO_TESTTTT/mobile/data/menu.js"
  fi
  echo ""
  echo "## Web data/menu.js"
  if [ -f "$REPO_WEB/data/menu.js" ]; then
    stat -f "mtime: %Sm size: %z bytes" "$REPO_WEB/data/menu.js"
    wc -l "$REPO_WEB/data/menu.js"
  else
    echo "MISSING: $REPO_WEB/data/menu.js"
  fi
  echo ""
  echo "## Sentinel parity result"
  echo ""
  if command -v node >/dev/null 2>&1; then
    node "$REPO_TESTTTT/tools/sentinel-codebase-parity.mjs" 2>&1 | head -80
  else
    echo "node not found in PATH — sentinel skipped"
  fi
  echo ""
  echo "## Web git status"
  echo ""
  if [ -d "$REPO_WEB/.git" ]; then
    echo "### Last 3 commits"
    git -C "$REPO_WEB" log --oneline 2>/dev/null | head -3 || echo "(no commits readable)"
    echo ""
    echo "### Working tree status (top 10)"
    git -C "$REPO_WEB" status -s 2>/dev/null | head -10 || echo "(status not readable)"
  else
    echo "NOT a git repo (or .git missing): $REPO_WEB"
  fi
  echo ""
  echo "## Testttt git HEAD (context)"
  if [ -d "$REPO_TESTTTT/.git" ]; then
    git -C "$REPO_TESTTTT" log --oneline 2>/dev/null | head -1 || echo "(no commits readable)"
  fi
} > "$REPORT"

echo "Drift watch report: $REPORT"
