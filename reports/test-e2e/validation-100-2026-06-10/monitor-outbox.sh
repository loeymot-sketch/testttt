#!/bin/bash
# Continuous outbox/sync monitor — polls foodking_e2e domain_events every 2s,
# logs each new event with its dispatch latency (occurred_at -> dispatched_at).
# Usage: ./monitor-outbox.sh <output.jsonl> [duration_s]
OUT="${1:-/tmp/monitor-outbox.jsonl}"
DUR="${2:-1800}"
LAST=$(mysql -u root foodking_e2e -N -B -e "SELECT IFNULL(MAX(id),0) FROM domain_events;")
START=$(date +%s)
echo "{\"monitor_start\":\"$(date -Iseconds)\",\"baseline_event\":$LAST}" >> "$OUT"
while [ $(( $(date +%s) - START )) -lt "$DUR" ]; do
  ROWS=$(mysql -u root foodking_e2e -N -B -e "
    SELECT JSON_OBJECT('id',id,'type',event_type,'aggregate',aggregate_id,
      'occurred',DATE_FORMAT(occurred_at,'%H:%i:%s.%f'),
      'dispatched',IFNULL(DATE_FORMAT(dispatched_at,'%H:%i:%s.%f'),'PENDING'),
      'latency_ms',IF(dispatched_at IS NULL,-1,ROUND(TIMESTAMPDIFF(MICROSECOND,occurred_at,dispatched_at)/1000)),
      'attempts',attempts,'err',IFNULL(last_error,''))
    FROM domain_events WHERE id > $LAST ORDER BY id;")
  if [ -n "$ROWS" ]; then
    echo "$ROWS" >> "$OUT"
    LAST=$(mysql -u root foodking_e2e -N -B -e "SELECT IFNULL(MAX(id),0) FROM domain_events;")
  fi
  sleep 2
done
echo "{\"monitor_end\":\"$(date -Iseconds)\",\"last_event\":$LAST}" >> "$OUT"
