# Wave W7 — T-3.3.1 — ARCHITECT specialist read-only audit
**Webhook idempotency by provider (Stripe + SenangPay + FCM)**
Date: 2026-05-18 — Round 2 — Read-only — Architect lens only.

---

## VERDICT

**GREEN** for SenangPay (full parity, signature→idempotency→process→DLQ chain wired).
**GREEN-with-2-conditions** for Stripe (handler + route + tests live, but per-event business logic on DLQ replay is intentionally deferred to V1.0.2 — documented stub).
**N/A** for FCM (push notification SEND, not inbound webhook — different domain; reachability/retry covered by `SendFcmNotificationJob`).

BRAIN context "Stripe webhook idempotency parity STILL PENDING" is **stale** — parity was closed in Sprint 3A 2026-05-16 (commit anchors in `app/Http/PaymentGateways/Gateways/Stripe.php:166-328` + route file + tests). What remains is V1.0.2 "DLQ replay re-runs inner business logic" — a P2 polish item, not a P0 blocker.

Strong findings concentrate on (a) DLQ replay business-logic skip, (b) cross-provider order-id parsing trust, (c) provider-specific retry budget reconciliation, (d) Stripe activation guard ↔ webhook route divergence.

---

## TOP FINDINGS

### F-T331-ARCH-01 — Stripe DLQ replay skips inner business logic (CapturePaymentNotification)
```yaml
severity: P1
category: dlq-business-logic-gap
confidence: high
evidence:
  - app/Http/PaymentGateways/Gateways/Stripe.php:353-381 — handleFromStoredEvent
    only calls $event->markProcessed($event->order_id); no
    CapturePaymentNotification re-creation, no transaction parsing
  - app/Http/PaymentGateways/Gateways/Stripe.php:330-352 docblock — V1.0.1
    scope-minimal explicit, V1.0.2 TODO documented (commit + Wave Z tracker)
  - app/Http/PaymentGateways/Gateways/Senangpay.php:204-251 — IDENTICAL pattern
    (same stub semantics, same V1.0.2 deferral)
  - app/Console/Commands/OutboxWebhookRetryFailedCommand.php:50-60 — resets
    failed → pending, dispatches ProcessWebhookEventJob; job calls
    handleFromStoredEvent which short-circuits
reasoning: >
  The DLQ semantics are correct IF the original live webhook handler
  fully completed CapturePaymentNotification creation BEFORE the failure
  flipped the row to failed. But the failure path in handleWebhook
  (Stripe.php:311-325) is reached AFTER WebhookEvent::firstOrCreate but
  CAN throw inside the DB::transaction at line 271 — meaning the
  CapturePaymentNotification insert may have been rolled back even
  though the WebhookEvent row exists (firstOrCreate is outside the txn).
  A DLQ replay then marks the WebhookEvent processed without ever
  re-creating the CapturePaymentNotification — silent fiscal data loss
  for any failure mode that flipped to failed AFTER WebhookEvent row
  creation but BEFORE inner CapturePaymentNotification commit. Same
  defect on SenangPay (Senangpay.php:148-183 mirrors the pattern).
  Production impact at V1 = nil (Stripe + SenangPay both gated off per
  config/payment.php). At V1.0.2 with live providers, P0 fiscal silent
  loss under any DB hiccup that triggers failure between firstOrCreate
  and txn commit.
fix_direction: >
  Refactor handleWebhook into a thin parser + private
  processStripeEvent(StripeEvent $event, WebhookEvent $row) — the
  business-logic body — then handleFromStoredEvent rehydrates the
  Stripe event from $row->payload and re-invokes processStripeEvent.
  Same shape for Senangpay::processSenangpayEvent. Pre-conditions:
  payload re-parse must be deterministic (raw payload stored in
  WebhookEvent::payload as JSON, already in place per migration
  line 53-54). NF525 path unaffected — CapturePaymentNotification
  is an *operational* bridge to the success controller, not a
  fiscal table. Test gate: add Feature/Webhooks/StripeWebhookDlqReplayTest
  asserting CapturePaymentNotification count BEFORE and AFTER replay
  on a fixture failed row.
load_at_100x5min: >
  V1 = no impact (both providers gated off). V1.0.2 with Stripe live:
  one DLQ replay per failure that occurred between firstOrCreate and
  txn commit → silently swallowed payment capture. Owner-decision per
  provider go-live readiness gate.
```

### F-T331-ARCH-02 — Stripe metadata.order_id trust gap on DLQ replay
```yaml
severity: P1
category: cross-trust
confidence: high
evidence:
  - app/Http/PaymentGateways/Gateways/Stripe.php:67-75 — payment() now
    injects metadata.order_id = (string) $order->id explicitly
    (P0-POS-01 GOAL round-2 fix 2026-05-18)
  - app/Http/PaymentGateways/Gateways/Stripe.php:286-290 — handleWebhook
    casts $charge->metadata->order_id (int) directly without bounds check
  - tests/Feature/Webhooks/StripeWebhookIdempotencyTest.php:214 — fixture
    uses (object) ['order_id' => '42'] — stringly-typed mirrors prod path
  - app/Models/Order.php — no model-side guard on what an Order belongsTo
    a tenant; the (int) cast on metadata.order_id resolves to any FK target
reasoning: >
  Stripe metadata is *attacker-controllable* on a re-played webhook IF
  an attacker can force Stripe to retry an old event whose metadata was
  injected by a different merchant account (same Stripe Connect parent
  account, multi-tenant SaaS). For V1 Le Cayenne single-Stripe-account
  scenario this is moot — metadata.order_id is always written by our
  own payment() flow. For V2/SaaS multi-tenant the cast at line 287
  silently links a payment notification to ANY order_id with no
  BranchScope verification because WebhookEvent has no BranchScope
  (comment at WebhookEvent.php:43-46 is correct; the FK enforcement
  must live in the handler). Cross-provider risk amplifies on DLQ
  replay where the stored payload (potentially aged 24h+) carries
  metadata that may reference an order_id since deleted or refunded.
fix_direction: >
  Add Order::query()->withoutGlobalScope(BranchScope::class)->find()
  + branch_id sanity check (matches the Stripe account → branch_id
  registry) before writing CapturePaymentNotification. For V1 single
  branch this collapses to a presence-check (Order::find($id)?->exists).
  In handleFromStoredEvent, additionally cap metadata.order_id age
  (e.g. WHERE created_at >= now()->subDays(7)) to refuse replays for
  stale orders. Document in CLAUDE.md §9 Multi-Tenant Invariants under
  "webhook → order linkage".
load_at_100x5min: >
  V1 = no impact (single Stripe account, single branch). V2/SaaS = P0
  cross-tenant data crossover blocker. Document in V1.0.2 → V2 graduation
  checklist.
```

### F-T331-ARCH-03 — Provider retry budget vs WebhookEvent attempts counter divergence
```yaml
severity: P2
category: observability-gap
confidence: medium
evidence:
  - app/Models/WebhookEvent.php:113 — markFailed increments attempts
    each time an error path runs
  - app/Console/Commands/OutboxWebhookRetryFailedCommand.php:30-32 docblock
    — "attempts counter intentionally NOT reset; webhook providers have
    their own retry budget and the application-side counter is informational
    only"
  - app/Jobs/ProcessWebhookEventJob.php:31-38 — $tries=3, $backoff=[10,60,300]
    per DLQ re-drive (3 attempts per cron tick)
  - Stripe webhook retry policy (provider-side): exponential up to 3 days
    with 16-18 attempts before giving up
  - SenangPay retry policy: any non-200 triggers retry; no docs-stated cap
reasoning: >
  Two retry budgets stack invisibly: (a) provider-side retries on non-200
  response, (b) DLQ re-drive (foodking:webhook:retry-failed hourly + 3
  job tries with exponential backoff). For a row that fails repeatedly:
  Stripe retries on 500 → 16-18 events recorded; cron flips them all back
  to pending hourly → each row gets +3 attempts per cron cycle. After
  24h of failures: a single transaction_id row could carry attempts=50+
  with no signal that it's a poisoned row vs ongoing degradation. No
  alarm threshold on attempts (cf. outbox `--threshold=10` in
  Kernel.php:49).
fix_direction: >
  Add a `webhook_events_attempts_threshold` config (default 10) +
  `foodking:webhook:monitor --threshold=10` command mirroring
  MonitorOutboxStaleness — emits structured log to observability channel
  when any row's attempts crosses threshold so Grafana/Sentry pages a
  human for triage. The DLQ retry cron remains unchanged; the monitor
  is read-only. Optionally cap markFailed: if attempts >= 20 →
  status='poisoned' (new enum value) + exclude from
  OutboxWebhookRetryFailedCommand query.
load_at_100x5min: >
  V1 single-branch, providers gated off → no impact. V1.0.2 with
  providers live + 100 orders/5min = 20 evt/sec target; one poisoned
  row = ~80 attempts over 24h, still well under DLQ row exhaustion
  but invisible to ops. P2.
```

### F-T331-ARCH-04 — Stripe activation guard ↔ webhook route inconsistency
```yaml
severity: P2
category: gate-divergence
confidence: high
evidence:
  - config/payment.php:49-56 — stripe.activation_guard.activation_gate_cleared
    = false (GATE_STRIPE_CENTS_ACTIVE_2026-04-25 Option B)
  - app/Http/Controllers/Frontend/PaymentController.php:151-164 — guard
    enforced on payment INITIATION routes (index/payment/success/fail/cancel)
  - app/Http/PaymentGateways/Routes/stripe.php:22-24 — webhook route is NOT
    behind the activation guard. Only middleware('installed') applies.
  - app/Providers/RouteServiceProvider.php:181-189 — auto-loads every
    file in PaymentGateways/Routes/ unconditionally
reasoning: >
  The Stripe activation gate is the V1 product-safety gate that prevents
  public Stripe activation. By design it shadows the entire Stripe path
  including initiation routes (PaymentController guards them). The
  webhook route bypasses this gate. For V1 Le Cayenne this is benign
  (no Stripe account configured upstream → STRIPE_WEBHOOK_SECRET empty
  → handler returns 500 misconfigured early at Stripe.php:200-208). But
  the gate's intent is "Stripe stays inactive for production V1" — a
  publicly-reachable webhook URL contradicts that intent if an attacker
  fuzzes /payment/stripe-webhook/ for fingerprinting (500 vs 404 reveal
  what's configured). Symmetry argument: PaymentController.php:159-163
  returns 404 (abort) when guard not cleared, the webhook returns 500
  exposing config state.
fix_direction: >
  Wrap the webhook route registration in a config check:
    if (config('payment.stripe.activation_guard.activation_gate_cleared'))
        Route::post(...);
  Or add a middleware StripeActivationGuard that aborts(404) when the
  guard is not cleared, mirroring PaymentController. Discuss with owner:
  Stripe sandbox testing may need the webhook reachable even while
  production guard is closed → introduce a separate "webhook_acceptance"
  config key.
load_at_100x5min: no impact. Hardening polish — non-blocking for V1.
```

---

## COVERAGE MAP

### Provider parity matrix
| Provider | Inbound webhook handler | Idempotency anchor | Signature verify | Route | Tests | DLQ replay |
|----------|------------------------|--------------------|-----------------|-------|-------|------------|
| Stripe | `Gateways/Stripe.php::handleWebhook` 197-328 | `firstOrCreate(provider=stripe, webhook_id=event.id)` | `\Stripe\Webhook::constructEvent` (HMAC-SHA-256 of timestamp.payload via Stripe-Signature) | `Routes/stripe.php:23` POST `/payment/stripe-webhook/` | `tests/Feature/Webhooks/StripeWebhookIdempotencyTest.php` 6 scenarios | `handleFromStoredEvent` 353-381 — markProcessed only (V1.0.1 stub) |
| SenangPay | `Gateways/Senangpay.php::webhook` 50-186 | `firstOrCreate(provider=senangpay, webhook_id=$txn_id)` | Inline HMAC-SHA-256 over `status_id\|order_id\|transaction_id\|msg` | `Routes/senangpay.php:18` GET+POST `/payment/senangpay-webhook/` | `tests/Feature/Webhooks/SenangpayWebhookIdempotencyTest.php` 6 scenarios | `handleFromStoredEvent` 224-251 — markProcessed only (V1.0.1 stub) |
| FCM | N/A (no inbound webhook) | N/A | N/A | N/A | N/A | N/A — outbound only via `SendFcmNotificationJob` |

### Architecture: signature → idempotency → process order (Stripe + SenangPay parity)
1. **Pre-flight validation** (Stripe:198-243, Senangpay:50-79). Required-field presence check + early 400 if malformed.
2. **Secret resolution** (Stripe: `config('services.stripe.webhook_secret')` → 500 if empty; Senangpay: PaymentGateway::with('gatewayOptions')->where('slug','senangpay')->first() then `senangpay_secret_key` → 500 if empty).
3. **Signature verification** (Stripe: `\Stripe\Webhook::constructEvent`; Senangpay: `hash_hmac('sha256', $canonical, $secret)` + `hash_equals`).
4. **Failure → 400 BEFORE writing WebhookEvent row** (provider stops retrying signature errors — Stripe behavior, SenangPay assumed same per spec). This is the correct order: an unsigned payload cannot pollute the idempotency ledger.
5. **firstOrCreate** atomic insert vs DB UNIQUE(provider, webhook_id) — concurrent INSERT race resolved at the DB layer (migration index `uk_webhook_provider_id`).
6. **Duplicate path** — `wasRecentlyCreated === false` → return 200 + `duplicate_ignored`. Provider stops retrying.
7. **Process inside `DB::transaction`** — CapturePaymentNotification upsert + `$event->markProcessed($orderId)`.
8. **Failure inside txn** → `markFailed($e->getMessage())` + return 500 (provider retries). Row sits in DLQ.

### DLQ semantics (cross-provider)
- Failed rows surface via `WebhookEvent::where(status='failed')`.
- `foodking:webhook:retry-failed --since=24h` (Kernel.php:75-80) flips them back to `pending`, dispatches `ProcessWebhookEventJob`.
- Job (`Jobs/ProcessWebhookEventJob.php:75-95`) routes by provider to `handleFromStoredEvent`.
- Currently stubbed (markProcessed only) — see F-T331-ARCH-01 above.
- Admin surfacing: **NO admin UI for DLQ replay yet** (manual SQL or `foodking:webhook:retry-failed --since=<id>`). V1.0.2 polish.

### Pruning (RED-team P0 closure)
- `foodking:webhook:prune --older-than-days=180` (Kernel.php:116, PruneWebhookEventsCommand) deletes rows with `status IN (processed, duplicate)`.
- `pending` + `failed` rows never pruned (DLQ retention guaranteed).
- 180d ≥ PCI chargeback window (per V1 Cloud-Prep insights bump).

### Cross-provider collision test
UNIQUE(provider, webhook_id) handles `evt_123` from Stripe vs `evt_123` from SenangPay correctly — verified by migration line 83 + `tests/Feature/Webhooks/SenangpayWebhookIdempotencyTest.php` and Stripe test do not collide because each seeds disjoint providers. **No defect.**

---

## OPEN QUESTIONS

1. **Stripe `payment_intent.succeeded` vs `charge.succeeded` event handling.** Stripe.php:276 only bridges `charge.succeeded` to CapturePaymentNotification. Modern Stripe (PaymentIntents API, default since 2019) fires `payment_intent.succeeded` as the canonical success event. The current `payment()` flow at Stripe.php:67-75 uses the legacy `charges->create` API which still fires `charge.succeeded`. If V1.0.2 migrates to PaymentIntents, the webhook handler will silently record success events without bridging payment → permanent PENDING orders. **Verify legacy charge API stays in V1.0.2 OR add a `payment_intent.succeeded` case at line 276.**

2. **SenangPay `status_id="2"` (pending) handling.** Senangpay.php:195-200 maps "2" → `payment_pending` but Senangpay.php:155 only bridges `status_id === '1'` (success) to CapturePaymentNotification. Pending events are recorded but stuck — no retry strategy if a "pending" event is never followed by a final "1" / "0" (provider bug or merchant deletion). **Verify SenangPay v2 spec actually transitions pending → success/failure within a bounded window. If not, add a stale-pending monitor mirroring outbox-staleness.**

3. **`handleFromStoredEvent` does NOT re-verify signature.** Documented in both files (Stripe.php:340-345, Senangpay.php:212-217) with rationale: "row already passed signature on first receipt". Correct as long as `webhook_events.payload` is treated as authoritative and the row's `signature` column is forensic-only. **Verify no admin path exists to mutate `webhook_events.payload` (Eloquent fillable list at WebhookEvent.php:61-73 includes `payload` — model layer permits update). Lock this down with a DB trigger or guarded fillable for V1.0.2.**

4. **FCM scope drift in T-3.3.1.** Task brief includes "FCM (push notifications, different domain)" — confirmed FCM is OUTBOUND only via `SendFcmNotificationJob`. No inbound webhook from Firebase to FoodKing exists in this codebase. **Recommend the goal-doc author scope T-3.3.1 to "payment provider webhooks only" and split FCM reliability (job retries, sad-path delivery) into a separate T-3.3.2.**

5. **Webhook signature replay window.** Stripe signature includes a `t=<timestamp>` component (Stripe.php:227 in test fixture, real handler at Stripe.php:214 calls `\Stripe\Webhook::constructEvent` with default tolerance 300s). SenangPay HMAC has NO timestamp component (canonical is `status_id|order_id|transaction_id|msg` — no nonce). **SenangPay is vulnerable to replay-after-secret-leak: if the merchant secret ever leaks, an attacker can re-send any historical signed payload with a fresh transaction_id and it passes signature; the firstOrCreate idempotency only blocks identical transaction_ids.** Confirm secret rotation procedure + idempotency window on order_id presence-check.

---

## What fails first at 100 orders × 5 min?

**With Stripe + SenangPay both gated off (V1 reality):** nothing — webhook handlers don't fire because no upstream provider is configured.

**With providers live (V1.0.2 hypothetical):**
- Stripe sustained 20 evt/sec well below provider rate limits (Stripe says 100 req/sec/account).
- SenangPay docs are vaguer; assume similar absorption.
- First measurable failure mode = **DLQ replay race**: a row that fails for transient reasons (DB connection blip) gets re-driven 3× by `ProcessWebhookEventJob`, all 3 retries land in the markProcessed-only stub (F-T331-ARCH-01), masking the underlying transient as "processed without effect". One in a thousand events at 20 evt/sec = 1 silent loss per 50 seconds of failure. **Owner-gate before V1.0.2.**
- At **provider-side incident** (Stripe partial outage, retry storm): each storm-retried event triggers fresh firstOrCreate which short-circuits to `duplicate_ignored` 200 — provider stops retrying within 1-2 storms. No queue pressure builds.

**Stripe replay-after-restart:** if FoodKing is offline 5 min, Stripe's storm retry hits us 1-3 times after we restart; each is logged + idempotent. Recovery clean.

**SenangPay malformed payload bursts:** 400 responses cause SenangPay to retry forever (some providers do); risk of cascading 400 storms. **Recommend SenangPay rate-limit middleware on the webhook route** (10 req/sec/IP) — currently absent.

---

## Cross-reference to Round 1 W6 architect (outbox + domain-events)

The webhook ledger and the outbox ledger are **architecturally separate but symmetrically designed**:
- Both use `firstOrCreate(unique-key)` as app-level guard.
- Both use DB UNIQUE constraint as concurrency floor.
- Both have separated `retry-failed` cron + `prune` cron + `monitor` (outbox has it via MonitorOutboxStaleness; webhook **lacks** an equivalent — see F-T331-ARCH-03).
- Both defer business-logic replay (outbox: `ProcessOutboxJob` is fully wired; webhook: `ProcessWebhookEventJob` is a markProcessed-only stub for V1.0.1 — see F-T331-ARCH-01).

The webhook side is the **less-mature lane** of the two. V1.0.2 should bring it to outbox parity: monitor command + business-logic re-run on replay + admin DLQ UI.
