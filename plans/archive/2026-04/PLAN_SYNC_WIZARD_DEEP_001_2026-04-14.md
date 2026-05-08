# Plan – SYNC_WIZARD_DEEP_001 – 2026-04-14

## TASK_ID
SYNC_WIZARD_DEEP_001

## PRIMARY_MODEL
GPT-5.4

## Test Strategy
`playwright-critical-flow` + `local-validation`
PHPUnit: 191 existing + new ConcurrentOrderTest (A6)
Playwright flows:
1. Kiosk tacos XL: taille → 2 viandes → sauce → garnitures → formule → boisson → recap (prix arrondi)
2. Kiosk sandwich: pain → (sauce vide gérée) → supplements → menu → recap
3. Kiosk 3 produits: tacos + simple → 2 lignes distinctes panier

## PRIOR_CONTEXT
WIZARD_AUDIT_001: template fallback (P1), garnitures sync (P2), viande count alignment (P3), supplement filter (P4), cart merge (P7), addon.variations bug (P11).
UX_FLOW_001: KDS filter reset, empty states, OSS/delivery key fixes, error handling.
Both cycles: 191 tests passing, frozen zones intact.

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| FrontendOrderService.php | A1: round() on monetary values | Write (GATE REQUIRED) | No | No (dispatch position unchanged) |
| OrderService.php | A1: round() on monetary values | Write (GATE REQUIRED) | No | No (dispatch position unchanged) |
| kioskPricing.js | A2: Math.round on display totals | Write | No | No |
| KioskWizardComponent.vue | A3: sauce step visibility, B2: hasViandeVariations, B3: taille heuristic, B9: selections reset | Write | No | No |
| KioskStepSauceComponent.vue | B4: empty sauce message | Write | No | No |
| KioskAppComponent.vue | A5: idle timeout 60s → 180s | Write | No | No |
| kioskMenuCache.js | A4: stale price warning | Write | No | No |
| KitchenDisplaySystemOrderService.php | A8: sort audit (read; write if fix needed) | Read/Write | No | No |
| KioskOrderSummaryComponent.vue | B8: audit display rounding | Read | No | No |
| KioskStepMenuComponent.vue | B7: audit boisson enforcement | Read | No | No |
| tests/Feature/ConcurrentOrderTest.php | A6: create new test | Write | No | No |
| docs/BUSINESS_RULES.md | A5+A7: document idle timeout + stock gap | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- Any database migration
- CSS / style files
- Auth middleware / guards
- Loyalty system (implementation)
- Pusher/Soketi configuration
- pos-wizard.js (audited in WIZARD_AUDIT_001)

## INVARIANTS_AT_RISK
- **Backend pricing SSOT** — A1 modifies pricing services (strengthens SSOT with rounding). GATE REQUIRED.
- **OrderStatus enum** — A8 audit confirms enum usage in KDS sort
- **branch_id data isolation** — not weakened by any change
- **Dispatch after DB commit** — A1 does NOT move dispatch calls
- **Frozen zones** — FrontendOrderService.php + OrderService.php: GATE REQUIRED for A1
- **OrderService / FrontendOrderService symmetry** — A1 applies same pattern to both

## GATE_CONDITIONS
- **GATE OPEN (A1):** Frozen zone modification required for rounding fix. Gate brief at `docs/gates/GATE_SYNC_WIZARD_DEEP_001_2026-04-14.md`. Cycle CANNOT proceed to EXECUTE until gate is cleared by human.

## SYMMETRY_NOTE
A1 modifies both OrderService and FrontendOrderService with identical `round($value, 2)` pattern. Symmetry review is mandatory at audit: same rounding applied at equivalent calculation points in both services.

## Items Already Handled (from prior cycles or existing code)
| Item | Status | Evidence |
|---|---|---|
| B1 — simple with 0 supplements | ALREADY HANDLED | `shouldShowStep('supplements')` filters by `item.extras.some(price > 0 && !isSauce)`. Empty extras → supplements step hidden → recap only. |
| B5 — back navigation index | ALREADY HANDLED | `prevStep()` uses `currentStepIndex` on filtered `activeSteps`. No raw index issue. |
| B6 — garnitures race condition | ALREADY HANDLED | Fixed in WIZARD_AUDIT_001 (P2): `userInteracted` flag + `mounted()` adoption. |
| B7 — boisson obligatoire | ALREADY HANDLED | `canAdvance` in wizard (L.317-323) blocks when `menuChoice ∈ ['full','boisson']` and `boissonChoice` is null. |
| B8 — recap prix non arrondi | ALREADY HANDLED | `formatPrice()` uses `toFixed(digits)` with `digits=2` via `kioskFormatPrice.js`. Display is already rounded. |
| B10 — item.category null | ALREADY HANDLED | Fixed in WIZARD_AUDIT_001 (P1): `item.category?.wizard_template` with safe optional chaining, falls to `detectTemplateFromName()`. |

## Execution Steps

### Step 1 — A1: Backend rounding (REQUIRES GATE CLEARANCE)
**Files:** `app/Services/FrontendOrderService.php`, `app/Services/OrderService.php`
**Change:** Add `round($value, 2)` at all monetary calculation points before DB persistence.
**FrontendOrderService:** L.295 ($verifiedTotalPrice), L.302 ($taxPrice), L.452-455 (order totals)
**OrderService:** L.661-668 ($verifiedTotalPrice, $taxPrice), L.730, L.751-753 (order totals)
**DO NOT EXECUTE UNTIL GATE IS CLEARED.**

### Step 2 — A2: Client-side rounding in kioskPricing.js
**File:** `resources/js/helpers/kioskPricing.js`
**Change:** Wrap final return of `calculateKioskRunningTotal()` and `getKioskMenuAddonPrice()` with `Math.round(result * 100) / 100`.

### Step 3 — A3: Sauce step visibility
**File:** `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**Change:** Add `shouldShowStep('sauce')` logic: check if item has variations with a sauce-related attribute (attribute name includes 'sauce'). If no sauce variations exist, return false (hide the step).

### Step 4 — A4: Stale price warning
**File:** `resources/js/helpers/kioskMenuCache.js`
**Change:** Export a helper `isSnapshotStale(savedAt, thresholdMs = 4 * 60 * 60 * 1000)` that returns true when cache > 4h. The wizard or cart can use this to show a toast when submitting with stale data.
**File:** `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` or `kioskCart.js`
**Change:** Before or after submission, check `isSnapshotStale()` and show a warning toast if true.

### Step 5 — A5: Kiosk idle timeout correction
**File:** `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Change:** Lines 127-128:
- `IDLE_TIMEOUT_MS = 180000` (3 min)
- `STILL_HERE_MS = 150000` (2.5 min = 30s before reset)
**File:** `docs/BUSINESS_RULES.md`
**Change:** Add section documenting canonical kiosk idle timeout = 3 min.

### Step 6 — A6: ConcurrentOrderTest
**File:** `tests/Feature/ConcurrentOrderTest.php` (create)
**Tests:**
- Test A: two requests with same idempotency_key → one order created
- Test B: two simultaneous requests without idempotency_key → two distinct orders with different queue_numbers
- Test C: loyalty_points = 100, two simultaneous requests → points deducted once only

### Step 7 — A7: Stock management documentation
**File:** `docs/BUSINESS_RULES.md`
**Change:** Add "Stock Management (not implemented)" section documenting the gap and planned schema.

### Step 8 — A8: KDS sort audit
**File:** `app/Services/KitchenDisplaySystemOrderService.php`
**Audit:** Default is `id DESC` (newest first). For chef workflow, oldest orders should have priority (ASC).
**Decision:** If the current behavior is `id DESC` and the KDS UI shows columns per order type, the sort order matters for prioritization. Change default to `id ASC` if this improves chef workflow. Document decision.

### Step 9 — B2: Sandwich viande — check extras too
**File:** `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**Change:** Extend `hasViandeVariations()` to also check `item.extras` for extras with name/group_label containing 'viande'.

### Step 10 — B3: Taille heuristic word boundary
**File:** `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**Change:** In `detectViandeCount()`, replace `name.includes(' l ')` with a regex `/\bl\b/i` (word boundary) to avoid false positives on words like "l'été" or "Le classique".

### Step 11 — B4: Sauce step empty state
**File:** `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue`
**Change:** When sauce list is empty (0 options after filtering), show message "Aucune sauce disponible" + "Continuer" button that emits advance to next step.

### Step 12 — B9: Wizard selections reset between products
**File:** `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**Change:** In `mounted()` or `fetchItemById()` success callback, reset `selections` to initial state before initializing for the new product. Ensure `currentStepIndex` is also reset to 0.

### Step 13 — Execution report
Write `reports/execution/REPORT_SYNC_WIZARD_DEEP_001_2026-04-14.md`.

## SCOPE_PRESSURE
[Populated mid-cycle only.]

## ESCALATION
[Populated mid-cycle only.]

## Audit Status
[x] Pending
[ ] Passed — cycle closed
[ ] Gate opened — `docs/gates/GATE_SYNC_WIZARD_DEEP_001_2026-04-14.md`
