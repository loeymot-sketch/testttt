# Execute Brief — CV1-M22-POST-LAUNCH-OBSERVABILITY

Mode: GPT-only, no Claude, no sub-agent.

## Objective

Deliver post-launch observability readiness:

- KPI inventory for POS, kiosk, and KDS LCP/perf signals.
- Anomaly rules for payment-confirm without ability, branch crossover, no-op double trigger, fiscal Z mismatch, invalid seal, KDS error rate, and canary payment success.
- J+1 / J+7 / J+30 review cadence.
- Read-only evidence checker that fails closed when required reports are absent.

## Scope

Docs/runbook/script/test only. Do not change product runtime behavior, database schema, services, routes, or frontend bundles.

## Validation

- `php artisan test --filter=PostLaunchObservabilityChecklistTest`
- `bash scripts/post-launch-observability-check.sh --help`

## Invariants

- branch_id: anomaly rules must include branch crossover exactness.
- dispatch_after_commit: observability must not introduce pre-commit dispatch.
- fiscal_NF525: include Z mismatch and invalid seal as P0 anomalies.
- frozen_zones: docs/read-only script only.
