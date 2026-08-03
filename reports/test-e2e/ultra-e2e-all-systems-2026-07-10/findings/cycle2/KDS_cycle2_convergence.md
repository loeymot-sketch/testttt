# KDS — CYCLE 2 convergence check (2026-07-10)

Scope: verify 2 just-healed KDS issues are GONE + hunt NEW P0/P1. Bash/Read/tinker only.

## Test gate
`php artisan test tests/Feature/Kds` → **47 passed, 0 failure** (8.9s).

## Healed-issue verification (both GONE)

### H1 — empty-board FORCE INDEX regression (was P0: board VIDE in HTTP under BranchScope)
Reverted in commit f959e813b. Verified: list() returns a non-empty, fast board under a
branch-staff user (BranchScope active in real path).
- STAFF branch1 list count=25 overflow=false elapsed_ms=38
- ADMIN(all) list count=25 overflow=false elapsed_ms=15
No FORCE INDEX present in KitchenDisplaySystemOrderService::list (lines 186-199 document the revert).
=> GONE.

### H2 — sync() board-release SSOT (was P1: UNPAID non-cash leaked into delta sync)
Commit 8bb6c00ac applies KitchenReleaseRule::applyBoardReleaseFilter in KdsSyncService::sync.
Reproduced with an UNPAID DELIVERY order at PREPARING (id 5636, pay=10):
- in list(): no
- in sync(): no
- BUMP unpaid blocked: "Transition de statut invalide…"
Excluded from all 3 board paths + non-bumpable. => GONE.

## Real journey (paid POS cash order, id 5635)
ACCEPT(4) -> PREPARING(7) OK -> PREPARED(8) OK ; replay PREPARED no-op OK.
PREPARED -> DELIVERED (served) correctly BLOCKED in KDS (canTransition only allows
ACCEPT->PREPARING, PREPARING->PREPARED; "served" is handled by OSS/delivery, by design).

## Concurrency / isolation probes (all sound)
- changeStatus: DB::transaction + lockForUpdate + expected_status optimistic guard → 409 on
  mismatch, no-op path returns without dispatch. Post-commit notifications wrapped in
  try/catch(\Throwable) so a committed bump never re-wraps to 422/500.
- Idempotency: frontend buildIdempotencyHeaders generates a fresh UUID per bump →
  no per-order key reuse, no bricking of the 2nd transition.
- throttle:kds-bump = 120/min per user — adequate for a kitchen.
- Branch isolation: a tinker `Order::find()` as staff returned a branch-7 row, but this is a
  CONSOLE ARTIFACT — BranchScope::apply line 27 gates on `!App::runningInConsole() || runningUnitTests`
  (both false in tinker → scope skipped). Real HTTP path scopes correctly; feature test
  "recall is branch scoped for branch staff" PASSES. NOT a defect.

## Known P3 (not re-reported — no P0/P1 angle found at current scale)
- Board sort>50 (id ASC truncation) + list()/sync() ordering divergence — branch1 currently
  has 24 windowed-active orders (<50), does not manifest.
- computeOrderVersion = updated_at unix SECONDS (D-03bis TODO): two transitions within the
  same wall-clock second collide on version. Requires sub-second double-bump; documented.
- Grouping raw / recall-window updated_at — documented, unchanged.

## VERDICT
No NEW P0/P1 found. Both just-healed issues verified GONE. KDS = CONVERGED for cycle 2.
