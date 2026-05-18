# F-3 SYNC — Foundation Audit Round 1 (Read-Only)

**Zone**: Sync infrastructure (Outbox + Pusher + Polling + Webhooks + Idempotency)
**Date**: 2026-05-18
**Mode**: READ-ONLY, adversarial RED-team enabled
**Wall-clock**: ~30 min
**Specialist reports**: `architect.json` (5 findings), `sre.json` (6 findings), `security.json` (6 findings), `red-team.json` (7 scenarios)

---

## Verdict at a Glance

**Status**: GREEN with 2 P0 callouts to clarify with owner.
The Sync foundation is among the most-hardened zones in the V1.0.1 stack. Session-A heals (Wave 2c Cache::lock, Wave 3 write-then-dispatch ordering, Wave 3b/3c cadence cap + LOCK_TTL) have closed every race condition the RED-team probed. The remaining surface is operational (DLQ retention, polling-fallback transparency) and 2 specific deploy-time risks the owner alone can resolve.

| Category | Count |
|---|---|
| P0 findings | 2 (CORS mismatch on /api/broadcasting/auth, depends on prod env) |
| P1 findings | 6 |
| P2 findings | 9 |
| P3 findings | 6 |
| DEAD-CODE | 1 (`ComposerProfilePublished` event) |
| SAFE-TO-CONSOLIDATE | 3 |
| KEEP-AS-IS | 6 distinct hardening patterns |

---

## DEAD-CODE (1 item)

### D-1 — `ComposerProfilePublished` event has no listener mapping
- **File**: `app/Events/ComposerProfilePublished.php`
- **Evidence**: `EventServiceProvider::shouldDiscoverEvents()` returns false (`app/Providers/EventServiceProvider.php:271`); event class is NOT a key in the `$listen` array (lines 90-251); dispatched at `app/Services/Composer/ComposerProfileService.php:168` immediately followed by `ComposerProfileChanged::dispatch(..., 'published')` on line 169 which IS mapped to `InvalidateKioskMenuCacheOnCatalogChange` + `PersistCatalogChangedToOutbox`.
- **Impact**: Event fires into the void. No production behavior depends on it.
- **Action**: SAFE-TO-DELETE — drop the dispatch at line 168 and the Event class file. The Changed sibling carries the published-vs-updated signal via its `changeType` parameter.

---

## SAFE-TO-CONSOLIDATE (3 items, V1.0.2 backlog)

### C-1 — Cron command trio (DRY)
- `OutboxRescueCommand` + `OutboxRetryFailedCommand` + `OutboxWebhookRetryFailedCommand` share ~80% of structure (Cache::lock + BATCH_CAP + audit_log + dispatch).
- **DEFER**: 2 of the 3 are session-A DIRTY. No change recommended until session-A merges.

### C-2 — CatalogChanged.fromMenuMutation adapter
- Has 9 `instanceof` type-checks (`CatalogChanged.php:42-114`). A Match expression or a registry would shrink it.
- **Cosmetic only.** No defect.

### C-3 — Listener idempotency-key convention
- Two patterns coexist: one-shot (`sha1(EventType|aggregate_id)`, e.g. `PersistOrderCreatedToOutbox:22`) and replayable (`sha1(EventType|entityType|entityId|branchId|changeType|correlationId)`, e.g. `PersistCatalogChangedToOutbox:59-66`).
- Correct per domain, but undocumented. Consolidate into a single `docs/sync-listener-keys.md` or inline comment.

---

## KEEP-AS-IS (Session-A heals confirmed correct)

1. **Two-phase claim + dispatch** in `DispatchDomainEventsJob` (lockForUpdate -> commit -> broadcast outside tx) — closes double-broadcast race (`app/Jobs/DispatchDomainEventsJob.php:60-94`)
2. **Cache::lock + LOCK_TTL=300s + BATCH_CAP=500** in both retry-failed commands — closes cron-vs-manual double-replay (`OutboxRetryFailedCommand:42-46`, `OutboxWebhookRetryFailedCommand:62-66`)
3. **Write-then-dispatch audit ordering** — audit_log row exists IFF dispatch attempted (`OutboxRetryFailedCommand:94-116`)
4. **Cadence cap [250ms, 60_000ms] + jitter [0, 30_000ms]** across KDS/POS/OSS sync services — closes silent-blind misconfig DOS (`KdsSyncService:25-26`, `PosSyncService:52-53`, `OssSyncService:34-35`)
5. **Backoff curve [1, 5, 15, 60, 300]s + tries=6 = 381s worst-case** — outlasts typical Pusher restart (`DispatchDomainEventsJob:40-42`)
6. **Channel-auth token-name discriminator + admin role check** — closes Sanctum '*' wildcard bypass AND guest-echo-bypass (`routes/channels.php:36-58`)
7. **ws:heartbeat cache write on successful broadcast** — closes the green-dashboard-while-Pusher-dead defect (`DispatchDomainEventsJob:127-131`)

The BRAIN flag "Pusher channel-auth observably broken via Sanctum wildcard" is **STALE** — the fix is in code per `routes/channels.php:36-58` (GOAL-CMS S-R3-P0-G heal). Do not re-raise.

---

## RECOMMENDATIONS (Prioritized)

### P0 — Must answer / verify with owner
1. **CORS mismatch on /api/broadcasting/auth** — `localhost` vs `127.0.0.1` host alias silently breaks channel-auth; polling fallback masks it. Evidence: `sync-findings.json` flow-6 consoleErrors. **Action**: audit `config/cors.php` allowed_origins; add a sentinel test asserting `/api/broadcasting/auth` returns CORS-compatible headers for the configured kiosk host pattern.
2. **Idempotency middleware default-off** — `config/idempotency.php:21` defaults `enabled=false`. If production .env has not set `IDEMPOTENCY_MIDDLEWARE_ENABLED=true`, the 10 new C-P0-H heal routes (cash-drawer, refund-with-counter-entry, change-status) silently bypass the middleware. **Action**: verify production env value; add to deploy checklist; consider failing /api/health/ready when middleware is off in APP_ENV=production.

### P1 — Hardening
3. **Stripe replay tolerance** — `Stripe::handleWebhook` does not pass a tolerance parameter to `Webhook::constructEvent`. Replay attack window extends until WebhookEvent row pruned (180d). **Action**: add 300s tolerance: `Webhook::constructEvent($payload, $sigHeader, $secret, 300)`.
4. **PayloadMismatchException retry loop** — DispatchDomainEventsJob retries 6 times on contract violations (`tries=6`, backoff to 300s). Each retry is 1 'high' queue message; 1000 bad payloads = 6000 messages saturating the high lane. **Action**: special-case PayloadMismatchException to skip retries (use `$this->fail($e)` once).
5. **OSS production polling cadence** — `intervalMsWhenDisconnected=2000ms` (`OssSyncService:14-17`) — 0.5 req/s/screen if WS down. **Action**: verify production OSS load behavior under Soketi outage; consider raising to 5000ms and relying on visibility-burst-poll.
6. **SenangPay gateway lookup before signature verify** — 2 DB queries (`PaymentGateway::with('gatewayOptions')`) per webhook before signature check. **Action**: cache the secret via `Cache::remember('senangpay.secret', 3600, ...)`.
7. **DLQ horizon vs prune horizon gap** — webhook_events `failed` rows older than 24h are NOT auto-retried, but persist for 180d (`OutboxWebhookRetryFailedCommand:40` cutoff vs `PruneWebhookEventsCommand` retention). 179-day silent operational risk. **Action**: add weekly `--since=168h` sweep OR widen monitor alert.
8. **Reconnect-storm jitter window** — KDS uses 500ms uniform jitter on storm (`KdsSyncService:255-273`). 100 stations / 500ms = 200 req/s peak. **Action**: raise to 2000ms uniform, propagate the same pattern to POS/OSS.

### P2 — Sentinels + docs
9. **OrderCreated listener order enforced only by PHP array index** — add `tests/Feature/Sync/OrderCreatedListenerOrderTest` asserting `PersistOrderCreatedToOutbox::class` is at index 0 (`EventServiceProvider:145-150`). Same for `OrderStatusChanged` (lines 138-143).
10. **Document polling-fallback hint UI** — `config/broadcasting.php:33` defines `polling_fallback.hint_when_off` but no Vue component subscribes. Wire a small banner on OSS/KDS/POS visible after 30s of polling fallback (data already available at `OssSyncService:175`).
11. **PaymentStatus enum equality fragility** — `PersistOrderCreatedToOutbox:37` uses `(int) $order->payment_status === PaymentStatus::PENDING_COUNTER`. Replace with enum equality to avoid the int-cast trap.
12. **Verify ProcessWebhookEventJob queue lane** — out-of-scope confirm only. If lane != 'high', document worker boot convention `queue:work --queue=high,default`.

### P3 — Cosmetic
13. **Broadcast::routes per-IP throttle** — `BroadcastServiceProvider:22` has no throttle. Add `'throttle:60,1'` as defense-in-depth.

---

## User-Friendly Questions for Owner

1. **CORS host alignment** — What is the exact `APP_URL` value in production, and does the in-store kiosk/POS/KDS browser hit the exact same host (FQDN, IP, or alias)? If they differ, real-time push has silently failed since the SPA was deployed and only polling has worked. This is the highest-priority P0.

2. **Idempotency middleware production env** — Is `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` set in production .env? The config default is `false`. If the middleware is off, the 10 new heal routes (cash-drawer, refund, change-status) rely solely on app-layer DB UNIQUE — which only exists for orders, NOT for cash-drawer-open or status changes.

3. **Idempotency fail-open mode** — What is the production value of `IDEMPOTENCY_FAIL_OPEN`? If it's `true` (chosen for availability), the 10 new heal routes need an inventory of app-layer UNIQUE backstops. Many do NOT have them and a Redis outage + concurrent retry would double-execute.

4. **Stripe webhook secret in production** — Is `config('services.stripe.webhook_secret')` set in production? The handler returns 500 'misconfigured' otherwise, and Stripe retries on 5xx — the misconfig surfaces in Stripe Dashboard but not in our health-check.

5. **ComposerProfilePublished observability** — Should publication-vs-update be observable separately (e.g. analytics on first-publish, notify-on-launch hook)? If no, we can drop the event entirely. Currently it fires into the void.

6. **OSS production polling cadence** — Has the 2000ms `intervalMsWhenDisconnected` fallback ever fired in production? The comment says "production still uses Echo/Pusher live so this fallback is essentially unused there" — but that is an unverified assumption. If Soketi outage occurs in production, 10 OSS screens = 5 req/s on `/api/order-status-screen/orders`.

7. **Webhook DLQ retention horizon** — What is the operational target? V1 single-restaurant 24h is the current autonomous retry window. Anything older needs a human via staleness monitor. Is that acceptable?

8. **Polling-fallback hint UI** — Has the `broadcasting.polling_fallback.hint_when_off` UI banner been wired anywhere? Search returned no matches in Vue components. Production OSS users currently see lag but no UI indication.

---

## DIRTY Files Cited (Session-A WIP — NOT TOUCHED)

| File | Why dirty | Cited findings |
|---|---|---|
| `app/Console/Commands/OutboxRetryFailedCommand.php` | Wave 3 / Wave 2c WIP | ARCH-2, SRE-3, SRE-5, RED-5 |
| `app/Console/Commands/OutboxWebhookRetryFailedCommand.php` | Wave 3 / Wave 2c WIP | SRE-3, SRE-5, RED-5 |
| `tests/Feature/Outbox/OutboxReplayAuditTest.php` | session-A WIP | (no findings; mentioned in scope only) |

## FROZEN Files Cited (NF525 / Multi-tenant — NOT TOUCHED)

| File | Why frozen | Cited findings |
|---|---|---|
| `app/Http/Middleware/IdempotencyKeyMiddleware.php` | Frozen per scope | SEC-4, SEC-5 |

---

## Files Inspected (Read-only)

- `app/Models/DomainEvent.php`
- `app/Models/WebhookEvent.php`
- `app/Providers/EventServiceProvider.php`
- `app/Providers/BroadcastServiceProvider.php`
- `app/Jobs/DispatchDomainEventsJob.php`
- `app/Listeners/PersistOrderCreatedToOutbox.php`
- `app/Listeners/PersistCatalogChangedToOutbox.php`
- `app/Services/Composer/ComposerProfileService.php` (lines 160-185)
- `app/Http/PaymentGateways/Gateways/Stripe.php` (webhook handler lines 160-279)
- `app/Http/PaymentGateways/Gateways/Senangpay.php` (lines 1-200)
- `app/Http/Middleware/IdempotencyKeyMiddleware.php`
- `app/Events/CatalogChanged.php`
- `app/Console/Commands/OutboxRetryFailedCommand.php` (DIRTY, read-only)
- `app/Console/Commands/OutboxWebhookRetryFailedCommand.php` (DIRTY, read-only)
- `app/Console/Commands/OutboxRescueCommand.php`
- `app/Console/Kernel.php` (lines 37-120)
- `config/broadcasting.php`
- `config/idempotency.php`
- `config/queue.php`
- `routes/channels.php`
- `resources/js/services/KdsSyncService.js`
- `resources/js/services/PosSyncService.js`
- `resources/js/services/OssSyncService.js`
- `reports/test-e2e/goal-pageby-2026-05-18/round-1/SYNC/sync-findings.json`

## Cross-References

- `architect.json` — structural integrity, dead listeners, listener idempotency keys
- `sre.json` — cadence drift, retry curves, cron coherence, polling fallback transparency
- `security.json` — channel-auth, webhook signatures, idempotency UNIQUE, CORS
- `red-team.json` — replay attacks, payload tampering, queue starvation, race conditions
