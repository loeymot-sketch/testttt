#!/usr/bin/env bash
# PERF probe — calls each endpoint via curl on the live dev server.
# Captures HTTP status + total time (curl-side, includes network/dev-server overhead).
# Warm cache: 1st call cold, 2nd call warm. Report warm.

set -u
API_KEY="b6d68vy2-m7g5-20r0-5275-h103w73453q120"
ADMIN_TOK="$(cat /tmp/admin.tok)"
KIOSK_TOK="$(cat /tmp/kiosk.tok)"
BASE="http://127.0.0.1:8000"
SINCE="$(php -r "echo date('c', strtotime('-15 minutes'));")"

probe() {
    local label="$1" path="$2" token="$3"
    # Cold
    local c1=$(curl -s -o /dev/null -w "%{http_code}|%{time_total}|%{size_download}" \
        -H "Accept: application/json" -H "x-api-key: $API_KEY" \
        -H "Authorization: Bearer $token" \
        "$BASE$path")
    # Warm (best of 2)
    local w1=$(curl -s -o /dev/null -w "%{http_code}|%{time_total}|%{size_download}" \
        -H "Accept: application/json" -H "x-api-key: $API_KEY" \
        -H "Authorization: Bearer $token" \
        "$BASE$path")
    local w2=$(curl -s -o /dev/null -w "%{http_code}|%{time_total}|%{size_download}" \
        -H "Accept: application/json" -H "x-api-key: $API_KEY" \
        -H "Authorization: Bearer $token" \
        "$BASE$path")
    echo "${label}|cold=${c1}|warm1=${w1}|warm2=${w2}"
}

echo "=== curl perf probe — warm timings (HTTP/total_time_s/bytes) ==="
probe "frontend_menu_kiosk"     "/api/frontend/menu"                        "$KIOSK_TOK"
probe "kds_order_sync"          "/api/admin/kds-order/sync?since=$SINCE"    "$ADMIN_TOK"
probe "kds_order_index"         "/api/admin/kds-order"                      "$ADMIN_TOK"
probe "oss_order_index"         "/api/admin/oss-order"                      "$ADMIN_TOK"
probe "dashboard_total_sales"   "/api/admin/dashboard/total-sales"          "$ADMIN_TOK"
probe "dashboard_realtime"      "/api/admin/dashboard/realtime-report"      "$ADMIN_TOK"
probe "item_index"              "/api/admin/item"                           "$ADMIN_TOK"
probe "pos_order_index"         "/api/admin/pos-order"                      "$ADMIN_TOK"
probe "cash_overview"           "/api/admin/cash-overview"                  "$ADMIN_TOK"
probe "observability_outbox"    "/api/admin/observability/outbox"           "$ADMIN_TOK"
