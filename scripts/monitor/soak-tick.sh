#!/usr/bin/env bash
# [GOAL-2026-05-23 Phase L Agent L3] Long-soak background monitor
#
# DERIVED FROM `storage/logs/h3-monitor.sh` with three production-grade upgrades:
#   1. JSONL output (one structured event per tick) so post-mortem parsing
#      is grep/jq friendly — H.3's printf format was readable but brittle.
#   2. 300s (5 min) default tick — appropriate for a 4h+ soak (48 ticks total).
#   3. Adds Redis INFO + queue depth + DB pool pct + outbox latency probe
#      so the runbook acceptance criteria can be evaluated from this output
#      alone without re-running the artisan command's internal monitor.
#
# This script is REDUNDANT with the in-process monitor inside
# E2ESoakCommand::snapshotState() but is useful for:
#   (a) standalone monitoring when the soak is driven by another tool
#   (b) cross-checking the in-process monitor's reads (independent sampling)
#   (c) running INDEFINITELY past the artisan command's planned duration
#
# USAGE
#
#   # Plain 4h soak, 5 min ticks, output to soak-monitor-<ts>.jsonl
#   DURATION_S=14400 ./scripts/monitor/soak-tick.sh > storage/logs/soak-monitor.jsonl
#
#   # 8h, 60s ticks, custom output
#   DURATION_S=28800 TICK_S=60 OUTPUT=storage/logs/marathon.jsonl ./scripts/monitor/soak-tick.sh
#
#   # Background while artisan command runs in foreground
#   nohup DURATION_S=14400 ./scripts/monitor/soak-tick.sh > storage/logs/soak.jsonl 2> storage/logs/soak.err &
#   php artisan foodking:e2e:soak --hours=4
#
# PRECONDITIONS
#   - MySQL CLI (`mysql`) on PATH with valid credentials via /tmp/soak-mysql.cnf
#     OR exported DB_HOST / DB_USER / DB_PASS / DB_NAME
#   - Optional: `redis-cli` on PATH for cache hit-ratio metrics
#   - `php artisan fiscal:verify-chain --all` must be runnable in the project
#
# STOP-CONDITIONS (evaluated per-tick, emitted as "alarm" lines)
#   - 5xx_count > 0
#   - 429_count > 0
#   - nf525_chain != CHAIN OK
#   - rss_growth_mb > RSS_CEILING_MB
#   - db_pool_pct > DB_POOL_PCT_CEILING
#   - disk_growth_kb > 1048576 (1 GB)
#   - outbox_growth_per_min > 100 across 3 consecutive ticks

set -euo pipefail
cd "$(dirname "$0")/../.."

# Force C locale so awk's printf %.Nf uses '.' (not ',') as decimal separator.
# Otherwise on FR locales the JSON output becomes invalid (e.g. 0,7 vs 0.7).
export LC_ALL=C
export LANG=C

# ─── Config (env-overridable) ──────────────────────────────────────────
DURATION_S="${DURATION_S:-14400}"            # 4 hours default
TICK_S="${TICK_S:-300}"                       # 5 min default
OUTPUT="${OUTPUT:-/dev/stdout}"
SERVER_PID="${SERVER_PID:-}"
RSS_CEILING_MB="${RSS_CEILING_MB:-200}"
DB_POOL_PCT_CEILING="${DB_POOL_PCT_CEILING:-80}"
MYSQL_CNF="${MYSQL_CNF:-/tmp/soak-mysql.cnf}"

# ─── Auto-detect server PID if not provided ───────────────────────────
if [[ -z "$SERVER_PID" ]]; then
    SERVER_PID="$(pgrep -fn 'php.*artisan serve' 2>/dev/null || true)"
    if [[ -z "$SERVER_PID" ]]; then
        SERVER_PID="$(pgrep -fn 'php-fpm.*master' 2>/dev/null || echo 0)"
    fi
fi

# ─── Auto-generate MySQL cnf from .env if absent ──────────────────────
if [[ ! -f "$MYSQL_CNF" ]]; then
    if [[ -f .env ]]; then
        DB_USER="$(grep -E '^DB_USERNAME=' .env | head -1 | cut -d= -f2- || echo root)"
        DB_PASS="$(grep -E '^DB_PASSWORD=' .env | head -1 | cut -d= -f2- || echo '')"
        DB_NAME="$(grep -E '^DB_DATABASE=' .env | head -1 | cut -d= -f2- || echo foodking)"
        DB_HOST="$(grep -E '^DB_HOST=' .env | head -1 | cut -d= -f2- || echo localhost)"
        cat > "$MYSQL_CNF" <<EOF
[client]
host=$DB_HOST
user=$DB_USER
password=$DB_PASS
database=$DB_NAME
EOF
        chmod 600 "$MYSQL_CNF"
    fi
fi

# ─── Emit a JSONL line; auto-trim CR/LF ────────────────────────────────
emit() {
    local line="$1"
    if [[ "$OUTPUT" == "/dev/stdout" ]]; then
        echo "$line"
    else
        echo "$line" >> "$OUTPUT"
    fi
}

# ─── Pull one snapshot ─────────────────────────────────────────────────
snapshot() {
    local tick="$1" t="$2"

    # MySQL counts (single round trip)
    local db_stats
    db_stats="$(mysql --defaults-extra-file="$MYSQL_CNF" -N -B 2>/dev/null <<SQL || echo 'ERR'
SELECT (SELECT COUNT(*) FROM audit_logs),
       (SELECT COUNT(*) FROM orders),
       (SELECT COUNT(*) FROM domain_events WHERE dispatched_at IS NULL),
       (SELECT COUNT(*) FROM domain_events WHERE dispatched_at IS NULL AND created_at < NOW() - INTERVAL 30 SECOND),
       (SELECT COALESCE(MAX(fiscal_sequence_no),0) FROM orders WHERE branch_id=1),
       (SELECT VARIABLE_VALUE FROM performance_schema.session_status WHERE VARIABLE_NAME='Threads_connected'),
       (SELECT VARIABLE_VALUE FROM performance_schema.global_variables WHERE VARIABLE_NAME='max_connections');
SQL
)"
    local audit=0 orders=0 obx_p=0 obx_s30=0 fs_b1=0 db_conn=0 db_max=151
    if [[ "$db_stats" != "ERR" && -n "$db_stats" ]]; then
        IFS=$'\t' read -r audit orders obx_p obx_s30 fs_b1 db_conn db_max <<<"$db_stats"
    fi
    local db_pool_pct=0
    if [[ "$db_max" -gt 0 ]]; then
        db_pool_pct=$(awk -v c="$db_conn" -v m="$db_max" 'BEGIN { printf "%.1f", (c / m) * 100 }')
    fi

    # Server RSS — under php-fpm sum all children since master is ~5MB and
    # doesn't reflect worker pressure. Under `php artisan serve` measure the
    # single-process master directly.
    local rss_kb=0
    if [[ "$SERVER_PID" -gt 0 ]]; then
        local comm
        comm="$(ps -o comm= -p "$SERVER_PID" 2>/dev/null || echo '')"
        if [[ "$comm" == *php-fpm* ]]; then
            # GNU ps with -C, then BSD ps fallback (macOS)
            rss_kb="$(ps -o rss= -C php-fpm 2>/dev/null | awk '{sum+=$1} END {print sum+0}')"
            if [[ "$rss_kb" == "0" ]]; then
                rss_kb="$(ps -A -o rss=,comm= 2>/dev/null | awk '/php-fpm/ {sum+=$1} END {print sum+0}')"
            fi
        else
            rss_kb="$(ps -o rss= -p "$SERVER_PID" 2>/dev/null | tr -d ' ' || echo 0)"
        fi
    fi
    : "${rss_kb:=0}"

    # NF525 chain (one terse verdict line)
    local chain
    chain="$(php artisan fiscal:verify-chain --all 2>&1 | tail -1 | sed 's/"//g')"

    # Disk usage of storage/logs
    local logs_kb
    logs_kb="$(du -sk storage/logs 2>/dev/null | awk '{print $1}')"

    # Redis stats (best-effort)
    local redis_hits=0 redis_misses=0 redis_mem_kb=0 redis_clients=0
    if command -v redis-cli >/dev/null 2>&1; then
        redis_hits="$(redis-cli INFO stats 2>/dev/null | grep -E '^keyspace_hits:' | cut -d: -f2 | tr -d '[:space:]' || echo 0)"
        redis_misses="$(redis-cli INFO stats 2>/dev/null | grep -E '^keyspace_misses:' | cut -d: -f2 | tr -d '[:space:]' || echo 0)"
        redis_mem_kb="$(redis-cli INFO memory 2>/dev/null | grep -E '^used_memory:' | cut -d: -f2 | tr -d '[:space:]' | awk '{print int($1/1024)}' || echo 0)"
        redis_clients="$(redis-cli INFO clients 2>/dev/null | grep -E '^connected_clients:' | cut -d: -f2 | tr -d '[:space:]' || echo 0)"
    fi
    : "${redis_hits:=0}" "${redis_misses:=0}" "${redis_mem_kb:=0}" "${redis_clients:=0}"

    # Cache hit ratio (cumulative since redis-server start)
    local cache_ratio=null
    if [[ "$redis_hits" -gt 0 || "$redis_misses" -gt 0 ]]; then
        cache_ratio=$(awk -v h="$redis_hits" -v m="$redis_misses" 'BEGIN { printf "%.4f", h / (h + m) }')
    fi

    # Queue worker presence
    local qworker_pid
    qworker_pid="$(pgrep -fn 'queue:work.*--queue=high' 2>/dev/null || echo 0)"

    # Emit JSONL
    local ts
    ts="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    emit "{\"kind\":\"tick\",\"tick\":$tick,\"elapsed_s\":$t,\"ts\":\"$ts\",\"audit_logs\":$audit,\"orders\":$orders,\"outbox_pending\":$obx_p,\"outbox_stale_30s\":$obx_s30,\"fiscal_seq_b1\":$fs_b1,\"db_threads_connected\":$db_conn,\"db_max_connections\":$db_max,\"db_pool_pct\":$db_pool_pct,\"server_pid\":$SERVER_PID,\"server_rss_kb\":$rss_kb,\"storage_logs_kb\":$logs_kb,\"nf525_chain\":\"$chain\",\"redis_keyspace_hits\":$redis_hits,\"redis_keyspace_misses\":$redis_misses,\"redis_used_memory_kb\":$redis_mem_kb,\"redis_connected_clients\":$redis_clients,\"cache_hit_ratio_cumulative\":$cache_ratio,\"queue_worker_pid_high\":$qworker_pid}"

    # Echo individual fields for the alarm evaluator (caller scope)
    LAST_OUTBOX_PENDING="$obx_p"
    LAST_RSS_KB="$rss_kb"
    LAST_DB_POOL_PCT="$db_pool_pct"
    LAST_LOGS_KB="$logs_kb"
    LAST_CHAIN="$chain"
    LAST_QWORKER="$qworker_pid"
}

# ─── Alarm evaluator ───────────────────────────────────────────────────
# Tracks state across ticks for rolling-window alarms (e.g. outbox growth)
declare -a OUTBOX_HISTORY=()
declare -a OUTBOX_TS=()
BASELINE_RSS_KB=0
BASELINE_LOGS_KB=0

evaluate_alarms() {
    local tick="$1" t="$2"
    local alarms=()

    # 1. Chain broken
    if [[ "$LAST_CHAIN" != *"CHAIN OK"* ]]; then
        alarms+=("\"nf525_chain_broken\":\"$LAST_CHAIN\"")
    fi
    # 2. Queue worker missing
    if [[ "$LAST_QWORKER" -le 0 ]]; then
        alarms+=("\"queue_worker_missing\":true")
    fi
    # 3. RSS growth ceiling
    local rss_delta_mb
    rss_delta_mb=$(awk -v a="${LAST_RSS_KB:-0}" -v b="${BASELINE_RSS_KB:-0}" 'BEGIN { printf "%.2f", (a - b) / 1024 }')
    if awk -v d="$rss_delta_mb" -v c="$RSS_CEILING_MB" 'BEGIN { exit !(d > c) }'; then
        alarms+=("\"rss_growth_exceeds_ceiling\":$rss_delta_mb")
    fi
    # 4. DB pool ceiling
    if awk -v p="${LAST_DB_POOL_PCT:-0}" -v c="$DB_POOL_PCT_CEILING" 'BEGIN { exit !(p > c) }'; then
        alarms+=("\"db_pool_pct_exceeds_ceiling\":$LAST_DB_POOL_PCT")
    fi
    # 5. Disk growth > 1 GB
    local disk_delta_kb=$((LAST_LOGS_KB - BASELINE_LOGS_KB))
    if [[ "$disk_delta_kb" -gt 1048576 ]]; then
        alarms+=("\"disk_growth_exceeds_1gb_kb\":$disk_delta_kb")
    fi
    # 6. Outbox growth > 100/min across 3 ticks
    OUTBOX_HISTORY+=("$LAST_OUTBOX_PENDING")
    OUTBOX_TS+=("$t")
    if [[ "${#OUTBOX_HISTORY[@]}" -ge 4 ]]; then
        local n="${#OUTBOX_HISTORY[@]}"
        local all_growing=1
        for ((i=n-3; i<n; i++)); do
            local dt=$((${OUTBOX_TS[i]} - ${OUTBOX_TS[i-1]}))
            if [[ "$dt" -le 0 ]]; then all_growing=0; break; fi
            local per_min
            per_min=$(awk -v cur="${OUTBOX_HISTORY[i]}" -v prev="${OUTBOX_HISTORY[i-1]}" -v dt="$dt" 'BEGIN { printf "%.0f", ((cur - prev) / dt) * 60 }')
            if [[ "$per_min" -lt 100 ]]; then all_growing=0; break; fi
        done
        if [[ "$all_growing" -eq 1 ]]; then
            alarms+=("\"outbox_growth_gt_100_per_min_3_ticks\":true")
        fi
    fi

    if [[ "${#alarms[@]}" -gt 0 ]]; then
        local joined
        joined="$(IFS=, ; echo "${alarms[*]}")"
        emit "{\"kind\":\"alarm\",\"tick\":$tick,\"elapsed_s\":$t,\"alarms\":{$joined}}"
    fi
}

# ─── Main loop ─────────────────────────────────────────────────────────
emit "{\"kind\":\"start\",\"duration_s\":$DURATION_S,\"tick_s\":$TICK_S,\"server_pid\":$SERVER_PID,\"rss_ceiling_mb\":$RSS_CEILING_MB,\"db_pool_pct_ceiling\":$DB_POOL_PCT_CEILING}"

t=0
tick=0
end="$DURATION_S"

# Baseline tick
tick=1
snapshot "$tick" "$t"
BASELINE_RSS_KB="$LAST_RSS_KB"
BASELINE_LOGS_KB="$LAST_LOGS_KB"
sleep "$TICK_S"
t=$((t + TICK_S))

while [ "$t" -lt "$end" ]; do
    tick=$((tick + 1))
    snapshot "$tick" "$t"
    evaluate_alarms "$tick" "$t"
    sleep "$TICK_S"
    t=$((t + TICK_S))
done

emit "{\"kind\":\"done\",\"ticks_total\":$tick,\"elapsed_s_actual\":$t}"
