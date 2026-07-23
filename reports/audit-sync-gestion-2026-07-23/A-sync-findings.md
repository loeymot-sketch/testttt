# Dimension A — SYNCHRONISATION TEMPS-RÉEL cross-surface — findings

Auditeur adversaire READ-ONLY. HEAD `b8084b3107`, branche `pos/category-first-caisse-2026-06-23`.
Env LOCAL réel : `:8000` (PID 24108) + `:8766`, DB **`foodking_e2e`** (mysql), worker `queue:work redis --queue=high,default,low` **UP** (PID 46735), soketi `:6001` UP, `BROADCAST_DRIVER=pusher`, `CACHE/QUEUE=redis`.
Discipline : chaque finding reproduit réellement ; sinon supprimé. **2 findings candidats RÉFUTÉS** par la repro (documentés plus bas).

## Tableau de sévérité
| Sévérité | Count | Résumé |
|---|---|---|
| P0 | 0 | — |
| **P1** | **1** | **Toggle 86 manuel → 500 (colonne `manual_unavailable_since` absente / migration Pending) → pilier Dispo/86 MORT dans l'env courant** |
| P2 | 0 | — |
| P3 | 3 | Alarme outbox log-only ; reason `stock_rupture` fantôme sur ligne dispo ; double-broadcast (refetch redondant) |
| RÉFUTÉ | 2 | KDS board « 412 » (fenêtre 8h existe → 22) ; `isWebPending` sans garde paiement (serveur gate UNPAID) |
| PROUVÉ SAIN | 8 | Voir §Preuves |

---

## P1 — Toggle 86 manuel émet 0 event : `AvailabilityService::toggle()` écrit une colonne absente de la DB
- **file:line** : `app/Services/Menu/AvailabilityService.php:95,104,143` (écrit `manual_unavailable_since`) — migration `database/migrations/2026_07_23_100000_add_manual_unavailable_since_to_item_branch_availability.php` = **Pending**.
- **Repro exacte** (tinker, service réel) :
  `app(AvailabilityService::class)->toggle(2, 1, false, 'stock_rupture')`
  →  `SQLSTATE[42S22]: Unknown column 'manual_unavailable_since' in 'field list'` (AvailabilityService.php:144, `Model::save()`).
- **Evidence** :
  - `php artisan migrate:status` → `2026_07_23_100000_...manual_unavailable_since ... Pending` **alors que** `2026_07_23_140000` (batch 22) et `2026_07_23_160000` (batch 23), timestamps POSTÉRIEURS, sont **Ran** → insertion de migration hors-ordre.
  - `Schema::getColumnListing('item_branch_availability')` = `branch_id, created_at, daily_consumed_qty, daily_reset_at, id, is_available, item_id, max_daily_qty, unavailable_reason, unavailable_since, updated_at` → **pas** de `manual_unavailable_since`.
  - Appelants HTTP du chemin cassé : `AvailabilityController.php:94` (`POST /menu/availability/toggle`, routes/api.php:289), `Mobile/MobileStockController.php:131` (app **/m PIN 2580**), `Admin/IngredientController.php:65`.
- **Impact sync** : `toggle()` jette AVANT `dispatchEvent()` (AvailabilityService.php:146,557) → **aucun** `ItemAvailabilityChanged`, **aucune** ligne outbox, **aucun** broadcast Echo → la rupture ne se propage à AUCUNE des 4 surfaces (borne/caisse/KDS//m). Le pilier « Disponibilité/86 » (scénario #1) est mort dans l'environnement tel qu'il tourne. Le code sync est correct ; c'est un **drift de schéma** (code HEAD en avance sur la DB).
- **Repro négative de contrôle** : `event(ItemAvailabilityChanged::forBranch(2,1,false,'stock_rupture'))` en direct (contourne l'écriture) → 2 lignes outbox créées + dispatchées par le worker en <4 s (voir Preuve T1) → la panne est bien localisée à l'écriture DB, pas au pipeline.
- **Reco** : `php artisan migrate` (env dev/staging/prod) ; ratifier l'ordre de migration (timestamp `100000` < `140000`/`160000` déjà passés — inoffensif pour `migrate --force` qui rejoue tout Pending, mais à surveiller). Optionnel défensif : `Schema::hasColumn` guard OU rendre le toggle tolérant si la colonne manque. **PAS de correctif code requis** — action = migrer.

---

## P3 — Staleness outbox détectée mais jamais escaladée à un humain (alarme log-only)
- **file:line** : `app/Console/Commands/MonitorOutboxStaleness.php` — `Log::error` + exit non-zéro **uniquement** (aucun `Mail::`/`Notification`).
- **Repro/Evidence** : grep source → `Log::error present: YES, Mail/Notification: no (log-only)`. Confirme SYNC_CONTRACT §7 (gap connu). `OutboxBroadcastSwallowedEvent`/`EscalateOutboxBroadcastSwallowed` existent mais l'escalade terminale reste un log.
- **Impact** : un worker mort / broadcast avalé est *détecté* mais aucun canal externe ne réveille l'humain → latence de découverte = prochaine inspection manuelle. (Contexte V1 mono-poste : faible, mais réel.)
- **Reco** : câbler un canal (mail/SMS/webhook) sur `MonitorOutboxStaleness` + `EscalateOutboxBroadcastSwallowed`. Backlog owner.

## P3 — `unavailable_reason='stock_rupture'` fantôme sur une ligne `is_available=1`
- **file:line** : donnée `item_branch_availability` (item=1, branch=1) : `is_available=1` **et** `unavailable_reason='stock_rupture'`.
- **Repro/Evidence** : `ItemBranchAvailability::all()` → `item=1 br=1 avail=1 reason=stock_rupture`. `toggle()` remet pourtant `unavailable_reason=null` au ré-enable (AvailabilityService.php:141) → ligne écrite par un chemin ancien/direct.
- **Impact** : nul fonctionnellement — `getBranchAvailabilitySnapshot()` (AvailabilityService.php:645) et `KioskMenuService` (`is_available` seul) filtrent sur `is_available=false`, donc l'item reste vendable. Hygiène de donnée seulement.
- **Reco** : nettoyage ponctuel (set `unavailable_reason=null where is_available=1`). Non bloquant.

## P3 — Double-broadcast par toggle dispo (CatalogChanged + ItemAvailabilityChanged) → refetch menu redondant
- **file:line** : `app/Listeners/PersistCatalogChangedToOutbox.php:106` (s'abonne AUSSI à `ItemAvailabilityChanged`) ; consommateurs `KioskAppComponent.vue:548-556`, `PosComponent.vue:3381-3382`.
- **Repro/Evidence** (Preuve T1) : un `ItemAvailabilityChanged::forBranch` crée **2** lignes outbox (`CatalogChanged` #11120 + `ItemAvailabilityChanged` #11121, canaux `private-branch.1` + `public-menu.1`). Côté client, `_onItemAvailabilityChanged` (flip tuile ciblé) ET `_onCatalogChanged`→`itemList()` (refetch complet) tournent tous deux (PosComponent.vue:3388,3415).
- **Impact** : PAS de double-toast ni double-mutation (handlers complémentaires) ; coût = 1 refetch `/frontend/menu` (borne) + 1 `itemList()` (POS) redondants par toggle. Dedup eventContract ne les fusionne pas (types/correlation distincts, by design).
- **Reco** : acceptable ; si perf souhaitée, coalescer côté client (le flip ciblé suffit, le refetch est le filet). Aucune action requise.

---

## Findings RÉFUTÉS (verify-before-report)
1. **« KDS board = 412 commandes, pas de plancher de fraîcheur pour l'ASAP »** → **RÉFUTÉ**. Ma requête brute omettait la fenêtre. `list()` applique une fenêtre glissante 8h : `order_datetime >= now - oss.stale_window_hours (8h)` (`KitchenDisplaySystemOrderService.php:142,154-159`). Repro avec la fenêtre → **22 commandes** (pas 412). Les ASAP >8h tombent du board. Plancher OK.
2. **« `isWebPending` ne garde pas le paiement → une web PAID+PENDING reçoit un CTA encaissement au comptoir (double-charge) »** → **RÉFUTÉ au serveur**. `OnlineOrderController::changeStatus` ne bascule PENDING_COUNTER+COUNTER_DEFERRED que si `payment_status === UNPAID` (`OnlineOrderController.php:162-184`). Une web déjà PAID cliquée « Accepter » avance normalement (déjà board-released via PAID) → **aucun** dommage financier. Reste cosmétique (libellé CTA) sur un état PAID+PENDING anormal (2 lignes observées, probable artefact e2e). Non-finding.

---

## Preuves que les piliers sont « sans faute » (observations réelles)
- **T1 — Transport / outbox vivant** : `event(ItemAvailabilityChanged::forBranch(2,1,false,…))` → outbox #11120+#11121 `dispatched_at=NULL` à la création → **worker les dispatche en <4 s** (`attempts=1, last_error=-`), `ws:heartbeat` rafraîchi à `now`. Pipeline plain-event→`Persist…ToOutbox`→`DispatchDomainEventsJob`→soketi opérationnel.
- **Job dispatch robuste** : `DispatchDomainEventsJob.php:50-190` — claim atomique sous lock + `dispatched_at` guard (double-worker safe), broadcast APRÈS commit (invariant commit-before-dispatch), validation `EventContract::assertEnvelopeValid`, `contract_violation` → `fail()` once (pas de 6× retry poison), backoff `[1,5,15,60,300]`. `domain_events` non-dispatchés en 24h = **0** (10 565 historiques = bruit test hors-fenêtre, exclus du readiness gate >24h).
- **Web→caisse tracker** (`PosOrdersTrackerComponent.vue`) : web PENDING → voie « À encaisser » + CTA « Accepter » idempotent (`X-Idempotency-Key web-accept-{id}-{minute}`, l.620,1095-1125) ; `sourceOf` web→'online' (l.1167) ; poll transport-agnostique — `POLL_NO_WS_MS=8s` si `!realtimeConnected` OU events stale >`EVENT_STALE_MS=35s` OU board vide, sinon `POLL_WS_MS=60s` (l.459-468,814-824) ; freshness poll-diff (`_seenOrderIds`) flashe les nouvelles commandes **même worker mort** (l.935-948). `fetchOrders` lit la table `orders` en direct → indépendant du transport.
- **KDS board release SSOT** : `list()`/`itemBoard()`/`changeStatus()` partagent `KitchenReleaseRule::applyBoardReleaseFilter` (PAID|PENDING_COUNTER|POS-cash) + `applyScheduledBoardFilter` ; le guard de bump `changeStatus` miroir `orderIsReleasedForBoard` + `orderIsWithinScheduledWindow` (`KitchenDisplaySystemOrderService.php:80-152,556-587`) → « visible ⟹ bumpable » tenu.
- **Commandes programmées** : plancher de grâce 2h (`KitchenReleaseRule.php:162-202`, `now-grace <= scheduled_at <= now+lead`) → une no-show sort du board `grace` h après cible. Timer ancré sur `scheduled_at − lead`, PAS `created_at` (`KDSOrderDetailsResource.php:57-61`) → pas de faux « en retard ». (0 ligne `scheduled_at` en DB → logique vérifiée statiquement + math filtre, pas de flux live.)
- **eventContract** (`eventContract.js`) : refcount par canal partagé (l.360-374) + `stopListening(event, rawHandler)` unbind CIBLÉ (l.464-468, SYNC-W6) → un co-abonné ne perd pas son handler ; dedup par `type:branch:agg:correlation` (l.264-301) → multi-item auto-86 non collapsé, vraie redelivery WS↔poll dédupliquée.
- **Anti-doublage / une vérité par objet** : items → `item_branch_availability` uniquement (KioskMenuService.php:103-109,312) ; extras/variations → `stock_levels` polymorphe (AvailabilityService.php:702-770). Aucune double-écriture. Provenance manuel↔auto séparée par `manual_unavailable_since` (la colonne du P1).
- **Poll fallback PosComponent** : availability poll throttle 30 s (`PosComponent.vue:3834`) + rejeu `_onItemAvailabilityChanged` sur poll quand worker mort (l.3818-3862) ; cadence `isConnected()?60000:5000` (l.3295).

## Limites de repro (honnêteté)
- Worker **non tué** (box partagée en cours d'usage owner ; restart exact risqué) → fallback poll prouvé par construction (chemin DB-direct, freshness poll-diff) et non par extinction physique.
- 0 commande `scheduled_at` en DB → flux programmé non rejoué live (logique + filtres vérifiés statiquement).
- HTTP `POST /menu/availability/toggle` non frappé authentifié → le 500 est prouvé au niveau service (chemin identique) + `migrate:status`/schema.
