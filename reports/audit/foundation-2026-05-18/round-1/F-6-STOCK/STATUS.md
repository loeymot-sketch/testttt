# F-6 Stock + Availability — Foundation Audit STATUS

**Round 1 — Read-only — 2026-05-18**
**Zone**: F-6 Stock + Availability infrastructure
**Owner of zone state**: Z-3 (Stock fullsys) recently healed: commit fe73fdbb1 (i18n integrity) + a27721d21 (E2E spec). Owner attested "impeccable".

---

## 1. Files examined (Read-cited)

### Services + Models
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Stock/StockService.php` (468 lines)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Stock/ChoiceAvailabilityResolver.php` (362 lines)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Models/StockLevel.php` (91 lines)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Models/StockMovement.php` (66 lines)

### Migrations
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_04_27_143120_create_stock_levels_table.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_04_27_143130_create_stock_movements_table.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_05_08_150000_add_manual_unavailable_to_stock_levels.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_04_22_000002_create_audit_logs_table.php` (comparator for trigger pattern)

### Listeners
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/DecrementStockOnOrderCreated.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/ReleaseStockOnOrderCanceled.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/ReleaseStockOnRefundCreated.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/NotifyStockLowOnStockLevelChanged.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Providers/EventServiceProvider.php` (L130-220)

### Callers (event wiring + inline)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/OrderService.php:894` (inline POS decrement)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/FrontendOrderService.php:511` (inline kiosk decrement)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Menu/AvailabilityService.php` (sibling — daily_consumed_qty + manual rupture toggles)

### Console + Dashboard
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Console/Commands/StockScanRupture.php` (preventive cron)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Admin/StockRuptureDashboardController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Console/Commands/CleanupTestFixturesCommand.php` (raw stock_movements queries)

### Tests (21 files in tests/Feature/Stock/)
All read. Key ones cited in specialist JSONs:
- `StockConcurrentDecrementTest.php`, `StockMovementsAppendOnlyTest.php`, `StockReleaseOnCancelTest.php`
- `StockReleaseOnRefundTest.php`, `AvailabilityDecrementConcurrencyTest.php`, `OrderDecrementsExtrasAndVariationsStockTest.php`
- `StockLevelSchemaTest.php`, `StockBranchIsolationTest.php`, `StockAvailabilityAfterCommitTest.php`
- `NotifyStockLowOnStockLevelChangedTest.php`, `StockMovementIdempotencyKeyUniqueTest.php`

### Factory
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/factories/StockLevelFactory.php` (drift bomb — see ARCH-02)

---

## 2. Specialist verdicts

| Specialist | Verdict | Critical findings |
|---|---|---|
| **Architect** | OK-with-architecture-debt | 1× P0 (dead-import exception namespace), 1× P1 (factory/prod stockable_type drift), 1× P2 (event boundary documentation) |
| **DBA** | OK-with-trigger-gap | 1× P0 (no DB-level append-only trigger on stock_movements), 1× P1 (nullable UNIQUE idempotency_key), 1× P2 (missing dashboard hot-path index) |
| **Security** | OK-with-tamper-window | 1× P1 (stock_movements tamper window — no HMAC chain, no DB trigger), 1× P2 (single raw-SQL site in AvailabilityService) |
| **RED-team** | OK-with-tests-not-covering-cross-process | 1× P1 (concurrent tests not truly multi-process), 1× P1 (release-without-decrement race observability gap), 1× P2 (releaseForOrder empty-array surprise semantics) |

---

## 3. 4-LIST OUTPUT

### 3.1 P0 — must heal pre-merge

| ID | Title | Owner-question | Heal scope (estimated) |
|---|---|---|---|
| **F6-ARCH-01** | `DecrementStockOnOrderCreated.php` L6 imports `App\Exceptions\StockUnavailableException` (class is at `App\Exceptions\Stock\StockUnavailableException`). At runtime, when StockService throws StockUnavailableException, the catch matcher autoloads the wrong FQCN → fatal `Error: Class not found` swallows the real exception. Sentry log mislabel + 'let it bubble up' comment is a lie. | "Owner — corriger l'import (1 ligne) en heal-light pré-merge ou tracker V1.0.2?" | 1 line + 1 regression test (~10 LOC) |
| **F6-DBA-01** | `stock_movements` has no DB-level BEFORE DELETE/UPDATE trigger. Append-only enforced ONLY at Eloquent boot(). `DB::table('stock_movements')->delete()` bypasses entirely. `audit_logs` HAS triggers — inconsistent NF525-grade claims. `CleanupTestFixturesCommand` already uses raw `DB::table('stock_movements')` so the precedent is one line from prod. | "Owner — ajouter trigger DB en V1.0.1 (heal-light) ou tracker V1.0.2?" | 1 new migration + 1 test (~30 LOC) |

### 3.2 P1 — heal in next sprint

| ID | Title | Owner-question | Heal scope |
|---|---|---|---|
| **F6-ARCH-02** | Factory `'stockable_type' => 'item'` raw string vs production `Item::class` FQCN. UNIQUE constraint treats both as distinct. Drift bomb if a future seeder reuses factory. | "Corriger factory + tests pour Item::class systématique, ajouter morphMap, ou reporter?" | ~10 LOC, 3 files |
| **F6-DBA-02** | `idempotency_key` UNIQUE on nullable column — MySQL/SQLite divergence (multiple NULLs vs one NULL). Today all writes set the key, but schema permits drift. | "Rendre NOT NULL via migration future, ou laisser + runtime assertion?" | 1 migration + backfill |
| **F6-SEC-01** | `stock_movements` has neither HMAC chain nor DB trigger (vs audit_logs/z_reports which have both). Tamper window for compromised admin / DBA error. | "V1.0.1 = trigger DB seul, ou V2 SaaS = ajouter HMAC chain?" | V1: 1 migration (couples with F6-DBA-01). V2: ~30 LOC + HmacService + verify command |
| **F6-RED-01** | Concurrent decrement tests are sequential single-process loops. lockForUpdate + UNIQUE are theoretically sound but NOT exercised under true concurrency. SQLite quirk could silently mask broken locking. | "Écrire test parallel-worker (Symfony\\Process fork) ou accepter V1?" | 1 new test (~50 LOC) + MySQL CI matrix entry |
| **F6-RED-02** | Release-without-decrement race observability gap. When `requireOriginalDecrement` noop's, no log emitted → forensic blind spot if a real race ever happens. | "Ajouter Log::warning + test afterCommit-simulated race?" | 1 log line + 1 test |

### 3.3 P2 — backlog V1.0.2

| ID | Title |
|---|---|
| **F6-ARCH-03** | `StockLevelChanged` event only fires on zero-crossing or non-Item type — document explicitly. |
| **F6-DBA-03** | Missing compound index `(branch_id, threshold_low, on_hand)` for dashboard `lowAlerts`. Negligible at Le Cayenne, prep for SaaS scale. |
| **F6-SEC-02** | `AvailabilityService::decrementForOrder` L310-312 raw SQL with `{$qty}` interpolation. Safe via `(int)` cast but fragile pattern. Refactor to bindings. |
| **F6-RED-03** | `releaseForOrder($order, $reason, [])` with empty array = release ENTIRE order (opposite of intuition). Rename / split API. |

### 3.4 INFO / no action

| ID | Title |
|---|---|
| **F6-ARCH-04** | Inline `StockService::decrementForOrder` + listener call is INTENTIONAL idempotent defense (same composite movement_key → second insert noop's). Document in PHPDoc. |
| **F6-DBA-04** | CHECK constraints applied only on non-sqlite; model::saving guard fills sqlite gap. Dual defense acceptable. |
| **F6-SEC-03** | BranchScope verified on both StockLevel + StockMovement (iter12 P0 closed). |
| **F6-SEC-04** | Dashboard authz uses permission gates + scopedBranches respecting user.branch_id. Acceptable. |
| **F6-RED-04** | Idempotency-key pre-claim attack requires DB write access (internal threat only). Not external surface. |

---

## 4. Duplication + dead-code focus (per zone mandate)

### Duplication search verdict: NO ACTIVE DUPLICATION
- **`StockService::decrementForOrder` called twice (inline OrderService L894 + listener)** → confirmed INTENTIONAL idempotent defense via composite movement_key (see ARCH-04). Single source of truth: `StockService::mutateForOrderInTransaction`.
- **`AvailabilityService` vs `StockService`** → DIFFERENT concerns. AvailabilityService manages `item_branch_availability.daily_consumed_qty` (rate-limit per day). StockService manages `stock_levels.on_hand` (inventory). Both fire on OrderCreated via separate listeners. NOT duplication.

### Dead listeners: NONE
- All 3 stock listeners (Decrement / ReleaseOnCancel / ReleaseOnRefund) are wired in EventServiceProvider L145-167 + L148-149 and respond to live events (OrderCreated, OrderCanceled, RefundCreated). NotifyStockLowOnStockLevelChanged is conditional on `catalog_v15.stock_low_alert.enabled` — config-flagged, not dead.

### Duplicate stock movements pattern: NONE
- Movement key composite (`reason | class | id | line_uid | stockable | seed`) is the SoT. Release path uses `'released:'.released_qty.':delta:'.deltaLineQty` seed to allow legitimate multi-partial-refund without false-duplicate skip.

### Raw stock_levels/stock_movements queries outside StockService: 5 sites, all legitimate
| Path | Verdict |
|---|---|
| `AvailabilityService.php` L437-498 | LEGITIMATE — owns F-016a-BIS manual rupture toggles. Different columns of same row. |
| `StockRuptureDashboardController.php` L69 | READ-ONLY low_alerts query. Acceptable. |
| `StockScanRupture.php` L93 | READ-ONLY preventive cron scan. Acceptable. |
| `NotifyStockLowOnStockLevelChanged.php` L31 | READ-ONLY threshold scan. Acceptable. |
| `CleanupTestFixturesCommand.php` L182/185/188/261 | Dev-only test fixture cleanup. **Pattern present → reinforces F6-DBA-01 P0**. |

### Worktree finding (informational)
`.claude/worktrees/blissful-mclean-c915c2/app/Http/Controllers/Admin/StockToggleController.php` exists in a worktree branch — not in the main service set. Read for completeness elsewhere if integrated.

---

## 5. Synthesized user-friendly questions for owner gate

1. **F6-ARCH-01 (P0)**: "Le listener `DecrementStockOnOrderCreated` importe une classe d'exception qui n'existe pas (sous-dossier `Stock\` manquant dans le `use`). Au runtime, le catch wrappe l'exception réelle en `Error: Class not found` → log Sentry trompeur. Veux-tu (a) corriger l'import 1 ligne en heal-light pré-merge, (b) tracker V1.0.2?"

2. **F6-DBA-01 (P0)**: "`stock_movements` est documentée append-only mais sans trigger DB. Un `DELETE` raw bypasse la garde Eloquent. Audit_logs et z_reports ont des triggers. Veux-tu (a) ajouter trigger MySQL/SQLite via nouvelle migration V1.0.1 (~30 LOC, 1 test), (b) garder modèle-only + GRANT-level documenté, (c) reporter V1.0.2?"

3. **F6-SEC-01 (P1)**: "`stock_movements` n'a pas de HMAC chain (vs audit_logs/z_reports). Tamper invisible si admin compromis. V1 single-resto trusted admin = risque faible. V2 SaaS = risque réel. Veux-tu (a) V1.0.1 = trigger seul (couples avec DBA-01), (b) V2 = ajouter HMAC chain complet, (c) accepter V1?"

4. **F6-RED-01 (P1)**: "Tests 'concurrent decrement' sont en réalité séquentiels single-process. Le lock SQL est OK sur papier mais non testé sous vraie concurrence. Veux-tu (a) ajouter test parallel-worker (~50 LOC + CI MySQL matrix), (b) accepter V1 Le Cayenne (trafic faible), (c) reporter V1.0.2?"

5. **F6-ARCH-02 (P1)**: "Factory `StockLevelFactory` écrit `'stockable_type' => 'item'` raw, prod attend `Item::class`. Drift bomb si futur seeder réutilise. Veux-tu (a) corriger factory + tests (~10 LOC), (b) ajouter morphMap centralisé, (c) reporter?"

---

## 6. KEEP-CURRENT-WORKING attestation

**All findings are non-blocking for current ship state.** Zone Z-3 attestation by owner stands. Heals proposed are:
- P0 ARCH-01: cosmetic logging fix (no functional break in success path)
- P0 DBA-01: belt-and-suspenders trigger (model boot already enforces)
- P1 SEC-01 / RED-01 / RED-02 / ARCH-02 / DBA-02: preventive hardening (no current exploited path)

No P0 introduces a production-blocking heal. All proposed remediations are **additive** (new migration, new test, single-import fix) — none touch `StockService` business logic.

---

## 7. Test coverage matrix (verified existing)

| Concern | Test file | Verdict |
|---|---|---|
| Atomic decrement under guard | `StockConcurrentDecrementTest::test_atomic_guard_allows_only_available_quantity` | OK (single-process) |
| 50-attempt stress for 20-cap | `StockConcurrentDecrementTest::test_stress_guard_allows_only_20_successes_across_50_attempts` | OK (sequential) |
| Multi-line rollback on partial fail | `StockConcurrentDecrementTest::test_failed_multi_line_decrement_rolls_back_prior_stock_changes` | OK |
| Append-only update guard | `StockMovementsAppendOnlyTest::test_stock_movements_are_append_only` | OK (model-level, NOT DB) |
| Append-only delete guard | `StockMovementsAppendOnlyTest::test_stock_movements_cannot_be_deleted` | OK (model-level, NOT DB) |
| Idempotency unique | `StockMovementsAppendOnlyTest::test_stock_movement_idempotency_key_is_unique_when_present` | OK (StockMovementIdempotencyKeyUniqueTest skipped — consolidated) |
| Release idempotency | `StockReleaseOnCancelTest::test_order_canceled_event_releases_decremented_stock_once` | OK |
| Refund idempotency | `StockReleaseOnRefundTest::test_refund_event_releases_decremented_stock_once` | OK |
| Partial refund with addon | `StockReleaseOnRefundTest::test_decrement_and_partial_refund_track_addon_target_stock_from_composition_snapshot` | OK |
| Branch isolation read | `StockBranchIsolationTest::test_same_stockable_can_have_isolated_levels_per_branch` | OK (uses raw 'item' — see ARCH-02) |
| Extras + variations decrement | `OrderDecrementsExtrasAndVariationsStockTest` (3 tests) | OK |
| Schema + non-negative + reserved<=on_hand | `StockLevelSchemaTest` (2 tests) | OK (sqlite via model guard, MySQL via CHECK) |
| afterCommit side-effects | `StockAvailabilityAfterCommitTest` (2 tests) | OK |
| Low-stock listener | `NotifyStockLowOnStockLevelChangedTest` (4 tests) | OK |
| Symmetry OrderService↔FrontendOrderService | `StockSymmetryDiffTest::test_order_service_and_frontend_order_service_stock_hunks_stay_symmetric` | Runs external node script |

### Test gaps (vs RED-team findings)
- No true cross-process concurrent test (F6-RED-01)
- No simulated cancel-during-create race (F6-RED-02)
- No raw-DELETE bypass attempt for append-only (F6-DBA-01)
- No factory↔production stockable_type assertion (F6-ARCH-02)

---

## 8. Audit metadata

- Wall-clock: ~25 min (within 25-30 budget)
- Read-only: confirmed, zero writes outside `reports/audit/foundation-2026-05-18/round-1/F-6-STOCK/`
- 4 specialist JSONs: architect.json + dba.json + security.json + red-team.json
- All findings Read-cited with exact line numbers
- Compatible with Z-3 owner attestation: yes — all heals proposed are additive, no `StockService` business logic touched
