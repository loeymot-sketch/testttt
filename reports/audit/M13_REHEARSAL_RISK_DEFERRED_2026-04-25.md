# M13 Rehearsal Risk Register — CV1-M13-MIGRATIONS-SAFETY

Date: 2026-04-25T21:09:55Z  
Decision type: risk deferred, not accepted as production proof

## Risk

The real staging/full-volume Up/Down/Up migration rehearsal was not executed in this local Codex run. Local validation covered fail-closed behavior, command shape, dry-run help, backup help, sqlite backup manifest hashing, and print-only rehearsal command generation.

## Handling

This risk is explicitly deferred to `CV1-M14-OPS-PREFLIGHT` / human staging environment. M13 may close as a safety tooling and runbook mission, but it must not be used as evidence that staging/full-volume rehearsal has completed.

Production GO remains blocked until M14 or a later gate attaches:

- backup manifest
- dry-run transcript
- staging Up/Down/Up transcript
- exact `branch_id` verification evidence
- rollback sign-off

## Local Evidence Produced

The print-only rehearsal command successfully emitted this sequence:

```text
bash scripts/db/dry-run.sh --env=staging --run --connection=sqlite
php artisan migrate --force --database=sqlite
php artisan migrate:rollback --step=1 --force --database=sqlite
php artisan migrate --force --database=sqlite
```

VERDICT: RISK_DEFERRED_TO_M14_PREFLIGHT
