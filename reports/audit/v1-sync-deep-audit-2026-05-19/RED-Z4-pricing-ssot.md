# RED-Z4 — Pricing SSOT Audit
**Date**: 2026-05-19 · **Mode**: read-only adversarial · **Agent**: RED-Z4 (1 of 8)
**Branch**: v1-0-1-hardening-2026-05-17 · **HEAD**: 1e7c65ecc
**Scope**: PricingService SSOT, composition_snapshot immutability, frontend total acceptance paths, promo/loyalty/tax/refund interactions.

---

## A. Anchors verified (file:line — read this session)

| Anchor | Evidence |
|---|---|
| Single backend authority | `app/Services/Pricing/PricingService.php:36-370` — `calculateOrder()` reads `Item::price` + `ItemVariation::price` + `ItemExtra::price` + `ItemAddon->addonItem->price` from DB (line 57-98); `$item->price` from payload is **never read**. |
| Cross-item injection guards | `PricingService.php:152-156` (variations), `:182-186` (extras), `:207-211` (addons) — throw 422 if `dbVar->item_id !== item->item_id`. `enforceCrossItemGuards=true` on all 4 surface factories: `PricingRequest::forWeb/forPos/forKiosk/forTable` (`PricingRequest.php:30-108`). |
| Snapshot creation site (SSOT path) | `PricingService.php:266-298` — INSERT-only via `OrderItem::insert($itemsArray)` (called by callers). 5 INSERT sites total: `OrderService.php:474, 834, 1290`, `FrontendOrderService.php:441`, plus `PricingService::calculateOrder` returning the array. |
| Snapshot immutability sentinel | `tests/Feature/Sentinels/Zone5PricingSsotConvergenceSentinelTest.php:189-252` (PR03) — recursively greps `app/` for any `UPDATE/save/setAttribute/fill/forceFill` on `composition_snapshot`. Asserts ZERO offenders. |
| PR04 frozen-price proof | `Zone5PricingSsotConvergenceSentinelTest.php:262-385` — admin re-prices Item mid-cycle → historical `composition_snapshot` + `total_price` + `price` columns remain frozen; NEW order picks up the new price. |
| Refund snapshot carry-forward | `app/Services/Order/RefundWithCounterEntryService.php:121-143` — `OrderItem::create(['composition_snapshot' => $item->composition_snapshot, …])`. Only `quantity` + `tax_amount` + `total_price` are negated; snapshot field is verbatim. `allergens_snapshot` also carried verbatim (`:141`). |
| Reorder isolation (SSOT NOT trusted) | `app/Http/Controllers/Admin/PosOrderController.php:197-258` + `tests/Feature/Sentinels/PosReorderHistoricalPricingSentinelTest.php:143-152` — reorder returns historical snapshot for UX cart re-import, but the subsequent `/api/admin/pos` POST routes through `PricingService` which re-reads current DB price (1.00→10.00 verified). |
| Frontend-total stripped | `OrderService.php:330` (`unset($validated['total'], …])`), `:631` (POS), `FrontendOrderService.php:257` (kiosk/web). `PosOrderRequest.php:82,101` + `OrderRequest.php:148,160` declare `total/subtotal/discount` as `['nullable', 'numeric', 'min:0']`. Pinned by `tests/Feature/Pos/PosOrderRequestNoClientTotalsTest.php`. |
| HMAC quote sealing | `app/Services/Order/OrderQuoteService.php:109-125` (`sealForCommit`), `:330-355` (`resolveReplay`) — HMAC SHA-256 of canonical payload; consumed at order create; total mismatch → 409. POS + Kiosk path call `sealForCommit` (PosOrder via OrderService:686+; Kiosk via FrontendOrderService:502-508). |
| Tax inclusive (TTC) mode | `app/Services/Pricing/TaxCalculator.php:32-48` — extracts tax from TTC via `ht = ttc/(1+rate/100); tax = ttc-ht`. Default `PRICING_TAX_INCLUSIVE=true` (`config/pricing.php:31`). |
| Stripe round-before-cast | `Zone5PricingSsotConvergenceSentinelTest.php:393-409` — `(int) round((float)$total * 100)` proven on €9.99/€0.99/€12.345. |
| Split-payment server-total revalidation | `app/Services/Payments/SplitPaymentService.php:51-167` — sum of tranches compared against `$order->total` (server SSOT), tolerance 1€, cents-arithmetic. |

---

## B. Findings P0 → P3

### P0-Z4-01 — **Unbounded `role` injection on `/api/frontend/order` + `/api/admin/pos` allows menu-ratio discount on ANY addon**

**Evidence**:
- Attack input: `items[].item_addons[]: {id:<any addon>, quantity:1, role:'menu_boisson'}` — POSTed to `/api/frontend/order` or `/api/admin/pos`.
- `PricingService.php:224-228` reads `$addon->role` from payload and forwards to `menuRoleAdjustedAddonPrice` (`:793-813`), which switches on the string prefix `menu_` only.
- `CompositionSnapshotBuilder.php:136-138`: `$payloadRole = $addon->role ?? $dbAddon->role` — **payload role overrides DB role with no validation**.
- `app/Rules/ValidJsonOrder.php` (read in full): validates `item_id` + `quantity` + `instruction.max:500`. Zero `role` validation.
- `app/Http/Requests/Concerns/ValidatesOrderItemVariations.php` — `grep -n "role"` returned **empty**.
- `app/Http/Requests/OrderRequest.php` + `app/Http/Requests/PosOrderRequest.php` — `grep -n "role"` returned **empty** (apart from RBAC contexts).
- Only `app/Http/Requests/Kiosk/PricingPreviewRequest.php:61` validates role, and only as `['sometimes','nullable','string','max:32']` — **no enum/whitelist on the values**.

**Impact**:
- 5€ "Extra fromage" addon + `role='menu_boisson'` → backend charges `5 × 0.4 = 2€`.
- `composition_snapshot.addons[].unit_price` persists 2€ (line 145 builder) → sealed under-priced row.
- **Same NF525 §V breach pattern that the borne E-001 fix was originally designed to CLOSE** — it now flips the other way: legitimate menu-mode fix becomes an attack vector when the role string is not bound to the addon's actual menu membership.

**Surfaces hit**:
- POST `/api/frontend/order` (kiosk + web + mobile-app) via `OrderRequest` → `FrontendOrderService::myOrderStore` → `PricingService::calculateOrder` (forKiosk/forWeb).
- POST `/api/admin/pos` via `PosOrderRequest` → `OrderService::posOrderStore` → `PricingService::calculateOrder` (forPos).
- POST `/api/frontend/pricing/preview` via `PricingPreviewRequest` — same gap (preview is the **rehearsal**, not the sale; still leaks the under-price into the Quote HMAC seal because `OrderQuoteService::canonicalPayload` includes addons but not the role meaning, only its raw string).

**Severity**: **P0** — revenue leak per order, NF525 sealed-under-price chain mismatch, replicable trivially via curl/Postman, no auth bypass needed (any valid kiosk:order or POS token).

**Suggested fix (V1.0.2)**:
1. In `PricingService::menuRoleAdjustedAddonPrice` — require `$dbAddon->addonItem` to belong to an Item whose `category.has_menu=true` AND parent item has `wizard_template ∈ ('burger','tacos',…)` AND payload role matches a per-addon whitelist persisted on `item_addons` (e.g. `allowed_roles` JSON column).
2. Short-term application-side mitigation (no migration): reject ratio application when `dbAddon->role` is NULL/empty and only honor `menu_*` roles when `dbAddon->role` itself starts with `menu_`. Snippet:
   ```php
   $effectiveRole = $payloadRole !== '' ? $payloadRole : (string) ($dbAddon->role ?? '');
   if (str_starts_with($effectiveRole, 'menu_') && ! str_starts_with((string)($dbAddon->role ?? ''), 'menu_')) {
       $effectiveRole = ''; // payload-only menu_* on non-menu addon → reject ratio
   }
   ```
3. Add a sentinel — POST forged role on non-menu addon → assert charged price = full catalog price.

---

### P1-Z4-02 — Latent NF525 chain mismatch when `PRICING_USE_SSOT=false` (legacy path emits snapshot.addons but does not charge them)

**Evidence**:
- `config/pricing.php:9` — `PRICING_USE_SSOT` defaults to `true` (default GOOD).
- Legacy path: `OrderService.php:367-501` (`myOrderStore`), `:700-840+` (`posOrderStore`), `:1162-1300+` (`tableOrderStore`), `FrontendOrderService.php:296-458`.
- Each legacy loop reads ONLY `dbVariations` + `dbExtras` (no `$dbAddons` bulk-load). `addonTotal` is never accumulated into `$realSubtotal`.
- `CompositionSnapshotBuilder->build($item, $dbVariations, $dbExtras)` — third-pos `$dbAttributes=null`, fourth-pos `$dbAddons=null`. The builder then **lazy-loads addons internally** (`CompositionSnapshotBuilder.php:114-115`): `ItemAddon::query()->with('addonItem')->whereIn('id', …)->get()->keyBy('id')`.
- Result: when `PRICING_USE_SSOT=false`, `composition_snapshot.addons` will be **populated with full catalog prices**, but `order_items.total_price` + `orders.total` are **missing addonTotal entirely**. Sealed-row arithmetic disagreement.

**Impact (conditional)**:
- ONLY fires when env flag `PRICING_USE_SSOT=false` is set. BRAIN §2 confirms V1 Le Cayenne LOCAL prod has default-true. Latent until someone flips the flag (e.g. emergency rollback).
- Severity downgraded to **P1** because:
  - Default is safe.
  - Boot guards (`AppServiceProvider:78-145`) do not currently include `PRICING_USE_SSOT` — recommendation: **promote it to a refuse-to-boot invariant** alongside POS_SIMULATION_HARDWARE.

**Suggested fix**:
- Add `pricing.use_ssot_service=true` to `AppServiceProvider::boot()` production invariants.
- OR delete the legacy path entirely (BRAIN-acknowledged technical debt; 4 large dead branches).

---

### P2-Z4-03 — `OrderRequest::validateDeliveryMinimumOrder` trusts client `subtotal` for floor check

**Evidence**: `app/Http/Requests/OrderRequest.php:270-294` — `$subtotal = (float) $this->input('subtotal', 0)`. If `$subtotal < $minimum`, reject. Conversely, a forged `subtotal=999.99` on a real 5€ order passes the floor check.

**Impact**: A user can bypass branch-level delivery minimum (e.g. 50€ minimum to order delivery) by spoofing client subtotal. PricingService still SSOT-computes the real total downstream, so revenue is correct, but the order is shipped under-threshold → operations + economic leak (delivery fee absorbs the loss). **P2** (operational, not fiscal/security).

**Suggested fix**: Compute floor against `PricingService::calculateOrder` SSOT result, not `$request->input('subtotal')`. Hoist the rule out of `OrderRequest` and into `OrderService::myOrderStore` post-pricing-calc.

---

### P3-Z4-04 — composition_snapshot has zero DB-trigger immutability; sentinel is grep-only

**Evidence**:
- `OrderItem.php:31-83` — `composition_snapshot` is in `$fillable` (line 44) + cast `'array'` (line 71). Any future `OrderItem::query()->where(...)->update(['composition_snapshot' => …])` would compile.
- `Zone5PricingSsotConvergenceSentinelTest.php:189-252` (PR03) — grep-based regex sentinel catches the 7 common UPDATE/save patterns. Does NOT catch obfuscation (string concat, variable column name, DB::raw).
- `database/migrations/2026_04_22_000020_add_composition_snapshot_to_order_items.php:10-12` — adds the column as `json nullable`. No `BEFORE UPDATE` trigger.
- Wave 1 R5 / V1.1 backlog already documents this as P3.

**Severity**: **P3** confirmed (defense-in-depth, not active V1 ship blocker).

**Suggested fix (V1.0.2 / V1.1)**:
```sql
CREATE TRIGGER order_items_snapshot_immutable
BEFORE UPDATE ON order_items
FOR EACH ROW
BEGIN
  IF NEW.composition_snapshot IS NOT NULL
     AND OLD.composition_snapshot IS NOT NULL
     AND NEW.composition_snapshot <> OLD.composition_snapshot THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NF525 §V — composition_snapshot is immutable';
  END IF;
END;
```
(Mirror the trigger pattern already used on `audit_logs` + `z_reports`.)

---

### P3-Z4-05 — Mixed line-total rounding semantics web/table vs pos/kiosk

**Evidence**: `PricingRequest::forWeb` + `forTable` use `roundLineTotals: false, roundLineTax: false, roundOrderTotalTax: false, roundFinalOrderTotal: false, roundSubtotal: false`. `forPos` + `forKiosk` flip ALL FIVE to true (`PricingRequest.php:30-108`).

**Impact**: A web-app order for the same cart returns subtle decimal-place differences vs the same cart on POS/kiosk. Stripe.php round-before-cast (`(int) round($total * 100)`) catches the cents level, so payment is correct. Receipts may show €19.985 vs €19.99 if any consumer prints `lineSubtotalExTax` without re-rounding.

**Severity**: **P3** — cosmetic. Possible NF525 receipt micro-divergence not legally material at €0.005 level, but worth aligning V1.0.2 for cross-surface receipt parity.

---

## C. Hard questions for owner (sharp, hostile)

1. **Role injection (P0-Z4-01)** — Have you ever POSTed a curl to `/api/frontend/order` with `role='menu_boisson'` on a non-menu addon and watched the price collapse 60%? If yes, what stops it? If no, can we attempt it on staging together?
2. Was the original borne E-001 fix supposed to bind `role` to addon membership, or only to "the kiosk wizard pushes the menu addon and tags it"? Trust on the producer ≠ enforcement on the consumer — confirm intent.
3. `PRICING_USE_SSOT=false` rollback — has this ever been tested in prod? Do we have a smoke-test that catches addonTotal vanishing if someone flips the flag during a hot incident?
4. Why is `PRICING_USE_SSOT` not in `AppServiceProvider`'s refuse-to-boot list alongside `POS_SIMULATION_HARDWARE`?
5. `OrderRequest:282` reads `subtotal` from client for the delivery-minimum check — should that be hoisted out of FormRequest and back into the service after PricingService SSOT?
6. composition_snapshot stays `$fillable` on OrderItem. Is the V1.0.2 plan still grep-only sentinel, or is the DB trigger now scheduled?
7. Mobile (standalone) — does it call `/api/frontend/order` with addons? If so, mobile sends `role` strings too — is the same P0-Z4-01 attack vector reachable from mobile?
8. POS Vanilla JS wizard (frozen) — does it ever emit `role` strings other than via the Vue `KioskWizardComponent`? grep of `public/js/pos-wizard.js` shows it computes display totals only; confirm it does NOT inject `role` into `/api/admin/pos` payload.
9. Quote canonical payload (`OrderQuoteService.php:445-454`) includes `addons` with `id`+`quantity` but does NOT include `role` in modifiers. Is the HMAC seal blind to role mismatch?
10. Web/table use `roundLineTotals=false`; POS/kiosk use `true`. Same item identical cart — divergent line totals on receipts. Acceptable for V1 fiscal export?
11. `assertManualDiscountAllowed` (PricingService) is invoked AFTER `calculateOrder` for POS (`OrderService.php:686-692`). What if the SSOT subtotal differs slightly from the cashier's preview at the moment of submit — discount > backend subtotal aborts the order; cashier UX impact tested?
12. `kioskLoyaltyRedemption` (`DiscountCalculator.php:36-64`) reads `Settings::group('loyalty_setup')->get('loyalty_points_for_1_euro_discount', 100)` inside the pricing transaction. Cached? Race condition with admin changing the rate mid-session?
13. Refund mirror `composition_snapshot` is copied verbatim (`RefundWithCounterEntryService.php:136`). If allergens are added to the source Item post-sale, mirror still carries the OLD allergens — confirmed acceptable per NF525 §V (frozen)?
14. `enforceCrossItemGuards=true` on all 4 factory methods — any future internal caller (cron, console command) that does `new PricingRequest(enforceCrossItemGuards: false)` would silently lose injection protection. Any sentinel forbidding `false` outside test fixtures?
15. Stripe round-before-cast (PR07) — covered for Stripe. What about other gateways (Senangpay, mock, cash) — same arithmetic? `app/Http/PaymentGateways/Gateways/*.php` audited symmetrically?

---

## D. Sync invariants verified GREEN

| Invariant | Evidence |
|---|---|
| PricingService is the single computation site | 6 entry points (`OrderService::myOrderStore/posOrderStore/tableOrderStore`, `FrontendOrderService::frontendOrderStore` (kiosk+web), `OrderQuoteService::calculatePricing`, `PricingPreviewService::preview`) — ALL route through `PricingService::calculateOrder`. `grep -n "PricingService" app` returned 14 matches, all internal callers. |
| Frontend totals never trusted | `OrderRequest.php:148,160` + `PosOrderRequest.php:82,101` nullable+min:0; service-layer `unset($validated['total','subtotal','discount'])` (3 sites). Pinned by `PosOrderRequestNoClientTotalsTest`. |
| composition_snapshot UPDATE = zero | `Zone5PricingSsotConvergenceSentinelTest.php:189-252` PR03 grep sentinel — recursive scan of `app/` for 7 regex patterns. |
| Cross-item injection rejected | PricingService.php:152, 182, 207 — 422 throw on `dbVar/dbExt/dbAddon->item_id !== item->item_id`. `enforceCrossItemGuards=true` on all 4 factory methods. |
| Snapshot frozen across admin repricing | PR04 — `Zone5PricingSsotConvergenceSentinelTest.php:262-385` (admin update Item::price 7.50→9.99; historical row unchanged; new order picks up 9.99). |
| Refund preserves snapshot + allergens verbatim | `RefundWithCounterEntryService.php:121-143` (snapshot, allergens_snapshot, price, item_variations, item_extras copied unchanged; only quantity + tax_amount + total_price negated). |
| Reorder triggers fresh SSOT | `PosReorderHistoricalPricingSentinelTest.php` historical=1.00€ → new order = 10.00€ (DB price now). |
| Stripe cents arithmetic | PR07 — `(int) round($total * 100)` proven on €9.99→999, €0.99→99, €12.345→1235 (HALF_UP). |
| HMAC quote seal | `OrderQuoteService::sealForCommit:120-122` — `abs($quote->total_ttc - $expectedTotal) > 0.000001` ⇒ 409. POS + Kiosk forced via `surface ∈ [pos,kiosk]` requirement. |
| Split-payment validates against server total | `SplitPaymentService.php:51-167` — cents arithmetic, tolerance 1€, throws ValidationException if sum<total. |
| Manual discount RBAC gate | `OrderQuoteService::assertManualDiscountAllowed:288-328` — 50%/10%/any thresholds; permission strings `pos-discount-{unlimited,over-10-requires-manager,up-to-10}`. |
| Composer step constraints SSOT | PricingService.php:557-657 (`assertComposerStepConstraints`) — min_select/max_select/allow_repeat reread from DB, payload validated against `ItemWizardProfile` published projection. |

---

## E. Out of scope / unverifiable in this audit

- **Mobile standalone** — `mobile/data/orders.js:8` references `composition_snapshot` for reorder. Did NOT verify mobile-side payload shape for `role` injection — needs RED-Z3 (sync owner) cross-check. Flagged to owner Q7.
- **Cron / queue callers** of PricingRequest — searched but no offenders today; future-proofing handled by hardening the constructor (see Q14).
- **Other payment gateways** beyond Stripe — Senangpay/mock not read this session; RED-Z6 (refund/idempotency or fiscal lane) better-positioned.
- **POS Vanilla JS wizard frozen file** — grep of `public/js/pos-wizard.js` showed it does totals computation locally for display, but did not exhaustively trace whether it builds `role` strings before POST. Sentinel grep is read-only-safe; needs deeper walk. Flagged Q8.
- **DB-level INSERT INTO … SELECT bypass of composition_snapshot** — sentinel regex covers application code; a SQL admin run-time could bypass. Out of scope for V1 LOCAL (1 owner controls DB).

---

## F. RED verdict

**Score: 7/10**

**Top 3 risks**:
1. **P0-Z4-01 role injection** — unbounded `role` accepted on `/api/frontend/order` + `/api/admin/pos` allows menu-ratio (×0.4) on any addon. **Active V1 ship blocker for fiscal integrity** if owner concedes the exploit reproduces.
2. **P1-Z4-02 PRICING_USE_SSOT=false latent chain mismatch** — addonTotal vanishes but snapshot.addons populated. Default-safe today (true), but not in `AppServiceProvider` refuse-to-boot list.
3. **P2-Z4-03 delivery-minimum floor reads client subtotal** — operational bypass, not fiscal.

**Shippable V1 LOCAL?**
- **CONDITIONAL GO** — pending owner verification of P0-Z4-01 exploitability.
- If P0-Z4-01 reproduces on staging via curl/Postman: **NO-GO until role binding fix is shipped + sentinel added**.
- If P0-Z4-01 does NOT reproduce (e.g. some upstream guard I missed): **GO with P1/P2 in V1.0.2 backlog**.
- Sentinel coverage (PR01-PR07) is strong defense-in-depth; refund/reorder/snapshot-freeze invariants all GREEN.
- The audit-chain immutability (P3-Z4-04) is acknowledged backlog; not a V1 blocker for a 1-owner LOCAL deployment.

**Single hostile question that justifies the audit**:
> Can you reproduce P0-Z4-01 in your manual test phase? Add `{id:<any non-menu addon>, role:'menu_boisson'}` to a POS or kiosk order payload and check whether `order_items.total_price` reflects 60% discount on that addon. If yes → V1 ship blocker.
