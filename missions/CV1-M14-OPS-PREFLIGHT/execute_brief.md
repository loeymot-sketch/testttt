# Execute Brief — CV1-M14-OPS-PREFLIGHT

Mode: GPT-only, no Claude, no sub-agent.

## Objective

Implement only M-14 ops preflight tooling. The output must make it hard to accidentally claim production readiness without proving the ops runtime.

## Scope

Allowed files:

- `scripts/ops-preflight-caisse-v1.sh`
- `app/Console/Commands/PreflightProductionCommand.php`
- `config/horizon.php`
- `tests/Feature/OpsPreflightCaisseV1Test.php`
- `tests/Feature/AfterCommitDispatchTest.php`
- `tests/Feature/OutboxRescueTest.php`

Do not edit product flows, migrations, routes, frontend resources, order services, fiscal services, `.cursor`, or `AGENTS.md`.

## Requirements

- Add a read-only shell preflight wrapper that fails closed and checks command availability/config for queue, scheduler, workers, broadcast, cache, outbox rescue, fiscal archive, and migration rehearsal evidence.
- Extend `app:preflight-production` only inside `PreflightProductionCommand.php`; avoid direct `env()` runtime drift for checks that should survive `config:cache`.
- If `config/horizon.php` is created, keep it conservative and compatible with a project where Horizon may not be installed.
- Tests must prove fail-closed behavior, missing staging transcript blocks GO, command/help shape, dispatch-after-commit invariant, and outbox rescue availability.
- Keep M13's real staging/full-volume rehearsal risk explicitly deferred to M14/preflight unless a transcript path is supplied and verified.

## Validation

Mandatory:

- `php artisan test --filter=OpsPreflightCaisseV1Test`
- `php artisan test --filter=AfterCommitDispatchTest`
- `php artisan test --filter=OutboxRescueTest`
- `bash scripts/ops-preflight-caisse-v1.sh --help`
- `php artisan app:preflight-production --help`

## Invariants

- Pricing SSOT: N/A, do not touch pricing.
- OrderStatus enum: N/A, do not touch order state transitions.
- branch_id: preflight/runbook must require exact branch leakage checks, not prefix/LIKE.
- dispatch after commit: preserve and test invariant.
- frozen zones: no migration/product frozen file edits.
- OS/FOS symmetry: N/A unless order services are touched, which is off-limits.
