# L3 — PAYMENT GATEWAY + FISCAL NF525 — Audit (2026-05-17)

**Auditor**: L3-payment-fiscal (read-only, anti-drift)
**Scope**: Payment Gateway implementations (Stripe + SenangPay + Cash + TPE) + Fiscal NF525 layer (HMAC chain + Z-report + sequence) + Sprint 1A-1D + 3A + P0-6 outputs.
**Working tree**: includes uncommitted P0-6 Stripe cents fix in `Stripe.php`.
**Reference invariants**: CLAUDE.md §8 (NF525 non-negotiable).

---

## 0. ACTIVATION MAP — discriminator for severity

Most payment gateways are gated OFF in V1. This radically changes severity.

| Path | Active in V1? | Source |
|---|---|---|
| `credit` (in-app balance) | ✅ ACTIVE | `config/payment.php:33-34` allowed_methods=['credit'] |
| `cash` (POS counter cash) | ✅ ACTIVE | counter-payment + split-payment flows |
| `card` (POS TPE manual) | ✅ ACTIVE | `pos_payment_method=CARD` accepted; PaymentTerminal entity present |
| `stripe` (online card) | ❌ GATED OFF | `config/payment.php:49-56` activation_guard.enabled=true, activation_gate_cleared=false |
| `senangpay` (MY gateway) | ❌ HALF-BUILT | only webhook class; no `extends PaymentAbstract` payment path |
| `paypal` | ❌ DEAD V1 | code present, not in pilot allow-list |
| `web_payment` public | ❌ GATED OFF | `config/payment.php:14-19` enabled=false |
| `payment.bypass` | ❌ DEV-ONLY | `forbidden_environments=[production,prod,live]` |

> **Implication**: P0-6 Stripe cents fix is **LATENT GUARDRAIL**, not an active revenue-loss bug. Same for missing SenangPay payment redirect.

---

## 1. PAYMENT GATEWAY IMPLEMENTATION — 60/100

### F-L3-001 — P0-6 Stripe cents fix is correct but covers a gated-off code path **[LATENT — HARDENING]**
- **File**: `app/Http/PaymentGateways/Gateways/Stripe.php:58`
- **Working-tree diff** vs HEAD: `(int) round((float) $order->total * 100)` replaces `(int) $order->total * 100` (operator-precedence truncation: €9.99 → 900 cents).
- **Status**: ✅ Pattern correct, matches sibling callsites at `OrderController:137` and `PaymentReconcileController:173` (both verified using same `(int) round((float) X * 100)` form).
- **Caveat**: Stripe path is gated off (`payment.stripe.activation_guard.enabled=true`, `pilot_restrict.allowed_methods=['credit']`). `PaymentService::assertPilotPaymentMethodAllowed()` throws ValidationException for any non-credit slug. **The bug was never active in V1 production**.
- **Recommendation**: keep the fix; commit it. Add CHANGELOG note framing it as latent fix.

### F-L3-002 — Stripe handleWebhook→Order bridge always misses `metadata.order_id` **[LATENT P1 if Stripe ever activates]**
- **File**: `Stripe.php:274-289` (webhook handler) vs `Stripe.php:57-62` (payment initiation)
- **Evidence**: `payment()` charges call at L57-62 does NOT include `metadata: ['order_id' => $order->id]`. `handleWebhook` at L273-277 reads `$charge->metadata->order_id` → always `null` → `CapturePaymentNotification` never created from webhook → success() relies on synchronous redirect path only.
- **Impact**: If Stripe gate clears, asynchronous webhook recovery (provider retry after merchant 5xx) cannot heal a missed charge → orphaned payments.
- **Comment in code (L268-272)** acknowledges the gap ("extend metadata in payment() in a future iteration").
- **Severity**: P1 if Stripe activates; HARDENING-BACKLOG today.

### F-L3-003 — Stripe currency default = 'USD' with no fallback safeguard **[LATENT P2]**
- **File**: `Stripe.php:41-48`
- **Evidence**: `$currencyCode = 'USD'`; only overridden if Settings::group('site')->get('site_default_currency') resolves to a row.
- **Impact**: French restaurant (Le Cayenne pilot) without seeded `site_default_currency` would charge in USD instead of EUR.
- **Severity**: P2 (latent, gated off today; pre-cutover sanity check needed).

### F-L3-004 — Stripe missing zero-decimal currency handling **[LATENT P2]**
- **File**: `Stripe.php:58`
- **Evidence**: `'amount' => (int) round((float) $order->total * 100)` is unconditional. Per Stripe docs, JPY/KRW/VND/HUF/ISK/TWD/UGX use raw units (no *100). Multiplying yen by 100 → 100x overcharge.
- **Impact**: V1 EUR-only target = no current risk, but Stripe Connect SaaS roadmap = potential future overcharge.
- **Severity**: P2 latent.

### F-L3-005 — SenangPay payment redirect MISSING (webhook-only implementation) **[LATENT]**
- **Evidence**: `grep "extends PaymentAbstract" app/` returns only Stripe + Paypal + Credit. `app/Http/PaymentGateways/Gateways/Senangpay.php` is webhook-only (no `payment()`, `success()`, `cancel()`, `fail()` methods). `app/Http/PaymentGateways/PaymentRequests/Senangpay.php:24-27` has empty `rules() return []`.
- **Impact**: SenangPay cannot be activated as a checkout option even if added to pilot allow-list — the webhook handler can record events but no payment redirect builds.
- **Frame**: HALF-BUILT, not a regression. SenangPay isn't a V1 target market (FR pilot = EUR + manual TPE).

### F-L3-006 — `PaymentRequests/*` directory contains 22 dead FormRequest stubs **[CODE HYGIENE]**
- **Evidence**: `ls app/Http/PaymentGateways/PaymentRequests/` lists 22 gateways (Bkash, Cashfree, Easypaisa, Flutterwave, Iyzico, Mercadopago, Midtrans, Mollie, Myfatoorah, Payfast, Paystack, Paytm, Pesapal, Phonepe, Razorpay, Skrill, Sslcommerz, Telr, TwoCheckout, plus Stripe/SenangPay/Credit/Paypal). Only 4 have corresponding `Gateways/` implementations; the rest are dead.
- **Severity**: P3 backlog cleanup, no functional impact.

### F-L3-007 — `Credit` gateway uses `rand()` for token generation **[P2 LATENT]**
- **File**: `Credit.php:33`: `$token = rand(111111111, 999999999)`
- **Impact**: ~9-digit token, predictable, no CSPRNG. Active path in V1.
- **Severity**: P2 (credit balance flow has a separate `Transaction.transaction_no` UNIQUE guard at `PaymentService::assertTransactionReferenceAvailable` L62-69, so collision is detected but token guessability remains).

---

## 2. NF525 HMAC CHAIN INTEGRITY — 94/100 ✅

### Verified-safe items
- **Chain hash function** (`AuditLogService::computeHash`, L237-243): `hash_hmac('sha256', prevHash||canonical, secret)`. Canonical JSON is sorted recursively (L335-352, `sortRecursive` L358-374). Stable across PHP versions.
- **Genesis prev_hash sentinel** (`fiscal.genesis_prev_hash`, default 64 zeros) — `ZReportService::verifyChain` L505-507 accepts either empty or genesis on first Z.
- **DB UNIQUE(branch_id, prev_hash)** index migration `2026_04_22_100000_add_unique_chain_index_to_audit_logs.php` confirmed present → INSERT-time fork prevention.
- **Cache lock** `audit_chain_b{n}` 10s TTL + 5s wait (`AuditLogService::CHAIN_LOCK_TTL/WAIT` L66-68), single-retry on UNIQUE collision (L185-189).
- **Per-branch secret override** via `FISCAL_AUDIT_SECRET_BRANCH_N` env (L272-277). Multi-tenant ready.
- **Branch-id required** for write (`AuditLogService::write` L93-98) — no silent global-chain poison.
- **DB triggers** (`audit_logs`): BEFORE UPDATE + BEFORE DELETE `SIGNAL SQLSTATE '45000'` (MySQL/MariaDB) + `RAISE(ABORT)` (SQLite) → migration `2026_04_22_000002_create_audit_logs_table.php:97-135`. Triggered in both env.
- **6-year retention guard** at migration `down()` L70-76: throws `RuntimeException` if `APP_ENV=production`.
- **Z chain validator** double-walk: `ZReportService::verifyChain` (L463-572) + `FiscalChainValidator::assertChainIntegrity` (L55-107) called before every `Z.open()` AND `Z.close()`.
- **z_reports DELETE trigger** at migration `2026_05_09_160000` — same SIGNAL pattern.
- **Cash + payment immutability**: migration `2026_05_10_010000_secure_fiscal_audit_trail_immutability.php` adds DELETE triggers on `cash_movements`, `cash_drawer_sessions`, `order_payments` + replaces cascadeOnDelete FKs with `restrictOnDelete()`.

### F-L3-008 — TRUNCATE bypasses MySQL triggers (documented, mitigated outside repo) **[OPERATIONAL P2]**
- Acknowledged in 3 migrations (audit_logs, z_reports, fiscal_audit_trail). Mitigation = revoke TRUNCATE GRANT on prod DB user (deploy doc, out of scope here). **No code-level fix possible** — accept as ops-policy.

### F-L3-009 — Production secret enforcement (`assertProductionSafe`) is duplicated in two services **[CODE HYGIENE P3]**
- `AuditLogService::assertProductionSafe` (L303-327) duplicated verbatim in `ZReportService::assertProductionSafe` (L702-726). 25 LOC each. Cleanup candidate.

---

## 3. FISCAL SEQUENCE MONOTONIC — 100/100 ✅

`FiscalSequenceService::next` (`app/Services/Fiscal/FiscalSequenceService.php`):
- `Cache::lock("fiscal_seq_b{branch}", 5)` TTL + `->block(3)` wait (L65-69).
- Inside lock: `DB::transaction(function () { Order::lockForUpdate()->max('fiscal_sequence_no') + 1 })` (L88-93) — **triple defense**: cache lock + DB row-level lock + DB UNIQUE constraint (`orders_branch_fiscal_seq_unique` per comment L18).
- `branchId <= 0` rejected with `InvalidArgumentException` (L59-63).
- Lock release wrapped in try/catch (L98-102) — never masks caller exception.
- Tests: `tests/Feature/Fiscal/OrderFiscalSequenceSchemaTest.php` + `FiscalAllocOrphanRetryTest.php` present.

**Verdict**: CLAUDE.md §8 "Cache::lock 5s + DB FOR UPDATE = triple défense" claim is **VERIFIED**.

---

## 4. WEBHOOK IDEMPOTENCY — 78/100

### Verified-safe
- **Schema** `webhook_events` (`2026_05_09_120000_create_webhook_events_table.php`): UNIQUE (provider, webhook_id) `uk_webhook_provider_id` L83. Status enum, attempts counter, payload JSON, signature column, dead-letter index `idx_pending_received`.
- **Stripe handler** (`Stripe::handleWebhook` L184-315):
  - Stripe signature verification via SDK `Webhook::constructEvent` (L201) → 400 on invalid (L208-211).
  - Missing secret → 500 misconfigured (L186-195) — NEVER silently bypassed.
  - `WebhookEvent::firstOrCreate` (L234-246) → DB UNIQUE atomicity floor. `wasRecentlyCreated` false → `duplicate_ignored` 200 (L248-255).
  - DB transaction wraps capture-bridge (L258-297). Failure → markFailed + 500 to retry.
- **SenangPay handler** (`Senangpay::webhook` L50-186):
  - HMAC-SHA256 over canonical `status_id|order_id|transaction_id|msg` (L107) + `hash_equals` (L110).
  - Missing secret → 500 misconfigured (L96-104).
  - Same firstOrCreate + duplicate_ignored pattern.
- **Tests**: `tests/Feature/Webhooks/StripeWebhookIdempotencyTest.php` 6 test methods (first/duplicate/independent/invalid-sig/missing-secret/non-capture). Forges valid `t=...,v1=hmac(timestamp.payload)` signatures locally.

### F-L3-010 — `StripeCentsCastTest` is a regex sentinel, not a pipeline assertion **[TEST MATURITY]**
- **File**: `tests/Unit/Payment/StripeCentsCastTest.php:54-74`
- **Evidence**: `test_stripe_gateway_uses_round_cast_pattern_at_callsite` does `file_get_contents()` + regex match against `Stripe.php`. No `Order(total=9.99)` is constructed; the Stripe SDK is never invoked. A reformat (e.g. moving the `amount` onto its own line) could pass the regex while reintroducing the bug elsewhere.
- **Recommendation**: complement with a `Stripe\StripeClient` fake that captures the `amount` arg.

### F-L3-011 — No SenangPay webhook test in `tests/Feature/Webhooks/` **[TEST GAP]**
- Only `StripeWebhookIdempotencyTest.php` exists. SenangPay handler (significantly more parsing risk: form-encoded vs JSON, canonical hash assembly) has zero feature-level coverage. Per advisor: "SenangPay is where the gap usually hides."
- Severity: P1 if SenangPay ever ships; P2 latent.

### F-L3-012 — No dead-letter consumer for `webhook_events.attempts` **[OBSERVABILITY GAP]**
- **Evidence**: `WebhookEvent::markFailed` (L108-115) increments `attempts` but `grep -rn "webhook_events" app/Services/` returns no scheduler/cron/queue worker that re-drives `status=failed`. `idx_pending_received` index exists but is unused.
- **Impact**: 5xx outages → events sit FAILED forever until manual SQL intervention.

---

## 5. SPLIT PAYMENT MATH (CENTS PRECISION) — 90/100 ✅

### Verified-safe (`SplitPaymentService::validateBreakdown` L50-136)
- Each tranche amount converted via `(int) round($amount * 100)` (L110, L113, L114) — same pattern as sibling P0-6 fix.
- Per-tranche cash sufficiency check: `(int) round($tendered * 100) < (int) round($amount * 100)` (L103) — cent-exact.
- Sum compared to `serverTotalCents = (int) round($orderTotal * 100)` (L113) — no per-tranche drift.
- Overpay tolerance: `1.00€` (L35) configurable.
- Max tranches gate (default 12) → DOS protection.

### F-L3-013 — `PaymentService::confirmCounterPayment` float `<` compare without cent normalization **[ACTIVE P1]**
- **File**: `app/Services/PaymentService.php:172`
- **Evidence**:
  ```php
  if ($mode === PosPaymentMethod::CASH && $received !== null
      && (float) $received < (float) $locked->total) { … }
  ```
- **Impact**: Same bug class as the Stripe cents truncation, but for the **active** counter-payment flow. `9.99` vs `9.99` float compare is usually OK; `10.0 - 0.1 - 0.1 - 0.1` situations on the operator side could miss by 1 ULP. Edge case but ACTIVE V1.
- **Recommendation**: normalize both sides to `(int) round(* * 100)` before compare. Match the SplitPaymentService L103 pattern.

### Sprint 1B cash trail wiring (`SplitPaymentService::persistTranches` L148-250)
- Fail-fast guard L173-185: cash tranche without OPEN session → throw `CashDrawerSessionNotOpenException` → parent transaction rollback (L187).
- `CashDrawerService::recordMovement(..., strict: true)` inside parent DB::transaction (L233-243) → if movement fails mid-flow, order is rolled back. NF525 cash trail desync **prevented**. ✅
- Sibling path `PaymentService::recordCashOrderMovement` (L261-329) with `strict=true` for POS direct CASH single-tender. Also called from inside `DB::transaction` in `confirmCounterPayment` (L230-233). ✅

---

## 6. CASH TRAIL (Sprint 1B) — 86/100

### Verified
- Direct POS CASH + split CASH tranches BOTH wire to `CashMovement` write inside the parent DB::transaction (L233-243 + L230-233 of PaymentService). Wave Z gap closed.
- `cash_movements` immutability via DELETE trigger (`2026_05_10_010000` migration L107-117).
- Variance gate (Sprint 1D) at `CashDrawerService::reconcileSession` L241-277 — threshold check + permission `cash.reconcile.variance.override` + reason text required → `CashVarianceRequiresApprovalException` (HTTP 422).

### F-L3-014 — `CashDrawerService::recordMovement` not transaction-wrapped, no lockForUpdate on session probe **[MITIGATED-IN-PRACTICE P2]**
- **File**: `CashDrawerService.php:326-410`
- **Evidence**: `recordMovement` reads `$session = CashDrawerSession::query()->find($sessionId)` (L370) and checks `status === STATUS_OPEN` (L380) WITHOUT `lockForUpdate()` AND WITHOUT wrapping the read+insert in `DB::transaction()`.
- **TOCTOU**: between L380 (status check) and L389 (`CashMovement::create`), a sibling request could close the session.
- **Mitigation**: ALL current callers (SplitPaymentService L234, PaymentService::recordCashOrderMovement L308) invoke `recordMovement` from inside their own `DB::transaction()`, which gives the row a read-consistency view BUT not a row lock. The DELETE trigger prevents row deletion mid-flow, not state mutation.
- **Severity**: P2 — the API contract is fragile if a future caller misses the parent tx.

### F-L3-015 — `CashDrawerService::writeAuditLog` swallows exceptions silently **[ACCEPTED]**
- **File**: `CashDrawerService.php:474-488`
- **Evidence**: Audit-log failure → `Log::warning` + continue. Justified in comment L475-479 (DB-layer immutability is SSOT, audit row is belt-and-suspenders). NF525 fiscal evidence remains in `cash_movements` rows protected by the DELETE trigger.

---

## 7. TPE DRIVER READINESS (Sprint 1C) — 35/100

### Verified
- `payment_terminals` migration `2026_05_16_120000`: branch_id FK + fee_percent(5,3) + fee_fixed(8,2) + serial_number + status (1=ACTIVE, 5=ARCHIVED) + gateway_type enum {stripe, senangpay, ingenico, verifone, manual}.
- `order_payments.terminal_id` migration `2026_05_16_120001` adds nullable FK with `nullOnDelete` (preserves historical traceability if TPE deleted).
- Model `PaymentTerminal` with BranchScope global scope (L66-70), proper decimal casts, scopeActive.
- Admin CRUD `PaymentTerminalController` + `PaymentTerminalRequest` + `PaymentTerminalResource`.
- Z-report fee breakdown `ZReportCashEnrichmentService::aggregateByTerminal` L151-222 — read-only decorator, NOT signed (preserves HMAC chain).

### F-L3-016 — Sprint 1C is **metadata-only**; no actual TPE hardware driver **[BY-DESIGN — FRAMING REQUIRED]**
- **Evidence**: No driver class, no protocol handler, no Ingenico/Verifone SDK integration. The `PaymentTerminal.gateway_type` is informational only. `bypass.simulated_response` (config/payment.php:82-93) is the dev-time stand-in.
- **Impact**: V1 ships with manual TPE entry (cashier reads amount approved on physical TPE → enters into POS). No automatic amount-echo from TPE to POS for `manual/ingenico/verifone` gateway_type. The `AMOUNT_ECHO_MISMATCH` gate at `OrderController:137-152` (kiosk path) is the only automated echo verification, and only on kiosk.
- **Frame**: Backlog item, not regression. Sprint 1C is the schema foundation for a future driver.

### F-L3-017 — `PaymentTerminal.fee_percent` integer cast in JS could lose precision (DECIMAL 5,3 = 1.500%) **[LATENT P3]**
- Casts: `'fee_percent' => 'decimal:3'` in model L62 — backend OK. Frontend serializer needs verification (out of L3 scope).

---

## 8. TEST MATURITY — 78/100

### Strong fiscal coverage (`tests/Feature/Fiscal/`)
- `AuditLogHashChainTest` (5 tests): tampering detection, forged prev_hash detection, payload key-order invariance, mandatory secret.
- `AuditLogImmutabilityTest` (5 tests): insert OK, update/delete blocked via Eloquent AND raw SQL.
- `AuditLogConcurrencyTest` (4 tests): UNIQUE chain index rejects fork, retry produces consistent chain, sequential branches independent, cache lock serialises.
- `AuditLogBranchRequiredTest`, `FiscalRateLimitTest`, `RefundPostZTest`, `RefundPreZTest`, `VoidPreZTest`, `XReportTest`, `ZReportTerminalBreakdownTest`, `ZReportCloseTest`, `ZReportControllerTest`, `ZReportAggregateFilterTest`, `FiscalSealingHmacTest`, `FiscalArchiveScheduledTest`, `FiscalArchiveTtlTest`, `FiscalArchiveMemoryBoundedTest`, `OrderFiscalSequenceSchemaTest`, `FiscalAllocOrphanRetryTest`, `FiscalObservabilityTest`, `FiscalPermissionTest`, `FiscalCashAtCounterLifecycleTest`, `SealedOrderMutationGuardTest`, `FiscalHardeningMinorTest`, `TaxTypeMisconfigDetectionTest`, `PosOrderBL2AuditCallSitesTest`.

### Gaps (already detailed F-L3-010..012)
- StripeCentsCastTest = regex-only, not Stripe SDK fake.
- SenangPay webhook test absent.
- No real concurrent fiscal_sequence_no allocation test (parallel POS checkouts) — only `AuditLogConcurrencyTest` exists for audit chain. The `Cache::lock + lockForUpdate` triple defense in `FiscalSequenceService` is unit-trusted but not adversarially tested.

### F-L3-018 — No HMAC-key-rotation test **[P2]**
- `assertProductionSafe` rejects dev sentinels, but no test asserts that a per-branch secret rotation produces a re-verifiable chain across the boundary. NF525 7-year retention will encounter at least one key rotation.

---

## 9. CROSS-REFERENCES (don't re-flag, already closed)

Reviewed `reports/audit/ultra-review-2026-05-16/HEAL_FINAL_VERDICT.md` and `reports/audit/wave-z-*` (project memory) — already closed and **not re-raised here**:
- P0-FIX-1/2 Z aggregate `withTrashed()` post-iter6 archive workflow (`ZReportService.php:338`).
- P0-FIX-3 z_reports DELETE trigger (`2026_05_09_160000` migration).
- P0-FIX-4 cash + payment immutability (`2026_05_10_010000` migration).
- P11-FZH FiscalChainValidator cycle-break via lazy resolution.
- Sprint 1B POS direct cash + split tranche → CashMovement wiring.
- Sprint 1C TPE entity + fee tracking.
- Sprint 1D variance gate + audit binding.
- Sprint 3A Stripe + SenangPay webhook idempotency (signature + firstOrCreate + duplicate_ignored).

---

## 10. LAYER SCORE — 79/100

| Dimension | Score | Notes |
|---|---|---|
| 1. Gateway implementation | 60 | F-L3-001..007: latent gaps mostly on gated paths |
| 2. NF525 HMAC chain integrity | 94 | rock-solid; only ops TRUNCATE caveat |
| 3. Fiscal sequence monotonic | 100 | triple defense verified |
| 4. Webhook idempotency | 78 | Stripe excellent; SenangPay untested; no dead-letter |
| 5. Split payment cents math | 90 | Sprint 1B clean; F-L3-013 float compare elsewhere |
| 6. Cash trail | 86 | wiring closed; recordMovement TOCTOU latent |
| 7. TPE driver | 35 | metadata-only by design; no hardware |
| 8. Test maturity | 78 | strong fiscal coverage; SenangPay/Stripe SDK gaps |

---

## 11. FINDINGS TABLE (severity, frame, file:line)

| ID | Severity | Frame | File:Line | One-line |
|---|---|---|---|---|
| F-L3-001 | P0 | LATENT (gated) | Stripe.php:58 | P0-6 cents fix correct + uncommitted |
| F-L3-002 | P1 | LATENT (gated) | Stripe.php:274 | Webhook→Order bridge missing metadata.order_id |
| F-L3-003 | P2 | LATENT (gated) | Stripe.php:41 | USD default if site currency unseeded |
| F-L3-004 | P2 | LATENT (gated) | Stripe.php:58 | Zero-decimal currency not handled |
| F-L3-005 | P1 | HALF-BUILT | Senangpay.php (missing) | No payment() redirect implementation |
| F-L3-006 | P3 | HYGIENE | PaymentRequests/* | 22 dead FormRequest stubs |
| F-L3-007 | P2 | ACTIVE | Credit.php:33 | `rand()` not CSPRNG for token |
| F-L3-008 | P2 | OPS | (3 migrations) | TRUNCATE bypass — handled outside repo |
| F-L3-009 | P3 | HYGIENE | AuditLogService:303 + ZReportService:702 | duplicate assertProductionSafe |
| F-L3-010 | P2 | TEST MATURITY | StripeCentsCastTest.php:54 | regex-only sentinel, no SDK fake |
| F-L3-011 | P1 | TEST GAP | (missing) | No SenangPay webhook feature test |
| F-L3-012 | P1 | OBSERVABILITY | (cron missing) | No dead-letter consumer for webhook_events |
| F-L3-013 | P1 | **ACTIVE V1** | PaymentService.php:172 | Cash counter `(float) <` compare without cents |
| F-L3-014 | P2 | MITIGATED | CashDrawerService.php:326 | recordMovement not tx-wrapped, no lockForUpdate |
| F-L3-015 | INFO | ACCEPTED | CashDrawerService.php:474 | audit_log write swallowed by design |
| F-L3-016 | INFO | BY-DESIGN | (no driver) | Sprint 1C metadata-only, no hardware |
| F-L3-017 | P3 | LATENT | PaymentTerminal.php:62 | fee_percent precision needs frontend check |
| F-L3-018 | P2 | TEST GAP | (missing) | No HMAC-key-rotation test |

**Active-V1 P1 count**: 1 (F-L3-013, the cash counter float compare).
**Gated/Latent P0+P1**: 4 (Stripe cents, Stripe metadata bridge, SenangPay redirect, SenangPay test gap).
**Hardening/Observability**: 6.

---

## 12. RECOMMENDATIONS — priority order

1. **F-L3-013 (P1 ACTIVE)** — normalize cash counter compare to cents. 1-line fix.
2. **F-L3-012 (P1 OBS)** — add `WebhookEventsRedriveCommand` artisan command for cron-driven dead-letter retries.
3. **F-L3-001 (P0 LATENT)** — commit the working-tree Stripe cents fix. Add CHANGELOG entry.
4. **F-L3-002 (P1 LATENT)** — add `metadata: ['order_id' => $order->id]` to `Stripe::payment()` Charges create call before activation gate clears.
5. **F-L3-011 (P1 LATENT)** — add SenangPay feature webhook test (mirror StripeWebhookIdempotencyTest shape).
6. **F-L3-010 (P2)** — convert StripeCentsCastTest to SDK-fake assertion.
7. **F-L3-018 (P2)** — add HMAC key rotation test.
8. **F-L3-014 (P2)** — wrap `recordMovement` in DB::transaction + lockForUpdate on session probe.

---

## 13. NF525 INVARIANT VERIFICATION (CLAUDE.md §8 checklist)

| Invariant | Status | Evidence |
|---|---|---|
| Pricing SSOT (composition_snapshot frozen) | (out of L3 scope, see L1) | — |
| Fiscal seq monotonic per branch, gap-free | ✅ | `FiscalSequenceService` triple defense |
| Cache::lock 5s + DB FOR UPDATE | ✅ | L42-43 + L88-91 |
| Alloc fail → flag + retry, no silent gap | ✅ | `fiscal_alloc_error_at` migration + `warnOnOrphanedPaidOrders` |
| audit_logs HMAC SHA-256 chain | ✅ | `AuditLogService::computeHash` |
| z_reports HMAC chain-signed | ✅ | `ZReportService::sign` + `verifyChain` |
| DB BEFORE DELETE trigger SIGNAL 45000 | ✅ | 3 migrations cover audit_logs, z_reports, cash/payment |
| TRUNCATE bypass mitigated via GRANT | ⚠️ ops-policy | Out of repo scope |
| 6y retention enforced | ✅ | `down()` blocks production rollback |

---

**End L3 report.**
