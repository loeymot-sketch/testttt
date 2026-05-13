# A15 — Webhook Events + SenangPay + Stripe Parity

**Agent** : A15
**Branch / HEAD** : `feature/mobile-app-le-cayenne-2026-05-10` @ `a220b9bd8`
**Scope** : `app/Models/WebhookEvent.php`, migration `2026_05_09_120000_create_webhook_events_table.php`, `app/Http/PaymentGateways/Gateways/{Senangpay,Stripe}.php`, `app/Http/PaymentGateways/Routes/senangpay.php`, `tests/Feature/Webhooks/*`, `app/Providers/RouteServiceProvider.php`.
**Method** : read-only, file:line evidence, fresh re-verification of P0-11 from `reports/review/pos-ultra-audit-2026-05-09/99_VERDICT.md` and BRAIN §7 row 5.

---

## 1. Past audit verification — P0-11

**Past claim** (`reports/review/pos-ultra-audit-2026-05-09/...` + BRAIN line 408) :

> `WebhookEvent` model orphan dead code (no handler writes to it), SenangPay Gateway class missing → `/senangpay-webhook/` returns 500. BRAIN §7 row 5 "webhook_events unifié ✅".

### Verification verdict (2026-05-11)

| Sub-claim | Status @ a220b9bd8 | Evidence |
| --- | --- | --- |
| SenangPay Gateway class missing → 500 | **FIXED** | `app/Http/PaymentGateways/Gateways/Senangpay.php:31-46` returns 501 stub + logs to `fiscal` channel. Confirmed by `tests/Feature/Webhooks/SenangPayStubResponseTest.php:38` asserting `501`. |
| Route binding | **OK** | `app/Http/PaymentGateways/Routes/senangpay.php:18` binds `[Senangpay::class, 'webhook']` to `/payment/senangpay-webhook/`. Loaded by `app/Providers/RouteServiceProvider.php:152-170` via `scandir`. |
| `WebhookEvent` model orphan (no handler writes to it) | **STILL TRUE** | `grep -rn "WebhookEvent::" app/` returns only the docblock skeleton in `app/Models/WebhookEvent.php:18-26` and **zero** production callers. Only test files reference `WebhookEvent::firstOrCreate/create` (`tests/Feature/Webhooks/WebhookEventIdempotencyTest.php`). |
| BRAIN §7 row 5 "webhook_events unifié ✅" | **MISLEADING** | Schema + UNIQUE + model exist (line 612 of BRAIN). But "unifié" implies both providers route through it. **Neither SenangPay nor Stripe handler writes to `webhook_events`** in production. |

**Conclusion** : 500 leak is closed (P0-11 SenangPay sub-claim resolved via 501 stub). The "unified ledger" infrastructure is still **dead code in production** — model + migration + test suite exist with zero call-sites. The BRAIN green ✅ on row 5 misrepresents the state.

---

## 2. Defects found (fresh)

### P0 (none new — past P0-11 closed as 500 leak; the underlying "unified ledger" gap is now reclassified P1)

### P1

**P1-A15-01 — `webhook_events` is production-orphan: model + table + indexes + UNIQUE constraint exist, zero handler writes to it.**
- Evidence : `app/Models/WebhookEvent.php:18-46` docblock prescribes a `firstOrCreate` pattern; no production file in `app/` calls `WebhookEvent::firstOrCreate` or `WebhookEvent::create`. Only tests do (`tests/Feature/Webhooks/WebhookEventIdempotencyTest.php:27,53,63,74,82,95,113,139,157,175`). The SenangPay stub `app/Http/PaymentGateways/Gateways/Senangpay.php:33-46` logs to `fiscal` channel only — never touches `webhook_events`.
- Impact : The infrastructure is honest but unused. If SenangPay is ever wired up in V1.x without re-reading the docblock, the developer can re-introduce the duplicate-processing bug the table was meant to prevent.
- Risk : MEDIUM — feature pending, but contract drift is invisible to future implementers.

**P1-A15-02 — No Stripe webhook handler exists.** Past audit + BRAIN line 36/568 list "Stripe webhook idempotency (parité SenangPay iter11)" as backlog. `Stripe.php` Gateway only implements `payment/success/fail/cancel` redirect-flow (`app/Http/PaymentGateways/Gateways/Stripe.php:34-138`). No `webhook()` method, no `payment_intent.succeeded` / `charge.succeeded` event handler, no `Stripe-Signature` HMAC verification. Stripe relies on legacy `capture_payment_notifications` table (`Stripe.php:54-63`) which the migration docblock (`2026_05_09_120000_create_webhook_events_table.php:32-34`) acknowledges as legacy and "left untouched for backward compat".
- Impact : V1 cannot reconcile out-of-band Stripe charges (refunds, disputes, async 3DS confirmations). Activation guard (`tests/Feature/Payment/StripeActivationGuardTest.php`) currently blocks stripe at `payment.store` route, so production exposure = 0 today, but blocker for activation.
- Risk : HIGH the day Stripe activation gate clears.

**P1-A15-03 — `WebhookEvent` model lacks BranchScope exclusion test.** Docblock (`WebhookEvent.php:42-46`) explains the design choice ("WebhookEvent is intentionally global (no BranchScope) because providers don't carry tenant context"). Test suite never **asserts** the model has no BranchScope, so a refactor in another agent's branch could quietly add a global scope and break webhook ingestion. No sentinel.
- Impact : Silent regression risk on a multi-tenant invariant.
- Risk : LOW today, MEDIUM under churn.

### P2

**P2-A15-04 — SenangPay stub does not verify HMAC signature even though `signature` column exists in `webhook_events` (`migration:57`).** Acceptable for a 501 stub, but the test suite asserts only HTTP status + log channel — there's no test pinning the future invariant `if signature invalid → 401`. Future implementer can ship without HMAC check.
- File : `app/Http/PaymentGateways/Gateways/Senangpay.php:33-46` + `tests/Feature/Webhooks/SenangPayStubResponseTest.php` (no signature test).

**P2-A15-05 — Stub responds 501 to **any** request body shape, including hostile.** No payload-size guard, no method-allowlist beyond `Route::match(['get','post'])`. Senangpay route file `app/Http/PaymentGateways/Routes/senangpay.php:17` applies only `installed` middleware (no rate-limit, no signature pre-filter). A bot probing the endpoint can spam the `fiscal` log channel (logger writes payload size + IP per call : `Senangpay.php:35-40`).
- Impact : Disk fill / log noise. Low severity but the `fiscal` channel is reserved for NF525 audit material (CLAUDE.md §8) — polluting it with bot scans is undesirable.

**P2-A15-06 — `capture_payment_notifications` legacy idempotency duplicates the role of `webhook_events`.** Migration docblock (`2026_05_09_120000_create_webhook_events_table.php:32-34`) acknowledges this : "Legacy table is left untouched for backward compat; future migration can deprecate it." Two parallel idempotency stores = drift risk. Stripe still uses legacy (`Stripe.php:54-63`).

**P2-A15-07 — No end-to-end HTTP Feature test covers the full SenangPay route stack (middleware `installed` + CSRF + form-data parsing + 501 response).** Existing tests : `SenangPayStubResponseTest.php:30-44` posts to `/payment/senangpay-webhook/` — this is good. But it relies on `RefreshDatabase` and the route file is auto-loaded by `RouteServiceProvider::mapWebRoutes` **only if `storage_path('installed')` exists** (`RouteServiceProvider.php:154`). The test passes because `installed` flag is typically present in test env, but the dependency is implicit and brittle — if a CI lane wipes `storage/installed`, the route returns 404 and the test fails noisily. **Verified live by reading the test**: no setUp creates `storage/installed`. (`StripeActivationGuardTest.php:74-82` does — instructive precedent.)

### P3

**P3-A15-08 — `webhook_events.signature` column width 512 is undersized for some providers** (e.g. Stripe `Stripe-Signature` header concatenates multiple HMACs + timestamps, can exceed 512 chars in v3+). Currently no caller writes to this column so the limit is theoretical, but future implementers should bump to TEXT.
- File : `migration:57`.

**P3-A15-09 — `WebhookEvent::markFailed` truncates `error_message` at 65535 chars via `mb_substr` (`WebhookEvent.php:112`) but the column is `text` (`migration:71`), which on MySQL holds 65535 *bytes* not chars. UTF-8 multi-byte chars could overflow. Defensive but slightly off.**

**P3-A15-10 — `PaymentRequests/Senangpay.php` FormRequest validation is empty** (`PaymentRequests/Senangpay.php:24-27` returns `[]`). Stripe's equivalent (`PaymentRequests/Stripe.php:26-28`) requires `stripeToken`. When SenangPay implementation lands, a request validation FormRequest is missing — silent acceptance of any payload shape. Today moot (501 stub), but a sentinel test would help.

---

## 3. Proposed Feature scenarios (3-5)

1. **`SenangPayWebhookIdempotencyHttpTest`** — POST `/payment/senangpay-webhook/` twice with identical `txn_id`, assert: (a) first call processes order payment (when impl exists), (b) second call returns 200 with `idempotent=true`, (c) `webhook_events` has exactly 1 row keyed on `(senangpay, $txn_id)`. Today this test should be marked `@skip("stub-501")` and unblock when impl lands.

2. **`SenangPayWebhookSignatureVerificationTest`** — POST with valid HMAC → 200 ; POST with bad HMAC → 401 + `webhook_events.status = 'failed'` row inserted with `signature` captured for forensics. Pins the security invariant before impl exists.

3. **`StripeWebhookHandlerExistsTest`** — Assert route `/payment/stripe-webhook/` (or whatever path is chosen) is registered + handler class `Stripe::webhook()` exists OR explicit `markTestSkipped("V1.x backlog")`. Mirror the `SenangPayStubResponseTest::test_senangpay_class_exists_and_is_invokable` pattern.

4. **`WebhookEventBranchScopeExclusionTest`** — Create a `WebhookEvent`, switch tenant context to a different branch, query `WebhookEvent::all()` — assert the row IS visible (no BranchScope applied). Pins the "intentionally global" docblock contract.

5. **`SenangPayWebhookProductionOrphanSentinelTest`** — Until SenangPay impl ships, assert via reflection or static grep that **no production file in `app/` writes to `WebhookEvent`**, with a fail-mode that prints "Implementation landed — replace this sentinel with real flow test". Pattern : `RegressionsTest` style. Prevents partial wiring drift.

---

## 4. Verdict

| Past P0-11 sub-claim | Verdict @ a220b9bd8 |
| --- | --- |
| SenangPay route → 500 | **CLOSED** (501 stub, evidence `Senangpay.php:42-46` + `SenangPayStubResponseTest.php:38`). |
| `WebhookEvent` model orphan | **STILL TRUE** — production zero writes ; tests-only. Reclassified **P1-A15-01**. |
| BRAIN §7 row 5 "webhook_events unifié ✅" | **MISLEADING** — schema exists but unified ingestion doesn't. Recommend BRAIN edit to ⚠️ "infra ready, ingestion pending V1.x". |

**Stripe webhook parity** : explicitly absent (P1-A15-02). Activation guard mitigates exposure today.

**Net audit confidence** : the iter11/iter15 work landed a real fix for the 500 leak and well-documented test coverage on the model. The "unified ledger" framing in BRAIN/migration docblock overstates production reality — it's a contract waiting for an implementation. No new P0 found.

**Recommended actions** (owner gate) :
1. Edit BRAIN §7 row 5 to ⚠️ pending.
2. Add P1-A15-01 sentinel test (scenario #5).
3. Add P1-A15-02 placeholder Stripe webhook scaffold (skip-mark until activation).
4. Bump `signature` to TEXT (P3-A15-08) before any real write hits the table.

Word count : ~1280.
