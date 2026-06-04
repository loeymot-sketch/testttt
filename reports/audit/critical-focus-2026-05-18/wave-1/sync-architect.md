# GStack Architect — Sync — Wave 1 (resumed)

**Mission:** Deep audit of the FoodKing V1 LOCAL Le Cayenne Sync layer
(Outbox + Domain Events + Soketi + Webhook + Idempotency), read-only,
file:line strict.
**Branch:** `v1-0-1-hardening-2026-05-17` HEAD `f24b49c4...` post Wave 2.
**Date:** 2026-05-18.
**Scope constraint:** LOCAL-only; `IdempotencyKeyMiddleware` is FROZEN
(audit only).

---

## 1. Surface inventory (file:line)

**Outbox listeners (8 outbox writers, registered in EventServiceProvider):**
- `app/Listeners/PersistOrderCreatedToOutbox.php:13` — `OrderCreated`
  → `EventServiceProvider.php:147`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php:13` — `OrderStatusChanged`
  → `EventServiceProvider.php:139`
- `app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php:20`
  → `EventServiceProvider.php:157`
- `app/Listeners/PersistOrderTableChangedToOutbox.php:27` — `OrderTableChanged`
  → `EventServiceProvider.php:171`
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php:15`
  → `EventServiceProvider.php:180`
- `app/Listeners/PersistItemExtraAvailabilityChangedToOutbox.php:21`
  → `EventServiceProvider.php:187`
- `app/Listeners/PersistItemVariationAvailabilityChangedToOutbox.php:20`
  → `EventServiceProvider.php:193`
- `app/Listeners/PersistSettingsUpdatedToOutbox.php:25`
  → `EventServiceProvider.php:240`

**Non-outbox listeners (different correctness category):**
- `app/Listeners/DispatchKdsTicket.php:11` — re-emits `OrderStatusChanged`
  (caller, not writer)
- `app/Listeners/RevokeTokensOnBranchDeactivated.php:23`
  → `EventServiceProvider.php:246` (Sanctum side-effect)

**Jobs:** `app/Jobs/DispatchDomainEventsJob.php:20`
(`backoff=[1,5,15,60,300]`, `tries=6`, queue `high`);
`app/Jobs/ProcessWebhookEventJob.php:27` (`tries=3`, `backoff=[10,60,300]`).

**Commands:** `app/Console/Commands/PruneOutboxCommand.php:33`;
`app/Console/Commands/PruneWebhookEventsCommand.php:41`;
`app/Console/Commands/OutboxRetryFailedCommand.php:12`;
`app/Console/Commands/OutboxWebhookRetryFailedCommand.php:37`;
`app/Console/Commands/MonitorOutboxStaleness.php`;
`app/Console/Commands/OutboxRescueCommand.php`.

**Webhook routes + secrets:**
- `app/Http/PaymentGateways/Routes/stripe.php:26` — POST only, throttle 60/1
- `app/Http/PaymentGateways/Routes/senangpay.php:21` — POST only, throttle 60/1
- `config/services.php:67-68` — `services.stripe.webhook_secret`
- `routes/channels.php:25` — `branch.{branchId}` (Soketi auth, local)
- `database/migrations/2026_05_09_120000_create_webhook_events_table.php:83`
  — UNIQUE(provider, webhook_id) = `uk_webhook_provider_id`

**Idempotency:**
- `app/Http/Middleware/IdempotencyKeyMiddleware.php:27` (FROZEN)
- `config/idempotency.php:25-34` — 8 `required_routes` patterns
- 17 route attachments via `'idempotency'` alias in `routes/api.php`
  (`app/Http/Kernel.php:135` alias mapping)

---

## 2. Critical invariants

| Invariant | Status | Evidence |
|---|---|---|
| 8 outbox writers carry `wasRecentlyCreated` dedup guard | PASS | See §3 |
| 2 non-outbox listeners use category-appropriate guards | PASS | See §3 |
| `DispatchDomainEventsJob` backoff `[1,5,15,60,300]`s, tries=6 | PASS | `DispatchDomainEventsJob.php:40-42` |
| `failed_jobs` terminal handler with last_error persisted | PASS | `DispatchDomainEventsJob.php:165-186` |
| `webhook_events` UNIQUE(provider, webhook_id) | PASS | `2026_05_09_120000:83` |
| Stripe signature gated on `STRIPE_WEBHOOK_SECRET` empty-guard | PASS | `Stripe.php:199-208` |
| `IdempotencyKey` middleware aliased + attached on 17 routes (8 required, 9 opt-in) | PASS | `Kernel.php:135` + `api.php:728/768/788/799/800/813/817/820/824/859/861/868/879/880/889/1131/1134` |
| Cron prune 04:00 outbox + 04:15 webhook cadence | PASS | `Kernel.php:100,116` |

Note: the brief said "17 routes registered". Primary evidence shows 17
**attachments** but only 8 patterns in `idempotency.required_routes`
(`config/idempotency.php:26-34`). The 9 additional routes accept the
header but do not reject when missing — opt-in semantics. Worth surfacing
to owner.

---

## 3. Listener verification (each guard, file:line)

| Listener | Guard kind | Line |
|---|---|---|
| `PersistOrderCreatedToOutbox` | `wasRecentlyCreated` early-return | `:57-59` |
| `PersistOrderStatusChangedToOutbox` | `wasRecentlyCreated` early-return | `:64-66` |
| `PersistOrderPaymentStatusChangedToOutbox` | `wasRecentlyCreated` early-return | `:69-71` |
| `PersistOrderTableChangedToOutbox` | `wasRecentlyCreated` early-return | `:85-87` |
| `PersistItemAvailabilityChangedToOutbox` | `wasRecentlyCreated` early-return | `:89-91` |
| `PersistItemExtraAvailabilityChangedToOutbox` | `wasRecentlyCreated` early-return | `:66-68` |
| `PersistItemVariationAvailabilityChangedToOutbox` | `wasRecentlyCreated` early-return | `:65-67` |
| `PersistSettingsUpdatedToOutbox` | `wasRecentlyCreated` per-branch collection | `:75-77` |
| `DispatchKdsTicket` | n/a — pure caller; dedup downstream in `PersistOrderStatusChangedToOutbox:64` | n/a |
| `RevokeTokensOnBranchDeactivated` | Transition guards (`oldStatus===newStatus` no-op + `new !== INACTIVE` no-op) | `:27-32` |

**Verdict:** 8/8 outbox writers carry the Wave Z 5C dedup pattern
(`Sprint 5C Z8-P1-01 2026-05-16` comment). The two non-outbox listeners
use the correct alternate primitive for their category — they are not
gaps. The brief's "other 4 to verify" framing conflates layers.

Layering: `DispatchKdsTicket` is a domain rule (KitchenReleaseRule)
that conditionally re-emits `OrderStatusChanged`. Idempotency for the
broadcast lives one hop downstream in `PersistOrderStatusChangedToOutbox`,
which dedups by `(event_type, order_id, old, new, correlation_id)` →
duplicate KDS fires in the same request collapse to one outbox row.

---

## 4. Weak spots (residue post Wave 2 heals)

### SYNC-W-01 — P3 — `Senangpay.php:59-63` reads via `$request->input()`
After Wave 2's POST-only route (`senangpay.php:21`), the access-log leak
of `hash` is closed. However the handler still uses
`$request->input('hash', '')` which Laravel resolves from EITHER
`$_POST` OR `$_GET` (and JSON body for application/json). If a
misconfigured nginx duplicates the body into the query string (some
reverse-proxy "preserve_query_args" configs do this), the hash can
re-enter the access log via the upstream URI. Pin source explicitly
with `$request->post('hash', '')`. Same applies to lines 59-62.
**Impact:** defence-in-depth only — Wave 2 closes the primary vector.

### SYNC-W-02 — P3 — Missing-secret path bypasses `webhook_events` ledger
`Stripe.php:199-208` returns HTTP 500 BEFORE `WebhookEvent::firstOrCreate`
when `STRIPE_WEBHOOK_SECRET` is empty. Stripe will retry up to 3 days,
which is correct delivery behaviour, but the forensic ledger has zero
trace of the misconfigured period — a future audit cannot answer "when
did the secret go missing?". Same pattern in `Senangpay.php:96-104`.
**Mitigation:** create a `webhook_events` row with
`status=failed, error_message='misconfigured'` before returning 500.
Reuses the existing UNIQUE(provider, webhook_id) — duplicate retries
collapse cleanly.

### SYNC-W-03 — P3 — Webhook DLQ retry asymmetry vs domain-event retry
`DispatchDomainEventsJob.php:40` uses `[1,5,15,60,300]s × 6 tries`
(381s window).
`ProcessWebhookEventJob.php:31,38` uses `[10,60,300]s × 3 tries` (370s).
After exhaustion, webhook DLQ relies ENTIRELY on the hourly cron
(`Kernel.php:75`) — there is no `failed()` handler equivalent to
`DispatchDomainEventsJob.php:165`. If the cron is masked (mutex stuck,
DST jump, single-server lock contention), failed rows accumulate without
pager signal. **Mitigation:** add a `ProcessWebhookEventJob::failed()`
that logs to `fiscal` channel + records `audit_logs` for symmetry
with the new Wave 2 audit_logs on manual replay.

### SYNC-W-04 — P3 — `KioskMachine` lookup in `channels.php:28` lacks scope assertion
`routes/channels.php:28` resolves the kiosk machine's branch via
`KioskMachine::where('user_id', $user->id)->first()`. If a future migration
moves `KioskMachine` under a global scope OR if a kiosk machine row is
re-assigned to a different branch (legitimate operation), the channel
auth callback could short-circuit incorrectly. Defensive: pin
`->withoutGlobalScopes()` if `KioskMachine` ever inherits `BranchScope`.
Cross-zone: validate with Zone 4 BranchScope/Auth auditor.

### SYNC-W-05 — P3 — `config/idempotency.php:25-34` lists 8 patterns; 9 additional routes attach the middleware without enforced requirement
The middleware is opt-in via `required_routes` (`IdempotencyKeyMiddleware.php:159-180`).
Routes outside the config list (e.g. `api.php:768,788,799,813,817,820,824,859,861`)
attach the alias but accept missing headers silently. This is intentional
backwards-compat for kiosk/mobile clients pre-rollout, but the asymmetry
is undocumented — adversarial clients can selectively omit headers on
the 9 opt-in routes. **Mitigation:** expand `required_routes` to cover
the full 17 once kiosk/mobile clients are confirmed to always emit the
header (Phase D V1.0.2 backlog).

---

## 5. Existing test coverage

| Concern | Test file |
|---|---|
| Listener replay dedup (8 outbox writers) | `tests/Feature/Outbox/ListenerReplayDedupeTest.php` + `tests/Feature/Sync/ListenerReplayGuardTest.php` |
| `firstOrCreate` + `wasRecentlyCreated` parity | `tests/Feature/Catalog/CatalogOutboxIdempotencyTest.php` |
| Concurrent worker dedup (Phase 1 atomic claim) | `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php` |
| `DispatchDomainEventsJob` failed callback (terminal log) | `tests/Feature/Queue/DispatchDomainEventsFailedCallbackTest.php` |
| After-commit dispatch + broadcast best-effort | `tests/Feature/AfterCommitDispatchTest.php` + `tests/Feature/Outbox/CatalogEventDispatchAfterCommitTest.php` |
| Outbox rescue (`attempts < 5` re-queue) | `tests/Feature/OutboxRescueTest.php` |
| Outbox retry-failed (`--since`) | `tests/Feature/Sync/OutboxRetryFailedScheduleTest.php` |
| Webhook DLQ retry-failed | `tests/Feature/Sync/WebhookDLQRetryFailedTest.php` |
| Webhook UNIQUE(provider, webhook_id) | `tests/Feature/Webhooks/WebhookEventIdempotencyTest.php` + `StripeWebhookIdempotencyTest.php` + `SenangpayWebhookIdempotencyTest.php` |
| Webhook route hardening (POST-only, throttle) | `tests/Feature/Webhooks/WebhookRouteHardeningTest.php` |
| Idempotency middleware (header parsing, branch resolution) | `tests/Feature/Idempotency/IdempotencyMiddlewareTest.php` + `tests/Feature/Sentinels/IdempotencyMiddlewareSentinelTest.php` |
| Idempotency branch-scoped key | `tests/Feature/Sentinels/IdempotencyRecoveryBranchScopedTest.php` |
| Branch deactivation token revoke | `tests/Feature/Branch/BranchDeactivationTokenRevokeTest.php` |
| Outbox pipeline health sentinel | `tests/Feature/Sentinels/OutboxPipelineHealthSentinelTest.php` |
| Production-like simulation (rush) | `tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php` + `tests/load/RushMidiSimulationTest.php` |
| Audit log on manual replay (Wave 2 heal) | `tests/Feature/Outbox/OutboxReplayAuditTest.php` |

---

## 6. Test coverage GAPS

1. **Prune commands** (`PruneOutboxCommand`, `PruneWebhookEventsCommand`)
   — no dedicated Feature test asserting the safe-set predicate
   (dispatched_at IS NOT NULL OR attempts >= 6 + cutoff) and the
   `status IN (processed, duplicate)` filter for webhook prune. A
   regression could accidentally include `pending` or `failed` rows.
2. **`ProcessWebhookEventJob::failed()` callback** — no test exists
   because no callback exists (SYNC-W-03 gap).
3. **Missing-secret forensic ledger** — no test asserts that
   `STRIPE_WEBHOOK_SECRET=''` records a `webhook_events` row (SYNC-W-02).
4. **`KioskMachine` channel auth cross-branch** — `routes/channels.php:28`
   not exercised in a multi-branch fixture; the only KioskMachine cross-branch
   test path is in BranchScope coverage (Zone 4).
5. **`DispatchKdsTicket.dispatch` invocation discipline** — no test
   asserts that the conditional re-emit (KitchenReleaseRule) does NOT
   double-fire when the listener is replayed (downstream
   `wasRecentlyCreated` absorbs it but symmetry is unproven by test).
6. **Idempotency middleware opt-in semantics** — no test asserts
   that the 9 opt-in routes (non-`required_routes`) STILL replay
   correctly when a header IS sent.
7. **`SettingsUpdated` fan-out per branch** — `PersistSettingsUpdatedToOutbox`
   creates N rows on N active branches; no test asserts that a partial
   collection (e.g. one branch's `firstOrCreate` raced into existing)
   still dispatches only the new ones (`:80-82` guard).

---

## 7. Wave 2 heal verification

### Heal 1 — Webhook route hardening (`f24b49c42`, P1 SYNC-RED-01 + 02)
- `stripe.php:26-28` — `Route::post('/stripe-webhook/', ...)`
  + `->middleware(['throttle:60,1'])`. SYNC-RED-01 closed.
- `senangpay.php:21-23` — `Route::post(...)` (was `Route::match(['get','post']...)`)
  + `->middleware(['throttle:60,1'])`. SYNC-RED-02 (GET hash leak) + SYNC-RED-01 closed.
- Coverage: `tests/Feature/Webhooks/WebhookRouteHardeningTest.php`.
- **Residue:** SYNC-W-01 (form-source pin) + SYNC-W-02 (missing-secret ledger) remain — P3, both new.

### Heal 2 — Outbox audit_logs on manual replay (`8dc6ec331`, P1 SYNC-RED-03)
- `OutboxRetryFailedCommand.php:27` — `app(AuditLogService::class)`
  + `:44-58` `auditLog->write(['action' => 'outbox.replay', ...])` per event.
- `OutboxWebhookRetryFailedCommand.php:52` — same pattern;
  `:71-85` writes `audit_logs` with `branch_id=0` (system/CLI per
  contract since `webhook_events` is tenant-agnostic).
- Coverage: `tests/Feature/Outbox/OutboxReplayAuditTest.php`.
- **Verification:** SYNC-RED-03 (forensic-trail gap on manual replay) closed.
- The NF525 chain remains append-only; both replay actions append to the
  existing chain, do not modify prior rows.

---

## 8. Recommendations

**P1 (V1.0.1 sealing):**
- Add `ProcessWebhookEventJob::failed()` mirror of
  `DispatchDomainEventsJob.php:165-186` (SYNC-W-03). Symmetry +
  pager-grade terminal-failure signal. Low risk, isolated to one job.

**P2 (V1.0.2 backlog):**
- Promote 9 opt-in idempotency routes to `required_routes` once kiosk
  and mobile clients are confirmed header-emitting (SYNC-W-05).
  Coordinate with Zone 3 KDS sync auditor + mobile maintainer.
- Add `webhook_events` ledger row on missing-secret path (SYNC-W-02).

**P3 (forensic discipline):**
- Pin `$request->post('hash', '')` in `Senangpay.php:59-63` for source
  source-of-truth (SYNC-W-01) — defence-in-depth post POST-only.
- Add `->withoutGlobalScopes()` belt-and-suspenders on
  `channels.php:28` KioskMachine lookup (SYNC-W-04) — preventive only,
  no current exploit path.

**Test gaps to close:**
- Prune commands: assert safe-set predicate (gap #1).
- Webhook DLQ failed callback (gap #2 — depends on SYNC-W-03 implementation).
- Settings fan-out partial: assert
  `PersistSettingsUpdatedToOutbox.php:80-82` early-return on empty (gap #7).

**Non-actions (already correct):**
- `DispatchKdsTicket` does NOT need `wasRecentlyCreated` — downstream
  `PersistOrderStatusChangedToOutbox:64` absorbs duplicates by
  correlation_id.
- `RevokeTokensOnBranchDeactivated.php:27-31` transition guards are
  the correct primitive for an idempotent side-effect (not an outbox
  writer); `wasRecentlyCreated` would be a category error.
- `DispatchDomainEventsJob` backoff curve `[1,5,15,60,300]s × 6 tries`
  matches the documented intent (381s worst-case window outlasts a
  typical Soketi restart). No change required.

**Frozen-zone respect:** `IdempotencyKeyMiddleware.php` audited only.
No recommendations require touching it; SYNC-W-05 is a config-level
change (`config/idempotency.php`).

---

GStack Architect — Sync — Wave 1 (resumed)
