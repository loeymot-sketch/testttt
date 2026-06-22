#!/usr/bin/env bash
# FoodKing — MASTERPLAY runner (boucle d'exécution Caisse V1)
# Lit plans/masterplay/MASTERPLAY_QUEUE.md, lance codex-extension en série,
# audite (option), ingest mémoire (option), met à jour statut.
#
# Usage:
#   bash scripts/run-masterplay.sh                # boucle simple, sans audit Claude
#   bash scripts/run-masterplay.sh --with-audit   # + audit Claude terminal après chaque mission
#   bash scripts/run-masterplay.sh --with-final   # + GPT final audit
#   bash scripts/run-masterplay.sh --dry-run      # affiche le plan, ne lance rien
#   bash scripts/run-masterplay.sh --max 3        # stop après 3 missions
#
# Stop : Ctrl-C OU `touch reports/masterplay/STOP` OU `touch reports/masterplay/PAUSE`
# -----------------------------------------------------------------------------
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

QUEUE_FILE="plans/masterplay/MASTERPLAY_QUEUE.md"
DISCIPLINE_FILE="plans/masterplay/MASTERPLAY_DISCIPLINE.md"
LOG_DIR="reports/masterplay"
STATUS_FILE="$LOG_DIR/status.json"
STOP_FILE="$LOG_DIR/STOP"
PAUSE_FILE="$LOG_DIR/PAUSE"
INTER_TASK_PAUSE="${MASTERPLAY_PAUSE_SECONDS:-5}"
STOP_ON_REWORK="${MASTERPLAY_STOP_ON_REWORK:-1}"

WITH_AUDIT=0
WITH_FINAL=0
DRY_RUN=0
MAX_TASKS=0  # 0 = unlimited
AUDIT_MODE="terminal"  # terminal | cursor-pending

while [[ $# -gt 0 ]]; do
  case "$1" in
    --with-audit) WITH_AUDIT=1; shift;;
    --with-final) WITH_FINAL=1; shift;;
    --dry-run) DRY_RUN=1; shift;;
    --max) MAX_TASKS="${2:-0}"; shift 2;;
    --audit-mode) AUDIT_MODE="${2:-terminal}"; shift 2;;
    --audit-mode=*) AUDIT_MODE="${1#*=}"; shift;;
    --resume-audit)
      # Usage: --resume-audit <TASK_ID> <PASS|REWORK>
      RESUME_TASK="${2:?Usage: --resume-audit TASK_ID PASS|REWORK}"
      RESUME_VERDICT="${3:?Usage: --resume-audit TASK_ID PASS|REWORK}"
      shift 3
      RESUME_AUDIT=1
      ;;
    -h|--help)
      sed -n '2,16p' "$0"
      exit 0;;
    *)
      echo "[FAIL] Unknown arg: $1"; exit 1;;
  esac
done

mkdir -p "$LOG_DIR"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)"
RUN_LOG="$LOG_DIR/run_${RUN_ID}.log"
echo "[$RUN_ID] MASTERPLAY runner start (with-audit=$WITH_AUDIT mode=$AUDIT_MODE with-final=$WITH_FINAL dry-run=$DRY_RUN max=$MAX_TASKS stop-on-rework=$STOP_ON_REWORK)" | tee -a "$RUN_LOG"

# --resume-audit handler (called by Cursor session after a sub-agent verdict)
if [[ "${RESUME_AUDIT:-0}" -eq 1 ]]; then
  log() { printf '[%s] %s\n' "$(date -u +%H:%M:%SZ)" "$*" | tee -a "$RUN_LOG"; }
  log "RESUME_AUDIT $RESUME_TASK → $RESUME_VERDICT"
  rm -f "$LOG_DIR/audit-pending/$RESUME_TASK.req" 2>/dev/null
  case "$RESUME_VERDICT" in
    PASS)
      # Update queue + ingest memory + close
      tmp="$(mktemp)"
      awk -F'|' -v t="$RESUME_TASK" -v s=" CLOSED " 'BEGIN{OFS="|"} {line=$0; if (line ~ /^\|[[:space:]]*[0-9]+[[:space:]]*\|/) { n=split(line,a,"|"); gsub(/^[[:space:]]+|[[:space:]]+$/,"",a[3]); if (a[3]==t) { a[7]=s; line=""; for(i=1;i<=n;i++) line=line a[i] (i<n?"|":""); } } print line}' "$QUEUE_FILE" > "$tmp" && mv "$tmp" "$QUEUE_FILE"
      bash scripts/after-execute-memory.sh >> "$RUN_LOG" 2>&1 || true
      log "$RESUME_TASK marked CLOSED"
      ;;
    REWORK)
      tmp="$(mktemp)"
      awk -F'|' -v t="$RESUME_TASK" -v s=" REWORK " 'BEGIN{OFS="|"} {line=$0; if (line ~ /^\|[[:space:]]*[0-9]+[[:space:]]*\|/) { n=split(line,a,"|"); gsub(/^[[:space:]]+|[[:space:]]+$/,"",a[3]); if (a[3]==t) { a[7]=s; line=""; for(i=1;i<=n;i++) line=line a[i] (i<n?"|":""); } } print line}' "$QUEUE_FILE" > "$tmp" && mv "$tmp" "$QUEUE_FILE"
      log "$RESUME_TASK marked REWORK"
      ;;
    *) log "[FAIL] Unknown verdict: $RESUME_VERDICT (PASS|REWORK)"; exit 1;;
  esac
  exit 0
fi

if [[ ! -f "$QUEUE_FILE" ]]; then
  echo "[FAIL] Missing $QUEUE_FILE — read $DISCIPLINE_FILE first." | tee -a "$RUN_LOG"
  exit 1
fi

# ----- Helpers -----------------------------------------------------------------

log() { printf '[%s] %s\n' "$(date -u +%H:%M:%SZ)" "$*" | tee -a "$RUN_LOG"; }

write_status() {
  local current="$1" status="$2" extra="${3:-}"
  cat > "$STATUS_FILE" <<JSON
{
  "run_id": "$RUN_ID",
  "current_task": "$current",
  "current_status": "$status",
  "extra": "$extra",
  "with_audit": $WITH_AUDIT,
  "with_final": $WITH_FINAL,
  "ts_utc": "$(date -u +%FT%TZ)"
}
JSON
}

# Parse the queue table; emit lines: ORDER|TASK_ID|MISSION|WAVE|DEPENDS_ON|STATUS|NOTE
parse_queue() {
  awk -F'|' '
    /^\| *0[0-9]+ *\|/ || /^\| *[1-9][0-9]+ *\|/ {
      gsub(/^[ \t]+|[ \t]+$/, "", $2);
      gsub(/^[ \t]+|[ \t]+$/, "", $3);
      gsub(/^[ \t]+|[ \t]+$/, "", $4);
      gsub(/^[ \t]+|[ \t]+$/, "", $5);
      gsub(/^[ \t]+|[ \t]+$/, "", $6);
      gsub(/^[ \t]+|[ \t]+$/, "", $7);
      gsub(/^[ \t]+|[ \t]+$/, "", $8);
      printf "%s|%s|%s|%s|%s|%s|%s\n", $2, $3, $4, $5, $6, $7, $8;
    }' "$QUEUE_FILE"
}

is_closed() {
  # Match on full TASK_ID OR prefix match (e.g. dep "CV1-M01" matches stored "CV1-M01-TRACEABILITY-MATRIX").
  # Acceptable closed states: CLOSED, FINAL_PASS, AUDIT_PASS, EXECUTED (best-effort: deps must have run).
  local task_id="$1"
  parse_queue | awk -F'|' -v t="$task_id" '
    $2 == t || index($2, t"-") == 1 { print $6 }
  ' | grep -qE '^(CLOSED|FINAL_PASS|AUDIT_PASS|EXECUTED)$'
}

deps_satisfied() {
  local depends="$1"
  [[ -z "$depends" || "$depends" == "—" || "$depends" == "-" ]] && return 0
  local dep
  IFS=',' read -ra DEPS <<< "$depends"
  for dep in "${DEPS[@]}"; do
    dep="$(echo "$dep" | sed -E 's/[[:space:]]+|\(.*\)//g')"
    [[ -z "$dep" ]] && continue
    if ! is_closed "$dep"; then
      return 1
    fi
  done
  return 0
}

# Update STATUS column for given TASK_ID. Portable sed for macOS+Linux.
# In dry-run mode, only logs the intent without touching the file.
update_status() {
  local task_id="$1" new_status="$2"
  if [[ $DRY_RUN -eq 1 ]]; then
    log "    (dry-run) would update $task_id → $new_status"
    return 0
  fi
  local tmp
  tmp="$(mktemp)"
  # Preserve the original column widths: only replace the STATUS token, not the surrounding spaces.
  awk -F'|' -v t="$task_id" -v s="$new_status" '
    {
      line = $0
      if (line ~ /^\|[[:space:]]*[0-9]+[[:space:]]*\|/) {
        n = split(line, a, "|")
        tid = a[3]; gsub(/^[[:space:]]+|[[:space:]]+$/, "", tid)
        if (tid == t) {
          # Replace only the trimmed STATUS token, keep the leading/trailing whitespace pattern of the cell.
          # a[7] looks like " PENDING " or " EXECUTED ".
          old = a[7]
          gsub(/^[[:space:]]+|[[:space:]]+$/, "", old)
          # Preserve at least 1 leading + 1 trailing space.
          a[7] = " " s " "
          line = ""
          for (i=1; i<=n; i++) {
            line = line a[i] (i<n ? "|" : "")
          }
        }
      }
      print line
    }' "$QUEUE_FILE" > "$tmp" && mv "$tmp" "$QUEUE_FILE"
}

run_codex_complex() {
  local task_id="$1"
  log "  → codex:complex $task_id"
  if [[ $DRY_RUN -eq 1 ]]; then
    log "    (dry-run) skip"
    return 0
  fi
  set +e
  bash scripts/codex-extension-execute.sh "$task_id" >> "$RUN_LOG" 2>&1
  local rc=$?
  set -e
  local out="missions/$task_id/output_codex.json"
  if [[ ! -s "$out" ]]; then
    log "    [FAIL] $out missing or empty → marking exec as failed"
    if grep -q "is not supported when using Codex with a ChatGPT account" "missions/$task_id/output_codex.raw.log" 2>/dev/null; then
      log "    [HINT] codex rejected the requested model — check CODEX_EXT_MODEL_PRO (must be 'gpt-5.5' on ChatGPT Pro accounts)"
    fi
    return 1
  fi
  return $rc
}

run_claude_audit() {
  local task_id="$1"
  log "  → claude audit $task_id (mode=$AUDIT_MODE)"
  if [[ $DRY_RUN -eq 1 ]]; then
    log "    (dry-run) skip"
    return 0
  fi
  case "$AUDIT_MODE" in
    cursor-pending)
      # Mark the mission as awaiting Cursor sub-agent audit (non-blocking).
      # The Cursor session will batch-audit and then call:
      #   bash scripts/run-masterplay.sh --resume-audit <TASK_ID> <PASS|REWORK>
      mkdir -p "$LOG_DIR/audit-pending"
      echo "$task_id" > "$LOG_DIR/audit-pending/$task_id.req"
      log "    [cursor-pending] marker created → $LOG_DIR/audit-pending/$task_id.req"
      log "    Cursor will audit this mission via foodking-planner-orchestrator sub-agent."
      return 0
      ;;
    terminal|*)
      if ! command -v claude >/dev/null 2>&1; then
        log "    [WARN] claude binary not on PATH — skipping audit (mark FALLBACK)"
        return 2
      fi
      set +e
      bash scripts/foodking-claude-orchestrate.sh context >> "$RUN_LOG" 2>&1
      bash scripts/foodking-claude-orchestrate.sh audit-brief >> "$RUN_LOG" 2>&1
      local rc=$?
      set -e
      return $rc
      ;;
  esac
}

run_codex_final_audit() {
  local task_id="$1"
  log "  → codex:final-audit $task_id"
  if [[ $DRY_RUN -eq 1 ]]; then
    log "    (dry-run) skip"
    return 0
  fi
  set +e
  bash scripts/codex-final-audit.sh "$task_id" >> "$RUN_LOG" 2>&1
  local rc=$?
  set -e
  return $rc
}

ingest_memory() {
  local task_id="$1"
  log "  → after-execute-memory.sh"
  if [[ $DRY_RUN -eq 1 ]]; then return 0; fi
  set +e
  bash scripts/after-execute-memory.sh >> "$RUN_LOG" 2>&1
  set -e
}

# ----- Main loop ---------------------------------------------------------------

bash scripts/agent-activity-log.sh tail 30 >> "$RUN_LOG" 2>&1 || true

processed=0
while true; do
  if [[ -f "$STOP_FILE" ]]; then
    log "STOP file detected → exit"
    rm -f "$STOP_FILE"
    break
  fi
  while [[ -f "$PAUSE_FILE" ]]; do
    log "PAUSE active — sleep 30s (rm $PAUSE_FILE to resume)"
    sleep 30
  done

  # Find next runnable task
  next=""
  while IFS='|' read -r ord task mission wave depends status note; do
    [[ -z "$task" || "$task" == "TASK_ID" ]] && continue
    [[ "$status" != "PENDING" ]] && continue
    if ! deps_satisfied "$depends"; then
      log "  - $task waits for: $depends"
      continue
    fi
    if [[ ! -f "missions/$task/input.json" ]] || [[ ! -f "missions/$task/execute_brief.md" ]]; then
      log "  - $task missing mission files → BLOCKED"
      update_status "$task" "BLOCKED"
      continue
    fi
    next="$task"
    NEXT_NOTE="$note"
    break
  done < <(parse_queue)

  if [[ -z "$next" ]]; then
    log "No runnable PENDING task left — exit loop."
    break
  fi

  log "▶ next: $next ($NEXT_NOTE)"
  write_status "$next" "RUNNING"

  # 1) activity-log start
  alfile="missions/$next/input.json"
  files_csv="$(awk '/"allowlist"/,/]/' "$alfile" | grep -E '"' | grep -v allowlist | sed -E 's/.*"([^"]+)".*/\1/' | tr '\n' ',' | sed 's/,$//')"
  if [[ -z "$files_csv" ]]; then files_csv="missions/$next/"; fi
  set +e
  bash scripts/agent-activity-log.sh start codex-extension "$next" execute "$files_csv" "masterplay-loop" >> "$RUN_LOG" 2>&1
  start_rc=$?
  set -e
  if [[ $start_rc -eq 2 ]]; then
    log "  collision detected → BLOCKED"
    update_status "$next" "BLOCKED"
    continue
  fi

  update_status "$next" "RUNNING"

  # 2) codex exec
  if run_codex_complex "$next"; then
    update_status "$next" "EXECUTED"
  else
    log "  codex exec failed → REWORK"
    update_status "$next" "REWORK"
    bash scripts/agent-activity-log.sh done codex-extension "$next" blocked "codex-exec-failed" >> "$RUN_LOG" 2>&1 || true
    write_status "$next" "REWORK" "codex-exec-failed"
    if [[ "$STOP_ON_REWORK" == "1" ]]; then
      log "  stop-on-rework enabled → exit loop"
      break
    fi
    sleep "$INTER_TASK_PAUSE"
    processed=$((processed+1))
    [[ $MAX_TASKS -gt 0 && $processed -ge $MAX_TASKS ]] && break
    continue
  fi

  # 3) activity-log done (execute phase)
  bash scripts/agent-activity-log.sh done codex-extension "$next" done "codex-executed" >> "$RUN_LOG" 2>&1 || true

  # 4) optional Claude audit
  audit_ok=1
  if [[ $WITH_AUDIT -eq 1 ]]; then
    if run_claude_audit "$next"; then
      update_status "$next" "AUDIT_PASS"
    else
      audit_ok=0
      log "  claude audit returned non-zero → REWORK"
      update_status "$next" "REWORK"
    fi
  fi

  # 5) optional Codex final audit
  if [[ $WITH_FINAL -eq 1 && $audit_ok -eq 1 ]]; then
    if run_codex_final_audit "$next"; then
      update_status "$next" "FINAL_PASS"
    else
      log "  codex final-audit returned non-zero → REWORK"
      update_status "$next" "REWORK"
    fi
  fi

  current_after_audit="$(parse_queue | awk -F'|' -v t="$next" '$2==t{print $6}')"
  if [[ "$current_after_audit" == "REWORK" && "$STOP_ON_REWORK" == "1" ]]; then
    log "  stop-on-rework enabled → exit loop"
    write_status "$next" "REWORK" "audit-failed"
    break
  fi

  # 6) memory ingest + CLOSED (only if both audits enabled and passed, OR no audits at all)
  if [[ $WITH_AUDIT -eq 0 && $WITH_FINAL -eq 0 ]]; then
    log "  no audit gates → leaving status=EXECUTED (mark CLOSED manually after audit)"
  elif [[ $WITH_FINAL -eq 1 && "$(parse_queue | awk -F'|' -v t="$next" '$2==t{print $6}')" == "FINAL_PASS" ]]; then
    ingest_memory "$next"
    update_status "$next" "CLOSED"
  elif [[ $WITH_AUDIT -eq 1 && $WITH_FINAL -eq 0 && "$(parse_queue | awk -F'|' -v t="$next" '$2==t{print $6}')" == "AUDIT_PASS" ]]; then
    ingest_memory "$next"
    update_status "$next" "CLOSED"
  fi

  log "  $next → $(parse_queue | awk -F'|' -v t="$next" '$2==t{print $6}')"
  write_status "$next" "$(parse_queue | awk -F'|' -v t="$next" '$2==t{print $6}')"

  processed=$((processed+1))
  [[ $MAX_TASKS -gt 0 && $processed -ge $MAX_TASKS ]] && { log "MAX reached"; break; }

  sleep "$INTER_TASK_PAUSE"
done

log "MASTERPLAY runner end (processed=$processed)"
exit 0
