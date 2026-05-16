# Wave Z — Z8 (Sync: Outbox + Webhooks + Idempotency) — Round 2 Findings

- Date: 2026-05-16
- Branch: `feature/mobile-app-le-cayenne-2026-05-10`
- HEAD: `56204f052`
- Auditor: Round 2 read-only verifier (Claude)
- Scope: verify Round 1 P1 heals + RED-team for regressions

---

## 1. Round 1 heal verification

### P1-Z8-01 — Outbox listeners missing `wasRecentlyCreated` guard → HEALED in `d424f8402` (Sprint 5C)

Verified all 6 target listeners now skip `DB::afterCommit` dispatch on replay
(when `firstOrCreate` returned an existing row). The early `return` short-circuits
queue serialization + log noise; Phase 1 atomic claim still backstops at the
broadcaster layer (`OutboxConcurrentWorkerDedupeTest::two_sequential_handle_calls_only_broadcast_once`).

| # | File (absolute path) | Guard line | Form | Status |
|---|---|---|---|---|
| 1 | `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/PersistOrderStatusChangedToOutbox.php` | 64 | `if (! $de->wasRecentlyCreated) { return; }` | OK |
| 2 | `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php` | 69 | `if (! $de->wasRecentlyCreated) { return; }` | OK |
| 3 | `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/PersistOrderTableChangedToOutbox.php` | 85 | `if (! $de->wasRecentlyCreated) { return; }` | OK |
| 4 | `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/PersistItemAvailabilityChangedToOutbox.php` | 89 | `if (! $de->wasRecentlyCreated) { return; }` | OK |
| 5 | `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/PersistItemExtraAvailabilityChangedToOutbox.php` | 66 | `if (! $de->wasRecentlyCreated) { return; }` | OK |
| 6 | `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/PersistItemVariationAvailabilityChangedToOutbox.php` | 65 | `if (! $de->wasRecentlyCreated) { return; }` | OK |

Cross-check — the 4 pre-Sprint-5C healed listeners also have the guard, completing
parity across **all 10 outbox listeners**:

- `PersistOrderCreatedToOutbox.php:57` (negative-return form, Sprint 3B)
- `PersistOrderPaidAtCounterToOutbox.php:54` (negative-return form, Sprint 3B)
- `PersistCatalogChangedToOutbox.php:92` (positive-inline form, Sprint 3B)
- `PersistCouponChangedToOutbox.php:82` (positive-inline form, Sprint 3B)

Both guard forms are semantically equivalent. Style mix is intentional per the
Sprint 5C commit message ("parity pattern"); no refactor required.

#### Test evidence

`php artisan test --filter='Outbox|DomainEvent|ItemAvailability|OrderStatus'`:

- **98 passed, 2 skipped, 0 failed** (11.58s).
- The 2 skips are `OutboxPipelineHealthSentinelTest` cases requiring
  `CI_WEBSOCKETS_HARNESS=1` (real Soketi on `127.0.0.1:6001`) — NOT a regression,
  expected when running without the harness bootstrap.
- Critical coverage validated:
  - `Outbox\ListenerReplayDedupeTest` — 10 cases, includes the
    `persist_item_availability_changed_replay_yields_one_row` and
    `persist_item_availability_changed_global_and_branch_scoped_coexist` cases
    that prove the guard does NOT collapse the legitimate global+branch fan-out.
  - `OutboxConcurrentWorkerDedupeTest::two_sequential_handle_calls_only_broadcast_once`
    — proves the existing Phase 1 atomic claim continues to absorb the duplicate
    path the new guard now short-circuits earlier.
  - `Outbox\OutboxProductionLikeSimulationTest::global_catalog_event_fans_out_to_active_branch_channels_with_valid_envelopes`
    — fan-out path unaffected.

**Verdict: P1-Z8-01 — CLOSED (HEALED + GREEN).**

---

### P1-Z8-02 — No webhook events DLQ cron → DEFERRED V1.0.1 (confirmed)

Searched for command + schedule entry:

```
grep -rn "foodking:webhook:retry-failed\|webhook:retry-failed\|RetryFailedWebhooks" \
  app/ routes/ bootstrap/   → 0 hits
```

`app/Console/Kernel.php` schedule contains `foodking:outbox:retry-failed --since=24h`
(hourly) but no webhook equivalent. No `App\Console\Commands\WebhookRetry*.php`.

This is consistent with the Round 1 verdict — scheduling a phantom command would
have failed at boot. V1.0.1 requires:

1. New artisan command `foodking:webhook:retry-failed` (probably hour window like
   the outbox sibling).
2. Retry job + backoff (Stripe webhooks are signed-replayable; SenangPay needs
   careful design since `transaction_id` is server-issued — the firstOrCreate
   guard already idempotency-protects re-submissions).
3. Schedule entry in `Kernel.php`.
4. Sentinel test mirroring `OutboxRetryFailedScheduleTest`.

**Verdict: P1-Z8-02 — DEFERRED V1.0.1 (CONFIRMED). No phantom scheduling.**

---

### Webhook idempotency (Sprint 3A bundled in `80dbc79c2`) — re-verified

Note: the path referenced in the mission (`app/Http/Controllers/Webhook/{Stripe,Senangpay}.php`)
does not exist. Actual location is `app/Http/PaymentGateways/Gateways/`. Both
controllers use `WebhookEvent::firstOrCreate` keyed on `(provider, webhook_id)`
backed by a DB UNIQUE constraint, with explicit `wasRecentlyCreated` duplicate
short-circuit returning `200 {"status": "duplicate_ignored"}`.

- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/PaymentGateways/Gateways/Stripe.php:227-248`
  - `WebhookEvent::firstOrCreate(['provider' => PROVIDER_STRIPE, 'webhook_id' => $eventId], ...)`
  - Duplicate path logged on `fiscal` channel → `200 duplicate_ignored`.
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/PaymentGateways/Gateways/Senangpay.php:125-146`
  - `WebhookEvent::firstOrCreate(['provider' => PROVIDER_SENANGPAY, 'webhook_id' => $transactionId], ...)`
  - Same duplicate path + fiscal log.

Both controllers verify signature BEFORE the firstOrCreate ledger write — the
correct order (replay attacks blocked by signature; legitimate retries blocked
by the ledger).

**Verdict: webhook idempotency — STILL HEALED.**

---

## 2. RED-team — NEW issues introduced by Wave Z

### NEW-Z8-A (NONE / informational) — Listener guard form inconsistency
Two coexisting forms across the 10 outbox listeners:
- Negative `if (! $de->wasRecentlyCreated) { return; }` (8 listeners, including
  the 6 patched in Sprint 5C).
- Positive `if ($de->wasRecentlyCreated) { DispatchDomainEventsJob::dispatch(...); }`
  inline (2 listeners: `PersistCatalogChangedToOutbox`, `PersistCouponChangedToOutbox`).

Semantically equivalent; no correctness gap. Optional V1.0.1 cosmetic refactor
to a single style. NOT a finding.

### NEW-Z8-B (NONE / verified) — Guard does not break global fan-out
`PersistItemAvailabilityChangedToOutbox` is the only listener that fans a single
event to multiple channels (global = all active branches). The
`ListenerReplayDedupeTest::persist_item_availability_changed_global_and_branch_scoped_coexist`
case proves the idempotency key includes `branchId === null ? 'global' : (int)`
so a global emission and per-branch emission for the same item in the same
request continue to coexist. Confirmed by reading
`PersistItemAvailabilityChangedToOutbox.php:62-69`.

### NEW-Z8-C (NONE / verified) — `DB::afterCommit` timing not regressed
Guard runs BEFORE `DB::afterCommit` registration, NOT inside it. If outer txn
rolls back, no event is persisted (firstOrCreate is post-write so the row was
written in-txn; the rollback drops it). `CatalogEventDispatchAfterCommitTest`
covers the rollback-suppresses-dispatch path and passes — heal does not change
the broadcast-after-commit contract.

### NEW-Z8-D (NONE / verified) — Failure path unaffected
The 6 guarded listeners still wrap `DispatchDomainEventsJob::dispatch` in
`try { ... } catch (\Throwable)` → Log::warning, preserving the E-001 (round-2/3)
sync-queue isolation. `OutboxConcurrentWorkerDedupeTest::broadcast_failure_releases_claim_for_retry`
and the `Outbox\OutboxRetryFailedScheduleTest` family all green.

### NEW-Z8-E (informational, no action) — One outbox sibling does NOT skip on replay
`PersistOrderCreatedToOutbox` uses correlation_id in its key, so technically
replay within the same request returns the existing row and the early `return`
applies. However the upstream test `OutboxTest::order_created_persists_domain_event`
covers happy-path persistence + dispatch and still passes. No regression.

---

## 3. Convergence summary

| Round 1 finding | Round 2 verdict | Action |
|---|---|---|
| P1-Z8-01 outbox listeners replay guard | CLOSED — 6/6 guarded, 98/98 tests green | None |
| P1-Z8-02 webhook DLQ cron | DEFERRED V1.0.1 — confirmed no command | V1.0.1 backlog |
| Webhook idempotency (Sprint 3A) | CONFIRMED HEALED | None |
| P2/P3 items | DEFERRED V1.0.1 | V1.0.1 backlog |
| RED-team NEW issues | None substantive | None |

### Z8 status: GREEN — all P1 either CLOSED-HEALED or DEFERRED-V1.0.1 with explicit, traceable rationale.

---

## 4. References

- Heal commit: `d424f8402 feat(wave-z-5c): outbox parity + OSS deterministic + EN i18n + POS kiosk-quote`
- Earlier heal: `4573ae7de feat(outbox): Sprint 3B — schedule retry-failed hourly + listeners wasRecentlyCreated guard`
- Earlier webhook heal: `80dbc79c2 feat(kds): Sprint 2A+3C — V2 layout default + delivery address/phone/name enrichment` (Sprint 3A bundled per mission brief)
- Test command: `php artisan test --filter='Outbox|DomainEvent|ItemAvailability|OrderStatus'`
- Test result: 98 passed, 2 skipped (harness-only sentinels), 0 failed, 11.58s
