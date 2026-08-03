# LOCK Plan — POS Cashier Loyalty Redeem UI

**Status** : DRAFT — pending owner countersign
**Date** : 2026-05-18
**Author** : Claude Opus 4.7 (session-B Couche 1 Intersection POS×Loyalty)
**Owner decision recap** : POS×Loyalty audit (intersection-pos-loyalty-2026-05-18) found that POS surface only handles **EARN** (via `loyalty_customer_code` → `AwardLoyaltyPointsOnDelivery` listener). REDEEM is currently kiosk-only. Owner clarified : "Non oublié — ajoute cashier redeem UI au POS V1".

---

## §1 Scope définition

Add a POS cashier-facing redemption UI that allows the cashier to :
1. Lookup the customer's loyalty balance (already supported via phone)
2. Apply a redemption discount during order finalization (before payment)
3. Trigger backend `LoyaltyService::redeem` (or similar) which decrements balance + appends `loyalty_transaction`
4. Show the final order total with redemption applied

**NOT IN SCOPE V1** :
- Auto-suggest "best redemption" UI
- Multi-redemption per order (single redemption only for V1)
- Loyalty tier UI (V1 = flat balance/points)

## §2 Files affected — 3 design options

### Option A — Modify FROZEN pos-wizard.js (highest fidelity, highest risk)

Files touched :
- `public/js/pos-wizard.js` (FROZEN §7) — ~50-100 LOC added for redemption step
- `public/css/pos-wizard.css` (FROZEN) — ~10 LOC for new step styles
- `resources/views/admin-pos-v4.blade.php` (FROZEN) — possible 1-2 lines for new step trigger

Owner countersign : **MANDATORY** for all 3 frozen files.

Pro :
- Single integrated UX flow (cashier doesn't switch context)
- Looks/feels native to existing wizard

Con :
- Frozen-zone touch on 3 files
- Requires deep regression testing (Vanilla JS S25 wizard 5964 LOC, intricate state machine)
- Higher risk of breaking the "impeccable" current state owner attested

### Option B — Separate Vue overlay outside FROZEN wizard (RECOMMENDED)

Files touched :
- `resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue` (NEW)
- `resources/js/components/admin/posOrders/PosOrderComponent.vue` (existing, NOT frozen)
- `app/Http/Controllers/Admin/PosController.php` (DIRTY — observe; OR new controller PosLoyaltyController)
- `app/Services/Loyalty/PosRedemptionService.php` (NEW)
- `routes/api.php` (NEW endpoint `POST admin/pos-order/{id}/redeem-loyalty`)
- Migration (if loyalty_transactions table needs `pos_session_id` column)
- Tests : `tests/Feature/Pos/PosLoyaltyRedeemTest.php` (NEW)
- Vitest spec : `tests/js/posLoyaltyRedeemModal.spec.js` (NEW)

Frozen-zone touch : **0** (no LOCK needed).
Owner countersign : **NOT NEEDED** for this scope (no frozen files touched).

Pro :
- Zero frozen-zone risk
- Easier to revert (single modal, single endpoint)
- Vue testable via Vitest
- Idempotency middleware already covers `pos-order/*` routes — automatic anti-doublon

Con :
- Cashier opens a separate modal — context switch (1-click overhead)
- Slightly less polished feel

### Option C — Redirect to a separate page (lowest dev cost)

Files touched :
- `resources/js/router/modules/posRoutes.js` (existing)
- `resources/js/views/admin/pos/PosLoyaltyRedeemPage.vue` (NEW)
- Backend same as Option B

Frozen-zone touch : 0.
Cashier navigates to `/admin/pos/orders/{id}/redeem-loyalty` → applies redemption → returns to order.

Pro : simplest implementation. Con : worst UX (full page navigation).

---

## §3 Recommended path — Option B

**Scope-minimal, zero LOCK needed, zero owner countersign for frozen.**

Implementation steps (TDD-first) :
1. **Sentinel test first** (RED) : `tests/Feature/Pos/PosLoyaltyRedeemTest.php` asserting endpoint behaviour (4 cases : happy path / insufficient balance / customer-not-found / redeem-after-paid-rejected)
2. **Backend** : `PosRedemptionService::applyToOrder(Order $order, int $points)` with anti-fraud checks (balance >= points / order status != PAID / single redemption per order / idempotency via UNIQUE on loyalty_transactions)
3. **Controller** : `PosController::redeemLoyalty(PosLoyaltyRedeemRequest $req, int $orderId)` ; route registered in `routes/api.php` with `idempotency` middleware
4. **Migration** : add `pos_session_id` nullable column to `loyalty_transactions` (cashier audit trail)
5. **Vue** : `PosLoyaltyRedeemModal.vue` — opens from existing `PosOrderComponent` ; shows balance, input points, validate
6. **Vitest** : `posLoyaltyRedeemModal.spec.js` — UI interaction tests
7. **i18n** : add 5-10 keys for the modal (`pos.loyalty.redeem.title`, `pos.loyalty.redeem.balance`, etc.) to fr/en/ar JSON
8. **A11y** : modal `role="dialog"` + ARIA + focus trap + Esc dismiss (per existing modal patterns)
9. **Visual capture** : Playwright headed POS modal open → apply 100 points → see updated total

**Estimated effort** : 1-2 jours-agent + 1-2h owner manual test acceptance.

---

## §4 If owner insists on Option A (FROZEN touch)

**LOCK override conditions** :
- Owner countersign on this LOCK doc (sign-off section §10)
- Backup branch BEFORE first commit : `backup/pre-pos-loyalty-redeem-2026-05-18`
- Frozen-zone safety-check.sh override (per .cursor/hooks/safety-check.sh override syntax) — see §7 below
- All 3 frozen file commits triple-verified : PHPUnit POS suite + Vitest POS suite + Playwright POS wizard E2E
- Final commit MUST include : "LOCK signed by owner $(date) ; frozen-zone touch authorized per plans/LOCK_POS_LOYALTY_REDEEM_UI_2026-05-18.md §10"

### §7 safety-check.sh override config (Option A only)

```bash
# .cursor/hooks/safety-check.sh override block — added by Claude on owner sign-off
# Scope : POS_LOYALTY_REDEEM_UI feature only
# Authorized files : pos-wizard.js + pos-wizard.css + admin-pos-v4.blade.php
# Authorized commits : matching pattern "feat(pos-loyalty-redeem): *"
# Reverts after merge to main
LOCK_OVERRIDE_FILES=(
    "public/js/pos-wizard.js"
    "public/css/pos-wizard.css"
    "resources/views/admin-pos-v4.blade.php"
)
LOCK_OVERRIDE_PATTERN="^feat\(pos-loyalty-redeem\):"
```

---

## §5 Rollback plan

- Option B (default) : `git revert <feat commit>` — clean, no state to repair
- Option A : revert + restore frozen files from backup branch + remove LOCK override from safety-check.sh + re-run PHPUnit + Vitest + Playwright to confirm wizard intact

---

## §6 Anti-fraud safeguards (mandatory in both Option A and B)

1. **Cashier presence required** : cashier must be authenticated + have `permission:pos.redeem-loyalty` (NEW Spatie permission)
2. **Single redemption per order** : UNIQUE constraint on `(order_id, type='redeem')` in `loyalty_transactions`
3. **Balance check at apply** : `$order->customer->loyalty_points >= $request->points_to_redeem`
4. **Idempotency** : `X-Idempotency-Key` header required (already enforced by middleware on `pos-order/*` routes)
5. **Audit log** : every redemption appends to `loyalty_transactions` AND to `audit_logs` (NF525-adjacent forensic trail)
6. **Anti-replay** : payload includes `pos_session_id` + `cashier_user_id` — replay outside session = rejected
7. **Pre-payment only** : `$order->status NOT IN [PAID, COMPLETED, DELIVERED]` — redeem after payment rejected

---

## §10 Owner sign-off section

```
☐ Owner read this LOCK plan in full
☐ Owner chose Option : ___ (A / B / C)
☐ If Option A : owner countersigns the 3 frozen file paths (above §2)
☐ Owner authorizes the scope §1 (no scope creep)
☐ Owner accepts rollback plan §5
☐ Owner accepts anti-fraud safeguards §6 as non-negotiable

Owner signature : ________________
Date : ________________
```

---

**Status** : DRAFT pending owner choice between Option A/B/C + countersign §10.

**Claude recommendation** : Option B (separate Vue overlay) — zero LOCK needed, ~1-2 jours-agent, no frozen-zone risk, easy revert.
