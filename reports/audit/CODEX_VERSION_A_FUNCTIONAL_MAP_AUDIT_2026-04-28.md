# Codex Version A Functional Map Audit - 2026-04-28

Date: 2026-04-28
Auditor: Codex
Mode: Graphiti + project map + reports + targeted verification

## 1. Inputs Read

- Graphiti group `foodking`:
  - FoodKing central KPI: zero order loss, zero desynchronization between Kiosk/POS/KDS/admin surfaces.
  - Kiosk, KDS, outbox, idempotency, fiscal, branch isolation, frozen zones.
  - Item / variations / extras / order item snapshots.
- `.cursor/ACTIVE_CYCLE.md`
- `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`
- `docs/ARCHITECTURE.md`
- `docs/CORE_MODULES.md`
- `docs/ORDER_FLOW.md`
- `docs/DEVICE_FLOW.md`
- `reports/audit/CODEX_CENTRAL_SYNC_S0_S8_FINAL_REPORT_2026-04-28.md`
- `reports/audit/CODEX_CENTRAL_SYNC_COMPOSER_DATA_ULTRA_PLAN_2026-04-28.md`
- `reports/audit/CODEX_GLOBAL_FINISHING_VALIDATION_REPORT_2026-04-28.md`
- `reports/masterplay/status.json`

Targeted re-verification run during this audit:

- `php artisan test tests/Feature/Composer --stop-on-failure` -> 15 passed.
- `php artisan test tests/Feature/Services/Menu --stop-on-failure` -> 22 passed.
- `npm test -- tests/js/kioskWizardGenericComposer.spec.js tests/js/productComposerEditor.spec.js tests/js/productComposerSummary.spec.js` -> 11 passed.

## 2. Version A Target Definition

Version A should mean:

1. A restaurant can manage categories, products, photos, visibility, availability, stock, options, addons and wizard/composer profiles from the dashboard.
2. Kiosk, POS, KDS, OSS and backend consume the same central data without price drift or branch leakage.
3. Kiosk can guide customers through simple products, products with options, and products with a wizard.
4. POS can take the same order faster in a compact cashier flow.
5. Orders decrement stock atomically, release stock on cancellation/refund, keep fiscal snapshots immutable, and broadcast to KDS/OSS.
6. Queue numbers are unique across POS and kiosk.
7. Hardware-specific devices are validated on real devices before commercial go-live.

## 3. Strongly Validated / Functional

### Order Core

Status: PASS_STRONG_LOCAL

Validated:

- Backend pricing SSOT: frontend sends IDs/quantities, backend recomputes.
- Quote seal / tamper protection for kiosk commit.
- Immutable composition and allergen snapshots.
- POS/kiosk order creation paths.
- Payment lifecycle including cash-at-counter.
- Fiscal sequence null before counter payment, allocated on confirm, not allocated on cancel.
- Order status transitions and KDS/OSS visibility.

Evidence:

- Final S0-S8 report lists pricing, quote, payment, fiscal, outbox and KDS suites PASS.
- Recent report lists C0/C1/C2/C3 runtime PASS.

### Sync Runtime Local

Status: PASS_LOCAL

Validated:

- Kiosk order reaches KDS/POS/OSS in local runtime.
- POS order reaches KDS/OSS in local runtime.
- Outbox and branch channels are present.
- CatalogChanged events are persisted into `domain_events`.

Evidence:

- `tests/e2e/c3-runtime-multi-surface.spec.js` previously passed repeat-each.
- Outbox tests and SyncComprehensive tests passed in final report.

### Stock And Queue

Status: PASS_STRONG_LOCAL_AND_PRODLIKE

Validated:

- Parent item stock decrement/release.
- Branch-isolated stock.
- Rupture sync for parent item availability.
- Queue number uniqueness across POS/kiosk.
- MySQL/Redis real concurrency harness: 3/3 PASS.

Evidence:

- `tests/Feature/ProdLike/ProdLikeConcurrencyTest.php` -> 3/3 PASS with 50 workers for stock and 50 mixed POS/kiosk queue allocations.

### Composer Security And Publish Sync

Status: PASS

Validated now:

- Branch Admin cannot create global composer profile.
- Branch Admin cannot update foreign profile by forging payload scope.
- Tenant Admin can create global composer profile.
- Publish/unpublish invalidates kiosk cache and persists CatalogChanged outbox.
- Published step mutation invalidates catalog projection.

Evidence from current targeted run:

- `tests/Feature/Composer` -> 15 passed.

### POS/Kiosk Menu Projection

Status: PASS_LOCAL

Validated now:

- POS and kiosk projections share item identity, price and availability.
- Branch-scoped composer profile wins over global profile.
- Published composer profile steps are shared by POS/kiosk projections.
- Inactive variation/extra choices are filtered.
- Kiosk legacy payload and canonical projection have sentinels.

Evidence from current targeted run:

- `tests/Feature/Services/Menu` -> 22 passed.

### Kiosk Generic Wizard

Status: FUNCTIONAL_WITH_CONTRACT_GAPS

Validated:

- Generic choice component exists.
- It handles min/max/allow_repeat and unavailable choices in frontend.
- Non-legacy labels can be handled if the step has choices from `item_attribute`, `extra_group`, or `addon`.

Evidence:

- `tests/js/kioskWizardGenericComposer.spec.js` passed.

Important nuance:

- There is no explicit `step_kind` / `ui_component` schema yet.
- Runtime still derives known step types using legacy labels first, then falls back to `generic_choices` when choices exist.
- This is functional for many Version A cases, but not yet a clean long-term wizard contract.

### Dashboard Composer UI Basics

Status: PARTIAL_PASS

Validated:

- Product composer editor and summary have basic JS coverage.
- API rejects price fields in composer payload.

Evidence:

- `tests/js/productComposerEditor.spec.js` and `tests/js/productComposerSummary.spec.js` passed.
- `tests/Feature/Composer/ComposerProfileApiTest.php` passed.

## 4. Main Gaps / Not Yet Fully Proved For Version A

### P0-Release - Hardware UAT Is Still Mandatory

Status: NOT SOFTWARE-BLOCKING, BUT GO-LIVE BLOCKING

Missing real-world proof:

- Physical TPE success/refusal/timeout.
- Fiscal printer ticket and reprint behavior.
- Kiosk OS lockdown, URL bar, touch behavior.
- KDS readability in real kitchen conditions.
- Network loss/reconnect with real router/Wi-Fi.
- Google Maps live production key/quota behavior.

Decision:

- Code can proceed to Hardware UAT.
- Commercial go-live must remain blocked until this is signed.

### P1-A - Full Dashboard Management E2E Is Not Yet A Single End-To-End Proof

Current state:

- Backend/API/component tests exist.
- Photo/cache/catalog tests exist.
- Composer API and projection tests pass.

Gap:

- No single browser scenario proves:
  1. dashboard creates category;
  2. dashboard creates product;
  3. dashboard uploads photo;
  4. dashboard builds wizard;
  5. dashboard publishes;
  6. kiosk sees product/photo/wizard;
  7. POS sees same product;
  8. order reaches KDS;
  9. stock decrements;
  10. dashboard changes stock to 0;
  11. POS/kiosk refuse;
  12. dashboard restocks;
  13. POS/kiosk accept again.

Risk:

- A restaurateur workflow can break even while lower-level tests remain green.

### P1-B - Wizard Contract Is Functional But Not Yet Fully Formalized

Current state:

- Generic choice fallback exists.
- Kiosk can render generic choices if `choices[]` exists.
- Backend validates composer step constraints in PricingService.

Gaps:

- No explicit `step_kind` or `ui_component`.
- `source_type=fixed` returns no choices in projection.
- `visible_on.*` is not constrained to `pos|kiosk|web` in request validation.
- `source_ref` is string-free and not validated against the selected source.

Risk:

- Admin can create a profile that looks valid but produces no runtime choices.
- The runtime contract still relies partly on legacy label detection.

### P1-C - Stockable Choices Need A Clear Business Decision

Current state:

- Parent item stock is robust and concurrency-proven.
- Addon item branch availability and item availability are enforced in pricing/projection.

Gap:

- If the business expects per-choice ingredient stock (variation/extra/addon component stock), the current evidence is not enough.
- `stockable_choices=true` exists, but Version A needs a clear rule:
  - parent-item stock only, or
  - ingredient/choice-level stock.

Risk:

- If "sauce X", "boisson Y", "viande Z" must have independent stock, the current Version A scope is incomplete.

### P1-D - Production WebSocket / Realtime Topology Still Needs Staging Or Hardware Proof

Current state:

- Local C3 runtime passed.
- Outbox/job tests pass.

Gap:

- Production provider topology (Pusher/Reverb/queue workers/Horizon/reconnect) is not proven on the final staging/hardware network.

Risk:

- Local fallback/polling can pass while production channel/auth/queue config has a deployment issue.

### P1-E - Full Authz Matrix For Central Management Is Not Yet Exhaustive

Current state:

- Composer targeted authz is strong.
- Branch isolation is heavily tested in core flows.

Gap:

- No single exhaustive matrix over all central management routes:
  - products;
  - categories;
  - photos;
  - stock;
  - composer profile/steps;
  - publish/unpublish.

Risk:

- A route outside composer can still expose cross-branch mutation.

### P2-A - Central Sync Documentation / Runbook Is Behind The Code

Current state:

- Graphiti has strong historical facts.
- Reports contain the latest truth.

Gap:

- A clean operational runbook for "product not visible on kiosk", "stock not synced", "wizard not visible on POS", "outbox stuck", "cache stale" is still missing or not canonical.

Risk:

- Support/debug in UAT or production becomes slow.

### P2-B - Active Cycle Governance Still Looks Open

Current state:

- `reports/masterplay/status.json` says current task is `CLOSED`.
- `.cursor/ACTIVE_CYCLE.md` still says `PHASE=IN_PROGRESS` for Train A.

Risk:

- Agents/humans can believe the project is still mid-cycle and fork duplicate work.

Action:

- Close or update the active-cycle artifact once the human validates the Version A checklist.

## 5. API vs MCP Decision

Do not replace runtime APIs with MCP.

Decision:

- Runtime machine-to-machine flow remains API + WebSocket/Echo/Pusher/Reverb + outbox + MySQL/Redis locks.
- MCP is acceptable for developer/admin assistants, menu import, audit, reporting, and external tool orchestration.

Reason:

- POS/Kiosk/KDS need low latency, stable auth, offline/retry semantics, idempotency, monitoring and deterministic contracts.
- MCP is a context/tool protocol, not the runtime SSOT for restaurant devices.

## 6. Proposed Plan To Validate With The User

### VA-0 - Decide Version A Scope

Human decision needed:

1. Version A stock scope:
   - A: parent product stock only;
   - B: ingredient/choice-level stock for variations/extras/addons.
2. Wizard contract:
   - A: keep current generic fallback for Version A;
   - B: add explicit `step_kind/ui_component` before Version A.
3. Dashboard scope:
   - A: API/component-level management is enough for UAT;
   - B: full browser dashboard create/edit/publish E2E is mandatory before UAT.

### VA-1 - Full Dashboard Management E2E

Create a browser-level Playwright test:

- dashboard create category;
- create product;
- upload/change/delete photo;
- create wizard;
- publish;
- verify kiosk/POS projection;
- place order;
- KDS receives;
- stock decrements;
- set stock 0 and verify refusal;
- restock and verify acceptance.

Priority:

- P1 if Version A includes restaurateur self-management.

### VA-2 - Wizard Contract Hardening

Minimal path:

- validate `visible_on.* in pos,kiosk,web`;
- validate `source_ref` per source type;
- reject `fixed` unless explicit choices are supported;
- add tests proving non-legacy labels work.

Full path:

- add `step_kind` / `ui_component` schema after migration gate;
- render by explicit kind, not labels;
- keep legacy label adapters only as fallback.

Priority:

- P1 for future-proof Version A.

### VA-3 - Stockable Choices Decision And Tests

If parent stock only:

- document it as Version A behavior;
- ensure UI copy does not imply ingredient-level stock.

If choice-level stock:

- implement projection and backend validation for variation/extra/addon stock levels;
- add stale-order tests;
- add restock propagation tests.

Priority:

- P1 if the restaurant needs ingredient/component stock.

### VA-4 - Central Management Authz Matrix

Create matrix tests:

- roles: Admin, Tenant Admin, Branch Admin, POS Operator, Delivery, KDS/Chef where relevant;
- scopes: own branch, foreign branch, global;
- routes: products, categories, photos, stock, composer profile, composer steps, publish/unpublish.

Priority:

- P1 before multi-branch production.

### VA-5 - Staging / Hardware Realtime Proof

Run:

- C3 multi-surface on staging or hardware network;
- outbox worker running as production;
- WebSocket provider actual config;
- reconnect / network loss;
- latency target: order appears KDS/OSS under 5 seconds.

Priority:

- P0 for go-live; P1 before final UAT signoff.

### VA-6 - Hardware UAT

Run the physical checklist:

- TPE success/refusal/timeout;
- fiscal printer and reprint;
- kiosk lockdown;
- KDS screen readability;
- real Wi-Fi/router loss/reconnect;
- Google Maps production geocode/quota.

Priority:

- P0 go-live gate.

### VA-7 - Docs / Runbook / Memory Close

Create or update:

- `docs/sync/CATALOG_COMPOSER_DATA_FLOW.md`
- `docs/sync/WIZARD_PRODUCT_MODEL.md`
- `docs/sync/STOCK_SYNC_AND_AVAILABILITY.md`
- `docs/sync/API_VS_MCP_DECISION.md`
- `docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md`
- Graphiti/JSONL memory with final Version A decisions.
- `.cursor/ACTIVE_CYCLE.md` close/update after human validation.

Priority:

- P2 before commercial support, P1 if several agents keep working in parallel.

## 7. Recommended Decision

Recommended Version A gate:

- Proceed to Hardware UAT for the core order/payment/stock/queue/KDS software.
- Before declaring "Version A management complete", add VA-1 and VA-2 minimal hardening.
- Decide VA-3 explicitly:
  - If parent-item stock only is acceptable, document it and move on.
  - If ingredient/choice-level stock is required, implement it before Version A.

Current concise verdict:

- `ORDER_CORE: PASS_STRONG_LOCAL`
- `CENTRAL_SYNC_RUNTIME_LOCAL: PASS_LOCAL`
- `CATALOG_COMPOSER_API_SYNC: PASS_LOCAL`
- `DASHBOARD_RESTAURATEUR_E2E: NOT_FULLY_PROVED`
- `WIZARD_CONTRACT: FUNCTIONAL_WITH_CONTRACT_GAPS`
- `CHOICE_LEVEL_STOCK: BUSINESS_DECISION_REQUIRED`
- `HARDWARE_UAT: REQUIRED_BEFORE_GO_LIVE`
