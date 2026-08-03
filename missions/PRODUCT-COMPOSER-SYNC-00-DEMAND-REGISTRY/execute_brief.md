# PRODUCT-COMPOSER-SYNC-00-DEMAND-REGISTRY

## Intent

Archive the full user demand set and make the next implementation work impossible to lose in chat history.
This mission is documentation and orchestration only. It must not edit product code.

## Scope

- Consolidate the ignored/partial demands about dashboard product management, categories, photos, stock, POS/kiosk sync, KDS, queue, payment, and kiosk lock-down.
- Keep the current-state audit explicit: what exists, what is partial, what is missing, and what is blocked by gates.
- Maintain the train plans as the source for future implementation.

## Non-negotiable invariants

- Backend remains pricing SSOT.
- `OrderStatus` enum remains authoritative.
- `branch_id` isolation is mandatory.
- Events/jobs after DB commit.
- Frozen zones stay untouched.
- No self-approved gate.

## Validation

- `git diff --check -- reports/audit/PRODUCT_COMPOSER_SYNC_DEEP_AUDIT_ORCHESTRATION_2026-04-27.md plans/PLAN_PRODUCT_COMPOSER_SYNC_* missions/PRODUCT-COMPOSER-SYNC-00-DEMAND-REGISTRY`
- Manual readback: every user demand category is represented in the audit matrix.

## Exit criteria

- The report names all critical user concerns.
- The master plan points to every train plan.
- Future missions have a single starting point that does not depend on chat memory.
