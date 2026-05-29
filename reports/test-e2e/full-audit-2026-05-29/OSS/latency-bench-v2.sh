#!/bin/bash
# OSS latency benchmark v2: flip→API-visible time. Polls every 100ms with 10s cap.
# Measures backend OSS-data freshness — the frontend polls at 5-60s, so the JS DOM
# update follows by 0-5000ms depending on the poll cycle the WS-disconnect bucket.
# This proves the DATA SOURCE is fresh; the wall-DOM dynamic refresh is bounded
# above by the ossSyncService polling interval (verified in Wave L).
set -u
APIKEY="b6d68vy2-m7g5-20r0-5275-h103w73453q120"
URL="http://127.0.0.1:8000/api/frontend/oss-order?branch_id=1"
ORDERS=(429 430 431 319 318)
TARGETS=(7 8 7 8 7)

OUT="$(dirname "$0")/latency-samples.csv"
echo "sample,order_id,target_status,t1_flip_ms,t2_visible_ms,delta_ms,status_seen" > "$OUT"

for sample in 1 2 3 4 5; do
  oid=${ORDERS[$((sample - 1))]}
  tgt=${TARGETS[$((sample - 1))]}

  # Reset to ACCEPT (4) so the order is NOT visible at baseline
  php artisan tinker --execute="use App\Models\Order; \$o = Order::find($oid); if (\$o) { \$o->status = 4; \$o->save(); }" >/dev/null 2>&1
  sleep 0.4

  # Capture T1 = epoch ms BEFORE we flip
  t1=$(php -r "echo (int) (microtime(true)*1000);")

  # Flip the order
  php artisan tinker --execute="use App\Models\Order; \$o = Order::find($oid); \$o->status = $tgt; \$o->save();" >/dev/null 2>&1

  # Poll loop max 10s
  t2=""
  status_seen=""
  for i in $(seq 1 100); do
    now=$(php -r "echo (int) (microtime(true)*1000);")
    resp=$(curl -s --max-time 1 "$URL" -H "Accept: application/json" -H "x-api-key: $APIKEY" 2>/dev/null)
    status_seen=$(echo "$resp" | python3 -c "
import sys, json
try:
  d = json.load(sys.stdin)
  data = d.get('data', [])
  for o in data:
    if str(o.get('id')) == '$oid':
      print(o.get('status'))
      sys.exit(0)
  print('absent')
except:
  print('parse_err')
")
    if [ "$status_seen" = "$tgt" ]; then
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

  echo "sample=$sample oid=$oid target=$tgt t1=$t1 t2=$t2 delta_ms=$delta status_seen=$status_seen"
  echo "$sample,$oid,$tgt,$t1,$t2,$delta,$status_seen" >> "$OUT"
  sleep 0.5
done

echo "---"
cat "$OUT"
