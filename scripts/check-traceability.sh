#!/usr/bin/env bash
set -u

MD_FILE="reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md"
CSV_FILE="reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.csv"
EXPECTED_HEADER='FK-ID,Source,Description,Severity,Plan-ID,TASK_ID,Sentinel,Test_Command,Gate,Owner,Status,Evidence'

fail=0

emit_ok() {
  printf 'OK — %s\n' "$1"
}

emit_fail() {
  fail=1
  printf 'FAIL — %s — %s — %s\n' "$1" "$2" "$3"
}

if [ ! -f "$MD_FILE" ]; then
  emit_fail "FILE" "$MD_FILE" "missing markdown matrix"
fi

if [ ! -f "$CSV_FILE" ]; then
  emit_fail "FILE" "$CSV_FILE" "missing csv matrix"
  exit 1
fi

header="$(head -n 1 "$CSV_FILE" | tr -d '\r' | sed 's/^"//; s/"$//; s/","/,/g')"
if [ "$header" = "$EXPECTED_HEADER" ]; then
  emit_ok "CSV header conforme"
else
  emit_fail "R3" "HEADER" "expected $EXPECTED_HEADER"
fi

awk -v expected_header="$EXPECTED_HEADER" '
function parse_csv(line, out,    i, c, n, field, inq, nextc) {
  n = 1
  field = ""
  inq = 0
  for (i = 1; i <= length(line); i++) {
    c = substr(line, i, 1)
    if (inq) {
      if (c == "\"") {
        nextc = substr(line, i + 1, 1)
        if (nextc == "\"") {
          field = field "\""
          i++
        } else {
          inq = 0
        }
      } else {
        field = field c
      }
    } else {
      if (c == "\"") {
        inq = 1
      } else if (c == ",") {
        out[n] = field
        n++
        field = ""
      } else {
        field = field c
      }
    }
  }
  out[n] = field
  return n
}

function valid_plan(plan) {
  if (plan == "?") return 1
  if (plan == "PLAN-04A" || plan == "PLAN-04B") return 1
  if (plan ~ /^PLAN-[0-9][0-9]$/) {
    n = substr(plan, 6, 2) + 0
    return n >= 0 && n <= 22
  }
  return 0
}

function valid_test_cmd(cmd) {
  return cmd == "PREUVE_MANQUANTE" || cmd ~ /^(php artisan test|npm run|npx |bash |python3 |rg |manual:|checklist )/
}

function say_ok(msg) {
  print "OK — " msg
}

function say_fail(rule, fk, reason) {
  failures = 1
  print "FAIL — " rule " — " fk " — " reason
}

BEGIN {
  failures = 0
  expected_cols = 12
  data_rows = 0
}

NR == 1 {
  normalized = $0
  gsub(/\r$/, "", normalized)
  gsub(/^"/, "", normalized)
  gsub(/"$/, "", normalized)
  gsub(/","/, ",", normalized)
  if (normalized != expected_header) {
    say_fail("R3", "HEADER", "header non conforme")
  }
  next
}

{
  gsub(/\r$/, "", $0)
  delete f
  cols = parse_csv($0, f)
  data_rows++
  expected_fk = sprintf("FK-%03d", data_rows)

  if (cols != expected_cols) {
    say_fail("R3", (f[1] ? f[1] : expected_fk), "nb colonnes=" cols " attendu=" expected_cols)
  }

  if (f[1] != expected_fk) {
    say_fail("R3", (f[1] ? f[1] : expected_fk), "FK-ID attendu " expected_fk)
  }

  if (!valid_plan(f[5])) {
    say_fail("R4", f[1], "Plan-ID invalide: " f[5])
  }

  if (f[4] == "P0") {
    if (f[5] == "" || f[5] == "?") {
      say_fail("R1", f[1], "P0 sans Plan-ID")
    }
    if (f[7] == "(none)" && !valid_test_cmd(f[8])) {
      say_fail("R2", f[1], "P0 sans Sentinel ni Test_Command executable ni PREUVE_MANQUANTE")
    }
  }
}

END {
  if (data_rows == 0) {
    say_fail("R3", "CSV", "aucune ligne de donnees")
  } else {
    say_ok("CSV lignes=" data_rows " FK-ID sequentiels")
  }
  if (!failures) {
    say_ok("R1/R2/R3/R4 conformes")
  }
  exit failures
}
' "$CSV_FILE" || fail=1

if grep -q 'TRACEABILITY_STATUS: COMPLETE' "$MD_FILE"; then
  emit_ok "Markdown verdict COMPLETE"
else
  emit_fail "MD" "TRACEABILITY_STATUS" "missing COMPLETE verdict"
fi

md_rows="$(grep -E '^\| FK-[0-9]{3} \|' "$MD_FILE" | wc -l | tr -d ' ')"
csv_rows="$(tail -n +2 "$CSV_FILE" | wc -l | tr -d ' ')"
if [ "$md_rows" = "$csv_rows" ]; then
  emit_ok "Markdown/CSV row count aligned ($csv_rows)"
else
  emit_fail "MDCSV" "ROW_COUNT" "markdown=$md_rows csv=$csv_rows"
fi

exit "$fail"
