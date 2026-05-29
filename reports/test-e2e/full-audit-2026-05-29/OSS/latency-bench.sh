#!/bin/bash
# OSS latency benchmark: KDS-equivalent status flip -> OSS-equivalent API visible
# T1 = order saved timestamp (DB), T2 = first poll where queue_number visible
# Method: poll /api/frontend/oss-order every 100ms with 10s timeout
set -u
APIKEY="b6d68vy2-m7g5-20r0-5275-h103w73453q120"
URL="http://127.0.0.1:8000/api/frontend/oss-order?branch_id=1"
ORDERS=(429 430 431)
TARGETS=(7 8 7 8 7)  # PREPARING and PREPARED interleaved

mkdir -p $(dirname "$0")
OUT="$(dirname "$0")/latency-samples.csv"
echo "sample,order_id,target_status,t1_flip_ms,t2_visible_ms,delta_ms" > "$OUT"

for sample in 1 2 3 4 5; do
  # Pick order + target
  oid=${ORDERS[$((($sample - 1) % 3))]}
  tgt=${TARGETS[$((sample - 1))]}

  # Reset to ACCEPT first
  php artisan tinker --execute="use App\Models\Order; \$o = Order::find($oid); if (\$o) { \$o->status = 4; \$o->save(); }" >/dev/null 2>&1
  sleep 0.5

  # T1: flip the status (record timestamp BEFORE save in PHP)
  t1=$(php artisan tinker --execute="use App\Models\Order; \$o = Order::find($oid); \$o->status = $tgt; \$ts = microtime(true); \$o->save(); echo number_format(\$ts*1000, 0);" 2>&1 | tail -1)

  # Poll until visible
  bucket="preparing_orders"
  if [ "$tgt" = "8" ]; then bucket="ready_orders"; fi

  t2=""
  for i in $(seq 1 100); do
    now=$(php -r "echo number_format(microtime(true)*1000, 0);")
    resp=$(curl -s "$URL" -H "Accept: application/json" -H "x-api-key: $APIKEY" 2>/dev/null)
    found=$(echo "$resp" | python3 -c "
import sys, json
try:
  d = json.load(sys.stdin)
  data = d.get('data', d)
  prep = data.get('preparing_orders', data.get('preparing', [])) if isinstance(data, dict) else []
  ready = data.get('ready_orders', data.get('prepared', data.get('ready', []))) if isinstance(data, dict) else []
  ids_prep = [str(o.get('id','')) for o in prep]
  ids_ready = [str(o.get('id','')) for o in ready]
  found = '$oid' in (ids_prep if '$tgt' == '7' else ids_ready)
  print('1' if found else '0')
except:
  print('0')
")
    if [ "$found" = "1" ]; then
      t2=$now
      break
    fi
    sleep 0.1
  done

  if [ -n "$t2" ]; then
    delta=$((t2 - t1))
  else
    delta="TIMEOUT"
    t2="TIMEOUT"
  fi

  echo "sample=$sample oid=$oid target=$tgt t1=$t1 t2=$t2 delta_ms=$delta"
  echo "$sample,$oid,$tgt,$t1,$t2,$delta" >> "$OUT"

  sleep 1
done

echo "---"
echo "Samples saved to $OUT"
cat "$OUT"
