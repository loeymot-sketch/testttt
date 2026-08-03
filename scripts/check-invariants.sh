#!/usr/bin/env bash
# ------------------------------------------------------------------------------
# [POS-9-H.1.7] POS invariants CI guard — source: POS_INVARIANTS_AND_GATES.md §3
# ------------------------------------------------------------------------------
# Runs the six invariant greps as fast fail checks. Any hit => exit 1.
# Designed to be called from:
#   * a pre-push/pre-commit hook
#   * GitHub Actions (.github/workflows/ci.yml)
#   * `composer invariants` (add to composer.json scripts)
#
# Usage:
#   bash scripts/check-invariants.sh          # quiet mode
#   bash scripts/check-invariants.sh -v       # verbose: show context on hit
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

FAILED=0
TOTAL_HITS=0

# run_check <label> <regex> <exclude-regex> <scope...>
run_check() {
    local label="$1"; shift
    local pattern="$1"; shift
    local exclude="$1"; shift
    local -a scope=("$@")

    echo -n "  [${label}] ... "

    local hits
    # -e is required so BSD grep on macOS does not mistake a `->` prefixed
    # pattern for a CLI option.
    hits=$(grep -rEn --include='*.php' -e "$pattern" "${scope[@]}" 2>/dev/null || true)
    if [[ -n "$exclude" ]]; then
        hits=$(echo "$hits" | grep -vE "$exclude" || true)
    fi

    # Strip grep files-not-found style empty lines.
    hits=$(echo "$hits" | grep -v '^$' || true)

    local count=0
    if [[ -n "$hits" ]]; then
        count=$(echo "$hits" | wc -l | tr -d ' ')
    fi

    if [[ $count -eq 0 ]]; then
        echo "${GREEN}OK${NC}"
    else
        echo "${RED}FAIL (${count} hit(s))${NC}"
        FAILED=$((FAILED + 1))
        TOTAL_HITS=$((TOTAL_HITS + count))
        if [[ $VERBOSE -eq 1 ]]; then
            echo "$hits" | head -20 | sed 's/^/      /'
        fi
    fi
}

# filter_aftercommit_wrapped <hits>
#
# For each hit line "path:line:content", inspect the 5 lines preceding
# `line` in `path`. If any of those lines contains `DB::afterCommit(`,
# the hit is considered properly wrapped and is REMOVED from the output.
# Otherwise the hit is kept (genuine invariant violation).
#
# Used by invariant 4/6 to detect manual after-commit wrapping without
# needing per-site `// allow:` comments.
filter_aftercommit_wrapped() {
    local hits="$1"
    [[ -z "$hits" ]] && return 0
    local kept=""
    while IFS= read -r line; do
        [[ -z "$line" ]] && continue
        local file="${line%%:*}"
        local rest="${line#*:}"
        local lineno="${rest%%:*}"
        # Guard: file must exist and lineno must be a positive int.
        if [[ ! -f "$file" ]] || ! [[ "$lineno" =~ ^[0-9]+$ ]] || (( lineno < 1 )); then
            kept+="${line}"$'\n'
            continue
        fi
        local start=$(( lineno - 5 ))
        (( start < 1 )) && start=1
        # Use awk to inspect the window [start, lineno-1].
        local has_wrap
        has_wrap=$(awk -v s="$start" -v e="$((lineno - 1))" \
            'NR>=s && NR<=e && /DB::afterCommit\(/ { found=1 } END { print (found ? "1" : "0") }' \
            "$file")
        if [[ "$has_wrap" != "1" ]]; then
            kept+="${line}"$'\n'
        fi
    done <<< "$hits"
    # Strip trailing newline.
    printf '%s' "${kept%$'\n'}"
}

# filter_dispatchableaftercommit_traits <hits>
#
# Call sites like OrderCreated::dispatch() go through
# app/Events/Concerns/DispatchableAfterCommit (gate C9 / KI-001) which
# defers to connection()->afterCommit when inside a transaction. The
# mechanical grep for "DB::afterCommit(" on the 5 lines above the call
# would still flag these — they are not violations.
#
# If a line dispatches OrderCreated or OrderStatusChanged via ::dispatch and
# the corresponding event class file contains DispatchableAfterCommit, drop
# the hit. (Do NOT use event(new X) for those — that bypasses the trait's
# static dispatch; fix in code, not here.)
filter_dispatchableaftercommit_traits() {
    local hits="$1"
    [[ -z "$hits" ]] && return 0
    local kept=""
    while IFS= read -r line; do
        [[ -z "$line" ]] && continue
        local cont="${line#*:*:}"
        # event(new \App\Events\Order* — must not be ignored by this filter;
        # it must be removed in code.
        if echo "$cont" | grep -qE 'OrderCreated::dispatch|\\\\Events\\\\OrderCreated::dispatch'; then
            if grep -qF 'DispatchableAfterCommit' "$REPO_ROOT/app/Events/OrderCreated.php" 2>/dev/null; then
                continue
            fi
        fi
        if echo "$cont" | grep -qE 'OrderStatusChanged::dispatch|\\\\Events\\\\OrderStatusChanged::dispatch'; then
            if grep -qF 'DispatchableAfterCommit' "$REPO_ROOT/app/Events/OrderStatusChanged.php" 2>/dev/null; then
                continue
            fi
        fi
        kept+="${line}"$'\n'
    done <<< "$hits"
    printf '%s' "${kept%$'\n'}"
}

echo "== POS invariants CI guard (${YELLOW}POS_INVARIANTS_AND_GATES.md §3${NC}) =="

# 1. SSOT pricing — price/total/subtotal must never come from payload in POS layer.
run_check "1/6 SSOT pricing (no payload pricing)" \
    '->input\(.(price|total|subtotal).\)|\$request\[.(price|total|subtotal).\]' \
    '// allow:|_archive/|tests/' \
    app/Http/Controllers/Admin/PosController.php \
    app/Http/Controllers/Admin/PosOrderController.php \
    app/Services/OrderService.php \
    app/Services/Pricing/

# 2. branch_id server-side — never from request payload in ORDER FLOW code.
#    (Admin staff provisioning services that legitimately receive branch_id
#    from an admin form are out of scope — the invariant targets order
#    creation/update leakage, not CRUD for Waiters/Chefs/KioskMachines.)
run_check "2/6 branch_id server-side only" \
    '->input\(.branch_id.\)|\$request->branch_id' \
    '// allow:|_archive/|tests/' \
    app/Http/Controllers/Admin/PosController.php \
    app/Http/Controllers/Admin/PosOrderController.php \
    app/Services/OrderService.php \
    app/Services/FrontendOrderService.php \
    app/Services/KitchenDisplaySystemOrderService.php

# 3. Direct status writes on ORDERS — must go through OrderStateMachine.
#    (KioskMachine.status / User.status / Payment.status are legitimate
#    domain-specific state transitions handled by their own models.)
run_check "3/6 status via OrderStateMachine" \
    "->update\\(\\[[[:space:]]*['\"]status['\"]" \
    "OrderStateMachine|_archive/|tests/|// allow:" \
    app/Services/OrderService.php \
    app/Services/FrontendOrderService.php \
    app/Services/KitchenDisplaySystemOrderService.php \
    app/Http/Controllers/Admin/PosController.php \
    app/Http/Controllers/Admin/PosOrderController.php

# 4. Event broadcast dispatched without afterCommit — scope to App\Events\* broadcast events.
#    V5 #2: FQN (\App\Events\X::dispatch) AND short-name (X::dispatch with `use`).
#    V8 #1: Laravel helpers event(new X(...)) and Event::dispatch(new X(...)).
#    V9 #1: awk post-filter — if DB::afterCommit( appears in the 5 lines above a hit, skip.
#    NOTE 2026-04-23: OrderCreated / OrderStatusChanged use trait DispatchableAfterCommit
#    (app/Events/Concerns/DispatchableAfterCommit.php). Call sites Order*::dispatch()
#    are NOT violations — filter_dispatchableaftercommit_traits drops them. Never use
#    event(new OrderStatusChanged(...)) — that bypasses the trait's static dispatch().
#    Item/Category catalog events use manual DB::afterCommit wrapping (multi-line);
#    invariant 4/6 detects the wrap structurally (no per-site // allow:).
BROADCAST_EVENTS_4_6='OrderCreated|OrderStatusChanged|ItemAvailabilityChanged|ItemCreated|ItemUpdated|ItemDeleted|CategoryCreated|CategoryUpdated|CategoryDeleted'
PATTERN_4_6="(${BROADCAST_EVENTS_4_6})::dispatch\\(|(event\\(new |Event::dispatch\\(new )(${BROADCAST_EVENTS_4_6})\\b"
EXCLUDE_4_6='afterCommit|shouldDispatchAfterCommit|// allow:|use App\\\\Events|DB::afterCommit'
SCOPE_4_6=( app/Services/OrderService.php
            app/Services/FrontendOrderService.php
            app/Services/Menu/AvailabilityService.php
            app/Services/ItemService.php
            app/Services/ItemCategoryService.php
            app/Http/Controllers/Admin/AvailabilityController.php )

echo -n "  [4/6 App\\Events\\* dispatch afterCommit] ... "
raw_hits_4_6=$(grep -rEn --include='*.php' -e "$PATTERN_4_6" "${SCOPE_4_6[@]}" 2>/dev/null || true)
raw_hits_4_6=$(echo "$raw_hits_4_6" | grep -vE "$EXCLUDE_4_6" || true)
raw_hits_4_6=$(echo "$raw_hits_4_6" | grep -v '^$' || true)
filtered_hits_4_6=$(filter_aftercommit_wrapped "$raw_hits_4_6")
filtered_hits_4_6=$(filter_dispatchableaftercommit_traits "$filtered_hits_4_6")
count_4_6=0
[[ -n "$filtered_hits_4_6" ]] && count_4_6=$(echo "$filtered_hits_4_6" | wc -l | tr -d ' ')

if [[ $count_4_6 -eq 0 ]]; then
    echo "${GREEN}OK${NC}"
else
    echo "${RED}FAIL (${count_4_6} hit(s))${NC}"
    FAILED=$((FAILED + 1))
    TOTAL_HITS=$((TOTAL_HITS + count_4_6))
    if [[ $VERBOSE -eq 1 ]]; then
        echo "$filtered_hits_4_6" | head -20 | sed 's/^/      /'
    fi
fi

# 5. EventContract bypass — broadcast() must build & assert envelope.
#    Exclude Concerns/DispatchableAfterCommit.php — the trait overrides
#    broadcast() to wrap after-commit; hits there are structural false positives.
run_check "5/6 EventContract envelope" \
    'broadcast\(' \
    'buildEnvelope|assertEnvelopeValid|// allow:|Concerns/DispatchableAfterCommit' \
    app/Events/

# 6. Sensitive actions without audit log.
run_check "6/6 audit log on sensitive actions" \
    '(OrderCancel|OrderRefund|applyDiscount)' \
    'AuditLog|audit_log|ActionLog|action_log|// allow:' \
    app/Services/

echo
if [[ $FAILED -eq 0 ]]; then
    echo "${GREEN}==> All 6 POS invariants clean.${NC}"
    exit 0
else
    echo "${RED}==> ${FAILED} invariant(s) violated (${TOTAL_HITS} total hit(s)).${NC}"
    echo "    Run with -v to see offending lines."
    echo "    Reference: tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md §3"
    exit 1
fi
