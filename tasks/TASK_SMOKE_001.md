# Task – SMOKE_001

## Description
Add a single smoke-test comment to `config/app.php` to validate the full autonomous
run-cycle.md protocol (PLAN → EXECUTE → post-execute → VALIDATE → AUDIT → CLOSE).
No logic changes. Comment only.

## Acceptance Criteria
- [x] A single comment line `# smoke-test: run-cycle validated [date]` is added to config/app.php
- [x] No other file is modified
- [x] Full cycle completes with CLOSED status and no open gate

## Scope

**In scope:**
- `config/app.php` — one comment line only

**Explicitly out of scope:**
- `app/` — all services, models, controllers
- `resources/` — all Vue, JS, CSS
- `routes/` — all route files
- `database/` — all migrations, seeders
- All services (OrderService, FrontendOrderService, etc.)

## branch_id Impact
[x] No impact on branch_id data scoping

## Invariants at Risk
[x] None

## Anticipated Gate Conditions
[x] None anticipated

## PRIMARY_MODEL
[x] Composer — routine implementation
Planning and audit: Claude always.

## Status
[ ] Pending plan
[ ] Plan approved
[ ] In execution
[ ] Validation
[ ] Audit
[ ] Gate open — `docs/gates/GATE_SMOKE_001_[DATE].md`
[ ] Closed
