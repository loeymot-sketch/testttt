# MAX AUDIT POS + GLOBAL RECENT CORRECTIONS

Date: 2026-04-26
Scope: POS, pricing/quote, order lifecycle, outbox/realtime, KDS/Kiosk/OSS sync, tests, gouvernance Phase A.
Mode: revue locale du code + validation ciblee. Aucun fichier produit modifie.

## Verdict

GLOBAL_VERDICT: REWORK_REQUIRED
RELEASE_VERDICT: NOT_VALIDATED
PHASE_B_PLUS_VERDICT: BLOCKED_UNTIL_PHASE_A_SIGNED

Le systeme contient des corrections solides, notamment sur l'outbox K09B et le dispatch apres commit. Mais la zone POS/global ne peut pas etre validee: l'etat git n'est pas gouvernable, les suites globales restent rouges, et plusieurs risques produit restent actifs sur idempotence, isolation branche, queue offline, numerotation de file, paiement et legacy cutover.

## Preuves executees

- `php artisan test --filter='EventContractTest|KioskRealtimeBroadcastTest|AfterCommitDispatchTest'`: PASS, 22 tests.
- `npx vitest run tests/js/kioskOfflineQueue.spec.js tests/js/kioskOfflineQueueMigration.spec.js tests/js/kioskOfflineQueueV2.spec.js`: FAIL, 3 files failed, 6 tests failed, 28 passed.
- `FK_LEGACY_STRICT_POS_WIZARD=1 bash scripts/lint-fk-bundle-legacy.sh strict`: FAIL, references in `public/js/kiosk.js` and `public/js/kiosk-wizard.js`.
- `git status --porcelain`: 111 tracked modified.
- `git status --porcelain -uall`: 684 untracked full-file entries.

## Zones validees

### K09B outbox / realtime contract

Status: VALIDATED_TARGETED

Evidence:
- `app/Domain/Events/EventContract.php:49-58` requires `_origin`, `payment_method`, `queue_number` for `ORDER_CREATED` and `ORDER_STATUS_CHANGED`.
- `app/Listeners/PersistOrderCreatedToOutbox.php` and `app/Listeners/PersistOrderStatusChangedToOutbox.php` persist those keys.
- `app/Jobs/DispatchDomainEventsJob.php` validates the envelope before broadcast and rolls back the claim on failures.
- Targeted tests pass: `EventContractTest`, `KioskRealtimeBroadcastTest`, `AfterCommitDispatchTest`.

Residual risk:
- `EventContract::assertPayloadValid()` only checks key presence, not non-null value. This is acceptable for the current targeted contract, but consumers that require a real `payment_method` should add a stricter assertion.

### POS pricing SSOT primary path

Status: PARTIALLY_VALIDATED_BY_CODE

Evidence:
- `app/Services/OrderService.php:647-679` uses `PricingService::calculateOrder(...)` when `pricing.use_ssot_service` is true.
- Client totals are removed before pricing in `app/Services/OrderService.php:609-610`.
- POS commit requires token and signature via `OrderQuoteService::sealForCommit()` because surface `pos` enters the mandatory quote condition.
- Expired provided quote tokens are rejected by `OrderQuoteService::resolveReplay()` at `app/Services/Order/OrderQuoteService.php:245-258`.

Residual risk:
- The legacy pricing branch remains present and executable when the config flag is false.

### Dispatch after commit

Status: VALIDATED_TARGETED

Evidence:
- Outbox listeners call `DB::afterCommit(...)`.
- `DispatchDomainEventsJob` claims rows transactionally and broadcasts outside the DB transaction.
- Targeted after-commit tests pass.

## Blocking findings

### P0 - Governance/persistence is still not closed

Evidence:
- `reports/audit/PHASE_A_GOVERNANCE_EXECUTION_2026-04-26.md` declares `PHASE_A_VERDICT: STARTED_NOT_CLOSED`.
- Current status: 111 tracked modified, 684 untracked full-file entries.
- `reports/audit/MISSIONS_CLOSED_VS_GIT_2026-04-26.md` declares `CLOSED_VS_GIT_VERDICT: REWORK_NOT_PERSISTED`.
- The untracked set includes mission packets, gates, memory episodes, reports, plans and product/migration files.

Impact:
- A release or global green verdict would be unverifiable. Work can disappear with cleanup, and CLOSED missions are not equivalent to persisted code.

Required correction:
- Finish Phase A before any B.1+ implementation: bucket decisions, atomic commits or explicit discard list, memory policy, gate decisions, single ACTIVE_PRIMARY, and regeneration of CLOSED vs git with 0 REWORK.

### P1 - POS idempotency fallback can return the wrong branch order

Evidence:
- `app/Services/OrderService.php:590-600` scopes the pre-check by `branch_id`.
- But the duplicate-key race fallback uses only `idempotency_key`: `app/Services/OrderService.php:1054-1058`.
- The migration `database/migrations/2026_04_18_140003_scope_idempotency_key_to_branch.php:33-36` creates composite uniqueness on `branch_id,idempotency_key`, so the same idempotency key can exist in several branches.

Impact:
- On a duplicate-key race, `Order::where('idempotency_key', $idempotencyKey)->first()` can return a same-key order from another branch if such rows exist. That violates the branch isolation invariant and can return the wrong receipt/order.

Required correction:
- In the fallback lookup, require the same `branch_id` and ideally the same actor/order source. Add a regression test with two branches sharing the same idempotency key and a duplicate insert race on one branch.

### P1 - POS order show bypasses BranchScope before authorization guard

Evidence:
- `app/Http/Controllers/Admin/PosOrderController.php:55` fetches with `Order::withoutGlobalScope(BranchScope::class)->findOrFail($order)`.
- The service later guards branch visibility at `app/Services/OrderService.php:2135-2145`.
- The route allows `permission:pos-orders|pos` for `show`.

Impact:
- The service guard mitigates full data exposure, but the controller still queries outside scope and can create an existence oracle: cross-branch existing order -> 403, nonexistent order -> 404. It also makes safety depend on every downstream service call staying strict.

Required correction:
- Do not disable `BranchScope` for POS show. Use a scoped query for non-global admins, or perform a single explicit branch-constrained lookup. Add `PosOrderShowCrossBranchSentinelTest`.

### P1 - Kiosk offline queue breaks original idempotency/local keys

Evidence:
- `resources/js/helpers/kioskOfflineQueue.js:95-100` preserves a supplied key only if it starts with `offline_`; otherwise it generates a new `offline_*` key.
- `resources/js/helpers/kioskOfflineQueue.js:335-359` uses that key as the stored local key.
- `resources/js/helpers/kioskOfflineQueue.js:488-492` replays it as `X-Idempotency-Key`.
- Targeted Vitest fails 6 tests, including original key replay, v1 migration key preservation, backoff telemetry key, stale entry lookup, force retry and cancel.

Impact:
- A queued command can replay with a different idempotency key than the original. Stale/cancel/retry operations also fail when UI or migrated data references the original key. This is a product bug, not just a test naming mismatch.

Required correction:
- Preserve the durable idempotency/local key. If `offline_` is required for display/waiting screen safety, store it as a separate offline display/reference id instead of rewriting the idempotency key used for replay and queue operations.

### P1 - Queue number uniqueness still relies on application locking plus unsafe fallback

Evidence:
- `app/Services/OrderService.php:854-883` allocates by cache lock + MAX query.
- On lock timeout, `app/Services/OrderService.php:875-877` generates a microtime-derived fallback.
- `tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php` requires a DB unique guard for `branch_id,queue_number`.
- No migration for a unique `orders(branch_id, queue_number)` guard is present in tracked migrations.

Impact:
- A rare cache lock failure can still create duplicate queue numbers. This is a fiscal/operational correctness risk and a known sentinel blocker.

Required correction:
- Human gate M-13 must decide and approve the DB unique strategy. Then add the migration and adapt collision handling to retry/409 deterministically instead of using a random fallback.

### P1 - PaymentService payment idempotency is too weak for gateway retries

Evidence:
- `app/Services/PaymentService.php:19` returns the first transaction for the order.
- It does not validate `transaction_no`, gateway, amount, sign or type before marking the order paid at `app/Services/PaymentService.php:30-31`.

Impact:
- A gateway retry with the same transaction number is not explicitly idempotent by transaction reference, and a mismatched later callback can be silently accepted as already paid. This is risky for money correctness.

Required correction:
- Make the idempotency key explicit: unique by at least `order_id,type,transaction_no` or gateway provider reference. If an existing transaction has a different gateway/amount/reference, reject or escalate rather than returning it.

### P1 - Kiosk forced branch contract is not aligned with the requested behavior

Evidence:
- `app/Services/Order/OrderQuoteService.php:135-151` resolves kiosk branch from `KioskMachine`.
- But forged `branch_id` payloads are rejected at `app/Services/Order/OrderQuoteService.php:146-149`.
- The audit brief expected the machine branch to win and forged branch payload to be ignored, with invalid token still rejected.

Impact:
- Current behavior is strict and safer than trusting payload, but it does not satisfy the stated product/test contract. This keeps the Kiosk forced-branch suite red.

Required correction:
- Decide the contract. If "machine wins" is the desired behavior, ignore payload `branch_id`, log a security breadcrumb, and always use `KioskMachine.branch_id`.

### P2 - Legacy pricing path remains executable

Evidence:
- `app/Services/OrderService.php:647-679` is the SSOT branch.
- The legacy branch remains active below it when `pricing.use_ssot_service` is false.

Impact:
- The backend SSOT invariant depends on config never flipping. That is not a hard invariant until the legacy path is removed or guarded by a release gate.

Required correction:
- Remove the legacy pricing path for POS, or make the config immutable in production and add a sentinel that fails if it is false.

### P2 - `reorderItems()` returns stale historical prices

Evidence:
- `app/Http/Controllers/Admin/PosOrderController.php:125-158` returns cart lines from stored order items.
- `unit_price`, `total_price`, variation prices and extras are read from historical DB values without a new quote.

Impact:
- Reordering can rehydrate a cart with prices that do not reflect current happy hour, tax, availability or modifier rules. The final POS commit may reject, but the UX and quote contract are inconsistent.

Required correction:
- Return a re-quotable cart only, or immediately call the quote service and return a fresh quote token/signature with current prices.

### P2 - KDS own-branch visibility is not globally validated

Evidence:
- `app/Services/KitchenDisplaySystemOrderService.php` applies a user branch filter for non-global users, but global reports still show `BranchIsolationTest::chef_kds_does_not_leak_other_branch_orders` and `SyncComprehensiveTest::kiosk_order_appears_in_kds` red.
- I did not find a direct branch-leak in the KDS service read path during this pass; the red tests may come from over-restriction, payment status filters, seeded order type, or test data drift.

Impact:
- The sync claim "kiosk order appears in KDS without leaking other branches" is not proven.

Required correction:
- Reproduce only those two tests and fix the root cause without weakening branch filtering.

### P2 - Legacy POS wizard cutover is still not release-clean

Evidence:
- `resources/views/admin-pos-v4.blade.php:120-129` still injects `window.POS_WIZARD_CONFIG` and `public/js/pos-wizard.js`.
- `FK_LEGACY_STRICT_POS_WIZARD=1 bash scripts/lint-fk-bundle-legacy.sh strict` fails on `public/js/kiosk.js` and `public/js/kiosk-wizard.js`.

Impact:
- Option B cutover is not signed/purged. This blocks a strict release gate even if core POS tests pass.

Required correction:
- Human decision: signed shim acceptance or purge. Then make strict linter pass.

### P3 - Hardcoded best sellers fallback remains

Evidence:
- `resources/js/components/admin/pos/PosComponent.vue:954-965` falls back to names `cayenne`, `terminator`, `double cheese`, `tacos l`, `tacos m`.

Impact:
- Not a release blocker by itself, but it is not catalogue-driven and will leak demo/product assumptions into real POS menus.

Required correction:
- If no `is_featured` items exist, show no best sellers or use a backend/admin-managed ranking.

## Test verdict

Current known state from local and recent reports:

- Outbox/K09B targeted: PASS.
- Playwright root from recent report: PASS, 35 passed.
- Full PHPUnit from recent report: FAIL, 44 failed, 8 skipped, 1013 passed.
- Full Vitest from recent report: FAIL, 6 failed tests.
- Offline queue targeted Vitest reproduced now: FAIL, 6 failed.
- Legacy strict lint reproduced now: FAIL.

Therefore: tests do not support a global GREEN verdict.

## Final decision

Do not validate POS/global release.

Recommended next order:
1. Close Phase A governance first; no B.1+ while unsigned.
2. Fix `kioskOfflineQueue` key preservation because it is a concrete reproduced product bug.
3. Fix `OrderService` idempotency fallback branch scoping.
4. Remove `withoutGlobalScope` from POS show and add the cross-branch sentinel.
5. Resolve queue number DB uniqueness through the M-13 gate.
6. Re-run full PHPUnit, full Vitest and strict legacy lint before any release verdict.

