# Codex Handoff to Claude - Master Review Product Composer / Catalogue / Stock / POS-Kiosk-KDS Sync - 2026-04-27

## Requested Claude Verdict

Claude must perform a full master review and produce:

- `AUDIT_VERDICT: PASS | REWORK | HOLD`
- a prioritized list of remaining problems, ordered P0/P1/P2
- a precise finishing plan if anything is incomplete
- explicit coverage of dashboard/control-plane catalogue management, product composition, photos, stock, delivery/maps, kiosk lockdown, cash-at-counter, POS-Kiosk-KDS sync, pricing SSOT, and NF525 fiscal risks
- exact file references for every finding
- a clear decision on whether the system can move to physical hardware UAT only, or whether code rework is still required first

## Current Codex Verdict

`LOCAL_FULL_GREEN_HARDWARE_PENDING`

Automated local validation is green. Commercial release remains blocked by physical hardware UAT only:

- TPE / external payment terminal behavior
- fiscal printer / non-fiscal counter slip behavior
- real kiosk touchscreen lock mode
- real KDS screen readability
- network loss / reconnect behavior on hardware

## What Codex Implemented Or Validated

### B0 - P0 Hotfix

- Server delivery fee authority restored for customer/web orders.
- `OrderRequest` delivery comparisons fixed with integer cast for HTTP string inputs.
- Frontend delivery preview helper aligned to the approved 5 EUR per 5 km rule.
- Legacy kiosk admin bundle/source removed from runtime exposure.
- Forbidden bundle guards added.

### B7 - Kiosk Lockdown

- `/kiosk/admin` blocked to customer-safe kiosk flow.
- Public kiosk admin bundles removed / blocked.
- Kiosk customer screens audited for admin/POS escape controls.
- Payment back button behavior verified: active before submit, disabled during submit.

### B8 - Delivery / Maps Hardening

- Delivery fee recomputed server-side from saved address coordinates when distance is supplied.
- Missing delivery distance or unresolvable/geocode-failed address blocks with explicit error.
- POS UI blocks un-geocoded delivery address text.
- Web checkout surfaces geocode failure instead of silently accepting wrong fees.

### B2 - Product Composer / Stock Schema ADR

- Composer schema introduced:
  - `item_wizard_profiles`
  - `item_wizard_steps`
  - addon role support
- Stock schema introduced:
  - `stock_levels`
  - `stock_movements`
- Models, factories, seeders, migration tests added.
- Gates cited in mission reports:
  - `HG-COMPOSER-SCHEMA-ADR`
  - `HG-STOCK-STOCKABLE-SCOPE`

### B3 - Dashboard Composer Write

- Dashboard/API control-plane for product composition introduced.
- Composer controllers, requests, resources, services, editor components, router exposure, and summary entrypoint implemented.
- Minimal permissions added:
  - `catalog.compose`
  - `catalog.publish`
- Pricing payload remains forbidden from frontend/dashboard composer logic.

### B4 - Runtime Wizard Migration

- Published composer profile now takes priority in runtime menu projection.
- POS/Kiosk wizard projection updated to expose composer constraints.
- Heuristic fallback remains tracked when no published composer profile exists.
- Kiosk event telemetry adjusted for composer-driven wizard runtime.
- No `OrderService` / `FrontendOrderService` changes in B4.

### B5a - Stock V2 Core

- `StockService`, stock exceptions, `StockLevelChanged`, decrement/release listeners introduced.
- Symmetric stock decrement wired into `OrderService` and `FrontendOrderService`.
- Stock zero projects into existing item availability read-plane with `stock_rupture`.
- Quote sealing runs before decrement, avoiding last-unit false rejection.
- Symmetry script added for order services.

### B5b - Cash-at-Counter Lifecycle

- New pending counter payment lifecycle implemented.
- `PaymentStateMachine`, `OrderPaidAtCounter`, `PaymentService` confirm/cancel paths introduced.
- Kiosk cash creates order as `ACCEPT` + `PENDING_COUNTER` with `fiscal_sequence_no = NULL`.
- POS confirm allocates fiscal sequence and marks paid.
- POS cancel does not allocate fiscal sequence and dispatches cancellation for stock release.
- POS pending kiosk cash panel added.
- KDS pending payment badge added.
- Kiosk cash instruction route added.

### B6 - Catalog Eventing / Photo E2E

- `CatalogChanged` event and `PersistCatalogChangedToOutbox` listener added.
- Existing `domain_events` outbox reused intentionally; no separate `catalog_outbox`.
- Item/category/photo/catalog mutations now flow through unified event contract tests.
- Menu/catalog projection tests and JS event/catalog specs passed.

### B9 - E2E / Hardware Signoff Packet

- Added full B9 E2E:
  - creates pending kiosk cash order
  - proves KDS shows `PAIEMENT COMPTOIR`
  - proves POS pending panel sees order
  - confirms payment at POS
  - verifies fiscal sequence allocation
  - verifies cancel path stays non-fiscal
- B9 E2E cleanup respects append-only `audit_logs`.
- B9 browser-page leak fixed by closing manual POS/KDS pages in `finally`.
- E2E RateLimiter helper added to prevent false local 429s from repeated reruns.
- Legacy tacos POS E2E hardened for POS v4 grid/selectors and hydration timing.

## Validation Evidence

### Full Automated Validation

- `php artisan test` -> PASS, 1167 passed, 8 skipped
- `npx vitest run` -> PASS, 899 passed
- `npx playwright test --project=chromium` -> PASS, 40 passed
- `git diff --check` -> PASS
- B9 DB cleanup sentinel -> PASS:
  - `PW-B9-%` orders = 0
  - `PENDING_COUNTER` orders = 0

### Targeted Validation Highlights

- `CounterDeferredPaymentLifecycleTest` -> PASS, 5 tests
- `PosCollectKioskCashRouteTest` -> PASS, 1 test
- `ZAggregationKioskRoutingTest` -> PASS, 1 test
- `OrderServicesContractTest` -> PASS, 5 tests
- kiosk lockdown E2E -> PASS
- composer cash-at-counter E2E -> PASS
- bundle scans:
  - `tools/lint/forbidden_bundles.sh` -> PASS
  - `tools/lint/scan_kiosk_bundles.mjs` -> PASS
  - `scripts/scan-bundle-legacy.sh` -> PASS

## Files Claude Should Read First

- `reports/audit/CODEX_FINAL_COMPOSER_AUDIT_2026-04-27.md`
- `reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B9-E2E-HARDWARE-SIGNOFF.md`
- `missions/PRODUCT-COMPOSER-SYNC-B9-E2E-HARDWARE-SIGNOFF/report.md`
- `docs/hardware/UAT_COMPOSER_2026-04-27.md`
- `reports/audit/CLAUDE_PRODUCT_COMPOSER_FINAL_EXECUTION_PLAN_2026-04-27.md`
- `reports/audit/CLAUDE_PRODUCT_COMPOSER_MEGA_AUDIT_AND_PLAN_2026-04-27.md`

## Critical Areas Claude Must Audit Deeply

### 1. Dashboard / Control Plane

Verify whether product/category/photo/composer management is operationally usable enough:

- create/edit/delete category
- create/edit product
- edit price through backend-authoritative paths only
- upload/change product image and refresh POS/Kiosk catalogue
- publish composer profile
- configure wizard steps per product/category
- branch scope and permissions
- no pricing logic moved to frontend

### 2. Product Composer Logic

Audit whether the composer is complete enough for real weekly offers:

- category defaults vs product overrides
- tacos/sandwich/assiette differences
- crudités/sauce/viande/supplements/drinks/menu steps
- published profile priority over heuristic wizard
- fallback behavior when no profile exists
- POS and Kiosk parity where required
- no regression in existing wizard selection/buttons

### 3. Stock V2

Audit the invariant:

- stock decrements atomically after quote sealing
- stock release on cancel/refund
- branch isolation
- rupture projected to POS/Kiosk consistently
- no negative stock race
- no frontend stock authority

### 4. Cash-at-Counter / NF525

Audit:

- pending kiosk cash order is not fiscalized at creation
- POS confirm allocates fiscal sequence inside transaction
- POS cancel never consumes fiscal sequence
- audit logs are append-only
- domain events/outbox are after commit
- KDS badge semantics are correct

### 5. Delivery / Maps

Audit:

- 5 EUR per 5 km business rule
- backend recomputation
- no client-forged delivery charge
- failed geocode blocks delivery with clear error
- POS/web parity

### 6. Kiosk Lockdown

Audit:

- no admin route from kiosk UI
- no visible POS escape/admin link
- legacy bundles removed
- kiosk remains customer-safe on URL changes
- payment back behavior
- offline/reconnect UX risk

### 7. Sync / Realtime

Audit:

- POS/Kiosk/KDS order visibility
- catalog eventing and menu refresh
- stock eventing and availability projection
- queue number uniqueness assumptions
- channel/event contract consistency
- stale websocket fallback behavior

## Known Residual Risks

- Physical hardware UAT is pending and cannot be signed by Codex.
- The worktree is very dirty from multiple missions; Claude should review actual diffs carefully and distinguish scoped Codex changes from pre-existing/unrelated work.
- Redis RateLimiter can create false local E2E failures during repeated manual reruns; E2E helper only clears known test keys before scenarios.
- WebSocket server is not running locally in some browser tests; tests tolerate reconnect banners, but Claude should decide if release requires a real WS smoke with Pusher/Echo server running.
- Some full-suite tests are skipped; Claude should inspect whether the 8 skipped PHPUnit tests are acceptable for release.

## Claude Output Required

Claude must write or propose:

1. `MASTER_REVIEW_VERDICT`
2. `P0_REWORK_REQUIRED_BEFORE_HARDWARE_UAT`
3. `P1_FINISHING_PLAN`
4. `PHYSICAL_UAT_PLAN`
5. `RELEASE_DECISION`
6. `FILES_TO_RECHECK`
7. `TESTS_TO_RUN_NEXT`

If Claude finds no P0/P1 code blocker, expected final recommendation:

`CODE_LOCAL_PASS__PROCEED_TO_HARDWARE_UAT`

If Claude finds a blocker, expected output:

`REWORK_REQUIRED` with exact mission breakdown and allowlisted files.
