# Z8 — Sync (Outbox + Webhooks + Idempotency) — Round 1 Wave Z findings

**Auditor**: Z8 sub-agent (read-only, adversarial)
**Branch**: feature/mobile-app-le-cayenne-2026-05-10
**HEAD**: c3ba89863
**Verdict**: **GO-CONDITIONAL** — sister P1-SYNC-01/02/03 all addressed (better than briefed: P1-SYNC-01 was reported open but is in fact **healed** at HEAD), but 3 NEW findings surface: P1-SYNC-03 partial heal scope (6 listeners still un-guarded), no dead-letter cron for `webhook_events`, and a commit-hygiene defect that silently bundled Sprint 3A into a "Sprint 2A+3C" commit. No P0.

---

## Summary

Architecture verified end-to-end:
- **Outbox**: `domain_events` table (`database/migrations/2026_04_15_200000_create_domain_events_table.php:11-30`) + `idempotency_key` UNIQUE column (`2026_05_09_180000_add_idempotency_key_to_domain_events.php:37-41`). `DispatchDomainEventsJob` (`app/Jobs/DispatchDomainEventsJob.php:50-94`) atomic-claims rows under `lockForUpdate` + `dispatched_at` guard, broadcasts after commit (Phase 2 line 96-117), releases claim on failure (line 140-162). Backoff `[1,5,15,60,300]` × `tries=6` per BRAIN — confirmed lines 40-42.
- **Listeners** (8 Persist*Outbox listeners + 1 PersistOrderTableChanged): all use `DomainEvent::firstOrCreate(['idempotency_key' => …])` keyed deterministically. Race-safe per `uniq_domain_events_idempotency_key` UNIQUE index.
- **Webhooks**: `webhook_events` table created via `2026_05_09_120000_create_webhook_events_table.php` with `UNIQUE (provider, webhook_id)` constraint at line 83 (`uk_webhook_provider_id`). `WebhookEvent` model (`app/Models/WebhookEvent.php:47-121`) with `markProcessed/markFailed` + 4-state enum. **Both Stripe and SenangPay handlers now call `WebhookEvent::firstOrCreate` with full HMAC signature verification** — see P1-SYNC-01 detail below.
- **Idempotency middleware**: `IdempotencyKeyMiddleware` (`app/Http/Middleware/IdempotencyKeyMiddleware.php:1-244`) is wired as alias `'idempotency'` in `app/Http/Kernel.php:98` and applied on 9 POST mutating routes in `routes/api.php` (lines 728, 857, 859, 866, 877, 878, 887, 1126, 1129). Frozen-zone — read-only diff verified untouched (matches KICKOFF line 74). Dual-layer: middleware-level cache via `IdempotencyKeyRepository` + DB UNIQUE `(branch_id, idempotency_key)` (line 24 comment).
- **Broadcasting**: `BroadcastServiceProvider` (`app/Providers/BroadcastServiceProvider.php:22`) routes via `auth:sanctum`. Channel auth (`routes/channels.php:25-39`): kiosk tokens (`tokenCan('kiosk:order')`) restricted to `KioskMachine.branch_id` (line 27-30). Admin (`branch_id=0`) wildcard. Staff scoped to own branch.

## Verifications against sister P1-SYNC findings

### P1-SYNC-01 — Webhook payment idempotency — **HEALED** (briefed as OPEN; correct status = CLOSED)

The brief stated SenangPay `webhook` returns 501 and Stripe lacked `firstOrCreate`. **Both are wrong at HEAD c3ba89863.**

- **SenangPay** `app/Http/PaymentGateways/Gateways/Senangpay.php:50-186`:
  - Lines 110-121: HMAC-SHA-256 verification against `senangpay_secret_key` from `gateway_options` (canonical = `status_id|order_id|transaction_id|msg`). 400 on invalid sig.
  - Lines 125-137: `WebhookEvent::firstOrCreate(['provider' => PROVIDER_SENANGPAY, 'webhook_id' => $transactionId], …)` — UNIQUE backed.
  - Lines 139-146: 200 `duplicate_ignored` when `!$event->wasRecentlyCreated`.
  - Lines 149-168: `DB::transaction` wraps `CapturePaymentNotification` write + `markProcessed`.
- **Stripe** `app/Http/PaymentGateways/Gateways/Stripe.php:177-308`:
  - Lines 194: `Stripe\Webhook::constructEvent($payload, $sigHeader, $secret)` — uses official lib signature check.
  - Lines 227-239: `WebhookEvent::firstOrCreate(['provider' => PROVIDER_STRIPE, 'webhook_id' => $eventId], …)`.
  - Lines 241-248: 200 `duplicate_ignored` on replay.
- CSRF excluded for both: `app/Http/Middleware/VerifyCsrfToken.php:25-26`.

Route registrations live at `app/Http/PaymentGateways/Routes/stripe.php:23` (`payment.stripe.webhook` POST) and `Routes/senangpay.php:18` (`payment.senangpay.webhook` GET/POST).

**Verdict on sister briefing**: the brief was generated against an older snapshot; SenangPay+Stripe webhook idempotency landed in commit `80dbc79c2` (see commit-hygiene finding NEW-Z8-03 below).

### P1-SYNC-02 — `foodking:outbox:retry-failed` schedule — **HEALED**

- Scheduled in `app/Console/Kernel.php:63-68`:
  - Command: `foodking:outbox:retry-failed --since=24h`
  - Cadence: `->hourly()` (cron `0 * * * *`)
  - Concurrency: `->withoutOverlapping(10)->onOneServer()`
  - Named: `outbox-retry-failed` (line 67)
  - Description: line 68
- Artisan command verified present: `php artisan list | grep outbox` returns `foodking:outbox:retry-failed` (alongside `monitor` and `rescue`).
- Command body `app/Console/Commands/OutboxRetryFailedCommand.php:11-64`: scope `failed(5)` (line 22, model scope at `DomainEvent.php:45-49` filters `attempts >= 5` + `dispatched_at NULL`), `--since=…` cutoff (line 19, parser line 42-63 supports `s/m/h/d`). For each match: reset `attempts=0`, `last_error=null`, `dispatched_at=null`, then re-`dispatch`.
- Heal commit `4573ae7de` `app/Console/Kernel.php +16 lines` confirmed via `git show 4573ae7de --stat`.

### P1-SYNC-03 — `wasRecentlyCreated` guard on listeners — **PARTIALLY HEALED** (see NEW-Z8-01)

The heal commit `4573ae7de` touched only **2 of the 8** Persist*Outbox listeners that use `firstOrCreate` + `DB::afterCommit(DispatchDomainEventsJob::dispatch)`. The remaining 6 still un-conditionally queue the dispatch job on listener replay. See NEW-Z8-01 below.

The two healed listeners:
- `app/Listeners/PersistOrderCreatedToOutbox.php:57-59` — `if (! $domainEvent->wasRecentlyCreated) { return; }` before `DB::afterCommit`.
- `app/Listeners/PersistOrderPaidAtCounterToOutbox.php:54-56` — same pattern.

Reference healed-pattern siblings (pre-existing): `PersistCatalogChangedToOutbox.php:92`, `PersistCouponChangedToOutbox.php:82`.

---

## P0 findings (file:line)

**None.** No fiscal violation, no auth bypass, no payment double-book risk (P1-SYNC-01 healed), no cross-branch leak. NF525 invariants untouched.

---

## P1 findings (file:line)

### P1-Z8-01 — `wasRecentlyCreated` guard missing on 6 outbox listeners (P1-SYNC-03 partial heal)

**Files** (each line is the `DB::afterCommit` registration immediately preceded by `firstOrCreate`):

| Listener | afterCommit line | Event triggered by | Re-fire risk |
|----------|------------------|--------------------|--------------|
| `app/Listeners/PersistOrderStatusChangedToOutbox.php:59` | OrderStatusChanged (status flip) | Each transition (PENDING→PREPARING→PREPARED→…) — listener can replay on queue retry between persistence + post-commit broadcast |
| `app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php:66` | OrderPaymentStatusChanged | Payment lifecycle flips (PENDING→PAID, refund) |
| `app/Listeners/PersistOrderTableChangedToOutbox.php:82` | OrderTableChanged | Table assign/move |
| `app/Listeners/PersistItemAvailabilityChangedToOutbox.php:86` | ItemAvailabilityChanged | Stock 86 flip / restore |
| `app/Listeners/PersistItemExtraAvailabilityChangedToOutbox.php:63` | ItemExtraAvailabilityChanged | Extra 86 flip |
| `app/Listeners/PersistItemVariationAvailabilityChangedToOutbox.php:62` | ItemVariationAvailabilityChanged | Variation 86 flip |

Sprint 3B heal commit `4573ae7de` message explicitly references `PersistCatalogChangedToOutbox:92` and `PersistCouponChangedToOutbox:82` as the parity siblings — but the heal stopped at 2 listeners (OrderCreated + OrderPaidAtCounter) when 8 share the same pattern.

**Severity**: P1 (parity with sister P1-SYNC-03). Correctness is preserved by `DispatchDomainEventsJob`'s Phase 1 atomic claim under `lockForUpdate` (`DispatchDomainEventsJob.php:65-86`) — the second dispatch finds `dispatched_at != null` and skips silently (line 75-78). The cost is **wasted queue serialization + log noise** (matches the cost calculus the heal commit message itself articulates for the two healed listeners). On a high-traffic branch with frequent stock-86 flips and status transitions, this can multiply duplicated `DispatchDomainEventsJob` enqueues by N×listeners-without-guard.

**Recommendation**: 6-listener heal with the same 3-line guard already vetted by Sprint 3B tests (`tests/Feature/Sync/ListenerReplayGuardTest.php`).

### P1-Z8-02 — No dead-letter cron / re-drive scheduler for `webhook_events`

**Files**: `app/Console/Kernel.php` (entire schedule); `app/Models/WebhookEvent.php:39`

`WebhookEvent.php:39` docblock promises «attempts counter for dead-letter re-drive jobs». `Stripe.php:174-176` comment mirrors the claim: «a dead-letter cron can re-drive (attempts counter visible in webhook_events)». And `Senangpay.php:177-178` likewise: «The DB row remains for the dead-letter cron».

But `app/Console/Kernel.php:21-154` schedules **only** `foodking:outbox:rescue`, `foodking:outbox:monitor`, `foodking:outbox:retry-failed`, `pos:purge-parked-orders`, `foodking:fiscal:retry-alloc`, `foodking:availability:reset-stale-quota`, NF525 archive — **zero references to `webhook_events`**. Grep confirms `app/Console/Commands/` has no command targeting `webhook_events`.

**Impact**: A Stripe `charge.succeeded` webhook where our `CapturePaymentNotification` write throws (e.g., DB transient failure inside `DB::transaction` at `Stripe.php:251-290`) flips the row to `status=failed`, `attempts++` (`WebhookEvent::markFailed`). The handler then returns 500 — Stripe **will** retry, so in practice the provider re-drives. **But SenangPay's retry policy is bounded** (their docs cap retries at ~5 over 30 minutes, then dead-letter on their side). After their retries exhaust, the row is stranded `status=failed, processed_at=NULL` forever. No app-side cron to re-attempt → payment confirmation **silently lost** for the customer; manual SQL re-drive required.

**Severity**: P1 — payment-trail completeness gap. Mitigated only when the provider's own retry policy succeeds within their window. Hidden coupling on provider behavior.

**Recommendation**: Add a `foodking:webhook:retry-failed` command + hourly schedule mirroring `foodking:outbox:retry-failed` (filter `status='failed' AND received_at >= now()-24h`, reset `status='pending', attempts=0, error_message=NULL`, re-invoke handler logic OR raise a Log::error so the staleness monitor pages).

---

## P2 findings (file:line)

### P2-Z8-03 — Commit hygiene: Sprint 3A webhook idempotency silently bundled into "Sprint 2A+3C" commit

**File**: git commit `80dbc79c2` (subject: `feat(kds): Sprint 2A+3C — V2 layout default + delivery address/phone/name enrichment`)

The commit message describes **KDS V2 flip (3C) + delivery enrichment (2A) only**. But `git show 80dbc79c2 -- app/Http/PaymentGateways/Gateways/Stripe.php` reveals **+165 lines net** including the entire `handleWebhook(Request)` method (lines 177-308 at HEAD) with HMAC verification + `WebhookEvent::firstOrCreate` — code annotated **`[Sprint 3A — Webhook idempotency 2026-05-16]`** in its own PHPDoc (line 146). Same commit also added the `Senangpay::webhook` full implementation (sister Senangpay.php diff in the commit).

The 00_KICKOFF.md line 39 lists Sprint 3A under «Remaining sprints from sister plan **not yet executed**» — but the work IS executed at HEAD. The kickoff inventory was generated from commit subjects/sprints labels, not actual diffs, so Sprint 3A landed invisibly.

**Severity**: P2 (audit-traceability defect, not a runtime bug). Triggers downstream confusion:
- Sister verdict reports P1-SYNC-01 as open ⇒ heal-planning meetings will allocate effort to already-done work.
- A regression in Stripe/SenangPay webhook handler can't be `git blame`'d to the right commit/PR.
- Owner-gate sign-off for «Sprint 3A» never explicitly happened — the work shipped under a kds-scoped commit subject.

**Recommendation**: For Round 2 of Wave Z, either (a) emit a separate doc commit / tag pinning `80dbc79c2` as Sprint 3A landing, or (b) backfill `CONVERGENCE.md` with the truth and ship a hygiene rule (`.gitcommit-template` / pre-commit lint) that fails when a commit touches files outside its declared sprint scope.

### P2-Z8-04 — Stripe webhook order_id only resolved via `charge.metadata.order_id` — legacy `payment()` does not populate metadata

**File**: `app/Http/PaymentGateways/Gateways/Stripe.php:264-285`

Stripe `payment()` (line 38-84) creates the charge with **no metadata**: `$this->gateway->charges->create([...amount, currency, source, description...])`. The webhook handler (line 264-270) extracts `order_id` from `$charge->metadata->order_id` — **always null for charges initiated by our own `payment()` path**. Result: `CapturePaymentNotification` is **NOT written** when the webhook fires for our own legacy charges; the original `payment()` path writes it inline (line 63-67) so the redirect-based capture flow still works, but the webhook-driven capture flow is permanently dead for our own initiations.

The own comment line 261-266 acknowledges «extend metadata in payment() in a future iteration» — explicit deferral.

**Severity**: P2. Not a regression, not a payment double-book. But it means the webhook acts only as an **idempotency ledger + forensic audit**, not as the actual capture pathway it advertises in the PHPDoc (line 165-168 «Mirrors the existing `payment()` flow: creates a `CapturePaymentNotification`»). Functional drift between docblock and behavior.

**Recommendation**: Either (a) add `'metadata' => ['order_id' => $order->id]` to the `charges->create` call at line 50, or (b) update the PHPDoc to match the real behavior («pure idempotency ledger + audit; capture path is `payment()`-only»).

### P2-Z8-05 — Queue `retry_after=90s` collides with `$backoff[300]` if prod runs database/redis driver

**File**: `config/queue.php:41, 49, 69` + `app/Jobs/DispatchDomainEventsJob.php:40`

`DispatchDomainEventsJob` declares `$backoff = [1, 5, 15, 60, 300]` (line 40). The last two entries (60s, 300s) exceed `retry_after` for `database` (line 41), `beanstalkd` (line 49), and `redis` (line 69) connections — all default to **90 seconds**. Laravel will re-release the job for processing after `retry_after` even if the worker is mid-backoff, leading to **concurrent processing of the same job ID**.

**Mitigated by**: `DispatchDomainEventsJob.php:65-86` Phase 1 atomic claim under `lockForUpdate` + `dispatched_at` guard — the losing worker observes `dispatched_at != null` and skips. So correctness is preserved.

**Severity**: P2 (worker waste, not correctness). Conditional on the prod env: `'default' => env('QUEUE_CONNECTION', 'sync')` (line 16) is `sync` in dev, but if `.env` sets `QUEUE_CONNECTION=database` or `=redis` (likely for prod), the collision applies. Without env evidence I flag as conditional.

**Recommendation**: Raise `retry_after` to ≥360 (covers the 300s + grace) **or** lower the last backoff entry to ≤80s. The latter is preferred since 5-min trailing backoff is rarely useful (the staleness monitor pages at 30s anyway — `MonitorOutboxStaleness.php:32`).

---

## P3 findings

### P3-Z8-06 — `foodking:outbox:retry-failed` hourly cadence — minor customer-experience smell

**File**: `app/Console/Kernel.php:63-68`

Scheduled `->hourly()`. The rescue lane (`foodking:outbox:rescue` at line 39-42) runs every minute for `attempts < 5`. So a typical event hits 5 retries within ~6.4 min (`$backoff` curve total documented in `DispatchDomainEventsJob.php:31-33`), then sits 0-60 min waiting for the next `retry-failed` run, then resets `attempts=0` and re-enters Phase 1 retries.

**Realistic scenario**: SignaPay/Stripe webhook arrives → app DB transient blip → `attempts` climbs to 5+ via the inner retries (~6 min). Then **up to 60 min wait** before the retry-failed cron picks it up. The customer order acknowledgment broadcast can be 1+ hours late if the queue infrastructure was flapping right when the order was paid.

**Severity**: P3. Cadence rationale (line 56-62 «scoped to last 24h to bound the recovery surface») is sound — the alternative (every 5 min) churns the failed bucket and competes with `outbox:rescue`. The staleness monitor (`MonitorOutboxStaleness.php`) pages at 30s threshold which is the real escalation path. The hourly cron is the **dead-letter recovery layer, not the primary path**.

**Recommendation**: Document the latency contract («terminal-failure events may broadcast up to 1h late post-resolution») in `docs/REALTIME_SETUP.md` or BRAIN.md §9 so operations expects it. No code change recommended unless owner declares the latency unacceptable.

### P3-Z8-07 — `Senangpay::webhook` returns 500 on processing failure; SenangPay retries — but the `webhook_events` row stays `status=failed` blocking forensic re-drive

**File**: `app/Http/PaymentGateways/Gateways/Senangpay.php:170-183`

Catch block at line 169-183: on processing exception, `markFailed` flips the row to `status='failed'`, then returns 500. SenangPay retries per its policy (typically 5 tries over 30 min); each retry hits `firstOrCreate` which **finds the row** (UNIQUE collision), returns existing event with `wasRecentlyCreated=false`, and **short-circuits to 200 `duplicate_ignored`** (line 139-146) — without re-attempting the inner `DB::transaction` work.

**Effect**: After the very first 500, SenangPay's subsequent retries are absorbed as duplicates and stop. The transaction is never bridged into `CapturePaymentNotification`. Manual SQL or P1-Z8-02 dead-letter cron is the only recovery path.

**Severity**: P3 (consequence of P1-Z8-02 — once that lands, this is moot). Without the dead-letter cron, this is the concrete mechanism for the «silent payment loss» risk flagged in P1-Z8-02.

**Recommendation**: Either (a) on `status='failed'` with `attempts < 3`, re-attempt the inner `DB::transaction` work inside the duplicate branch (line 139-146) instead of returning `duplicate_ignored`; or (b) ship P1-Z8-02's dead-letter cron and accept this pattern.

---

## Frozen-zone diff

Confirmed untouched in last 10 commits (KICKOFF baseline + spot-checked at HEAD):
- `app/Http/Middleware/IdempotencyKeyMiddleware.php` — 0 lines diff (per KICKOFF line 74). 244 lines at HEAD, unchanged.

Sprint 3B heal `4573ae7de` touched **only**: `app/Console/Kernel.php`, `app/Listeners/PersistOrderCreatedToOutbox.php`, `app/Listeners/PersistOrderPaidAtCounterToOutbox.php` + 2 test files. **Zero frozen-zone breach**.

---

## NF525 invariants

Untouched. Outbox / webhook layer is downstream of fiscal sequence allocation (see `OrderService.php:1513` `afterCommit` comment — listeners fire **after** the order is sealed). `DispatchDomainEventsJob` does not touch `audit_logs`, `z_reports`, or `fiscal_sequence_no`.

---

## Verdict

**GO-CONDITIONAL** for Sync layer.

| Sister finding | Status at HEAD c3ba89863 |
|----------------|--------------------------|
| P1-SYNC-01 webhook idempotency | **HEALED** (bundled into 80dbc79c2 as Sprint 3A — see P2-Z8-03) |
| P1-SYNC-02 retry-failed schedule | **HEALED** (4573ae7de — Kernel.php:63-68) |
| P1-SYNC-03 wasRecentlyCreated guard | **PARTIALLY HEALED** — 2/8 listeners patched, **6 remain** (P1-Z8-01) |

**Blockers for Round 2 GREEN**: P1-Z8-01 (6-listener parity heal) + P1-Z8-02 (webhook dead-letter cron). P2/P3 are V1.x backlog.
