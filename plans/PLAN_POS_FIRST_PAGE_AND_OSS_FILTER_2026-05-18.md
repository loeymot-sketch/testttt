# PLAN — POS first-page catalog + OSS customer-screen filter
**Date** : 2026-05-18 · **Branch** : `v1-0-1-hardening-2026-05-17` · **Author** : Claude orchestrator
**Pipeline** : per-task → `ultra-audit-profond` (5 specialists fan-out + implement + RED + visual)
**Convergence** : PHPUnit feature test PASS + RED P0=0 + visual screenshot analyzed + 0 frozen-zone diff

---

## §0 Préambule

### §0.1 Working-tree decision
- Branch `v1-0-1-hardening-2026-05-17` HEAD `155ddbde8` (post Wave 5G + uncommitted POS-payment-fix work).
- **In-scope uncommitted changes** (from prior session, already verified by tests):
  - `config/pos.php` (simulation_hardware), `.env` POS_SIMULATION_HARDWARE=true
  - `database/seeders/AlignProfile85ChickenBurgerSeeder.php`, `AlignFritesWizardProfilesSeeder.php`
  - `tests/Feature/Pos/PosSimulationHardware4ScenariosTest.php`, `FritesWizardComposerTest.php`
  - `app/Http/Controllers/Admin/PosController.php`, `app/Services/PaymentService.php`, `app/Services/Payments/SplitPaymentService.php`
  - `resources/js/languages/{fr,en}.json` (22 i18n keys)
  - Built artifacts: `public/js/*.js`, `public/css/app.css`, `public/mix-manifest.json`
- **Out-of-scope changes** (pre-existing, owner reviews independently): `app/Http/PaymentGateways/Gateways/Stripe.php`, mobile/*, AGENTS.md, .cursor/*, multiple test/screenshot PNGs.
- Decision: this plan **adds** to the working-tree set above. Owner commits as one bundle (3 fixes total: payment + frites + this plan's deliverables) before pushing.

### §0.2 Anti-fiction checklist (verified 2026-05-18)
- POS catalog source: ✅ `resources/js/components/admin/pos/PosComponent.vue:1415-1467` (`categories` getter) + `resources/js/store/modules/posCategory.js`
- POS catalog API: ✅ `/api/admin/pos-category` (via posCategory Vuex module; lazy verify in §1.1)
- OSS frontend: ✅ `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` (392 LOC) + parent `OrderStatusScreenComponent.vue`
- OSS backend: ✅ `app/Http/Controllers/Admin/OrderStatusScreenController.php` (`index` authed, `publicIndex` wall) + `app/Services/OrderStatusScreenOrderService.php` (`list`, `listForBranch`)
- Existing OSS filter: ✅ `whereIn('status', [PREPARING, PREPARED])` (service line 53, 158) + `whereDate(today())` + stale-prune 8h + exclude DELIVERED+CANCELED
- Order enums: ✅ `app/Enums/OrderStatus.php` (PENDING=1, ACCEPT=4, PREPARING=7, PREPARED=8, OUT_FOR_DELIVERY=10, DELIVERED=13) + `app/Enums/OrderType.php` (DELIVERY=5, TAKEAWAY=10)
- Categories live: ✅ 21 rows in `item_categories`. New (post menu-reset 2026-05-13): 344 Sandwich Cayenne, 345 Galette, 346 Sandwich Classique, 347 Bols Gourmands, 348 Frites, 349 Burgers, 350 Menu enfant. Legacy: 306 Tacos, 307-318 prior.
- Tests dir: ✅ `tests/Feature/Pos/` (real path), `tests/e2e/` (real path)

### §0.3 Convergence criteria (literal)
- Each task PASS = (a) named PHPUnit feature test green, (b) visual screenshot Read + no raw label / no DELIVERY tile visible on OSS, (c) RED-team sub-agent finds 0 P0 NEW.
- GOAL DONE = both systems converged in two consecutive cycles with identical findings + final smoke `php artisan test --filter='PosCatalogFirstPage|OssCustomerScreenFilter|FritesWizard|PosSimulation|PosCashTrail|SplitPayment'` GREEN.

### §0.4 Pipeline reference
- Per-task → `~/.claude/skills/ultra-audit-profond/SKILL.md` (14-step: anchor → 5-specialist parallel audit → synthesize → implement → RED dispute → test → visual gate → close).
- Frozen-zone touches anticipated: **NONE**. Implementation is config + Vue computed-prop filter + (if needed) controller scope filter — no PricingService / PaymentComponent / pos-wizard.js / Kiosk frozen Vue touched.

---

## §1 — Système 1 : POS catalog first-page filter

### §1.0 Contract
On the first POS page (just-logged-in cashier landing on `/admin/pos`), the category strip and item grid display **only the curated best-seller set**. The other categories remain reachable through the search input (full menu) and via an explicit "Toutes les catégories" / "Voir plus" affordance. Production goal: faster cashier flow on 80% of orders without removing access to the long tail.

### §1.1 Anchors (file:line, verified)
- `resources/js/components/admin/pos/PosComponent.vue:227-243` — category strip render (v-for)
- `resources/js/components/admin/pos/PosComponent.vue:1415-1419` — `categories` computed prop → `$store.getters['posCategory/lists']`
- `resources/js/components/admin/pos/PosComponent.vue:2668-2681` — `allCategory()` + `setCategory(id)` handlers
- `resources/js/store/modules/posCategory.js` — Vuex module (categories load)
- `app/Http/Controllers/Admin/PosCategoryController.php` *(to verify path)* — backend categories endpoint

### §1.2 Frozen zones (strict-no-touch)
- `public/js/pos-wizard.js` (POS wizard Vanilla JS) — **untouched**, this plan only edits the category strip ABOVE the wizard.
- `resources/views/admin-pos-v4.blade.php` — **untouched**, no Blade change needed.
- `app/Services/Pricing/PricingService.php` — **untouched**, not pricing-related.

### §1.3 Sub-systems

#### Sub 1.1 — Best-sellers source-of-truth (config-driven category allowlist)
**Why config not DB flag**: per advisor 2026-05-18, `items.is_featured` defaults to 5 (it's a misnamed Status field), don't repurpose. User asks for **categories**, not items. A config or a new typed column on `item_categories` is the clean source.

**Decision** (config first, DB later if owner wants admin UI):
- `config/pos.php` adds `featured_category_ids` (array, default = [344, 345, 346, 306, 348, 347]) + env `POS_FEATURED_CATEGORY_IDS` (CSV, optional override).
- Falls back to "all categories" if config is empty (production safety).

**Tasks**:
- T-1.1.1 — Extend `config/pos.php` with `featured_category_ids` (array) and `featured_category_default_show_all` (bool, default false). Documented owner-config knob.
   - anchor: `config/pos.php` (existing, +12 LOC)
   - test: `tests/Feature/Pos/PosFeaturedCategoriesConfigTest.php` (test TO BE CREATED) — asserts config default + env override
- T-1.1.2 — `PosCategoryController` (or equivalent) reads the allowlist; returns categories ordered with featured first OR exposes a separate `featured` field that the frontend uses to filter.
   - anchor: `app/Http/Controllers/Admin/PosCategoryController.php` (or update existing index endpoint; verify path during execution)
   - test: `tests/Feature/Pos/PosFeaturedCategoriesEndpointTest.php` (TO BE CREATED) — asserts response shape, allowlist behavior, empty-config fallback

#### Sub 1.2 — Frontend filter on category strip + "voir plus" escape hatch
**Tasks**:
- T-1.2.1 — `PosComponent.vue` computed `featuredCategories` filters `categories` by the allowlist when present; renders strip with only those + a final "Toutes les catégories" pill that toggles a `showAllCategories` ref.
   - anchor: `resources/js/components/admin/pos/PosComponent.vue:1415-1467`, `:227-243`
   - test: `tests/e2e/_pos-first-page-best-sellers-2026-05-18.spec.js` (TO BE CREATED) — Playwright visual: landing strip shows only 6 categories + the "Toutes" pill; click "Toutes" reveals the rest
- T-1.2.2 — Item grid below the strip honours the current selection. When no category is selected (initial load), shows items from the featured set ONLY (not all items). Search input continues to query the full menu (unfiltered) — critical so cashier can still find a Coca by typing "coca".
   - anchor: `resources/js/components/admin/pos/PosComponent.vue` items getter (search by `items: function` ~line 1450-1500 during execution)
   - test: extends T-1.2.1 spec to check item count + search yields long-tail items
- T-1.2.3 — No regression on existing flows: cart, payment modal, parked orders, wizard for Frites/Chicken Burger still work (chain to existing PosSimulationHardware4ScenariosTest).
   - acceptance: `php artisan test --filter='PosSimulation|FritesWizard|PosCashTrail'` 25/25 PASS as before.

### §1.4 Acceptance for §1 (GO criteria)
- ✅ Allowlist config exists + has env override + default-empty fallback (T-1.1.1 + T-1.1.2)
- ✅ Visual: landing POS screen shows exactly 6 category pills + 1 "Toutes" pill (Playwright capture analyzed, no raw labels)
- ✅ Search input still returns Coca-Cola / Tiramisu / Dessert items when queried
- ✅ "Toutes" click reveals the full 21-category strip (escape hatch)
- ✅ Existing 25/25 PHPUnit tests still PASS

---

## §2 — Système 2 : OSS customer screen filter + delivery routing

### §2.0 Contract
The customer-facing wall display (`/admin/order-status-screen`) shows only **orders in active prep or ready for pickup**, as 40-px tiles in two columns (orange "Préparation" + green "Prêt"). Orders not-yet-validated (PENDING / ACCEPT) are hidden. DELIVERY orders are hidden (they ride a separate driver-side flow). Once a cashier validates an order as delivered (status → DELIVERED), it disappears from the wall.

### §2.1 Anchors (file:line, verified)
- `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:13-50` — 2-column tile layout already implements owner's mental model (orange #B0004D bg + green #1AB759 bg, 40px font, queue numbers)
- `app/Services/OrderStatusScreenOrderService.php:37-94` (`list`) + `:147-200` (`listForBranch`) — backend filters
- `app/Services/OrderStatusScreenOrderService.php:53,158` — current `whereIn('status', [PREPARING, PREPARED])` ✅ matches owner spec
- `app/Services/OrderStatusScreenOrderService.php:45-52,150-156` — `where(token OR KIOSK OR TAKEAWAY+queue_number)` — **DOES NOT explicitly exclude DELIVERY**. A delivery order with a token would surface on OSS.
- `app/Enums/OrderType.php:7` — `DELIVERY = 5`

### §2.2 Frozen zones
- None in scope. Touches: service query body + (optionally) Vue tile coloring.

### §2.3 Sub-systems

#### Sub 2.1 — Backend explicit DELIVERY exclusion + delivered-vanish confirmation
The current query relies on token-presence as a heuristic. Owner wants a guarantee: DELIVERY orders never show on the customer wall, regardless of token state.

**Tasks**:
- T-2.1.1 — In `OrderStatusScreenOrderService::list` AND `::listForBranch`, add an explicit `where('order_type', '!=', OrderType::DELIVERY)` clause. Both code paths to stay byte-identical per the service's own docstring (line 144).
   - anchor: `app/Services/OrderStatusScreenOrderService.php:45-52` + `:150-156`
   - test: `tests/Feature/OrderStatusScreen/OssCustomerScreenFilterTest.php` (TO BE CREATED) — seeds a delivery order with token → asserts it is NOT in the OSS response; seeds takeaway/kiosk → assert IS present
- T-2.1.2 — Verify "DELIVERED status removes from OSS" (already enforced by `whereIn(['status' => [PREPARING, PREPARED]])`). Add a regression test that explicitly asserts the transition PREPARED → DELIVERED removes the order from the list.
   - anchor: same service
   - test: extends T-2.1.1 spec — `assertOssContains` then transition status → `assertOssDoesNotContain`
- T-2.1.3 — Sanity: confirm DELIVERY orders still show up SOMEWHERE the staff/driver can act on them. **Surfaces audited during execution**:
   - `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue` (cashier tracker) — confirm filter includes DELIVERY orders
   - Admin Orders list (`/admin/orders`) — confirm DELIVERY orders visible
   - If neither: that's a P0 blocker — owner-gate G2 surfaces it before we ship.

#### Sub 2.2 — Visual tile + auto-clear verification (mostly already-working, audit-only)
The component already implements the owner's tile spec. This sub-system runs an audit + visual capture to confirm no drift, not a rewrite.

**Tasks**:
- T-2.2.1 — Visual capture of `/admin/order-status-screen` with 0 / 1 / 3 / 8 orders seeded. Capture screenshots, Read + analyze:
   - No raw i18n labels
   - Orange column = PREPARING items only
   - Green column = PREPARED items only
   - No DELIVERY tiles
   - Empty-state "—" renders when 0 items
   - test: `tests/e2e/_oss-customer-screen-2026-05-18.spec.js` (TO BE CREATED) — Playwright capture + assertion-light
- T-2.2.2 — Wall update lag check: after creating an order via POS POST, the OSS endpoint MUST return it within one poll cycle (default poll = 2000ms degraded). Backend assertion only — visual lag deferred.
   - test: extends T-2.1.1 spec — assert real-time after status flip

### §2.4 Acceptance for §2 (GO criteria)
- ✅ Backend filter excludes DELIVERY explicitly (PHPUnit T-2.1.1)
- ✅ Transition to DELIVERED removes order from OSS (PHPUnit T-2.1.2)
- ✅ DELIVERY orders visible to staff somewhere else (PosOrdersTracker OR admin orders list) — gated by G2
- ✅ Visual capture clean (T-2.2.1)
- ✅ 0 regression on existing OSS-related tests (`php artisan test --filter='Oss|OrderStatus'`)

---

## §A — Agent army map (compact, per-task)

Per `~/.claude/skills/ultra-audit-profond/`, each task fans out the following **read-only** specialists in a SINGLE message (parallel):

| Role | Subagent | Scope for THIS GOAL |
|---|---|---|
| Architect | `Plan` | Anchor verification, schema/Vuex flow mapping, scope boundary |
| Security | `general-purpose` (RED mode) | Branch-scope leak / token presence / DELIVERY exposed PII on wall |
| UX / A11y | `general-purpose` | axe-core + WCAG 2.1 on POS landing + OSS wall (color contrast, focus order) |
| DBA | `general-purpose` | N+1 in `list()` / `listForBranch()`, index coverage of `order_type` filter |
| QA Visual | `general-purpose` + Playwright | Capture screenshots POS landing + OSS wall (3 seed states) |
| RED Visual | `general-purpose` | Hostile re-analyze QA's screenshots — different framing |

**Dispatch rule**: in ONE message, fire 5 Agent calls (Architect + Security + UX + DBA + QA-Visual) in parallel. RED-Visual fires after QA-Visual returns. Implementer fires sequential after synthesis. RED-team dispute fires AFTER implementer commits.

---

## §X — Convergence waves

### Wave 1 — Pre-flight (≤10 min)
- Read this plan + memory + advisor sign-off
- Confirm working-tree decision per §0.1
- Owner gates resolved per §G (or accept defaults and let owner correct)

### Wave 2 — Parallel audit (5 specialists, ~3 min wall-clock)
- Dispatch 5 read-only agents (Architect + Security + UX + DBA + QA-Visual) covering BOTH systems in their briefs (since the surfaces overlap on the cashier dashboard)
- Each agent writes a JSON report to `reports/test-e2e/pos-first-page-and-oss-2026-05-18/wave-2-<role>.json`
- Synthesize: dedupe findings, classify P0/P1/P2

### Wave 3 — Implement Sub 1.1 + 1.2 (POS catalog)
- Write PHPUnit tests FIRST per the named paths in §1
- Implement config + controller + Vue filter
- Run `php artisan test --filter='PosFeaturedCategories|PosCatalogFirstPage'` (the test names you'll create)
- Visual capture POS landing (Playwright spec from T-1.2.1)

### Wave 4 — Implement Sub 2.1 (OSS filter)
- Write PHPUnit test FIRST per T-2.1.1, T-2.1.2
- Implement DELIVERY exclusion in service `list` + `listForBranch` (byte-identical query bodies)
- Visual capture OSS wall (T-2.2.1)
- Verify DELIVERY orders visible in cashier tracker OR admin orders (G2 gate)

### Wave 5 — RED dispute + Adversarial visual + Cross-surface E2E
- RED-team agent: hostile review of POS landing + OSS wall (different framing from QA)
- Cross-surface flow: POS create → KDS show → OSS show → cashier mark delivered → OSS removes order
- If any P0 found → loop back to Wave 3 or 4

### Wave 6 — Close
- Full regression filter PHPUnit
- BRAIN.md §2 + §3 update
- Memory update (`memory/project_pos_first_page_oss_filter_2026-05-18.md`)
- Owner summary with honest scope + remaining manual checks

### Wave checkpoint protocol (each wave end)
1. All wave tasks PASS or documented baseline-fail
2. Frozen-zone diff = 0 LOC across this plan's commits
3. RED P0=0 / P1 deferred-with-reason
4. Visual screenshots Read + analyzed
5. BRAIN.md updated

### Wave interrupt-resume protocol
If a wave is cut (usage limit / owner pause): commit WIP with `wip(pos-first-page-oss): partial through T-X.Y.Z`, write `INTERRUPT_<wave>_<ts>.md` summary, update BRAIN.

### Convergence-failure protocol (3 heal loops on same cluster)
STOP, spawn Plan subagent for analysis, write `STUCK_<wave>_<ts>.md`, surface to owner with options A/B/C/D, do NOT auto-pick.

---

## §G — Owner gates

| Gate | Description | WHO | WHAT | WHERE | Default if no answer |
|---|---|---|---|---|---|
| G1 | Confirm featured category allowlist for POS first page | Physical owner | List of category IDs (or names) | This plan §1.1 + `config/pos.php` value | **Assumed default**: [344 Sandwich Cayenne, 345 Galette, 346 Sandwich Classique, 306 Tacos, 348 Frites, 347 Bols Gourmands]. Owner can edit `POS_FEATURED_CATEGORY_IDS` env to override. |
| G2 | DELIVERY orders visible elsewhere for staff (PosOrdersTracker OR admin/orders) | Claude orchestrator (audits) | Either confirmation found in code OR owner-explicit acceptance that delivery is V1.0.2 backlog (auto-dispatch deferred) | Wave 4 audit report | **Assumed default**: PosOrdersTrackerComponent shows delivery (verify in audit); if not, plan adds OSS-DELIVERY-gate task before merging. |
| G3 | PENDING / ACCEPT order display semantics | Physical owner | Confirm "just hide" (current behavior — backend filter excludes PENDING/ACCEPT) vs. "show in distinct color" | This plan §2.3 + OSS service line 53/158 | **Assumed default**: just-hide (matches owner's "not yet validated, we don't display it"). |

---

## §R — References

- `~/.claude/skills/ultra-audit-profond/SKILL.md` — per-task 14-step pipeline
- `~/.claude/skills/superpower-gstack/SKILL.md` — composition partner
- `~/.claude/skills/test-e2e/SKILL.md` — adversarial dual-team
- `CLAUDE.md` §§ 6, 7, 8 — visual mandate, frozen zones, NF525
- `PROJECT_BRAIN.md` §2 §3 — current state, recent fixes
- `memory/project_pos_payment_fix_2026-05-18.md` — Frites + Chicken Burger seeder pattern this plan mirrors
- `memory/feedback_pos_simulation_hardware_pattern.md` — bypass discipline (does NOT apply to this plan; included for context)
- `memory/reference_frozen_zones.md` — 13-file frozen list
- `app/Services/OrderStatusScreenOrderService.php` — canonical OSS filter source
- `app/Enums/OrderStatus.php` + `app/Enums/OrderType.php`

---

## §F — Final rule

DONE = both systems converged + visual evidence Read + RED 0 P0 + regression GREEN + owner gates resolved (or accepted defaults) + BRAIN updated. NOT done = any test fail, any P0 outstanding, any owner gate ignored, any visual not analyzed, any frozen-zone diff > 0. "Almost there" is **not** done — production-perfect or block.

**Estimated wall-clock** : ~90-120 min of Claude work (5 specialists in parallel ~3 min ×2 systems + sequential implements ~30 min each + visual + RED + close).

**Estimated context cost** : ~30-40 % of one session budget (lighter than V1.0.1 hardening cycle, comparable to today's Frites+payment session).
