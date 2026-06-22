# Execute Brief — CV1-M15-ROLLOUT-CANARY

Mode: GPT-only, no Claude, no sub-agent.

## Objective

Deliver rollout/canary drill tooling only. Do not perform a real rollout and do not change product behavior.

## Scope

Allowed files:

- `config/caisse_v1_rollout.php`
- `scripts/rollout-canary-drill.sh`
- `reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md`
- `tests/Feature/RolloutCanaryDrillTest.php`

## Requirements

- Name the six flags: `payment_ledger_v1`, `pos_revenue_guards`, `kds_strict_release`, `quote_v1`, `fiscal_z_v1`, `kiosk_offline_strict`.
- Define canary phases: pilot branch, 10%, 50%, 100%.
- Define rollback predicates exactly: `payment_success_rate < 95% / 5min`, `fiscal_anomaly > 0`, `kds_error_rate > 5%`.
- Add a read-only drill script that fails closed without M14 preflight report, pilot branch id, and metrics evidence.
- Keep production GO blocked unless M14 evidence says `summary: failures=0` and metrics are within thresholds.

## Validation

- `php artisan test --filter=RolloutCanaryDrillTest`
- `bash scripts/rollout-canary-drill.sh --help`

## Invariants

- No frontend pricing logic.
- No order status changes.
- Canary evidence must include exact `branch_id`, not prefix/LIKE proof.
- No dispatch, migration, route, service, or frontend edit.
