# Audit SYNC (read-only, adverse) — HEAD `9fba7b8f`

Cible : Events, Jobs (`DispatchDomainEventsJob` onQueue `high`), outbox, `AvailabilityService`, `KitchenReleaseRule`, `KdsSyncService`, `Kernel.php`, configs, `SYNC_CONTRACT.md`. Méthode : grep/Read, chaque finding prouvé file:line sinon rejeté (CLAUDE §3ter).

## Verdict
Couche sync **production-grade**. Anti-doublage, dégradation sans perte, scheduler et dead-man **tous vérifiés sains**. **0 P0, 0 P1** survivant à la vérification. 3 P2 (doc/observabilité).

## Ce qui est PROUVÉ sain (points 1-4 du mandat)

**1. Faits métier — émetteur → consommateurs → fallback.** `OrderCreated` / `OrderStatusChanged` / `KdsOrderRecalled` = events plain → `Persist*ToOutbox` écrit `domain_events` → `DispatchDomainEventsJob->broadcast()` sur `private-branch.{id}`. 86/stock/catalogue diffusent aussi (`ItemAvailabilityChanged`, `ItemExtra/Variation`, `CatalogChanged`, `Settings`, `Coupon`, `BranchStatus`, `OrderTable`, `OrderPaidAtCounter`, `OrderPaymentStatusChanged` — EventServiceProvider.php:155-343). Le 86 ingrédient propage bien (`InvalidateMenuProjectionOnIngredientChange.php:40` redispatch `CatalogChanged` → outbox). Fallback poll : KDS 5s/60s (`KitchenDisplaySystemComponent.vue:2422`), mur OSS public 5s (`PreparingAndReadyComponent.vue:266-270`), kiosk 15s, POS 30s. `OrderCanceled` n'a pas de persister mais est **toujours** dispatché en paire avec `OrderStatusChanged(→CANCELED)` (OrderService.php:2294+2303, PaymentService.php:856-857, CleanupStale:337+340) ; à défaut, `KdsSyncService::sync` renvoie l'id dans `deleted_ids` (statuts inactifs, l.51) au poll suivant.

**2. Anti-doublage — aucun trou.** `OrderCreated` : `idempotency_key=sha1(type|aggregate_id)` + index UNIQUE + garde `wasRecentlyCreated` (PersistOrderCreatedToOutbox.php:23,58). `OrderStatusChanged` : clé inclut `correlation_id` (revert légal = ligne fraîche, dup intra-requête collapse — PersistOrderStatusChangedToOutbox.php:27-33). `DispatchDomainEventsJob` : claim atomique `lockForUpdate`+`dispatched_at` (l.65-86) → jamais 2 broadcasts. Décrément stock : garde SETNX `Cache::add('availability:decremented:{fe|o}:{branch}:{id}')` (AvailabilityService.php:487-499). Release : ledger `released_qty` idempotent (l.905-919). Webhook : `webhook_events` UNIQUE + court-circuit `STATUS_PROCESSED` (ProcessWebhookEventJob.php:58). **Numéro de commande = 100% serveur** : `order_serial_no=date('dmy').$id` (OrderService.php:596), `queue_number=allocateQueueNumber()`, `fiscal_sequence_no=MAX+1` sous lock — **rien de client-généré**.

**3. Robustesse.** Worker down → 3 lanes de rattrapage (`outbox:rescue` /min attempts<5 lanes A pending + B crash-claimed 10min, `outbox:monitor` /min pageable 3 dimensions, `retry-failed` horaire attempts≥5 — Kernel.php:40-88 ; OutboxRescueCommand.php:34-64 ; MonitorOutboxStaleness.php:50-95). Pusher `timeout=5s/connect=3s` (broadcasting.php:71-72) → black-hole THROW (pas de « clean orphan » qui pend). Soketi down → bannière polling visible (SYNC_CONTRACT §7). **Scheduler VPS** : cron installé par `server-setup-hetzner.sh:194-197`, lanes présentes Kernel.php:22-537, **dead-man** `scheduler:last_tick` >10min → `/api/health/ready` 503 en prod (HealthController.php:59-64,168-183) → UptimeRobot alerte. Confirmé « réparé 27/07 » (commit `93c6d092e`).

**4. Cohérence statuts.** SSOT unique `KitchenReleaseRule` : `visibleStatuses=[ACCEPT,PREPARING,PREPARED]` == `KdsSyncService::activeStatuses` (l.50) ; `applyBoardReleaseFilter` partagé list()/sync()/guard bump (l.130) ; programmées `applyScheduledBoardFilter` miroir SQL↔booléen (l.187-234). Pas de divergence KDS/OSS/caisse.

## Findings (tous P2)

**P2-1 — `SYNC_CONTRACT.md` sous-documente le bus.** §3 (l.16-23) liste **4** events broadcast ; le code en diffuse **~13** (EventServiceProvider.php:238-343). En-tête figé « HEAD d6487f716 » (l.3). Un agent-lane sync sous-modélise la surface (croit que 86/catalogue/settings ne passent pas par l'outbox). Corrigé par la session S3 sur worktree `s3-sync-2026-07-29` (commit `e3cc5bbf2`) mais **NON mergé dans ce HEAD** (`git merge-base --is-ancestor` = NOT in HEAD). Repro : `diff` §3 vs providers.

**P2-2 — version-gate KDS à la seconde.** `computeOrderVersion = updated_at` en **secondes** (KdsSyncService.php:209 ; TODO l.196-201 en attente de `status_changed_at`). Deux transitions dans la même seconde → version identique → le version-gate client peut ignorer la 2ᵉ carte au poll delta. Mitigé (push WS porte l'event ; `/list` 60s corrige). Repro : bump ACCEPT→PREPARING→PREPARED scripté <1s puis poll delta.

**P2-3 — log scheduler jeté.** `server-setup-hetzner.sh:194` redirige `schedule:run` vers `/dev/null 2>&1` alors que `CRONTAB_PROD.md:35` prescrit `>> /var/log/lecayenne/schedule.log`. Une lane qui échoue ne laisse **aucune trace on-box** (le dead-man/UptimeRobot reste le seul filet). Repro : lecture des 2 fichiers.

## Comptes
P0 = 0 · P1 = 0 · P2 = 3 · rejetés (non prouvés / mitigés) : ingredient-no-broadcast (FAUX, l.40), double-broadcast Phase-3a (broadcasts advisory), clean-orphan (fermé par timeout Pusher), OrderCanceled non-broadcast (paire OrderStatusChanged + deleted_ids).
