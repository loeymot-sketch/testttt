# A11 — Card / TPE / Payment Confirm — Adversarial Audit
**HEAD** `a220b9bd8` | **Branch** `feature/mobile-app-le-cayenne-2026-05-10` | **Date** 2026-05-11
**Scope** Kiosk → POS card payment confirm flow (controller, route, FormRequest, model, tests).

---

## §1 — Findings (P0/P1/P2/P3)

### P0 — One real blocker (P0-13 fake E2E confirmed).

#### P0-13 (CONFIRMED) — `tests/e2e/05-pos-card.spec.js` is a fake E2E
**File** `tests/e2e/05-pos-card.spec.js:99-107`
**Evidence** The "adversarial-grade non-conditional click cycle" advertised in the header is gated by a `test.fixme(true, ...)` escape hatch that fires when `[data-testid="pos-v5-pay"]` is not visible (empty catalogue / unseeded CI). Lines 100-107:

```js
if (!(await payBtn.isVisible({ timeout: 5_000 }).catch(() => false))) {
    // …
    test.fixme(true, 'pos-v5-pay non visible : env catalogue vide…');
    return;
}
```

Combined with `if (await addCta.isVisible({…}).catch(() => false))` (line 83), and the second `noteOrSearch` step that swallows errors silently (line 92-95), the test reports green on any environment where the cart is not pre-seeded — i.e. the default CI state. The card flow is **never exercised** in CI. The companion-spec attribution from the past audit holds: this passes on a CI lane that does not own the card path. **Real coverage = 0 for /api/frontend/order/{id}/payment-confirm via Playwright.** Backend coverage is excellent (see §3) but the E2E claim is unsupported.

**Severity P0** because the test name and `data-testid` matrix imply card-flow protection that does not exist.

---

### Past-audit findings closed since 2026-05-09 — verified afresh.

| Past ID | Status | Evidence file:line |
|---|---|---|
| F-002 amount echo | **CLOSED** | `app/Http/Controllers/Frontend/OrderController.php:137-152` + `app/Http/Requests/Frontend/PaymentConfirmRequest.php:43` (required+integer+min:1, gate fires before lock, ±1c tolerance, `AMOUNT_ECHO_MISMATCH` stable error code). |
| P0-08 abilities middleware gap | **NOT P0** — downgraded to P1 (see below). Route-level middleware genuinely absent (`routes/api.php:1113` group has only `auth:sanctum`), **but** `PaymentConfirmRequest::authorize()` at `app/Http/Requests/Frontend/PaymentConfirmRequest.php:19-25` checks both `tokenCan('kiosk:order')` AND `KioskMachine::where('user_id')->exists()` — no constructed exploit demonstrated. Sentinel `PaymentConfirmAbilitySentinelTest:45-54` confirms a `['*']`-token non-kiosk user returns 403. |
| Cross-branch confirm | **CLOSED** | Pre-lock branch check `OrderController.php:129` + re-check inside `lockForUpdate` at `:168-170`. Sentinel + Feature test cover (`PaymentConfirmCrossBranchTest:28-55`). |
| Duplicate `transaction_id` two orders | **CLOSED** | `OrderController.php:184-191` (UNIQUE-style guard) + `PaymentConfirmCrossBranchTest:87-130`. |
| Same `transaction_id` replay on paid order | **CLOSED (idempotent)** | `OrderController.php:193-207` returns 200 with same tx, 409 on different tx. |
| BranchScope on PendingPaymentConfirmation | **CLOSED** | `app/Models/PendingPaymentConfirmation.php:22-25` registers `BranchScope`. |
| F-21 status-machine invariant | **CLOSED** | `FrontendOrderService.php:1077-1092` (PENDING whitelist + payment_status=PAID re-check inside lock). |

---

### P1 — Three real defense-in-depth gaps.

#### P1-A — Route-level `abilities:kiosk:order` missing on `frontend/order` group
**File** `routes/api.php:1113-1121`
**Evidence** The group declares only `middleware(['auth:sanctum'])`. The inline 11-line comment at `:1102-1112` explicitly documents the choice to enforce inside `OrderRequest::authorize()` instead, citing test fixtures using `actingAs($user, 'sanctum')` that would 401 under Sanctum's `CheckAbilities`. Reasonable rationale, but the design has TWO secondary effects:

1. New routes added to the group inherit ONLY `auth:sanctum`. Anyone adding a route here must remember to authz via FormRequest. `payment-confirm` was added with a dedicated `PaymentConfirmRequest` — good — but future additions may not. The defence is documentation-bound.
2. **Tooling drift** — security scanners that look for `abilities:` middleware on state-changing routes will flag this group as a CVE candidate. Not a runtime issue, but a triage cost.

**Severity** P1 (defense-in-depth, not a runtime exploit). Mitigation: add a sentinel test that fails when a new route in this group lacks a FormRequest with `tokenCan('kiosk:order')` in `authorize()`.

#### P1-B — TransientToken/session-auth bypass of ability check
**File** `app/Http/Requests/Frontend/PaymentConfirmRequest.php:14-25`
**Evidence** The `authorize()` method does:
```php
$token = $user && method_exists($user, 'currentAccessToken')
    ? $user->currentAccessToken() : null;
$hasKioskAbility = $token ? $user->tokenCan('kiosk:order') : app()->runningUnitTests();
```
For session-authenticated users (web guard), `currentAccessToken()` returns a `TransientToken` whose `can()` always returns `true`. The ternary catches `$token ? …` — `TransientToken` is truthy, so `tokenCan()` is invoked and returns `true` for ANY session-auth user. The `app()->runningUnitTests()` fallback is dead code in production.

The last line of defence becomes the `KioskMachine::where('user_id')->exists()` pivot check. Exploitability hinges on whether anyone with a `KioskMachine` pivot row can session-login (kiosk machine users currently log in via `KioskMachineLoginController` which mints a PAT, not a session — verify in deploy). Sentinel coverage is incomplete for this specific path.

**Severity** P1 (latent — depends on auth surface). Fix: explicitly reject `TransientToken` in `authorize()` for production (`if ($token instanceof TransientToken && !app()->runningUnitTests()) return false;`), mirroring the pattern used at `OrderRequest.php:247-250`.

#### P1-C — `Throwable` swallow on `finalizePaidKioskOrder` masks fiscal failures
**File** `app/Http/Controllers/Frontend/OrderController.php:266-282`
**Evidence** Any exception from `finalizePaidKioskOrder` (including fiscal allocation crash AFTER its inner `DB::transaction` rollback at `FrontendOrderService.php:1132-1167`) is caught and logged, and the HTTP response is still 200 `Paiement confirmé`. The order remains `payment_status=PAID, status=PENDING, fiscal_sequence_no=NULL, fiscal_alloc_error_at=now()`. Recovery depends on `foodking:fiscal:retry-alloc` cron.

The intent (don't 422 on post-commit side-effect after payment is persisted) is correct and was a fix from `B-001 round-2`. But the kiosk receives no signal that fiscal allocation failed. NF525 implication: silent orphan window until cron picks up. Verify cron is wired in production (`app/Console/Kernel.php` schedule for `foodking:fiscal:retry-alloc`).

**Severity** P1 (dependency risk on out-of-band recovery, fiscal-adjacent).

---

### P2 — Hardening.

#### P2-A — `payment_method` enum drift risk in confirm payload
**File** `OrderController.php:172-176`
The `payment_method` check `in_array($locked->payment_method, [CARD, TICKET_RESTAURANT], true)` is correct today. But the test `PaymentConfirmCrossBranchTest:79` confirms `CASH_ON_DELIVERY` rejects. No test pins `MOBILE_PAY`, `LOYALTY`, or future enum additions. Add a paramterised regression.

#### P2-B — `ActionLog::create` failure mode
**File** `OrderController.php:223-236, :292-305`
Both ActionLog writes are wrapped in `try/catch \Throwable`. Audit trail loss is logged at WARNING — acceptable, but consider a counter metric (`actionlog.write_failed`) to surface trends. Currently invisible to dashboards.

#### P2-C — `KioskMachine` resolver — first()
**File** `OrderController.php:115-117`
`->first()` without `orderBy` may return any kiosk if a user owns multiple `KioskMachine` pivot rows. Cross-branch user scenario: if a kiosk user has two pivots in two branches (unlikely but the schema permits), the `$kioskMachine->branch_id` is non-deterministic. Add `->orderBy('id')` + assertion that only one pivot exists, or pivot by request header `X-Kiosk-Machine-Id`.

---

### P3 — Minor.

- `PaymentConfirmRequest.php:43` — `'amount_cents' => 'required|integer|min:1'` allows `1` cent payments. Add `max` cap to match `int(11)` boundary or business max.
- `OrderController.php:99-103` — `BypassAuditLogger::paymentBypassed` is invoked unconditionally before any validation. Cosmetic — but a log line per failed confirm attempt may noise the channel.

---

## §2 — Cross-validation

- **Past audit P0-08 (CLOSED → P1-A)** — verified fresh against current `routes/api.php:1113-1121`. Past finding was technically true but exploit not demonstrated; FormRequest gate is real. Conflict with past `block` verdict — current state is `heal`.
- **F-002 amount echo (CLOSED)** — corroborated by `PaymentConfirmCrossBranchTest:79,123` payloads that include `amount_cents`. Sentinel coverage strong.
- **TransientToken bypass (NEW P1-B)** — past audit did not surface this. Cross-reference with `OrderRequest.php:247-250` which DOES reject `TransientToken` for the `isKioskOrderToken()` helper. The asymmetry between `OrderRequest` and `PaymentConfirmRequest` is the bug.

---

## §3 — Proposed Playwright + Feature scenarios (5)

| # | Type | Scenario | Asserts |
|---|---|---|---|
| 1 | Playwright + Feature | Happy path : kiosk pays card via mocked TPE (POST `/api/frontend/order/{id}/payment-confirm`) | 200 + `orders.payment_status=PAID, transaction_id=X` + `ActionLog::where('action','Paiement carte confirmé (borne)')` |
| 2 | Feature | `amount_cents` off by 5 cents → 422 `error_code=AMOUNT_ECHO_MISMATCH` + `payment_status=UNPAID` preserved | response status 422 + JSON `error_code` exact + DB unchanged |
| 3 | Feature | Cross-branch confirm (kiosk in branchA, order in branchB) → 403, no mutation | response 403 + `Order::find($foreignOrder->id)->payment_status === UNPAID` |
| 4 | Feature | Idempotent same-tx replay on paid order → 200; different-tx → 409 | first call 200, second 200 (no mutation), third with different `transaction_id` 409 |
| 5 | Feature | TransientToken (session-auth without PAT) → 403 [P1-B regression sentinel] | `Auth::loginUsingId($kioskUser->id)` then POST without Sanctum → expect 403 not 200 |
| 6 (bonus) | Playwright (real, replace fake) | Stub `posTpe.js` to fire approved callback after Pay-tile click → assert ticket DOM `data-testid="ticket-printed"` visible AND `/api/frontend/order/*/payment-confirm` network response = 200 | rewrites `tests/e2e/05-pos-card.spec.js` removing the `test.fixme` escape hatch — seeds an item via API in `beforeEach`. |

---

## §4 — Verdict

**heal** (not `block`).

The card / TPE / payment-confirm backend flow is in strong shape: F-002 amount-echo gate, BranchScope on pending confirmations, pre-lock + in-lock branch checks, duplicate-transaction guard, idempotent same-tx replay, fiscal-alloc retry path. Sentinel and Feature coverage are thorough.

The remaining issues are:
1. One real **P0** — `05-pos-card.spec.js` fake-E2E (P0-13) — must be replaced or its test name corrected; the file misrepresents coverage.
2. Three **P1** defense-in-depth — route-level ability gap (architectural, no runtime exploit), TransientToken bypass (latent depending on auth surface), Throwable swallow on fiscal post-commit (depends on cron).
3. P2/P3 hardening — KioskMachine multi-pivot, ActionLog failure metrics, amount cap.

**Past audit `block` verdict is stale.** Past F-002 + P0-08 either closed or downgraded. Card payment path is V1-acceptable conditional on (a) rewriting the fake E2E and (b) confirming `foodking:fiscal:retry-alloc` cron is scheduled in prod.

---

## §5 — BRAIN drift signal

Reads against `PROJECT_BRAIN.md` §7 verification checklist :
- "F-002 amount echo gate" — **verified live** at `OrderController.php:137-152`. Add to closed register.
- "P0-08 abilities middleware" — BRAIN should record the FormRequest-over-middleware design choice (`routes/api.php:1102-1112`) and the residual P1 sentinel-test backlog.
- New entry needed : **TransientToken session bypass on PaymentConfirmRequest** — pending P1 fix, mirror OrderRequest pattern.
- New entry needed : **Throwable swallow on finalizePaidKioskOrder side-effect** — pending verification that `foodking:fiscal:retry-alloc` cron is in `app/Console/Kernel.php` schedule.
- 05-pos-card.spec.js fake E2E — pending rewrite ; current "card flow protected by Playwright" claim is **drift**.

---

**File** `reports/review/pos-parallel-2026-05-11/A11_card_payment_confirm.md`
**Verdict line** `heal` — backend strong, 1 P0 (fake E2E) + 3 P1 hardening remain. Past audit `block` is stale.
