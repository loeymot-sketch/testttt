#!/usr/bin/env bash
# pre-compact hook (Cursor cycle)
# Runs automatically before Cursor compacts the conversation context.
# Writes a snapshot of the current cycle state so the agent can resume
# after compaction without re-reading files or losing phase decisions.
#
# Output: reports/compact_snapshot.md (overwritten each time)
# This file should be read FIRST after compaction to restore cycle awareness.

set -uo pipefail

ACTIVE_CYCLE=".cursor/ACTIVE_CYCLE.md"
SNAPSHOT="reports/compact_snapshot.md"

mkdir -p reports

echo "# Compact Snapshot — $(date '+%Y-%m-%d %H:%M:%S')" > "$SNAPSHOT"
echo "" >> "$SNAPSHOT"

# 1. Current cycle state
if [[ -f "$ACTIVE_CYCLE" ]]; then
  TASK_ID=$(grep "^TASK_ID:" "$ACTIVE_CYCLE" | awk '{print $2}' | xargs 2>/dev/null || echo "none")
  PHASE=$(grep "^PHASE:" "$ACTIVE_CYCLE" | awk '{print $2}' | xargs 2>/dev/null || echo "none")
  MODEL=$(grep "^PRIMARY_MODEL:" "$ACTIVE_CYCLE" | awk '{print $2}' | xargs 2>/dev/null || echo "none")
  PLAN=$(grep "^PLAN_FILE:" "$ACTIVE_CYCLE" | awk '{print $2}' | xargs 2>/dev/null || echo "none")
  REPORT=$(grep "^REPORT_FILE:" "$ACTIVE_CYCLE" | awk '{print $2}' | xargs 2>/dev/null || echo "none")
  GATE=$(grep "^GATE_FILE:" "$ACTIVE_CYCLE" | awk '{print $2}' | xargs 2>/dev/null || echo "none")

  echo "## Active Cycle" >> "$SNAPSHOT"
  echo "TASK_ID: $TASK_ID" >> "$SNAPSHOT"
  echo "PHASE: $PHASE" >> "$SNAPSHOT"
  echo "PRIMARY_MODEL: $MODEL" >> "$SNAPSHOT"
  echo "PLAN_FILE: $PLAN" >> "$SNAPSHOT"
  echo "REPORT_FILE: $REPORT" >> "$SNAPSHOT"
  echo "GATE_FILE: $GATE" >> "$SNAPSHOT"
else
  echo "## Active Cycle" >> "$SNAPSHOT"
  echo "No active cycle." >> "$SNAPSHOT"
fi

echo "" >> "$SNAPSHOT"

# 2. Phase completion status from ACTIVE_CYCLE
echo "## Phase Completion" >> "$SNAPSHOT"
if [[ -f "$ACTIVE_CYCLE" ]]; then
  grep -E "^\| (PLAN|EXECUTE|VALIDATE|AUDIT)" "$ACTIVE_CYCLE" >> "$SNAPSHOT" 2>/dev/null || echo "No completion rows found." >> "$SNAPSHOT"
fi

echo "" >> "$SNAPSHOT"

# 3. Last test result
LATEST_LOG="reports/post_execute_latest.log"
if [[ -f "$LATEST_LOG" ]]; then
  echo "## Last Post-Execute Hook" >> "$SNAPSHOT"
  # Extract only the verdict lines (PASSED/FAILED/SKIPPED)
  grep -E "\[post-execute\] (tests|lint|playwright):" "$LATEST_LOG" >> "$SNAPSHOT" 2>/dev/null || echo "No test results." >> "$SNAPSHOT"
fi

echo "" >> "$SNAPSHOT"

# 4. Open gates
echo "## Open Gates" >> "$SNAPSHOT"
if [[ -n "${GATE:-}" && "$GATE" != "none" && -f "$GATE" ]]; then
  echo "Gate file: $GATE" >> "$SNAPSHOT"
  grep -E "^## (Trigger|Decision Required)" "$GATE" >> "$SNAPSHOT" 2>/dev/null || true
else
  echo "No open gate." >> "$SNAPSHOT"
fi

echo "" >> "$SNAPSHOT"
echo "## Resume Instructions" >> "$SNAPSHOT"
echo "After compaction, read ACTIVE_CYCLE.md first, then this snapshot." >> "$SNAPSHOT"
echo "Do NOT re-read plan or report files unless audit requires it." >> "$SNAPSHOT"
echo "Use 3-line phase summaries per context-hygiene.mdc §4." >> "$SNAPSHOT"

echo "[pre-compact] snapshot written to $SNAPSHOT"
