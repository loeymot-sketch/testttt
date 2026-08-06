# AUDIT D — SYNCHRONISATION inter-systèmes (round 1)

- **Date** : 2026-08-06 · **HEAD** : `a13e1e65672c9214a515fa6fd3a7e48a5abc4e4e` · **Lecture seule**
- **Périmètre** : SYNC_CONTRACT.md vs code réel, post-durcissement outbox (broadcast_at / crash-claimed / dead-letter — NON ré-audité).
- **Méthode** : 13 listeners `Persist*ToOutbox` (émission) × tous les call-sites `onEvents()` de `eventContract.js` (écoute) × cadences poll par surface.

## 1. Tableau events × surfaces (émetteur → abonnés client réels)

Canal unique `private-branch.{id}`. Abonnés = bindings `onEvents` réels (file:line). « — » = aucune surface n'affiche cet état sans l'écouter.

| broadcast_as | Émis par (serveur) | KDS | POS | Tracker POS | Encaissement | OSS staff | Borne (shell/waiting) | Stock dash | Surface qui affiche SANS écouter → latence |
|---|---|---|---|---|---|---|---|---|---|
| OrderCreated | Order/FrontendOrderService (tous chemins create) → `PersistOrderCreatedToOutbox.php:45` | 2462 | 3466 | 1064 | 166 | 305 | waiting:274 | — | OSS public = poll 5 s (by design) ; web = poll |
| OrderStatusChanged | OrderService, PaymentService:534, FrontendOrderService, bump KDS, `CleanupStalePendingKioskOrders.php:394` | 2457 | 3481 | 1066 | 168 | 289 | waiting:284 | — | web suivi = poll (repo séparé) |
| OrderPaidAtCounter | `PaymentService.php:501` (site UNIQUE) | 2463 | 3493 | 1078 | 167 | — | — | — | — (OSS/borne n'affichent pas le paiement) |
| OrderPaymentStatusChanged | PersistOrderPaymentStatusChangedOnRefundCreated:129, OrderService:2900, DrainStrandedCpn:195 | — | — | **1083 (seul)** | — | — | — | — | Encaissement/KDS : refund hors file « à encaisser » → impact nul |
| OrderTableChanged | transfert table POS → `Persist…:76` | 2474 | — | — | — | — | — | — | — |
| KdsOrderRecalled | `KitchenDisplaySystemOrderService.php:519` | 2484 | — | — | — | — | — | — | — (statut DB reste PREPARED — compensating action, rien à synchroniser ailleurs) |
| ItemAvailabilityChanged | toggles 86 item → `Persist…:80` | 2469 | 3506 | — | — | — | shell:548 | 591 | web = rejet 86 à la commande (divergence permanente documentée) |
| ItemVariationAvailabilityChanged | 86 variation → `Persist…:56` | — | — | — | — | — | — | **592 (seul)** | — (CatalogChanged co-émis, ESP:269 → kiosk/POS refetch) |
| ItemExtraAvailabilityChanged | 86 extra → `Persist…:57` | — | — | — | — | — | — | **593 (seul)** | — (CatalogChanged co-émis, ESP:255) |
| CatalogChanged | CRUD item/catégorie, ComposerProfileChanged, StockLevelChanged (ESP:276-312) → `Persist…:84` | — | 3507 | — | — | — | shell:554 | — | KDS rend des snapshots scellés → n'en a pas besoin |
| CouponChanged | CRUD coupon → `Persist…:74` | — | — | — | — | — | shell:571 | — | web valide par REST à l'usage |
| BranchStatusChanged | `BranchController.php:72,99` → `Persist…:74` | — | — | — | — | — | — | — | **ZÉRO abonné** — borne/web restent « ouverts » jusqu'au prochain fetch/garde backend |
| SettingsUpdated | Currency/Company/OrderSetup controllers → `Persist…:69` | — | — | — | — | — | — | — | **ZÉRO abonné** — POS/kiosk affichent devise/params → reload manuel |

Colonnes : KDS=`KitchenDisplaySystemComponent.vue`, POS=`PosComponent.vue`, Tracker=`PosOrdersTrackerComponent.vue`, Encaissement=`EncaissementComponent.vue`, OSS=`PreparingAndReadyComponent.vue`, Borne=`KioskAppComponent.vue`/`KioskWaitingComponent.vue`, Stock=`StockRuptureDashboardComponent.vue`, ESP=`EventServiceProvider.php`.

**Hors table** : `OrderCanceled` n'est **PAS un event broadcast** (docblock `app/Events/OrderCanceled.php` : « NOT broadcast (internal only) » — release stock). Les écrans apprennent une annulation via `OrderStatusChanged`. `ComposerProfileChanged` (PHP) n'est **jamais** émis sous son propre nom sur le fil : il sort en `CatalogChanged` (ESP:306-308).

## 2. Cadences poll réelles (fallback)

| Surface | WS up | WS down / stale | Source |
|---|---|---|---|
| KDS | **15 s** (resserré 60→15 le 2026-07-31) | 5 s | `KitchenDisplaySystemComponent.vue:2428` |
| Tracker POS | 60 s, **8 s si >35 s sans event** (escape hatch) ou board vide | 8 s | `PosOrdersTrackerComponent.vue:608-609,621,1102-1111` |
| OSS staff | 60 s | 2 s + backoff | `OssSyncService.js:9-16` |
| OSS mur public | **5 s toujours** (jamais abonné, branchId≤0 early-return :283) | 5 s | `PreparingAndReadyComponent.vue:267-271` |
| Borne waiting | 15 s | 15 s | `KioskWaitingComponent.vue:183` |
| Borne shell (menu) | push + TTL cache | TTL cache | `KioskAppComponent.vue:541` |
| Pastille santé caisse | 45 s | 45 s | `PosSystemHealthPill.vue:103` |
| Web client | poll (repo déployé séparé, pas d'Echo) | idem | contrat §5 FAUX sur ce point |

## 3. Réponses aux questions dures

**Q2 — Bandeau CUISSON** : `KdsOrderCard.vue:427-431` computed `cuissonTexte` → `cuissonForOrder()` (`kdsSymbolic.js:774`), jumeau strict du PHP `MeatPortionCalculator` (verrou parité : `tests/js/kdsCuisson.spec.js` miroir de `tests/Feature/Kitchen/MeatPortionCalculatorTest.php`). C'est une computed sur `order.order_items` : **re-render à CHAQUE hydration du board** — push WS sub-seconde en régime normal, poll 15 s en dégradé. Pas d'endpoint séparé, pas de session serveur → **temps réel, même fraîcheur que la carte**. ✅

**Q3 — Nouveaux flips** : `OrderPaidAtCounter` a **un seul site d'émission** (`PaymentService.php:501`, `confirmCounterPayment`) et **une seule route** (`routes/api.php:975`) où convergent les 4 origines counter-collect (borne plan-B, walk-in, web PENDING_COUNTER, POS COUNTER_DEFERRED) — le **mixte** (breakdown `SplitPaymentService::persistTranches` dans la même transaction) émet l'event après commit ✅. L'auto-prepare émet `OrderStatusChanged` gardé (vraie transition ACCEPT→PREPARING seulement, :533-539) ✅. **Expiration web** (T-5.1.2, TTL 60 min web+delivery) passe par `cleanupStaleDeferredOrder` partagé → `OrderStatusChanged:394` ✅. **Seul trou** : la purge fantôme PREPARED (`softDeleteStalePreparedPhantom:492`) soft-delete **sans aucun event** → la carte s'évapore au poll (KDS ≤15 s, OSS staff ≤60 s, tracker ≤60 s). Voir D-04. Note : la question présuppose « OrderCanceled » broadcast — il ne l'est pas *par design* ; le signal écran est OrderStatusChanged.

**Q4 — Pastilles « Prêt » multi-écrans** : état par-item dans `store/modules/kds.js:1` (`localStorage 'kds.bumped_items_v1'`, map `bumpedByOrder`) — par navigateur, jamais envoyé au serveur. La bannière l'avoue (`KdsStatusBanner.vue:174-180`, `fr.json:815`). Impact réel : sur 2+ écrans cuisine, la progression ligne-à-ligne d'un poste est invisible de l'autre (re-préparation possible d'une ligne déjà faite ; progression perdue au changement de tablette en rush). Le bump COMMANDE (PREPARED) est lui synchronisé. V1 = 1 écran cuisine → P3. **Amélioration minimale** : persister le tick côté serveur (flag par order_item, renvoyé dans `KDSOrderItemsResource`) — convergence par le refresh existant (WS/poll 15 s), **zéro nouveau canal/event, zéro LOCK bus**.

**Q5 — Worker meurt (soketi vivant = cas silencieux)** : t+30 s les events comptent stale ; t+1 min `outbox:monitor` (`Kernel.php:57`) → `Log::error` seulement (`MonitorOutboxStaleness.php:187`) — aucun humain. **Où le staff le voit : UNIQUEMENT la pastille caisse** (`PosOrdersTrackerComponent.vue:41`) — ambre « Traitement en retard » dès backlog >10 events âgés >30 s (`PosSystemHealthController::staleOutboxCount`), poll 45 s → **typiquement 1 à 3 min en service ; JAMAIS en heures creuses** (<10 events = aucun signal, mais rien ne circule non plus). Amortisseurs sans bannière : tracker POS passe à 8 s après 35 s sans event ; KDS poll 15 s (tickets ≤15 s de retard, badge fraîcheur « il y a Xs », hard-stall >75 s ne se déclenche pas car le poll réussit) ; OSS public 5 s ; borne waiting 15 s. KDS/OSS n'affichent **jamais** explicitement « worker down ».

## 4. Findings

- **D-01 [P2] Contract drift massif — SYNC_CONTRACT.md ment sur 4 axes** : §3 documente **4 events, réalité 13** outboxés (`app/Listeners/Persist*ToOutbox`) ; §5 dit « Customer web tracker subscribes Echo » (faux : poll, repo web déployé sans Echo) ; §6-7 cadences périmées (KDS « 60 s » → réel **15 s** `:2428` ; tracker escape 35 s→8 s absent ; pastille santé absente). Le critère d'acceptance du contrat (« deux agents dérivent le même contrat sans lire le code ») est cassé. *Fix : réécrire §3/§5/§6/§7 (doc only).*
- **D-02 [P2] Node-pin soketi absent du template supervisor** — le contrat §7 l'exige (« le template doit invoquer soketi sous Node 18 », crash Node ≥20 reproduit 2026-07-11) mais `scripts/deploy/supervisor.conf.template:66` invoque `.bin/soketi` sans pin (`:77` = `NODE_ENV` seulement) → crash-loop autorestart au provisioning d'une box Node moderne. Gate G2 single-instance OK par ailleurs (1 seul `[program:lecayenne-soketi]`, pas de numprocs ; worker 2× `--queue=high,default` `:42` ; `DispatchDomainEventsJob:46` onQueue high).
- **D-03 [P2·connu, confirmé ouvert] Aucune escalade humaine worker-down** : `MonitorOutboxStaleness.php:187` Log::error only ; `EscalateOutboxBroadcastSwallowed.php:45` log fiscal only ; `/api/health/ready` 503 sans consommateur branché. Seul signal humain = pastille caisse (voir Q5) — aveugle en heures creuses. Backlog owner (choix canal) toujours pas tranché.
- **D-04 [P3] Purge fantôme PREPARED muette** : `CleanupStalePendingKioskOrders.php:492` (soft-delete) n'émet ni OrderStatusChanged (transition illégale, voulu) ni aucun signal → disparition par poll seulement. Latence bornée (15-60 s) sur un fantôme déjà vieux de ≥TTL → acceptable, à documenter au contrat.
- **D-05 [P3] Deux events morts (zéro abonné)** : `SettingsUpdated` et `BranchStatusChanged` sont émis + outboxés mais aucun client ne les binde — le commentaire ESP (~l.320) promet « POS/Kiosk receive a SettingsUpdated » : jamais câblé. Coût : lignes outbox inutiles + fausse confiance doc.
- **D-06 [P3] Bindings client `.ComposerProfileChanged` morts** : jamais émis sous ce nom (sort en CatalogChanged). `KioskAppComponent.vue:561` et `useCatalogChangeNotifier.js:423` ne recevront jamais rien — comportement sauvé car le MÊME flux est co-bindé sur CatalogChanged, mais piège : toute logique ajoutée au handler « composer » serait silencieusement morte.
- **D-07 [P3] Pastilles Prêt locales par poste** (Q4) — voir amélioration minimale ci-dessus.
- **D-08 [P3] `BROADCAST_MAP` client incomplet** (`eventContract.js:21-34`) : 4 events serveur absents (les 2 granulaires 86, BranchStatusChanged, SettingsUpdated) → la validation de type est silencieusement skippée pour les bindings StockRupture.

**Verdict** : cœur événementiel ordres/paiement/86 **couvert et cohérent** (chaque flip owner récent émet sur tous ses chemins sauf la purge fantôme, voulue). Le reste est de la dérive documentaire (D-01) + deux vrais risques ops de déploiement/alerte (D-02, D-03). Aucun P0/P1.
