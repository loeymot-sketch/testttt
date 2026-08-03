# RUNBOOK - Post-Launch Observability Caisse V1 (2026-04-25)

Task: `CV1-M22-POST-LAUNCH-OBSERVABILITY`
Owner: Ops lead with Tech lead backup
Mode: evidence review only; no runtime mutation

## Purpose

Use this runbook after Caisse V1 launch to prove that POS, kiosk, KDS, fiscal, and canary observability are ready for J+1, J+7, and J+30 reviews.

## Preconditions

- M14 ops preflight report exists and shows `summary: failures=0`.
- M15 rollout canary report exists and shows `summary: failures=0`.
- Real production GO remains blocked until the M14 production evidence packet is present.
- No incident responder changes product flags, pricing, order status logic, dispatch behavior, schema, routes, or frontend bundles from this runbook.

## Evidence File Formats

LCP/perf evidence is a key-value file:

```text
pos_lcp_p95_ms=1400
kiosk_lcp_p95_ms=1600
kds_lcp_p95_ms=1300
pos_tti_p95_ms=2800
kiosk_payment_wait_p95_ms=2200
kds_release_latency_p95_ms=900
```

Anomaly evidence is a key-value file:

```text
payment_confirm_without_ability=0
branch_crossover=0
noop_double_trigger=0
fiscal_z_mismatch=0
invalid_seal=0
kds_error_rate=1.2
canary_payment_success_rate=99.1
```

Cadence evidence can be a markdown note, but must explicitly include `J+1`, `J+7`, and `J+30`.

## Read-Only Check

Run the checker before the J+1 review and repeat it for J+7/J+30 with the latest evidence files:

```bash
bash scripts/post-launch-observability-check.sh \
  --m14-preflight-report=reports/ops/m14-preflight-prod.txt \
  --m15-canary-report=reports/ops/m15-canary-prod.txt \
  --lcp-evidence=reports/ops/post-launch-lcp.env \
  --anomaly-evidence=reports/ops/post-launch-anomalies.env \
  --cadence-evidence=reports/ops/post-launch-cadence.md
```

Expected green output:

```text
mode=read-only
summary: failures=0 warnings=0
```

If any required file is absent, the checker fails closed. Do not replace missing evidence with verbal confirmation.

## J+1 Review

1. Run the read-only checker.
2. Confirm POS, kiosk, and KDS LCP evidence exists for each launch branch.
3. Confirm P0 anomaly keys are zero:
   - `payment_confirm_without_ability`
   - `branch_crossover`
   - `fiscal_z_mismatch`
   - `invalid_seal`
4. Confirm canary payment success is at least 95%.
5. Record owner, branch list, evidence paths, and any open P1/P2 anomaly.

## J+7 Review

1. Compare POS/kiosk/KDS p95 trends against J+1.
2. Confirm no branch crossover appeared in exact `branch_id` evidence.
3. Confirm no no-op double trigger caused duplicate payment, fiscal, or KDS side effects.
4. Re-run canary predicates and keep rollback ready if `kds_error_rate > 5` or `canary_payment_success_rate < 95`.
5. Assign remediation owners for non-P0 drift.

## J+30 Review

1. Close the post-mortem with timeline, anomalies, and corrective actions.
2. Decide whether KPI thresholds need a normal planning update.
3. Keep NF525 fiscal anomalies as P0: `fiscal_z_mismatch` and `invalid_seal`.
4. Archive the evidence packet and link it from the launch report.

## P0 Response

Escalate immediately and stop rollout expansion when any of these is true:

- `payment_confirm_without_ability > 0`
- `branch_crossover > 0`
- `fiscal_z_mismatch > 0`
- `invalid_seal > 0`
- `canary_payment_success_rate < 95`

Preserve correlation IDs, branch IDs, actor IDs, and fiscal archive references before remediation.

## Invariant Guard

- Backend pricing remains the only pricing authority.
- Order status changes are not part of this runbook.
- Branch evidence must prove exact `branch_id` isolation.
- This runbook introduces no pre-commit dispatch.
