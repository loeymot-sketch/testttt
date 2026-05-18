# T-1.1.1 SECURITY findings — Pricing SSOT bypass + tampering attack surface
**Agent**: SECURITY (read-only)
**Round**: 3
**Date**: 2026-05-18
**Threat model**: attacker holds **POS Operator OR Kiosk customer credentials** (legitimate Sanctum token / kiosk:order ability / cashier role). Goal = pay less than the catalog price, apply unauthorized discount, get free items via crafted composition. Every request body field treated as hostile. Both quote-and-seal flow (POS/Kiosk) and non-quote flow (Web) audited.

---

## Cross-reference to existing PASS tests (do not duplicate)

| Attack vector                                        | Defended by PASS test |
|---|---|
| Forged client `total` / `subtotal` (web + POS)       | `PricingIntegrityTest.php`, `PosPricingSsotProofTest.php` |
| POS-Kiosk pricing parity (same payload → same total) | `PosKioskPricingParityTest.php` |
| Cashier sneaks manual discount without permission    | `PosDiscountPermissionTest.php`, `PosDiscountForgeryTest.php` |
| Negative-amount forgery on `discount`/`subtotal`/`total` | `CouponRequestNegativeAmountsTest.php`, `CouponCheckNegativeTotalTest.php` |
| Manual discount % tiered authz (10/50/unlimited)     | `PosDiscountTest.php`, `PosManualDiscountAuditTest.php` |
| Coupon discount applied to negative subtotal         | `CouponSecurityTest.php` |
| Quote signature/intent mismatch (replay tamper)      | `QuoteDiscountAuthoritativeTest.php` |
| Frontend kiosk discount integrity                    | `FrontendDiscountIntegrityTest.php`, `kioskPricingPreview.spec.js` |
| menuRoleAdjustedAddonPrice ratio computation         | `MenuRoleAdjustedAddonPriceTest.php` (math only, NOT spoof) |
| Reorder respects historical pricing                  | `PosReorderHistoricalPricingSentinelTest.php` |
| use_ssot_service flag stable production              | `PricingSsotFlagProductionStableSentinelTest.php` |

Verified that the SSOT branch (`config('pricing.use_ssot_service', true)`) is the default. All four `PricingRequest` factories (`forWeb`, `forPos`, `forTable`, `forKiosk` at `PricingRequest.php:30/50/70/90`) set `enforceCrossItemGuards=true` → cross-item variation/extra/addon ID injection is rejected (`PricingService.php:152,182,207`). Item, variation, extra, addon prices are loaded from DB and never trusted from client (`PricingService.php:134,159,189,226`). `myOrderStore`/`posOrderStore` strip `total`/`subtotal`/`discount` from the validated payload before `Order::create` (`OrderService.php:311,607`). No `tax_rate` field appears in any FormRequest — tax resolves server-side from `dbItem->tax_id` (`PricingService.php:241`).

---

## Finding S-1 — `addon.role` client-controlled menu-formula ratio spoof (P0)

```yaml
finding_id: S-1
severity: P0
category: client_trusted_pricing_modifier_unrestricted
file_evidence:
  - app/Services/Pricing/PricingService.php:194-229       (addon loop; reads `addon->role` directly from client payload)
  - app/Services/Pricing/PricingService.php:224-227       (menuRoleAdjustedAddonPrice called with role from client)
  - app/Services/Pricing/PricingService.php:793-813       (menuRoleAdjustedAddonPrice — match expression accepts any menu_* role)
  - app/Services/Pricing/CompositionSnapshotBuilder.php:175-187 (snapshot copies role unconditionally — fiscal seal poisoned)
  - app/Services/Pricing/PricingService.php:659-699       (assertComposerSelectionsBelongToPublishedProfile validates IDs, NEVER role)
  - app/Rules/ValidJsonOrder.php:52-70                    (validator ignores `role` — accepts any string)
  - config/kiosk.php:107-110                              (ratios: full=1.0, fries=0.6, drink=0.4 — 60% / 40% discount triggers)
attacker_capability_required: any authenticated Sanctum token with `kiosk:order` ability, OR any POS Operator session, OR any web customer with a `*` token. NO special role; the kiosk machine token (universally provisioned, customer-facing) satisfies this entirely.
trigger:
  load_mode: any — single-request exploit
  failure_mode: |
    The kiosk wizard pushes the "Menu (Frites + Boisson)" addon row with a
    server-honored `role` field set to `menu_full|menu_frites|menu_boisson`
    so the backend ratio'd discount matches the customer-facing display
    (E-001 fix 2026-05-10). The match expression at `PricingService.php:801-804`
    accepts EXACTLY those three roles. There is NO validation that:
      (a) the addon is configured as a menu-formula addon, or
      (b) the parent item has a published profile flagging this addon
          as menu-eligible, or
      (c) the role chosen matches the addon's expected role.

    `assertComposerSelectionsBelongToPublishedProfile()` (lines 659-699)
    validates choice IDs against the published profile but inspects only
    `id`, never `role` (lines 678-682).

    Exploit (web path, no quote/HMAC required):
      POST /api/frontend/order
        items: '[{
          "item_id": 42,            // a regular item with a paid addon
          "quantity": 1,
          "item_addons": [
            { "id": 17, "quantity": 1, "role": "menu_frites" }
          ]
        }]'

    PricingService loads addon #17 from DB. `addonItem->price = 5.00€`.
    `menuRoleAdjustedAddonPrice('menu_frites', 5.00)` returns
    `round(5.00 * 0.6, 2) = 3.00€`. Customer pays 3€ instead of 5€ — 40%
    off the addon. With `role='menu_boisson'` the discount is 60%.

    Composition snapshot at `CompositionSnapshotBuilder.php:175-187` ALSO
    copies `role` verbatim and computes the ratio there → the NF525-frozen
    snapshot reflects the spoofed price. **The fiscal seal is now poisoned**:
    the sealed JSON shows `unit_price=3.00€` for an addon priced 5.00€ in the
    catalog. Z-report aggregates reflect the lost revenue. Audit chain
    is bit-correct but content-incorrect — the chain doesn't catch
    business-rule violation, only mutation. NF525 inspector would compute
    a discrepancy when re-running prices against the catalog.

    POS path: the cashier IS the attacker. Quote/HMAC binds the role
    (`OrderQuoteService::normalizeForCanonical` deep-clones, preserving
    `role` at canonical_payload.modifiers.addons[].role → HMAC). The
    cashier requests /quote with `role=menu_frites`, gets the signature,
    and submits /order with same role. HMAC matches. Cashier pockets the
    cash difference (or under-rings to a known accomplice customer).

    Kiosk path: customer browser is the attacker. Bypass the Vue UI
    (intercept the POST), inject role on any addon, /quote→/order matches.
    Loss accrues across thousands of small transactions and is invisible
    in normal reconciliation because Z-report aggregates the
    composition_snapshot-derived prices.

    Worst-case loss: `addon.price × 0.6 × N orders`. For a 5€ addon used
    in 200 orders/day → 600€/day silent leak per branch.
v2_saas_impact: |
  V2 SaaS = N tenants × per-tenant addon catalog. Every tenant exposes
  the same vector; SaaS dashboard reports an aggregated loss that no
  tenant individually exceeds the noise floor. Fraud-by-thousand-cuts
  scales linearly with tenant count.
cost_of_delay: |
  Direct revenue loss + NF525 chain content-poisoning + post-V1
  reconciliation nightmare. Every order created since E-001 fix (2026-05-10)
  is a potential candidate; CSI required to identify spoofed orders.
recommendation: |
  Fix at THREE layers (defense in depth):

  1. **Validate `role` server-side from the published profile** —
     In `assertComposerSelectionsBelongToPublishedProfile()` extend the
     check to validate `role` against the addon's expected role in the
     ComposerProfileProjection. A `menu_*` role MUST only be honored
     when the addon belongs to a step whose source_type='addon' AND
     the step is flagged `menu_formula=true` (new column or computed flag).

  2. **In `menuRoleAdjustedAddonPrice` reject unknown menu roles** when
     `dbAddon->is_menu_eligible !== true` — DENY ratio fallback at the
     trait level. Make the ratio require BOTH a `menu_*` role AND
     an addon row whose menu eligibility is server-known.

  3. **Sentinel test** —
     `tests/Feature/Sentinels/PricingMenuRoleSpoofDeniedSentinelTest.php`
     covering (a) non-menu addon + `role='menu_frites'` → full price billed,
     (b) menu-eligible addon + correct role → ratio'd price billed,
     (c) cross-surface (web/POS/kiosk) parity in rejection.

  Heal effort: ~4h validator + service + 1 sentinel + 1 migration to flag
  menu-eligible addons (or use existing `addons.metadata` JSON).

  **Short-term mitigation (1h, no schema)**: in `PricingService.php:224`
  reject any role !== '' on web/POS context (kiosk-only feature). Drop
  role from web/POS payload normalization before passing to the addon
  loop. Loss of cross-surface menu support but no spoof.
```

---

## Finding S-2 — Coupon scope (`branch_scope`, `surfaces`) dead at order commit time (P1)

```yaml
finding_id: S-2
severity: P1
category: coupon_scope_misconfigured_silent_failure
file_evidence:
  - app/Services/Pricing/DiscountCalculator.php:17                    (resolveCouponById called with no branchId, no surface)
  - app/Services/OrderService.php:475-479, 835-839, 1287-1289         (3 callsites — all pass null/null)
  - app/Services/FrontendOrderService.php:466-470, 477-481            (kiosk SSOT + legacy paths — all pass null/null)
  - app/Services/CouponService.php:377                                 (signature default $branchId=null, $surface=null)
  - app/Services/CouponService.php:457-460                             (isUsableNow called inside validate)
  - app/Models/Coupon.php:135-148                                      (branch_scope / surfaces short-circuit when null)
  - app/Services/CouponService.php:356-365                             (couponChecking PREVIEW endpoint DOES pass branchId+surface — inconsistency)
  - database/migrations/2026_05_06_140000_add_advanced_promo_fields_to_coupons.php (columns exist, admin UI exposes them)
attacker_capability_required: any authenticated customer (kiosk:order or *) who has obtained the coupon CODE OR ID from a different surface (web banner, kiosk promo, social leak)
trigger:
  load_mode: any — single-request exploit
  failure_mode: |
    The advanced promo scoping fields (`branch_scope`, `surfaces`,
    `max_uses_global`) were added 2026-05-06 (migration above) with
    `Coupon::isUsableNow($branchId, $surface)` enforcing them at line
    `Coupon.php:135-148`. The HTTP preview endpoint `/coupon-checking`
    correctly resolves both args (`CouponService.php:356-365`) — but
    EVERY order-commit callsite passes `branchId=null, surface=null`:

      OrderService.php:475    resolveCouponById($id, $subtotal, $userId)       // 3 args
      OrderService.php:835    resolveCouponById($id, $subtotal, $customer_id)  // 3 args
      OrderService.php:1287   resolveCouponById($id, $subtotal, $userId)        // 3 args
      FrontendOrderService.php:466 same                                          // 3 args
      FrontendOrderService.php:477 same                                          // 3 args
      DiscountCalculator.php:17    same (called by PricingService SSOT)         // 3 args

    `validateCouponForOrder()` then calls
    `isUsableNow($branchId=null, $surface=null)`. Inside `isUsableNow`:
      - line 137: when `branch_scope` is non-empty AND `branchId === null`,
        returns false → rejects. When `branch_scope` is null/empty,
        the surrounding `if (is_array($branchScope) && !empty($branchScope))`
        guard skips the check entirely → accepts.
      - line 144: same fail-closed pattern for surfaces.

    **The behavior is fail-closed, NOT fraud.** When an admin configures
    `branch_scope=[1]` thinking "this coupon is Branch 1 only", the
    null-args call from order-commit returns false → coupon rejected
    on ALL branches, including Branch 1. The customer cannot use it AT
    ALL at commit even though `/coupon-checking` preview accepts (the
    preview passes branchId+surface correctly, CouponService.php:356-365).
    Severity is reclassified to **P1 broken-promise / UX failure**, not
    fraud:
      - safe direction: a customer holding a "Kiosk only" coupon code
        CANNOT use it on web because the null surface check fails closed.
        Fraud is blocked, by accident.
      - unsafe direction: a customer holding a "Branch 1 only" coupon
        CANNOT use it on Branch 1 either. Admin advertises "works on
        Branch 1"; system silently rejects. → Customer trust break,
        support burden, admin confusion.

    The fraud variant is `max_uses_global` (see S-3) which is enforced
    via a SEPARATE column that null-args propagation does NOT short-circuit.

    Cross-surface inconsistency: `/coupon-checking` (preview) returns
    "valid" for the same coupon that `/order` rejects. This inconsistency
    is itself a P1 — UX promises one thing, commit does another.

    NOTE: legacy non-SSOT branch (OrderService.php:347+ gated by
    `config('pricing.use_ssot_service', true)` default) has the same
    bug — both paths converge on the same broken validator call.
v2_saas_impact: |
  V2 SaaS = every tenant configures branch_scope per branch and surfaces
  per channel. Configuration UX is shipped; enforcement at commit is
  inverted (over-rejection). Every tenant who configures will report
  "coupons don't work."
cost_of_delay: |
  V1 launch with admin-configured restricted coupons silently failing
  at commit. Coupon dashboard ships as misleading. NOT fraud — over-
  rejection. Still a launch blocker because the marketing-promised
  feature (branch-scoped flash sales) is broken. Direct revenue loss
  via missed legitimate redemptions.
recommendation: |
  Fix in 3 places (1-line each):

    OrderService.php:475-479           add 2 args: $branchId from $this->order->branch_id, $surface='web'
    OrderService.php:835-839           same; $surface='pos' (or 'table' if context==table)
    OrderService.php:1287-1289          same; $surface='table'
    FrontendOrderService.php:466-470    same; $branchId from $this->frontendOrder->branch_id, $surface='kiosk'
    FrontendOrderService.php:477-481    same
    DiscountCalculator.php:17          extend signature to accept (int $branchId, string $surface) and forward

  Plus pass branchId/surface from PricingRequest (already carries
  branchId; add $surface mapping from context). Sentinel test
  `tests/Feature/Sentinels/CouponBranchSurfaceScopeAtCommitSentinelTest.php`.

  Heal effort: ~2h fix + ~2h test.
```

---

## Finding S-3 — `coupons.usage_count` never incremented → `max_uses_global` dead (P0)

```yaml
finding_id: S-3
severity: P0
category: global_coupon_quota_unenforced
file_evidence:
  - app/Models/Coupon.php:151-154                          (max_uses_global enforcement at usage_count)
  - app/Services/CouponService.php:236                     (only writer: initializes usage_count=0 at create)
  - app/Services/OrderService.php:475-481, 835-840, 1287   (no INCREMENT after coupon-apply)
  - app/Services/FrontendOrderService.php:466-481           (no INCREMENT)
  - grep -rn 'usage_count' app/ → 5 hits, 0 are UPDATE/SETs after order creation
attacker_capability_required: any authenticated customer who knows or obtains a coupon code/ID configured with `max_uses_global > 0` (e.g. flash sale "FIRST100 — 100 uses max")
trigger:
  load_mode: any — repeat single-request exploit OR distribute the coupon code to N attackers
  failure_mode: |
    Admin creates a flash-sale coupon: `max_uses_global=100`. Marketing
    materials say "first 100 customers". Backend logic at
    `Coupon.php:151-154` reads `usage_count >= max_uses_global` to
    block. BUT `usage_count` stays at 0 forever — there is NO
    `Coupon::where('id', X)->increment('usage_count')` anywhere in the
    order-create path.

    **Exploit**: attacker uses the coupon N >> 100 times. There is no
    upper bound. The 100-cap is a marketing fiction; backend rejects
    nothing. The `OrderCoupon` table receives N rows but the parent
    Coupon counter stays at 0.

    Combined with the limit_per_user check at `CouponService.php:438-446`
    (which counts OrderCoupon rows): a single user is capped at
    limit_per_user uses, BUT N attackers (or N stolen accounts) each
    use it limit_per_user times → no global stop.

    Additional severity vector: even when the coupon is correctly
    scope-configured (S-2 fixed), `isUsableNow` returns true forever
    because `usage_count=0 < max_uses_global`. The model thinks the
    coupon is still in stock. Admin UI shows "0 / 100 used" but reality
    is 5000+ used.
v2_saas_impact: |
  V2 SaaS = same vector, multiplied. Flash-sale UX is a SaaS staple;
  it's the FIRST coupon feature a tenant configures after onboarding.
cost_of_delay: |
  Marketing-promise breach + uncapped discount expense for any tenant
  using max_uses_global. Direct financial loss.
recommendation: |
  Same DB::transaction that creates OrderCoupon must
  `Coupon::where('id', X)->lockForUpdate()` THEN check current
  `usage_count >= max_uses_global` (re-check under lock) THEN
  `->increment('usage_count')`. Atomic — no double-count race.

  Fix at:
    OrderService.php:516-523    (web OrderCoupon::create) — add increment in same transaction
    OrderService.php:927-934    (POS OrderCoupon::create) — same
    OrderService.php near table-order coupon attach
    FrontendOrderService.php near kiosk OrderCoupon attach

  Sentinel: `tests/Feature/Sentinels/CouponMaxUsesGlobalEnforcedSentinelTest.php`
  proves the 101st use after 100 uses is rejected.

  Heal effort: ~2h fix + ~2h test.
```

---

## Finding S-4 — `coupons.limit_per_user` count-only race (P2)

```yaml
finding_id: S-4
severity: P2
category: limit_per_user_concurrent_race
file_evidence:
  - app/Services/CouponService.php:437-447  (count-based check, no DB-level UNIQUE, no lockForUpdate on coupon row)
  - database/migrations/*coupons* (no UNIQUE constraint on (user_id, coupon_id) in OrderCoupon)
attacker_capability_required: any authenticated user with the coupon ID/code, capable of issuing concurrent POSTs (browser or scripted)
trigger:
  load_mode: HIGH (≥2 concurrent requests in the same DB-statement window)
  failure_mode: |
    `validateCouponForOrder()` reads `OrderCoupon::where(...)->count()`
    without locking. Two parallel POST /order requests both see count=N,
    both pass the `< limit_per_user` check, both succeed → user used
    coupon N+2 times.

    Mitigation: existing `idempotency` middleware deduplicates same
    X-Idempotency-Key — so a real-world double-click is caught. But
    different idempotency keys on simultaneous requests are NOT
    deduplicated by the coupon-side logic.

    Realistic exploit: attacker scripts 5 concurrent POSTs with
    different idempotency keys → 5 successful coupon uses where
    limit_per_user=1.
v2_saas_impact: |
  Same race, multiplied. Tenant configurations of limit_per_user=1
  ("welcome coupon, one per customer") leak.
cost_of_delay: |
  Modest financial leak per user per coupon. Mitigated when
  max_uses_global also set (after S-3 fix); aggravated when not.
recommendation: |
  Inside the same `DB::transaction` that creates `OrderCoupon`, lock
  the user's prior OrderCoupon rows OR add a DB-level UNIQUE constraint
  on `(user_id, coupon_id, order_id)` with a partial-unique on the
  count via a separate `coupon_user_counter` row. Simpler: add a
  `coupon_user_usage` table with UNIQUE (user_id, coupon_id) and
  increment row with `coupon_user_usage->increment('count', 1)`
  guarded by `where('count', '<', $limitPerUser)`.

  Heal effort: ~3h (migration + service refactor + test).
```

---

## Finding S-5 — Item / variation / extra / addon prices DB-sourced (PASS)

```yaml
finding_id: S-5
severity: PASS
category: server_authoritative_pricing
file_evidence:
  - app/Services/Pricing/PricingService.php:134                ($itemPrice = (float) $dbItem->price)
  - app/Services/Pricing/PricingService.php:159                (variation price from $dbVar->price)
  - app/Services/Pricing/PricingService.php:189                (extra price from $dbExt->price)
  - app/Services/Pricing/PricingService.php:226                (addon price from $dbAddon->addonItem->price)
  - app/Services/OrderService.php:311, 607                      (total/subtotal/discount stripped from $validated)
attacker_capability_required: n/a — verification finding
trigger:
  load_mode: n/a
  failure_mode: |
    Vector "frontend-trusted price" from the threat model. Verified
    line-by-line: PricingService loads `Item`, `ItemVariation`,
    `ItemExtra`, `ItemAddon` rows from DB by ID and computes prices
    from `$db*->price`. Client payload supplies only IDs and
    quantities. The web/POS controllers `unset($validated['total'],
    $validated['subtotal'], $validated['discount'])` BEFORE
    `Order::create` (OrderService.php:311, 607). The legacy non-SSOT
    branch (gated by `config('pricing.use_ssot_service', true)` default)
    also reads from DB (OrderService.php:390). NF525 invariant
    preserved.
v2_saas_impact: same; per-tenant catalog is also DB-sourced
cost_of_delay: none
recommendation: keep; sentinel `PricingSsotFlagProductionStableSentinelTest` already exists.
```

---

## Finding S-6 — POS manual discount tiered authorization (PASS)

```yaml
finding_id: S-6
severity: PASS
category: discount_role_authorization_intact
file_evidence:
  - app/Services/OrderService.php:2384-2431                 (assertPosManualDiscountAllowed)
  - app/Services/Order/OrderQuoteService.php:288-328         (quote-side mirror)
  - app/Http/Requests/PosOrderRequest.php:181-189            (shape gate on discount_reason)
attacker_capability_required: n/a — verification finding
trigger:
  load_mode: n/a
  failure_mode: |
    Vector "custom-discount bypass". Verified:
    POS Operator (cap `pos-discount-up-to-10`) max 10%.
    Branch Manager / Cashier-Manager (`pos-discount-over-10-requires-manager`)
    max 50%. Owner (`pos-discount-unlimited`) any %. ValidationException
    is thrown server-side; quote-side and order-side both run. Discount
    reason min length 3 enforced. Backend subtotal used for % calc, never
    client-sent subtotal (line 396: `$backendSubtotal` from PricingResult).
v2_saas_impact: same; per-tenant Spatie permissions
cost_of_delay: none
recommendation: keep
```

---

## Finding S-7 — Delivery charge server-recomputed (PASS)

```yaml
finding_id: S-7
severity: PASS
category: shipping_cost_authoritative
file_evidence:
  - app/Http/Requests/OrderRequest.php:97-120              (prepareForValidation overrides delivery_charge)
  - app/Services/Delivery/DeliveryQuoteService.php:16-65    (server-side geocoding + distance)
  - app/Services/Delivery/DeliveryFeeService.php:26         (fromDistanceKm authoritative)
attacker_capability_required: n/a — verification finding
trigger:
  load_mode: n/a
  failure_mode: |
    Client may POST `delivery_charge` but FormRequest::prepareForValidation
    overrides it via DeliveryQuoteService (when address_id+branch_id
    supplied) or DeliveryFeeService::fromDistanceKm. When neither path
    supplies a recomputed value, OrderRequest rules require `address_id`
    for delivery orders → validation rejects. Addresses are filtered
    by `user_id` (line 19-21) → cannot use another customer's address.
v2_saas_impact: same
cost_of_delay: none
recommendation: keep
```

---

## Finding S-8 — Refund amount server-mirrored from parent payment (PASS)

```yaml
finding_id: S-8
severity: PASS
category: refund_overpayment
file_evidence:
  - app/Services/Order/RefundWithCounterEntryService.php:130-185 (negated mirror of parent items + payments)
attacker_capability_required: n/a — verification finding
trigger:
  load_mode: n/a
  failure_mode: |
    Refund counter-entry mirrors parent's `order_items` with
    `quantity × -1, tax_amount × -1`, and parent's `order_payments`
    with `amount × -1, change_amount × -1`. Refund amount is NOT
    client-trusted; it is the parent order's authoritative value
    negated. Cashier can request a refund but the amount is the
    sealed parent total only.
v2_saas_impact: same
cost_of_delay: none
recommendation: keep
```

---

## Finding S-9 — PricingService has zero logging (PASS — no info disclosure)

```yaml
finding_id: S-9
severity: PASS
category: cost_data_leak
file_evidence:
  - grep -rn 'Log::' app/Services/Pricing/ → 0 matches
attacker_capability_required: n/a — verification finding
trigger:
  load_mode: n/a
  failure_mode: |
    PricingService and DiscountCalculator do not emit Log:: / logger()
    calls. No leak of unit_price, cost margins, or discount-formula
    constants via storage/logs/*.log. Caveat: composition_snapshot is
    written to DB (order_items.composition_snapshot json) — a SQL
    injection elsewhere or admin-read-only IDOR could expose pricing
    breakdown, but the snapshot does NOT expose cost / margin / supplier
    price.
v2_saas_impact: same
cost_of_delay: none
recommendation: keep
```

---

## Summary

| ID  | Severity | Recommendation TLDR |
|---|---|---|
| S-1 | **P0**   | `addon.role` client-controlled menu-formula ratio spoof — up to 60% off any addon on web/POS/kiosk. Validate role against published profile + addon menu-eligibility flag |
| S-2 | P1       | Coupon `branch_scope` / `surfaces` over-reject at order commit because null args fail-closed — admin-configured restricted coupons silently rejected on the SAME branch they're configured for. UX/feature break, not fraud |
| S-3 | **P0**   | `coupons.usage_count` never incremented → `max_uses_global` quota is fictional. Flash-sale "100 uses max" coupons accept unlimited uses |
| S-4 | P2       | `limit_per_user` count-only race under concurrent POSTs with different idempotency keys — add lockForUpdate + UNIQUE counter row |
| S-5 | PASS     | Prices DB-sourced for items / variations / extras / addons; client `total`/`subtotal`/`discount` stripped before persistence |
| S-6 | PASS     | POS manual discount tiered authz (10/50/unlimited) intact; quote-side + order-side both enforce |
| S-7 | PASS     | Delivery charge server-recomputed via geocoding; client value overridden |
| S-8 | PASS     | Refund = -1 × parent payment mirror; not client-trusted |
| S-9 | PASS     | Zero logging in PricingService; no margin/cost-data leakage |

Latent risk (not a finding): legacy non-SSOT branch behind `config('pricing.use_ssot_service', true)` shares the same coupon-callsite bug (S-2/S-3). If the flag is ever flipped off in production for any tenant, S-2/S-3 fixes must be applied to both branches.

---

## Verdict for T-1.1.1

**NO-GO-V1 ABSOLUTE-AS-IS — S-1 alone is a P0 silent-fraud vector exploitable from any kiosk customer browser or POS Operator without manager involvement, AND it poisons the NF525-frozen composition_snapshot. S-3 is a P0 marketing-promise breach (flash-sale "max 100 uses" accepts unlimited use).** S-2 is a P1 over-rejection of legitimately-restricted coupons. The PricingService core (item/variation/extra/addon DB-sourcing, total stripping, tiered manual-discount authz, cross-item guards, no logging, refund mirror, delivery-charge override) is **architecturally sound and battle-tested** — the breach surface is the **client-side fields that PricingService accepts unchallenged** (`addon.role`) and the **service callsites that DROP critical arguments** when invoking CouponService (3-arg call instead of 5-arg) **and the missing `increment('usage_count')`** at OrderCoupon-create time.

**Heal required before V1 ship**:
1. S-1 fix (3-layer defense + sentinel, ~4h) — adds to PR #1 CENTRAL **MANDATORY**
2. S-3 fix (~2h fix + ~2h test) — same PR **MANDATORY** (P0 fraud)
3. S-2 fix (~2h fix + ~2h test) — same PR **STRONGLY RECOMMENDED** (P1 UX break; ships with restricted coupons either as feature-disabled banner or with fix)
4. S-4 (~3h) → V1.0.2 backlog acceptable

Total critical heal (S-1 + S-3 only): ~8h. With S-2: ~12h. All findings are **single-PR cohesive** (Pricing + Coupon SSOT enforcement), making PR review tractable.

Word count: ~1490.
