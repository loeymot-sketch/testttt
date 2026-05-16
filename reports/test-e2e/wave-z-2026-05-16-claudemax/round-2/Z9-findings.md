# Z9 — Delivery flow — Round 2 findings

**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**HEAD audited** : `56204f052`
**Heal commits verified** : `7fc62c066` (Sprint 5A) on top of `c3ba89863` (Sprint 2B) + Sprint 2A/3C
**Sister-verdict** : `reports/audit/ultra-review-2026-05-16/ULTRA_REVIEW_VERDICT.md` — DEL-1..DEL-9
**Round 1 reference** : `reports/test-e2e/wave-z-2026-05-16-claudemax/round-1/Z9-findings.md`
**Mode** : read-only adversarial RED-team — convergence pass

---

## Summary

Round 1 raised 3 P0 NEW (Z9-P0-01/02/03) + 1 P1 NEW (Z9-P1-03). Sprint 5A heal commit
`7fc62c066` addresses all four. Round 2 verifies them by reading the post-heal source and
running the canonical Feature test suite.

**Outcome** : **GO Round 2**. 0 P0 NEW, 0 P1 NEW from Wave Z heal. All four Z9-* findings
verified healed. DEL-5/6/7/8/9 unchanged (pre-existing, deferred V1.0.1 per kickoff scope).
Frozen-zone touched: 0.

The Sprint 5A patch is a clean targeted fix: ValidPhone now has 3 distinct branches
(PENDING_ reject, E.164 strict, national >= 9), `User::creating` keeps the sentinel but
emits a `Log::warning` so ops can surface them, both Resources gate `phone` on
`OrderType::DELIVERY (5)`, and `KdsOrderCard.customerPhone` collapses `PENDING_*` to ''.
Test `DeliveryValidationTest` passes 14/14 — the same surface that Round 1 used as the
canonical evidence.

---

## P0 findings

(none)

---

## P1 findings

(none — Wave Z heal)

---

## P2 findings

(none — Wave Z heal)

---

## P3 findings

(none)

---

## Healed-verified — Round 1 NEW closed by Sprint 5A

### Z9-P0-01 — ValidPhone E.164 enforcement now real ✅
- `app/Rules/ValidPhone.php:31-35` — explicit `str_starts_with($raw, 'PENDING_')` rejection branch (was missing).
- `app/Rules/ValidPhone.php:41-49` — when `$raw` starts with `+`, strict regex `/^\+[1-9]\d{7,14}$/`:
  - E.164 max 15 digits total → 1 leading non-zero + 7..14 trailing = 8..15 digit numbers.
  - Rejects `+0...` (leading zero forbidden by E.164).
- `app/Rules/ValidPhone.php:55-58` — national `$minLen = max(9, …)` (was effectively 8 via `$expected - 2 = 10 - 2 = 8`); 8-digit synthetic sequences like `12345678` and `00000000` are now refused.
- **Test evidence** : `php artisan test tests/Feature/Delivery/DeliveryValidationTest.php` → **14/14 PASS** (2.11s). The `del4_*` tests specifically exercise PENDING_, malformed, and valid phone branches.
- Verdict: **closed**. The Sprint 2B commit subject claim is now backed by code.

### Z9-P0-02 — `User::creating` sentinel injection now auditable ✅
- `app/Models/User.php:107-123` — sentinel injection wrapped with `Log::warning('User created without phone — sentinel injected', […])` carrying `name`, `email`, and the sentinel itself.
- `app/Rules/ValidPhone.php:31-35` — downstream `ValidPhone` now rejects any `PENDING_` prefix, so even if a sentinel-phoned user reaches `SignupRequest` / `OrderRequest` / `AddressRequest` later, the call returns 422 before any business logic.
- Combined effect: the sentinel cannot disappear silently — every legacy injection writes a `warning` line ops can grep, and the customer cannot place any DELIVERY order (or update an address) until they supply a real phone.
- Note: the sentinel itself is still allowed at row-creation time (intentional, preserves admin tooling + console + factory backward compat). This is the correct trade-off — the NOT NULL invariant is preserved, the gate is enforced at every user-facing entry point.
- Verdict: **closed**. The original "decorative NOT NULL" risk now has a real downstream gate AND an audit trail.

### Z9-P0-03 — `customer_phone` GDPR gate on `OrderType::DELIVERY` ✅
- `app/Http/Resources/SimpleOrderResource.php:58` — `'customer_phone' => ((int) $this->order_type === OrderType::DELIVERY) ? $this->user?->phone : null`.
- `app/Http/Resources/KDSOrderDetailsResource.php:70` — `'phone' => ((int) $this->order_type === OrderType::DELIVERY) ? $this->user->phone : null`.
- `OrderType::DELIVERY === 5` confirmed in `app/Enums/OrderType.php:7`.
- `(int)` cast guards against the column shipping as string from MySQL / Eloquent edge cases.
- Verdict: **closed**. Dine-in / takeaway / kiosk JSON wire no longer carries phone PII.

### Z9-P1-03 — `KdsOrderCard.customerPhone` sentinel collapse ✅
- `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue:315-325` — `customerPhone()` computed:
  - Reads `this.order?.customer?.phone || ''`.
  - If `typeof phone === 'string' && phone.startsWith('PENDING_')` → returns `''`.
  - `v-if="customerPhone"` (line 97) hides the entire tel: row when empty.
- Defensive: even though the backend already null-gates on non-DELIVERY (Z9-P0-03), the Vue computed handles the edge case of a backfilled legacy DELIVERY order where `phone` IS exposed but is a `PENDING_<id>` sentinel.
- Verdict: **closed**. `tel:PENDING_*` href cannot render.

---

## Pre-Wave-Z DEL-1..4 — still healed (re-verified Round 2)

### DEL-1 — `addresses.geocode_status` column + gate ✅
- `database/migrations/2026_05_16_140000_add_geocode_status_to_addresses_table.php:38-52` — column present, indexed, CHECK constraint on MySQL/PgSQL.
- DeliveryValidationTest `del1_*` (6 tests) PASS.

### DEL-2 — `OrderAddress::create` throw on IDOR ✅
- `app/Services/FrontendOrderService.php:540-556` — IDOR refusal throws `OrderAddressOwnershipException` (HTTP 403); `DB::transaction` rolls back atomically.
- DeliveryValidationTest `del2_*` (3 tests) PASS.

### DEL-3 — KDS + Simple Resources expose `order_address` + `customer` ✅
- `KDSOrderDetailsResource.php:56-71` — `order_address` + `customer` blocks present.
- `SimpleOrderResource.php:46-58` — same shape mirrored.

### DEL-4 — `users.phone` NOT NULL ✅
- `database/migrations/2026_05_16_140100_make_user_phone_required.php:103-104` — `ALTER TABLE users MODIFY phone VARCHAR(255) NOT NULL` (MySQL/MariaDB) + PgSQL equivalent + SQLite rebuild.
- DeliveryValidationTest `del4_*` (5 tests) PASS.

---

## Pre-Wave-Z DEL-5..9 — unchanged (deferred V1.0.1 per kickoff scope)

| Finding | File:line | Status |
|---|---|---|
| **DEL-5** (P1) — hardcoded delivery fee `max(5, ceil(d/5)*5)` | `app/Services/Delivery/DeliveryFeeService.php:14` | unchanged, deferred |
| **DEL-6** (P1) — i18n keys (≥10 sister claim) | `resources/js/languages/fr.json:263-674` — 11 delivery keys present (`delivery_boys`, `delivery_charge` x2, `basic_delivery_charge`, `delivery_address`, `delivery_boy`, `delivery_information`, `delivery_time`, `delivery_zone`, `estimated_delivery_time`, `preferred_time`, `total_delivery_charges`) | needs sister canonical list for full verdict — coverage looks complete from greppable evidence |
| **DEL-7** (P1) — `whereNotNull('zone')` silent branch exclusion | `app/Services/BranchService.php:132` + `:189` | unchanged, deferred |
| **DEL-8** (P2) — no min-order delivery | (no column / no settings entry) | unchanged, deferred |
| **DEL-9** (P2) — no auto-dispatch livreur | (`grep auto.*dispatch\|DeliveryBoyAssign` → 0 matches in `app/`) | unchanged, deferred |

These are tracked as Sprint 4 / V1.0.1 hardening per `00_KICKOFF.md:40`. Not regressions from Wave Z.

---

## NEW (introduced by Sprint 5A heal)

(none — Sprint 5A patch is scope-minimal and additive only.)

---

## RED-team adversarial pass — phone exposure across the API

I greppped every Resource for `phone` mentions to confirm Sprint 5A didn't miss a leak path:

- `MessageResource.php:24` + `:42` — `user_phone` shipped unconditionally on `/api/messages` history. **Pre-existing**, last touched in commit `209bbc515` (unrelated to Wave Z). **Out of Wave Z scope** (Z9 = Delivery flow). Flagged here for V1.0.1 backlog only; not a Round 2 regression.
- `UserResource.php`, `OrderUserResource.php`, `CustomerResource.php`, `AdministratorResource.php` — customer's own profile / admin-only resources; appropriate exposure (self-service or RBAC-gated).
- `DeliveryBoyResource.php`, `WaiterResource.php`, `EmployeeResource.php`, `ChefResource.php` — staff resources, admin-RBAC gated.
- `BranchResource.php`, `CompanyResource.php`, `SettingResource.php`, `DiningTableResource.php` — branch/company phone (public-facing contact, not customer PII).
- `SimpleOrderResource.php:58` + `KDSOrderDetailsResource.php:70` — both gated post-heal.

No new Wave Z phone-leak vector observed.

---

## Frozen-zone respect

Sprint 5A commit (`7fc62c066`) touched these files only:
- `app/Rules/ValidPhone.php`
- `app/Models/User.php`
- `app/Http/Resources/SimpleOrderResource.php`
- `app/Http/Resources/KDSOrderDetailsResource.php`
- `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue`
- `tests/Feature/KDS/KDSDeliveryEnrichmentTest.php`

Verified against CLAUDE.md §7 frozen list — **0 frozen files touched**:
- `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php` — untouched ✅
- `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue` — untouched ✅
- `FiscalSequenceService.php`, `ZReportService.php`, `AuditLogService.php` — untouched ✅
- `BranchScope.php`, `IdempotencyKeyMiddleware.php`, `PricingService.php`, `OrderStateMachine.php` — untouched ✅

✅ Frozen-zone discipline respected.

---

## Convergence verdict

**Round 2 = GO.**

All Round 1 Z9-* findings closed by Sprint 5A:
- Z9-P0-01 ValidPhone E.164 strict + PENDING_ reject + national min 9 — closed ✅
- Z9-P0-02 `User::creating` sentinel now `Log::warning`-audited + downstream `ValidPhone` rejects — closed ✅
- Z9-P0-03 `customer_phone` GDPR-gated on `OrderType::DELIVERY` in both Resources — closed ✅
- Z9-P1-03 `KdsOrderCard.customerPhone` collapses `PENDING_*` to '' — closed ✅

Healed-and-verified (pre-Wave-Z baseline still intact):
- DEL-1, DEL-2, DEL-3, DEL-4 — all green via DeliveryValidationTest 14/14 PASS.

Open carryover (Sprint 4 / V1.0.1 deferred per kickoff):
- DEL-5 hardcoded fee, DEL-6 i18n (likely complete, needs sister canonical list), DEL-7 silent zone exclusion, DEL-8 no min-order, DEL-9 no auto-dispatch.

**Z9 Delivery flow: convergence achieved. Ready for AGGREGATE Round 2.**

---

End of Z9 Round 2.
