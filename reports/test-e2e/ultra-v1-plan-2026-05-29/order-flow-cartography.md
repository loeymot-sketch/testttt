# Order-Flow / Architect — Verified Cartography (2026-05-29)

Read-only adversarial audit of how an order is created + mutated across POS, Kiosk, Mobile, Web and
how it converges to one backend order with backend-authoritative pricing. Every anchor was opened or
grepped in the live tree. (Two earlier draft assumptions were FALSIFIED during verification and
discarded: there is no `PosOrderService`/`KioskOrderService`/`App\Services\Frontend\FrontendOrderService`;
and `delivery_charge` is NOT unclamped — see "Falsified" note below. The facts below are re-confirmed.)

## Order-Taking — Verified Cartography

### Backend convergence — ONE PricingService, surface-typed entrypoints
All order creation funnels item-only payloads into `PricingService::calculateOrder`
(`app/Services/Pricing/PricingService.php:36`) and persists the backend-computed total. No create path
trusts a client total: `myOrderStore` even `unset()`s client `total`/`subtotal`/`discount` before the
`FrontendOrder::create` (`app/Services/FrontendOrderService.php:255-272`).

| Surface | Route (api.php) | Controller | FormRequest (authz) | Service → pricing + total |
|---|---|---|---|---|
| **POS** | `POST api/admin/pos/` — `Route::post('/', [PosController::class, 'store'])` (handler confirmed at `routes/api.php:798`; under `Route::prefix('pos')->name('pos.')`, so the bound route name is the admin-group prefix + `pos.` + auto — **exact route name not independently verified**); `throttle:pos-order-create`,`idempotency` | `PosController::store` (`app/Http/Controllers/Admin/PosController.php:54`; constructor `permission:pos` `:51`) | `PosOrderRequest::authorize` = `can('pos')`; rules items-JSON only, **no total**; `delivery_charge min:0` | `OrderService::posOrderStore` (`app/Services/OrderService.php:612`) → `PricingRequest::forPos` (`:752`, ctx `pos`, `enforceCrossItemGuards:true`); `$order->total = $posSsotPricingResult->total` (`:969`) |
| **Kiosk + Web (online customer)** | `POST api/frontend/order/` — `Route::prefix('order')` (`routes/api.php:1278`, name `order.`, `auth:sanctum`) nested under `Route::prefix('frontend')` (`:1211`); store handler `:1282`, `throttle:kiosk-orders`,`idempotency` | `Frontend\OrderController::store` (aliased `FrontendOrderController` at `routes/api.php:108`; `app/Http/Controllers/Frontend/OrderController.php:46`) — thin try/catch → `myOrderStore` (`:49`) | `OrderRequest::authorize` = `tokenCan('kiosk:order')` (`app/Http/Requests/OrderRequest.php:83`), tokenless test-fixture fallback restricted to `frontend.order.*` route (`:62-81`); rules items-JSON only, all money fields `min:0` (`:150-162`) | `FrontendOrderService::myOrderStore` (`app/Services/FrontendOrderService.php:132`) → `PricingRequest::forKiosk` (`:279`, ctx `kiosk`, `enforceCrossItemGuards:true`); `$frontendOrder->total = round(realSubtotal + tax + delivery − discount)` from SSOT figures (`:499-501`) |

Kiosk and web-online share the **same** controller/request/service path; the surface is distinguished
by `source`/`order_type`/token type inside the request, not a separate endpoint. SSOT is gated by
`config('pricing.use_ssot_service', true)` (`config/pricing.php:9`, env `PRICING_USE_SSOT`, default true);
when true the legacy branch (`:298-490`) is skipped entirely. Even the legacy branch reads prices from
`Item`/`ItemVariation` DB rows (`:307-310,:331-337,:355`) with the same cross-item guards (`:373-377`), so
disabling the flag does not enable client-price trust. (Admin-side mutation of online/table orders lives in
separate controllers — `Admin/OnlineOrderController` `routes/api.php:974-984`, `Table/OrderController` `:1488`.)

`forTable` (`OrderService::tableOrderStore:1246`, `:1271`) and `forWeb` (`OrderService::myOrderStore:333`,
`:359`) also exist as parallel entrypoints in `OrderService`; the live customer route uses `forKiosk` via
`FrontendOrderService`, NOT `forWeb`. The two `myOrderStore`s (one per service) are an INTENTIONAL symmetry,
locked by `tests/Feature/Order/OrderServiceFrontendOrderServiceSymmetryTest.php:32` — see P2. Callers of
`calculateOrder` (verified): OrderService.php:358/751/1270, FrontendOrderService.php:278,
Order/OrderQuoteService, Kiosk/PricingPreviewService — SSOT centralized.

### Frontend submit shape
- **Kiosk + Web (Vue)**: `KioskWizardComponent.vue` builds `item_variations` payload; POST dispatched from
  Vuex `resources/js/store/frontend/order.js` to `api/frontend/order`. item_id + option ids only — matches `OrderRequest`.
- **POS wizard (FROZEN)**: `public/js/pos-wizard.js` holds the composition UI but has **zero**
  fetch/axios/$.ajax/XMLHttpRequest and no order-endpoint string; `public/js/pos-app.js` **does not exist**
  (brief assumed it). The POST originates from the frozen `resources/views/admin-pos-v4.blade.php` inline
  layer that loads the wizard. Backend endpoint+service verified clean; the frontend caller wasn't traced (P3).
- **Mobile** (`mobile/`): `screens/`,`src/`,`components/` have 0 files with axios/fetch; consumes local
  `mobile/data/menu.js`. **Standalone, NOT wired to backend** — matches BRAIN.
- **Web showcase** (`/Users/1millnonstop/Downloads/web`): only `fetch` loads local `data/menu.js`; no order
  submission. **Standalone showcase, NOT wired.** (Distinct from the live `api/frontend/order` endpoint, which IS wired.)

### Pricing SSOT enforcement (proof — `PricingService.php`)
- Item base price from DB, never payload: `:57-61,:134`. Variation/extra/addon prices from DB: `:159,:189,:224-228`.
- Cross-item ownership guard (anti-IDOR on options): `:152-157`/`:182-187`/`:207-212` — 422 if option's `item_id` ≠ line item. All paths pass `enforceCrossItemGuards:true` (`PricingRequest.php:41,61,101`).
- Active-status + surface-visibility per option: `assertOptionsOrderable :452-555`.
- Published-profile membership + min/max/repeat: `:557-699`.
- Immutable `composition_snapshot` json-encoded at create: `:266-291`. Immutability **substantiated by negative
  check**: the ONLY write sites are 5 create-time inserts (`PricingService:291`, `OrderService:484/916/1417`,
  `FrontendOrderService:442`, all `'composition_snapshot' => json_encode(...)`); grep finds **zero**
  `->composition_snapshot =` reassignments anywhere in `app/`. Corroborated by `tests/Feature/Order/PriceChangeSnapshotTest.php`.
- Kiosk menu-formula ratio reconciled server-side: `menuRoleAdjustedAddonPrice :793-813`; addon-role bound to DB membership in `OrderRequest::validateAddonRolesAfter` (`:263`, RED-Z4 P0-Z4-01).

### OrderStateMachine — states + transitions (verbatim, `app/Domain/Order/OrderStateMachine.php`)
States (`app/Enums/OrderStatus.php:7-15`): PENDING=1, ACCEPT=4, PREPARING=7, PREPARED=8, OUT_FOR_DELIVERY=10,
DELIVERED=13, CANCELED=16, REJECTED=19, RETURNED=22.

Transitions (`allows()` `:30-75`):
- PENDING → ACCEPT | CANCELED | REJECTED
- ACCEPT → PREPARING | CANCELED  (+ DELIVERED if user `hasPermissionTo('pos')` — counter shortcut, `:41`)
- PREPARING → PREPARED | CANCELED  (+ DELIVERED if `pos` permission, `:48`)
- PREPARED → OUT_FOR_DELIVERY | DELIVERED
- OUT_FOR_DELIVERY → DELIVERED
- DELIVERED → RETURNED
- CANCELED / REJECTED / RETURNED → terminal, **except** Admin role may re-open (`:66`)
- `apply()` (`:179-254`) = atomic `lockForUpdate` + idempotent same-state early return + guard + mutate +
  audit (race fix iter15 P0-12). `requiresReason()` forces a reason for CANCELED/REJECTED/RETURNED (`:260`).
  Caveat: existing OrderService/FrontendOrderService call sites still use the legacy
  `status=;save();recordTransition()` pattern (frozen-zone V1), not `apply()`.

## Maturity score

**8.5 / 10.** Pricing SSOT is real and centralized; every create entrypoint is gated by a FormRequest
that structurally cannot accept a client total (and FrontendOrderService physically `unset()`s client
money fields). Cross-item ownership + published-profile membership + surface visibility + addon-role DB
binding are all enforced. State machine is correct, atomic, audited, race-safe. POS & Kiosk build
compositions through the same `ItemWizardProfile`/`ComposerProfileProjection` with identical
min/max/repeat enforcement (`PricingService:557-657`) — no composition price mismatch found. Deductions:
(1) two services each define `myOrderStore` (`OrderService::myOrderStore`/`forWeb` is a parallel
entrypoint with different rounding flags, not on the live customer path) — a real mis-wire seam; (2) POS
submit caller not locatable in scanned JS; (3) web-online customers carry the `kiosk:order` ability
(naming smell); (4) status-mutation authz on Admin/OnlineOrder/Table/KDS controllers only spot-checked.

## Findings (adversarial)

**[P2] app/Services/OrderService.php:333 `myOrderStore` (ctx `forWeb` :359) — parallel customer-order entrypoint not on the live customer create path (dead/mis-wire candidate).**
Repro: the live customer create path is `Frontend\OrderController::store` (`app/Http/Controllers/Frontend/
OrderController.php:46`) → `FrontendOrderService::myOrderStore` (`:132`, ctx `forKiosk` :279). Separately
`OrderService` exposes its OWN `myOrderStore` (`:333`, ctx `forWeb` :359) plus `posOrderStore`(`:612`, LIVE
via PosController) and `tableOrderStore`(`:1246`, LIVE via `Table/OrderController::store:27`). Two distinct
services each define a `myOrderStore`; only `FrontendOrderService`'s is reached by `api/frontend/order`.
Evidence: `grep myOrderStore` across routes+controllers+console → caller only in `Frontend/OrderController`
(→ FrontendOrderService live); **no controller/route/console caller of `OrderService::myOrderStore` exists**
(unlike the LIVE `tableOrderStore`), so the `forWeb` variant is unreached. The two `myOrderStore`s are held
in lockstep by an INTENTIONAL symmetry sentinel (`tests/Feature/Order/OrderServiceFrontendOrderServiceSymmetryTest.php:32-64`,
fails on method-surface drift), so it cannot just be deleted without touching that test. Risk: `forWeb`
carries DIFFERENT rounding flags than `forKiosk`/`forPos` (`PricingRequest.php:42-46` all `false` vs
`:62-66`/`:102-106` all `true`), so a future endpoint mis-wired to `OrderService::myOrderStore` would
silently persist unrounded totals. No exploit today (path unreached). Scope-minimal fix: if confirmed dead,
remove `OrderService::myOrderStore`/`forWeb` and relax the symmetry sentinel; if retained for a legacy web
client, align its rounding flags with `forKiosk`. Defer to V1.0.x.

**[P3] public/js/pos-wizard.js (frozen) + routes/api.php:798 — POS submit caller not located in scanned JS (traceability gap).**
Repro: route `admin.pos.order` → `POST api/admin/pos/` (`:798`); frozen `admin-pos-v4.blade.php` loads
`pos-wizard.js`, which has zero network calls; `pos-app.js` absent. Backend endpoint+service verified
clean; only the frontend POST caller (in the frozen Blade/inline layer) was not traced. No evidence of a
coexisting unaudited submit path — traceability gap, not a vuln. Fix: grep the frozen Blade inline JS for
the POST target and document the wire.

**[P3] app/Http/Requests/OrderRequest.php:83 — web-online customer orders authenticate with the `kiosk:order` ability.**
Repro: the single `api/frontend/order` endpoint serves kiosk machines AND web customers; authz is
`tokenCan('kiosk:order')` (web `['*']` tokens also pass). Likely by-design (one customer-order surface)
but the ability name is misleading and could cause a reviewer to mis-scope token issuance. Fix: doc line
clarifying `kiosk:order` is the shared "place a customer order" ability, or split abilities in V2 SaaS.

FALSIFIED during verification (NOT findings): (a) "unclamped negative `delivery_charge`" — `OrderRequest`
clamps `delivery_charge`/`total`/`subtotal`/`discount` to `min:0` (`:150-162`) AND recomputes the delivery
fee server-side from distance via `DeliveryFeeService` (`:108-128`); (b) "client total injection" — blocked
by FormRequest rules + DB-sourced pricing + explicit `unset()` of client money fields. No composition price
mismatch POS-vs-Kiosk. State-machine transition table is gap-free and audited.

## Existing tests (directory listing of tests/ — verified-real = basename observed in tree)
Exact filenames confirmed by `ls` of the relevant test dirs:
- `tests/Unit/Domain/Order/OrderStateMachineTest.php` — verified-real (state-machine transition matrix)
- `tests/Feature/Pos/PosOrderRequestNoClientTotalsTest.php` — verified-real (POS payload carries no client totals)
- `tests/Feature/Pos/PosSimulationHardware4ScenariosTest.php` — verified-real (POS create scenarios)
- `tests/Feature/Pos/QuoteBindingTest.php` — verified-real (quote token/signature binding)
- `tests/Feature/Pos/FritesWizardComposerTest.php` — verified-real (composer step constraints)
- `tests/Feature/Order/OrderServiceFrontendOrderServiceSymmetryTest.php` — verified-real (POS↔frontend create symmetry)
- `tests/Feature/Order/SubmitRevalidatesChoiceAvailabilityThroughPricingTest.php` — verified-real (availability re-check via PricingService)
- `tests/Feature/Order/PriceChangeSnapshotTest.php` — verified-real (composition_snapshot frozen vs price change)
- `tests/Feature/Order/CancelReasonEnforceTest.php` — verified-real (requiresReason on cancel)
- `tests/Feature/Order/AutoPrepareOnPaidTest.php` — verified-real (paid→prepare policy)
- `tests/Feature/Pricing/TaxInclusivePricesTest.php` — verified-real (TTC mode tax extraction)
- (~47 order/pricing-related test files match a `tests/` grep overall.)

NONE-FOUND / gaps:
- No dedicated `tests/Feature/Kiosk/` order-store SSOT recompute spec was observed in that dir (its files
  are payment/auto-login/reconcile focused) — the kiosk create + SSOT recompute appears covered indirectly
  by the Order/ symmetry + Pricing specs rather than a kiosk-named test.
- Dedicated regression posting a malicious `total`/`grand_total` to `api/frontend/order` with a **web**
  (`['*']`) token (vs a kiosk token) — NONE-FOUND.
- Suite NOT executed (read-only audit); pass/fail status NOT VERIFIED.
