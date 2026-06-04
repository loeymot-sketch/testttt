#!/bin/bash
# OSS latency bench v3 — rate-limit-aware (1 poll / 1.1s = 54 req/min max)
set -u
APIKEY="b6d68vy2-m7g5-20r0-5275-h103w73453q120"
URL="http://127.0.0.1:8000/api/frontend/oss-order?branch_id=1"
HERE="$(cd "$(dirname "$0")" && pwd)"
CHK="$HERE/check_visible.py"
OUT="$HERE/latency-samples.csv"

declare -a ORDERS=(429 430 431 429 430)
declare -a TARGETS=(7 8 7 8 8)

echo "sample,order_id,target_status,t1_flip_ms,t2_visible_ms,delta_ms" > "$OUT"

for i in 0 1 2 3 4; do
  sample=$((i + 1))
  oid=${ORDERS[$i]}
  tgt=${TARGETS[$i]}

  # Reset to ACCEPT (4)
  php artisan tinker --execute="use App\Models\Order; \$o = Order::find($oid); if (\$o) { \$o->status = 4; \$o->save(); }" >/dev/null 2>&1
  sleep 2

  # Capture T1 BEFORE flip
  t1=$(php -r "echo (int)(microtime(true)*1000);")
  php artisan tinker --execute="use App\Models\Order; \$o = Order::find($oid); \$o->status = $tgt; \$o->save();" >/dev/null 2>&1

  # Poll every 1.1s for max 12s (10 polls)
  t2=""
  for j in 1 2 3 4 5 6 7 8 9 10; do
    now=$(php -r "echo (int)(microtime(true)*1000);")
    resp=$(curl -s --max-time 2 "$URL" -H "x-api-key: $APIKEY")
    seen=$(echo "$resp" | python3 "$CHK" "$oid" "$tgt" 2>/dev/null)
    if [ "$seen" = "hit" ]; then
      t2=$now
      break
    fi
    sleep 1.1
  done

  if [ -n "$t2" ]; then
    delta=$((t2 - t1))
  else
    delta="TIMEOUT"
    t2="TIMEOUT"
  fi
  echo "sample=$sample oid=$oid target=$tgt t1=$t1 t2=$t2 delta_ms=$delta"
  echo "$sample,$oid,$tgt,$t1,$t2,$delta" >> "$OUT"
done

echo "---"
cat "$OUT"
