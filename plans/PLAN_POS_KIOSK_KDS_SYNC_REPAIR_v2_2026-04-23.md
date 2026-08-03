# PLAN v2 — Réparation synchronisation POS ↔ Kiosk ↔ KDS

**Date :** 2026-04-23 (révision v2 post Phase 0 + second-opinion GPT-5.4-pro)  
**Sources :** 
- Audit Claude Code : `reports/audit/AUDIT_MASSIF_POS_KIOSK_KDS_SYNC_2026-04-23.md`
- Investigations Phase 0 : `reports/audit/PHASE0_INVESTIGATIONS_2026-04-23.md`
- Second-opinion GPT-5.4-pro : `reports/audit/SECOND_OPINION_GPT54PRO_SYNC_PLAN_2026-04-23.json`
- Plan v1 (déprécié) : `plans/PLAN_POS_KIOSK_KDS_SYNC_REPAIR_2026-04-23.md`

---

## Changements majeurs vs plan v1

| Item | Changement | Source |
|------|-----------|--------|
| **F-04 reformulée → F-04bis** | Le listener itère bien sur les branches actives (audit FAUX). Vraie faille : payload global sans `is_available` → frontend prune cart à tort. | INV-01 |
| **F-01 élargie** | Pas de `OrderCanceled`/`RefundCreated` events ; pas de `releaseForOrder`. Doit créer events **et** gérer release **idempotente, partielle, par quantité**. | INV-02 + GPT NEW-05 |
| **F-21 promu P0 → 1.G** | Money-state corruption (commande non payée → ACCEPT). Plus dangereux que certains P0 actuels. | GPT disagreement |
| **1.C reclassé complex** | Race semantics + dedupe poll/WS, pas un simple timer. Polling 5–10 s adaptatif, jitter, version-gating. | GPT |
| **1.D — event dédié** | `OrderTableChanged` (pas réutiliser `OrderStatusChanged` même statut) — éviter contamination listeners. | GPT |
| **1.A critères acceptation** | Au lieu de « visible <1 s », critères mesurables : N branches actives → N events outbox sans doublon, frontend idempotent. | GPT |
| **Phase 0 exit** | Pas « binary OK/différé » : chaque gate → chemin d'implémentation concret + owner + UX acceptée. | GPT |
| **Tests sentinelles** | Ajouter chaos/concurrence : duplicate delivery, reconnect storm, after-commit rollback, partial refund idempotency. | GPT |
| **NEW-01..04 ajoutés P1** | Replay/dedupe, reconnect storm, outbox fan-out, observability/SLO. | GPT |
| **2.I (allergens)** | Plus lourd qu'estimé : migration colonne `allergens_snapshot_built_at` + backfill. | INV-04 |

---

## Phase 0 — État

| Étape | Statut | Lien |
|-------|--------|------|
| INV-01 | ✅ DONE | F-04 reformulée en F-04bis |
| INV-02 | ✅ DONE | F-01 confirmée + élargie |
| INV-03 | ✅ DONE | F-03 implémentable, polling repensé |
| INV-04 | ✅ DONE | F-13 → migration nécessaire |
| INV-05 | ⏳ HUMAN GATE | gates G-1..G-5 reformulées (à trancher) |

**Décisions humaines requises (formulées par GPT) — à coller en Slack/PM pour décision <5 min chacune :**

- **G-1 (réf F-01)** : « Lors de cancel/refund d'une commande, faut-il restaurer **automatiquement** la dispo pour les quantités exactes annulées, ou requérir une action manager ? Cancel total seulement, ou aussi partiel/par item ? »
- **G-2 (réf F-02)** : « Lors d'un transfer de table, KDS met à jour **en place** uniquement, ou doit aussi émettre un signal de reprint/notification visible ? »
- **G-3 (réf F-04bis)** : « Le `branchId=null` dans `ItemAvailabilityChanged` est-il un design global volontaire avec reachability prouvée (oui/non) ? Si oui, fournir le canal souscrit + un test e2e passant. Sinon, fan-out par branche (déjà fait côté backend, reste à corriger payload). »
- **G-4 (réf F-13)** : « Allergens modifiés post-commande : KDS doit-il afficher un warning **avant préparation** (oui/non) ? Si oui, signal **bloquant**, **badge non bloquant**, ou **risque accepté silencieux** ? »
- **G-5 (réf F-24)** : « Lignes KDS groupées avec snapshots allergens différents : **lignes séparées** ou **groupé + badge différence** ? »

---

## Phase 1 v2 — P0 (ordre final recommandé par GPT)

> **Ordre :** 1.A (corrigé) → 1.B (élargi) → **1.G (ex-F-21, promu)** → 1.E → 1.C → 1.D (dédié)

### 1.A — F-04bis · Payload `ItemAvailabilityChanged` global complet + frontend défensif

**Cible :** `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` + `resources/js/components/admin/pos/PosComponent.vue:1204–1240` + handlers KDS/kiosk équivalents.

- [ ] **Backend** : payload global doit inclure `is_available` (déduit du `status`), `reason`, `branch_id: null` explicite. Champs présents dans **tous** les modes (global et branch).
- [ ] **Frontend POS** : guard explicite — `if (typeof payload.is_available === 'undefined') { catalogRefresh(); return; }`.
- [ ] **Frontend KDS** : si listener `ItemAvailabilityChanged` existe, même guard.
- [ ] **Frontend kiosk** : même guard.
- [ ] **Test PHPUnit** `tests/Feature/ItemAvailabilityBroadcastPayloadTest.php` : payload global → contient `is_available`, `reason`, `branch_id` (null OK mais clé présente).
- [ ] **Test JS** `tests/js/posItemAvailabilityHandlerPayloadGuard.spec.js` : payload global type=`price` sans `is_available` → cart **inchangé**, catalogue rafraîchi.
- [ ] **Test JS** duplicate delivery : 2 events identiques `correlation_id` → 1 seul effet.
- [ ] **Test PHPUnit** outbox idempotency : retry du job → pas de re-création de `domain_event` (clé déterministe `{event_type}_{aggregate_id}_{version}` ou correlation_id unique).
- **Critère d'acceptation mesurable** : pour N branches actives, exactement N events `branch.{id}` créés en outbox dans T<2 s ; aucun doublon sur retry ; frontend handlers idempotents sous duplicate delivery.

### 1.B — F-01 + NEW-05 · Stock release idempotent, partial, line-item

**Cible :** créer events + listener compensateur + colonnes de tracking.

- [ ] **Migration** `add_release_tracking_to_order_items` : colonnes `released_qty INT DEFAULT 0`, `released_at TIMESTAMP NULL`.
- [ ] **Event** `app/Events/OrderCanceled.php` (after-commit dispatch via `DispatchableAfterCommit` trait).
- [ ] **Event** `app/Events/RefundCreated.php` (idem) avec `refunded_items: [{order_item_id, qty}]`.
- [ ] **Service** `AvailabilityService::releaseForOrderItems(array $lineItems)` — chaque ligne `{item_id, branch_id, qty}`.
- [ ] **Idempotence** : avant release, lock + check `released_qty + delta <= quantity`; sinon log warning et skip.
- [ ] **Listener** `app/Listeners/ReleaseAvailabilityOnOrderCanceled.php`.
- [ ] **Listener** `app/Listeners/ReleaseAvailabilityOnRefundCreated.php`.
- [ ] **Émettre** `ItemAvailabilityChanged::forBranch($branchId, $itemId, true, 'released_after_cancel|refund')` si flip out_of_stock → available.
- [ ] **Câblage** `EventServiceProvider`.
- [ ] **Câblage** call sites : `PaymentService::cashBack` dispatche `RefundCreated` ; cancel order dispatche `OrderCanceled` (chercher tous les call sites cancel).
- [ ] **Test PHPUnit** `tests/Feature/StockReleaseOnCancelTest.php` : full cancel → release exacte.
- [ ] **Test PHPUnit** `tests/Feature/StockReleaseOnPartialRefundTest.php` : refund 1 article sur 5 → release 1 unit, 4 conservées.
- [ ] **Test PHPUnit** `tests/Feature/StockReleaseIdempotencyTest.php` : 2 events `RefundCreated` identiques → release exécuté **1 fois**.
- [ ] **Test PHPUnit** `tests/Feature/StockReleaseAfterCommitTest.php` : si la transaction de cancel rollback → listener **non** appelé.
- [ ] **Sentinel** `scripts/check-invariants.sh` : interdire qu'`AvailabilityService::releaseForOrderItems` soit retiré.
- **Critère d'acceptation mesurable** : full cancel + partial refund + double-event delivery → `daily_consumed_qty` exact, `ItemAvailabilityChanged::forBranch` émis si flip, frontend voit l'item redevenir disponible <2 s après commit.

### 1.G — F-21 (PROMU P0) · `finalizePaidKioskOrder` exige paiement confirmé

**Cible :** `app/Services/FrontendOrderService.php:776–832` (frozen — patch chirurgical autorisé via gate sécurité).

- [ ] **Précondition gate** : ce fichier est frozen → patch nécessite `gate cleared` documenté dans `tasks/.../GATE_FROZEN_FRONTEND_ORDER_SERVICE_F21.md` AVANT toute modification.
- [ ] **Code** : ajouter assertion en tête de `finalizePaidKioskOrder` :
  ```php
  $hasPayment = $order->payments()->where('status', PaymentStatus::CONFIRMED)->exists()
              || ! is_null($order->payment_confirmed_at);
  if (! $hasPayment) {
      Log::warning('finalizePaidKioskOrder called without confirmed payment', [...]);
      return false;
  }
  ```
- [ ] **Test PHPUnit** `tests/Feature/FinalizePaidKioskOrderRequiresPaymentTest.php` : negative path → return false, no status flip, no event dispatched.
- [ ] **Test PHPUnit** : positive path inchangé.
- [ ] **Sentinelle** : ce test est marqué `@critical` et bloque CI si retiré.
- **Critère d'acceptation mesurable** : aucune commande kiosk avec `payment_confirmed_at = null` ne passe en `ACCEPT`. Negative-path test vert.

### 1.E — F-12 · Echo auth expiration : feedback + refresh proactif (avancé avant 1.D)

**Cible :** `resources/js/services/WebSocketService.js`, `resources/js/bootstrap.js`, `ConnectionStatusBanner.vue`.

- [ ] **WS service** : écouter Pusher `subscription_error` ; émettre `auth-expired` sur le bus.
- [ ] **Bootstrap** : `_refreshEchoAuth()` proactif, schedule ~25 min, bornes : max 3 retries puis état `session_invalid`.
- [ ] **Banner** : nouvelle bannière « Session expirée — recharger la page » non-dismissible.
- [ ] **Test JS** `tests/js/wsAuthExpired.spec.js` : `subscription_error` → bannière visible + event bus émis.
- [ ] **Test JS** `tests/js/wsAuthRefreshLoop.spec.js` : 3 échecs → state `session_invalid` (pas de refresh loop infini).
- [ ] **Test JS** : logout pendant refresh en cours → cleanup propre.
- **Critère d'acceptation mesurable** : token expiré côté backend → user voit banner + state explicit `session_invalid`. Pas de zombie UI.

### 1.C — F-03 · KDS polling fallback adaptatif + dedupe (reclassé complex)

**Cible :** `KitchenDisplaySystemComponent.vue`, possible nouveau helper `helpers/kdsPollingScheduler.js`.

- [ ] **Polling adaptatif** : 5 s pendant heures de service & disconnected ; 30 s sinon ; idle (pas d'orders depuis X min) → 60 s.
- [ ] **Jitter** ±20 % pour éviter thundering herd.
- [ ] **Cancel in-flight** au reconnect WS.
- [ ] **Version-gating** : chaque update store comparé à `updated_at` ; rejeter une réponse de poll si plus ancienne qu'un état déjà en mémoire.
- [ ] **Activation** : seulement si `wsService.isConnected() === false`.
- [ ] **Test JS** `tests/js/kdsPollingScheduler.spec.js` : disconnect → timer 5 s ; reconnect → timer cleared, in-flight cancelled.
- [ ] **Test JS** stale poll vs WS event : poll arrive après WS plus frais → store non régressé.
- [ ] **Test JS** thundering herd : 10 KDS instances simultanées → variance temps poll > 0 (jitter actif).
- **Critère d'acceptation mesurable** : déconnecté 60 s pendant service, KDS rafraîchit 8–12 fois (pas 2) ; aucun event n'est traité 2 fois (correlation_id dedupe via `eventContract`).

### 1.D — F-02 · Floorplan transfer → event dédié `OrderTableChanged` (PAS reuse OrderStatusChanged)

**Cible :** `app/Services/DiningTableService.php:280–360`, `app/Events/OrderTableChanged.php` (nouveau), `eventContract.js`.

- [ ] **Event** `app/Events/OrderTableChanged.php` avec `DispatchableAfterCommit`.
- [ ] **Backend** : `DiningTableService::transfer()` dispatche après commit DB de la mutation.
- [ ] **eventContract.js** : ajouter `OrderTableChanged` à la liste des broadcastAs supportés.
- [ ] **KDS subscribe** : `subscribeEcho()` ajoute handler → `_debouncedRefresh()`.
- [ ] **POS subscribe** : si POS affiche la commande courante avec table, refresh local.
- [ ] **Test PHPUnit** `tests/Feature/DiningTableTransferDispatchTest.php` : transfer → event dispatché after-commit, payload contient `{order_id, old_table_id, new_table_id}`.
- [ ] **Test PHPUnit** rollback : si transfer rollback → event **non** dispatché.
- [ ] **Test JS** `tests/js/kdsTableTransferRefresh.spec.js` : event reçu → KDS refresh, ticket affiche nouvelle table.
- [ ] **Compatibilité** : ancien clients ignorent gracieusement le nouvel event (pas de crash).
- **Critère d'acceptation mesurable** : transfer table 3 → 7, KDS et POS affichent la nouvelle table <2 s sans refresh manuel ; rollback de transfer = aucun event spurious.

### 1.F — Gate G-3 documentation (post 1.A)

- [ ] Une fois 1.A livrée, écrire `docs/orchestration/EVENT_BROADCAST_TOPOLOGY.md` documentant le design global → branch fan-out, avec test e2e pointé.

---

## Phase 1bis — Findings ajoutés par GPT (P1 mais à programmer après P0)

### 2bis.NEW-01 · Replay/dedupe first-class

- [ ] Étendre `eventContract.js` : `correlationId + version` (pas LRU 512 seulement) ; cap à 2048 + TTL 5 min.
- [ ] Tests JS de replay multi-bursts.

### 2bis.NEW-02 · Reconnect storm reconciliation

- [ ] Ajouter `version` ou `updated_at` dans payloads `OrderStatusChanged`, `OrderCreated`, `OrderTableChanged`.
- [ ] Stores frontend : reject update si `payload.version < cached.version`.
- [ ] Test chaos : delayed poll vs fresh WS.

### 2bis.NEW-03 · Outbox fan-out scalability

- [ ] Benchmark `1.A` à N=10, 50, 200 branches actives.
- [ ] Cap monitoring : `outbox_pending_count` > seuil → alerte.
- [ ] Retry policy explicite + DLQ.

### 2bis.NEW-04 · Observability/SLO sync health

- [ ] Métriques : `event_dispatch_lag_ms`, `ws_auth_failure_rate`, `poll_fallback_active_branches`, `transfer_conflict_rate`, `stock_release_latency_ms`.
- [ ] Dashboards + alertes seuils.

---

## Phase 2 — P1 (inchangée vs v1, sauf routing 1.C/1.D/2.J reclassés complex)

Voir plan v1 sections 2.A à 2.J. **Modifications** :
- 2.F (F-21) **retiré** car promu en 1.G.
- 2.G + 2.H : à designer ensemble (verrou backend + abonnement Echo) — implémentation parallèle après design conjoint.
- Routing 2.J reclassé `complex-implementer` (event contract + cross-surface).

## Phase 3 — Dette technique (inchangée vs v1)

Voir plan v1 section Phase 3. F-23 NEW-01 surfacés en P1 (déplacement).

---

## Critères de sortie globaux v2 (durcis par GPT)

- [ ] `bash scripts/check-invariants.sh` — 6/6.
- [ ] `npx vitest run` — 100 % vert.
- [ ] `php artisan test` — 100 % vert.
- [ ] **Tests non-happy-path obligatoires :**
  - [ ] Duplicate event replay (3 events `correlation_id` identique) → 1 seul effet.
  - [ ] WebSocket reconnect storm (disconnect 60 s, queue 50 actions, reconnect, burst 200 events) → no regression.
  - [ ] Token expiry mid-session → bannière, pas de zombie.
  - [ ] Concurrent transfer (2 cashiers même table) → 1 succès, 1 conflict 409 explicite.
  - [ ] Rollback after-commit (transaction rollback) → events **non** dispatchés.
  - [ ] Partial refund × 3 → idempotent.
  - [ ] Negative path F-21 → unpaid order **never** advance to ACCEPT.
- [ ] Reports d'exécution `reports/execution/RUN_POS_KIOSK_KDS_SYNC_*.md` un par phase.
- [ ] `memory/episodes/12_decisions_log.jsonl` — 1 entrée par gate tranchée.

---

## Routing d'exécution v2

| Tâche | Profil | Justification |
|-------|--------|---------------|
| INV-01..04 | ✅ DONE (Claude Opus orchestrateur) | Lecture, cross-fichiers. |
| INV-05 (gates) | ⏳ Humain | Décisions produit. |
| 1.A backend | `codex-terminal gpt-5.4-pro` | Event contract sensible. |
| 1.A frontend | `codex-terminal gpt-5.4` (routine seulement après contract gelé) | UI bornée mais tests gating. |
| 1.B (events + listener + idempotence) | `codex-terminal gpt-5.4-pro` | Lifecycle/idempotence/quantitative — COMPLEX. |
| 1.G (F-21) | `codex-terminal gpt-5.4-pro` + GATE FROZEN ZONE | Money-state critique + frozen file. |
| 1.E (Echo auth) | `codex-terminal gpt-5.4-pro` + revue produit/sécurité | Auth/session UX. |
| 1.C (polling adaptatif) | `codex-terminal gpt-5.4-pro` (RECLASSÉ) | Race semantics, pas pur UI. |
| 1.D (event dédié) | `codex-terminal gpt-5.4-pro` | After-commit dispatch + contract. |
| Phase 1bis NEW-01..04 | mix complex / instrumentation | Observability + dedupe transversal. |
| Phase 2/3 | mix selon item | Voir v1 routage. |

**Rationale modèle :** GPT-5.4-pro pour tout ce qui touche events/lifecycle/race ; GPT-5.4 standard pour les tâches UI bornées **après** contract gelé.

---

## Prochaine étape (immédiate)

1. **Tu** réponds aux 5 gates G-1..G-5 (5 min de produit chacune, formulées plus haut).
2. **Moi** délégue 1.A (backend) à `codex-terminal gpt-5.4-pro` dès G-3 reçue.
3. En parallèle, 1.B (events + idempotence) peut démarrer dès G-1 reçue.
4. 1.G (F-21) et 1.E peuvent démarrer **sans gate** (purement technique).
5. 1.C, 1.D : démarrent après 1.A/1.E (dépendance observability + auth stable).
