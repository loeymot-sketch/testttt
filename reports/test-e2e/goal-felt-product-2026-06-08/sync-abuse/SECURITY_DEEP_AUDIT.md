# SECURITY DEEP AUDIT — realtime / mutating / customer-facing surface

**Scope:** FoodKing V1 LOCAL "Le Cayenne" (single-box, FR, single-branch, 0 cloud).
**Mode:** READ-ONLY audit. Fixes applied by main thread. Probes against disposable
`:8766 / foodking_e2e` clone only (no operating-DB writes).
**Date:** 2026-06-08. **Branch:** heal/cms-pr1-quickwins / worktree pre-cloud-exec.
**Cloud/scale/multi-tenant gaps explicitly OUT of scope (V2).**

Prior falsification sweep already HEALED (NOT re-reported here):
- Loyalty `register` 409 PII echo (`existing_phone`+`existing_loyalty_code`) — fixed `d27ebb56d`.
- POS `print-receipt` missing `permission:pos` — fixed; route now `['permission:pos','idempotency']` (api.php:916), controller `Admin/Pos/PosReceiptPrintController`.

---

## 1. Endpoint posture table (mutating / sensitive surface)

| Endpoint | Auth / gate | Verdict |
|---|---|---|
| `POST /frontend/loyalty/check` (api.php:1407) | `auth:sanctum`+`throttle:10,1`; **NO** KioskMachine/owner/staff check | **P1 — PII enumeration (NEW)** |
| `POST /frontend/loyalty/register` (1408) | public+`throttle:5,1`; PII echo removed | OK (healed d27ebb56d) |
| `POST /frontend/loyalty/opt-in` (1468) | public+`throttle:5,1` → delegates `register()` | OK (inherits register heal) |
| `POST /frontend/loyalty/scan` (1475) | `auth:sanctum`; **requires `tokenCan('kiosk:order')`** (ctrl L611); HTTP200 no-PII fail | OK |
| `POST /frontend/loyalty/redeem` (1418) | `auth:sanctum`+`idempotency`; KioskMachine-row OR owner OR staff (L286-314) | OK (IDOR fixed 8db38d801) |
| `POST /frontend/loyalty/add-points` (1413) | `auth:sanctum`; staff-role check (L191-194) | OK |
| `GET /frontend/loyalty/history|balance` (1420-21) | `auth:sanctum`; scoped to `$user->id` | OK |
| `GET/POST /frontend/order/*` (1311-1321) | `auth:sanctum`; `show()`+`changeStatus()` enforce `user_id===Auth::id()` else 403 (svc L671/L702); FrontendOrder has BranchScope | OK (IDOR-safe) |
| `POST /frontend/order/{o}/payment-confirm` (1321) | `auth:sanctum`+`idempotency`; owner(L123)+branch(L129,L168)+amount-echo(L139)+dup-txn(L189) | OK (robust) |
| `POST /frontend/pricing/preview` (1453) | `auth:sanctum`; **requires KioskMachine row** (L34); generic 500 on internal err | OK |
| `POST /frontend/promo/validate` (1458) | `auth:sanctum`; **requires KioskMachine row**; getMessage only in Log | OK |
| `GET /frontend/upsell` / `/menu` (1448-63) | `auth:sanctum`+`kiosk.locale` | OK |
| `POST /frontend/kiosk/event` + `/kiosk-event` (1435,1481) | `auth:sanctum`+`abilities:kiosk:order` (route-level fail-closed) | OK |
| `POST /table/dining-order` (api.php:1521) | apiKey-only (unauth QR)+`throttle:20,1` | OK (intended public table order) |
| `POST /admin/pos/counter-collect/*/confirm|cancel`, `/collect-kiosk-cash/*` (838-910) | `abort_unless(can('pos'))` inline + `idempotency` | OK |
| `POST /admin/pos/cash-drawer*`, `/cash-sessions/*` | `permission:pos` / controller `__construct` + `idempotency` | OK |
| Admin CRUD (`item/coupon/offer/branch/role/permission/...`) | `block_kiosk_token_admin` + `auth:sanctum`; per-method `$this->middleware('permission:*')` in `__construct` AND/OR FormRequest `can()` | OK (no sibling gap) |

---

## 2. Findings (prioritized)

### [P1] `app/Http/Controllers/Frontend/LoyaltyController.php:60-112` (`check()`) — unauthenticated loyalty PII enumeration
**Route:** `POST /api/frontend/loyalty/check` — `auth:sanctum`, `throttle:10,1` (api.php:1407).

**Vuln.** `check()` takes an arbitrary `code` (loyalty code OR phone) from the body,
looks up ANY `User` (L75/L79), and returns `{name, points, discount_value, loyalty_code}`
(L90-98). It has **NO** KioskMachine-row / owner / staff discriminator (grep of L60-112 = NONE).
`auth:sanctum` is satisfied by a **guest token**: `GuestSignupController:146` mints
`createToken('auth_token', ['kiosk:order'], +30d)` for a `branch_id=0` guest obtained via
the public `/api/auth/guest-signup` flow (only the static client-bundle `x-api-key` + an OTP).
That token has the `kiosk:order` ability but no KioskMachine row — so an attacker who is NOT a
kiosk machine and owns NOTHING can POST a victim's **phone number** and harvest their full
**name + loyalty balance + 8-char redemption code**. Phone→identity enumeration, GDPR
disclosure. Same data class the prior sweep healed as **P1** on `register`; `check()` is its
unfixed sibling.

**Reproduction (LIVE, :8766/foodking_e2e):** seeded victim `phone=0612345678, loyalty_code=VICT1234, points=250`.
Minted a guest token (`['kiosk:order']`, `branch_id=0`, status=ACTIVE, **KioskMachine row = NO**), then:
```
POST /api/frontend/loyalty/check  (x-api-key + Bearer <guest_token>)
 body {"code":"VICT1234"} → {"status":true,"data":{"name":"Victim Secret","points":250,"discount_value":2.5,"loyalty_code":"VICT1234"}}
 body {"code":"0612345678"}→ {"status":true,"data":{"name":"Victim Secret","points":250,"discount_value":2.5,"loyalty_code":"VICT1234"}}
```
The guest token has zero relationship to the victim, yet receives the victim's PII by phone.

**Why siblings are safe (sharpens the finding):** `scan()` gates on `tokenCan('kiosk:order')`
+ returns no PII on miss; `pricing/preview`, `promo/validate` require a real **KioskMachine row**
(L34-44). `check()` is the only loyalty endpoint missing that discriminator. `redeem()` already
got exactly this fix (8db38d801, L286-290).

**Scope-minimal fix.** Mirror the `redeem()` discriminator: before returning PII, require the
caller be a real kiosk machine OR staff OR the owner of the looked-up account, e.g.
```php
$caller  = $request->user();
$isKiosk = $caller && $caller->tokenCan('kiosk:order')
    && \App\Models\KioskMachine::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
        ->where('user_id', $caller->id)->exists();
$isStaff = $caller && $caller->hasAnyRole(['Admin','Branch Manager','POS Operator','Stuff']);
$isOwner = $caller && (int)$caller->id === (int)$user->id;
if (!$isKiosk && !$isStaff && !$isOwner) {
    return response()->json(['status'=>false,'message'=>'Non autorisé'], 403);
}
```
(Place after the `$user` lookup, before the L90 data return. `balance()` calls `check()` so it
inherits the guard — confirm the mobile balance flow still resolves the owner path. Add a
regression test asserting a guest token gets 403.)

**owner_gated:** NO (non-frozen controller). **is_v1_blocker:** **YES** — V1 single-box
exposes the kiosk on the shop LAN; the guest-token mint is reachable with the public API key,
so this is real customer-PII disclosure under NF525/GDPR custody, not a V2 multi-tenant concern.

---

### [P3] `app/Services/FrontendOrderService.php:674` — cross-user `show()` 403 downgraded to 422 + message leak
`show()` correctly blocks cross-user reads (`abort(403)` when `user_id !== Auth::id()`), but the
surrounding `try/catch (Exception)` (L675-677) catches the 403 `HttpException` and re-throws as a
generic `Exception(..., 422)`, surfacing the literal string *"Access denied: you do not own this
order."* with a wrong status. **No data leaks** (ownership still enforced) — this is error-mapping
hygiene only. Fix: rethrow `HttpException`/`AuthorizationException` before the generic catch (same
pattern already used in `OrderController::paymentConfirm` L308-309). owner_gated: NO. is_v1_blocker: NO.

---

## 3. Attested negatives (positive evidence — core holds)

- **Order IDOR — SAFE.** `FrontendOrder` carries `BranchScope` (model L23). `show()` (svc L671) and
  `changeStatus()` (svc L702) both gate on `user_id === Auth::id()` → 403. A kiosk/guest token cannot
  read or cancel another customer's order. `changeStatus` additionally limited to CANCELED for
  kiosk tokens by `OrderStatusRequest::authorize()` (L31).
- **paymentConfirm — SAFE.** Owner check (L123), kiosk-machine existence (L115-121), branch match
  (L129 + locked re-check L168), TPE amount-echo gate (L139), duplicate-transaction guard (L189),
  idempotent (route + `UNIQUE(transaction_id)`). Cross-branch / cross-user / replay all rejected.
- **Admin mutating surface — NO sibling to print-receipt gap.** Every sensitive admin
  POST/PUT/PATCH/DELETE is gated by per-method `$this->middleware('permission:*')->only(...)` in the
  controller `__construct` (verified: Item, Coupon, ItemVariation/Extra/Addon, OfferItem, Printer,
  PaymentTerminal, ItemPhoto, ParkedOrder, Floorplan, Availability, DefaultAccess `permission:settings`,
  StockRupture `run`→`items_create`) and/or a FormRequest with a real `can()`
  (CouponRequest/OfferRequest/ItemRequest verified `->can('..._create|edit')`). The whole `admin`
  prefix also carries `block_kiosk_token_admin` (api.php:274,295) — a stolen kiosk token cannot reach it.
- **FormRequest authz drift — held.** Sentinel baseline 66 (`FormRequestAuthzDriftSentinelTest`); the
  `return true` FormRequests that remain back routes that are independently permission-gated by
  controller `__construct` middleware (defense-in-depth), not naked mutating endpoints.
- **Token abilities — SAFE.** Kiosk/guest tokens scoped to `['kiosk:order']` only (GuestSignup L146,
  KioskMachineLogin). `block_kiosk_token_admin` fences the admin bucket. `abilities:kiosk:order`
  route middleware fail-closes both `kiosk-event` aliases.
- **Idempotency / replay — SAFE.** `config/idempotency.php required_routes` covers `frontend/order`,
  `order/*/payment-confirm`, all `change-status/*`, `pos/counter-collect/*`, `collect-kiosk-cash/*`,
  cash-drawer/sessions, and `frontend/loyalty/redeem` → no double-encaissement / double-status / double-debit.
- **Info leak on public 4xx/5xx — SAFE.** `PricingPreview` echoes only domain `InvalidArgumentException`
  strings; generic `Exception` → "Erreur serveur." (no SQL/class/internal). `Promo`/`Upsell` use
  `getMessage()` only inside `Log::`. CspReportController redacts `token/password/email/phone/api_key`.
- **Secrets — NONE client-reachable.** No `app.key`/provider secret/password emitted in any frontend
  response or MenuResource; `loyalty.qr.secret`/`app.key` used server-side for HMAC only (ctrl L762).

---

## 4. Verdict

**One NEW V1-blocking gap: `check()` loyalty PII enumeration (P1)** — directly analogous to the
just-healed `register` P1, with LIVE proof via a bare guest token. One P3 error-mapping nit. The
realtime/order/cash/payment/admin-mutation core otherwise **holds** with positive evidence on every
axis (IDOR, replay, token abilities, info-leak, secrets). Recommend main thread apply the `check()`
discriminator (mirrors redeem 8db38d801) + regression test, then re-run the loyalty suite.
