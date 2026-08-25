#!/bin/bash
# Aggregate findings across all waves for a given round.
#
# Usage: ./aggregate_findings.sh <run-name> <round-N>
#
# Reads reports/test-e2e/<run-name>/round-<N>/wave-*-findings.json
# Emits a summary line + an exit code:
#   0 = GREEN (open_P0 + open_P1 == 0 across all waves)
#   1 = AMBER (open_P0 == 0 but open_P1 > 0)
#   2 = RED   (open_P0 > 0)
#
# Prints aggregated counts so the orchestrator can decide loop continuation.

set -e

RUN_NAME=${1:?"Usage: $0 <run-name> <round-N>"}
ROUND=${2:?"Usage: $0 <run-name> <round-N>"}

DIR="reports/test-e2e/${RUN_NAME}/round-${ROUND}"

if [ ! -d "$DIR" ]; then
  echo "FATAL: no findings directory at $DIR"
  exit 3
fi

shopt -s nullglob
FILES=("$DIR"/wave-*-findings.json)

if [ ${#FILES[@]} -eq 0 ]; then
  echo "FATAL: no wave-*-findings.json files in $DIR"
  exit 3
fi

echo "=== Aggregating round $ROUND across ${#FILES[@]} waves ==="
echo

TOTAL_P0=0
TOTAL_P1=0
TOTAL_P2=0
TOTAL_P3=0

for f in "${FILES[@]}"; do
  wave=$(jq -r '.wave' "$f")
  verdict=$(jq -r '.verdict' "$f")
  oP0=$(jq -r '.summary.open_P0 // .summary.P0 // 0' "$f")
  oP1=$(jq -r '.summary.open_P1 // .summary.P1 // 0' "$f")
  oP2=$(jq -r '.summary.open_P2 // .summary.P2 // 0' "$f")
  oP3=$(jq -r '.summary.open_P3 // .summary.P3 // 0' "$f")
  printf "  Wave %s : verdict=%-5s open(P0=%d P1=%d P2=%d P3=%d)\n" \
    "$wave" "$verdict" "$oP0" "$oP1" "$oP2" "$oP3"
  TOTAL_P0=$((TOTAL_P0 + oP0))
  TOTAL_P1=$((TOTAL_P1 + oP1))
  TOTAL_P2=$((TOTAL_P2 + oP2))
  TOTAL_P3=$((TOTAL_P3 + oP3))
done

echo
echo "=== Totals across all waves ==="
echo "  open_P0 = $TOTAL_P0   (must be 0 for green)"
echo "  open_P1 = $TOTAL_P1   (must be 0 for green)"
echo "  open_P2 = $TOTAL_P2   (informational)"
echo "  open_P3 = $TOTAL_P3   (informational)"
echo

if [ $TOTAL_P0 -gt 0 ]; then
  echo "VERDICT: RED — orchestrator must spawn fix wave"
  exit 2
elif [ $TOTAL_P1 -gt 0 ]; then
  echo "VERDICT: AMBER — orchestrator must spawn fix wave"
  exit 1
else
  echo "VERDICT: GREEN — orchestrator may move toward convergence"
  exit 0
fi
