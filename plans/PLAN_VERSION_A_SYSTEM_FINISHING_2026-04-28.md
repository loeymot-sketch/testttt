# Plan Version A System Finishing - 2026-04-28

Status: READY_FOR_HUMAN_VALIDATION
Owner: Codex
Scope: software/system finishing before hardware-industrial validation
Hardware status: DEFERRED_TO_FINAL_UAT_GATE

## 1. Context

The user explicitly decided that physical peripherals must not block the current software/system finishing loop.

Deferred hardware examples:

- TPE / external payment terminal.
- Fiscal printer.
- Kiosk OS lockdown and physical touch behavior.
- Real KDS kitchen screen.
- Real router/Wi-Fi loss.
- Google Maps production key/quota.

These remain go-live gates, but they are not part of this immediate implementation wave.

Immediate objective:

- Validate and harden the whole software system behavior before industrial hardware validation:
  - dashboard central management;
  - catalog/product/category/photo sync;
  - composer/wizard contract;
  - POS/kiosk projection;
  - stock/availability;
  - authz matrix;
  - realtime/outbox;
  - documentation and runbook;
  - massive tests and adversarial audits.

## 2. Inputs / Memory

Graphiti facts used:

- FoodKing central KPI: zero lost orders and zero desynchronization between Kiosk, POS, KDS and admin surfaces.
- MySQL is production SSOT.
- Redis is production lock/cache/queue support.
- Kiosk sends intentions only; backend is pricing SSOT.
- KDS consumes after-commit order events.
- Outbox persists business events before broadcast.
- Hardware failure must not block a transaction; reprint/duplicata exists as operational recovery.
- Branch isolation is non-negotiable.
- Frozen zones require surgical justification.

Repository artifacts used:

- `reports/audit/CODEX_VERSION_A_FUNCTIONAL_MAP_AUDIT_2026-04-28.md`
- `reports/audit/CODEX_CENTRAL_SYNC_S0_S8_FINAL_REPORT_2026-04-28.md`
- `reports/audit/CODEX_GLOBAL_FINISHING_VALIDATION_REPORT_2026-04-28.md`
- `docs/ARCHITECTURE.md`
- `docs/CORE_MODULES.md`
- `docs/ORDER_FLOW.md`
- `docs/DEVICE_FLOW.md`
- `.cursor/ACTIVE_CYCLE.md`
- `reports/masterplay/status.json`

Current software base:

- Order core: PASS_STRONG_LOCAL.
- Central composer/publish sync: PASS_LOCAL.
- POS/kiosk projection: PASS_LOCAL.
- Stock parent item + queue: PASS_STRONG_LOCAL_AND_PRODLIKE.
- C0/C1/C2/C3 runtime: PASS_LOCAL.
- D1/D2/D3 design: PASS_LOCAL.
- Remaining system gaps: dashboard full E2E, wizard contract formalization, stockable choices implementation, authz matrix, staging-like realtime, runbook/governance.

## 2.1 User Decision - Stock Semantics For Version A

Decision received from user on 2026-04-28:

- A whole sellable product can be put in rupture.
- Wizard choices can also be put in rupture when they represent a real stockable product, ingredient, or selectable component:
  - supplements;
  - drinks;
  - crudites;
  - sauces;
  - desserts/cakes;
  - fish/meat/ingredient choices;
  - menu components;
  - addon products.
- If a wizard ingredient/choice is in rupture, the kiosk/POS must show it as unavailable during composition and the backend must reject a stale forged payload.
- The target is not merely parent-item stock. Version A must support product-level rupture plus choice-level rupture for stockable wizard choices.

## 3. Non-Negotiable Invariants

1. Backend pricing SSOT:
   - frontend may preview only;
   - frontend never supplies authoritative price/tax/total;
   - composer payload must not accept price fields.
2. Branch isolation:
   - Branch Admin cannot create global resources;
   - Branch Admin cannot mutate foreign resources;
   - all central management routes must be tested own/foreign/global.
3. Dispatch after commit:
   - catalog/order/stock events must be after commit;
   - outbox rows are the durable broadcast source.
4. Frozen zone discipline:
   - `OrderService` / `FrontendOrderService` only touched with explicit symmetry note and tests;
   - migrations require explicit plan validation.
5. Hardware deferral:
   - no code should fake hardware as fully validated;
   - software flows must continue without real printer/TPE.
6. API vs MCP:
   - runtime remains API + WebSocket/Echo/Pusher/Reverb + outbox;
   - MCP can be used for agents/import/audit, not runtime SSOT.

## 4. Execution Model After Human Validation

Codex main loop:

1. Pre-audit each mission.
2. Implement only the mission allowlist.
3. Run focused tests.
4. Run adversarial read-only audit.
5. Fix if REWORK.
6. Run run-many validation.
7. Write mission report.
8. Move to next mission only after PASS.

Planned sub-agent roles after validation:

- `implementer`: bounded code changes.
- `adversary`: read-only, tries to break the logic and find false assumptions.
- `validator`: runs tests and checks artifacts.
- `sync-risk`: reviews state consistency, branch isolation, pricing, outbox, KDS/OSS.
- `doc-auditor`: verifies runbooks and memory match code.

No sub-agent may self-approve a gate or expand scope.

## 5. Mission DAG

```text
VA-SYS-00 Scope Lock / Hardware Deferral
  -> VA-SYS-01 Dashboard Workflow Discovery
     -> VA-SYS-02 Composer Request Contract Hardening
        -> VA-SYS-03 Wizard Runtime Contract
           -> VA-SYS-04 Dashboard Builder UX Hardening
              -> VA-SYS-05 Full Dashboard-To-Kiosk/POS/KDS E2E
                 -> VA-SYS-06 Stockable Choices Semantics
                    -> VA-SYS-07 Central Management Authz Matrix
                       -> VA-SYS-08 Realtime/Outbox Production-Like Simulation
                          -> VA-SYS-09 Docs / Runbook / Memory Close
                             -> VA-SYS-10 Final Massive Validation
```

Parallel candidates after implementation starts:

- VA-SYS-07 can start after VA-SYS-02.
- VA-SYS-09 docs can start after VA-SYS-03.
- VA-SYS-08 can start after VA-SYS-05.

Do not run VA-SYS-10 before all previous missions are PASS.

## 6. VA-SYS-00 - Scope Lock / Hardware Deferral

Priority: P0 governance

Objective:

- Freeze the current decision: hardware is a final UAT gate, not an immediate blocker for software/system finishing.
- Ensure no report claims go-live before hardware signoff.
- Ensure all future reports say `HARDWARE_UAT_REQUIRED_BEFORE_GO_LIVE`.

Allowed files:

- `reports/audit/*VERSION_A*`
- `missions/VERSION-A-SYSTEM-FINISHING/*`
- optional `docs/gates/GATE_VERSION_A_HARDWARE_DEFERRED_2026-04-28.md`

Implementation:

1. Write gate note:
   - hardware deferred;
   - software/system finishing continues;
   - no commercial release without hardware signoff.
2. Add checklist of deferred hardware items.
3. Add "software pass != go-live pass" language.

Tests:

- static inspection.

PASS:

- There is a canonical written decision.
- No plan treats TPE/printer/Google Maps live as immediate software blockers.

REWORK:

- Any report says go-live is allowed before hardware.

## 7. VA-SYS-01 - Dashboard Workflow Discovery

Priority: P1

Objective:

- Map the actual dashboard routes/components/API calls for:
  - category create/update/delete;
  - product create/update/delete;
  - photo upload/change/delete;
  - variations/extras/addons;
  - composer profile/steps/publish;
  - stock/availability toggle.

Why:

- Before building E2E, we need the exact UI flow, selectors, stores and API routes.

Read targets:

- `routes/api.php`
- `resources/js/router/modules/adminRoutes.js`
- `resources/js/router/modules/settingRoutes.js`
- `resources/js/store/modules/item.js`
- `resources/js/store/modules/itemCategory.js`
- `resources/js/store/modules/composer.js`
- `resources/js/store/modules/itemAvailability.js`
- `resources/js/components/admin/items/**`
- `resources/js/components/admin/settings/ItemCategory/**`
- `tests/e2e/composer-mega-flow.spec.js`

Outputs:

- `reports/audit/VA_SYS_01_DASHBOARD_WORKFLOW_DISCOVERY_2026-04-28.md`
- list of stable selectors to add if missing.
- list of missing test helpers.

Tests:

- No product code edits expected.
- If adding test IDs only, run affected JS/component tests.

PASS:

- Full dashboard action map exists.
- E2E implementation scope for VA-SYS-05 is clear.

REWORK:

- Any dashboard workflow remains unknown or untestable.

## 8. VA-SYS-02 - Composer Request Contract Hardening

Priority: P1

Objective:

- Prevent invalid composer profiles from being saved/published.
- Make request validation match runtime capabilities.

Current known gaps:

- `visible_on.*` is not constrained to `pos|kiosk|web`.
- `source_ref` is a free string.
- `source_type=fixed` currently has no choices in projection.

Allowed files:

- `app/Http/Requests/ComposerProfileRequest.php`
- `app/Http/Requests/ComposerStepRequest.php`
- `app/Services/Composer/ComposerStepService.php`
- `app/Services/Composer/ComposerProfileProjection.php`
- `tests/Feature/Composer/*`
- `tests/Feature/Services/Menu/*`

Implementation path, minimal:

1. Add validation:
   - `visible_on.*` must be one of `pos,kiosk,web`.
   - `source_type=fixed` must be rejected unless fixed choices are explicitly implemented.
   - `source_ref` required or validated when source type needs it.
2. Add service-level normalization guard so direct service calls cannot bypass form request rules.
3. Add tests:
   - invalid surface -> 422;
   - invalid fixed source without choices -> 422;
   - valid item_attribute source -> 2xx;
   - valid extra_group source -> 2xx;
   - valid addon role -> 2xx;
   - no price accepted.

Implementation path, full fixed choices:

- Requires schema/model decision for fixed choices.
- If validated by user, add `choices` JSON on steps or a related table.
- This is more durable but touches schema.

Recommendation:

- Start with minimal hardening.
- Do not add fixed choices schema unless human explicitly approves migration.

Tests:

- `php artisan test tests/Feature/Composer --stop-on-failure`
- `php artisan test tests/Feature/Services/Menu --stop-on-failure`
- run-many: 3x.

PASS:

- Invalid composer definitions cannot be stored.
- Existing valid composer tests still pass.

REWORK:

- Dashboard can save a profile that runtime cannot render.

## 9. VA-SYS-03 - Wizard Runtime Contract

Priority: P1

Objective:

- Remove practical dependence on label keywords for Version A.
- Ensure generic wizard steps work for non-tacos products with arbitrary labels.

Current state:

- Kiosk has generic fallback when choices exist.
- Legacy keywords still choose specialized components first.
- No explicit `step_kind` / `ui_component` field.

Two paths:

### Path A - No Migration Minimal Contract

Use existing fields:

- `source_type` drives rendering:
  - `item_attribute` -> generic choice component unless known legacy adapter is desired;
  - `extra_group` -> generic choice component;
  - `addon` -> generic choice component or menu adapter if role is menu-like;
  - `fixed` -> rejected until supported.
- label keywords become optional visual adapters, not required behavior.

Allowed files:

- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue`
- `tests/js/kioskWizardGenericComposer.spec.js`
- `tests/js/kioskWizardComposerProfile.spec.js`
- `tests/js/KioskWizard.spec.js`

Tests:

- step label `Choix cuisson` renders choices.
- step label `Base` renders choices.
- step label `Fromage` renders choices.
- required min blocks next.
- max blocks extra selection.
- unavailable choice disabled.
- visible_on kiosk-only/pos-only respected.

### Path B - Migration Contract

Add explicit `step_kind` or `ui_component`.

Possible values:

- `single_choice`
- `multi_choice`
- `quantity_choice`
- `menu_bundle`
- `upsell`
- `recap`

Requires:

- migration gate;
- model fillable/casts;
- requests;
- resources/projection;
- admin editor;
- kiosk renderer;
- POS compact renderer;
- backfill existing steps using adapter mapping.

Recommendation:

- Execute Path A first for Version A safety.
- Prepare Path B as a migration mission only if user wants the clean long-term contract before UAT.

PASS:

- A product with no legacy keyword can be ordered through wizard.
- Frontend and backend both enforce min/max/allow_repeat.

REWORK:

- Any valid dashboard-created wizard can disappear on kiosk because its label is unknown.

## 10. VA-SYS-04 - Dashboard Builder UX Hardening

Priority: P1/P2 depending on Version A product ambition

Objective:

- Make composer management usable by a restaurant operator, not only by a developer.

Target UX:

- Product mode selector:
  - simple ready;
  - simple options;
  - composer required;
  - menu/upsell.
- Step builder:
  - label;
  - source type;
  - source reference picker;
  - min/max;
  - repeat allowed;
  - surfaces visible;
  - stockable choices flag;
  - active/inactive;
  - preview.
- Publish panel:
  - branch/global scope;
  - current version;
  - last published time;
  - impacted surfaces;
  - validation errors before publish.

Allowed files:

- `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue`
- `resources/js/components/admin/items/composer/StepEditorComponent.vue`
- `resources/js/components/admin/items/composer/StepPreviewComponent.vue`
- `resources/js/store/modules/composer.js`
- `tests/js/productComposerEditor.spec.js`
- `tests/js/productComposerSummary.spec.js`

Design rules:

- No marketing page.
- Dense operational UI.
- No nested card clutter.
- Use predictable controls.
- Errors must be inline and blocking.

Tests:

- create step in UI state.
- invalid min/max shows error.
- invalid visible_on not allowed.
- publish disabled while validation fails.
- preview shows kiosk/POS differences.

PASS:

- A restaurant operator can configure a common product without raw technical guessing.

REWORK:

- UI still allows invalid composer definitions or hides critical publish impact.

## 11. VA-SYS-05 - Full Dashboard-To-Kiosk/POS/KDS E2E

Priority: P1 high

Objective:

- Prove the whole central management flow in one browser/runtime scenario.

Spec target:

- `tests/e2e/version-a-system/va-sys-dashboard-catalog-to-order.spec.js`

Scenario:

1. Login admin/branch admin.
2. Create category.
3. Create product.
4. Upload/change product photo.
5. Add variations/extras/addons.
6. Create composer wizard.
7. Publish composer.
8. Open kiosk context.
9. Verify product/photo/wizard visible.
10. Open POS context.
11. Verify same product and compatible choices.
12. Place kiosk order.
13. Verify KDS receives order.
14. Verify stock decremented.
15. Set stock to zero from dashboard/API.
16. Verify kiosk/POS refuse new order.
17. Restock.
18. Verify kiosk/POS accept again.

Implementation notes:

- Prefer UI steps for user-facing flows.
- API setup allowed only for test fixture creation if UI route is too brittle; final assertion must use real UI/runtime surfaces.
- No mocked backend pricing.
- No page.route mocked order/KDS/broadcast success.

Tests:

- initial local: repeat-each=1.
- stabilization: repeat-each=3.
- after fixes: repeat-each=5 if flaky history appears.

PASS:

- Full flow passes 3/3.
- Product appears without manual DB intervention.
- KDS sees order.
- Stock and availability propagate.

REWORK:

- Any manual refresh-only behavior must be documented and justified.
- Any stale price or missing photo/wizard is REWORK.

## 12. VA-SYS-06 - Stockable Choices Semantics

Priority: P1 if ingredient-level stock is required; P2 if parent-item stock is enough

Business decision:

- Option A: Version A stock is parent product only.
- Option B: Version A supports stock for choices/ingredients/addons.

Recommendation:

- If restaurant needs "boisson X out", "viande Y out", "supplément Z out", choose Option B.
- If restaurant only needs "product unavailable", choose Option A and document it.

Option A work:

- Document Version A behavior.
- Ensure UI copy says "product stock", not "ingredient stock".
- Keep existing parent stock tests.

Option B work:

Allowed files likely:

- `app/Services/Stock/StockService.php`
- `app/Services/Menu/AvailabilityService.php`
- `app/Services/Composer/ComposerProfileProjection.php`
- `app/Services/Pricing/PricingService.php`
- `app/Services/Pricing/CompositionSnapshotBuilder.php`
- possibly `OrderService` / `FrontendOrderService` only with explicit symmetry note.
- tests under `tests/Feature/Stock`, `tests/Feature/Services/Pricing`, `tests/Feature/Services/Menu`, `tests/js/kioskWizardGenericComposer.spec.js`.

Required semantics for Option B:

1. `stockable_choices=true` means choices are stock-checked.
2. Variation/extra/addon choice can be unavailable independently from parent item.
3. Projection hides or disables unavailable choices.
4. Backend rejects stale choice order.
5. Order decrement decrements parent item and stockable choices.
6. Cancel/refund releases parent item and stockable choices.
7. Restock emits catalog event and choices return.

Tests:

- variation stock 0 -> kiosk disables/refuses.
- extra stock 0 -> POS disables/refuses.
- addon stock 0 -> menu bundle disables/refuses.
- stale payload after rupture -> 409/422.
- cancel/refund releases choice stock.
- run stock suite.

PASS:

- No over-sale at parent or choice level.

REWORK:

- Choice unavailable on UI but accepted by backend.
- Backend decrements parent only while UI claims choice stock.

## 13. VA-SYS-07 - Central Management Authz Matrix

Priority: P1

Objective:

- Prove all central management routes obey branch and role isolation.

Route families:

- item category create/update/delete/sort/import/export;
- item create/update/delete/change-image;
- item variations;
- item extras;
- item addons;
- availability toggle;
- stock endpoints;
- composer profile/steps/publish/unpublish;
- menu projection.

Roles:

- Admin.
- Tenant Admin.
- Branch Admin.
- POS Operator.
- Chef/KDS.
- Delivery Boy.
- Anonymous/kiosk token where relevant.

Scopes:

- own branch;
- foreign branch;
- global/null scope;
- no branch.

Tests:

- create matrix helper under `tests/Feature/Authz`.
- assert 2xx/403/404 as expected.
- ensure foreign branch hides existence where needed.

PASS:

- Branch Admin own branch only.
- Tenant/Admin allowed global where intended.
- POS/KDS/Delivery cannot mutate central catalog.
- Kiosk token cannot touch admin catalog.

REWORK:

- Any cross-branch mutation or info leak.

## 14. VA-SYS-08 - Realtime / Outbox Production-Like Simulation

Priority: P1

Objective:

- Validate system sync without real hardware:
  - queue worker;
  - outbox dispatcher;
  - cache invalidation;
  - reconnect/replay;
  - WebSocket provider or local equivalent.

Focus:

- This is not hardware UAT.
- This is software deployment behavior under production-like services.

Tests:

- Run outbox worker path, not only sync queue.
- Simulate stale pending domain event then rescue.
- Simulate failed dispatch then retry.
- Simulate catalog mutation while kiosk/POS views are open.
- Simulate network interruption at browser context level if feasible.

Commands:

- existing outbox tests.
- C3 runtime.
- new Playwright sync test if needed.

PASS:

- Catalog/order/stock changes reach surfaces or are caught up by snapshot/outbox replay.
- Observability exposes failed/pending outbox state.

REWORK:

- Silent stale menu without recovery.
- Pending outbox event stuck without alert/runbook.

## 15. VA-SYS-09 - Docs / Runbook / Memory Close

Priority: P2, but required before handoff to production support

Objective:

- Make the system operable by humans and future agents.

Documents:

- `docs/sync/CATALOG_COMPOSER_DATA_FLOW.md`
- `docs/sync/WIZARD_PRODUCT_MODEL.md`
- `docs/sync/STOCK_SYNC_AND_AVAILABILITY.md`
- `docs/sync/API_VS_MCP_DECISION.md`
- `docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md`

Runbook must answer:

- Product not visible on kiosk: what to check?
- Product visible on POS but not kiosk: what to check?
- Wizard not visible: profile, branch scope, publish, projection, snapshot.
- Photo not updated: cache, event, media URL.
- Stock not synced: stock level, movement, CatalogChanged, cache key.
- KDS not receiving order: outbox, queue worker, branch channel, status.
- Queue number duplicate alert: DB unique, Redis lock, retry logs.

Memory:

- Add final decisions to JSONL/Graphiti after execution.
- Update `.cursor/ACTIVE_CYCLE.md` only when human validates close.

PASS:

- A new agent can diagnose sync issues from docs without reading all reports.

REWORK:

- Docs are generic and do not map to actual files/events/cache keys.

## 16. VA-SYS-10 - Final Massive Validation

Priority: P0 close gate

Objective:

- Re-run all critical tests after all system changes.

Required tests:

Backend:

- `php artisan test tests/Feature/Composer --stop-on-failure`
- `php artisan test tests/Feature/Services/Menu --stop-on-failure`
- `php artisan test tests/Feature/Catalog --stop-on-failure`
- `php artisan test tests/Feature/Menu --stop-on-failure`
- `php artisan test tests/Feature/Stock --stop-on-failure`
- `php artisan test tests/Feature/Payment --stop-on-failure`
- `php artisan test tests/Feature/Fiscal/FiscalCashAtCounterLifecycleTest.php --stop-on-failure`
- `php artisan test tests/Feature/OutboxTest.php --stop-on-failure`
- `php artisan test tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php --stop-on-failure`
- `php artisan test tests/Feature/SyncComprehensiveTest.php --stop-on-failure`
- `php artisan test tests/Feature/ProdLike/ProdLikeConcurrencyTest.php --stop-on-failure` under MySQL/Redis.

Frontend:

- `npm test -- tests/js/kioskWizardGenericComposer.spec.js tests/js/productComposerEditor.spec.js tests/js/productComposerSummary.spec.js`
- `npm test -- tests/js/kioskPricingPreview.spec.js tests/js/kioskCartSendPayload.spec.js tests/js/kioskOfflineQueueV2.spec.js`
- relevant POS/KDS/menu sync JS specs.

E2E:

- C0/C1/C2/C3 runtime set.
- D1/D2/D3 design set.
- new VA-SYS dashboard-to-runtime E2E.

Build:

- `npm run production`

Run-many:

- critical E2E: 3/3 minimum.
- prod-like concurrency: 3/3 minimum.
- flaky areas: 5/5 before PASS.

PASS:

- No P0/P1 open.
- No pricing frontend authority.
- No branch leak.
- No order lost.
- No stale catalog without recovery.
- Report written:
  - `reports/audit/CODEX_VERSION_A_SYSTEM_FINISHING_FINAL_REPORT_2026-04-28.md`

REWORK:

- Any failed critical test.
- Any missing final report.
- Any unresolved adversarial audit finding.

## 17. Validation Questions For User

Before execution, user should validate these defaults:

1. Hardware remains deferred until final UAT: YES by current instruction.
2. Wizard:
   - default plan uses no-migration hardening first;
   - migration `step_kind/ui_component` only if user explicitly approves.
3. Stock:
   - user decision is product-level rupture plus choice-level rupture for stockable wizard choices.
4. Dashboard:
   - full E2E is treated as mandatory before declaring Version A management complete.
5. Authz:
   - full central management matrix is mandatory before multi-branch production.

Codex recommended defaults:

- Hardware deferred.
- Execute VA-SYS-01 through VA-SYS-05 first.
- Execute VA-SYS-06 Option B because user confirmed choice-level ingredient/choice stock is required now.
- Execute VA-SYS-07/08/09/10 before final software close.

## 18. Definition Of Done

Version A system finishing is done only when:

- dashboard central flow has a full E2E proof;
- composer invalid profiles cannot be saved;
- wizard works without legacy label dependence for generic choices;
- stock semantics are explicit and tested;
- authz matrix passes;
- outbox/realtime simulation passes;
- final massive validation passes;
- docs/runbook are written;
- hardware remains clearly marked as the only final go-live gate.

Final software status target:

`VERSION_A_SYSTEM_SOFTWARE: PASS_READY_FOR_HARDWARE_INDUSTRIAL_UAT`
