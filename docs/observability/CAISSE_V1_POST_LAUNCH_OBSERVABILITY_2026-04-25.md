# Caisse V1 Post-Launch Observability - 2026-04-25

Task: `CV1-M22-POST-LAUNCH-OBSERVABILITY`
Mission: `M-22`
Mode: read-only readiness, after `CV1-M14-OPS-PREFLIGHT` and `CV1-M15-ROLLOUT-CANARY`

## Scope

This document consolidates post-launch evidence for Caisse V1 POS, kiosk, KDS, fiscal, and canary monitoring. It does not add runtime collectors, schema, routes, frontend bundles, jobs, or dispatch behavior.

Production GO still requires the M14 production evidence packet before real launch.

## Evidence Packet

The post-launch review packet is complete only when all of these files exist:

| Evidence | Required content | Checker flag |
| --- | --- | --- |
| M14 preflight report | `summary: failures=0` | `--m14-preflight-report=PATH` |
| M15 canary report | `summary: failures=0`, `payment_success_rate` | `--m15-canary-report=PATH` |
| LCP/perf snapshot | POS, kiosk, and KDS p95 LCP keys | `--lcp-evidence=PATH` |
| Anomaly snapshot | all anomaly rule keys below | `--anomaly-evidence=PATH` |
| Review cadence record | explicit `J+1`, `J+7`, and `J+30` owners or outcomes | `--cadence-evidence=PATH` |

Read-only checker:

```bash
bash scripts/post-launch-observability-check.sh \
  --m14-preflight-report=reports/ops/m14-preflight-prod.txt \
  --m15-canary-report=reports/ops/m15-canary-prod.txt \
  --lcp-evidence=reports/ops/post-launch-lcp.env \
  --anomaly-evidence=reports/ops/post-launch-anomalies.env \
  --cadence-evidence=reports/ops/post-launch-cadence.md
```

## KPI Inventory

| Surface | KPI key | Signal | Initial action threshold | Evidence source |
| --- | --- | --- | --- | --- |
| POS | `pos_lcp_p95_ms` | p95 Largest Contentful Paint on cashier route | investigate if sustained above 2500 ms, rollback discussion if above 4000 ms during canary | Lighthouse/field snapshot per branch |
| POS | `pos_tti_p95_ms` | p95 Time to Interactive | investigate if sustained above 5000 ms | field snapshot or browser perf export |
| POS | `pos_client_error_rate` | client errors over 5 minutes | P1 if above 2%, P0 if payment path impacted | observability log / JS error export |
| Kiosk | `kiosk_lcp_p95_ms` | p95 Largest Contentful Paint on customer kiosk flow | investigate if sustained above 2500 ms, P0 if payment step blocks | kiosk browser perf snapshot |
| Kiosk | `kiosk_payment_wait_p95_ms` | p95 wait from payment confirm to accepted terminal state | P1 above 5000 ms, P0 if payment success drops | payment/canary snapshot |
| Kiosk | `kiosk_offline_refusal_count` | refused CB/TR offline attempts | P0 if any offline card/meal-voucher acceptance appears | kiosk observability log |
| KDS | `kds_lcp_p95_ms` | p95 Largest Contentful Paint on KDS display | investigate if sustained above 2500 ms | KDS browser perf snapshot |
| KDS | `kds_release_latency_p95_ms` | order release to visible KDS card | P1 above 3000 ms | KDS sync/observability export |
| KDS | `kds_error_rate` | KDS API/UI errors over 5 minutes | rollback predicate if above 5% | M15 canary metrics |

Backend remains the source of truth for pricing and order lifecycle. These KPI names are observability evidence keys only.

## Anomaly Rules

All anomaly snapshots are branch-scoped. Cross-branch analysis must use exact `branch_id = ?` matching. Prefix, wildcard, `LIKE`, or inferred branch matching is not acceptable evidence.

| Rule key | Severity | Trigger | Expected value in green packet | First response |
| --- | --- | --- | --- | --- |
| `payment_confirm_without_ability` | P0 | a payment confirmation attempt succeeds or reaches mutation logic without the required ability | `0` | freeze rollout, inspect authz logs, preserve correlation ID and actor branch |
| `branch_crossover` | P0 | order, payment, quote, KDS, or fiscal evidence crosses actor branch and record branch | `0` | stop canary, isolate affected branch, audit exact `branch_id` filters |
| `noop_double_trigger` | P1 | repeated operator/customer trigger causes duplicate side effect or unexpected state transition | `0` | inspect idempotency proof and order status transition evidence |
| `fiscal_z_mismatch` | P0 | Z report aggregate does not match sealed fiscal/order evidence for the branch/day | `0` | stop launch, preserve fiscal archive, open fiscal incident |
| `invalid_seal` | P0 | fiscal seal, HMAC, chain, or archive validation fails | `0` | stop launch, quarantine archive, open fiscal incident |
| `kds_error_rate` | P1/P0 | KDS error rate over 5 minutes | `<= 5` | rollback canary if sustained or coupled with preparation delays |
| `canary_payment_success_rate` | P0 | payment success rate over 5 minutes | `>= 95` | rollback canary and inspect payment provider/TPE evidence |

The checker enforces these keys in `scripts/post-launch-observability-check.sh`.

## Cadence

| Review | Timing | Required outcome |
| --- | --- | --- |
| `J+1` | first business day after launch | evidence packet complete, P0 anomaly count confirmed at zero, owner signs launch diary |
| `J+7` | one week after launch | trend review across POS/kiosk/KDS, canary predicates stable, open anomalies triaged |
| `J+30` | one month after launch | post-mortem completed, long-term SLO targets adjusted only through normal planning/gate process |

## Invariant Notes

- `branch_id`: branch crossover is a P0 anomaly and evidence must prove exact branch isolation.
- `dispatch_after_commit`: M-22 adds no jobs, events, queues, or dispatch points.
- `fiscal_NF525`: `fiscal_z_mismatch` and `invalid_seal` are P0 anomalies.
- `frozen_zones`: this mission is docs/runbook/read-only script/test only.
