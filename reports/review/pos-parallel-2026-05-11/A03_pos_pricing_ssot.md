# A03 — POS Pricing SSOT & POS↔Kiosk Parity

**Audit role** : Sub-agent A03 in parallel 20-agent POS adversarial audit.
**HEAD** : `a220b9bd8` on `feature/mobile-app-le-cayenne-2026-05-10`
**Past ref** : `reports/review/pos-ultra-audit-2026-05-09/99_VERDICT.md` (P0-14)
**Method** : Read-only, file:line verified, no test runs.

---

## §0. P0-14 STATUS — **REFUTED (CLOSED)**

Past audit claimed `tests/js/posKioskVariationParity.spec.js` compares
fixtures against themselves. **Spot-check refutes this**.

`tests/js/posKioskVariationParity.spec.js:36-38` imports the **real
production helpers** :

```js
import { computePosCartLineDisplayTotal } from '../../resources/js/helpers/posCartLineMath';
import { calculateKioskRunningTotal }    from '../../resources/js/helpers/kioskPricing';
import { kioskViandeCatalogForItem }     from '../../resources/js/helpers/kioskViandeCatalog';
```

Each case (1-7) invokes both helpers on equivalent payloads and asserts
`expect(posTotal).toBeCloseTo(kioskTotal, 2)` (e.g. case 7 line 388 ;
case 4 line 259 ; case 6 line 312). Case 7 (paid viande extra) is a
genuine cross-path parity assertion exercising `kioskSumPaidViandesSurcharge`
and `kioskViandeCatalogForItem`.

The header comment lines 1-23 explicitly document the historical bug
("`helper(x) === helper(x)` — tautologie sans valeur de parité") and the
remediation ("Maintenant chaque scénario … Calcule le total POS via
`computePosCartLineDisplayTotal`, … Kiosk via `calculateKioskRunningTotal`").

**Verdict** : P0-14 has been **fixed since iter15** (commit referenced in
header `[V14 T03 / P0-14 iter15 adversarial rewrite]`). The past audit
description is stale and must be retracted in the consolidated index.

---

## §1. Findings (fresh, ranked)

### P1 — POS wizard does NOT emit `role=menu_*` on POS-issued addon payloads
**File:line** : `public/js/pos-wizard.js` (FROZEN, ~296 KB Vanilla JS)
Grep `role.*menu_\|menu_full\|menu_frites\|menu_boisson` over the wizard
JS returns **0 matches**. The same grep over
`resources/js/components/frontend/kiosk/KioskWizardComponent.vue:1923-1929`
returns the explicit push :
```js
const role = menuChoice === 'full'   ? 'menu_full'
          : (menuChoice === 'frites' ? 'menu_frites' : 'menu_boisson');
```
`PricingService::menuRoleAdjustedAddonPrice` (lines 793-813) and
`CompositionSnapshotBuilder::menuRoleAdjustedAddonPrice` (lines 171-191)
return `$fullPrice` unchanged when role is empty / not `menu_*`. **POS
orders that include a menu addon are therefore charged the full 3.00 €
catalog price** even though the customer’s wizard step picked
"frites only" (60 %) or "boisson only" (40 %). Verified in
`CompositionSnapshotBuilder.php:136-138` :
```php
$payloadRole = (string) ($this->payloadValue($addon, 'role') ?? ($dbAddon->role ?? ''));
$effectiveRole = $payloadRole !== '' ? $payloadRole : (string) ($dbAddon->role ?? '');
$unitPrice = $this->menuRoleAdjustedAddonPrice($effectiveRole, $catalogPrice);
```
The fallback to `$dbAddon->role` matters only if seeded ; otherwise POS
pays 100 % while Kiosk pays the ratio → **silent +1.20 €/+1.80 € per
formula on the POS path**, opposite-sign to the E-001 bug Kiosk patched.

> NB : pos-wizard.js is FROZEN. This finding belongs to **owner gate** —
> sentinel test + LOCK plan required to add the `role` write.

### P1 — `composition_snapshot` carries `unit_price` rounded to 6 decimals while `total_price` rounds to 2 — fiscal reconciliation arithmetic loss
**File:line** : `CompositionSnapshotBuilder.php:77-78, 99-100, 145-146`
vs `PricingService.php:236` (`round($verifiedTotalPrice, 2)` in POS/kiosk
contexts).
Snapshot records `unit_price = round($unitPrice, 6)` and
`line_total = round($unitPrice * $qty, 6)`, but the persisted
`order_items.total_price` is rounded to 2 decimals (POS/kiosk rounding
flags `roundLineTotals=true` cf. `PricingRequest::forPos:62`). For a
`menu_boisson` addon with `catalogPrice = 3.00`, `ratio = 0.4` →
`unit_price = 1.20` (clean), but if config returns non-decimal-clean
ratios (e.g. `0.333…`), snapshot stores `0.999900` while line stores
`1.00`. **Reprint reconcile would flag the line as tampered** even though
both came from the same compute path. Low-probability today (ratios are
1.0/0.6/0.4) but a tripwire if owners ever introduce variable ratios.

### P2 — Two parallel pricing-config readers : risk of frontend/backend ratio drift
**File:line** :
 - `resources/js/helpers/kioskPricing.js:21-27` reads
   `window.foodkingConfig?.kioskMenuPricing`
 - `app/Services/Pricing/PricingService.php:800` reads
   `config('kiosk.menu_pricing', [])`
 - `app/Services/Pricing/CompositionSnapshotBuilder.php:178` reads the
   same key.
`config/kiosk.php` lines 37-41 (auth-form mode) AND lines 80+ (default
mode) both declare the same ratios. **However the JS reads from a
window-injected payload** (the Blade master must expose
`window.foodkingConfig.kioskMenuPricing` mirroring `config('kiosk.menu_pricing')`).
If a future change updates one but not the other, the wizard preview
diverges from backend total. No automated assertion guards the
JS↔PHP config equality — sentinel missing.

### P2 — `manualDiscount()` returns 0 silently when greater than subtotal
**File:line** : `DiscountCalculator.php:22-29`
```php
return $requested <= $subtotal ? $requested : 0.0;
```
A cashier entering a manual discount **larger** than the cart subtotal
(typo, comma vs dot) is silently ignored : the order is then charged at
full price with **no UX feedback** from this service (the controller
must surface the message itself). Search of POS controllers does not
show any rejection path → cashier sees the order go through at full
price, customer sees the discount line missing. P2 (POS UX), not P0
fiscal because state stays consistent.

### P3 — `lineTaxAmountFromTTC` falls back to `lineTaxAmount` when `taxRate <= -100`
**File:line** : `TaxCalculator.php:39-42`. Defensive but the fallback
returns the WRONG branch (HT add-on-top) when running in TTC mode. Dead
code for current rates (5.5/10/20) — kept for completeness, document as
deliberate noise.

### Negative findings (verified clean) :
- **Cents discipline** : pricing is float-based but **every persistence
  rounds with `round(.., 2)`** when `roundLineTotals=true` (POS+kiosk
  contexts). Integer cents arithmetic absent but acceptable given the
  ≤ 6-decimal snapshot floor.
- **`composition_snapshot` immutability** : all 4 writers
  (`OrderService.php:455,810,1241` + `FrontendOrderService.php:441` +
  `PricingService.php:291`) **write only at insertion time**. Grep
  `update.*composition_snapshot\|composition_snapshot.*=` outside
  `=>\s*json_encode` returns **0 post-create mutation sites**. SSOT
  contract holds.
- **POS↔Kiosk payload shape** : both call `PricingService::calculateOrder`
  (`OrderService.php:329,645,1094` + `FrontendOrderService.php:277`),
  same `Item::price + $dbVar->price + $dbExt->price + addon` math.
  `PricingRequest::forPos` and `::forKiosk` set identical rounding flags
  (`roundLineTotals=true, roundLineTax=true, roundOrderTotalTax=true,
  roundFinalOrderTotal=true, roundSubtotal=true`). Pricing is symmetric.
- **Cross-item-id guards** : `PricingService.php:152,182,207` reject
  variations/extras/addons whose `item_id` ≠ payload’s item.
- **Backend-authoritative** : `PosPricingSsotProofTest.php:32-134`
  proves a `total=0.01` forged payload is overwritten to 20.00 by SSOT,
  and `total_price` lines come from DB price.

---

## §2. Cross-validation
- P1 menu_role drift on POS path : confirmed by absence in
  `pos-wizard.js` AND by E-001 fix doc explicitly scoped to kiosk only
  (`PricingService.php:218-223`). Independent grep + reading the fix
  patch comment. **HIGH confidence**.
- P0-14 refutation : confirmed by header docstring + by direct import
  statements. **HIGH confidence**.

## §3. E2E parity scenarios proposed (Playwright)
1. **PARITY-A** — Tacos with 4 viande slots (`2 Kebab + 1 Merguez +
   1 Lardon`, prices 2.00/2.50/1.00). POS submits via `/api/admin/pos`,
   Kiosk via `/api/frontend/order`. Expect `order.total` equal ±0.001.
   Already covered as Feature test
   `PosKioskPricingParityTest::test_case_c_four_meat_2_plus_1_plus_1_totals_match`
   — promote to E2E for HTML payload realism.
2. **PARITY-B (NEW)** — **Menu-formula POS path** : POS wizard picks
   sandwich + `Menu boisson only` (40 %). Expect `order.total = 10.00
   (sandwich) + 1.20 (40% of 3.00 menu addon)`. Will **FAIL** today —
   POS pays 13.00 because no `role` is emitted ; once pos-wizard.js
   patched, will pass. Sentinel for P1 menu_role finding.
3. **PARITY-C** — Same tacos composition issued at HT (set
   `PRICING_TAX_INCLUSIVE=false` via test config) and TTC. Expect
   ratio invariant `total_HT * 1.10 == total_TTC` for a 10 % TVA item.
4. **PARITY-D** — POS manual discount exceeding subtotal : assert HTTP
   response includes `discount=0` and a `WARNING` message field (today
   silent). P2 finding sentinel.
5. **PARITY-E (snapshot-vs-line tripwire)** — After order creation,
   read `OrderItem::composition_snapshot.addons[].line_total` and assert
   `≈ order_items.total_price - item_price - sum(variations) - sum(extras)`
   within 0.01 €. Sentinel for the 6-decimal-vs-2-decimal P1.

## §4. Verdict
**SSOT integrity** : strong. The `PricingService` is genuinely
authoritative — proven by `PosPricingSsotProofTest`. Snapshot is
immutable post-insert.

**Open risks** :
1. **P1** : POS path leaks 1.20 €–1.80 € per menu-formula order because
   pos-wizard.js does not push `role=menu_*` on the menu addon payload —
   owner gate needed to patch frozen file.
2. **P2** : JS↔PHP `menu_pricing` ratio config dual-read without
   automated equality sentinel.
3. **P2** : Silent manual-discount swallow.

**P0-14 retired**. Update BRAIN.md §6 DECISIONS LOG to reflect P0-14
closure (iter15 V14-T03 rewrite is real, not stub).

## §5. BRAIN.md drift
- Past audit verdict §1 P0 table listed P0-14 as MEDIUM unresolved.
- Reality 2026-05-11 : P0-14 was rewritten and is now a real-helper
  parity sentinel covering 7 scenarios incl. paid viande extras + 86’d
  variations + slot-cap saturation. The drift is the failure to retire
  P0-14 in the consolidated index — re-classify as **CLOSED**.
- New drift candidate : `PROJECT_BRAIN.md` should list
  **"POS menu-formula pricing leak (pos-wizard.js role omission)"** as
  pending owner gate, parallel to the E-001 kiosk fix already merged.
