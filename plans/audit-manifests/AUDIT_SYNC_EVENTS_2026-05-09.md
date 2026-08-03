# AUDIT MANIFEST — Synchronisation Cross-Surface (Events + Outbox + Broadcast)
**Date** : 2026-05-09
**Scope** : Sync POS↔Kiosk↔KDS↔Admin↔OSS — domain_events outbox, Pusher/Echo, polling fallback, listener idempotency
**Branch** : `review/audit-sync-events`
**Cible** : `/ultrareview <this-PR>` doit auditer tout le périmètre listé ci-dessous

---

## §1 — Files in scope

### Outbox + Domain Events
- `app/Models/DomainEvent.php` (post-iter14 idempotency_key UNIQUE)
- `app/Jobs/DispatchDomainEventsJob.php` (queue=high, retries 6×, backoff [1,5,15,60,300]s)
- `database/migrations/2026_04_15_200000_create_domain_events_table.php`
- `database/migrations/2026_05_09_180000_add_idempotency_key_to_domain_events.php` (iter14)
- `app/Console/Commands/OutboxRescueCommand.php`
- `app/Console/Commands/OutboxMonitorCommand.php`

### Listeners outbox (4 refactored iter14 + 6 restants)
- `app/Listeners/PersistOrderCreatedToOutbox.php` ✅ iter14 firstOrCreate
- `app/Listeners/PersistOrderStatusChangedToOutbox.php` ✅ iter14 firstOrCreate
- `app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php` ✅ iter14 firstOrCreate
- `app/Listeners/PersistOrderPaidAtCounterToOutbox.php` ✅ iter14 firstOrCreate
- `app/Listeners/PersistOrderTableChangedToOutbox.php` ⏳ V1.0.1 reste
- `app/Listeners/PersistCatalogChangedToOutbox.php` ⏳
- `app/Listeners/PersistCouponChangedToOutbox.php` ⏳
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` ⏳
- `app/Listeners/PersistItemExtraAvailabilityChangedToOutbox.php` ⏳
- `app/Listeners/PersistItemVariationAvailabilityChangedToOutbox.php` ⏳

### Stock + Availability listeners (sync-related)
- `app/Listeners/DecrementStockOnOrderCreated.php` (iter12 escalation)
- `app/Listeners/ReleaseStockOnOrderCanceled.php` (iter13 escalation)
- `app/Listeners/ReleaseStockOnRefundCreated.php`
- `app/Listeners/DecrementItemAvailabilityOnOrder.php`
- `app/Listeners/ReleaseAvailabilityOnOrderCanceled.php`
- `app/Listeners/ReleaseAvailabilityOnRefundCreated.php`

### Events
- `app/Events/OrderCreated.php`
- `app/Events/OrderStatusChanged.php`
- `app/Events/OrderPaymentStatusChanged.php`
- `app/Events/OrderPaidAtCounter.php`
- `app/Events/OrderCanceled.php`
- `app/Events/OrderTableChanged.php`
- `app/Events/ItemAvailabilityChanged.php`
- `app/Events/CatalogChanged.php`
- `app/Events/CouponChanged.php`
- `app/Events/StockLevelChanged.php`
- `app/Events/RefundCreated.php`
- `app/Providers/EventServiceProvider.php` (mapping events → listeners)

### Broadcasting + Echo
- `routes/channels.php` (private-branch.{id} auth)
- `config/broadcasting.php`
- `resources/js/echo.js`
- `resources/js/services/kdsSyncService.js`
- `resources/js/composables/useEventContract.js` (si existe)
- `resources/js/composables/useOnEvents.js`

### Webhook integrations (iter11 webhook_events unifié)
- `app/Models/WebhookEvent.php`
- `database/migrations/2026_05_09_120000_create_webhook_events_table.php`
- `app/Http/PaymentGateways/Gateways/Stripe.php`
- `app/Http/PaymentGateways/Gateways/Senangpay.php` (à créer per iter11 plan)
- `app/Http/PaymentGateways/Gateways/Credit.php`
- `tests/Feature/Webhooks/WebhookEventIdempotencyTest.php`

### Tests sync existants
- `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php`
- `tests/Feature/Outbox/OutboxDeliveryTest.php`
- `tests/Feature/Outbox/OutboxRescueTest.php`
- `tests/Feature/Outbox/OutboxPipelineHealthSentinelTest.php`
- `tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php`
- `tests/Feature/Outbox/CatalogEventDispatchAfterCommitTest.php`
- `tests/Feature/Sentinels/AfterCommitDispatchTest.php`
- `tests/Feature/Sentinels/EventContractTest.php`
- `tests/Feature/Sync/PusherAckTest.php`
- `tests/Feature/Pos/ChangePaymentStatusOutboxTest.php`

---

## §2 — Invariants à vérifier

1. **Outbox atomicity** — DispatchDomainEventsJob lockForUpdate + dispatched_at guard prevents double-broadcast
2. **Backoff curve** — [1, 5, 15, 60, 300]s sur 6 tries = 381s window outlasts Pusher restart (1-3min)
3. **Idempotency listener** — firstOrCreate sur (provider, webhook_id) ou (event_type, aggregate_id, [old, new], correlation_id)
4. **Branch-scoped channels** — `private-branch.{branchId}` auth via routes/channels.php (iter11 admin bypass + kiosk:order branch match + staff own branch)
5. **Polling fallback KDS 5s** — auto-bascule si Echo down (banner "Mode secours actif")
6. **No commit-before-broadcast** — broadcast happens AFTER DB commit (afterCommit hook)
7. **Cross-driver MySQL/SQLite** — UNIQUE index sur idempotency_key + handlers compat
8. **Production guards** — AppServiceProvider throws si BROADCAST_DRIVER null/unset
9. **Queue worker requirement** — `php artisan queue:work --queue=high --queue=notifications` doit être running prod (sinon outbox rows piling up)
10. **Webhook dedupe** — webhook_events UNIQUE (provider, webhook_id) iter11

---

## §3 — Questions critiques

### Outbox pipeline
- DomainEvent lifecycle : pending → dispatched_at set → broadcast → success/fail. Tracé complètement ?
- Si listener fire 2× (transient retry) : firstOrCreate empêche double row (iter14) — mais 6 listeners restants V1.0.1 sans pattern ?
- Outbox monitor cron : alerte si > 10 rows pending depuis > 30s ?
- Outbox rescue cron : re-queue events stales (dispatched_at NULL > 60s) ?

### Broadcast resilience
- Pusher placeholder credentials prod : bascule polling 30s OK pour V1 ?
- Soketi ou Pusher prod : si down 1-3min, backoff 381s window suffisant ?
- Race condition broadcast : 2 listeners fire pour même event → double dispatch via DispatchDomainEventsJob ?
- Channel auth : kiosk token branch mismatch → 403 ?

### Frontend dedup
- Frontend correlation_id dedup cache (V1.0.1 backlog) — actuellement pas implémenté, risk dupli broadcasts ?
- Echo subscribe : KDS écoute `private-branch.{id}` pour 4 event types — debounced refresh ?
- Admin polling 60s vs Echo : adaptive 10s si WS down (V1.0.1 reco) ?

### Webhook idempotency
- SenangPay handler controller (iter11 stub model) : pas encore implémenté ? Sécurité TODO ?
- Stripe webhook idempotency : token-based legacy + UNIQUE webhook_events new — coexistence safe ?

---

## §4 — Acceptance criteria

CLEAN si :
- ✅ Outbox dispatch atomic (lockForUpdate + dispatched_at guard)
- ✅ 4 listeners outbox iter14 firstOrCreate verified
- ✅ Backoff curve correct (6 tries / 381s)
- ✅ Channels.php auth strict (admin / kiosk / staff)
- ✅ Polling fallback KDS 5s active
- ✅ Tests `Outbox*` passent (OutboxConcurrentWorkerDedupe + OutboxDelivery + OutboxRescue + OutboxPipelineHealthSentinel + OutboxProductionLikeSimulation + CatalogEventDispatchAfterCommit)
- ✅ Sentinel `AfterCommitDispatchTest` + `EventContractTest` verts
- ✅ Production guards trigger si BROADCAST_DRIVER null

HEAL si :
- ⚠️ 6 listeners restants sans firstOrCreate pattern (V1.0.1 sprint)
- ⚠️ Frontend correlation_id dedup absent (V1.0.1)
- ⚠️ Admin polling 60s pas adaptive (V1.0.1)
- ⚠️ SenangPay handler stub pas implémenté (TODO post creds)

BLOCK si :
- ❌ Outbox dispatch non-atomic (race condition possible)
- ❌ Branch isolation channels broken (cross-branch broadcast)
- ❌ Webhook double-charge possible (idempotency UNIQUE bypassed)

---

## §5 — Out of scope

- Implémentation réelle controllers POS/Kiosk (cf manifests dédiés)
- Stock business logic (cf `AUDIT_TRACKING_STOCK_2026-05-09.md`)
- Multi-tenant Sanctum/BranchScope general (cf `AUDIT_GLOBAL_CROSS_SYSTEM`)
- Admin dashboards
- E2E user flows (focus sync infra ici)

---

## §6 — Reference

CLAUDE.md §4 (Architecture exécution + Sub-agents), §11 (Memory)
PROJECT_BRAIN.md §7 #1 Architecture event-driven validated
ULTRA-AUDIT-SYNC-EVENTS iter13 (cf `plans/MASTER_ITER13_HARDENING_AUDIT_2026-05-09.md`)

— *Manifest pour `/ultrareview review/audit-sync-events`. Audit synchronisation système.*
