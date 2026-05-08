# Plan – WIZARD_AUDIT_001 – 2026-04-14

## TASK_ID
WIZARD_AUDIT_001

## PRIMARY_MODEL
GPT-5.4

## Test Strategy
`playwright-critical-flow`
Flows after corrections:
1. Kiosk: idle → type commande → tacos XL (2 viandes) + sauce + formule frites → panier → vérifier 2 lignes distinctes
2. POS: login caissier → sélection produit → variante + extra → paiement cash → commande visible KDS

## PRIOR_CONTEXT
Last cycle MULTISURF_001 added router aliases and landing_url seeds. Frozen zones (OrderService, FrontendOrderService) confirmed intact. 191 tests passing. No prior wizard-specific audit cycles recorded.

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| KioskWizardComponent.vue | P1 template fallback, P2 init state, P3 viande count | Write | No | No |
| KioskStepViandeComponent.vue | P3 maxViandes alignment with parent | Write | No | No |
| KioskStepGarnituresComponent.vue | P2 init sync timing | Write | No | No |
| KioskStepSupplementsComponent.vue | P4 supplement filter review | Read (Write if fix needed) | No | No |
| KioskStepSauceComponent.vue | P9 sauce key lookup | Read (Write if fix needed) | No | No |
| KioskStepMenuComponent.vue | P6 menu addon pricing review | Read | No | No |
| KioskOrderSummaryComponent.vue | Display correctness audit | Read | No | No |
| kioskCart.js | P7 merge signature review | Read (Write if fix needed) | No | No |
| kioskPricing.js | P6 addon pricing, sauce unit price | Read | No | No |
| ItemComponent.vue (POS) | P5 variation format, P8 wizard bridge, addon.variations bug | Write | No | No |
| PosComponent.vue | P5 checkout row format audit | Read | No | No |
| PaymentComponent.vue | P10 cash/card payment logic | Read (Write if fix needed) | No | No |
| posCart.js | P7 POS merge signature audit | Read | No | No |
| posCartLineMath.js | Pricing arithmetic audit | Read | No | No |
| pos-wizard.js (public/js) | P1 template detection, P8 active confirmation | Read (Write if fix needed) | No | No |
| ItemCategory.php | wizard_template field audit | Read | No | No |
| Item.php | Model shape audit | Read | No | No |
| ItemExtra.php / ItemAttribute.php | Extras/attributes shape | Read | No | No |

## SUBSYSTEMS_OFF_LIMITS
- app/Services/OrderService.php — frozen zone
- app/Services/FrontendOrderService.php — frozen zone
- Any database migration
- Any auth/middleware file
- Any dispatch/event/job logic
- CSS / style files (no cosmetic changes)
- Loyalty system (audit read-only, no corrections)

## INVARIANTS_AT_RISK
- **Backend pricing SSOT** — kioskPricing.js and posCartLineMath.js compute client-side totals for display; corrections must not shift pricing authority to the frontend. Verify server reconciles all submitted totals.
- **branch_id data isolation** — wizard loads items/categories via branch-scoped API; corrections must not bypass branch filtering.
- **OrderService / FrontendOrderService symmetry** — P5 audits the variation format sent to these services from both surfaces; services themselves are frozen (read-only review of acceptance logic).
- **Frozen zones** — OrderService.php and FrontendOrderService.php: read-only. No edits.

## GATE_CONDITIONS
- **Conditional gate (P5):** If audit reveals POS variation format is silently dropped/ignored by FrontendOrderService → gate required (frozen zone edit needed).
- **Conditional gate (P8):** pos-wizard.js is confirmed ACTIVE (loaded from master.blade.php). No gate needed unless broken path discovered.

## SYMMETRY_NOTE
OrderService and FrontendOrderService are in READ scope for P5 format audit. Neither will be modified. Symmetry review is required at audit: both services must accept the variation/extras format sent by Kiosk and POS respectively. If asymmetry is found, gate opens (frozen zone edit required for correction).

## Execution Steps

### Step 1 — P1: Template detection hardening (Kiosk)
**File:** `KioskWizardComponent.vue`
**Problem:** `effectiveWizardTemplate()` falls back to `detectTemplateFromName()` when `wizard_template` is empty or `'simple'`. The heuristic is fragile.
**Fix:** Extend `effectiveWizardTemplate()` to also check `item.category?.wizard_template` (category-level template from API `ItemResource`) before falling to name heuristic. Priority chain: `item.wizard_template` → `item.category_wizard_template` (if API exposes it) → `detectTemplateFromName()`. If the API already includes `category_wizard_template` on the item payload, use it. If not, check `resolvedItem` shape and use whatever category template field is available.

### Step 2 — P2: Pre-selection cleanup
**File:** `KioskWizardComponent.vue` + `KioskStepGarnituresComponent.vue`
**Problem:** `initGarnitures()` pre-selects all free extras (price=0) as `true`. This is arguably intended UX (garnitures ON by default), but the timing with child mount creates a race.
**Fix:**
- In `KioskWizardComponent.vue`: ensure `initGarnitures()` runs BEFORE any step component mounts (call it in `created` or at item resolution, not in a delayed watcher).
- In `KioskStepGarnituresComponent.vue`: the "empty-only" sync watcher is correct design but fragile. Add a `nextTick` guard so the initial sync runs after parent data is available. If parent garnitures are not empty at mount, adopt them immediately.
- Verify no other step has unintended pre-selection (viandes, sauces, pain should all start empty).

### Step 3 — P3: Viande count alignment
**Files:** `KioskWizardComponent.vue`, `KioskStepViandeComponent.vue`
**Problem:** `detectViandeCount()` (wizard) and `maxViandes` (step) use different heuristic code paths when `_tailleMeta.viandeCount` is absent. This causes mismatches.
**Fix:**
- Extract a single shared `resolveMaxViandes(selections, item)` utility (or put it on the wizard and pass result as prop).
- `KioskStepViandeComponent` should use ONLY `selections._tailleMeta.viandeCount` (from parent) as its max, never run its own name heuristic. If `_tailleMeta` is missing, fall back to a value the wizard explicitly provides via prop/selection.
- Remove duplicated heuristic from the step component.

### Step 4 — P4: Supplement filter audit
**File:** `KioskStepSupplementsComponent.vue`
**Problem:** Filtering by `group_label.includes('sauce')` or `name.includes('sauce')` can hide legitimate paid supplements.
**Audit:** Review DB data shape — do any real supplements have "sauce" in their name? Check `ItemExtra` records from seeder.
**Fix (if needed):** Tighten filter to exclude only `group_label === 'sauce'` (exact match, not substring) when group_label is non-empty. When group_label is empty, keep name heuristic but add a comment documenting the assumption.

### Step 5 — P5: Variation format audit (Kiosk vs POS → Backend)
**Files:** `KioskWizardComponent.vue` (buildCartItem), `ItemComponent.vue` (POS buildPosCartMainPayload), `kioskCart.js` (submitOrder), `PosComponent.vue` (orderSubmit)
**Audit:** Trace the exact payload shape sent to `POST frontend/order` (kiosk) and `posOrder/save` (POS). Compare `item_variations` format:
- Kiosk: normalized array `[{id, variation_name, name}]`
- POS: object `{variations: {attrId: varId}, names: {attrName: chosenName}}`
Then read `FrontendOrderService` and `OrderService` acceptance logic (READ ONLY) to confirm both formats are handled. Document findings in the report.
**If divergence found:** Log under SCOPE_PRESSURE; do not edit frozen services. Gate will open at audit if fix requires frozen zone edit.

### Step 6 — P6: Menu addon pricing audit
**File:** `kioskPricing.js`
**Audit:** `getKioskMenuAddonPrice()` takes first addon with "menu" in name. Verify from seeder data: does any item have multiple "menu" addons? If single addon per item confirmed → document. If multi-addon exists → fix to use correct selection or flag.

### Step 7 — P7: Cart merge signature review
**Files:** `kioskCart.js`, `posCart.js`
**Problem:** Kiosk merge uses `item_id + stringified(variations) + stringified(extras)` — no instruction, no menu choice. Two identical items with different instructions or menu choices could incorrectly merge.
**Fix (if needed):** Add `instruction` and `menuChoice` (or a serialized addon key) to the kiosk merge comparison. Align with POS signature logic which already includes instruction + `posLineAddonsSignature`.

### Step 8 — P8: pos-wizard.js status clarification
**File:** `public/js/pos-wizard.js`
**Finding:** File EXISTS and is LOADED from `master.blade.php`. It is ACTIVE, not dead code. The `wizard:add-to-cart` event bridge to `ItemComponent.vue` is functional.
**Action:** Document this finding. No code change needed unless broken path discovered during audit.

### Step 9 — P9: Sauce key consistency
**File:** `KioskStepSauceComponent.vue`, `KioskWizardComponent.vue` (`kioskFindSauceVariation`)
**Audit:** Trace sauce selection from step → wizard → buildCartItem. Verify that numeric IDs are preferred and name fallback is safe. Check for collision risk with duplicate sauce names.
**Fix (if needed):** Ensure `kioskFindSauceVariation` always tries ID-based lookup first and logs a warning (dev only) when falling to name match.

### Step 10 — P10: Payment logic audit (POS)
**File:** `PaymentComponent.vue`
**Audit:**
- Cash: verify `pos_received_amount` is sent to server; confirm server validates amount >= total (no client check needed per SSOT).
- Card: verify `pos_payment_note` (last 4 digits) is informational only and no price calculation happens client-side.
- Confirm both cash and card paths result in same order status on server.
**Fix (if needed):** Only if a client-side price calculation is found that should not exist.

### Step 11 — ItemComponent addon.variations bug fix
**File:** `resources/js/components/admin/pos/ItemComponent.vue` line 685
**Bug:** `addon.variations !== "undefined"` compares against the STRING `"undefined"` instead of checking `typeof addon.variations !== "undefined"`. This means when `addon.variations` is an actual object, the check passes (correct), but when it's literally the string `"undefined"` (rare edge case), it fails silently.
**Fix:** Change to `typeof addon.variations !== 'undefined' && addon.variations && Object.keys(addon.variations).length !== 0`.

### Step 12 — Audit report
**File:** `reports/execution/REPORT_WIZARD_AUDIT_001_2026-04-14.md`
Write structured report covering all P1–P10 findings, changes made, and unresolved items.

## SCOPE_PRESSURE
[Populated mid-cycle only. Leave blank at plan time.]

## ESCALATION
[Populated mid-cycle only. Leave blank at plan time.]

## Audit Status
[ ] Pending
[x] Passed — cycle closed
[ ] Gate opened — `docs/gates/GATE_WIZARD_AUDIT_001_2026-04-14.md`
