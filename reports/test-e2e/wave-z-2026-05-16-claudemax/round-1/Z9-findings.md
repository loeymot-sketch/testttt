# Z9 — Delivery flow — Round 1 findings

**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**HEAD audited** : `c3ba89863`
**Heal commits verified** : `c3ba89863` (Sprint 2B), `5f48856f9` + `a8b363dd6` + `80dbc79c2` (Sprint 2A/3C)
**Sister-verdict** : `reports/audit/ultra-review-2026-05-16/ULTRA_REVIEW_VERDICT.md` — 5 P0 (DEL-1..DEL-5) + 4 P1 (DEL-6..DEL-9)
**Mode** : read-only adversarial RED-team

---

## Summary

Sprint 2B addresses DEL-1, DEL-2, DEL-4 with real backend code. Sprint 2A/3C addresses DEL-3.
DEL-5, DEL-7, DEL-8, DEL-9 confirmed **still open** per heal scope (Sprint 4 hardening).
DEL-6 i18n keys: 8/≥10 sampled keys present — **partial coverage** (downgrade to needs-verification).

**Outcome** : **NO-GO Round 1**. 2 P0 NEW + 1 P0 not-fully-healed + 4 P1 NEW + 2 P2.

The big-ticket regression: the commit subject for `c3ba89863` claims "User.phone **E.164 required**" — the
implementation (`ValidPhone::passes`) only checks digit-count 8..15. `12345678` passes. The NOT NULL
migration is real and works; the E.164 claim is rhetorical, not technical. Additionally `User::creating`
silently backfills `PENDING_CREATE_<hex>` on any model creation event, so every code path that does NOT
explicitly go through `SignupRequest` (admin tooling, console, future controllers, factory backfill,
`User::firstOrCreate`) can persist a sentinel-phone account, completely bypassing the validation gate.

The DEL-3 KDS enrichment is correctly delivery-gated in `KdsOrderCard.vue` (Vue `isDeliveryOrder`), but
the **wire-level** `SimpleOrderResource` JSON exposes `customer_phone` unconditionally for every order on
admin orders / POS sales / online-orders endpoints — a real privacy bug irrespective of the Vue gate.

---

## P0 findings

### Z9-P0-01 — DEL-4 commit subject claims E.164 enforcement; implementation only checks digit count
**Severity rationale** : the entire DEL-4 finding hinged on "no E.164 validation". The heal commit
subject promises "User.phone E.164 required". The actual rule (`ValidPhone`) accepts any string that
becomes 8..15 digits after `\D+` stripping — including `12345678`, `00000000`, `1111111111111`. No `+`
prefix, no country-code validation, no FR-MSISDN / EU MSISDN format check, no `libphonenumber`. The
audit-claimed P0 is partially healed in column-NOT-NULL but the format gate is **functionally absent**.
**File:line** :
- `app/Rules/ValidPhone.php:26-43` — only `preg_replace('/\D+/', '', $value)` + length check
- `app/Http/Requests/SignupRequest.php:34` — `new ValidPhone()` is the only phone gate at signup
- `app/Http/Requests/OrderRequest.php:258` — same rule reused for delivery
- `app/Http/Requests/AddressRequest.php:71` — same rule reused for address creation
- Commit `c3ba89863` subject : `feat(delivery): Sprint 2B — geocode_status + User.phone E.164 required`

**Adversarial demo** : `phone = "00000000"` → 8 digits → ValidPhone passes → signup succeeds → order
DELIVERY succeeds → KDS shows `tel:00000000` → livreur dials nothing.

**Heal direction** : either downgrade the commit subject to "User.phone NOT NULL + length range
required" (no code change, doc-only) OR add a real E.164 regex such as `/^\+?[1-9]\d{1,14}$/`
combined with `country_code` cross-check.

---

### Z9-P0-02 — `User::creating` silent `PENDING_CREATE_*` backfill defeats the NOT NULL gate
**Severity rationale** : the migration ships `users.phone NOT NULL`, but `User.php:107-111` installs
an auto-backfill that injects `PENDING_CREATE_<hex>` whenever `$user->phone` is empty/null at
`creating` time. Net effect: **any** `User::create(['name' => 'x', 'email' => 'y@y.fr'])` invocation
succeeds and persists a sentinel-phone account. The DELIVERY-only `OrderRequest` hook + the
`AddressRequest` hook ARE the only places that detect `PENDING_` prefix — pickup, kiosk, takeaway,
walk-in, admin tooling, console commands, future controllers all bypass detection. The migration's
NF525-style invariant ("`users.phone NOT NULL`") is **decorative**: there is no path through the
codebase that forces a real phone outside the two gated FormRequests.
**File:line** :
- `app/Models/User.php:107-111` — `static::creating(...)` injects `'PENDING_CREATE_' . bin2hex(random_bytes(6))`
- `app/Http/Requests/OrderRequest.php:226-228` + `OrderRequest.php:249-255` — `PENDING_` detection
  applied **only** when `order_type === OrderType::DELIVERY (5)`
- `app/Http/Requests/AddressRequest.php:62-69` — `PENDING_` detection applied only on address mutations
- `app/Services/Pos/WalkInCustomerResolver.php:31` — sentinel `'PENDING_WALKIN'` is the legitimised
  example: the project itself uses the backfill as a known bypass

**Implication** : a future feature that creates a User row (loyalty quick-enrol, kiosk anon checkout,
admin manual customer add) and routes the customer to a non-DELIVERY non-Address flow will leak a
sentinel-phoned user into the system forever. The "next login fails" claim in the commit message is
not supported by code — no login-time `PENDING_` gate exists.

**Heal direction** : remove the `User::creating` auto-backfill OR add a global "no PENDING_" check
inside `LoginController` / Sanctum guard — but the cleanest fix is to require explicit phone supply
at every creation site and let the migration's NOT NULL constraint do its job.

---

### Z9-P0-03 — `SimpleOrderResource` exposes `customer_phone` unconditionally on every order
**Severity rationale** : a SaaS API that ships every customer's raw phone number on every order row
(POS, online, admin sales report, kiosk) to any staff role with read-access to orders is a privacy
defect (GDPR art. 5(1)(c) data-minimisation). The Vue UI gates rendering by `isDeliveryOrder` but
the *wire-level* JSON ships the field for every order regardless of `order_type`. Network tab + DevTools
+ a staffer with /admin/pos-orders access = unrestricted phone harvest.
**File:line** :
- `app/Http/Resources/SimpleOrderResource.php:52` — `'customer_phone' => $this->user?->phone` — no gate
- `app/Http/Controllers/Admin/OnlineOrderController.php:48` — exposes via `/api/admin/online-orders`
- `app/Http/Controllers/Admin/PosOrderController.php:98` — exposes via `/api/admin/pos-orders`
- `app/Http/Controllers/Admin/SalesReportController.php:43` — exposes via `/api/admin/sales-report`
- `app/Http/Resources/KDSOrderDetailsResource.php:62-67` — same pattern on KDS endpoint

**Heal direction** : wrap the field in `'customer_phone' => $this->when((int)$this->order_type === OrderType::DELIVERY, fn() => $this->user?->phone)` so KDS / sales / orders consumers only see phone for delivery orders. Same conditional applies to `KDSOrderDetailsResource.php:62-67`.

---

## P1 findings

### Z9-P1-01 — DEL-5 still open: `DeliveryFeeService` hardcoded barème `max(5, ceil(d/5)*5)` EUR
**Severity rationale** : sister-verdict P0 not addressed by Sprint 2B (out of scope). Re-validated
unchanged. Branch operators cannot configure delivery fees per region / per market.
**File:line** :
- `app/Services/Delivery/DeliveryFeeService.php:14` — `return (float) max(5, (int) ceil($distance / 5) * 5);` — hardcoded.

**Status** : open-from-sister, deferred to Sprint 4 / V1.0.1. Not a regression.

---

### Z9-P1-02 — DEL-7 still open: `BranchService::showByLatLong` silently excludes branches with NULL `zone`
**Severity rationale** : if any branch is provisioned without a polygon (admin wizard skipped the
zone-draw step), it is silently excluded from delivery dispatch with **no operator-facing warning**
and no log line — the customer gets `out_of_service_area` with no diagnostic. Open-from-sister.
**File:line** :
- `app/Services/BranchService.php:132` — `Branch::whereNotNull('zone')->where('status', Status::ACTIVE)->get()`
- `app/Services/BranchService.php:189` — same pattern on branchShowByLatLong

**Heal direction** : `Log::info('branch.delivery.no_zone', [...])` for any branch with NULL zone after this filter, surface a banner in admin → branches list.

---

### Z9-P1-03 — `User::creating` sentinel persists raw into UI when DELIVERY order is placed by legacy backfilled user
**Severity rationale** : if a legacy NULL-phone user (backfilled to `PENDING_<id>` by migration) ever
reaches the DELIVERY checkout via a code path that bypasses the AddressRequest gate (e.g. address
already saved pre-migration, but `OrderRequest` order_type defaults to non-DELIVERY then user toggles
delivery client-side, then the address persistence path uses cached address row), then their phone
sentinel renders raw in the KDS `tel:` link.
**File:line** :
- `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue:99` — `tel:${customerPhone}` with no `PENDING_` sentinel detection
- `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue:103` — `{{ customerPhone }}` rendered verbatim
- `database/migrations/2026_05_16_140100_make_user_phone_required.php:76-90` — backfills `PENDING_<id>` (visible to UI)

**Heal direction** : add a `if (str_starts_with($phone, 'PENDING_')) return null;` filter inside the resource phone exposure logic, OR add a Vue-side guard `customerPhone || ''` ➜ `(customerPhone && !customerPhone.startsWith('PENDING_')) ? customerPhone : ''`.

---

### Z9-P1-04 — DEL-6 i18n keys: partial coverage (8 sampled, sister claimed ≥10 missing)
**Severity rationale** : Sister-verdict claimed "≥10 keys missing". Spot-check finds 8 present in
`resources/js/languages/fr.json:669-674` + `:264` + `:[preferred_time]`. Without the original sister
key-list to enumerate against, cannot confirm full healing. Downgrade to "partial / needs verification".
**File:line (present)** :
- `resources/js/languages/fr.json:264` — `delivery_charge` (rates group)
- `resources/js/languages/fr.json:669` — `delivery_address`
- `resources/js/languages/fr.json:670` — `delivery_boy`
- `resources/js/languages/fr.json:671` — `delivery_charge` (label group, duplicate)
- `resources/js/languages/fr.json:672` — `delivery_information`
- `resources/js/languages/fr.json:673` — `delivery_time`
- `resources/js/languages/fr.json:674` — `delivery_zone`
- `resources/js/languages/fr.json` (line not captured) — `preferred_time`
- `lang/fr/all.php:44` — `delivery_charge` (PHP back-end side)

**Status** : open-from-sister, partial. Round 2 should run a full diff against sister-verdict canonical
key list to confirm.

---

## P2 findings

### Z9-P2-01 — DEL-8 still open: no per-branch / per-zone minimum order amount for DELIVERY
**Severity** : the only minimum-order check is on `Coupon::minimum_order` for coupon application —
no branch-side or order-type-side minimum-order configurable. Customers can order a single 2 EUR
item with 5 EUR delivery for negative margin. Open-from-sister, Sprint 4.
**File:line** :
- `grep -rn 'minimum_order\|min_order' app/` returns only Coupon-related matches
- No `branch.minimum_order_for_delivery` column / settings entry exists

---

### Z9-P2-02 — DEL-9 still open: no auto-dispatch livreur, no push/SMS to assigned driver
**Severity** : sister-verdict noted this as Sprint 4 hardening. Confirmed unchanged. Manual livreur
assignment is the only path. Out of Sprint 2B scope by design.
**File:line** :
- `grep -rn 'auto.*dispatch\|DeliveryBoyAssign'` in `app/` → 0 matches.

---

## P3 findings

(none)

---

## Healed-verified

### DEL-1 — geocode_status column + gate functional ✅
- `database/migrations/2026_05_16_140000_add_geocode_status_to_addresses_table.php:42-52` — column
  `VARCHAR(20)` nullable, indexed `addresses_geocode_status_idx`, CHECK constraint
  `('OK','PARTIAL','ZERO_RESULTS','ERROR')` on MySQL/MariaDB/PgSQL, NULL allowed for legacy rows.
- `app/Services/Delivery/DeliveryQuoteService.php:33-37` — gate now reads real column, rejects with
  `GeocodeUnavailableException` when status != 'OK'. NULL falls back via `?? 'OK'` (legacy safety).
- `app/Services/AddressService.php:57-60`, `:76-79`, `:103-120` — `deriveGeocodeStatus()` populates
  new writes based on lat/lng validity.
- `app/Services/UserAddressService.php:60-63`, `:81-85` — same on admin-side address writer.
- `app/Models/Address.php:16` — `geocode_status` in `$fillable`.
- Verdict: **gate is real**. P0 closed.

### DEL-2 — Silent IDOR skip replaced with `OrderAddressOwnershipException` throw ✅
- `app/Services/FrontendOrderService.php:546-556` — IDOR check still in place; `$address === null` now
  throws `OrderAddressOwnershipException` inside the `DB::transaction` block (line 176).
- `app/Exceptions/OrderAddressOwnershipException.php:32-49` — HTTP 403, error code
  `ORDER_ADDRESS_FORBIDDEN`, customer-facing FR message.
- Exception bubbles through `catch (HttpException)` at `FrontendOrderService.php:612-613` and is re-thrown — Laravel handler renders JSON 403 via `render($request)` method on the exception.
- DB::transaction rollback guarantees `FrontendOrder` + `OrderItem` + `OrderCoupon` + `StockService::decrementForOrder` all atomically reverted.
- Verdict: **closed**. P0 closed.

### DEL-3 — KDS + admin resources expose `order_address` + `customer` ✅
- `app/Http/Resources/KDSOrderDetailsResource.php:55-67` — exposes `order_address` (label, address,
  apartment, latitude, longitude) and `customer` (name, phone). `whenLoaded('address'|'user')` guards
  against N+1 / missing-eager-load.
- `app/Http/Resources/SimpleOrderResource.php:45-52` — exposes `order_address` (same subset) +
  `customer_phone` (raw, no gate — see Z9-P0-03).
- `app/Services/KitchenDisplaySystemOrderService.php:70` — `Order::with(['orderItems', 'address', 'user'])` eager-loaded.
- `app/Services/KdsSyncService.php:60` — mirrors eager-load for delta-sync parity.
- `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue:80-105` — delivery block gated by `isDeliveryOrder` computed at `:287-294` (source_surface='delivery' OR order_type===5).
- Verdict: **closed for KDS rendering**. **Wire-level over-exposure** flagged separately as Z9-P0-03.

### DEL-4 — `users.phone` is NOT NULL + DELIVERY phone gate ✓ partial
- `database/migrations/2026_05_16_140100_make_user_phone_required.php:46-56` — `up()` backfills NULL rows with `PENDING_<id>` then flips column to NOT NULL via dialect-specific `ALTER TABLE` (MySQL `MODIFY`, PgSQL `ALTER COLUMN`, SQLite `PRAGMA + rebuild`).
- `database/factories/UserFactory.php:31` — fakes a unique 10-digit FR mobile.
- `app/Http/Requests/OrderRequest.php:226-228` + `:242-262` — `validateAuthenticatedUserPhoneForDelivery` blocks `PENDING_*` and `ValidPhone::passes() === false` on DELIVERY orders.
- `app/Http/Requests/AddressRequest.php:54-76` — same logic on address create/update.
- Verdict: **NOT NULL column closed**; **E.164 claim not delivered** (see Z9-P0-01); **silent backfill defeats the gate at creation time** (see Z9-P0-02).

---

## Open-from-sister (not addressed by Sprint 2B by design)

- **DEL-5** (P1 Z9-P1-01) — hardcoded delivery fee. Sprint 4 hardening per kickoff.
- **DEL-6** (P1 Z9-P1-04) — partial i18n coverage. Round 2 should diff against sister's canonical list.
- **DEL-7** (P1 Z9-P1-02) — `whereNotNull('zone')` silent branch exclusion.
- **DEL-8** (P2 Z9-P2-01) — no minimum order delivery.
- **DEL-9** (P2 Z9-P2-02) — no auto-dispatch. Listed as Sprint 4 hardening in kickoff (`00_KICKOFF.md:40`).

---

## NEW (introduced by heals)

- **Z9-P0-01** — Commit `c3ba89863` subject overpromises E.164 — `ValidPhone` is digit-length only.
- **Z9-P0-02** — `User::creating` silent `PENDING_CREATE_*` backfill creates a permanent leak path
  around the NOT NULL gate (introduced at `User.php:107-111`).
- **Z9-P0-03** — `SimpleOrderResource.php:52` + `KDSOrderDetailsResource.php:62-67` ship
  `customer_phone` / `customer.phone` unconditionally — wire-level privacy bug ungated by `order_type`.
- **Z9-P1-03** — `PENDING_<id>` / `PENDING_CREATE_<hex>` sentinel may render raw in `KdsOrderCard.vue`
  `tel:` link and visible text for legacy backfilled users on DELIVERY orders.

---

## Frozen-zone respect

Sprint 2B heal commit (`c3ba89863`) and Sprint 2A/3C commits (`5f48856f9`, `a8b363dd6`, `80dbc79c2`)
touched **0 frozen files** (verified via `git show --stat` against CLAUDE.md §7 frozen list).

- `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php` — untouched
- `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue` — untouched
- `FiscalSequenceService.php`, `ZReportService.php`, `AuditLogService.php` — untouched
- `BranchScope.php`, `IdempotencyKeyMiddleware.php`, `PricingService.php`, `OrderStateMachine.php` — untouched

✅ Frozen-zone discipline respected.

---

## Convergence verdict

**Round 1 = NO-GO.**

Blocking issues for Round 2 heal cycle (P0 — must fix before convergence):
- Z9-P0-01 — either implement E.164 or correct the commit subject (low-cost: doc-only)
- Z9-P0-02 — either remove `User::creating` silent backfill or add a login-time `PENDING_` gate
- Z9-P0-03 — gate `customer_phone` exposure on `order_type === DELIVERY` in both Resources

Recommended (P1 — improves but not blocking):
- Z9-P1-03 — `PENDING_` sentinel filter in KdsOrderCard.vue + Resource layer
- Z9-P1-04 — re-grep i18n keys against sister-verdict canonical list

Healed-and-verified (will pass Round 2 unchanged):
- DEL-1 (column + gate functional)
- DEL-2 (throw + rollback)
- DEL-3 (KDS Vue + Resources, modulo Z9-P0-03)
- DEL-4 column NOT NULL (modulo Z9-P0-01 / Z9-P0-02)

---

End of Z9 Round 1.
