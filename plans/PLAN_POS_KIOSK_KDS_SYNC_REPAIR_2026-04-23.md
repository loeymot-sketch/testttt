# PLAN — Réparation synchronisation POS ↔ Kiosk ↔ KDS

**Date :** 2026-04-23  
**Source audit :** `reports/audit/AUDIT_MASSIF_POS_KIOSK_KDS_SYNC_2026-04-23.md` (25 findings).  
**Contre-audit interne :** vérifié manuellement — 23 findings retenus, **2 reclassés** ci-dessous.  
**Stratégie :** 4 phases bornées (P0 critique → P3 dette latente). Chaque case = livrable atomique avec critère d'acceptation et test.  
**Invariants à respecter :** `pricing_ssot` · `order_status` (enum) · `branch_id` isolation · `commit_before_dispatch` · frozen zones (`OrderService`, `FrontendOrderService`, `posReceiptBuilder`).

---

## Reclassement / faux positifs identifiés

| Finding audit | Verdict contre-audit | Action |
|---|---|---|
| **F-05** (idempotency localStorage) | **Faux positif partiel.** L'`idempotency_key` réelle (`PosComponent.vue:1799`) utilise `Date.now() + Math.random() + branchId`, suffisamment entropique. Le compteur localStorage (`:1779`) sert au `token` d'**affichage** du ticket, pas à l'idempotence. | **Garder en P3 (cosmétique)** : doublons possibles de numéro de ticket inter-cashiers — UX seulement, pas de collision DB. |
| **F-07** (`branch_availability` non géré) | **Faux positif sur la formulation.** Le handler ne gate pas par `type` ; il prune sur `is_available === false` indépendamment du `type`. | **Re-cibler** : vérifier côté backend que les events `branch_availability` envoient bien le champ `is_available=false`, et qu'ils sont bien diffusés sur le canal `branch.{id}` (lien avec **F-04**). |

Tous les autres findings sont confirmés.

---

## Phase 0 — Investigations bloquantes (préalables à tout fix)

But : confirmer l'étendue réelle des risques signalés par l'audit avant d'investir dans des correctifs.

- [ ] **INV-01** Lire `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` end-to-end ; tracer le flux `branchId=null → outbox → broadcast`. Documenter dans `reports/audit/AUDIT_MASSIF_POS_KIOSK_KDS_SYNC_2026-04-23.md` (annexe). _Réf F-04, F-07-bis._
- [ ] **INV-02** Vérifier la présence d'un listener `OrderCanceled` / `RefundCreated` (rg + Read) ; documenter ce qui existe. _Réf F-01._
- [ ] **INV-03** Vérifier que `WebSocketService` expose un état `connected/disconnected` consommable par `KitchenDisplaySystemComponent`. _Réf F-03._
- [ ] **INV-04** Confirmer que `OrderItemResource` (ou équivalent KDS) expose `allergens_snapshot_built_at` ou champ équivalent ; sinon c'est une migration. _Réf F-13._
- [ ] **INV-05** Décider du scope sur **G-1, G-2, G-4, G-5** (gates humaines) — sans réponse, certaines tâches P1 attendent.

**Critère de fin Phase 0 :** annexe d'audit complétée, gates tranchées (au moins binaire OK / différé).

---

## Phase 1 — P0 (correctifs critiques production)

### 1.A — F-04 / F-07-bis · `ItemAvailabilityChanged` global broadcast cohérent
- [ ] Selon INV-01 : si `branchId=null` n'est pas re-éclaté en N events `branch.{id}`, modifier `PersistItemAvailabilityChangedToOutbox` pour itérer sur les branches actives.
- [ ] Test d'intégration `tests/Feature/ItemAvailabilityBroadcastTest.php` : événement `null` → vérifier qu'un `branch.{id}` event est émis pour chaque branche active dans l'outbox.
- [ ] Test JS `tests/js/posItemAvailabilityHandler.spec.js` : couvrir les deux types (`status` global et `branch_availability`) avec `is_available=false` → `posCart/pruneUnavailable` est dispatché.
- **Acceptation :** rupture globale d'un item visible sur kiosk + POS + KDS de **toutes** les branches dans la seconde.

### 1.B — F-01 · Stock libéré sur annulation / remboursement
- [ ] Créer `AvailabilityService::releaseForOrder(Model $order): void` (miroir `decrementForOrder`).
- [ ] Émettre `ItemAvailabilityChanged::forBranch($branchId, $itemId, true, 'released_after_cancel')` si transition out_of_stock → available.
- [ ] Créer `app/Listeners/ReleaseItemAvailabilityOnOrderCanceled.php`.
- [ ] Brancher dans `EventServiceProvider` aux events `OrderCanceled` et `RefundCreated` (créer/identifier ces events si absents — ne **pas** modifier OrderService directement, passer par event existant).
- [ ] Test PHPUnit `tests/Feature/StockReleaseOnCancelTest.php` : commande crée → `decrementForOrder` → annulation → `releaseForOrder` exécuté → `ItemAvailabilityChanged` dispatché.
- [ ] Sentinelle `scripts/check-invariants.sh` (ou test) : interdire qu'`AvailabilityService::releaseForOrder` soit retiré (régression).
- **Acceptation :** annulation d'une commande à 14 h libère immédiatement le `daily_consumed_qty` ; kiosk/POS/KDS voient l'item redevenir disponible.

### 1.C — F-03 · KDS polling fallback quand WS down
- [ ] Dans `KitchenDisplaySystemComponent.vue` : ajouter `_kdsFallbackPollingTimer` activé quand `wsConnected === false`, intervalle 30 s, appelle `_debouncedRefresh()`.
- [ ] Désactivé quand `wsConnected === true`.
- [ ] Vérifier que la bannière `ws-reconnect-banner` reste cohérente (déjà présente).
- [ ] Test JS `tests/js/kdsWsFallbackPolling.spec.js` (Vitest) : mock `wsConnected=false` → timer instancié ; `wsConnected=true` → timer cleared.
- **Acceptation :** debranché 60 s, le KDS rafraîchit deux fois sans intervention manuelle.

### 1.D — F-02 · Floorplan transfer notifie KDS
- [ ] Dans `DiningTableService::transfer()`, après commit DB de la mutation `orders.dining_table_id`, dispatcher `OrderStatusChanged::sameStatus($order)` (factory factice — même statut, force refresh) **OU** créer `OrderTableChanged` event listé dans `eventContract.js`.
- [ ] Étendre `eventContract.js` (clé `OrderTableChanged` si custom) ; abonner KDS dans `subscribeEcho()`.
- [ ] Test PHPUnit `tests/Feature/DiningTableTransferDispatchTest.php` : transfer → event dispatché after-commit.
- [ ] Test JS `tests/js/kdsTableTransferRefresh.spec.js` : event reçu → `_debouncedRefresh()` invoqué.
- **Acceptation :** transfer table 3 → 7 visible sur KDS sous 2 s sans rafraîchir.

### 1.E — F-12 · Echo token expiration : feedback utilisateur
- [ ] Dans `wsService.js`, écouter Pusher `subscription_error` ; émettre un événement bus `ws:auth-expired`.
- [ ] `ConnectionStatusBanner` (ou nouvelle bannière) affiche « Session expirée — recharger la page ».
- [ ] Tentative proactive : `_refreshEchoAuth()` appelé toutes les ~25 min ou avant expiration connue (à valider).
- [ ] Test JS `tests/js/wsAuthExpired.spec.js` : `subscription_error` → bannière visible.
- **Acceptation :** session backend expirée, l'utilisateur voit un avertissement explicite au lieu d'un silence.

### 1.F — Gate G-3 (F-04) — décision d'architecture broadcast
- [ ] Si INV-01 montre un design intentionnel (broadcast global `App.Models.Item.{id}` ou similaire), documenter ce choix dans `docs/orchestration/EVENT_BROADCAST_TOPOLOGY.md` (créer si absent) ; sinon corriger comme dans 1.A.

---

## Phase 2 — P1 (risques significatifs)

### 2.A — F-09 · Toast 409 KDS complet
- [ ] Vérifier que `kitchenDisplaySystemOrder/changeStatus` rejette bien la Promise sur 409 (déjà fait composant ; vérifier store).
- [ ] Couvrir avec `tests/js/kdsChangeStatusConflict.spec.js` (mock axios 409 → toast affiché + nouveau statut visible).

### 2.B — F-10 · POS reflète PREPARED en temps réel
- [ ] Modifier le handler `OrderStatusChanged` de `PosComponent.vue:1188` : si `payload.order_id === currentOrder?.id`, dispatcher `posOrder/show(id)` ou patch local du statut.
- [ ] Test JS `tests/js/posOrderStatusLive.spec.js`.
- **Acceptation :** KDS bump → POS voit la commande passer PREPARED sans refresh.

### 2.C — F-08 · Parked recall croise `branch_item_availability`
- [ ] Étendre `PosParkedOrderService::pruneUnavailableParkedVariations()` : join SQL avec `branch_item_availability` ; exclure `is_available=false` pour la `branch_id`.
- [ ] Test PHPUnit `tests/Feature/PosParkedRecallBranchAvailabilityTest.php`.

### 2.D — F-15 · Recall affiche les warnings UI
- [ ] `posParked` store : exposer `warnings` retournés par l'API.
- [ ] `ParkedOrdersComponent.vue` : bandeau / dialog listant les articles retirés, bouton « Continuer » explicite.
- [ ] i18n FR/EN/AR : `label.pos_parked_recall_warnings`.
- [ ] Test JS `tests/js/posParkedRecallWarnings.spec.js`.

### 2.E — F-14 · Toast prune cart POS nominatif (déjà partiel)
- [ ] Vérifier `_maybeToastItemUnavailableLost` couvre **tous** les chemins de `pruneUnavailable` (pas seulement `_onItemAvailabilityChanged`).
- [ ] Test JS dédié.

### 2.F — F-21 · `finalizePaidKioskOrder()` assertion paiement
- [ ] Ajouter assertion : `payments` row existante OU `payment_confirmed_at` set ; sinon log warning + return false.
- [ ] Test PHPUnit `tests/Feature/FinalizePaidKioskOrderRequiresPaymentTest.php`.

### 2.G — F-11 · Floorplan : abonnement Echo ou polling 5 s peak
- [ ] Choisir : abonnement `FloorplanStateChanged` (préféré) OU polling adaptatif.
- [ ] Si Echo : back dispatch event after-commit dans `DiningTableService::assign/release/transfer`.
- [ ] Message 409 dédié côté UI (`message.floorplan_table_already_assigned`).

### 2.H — F-22 · Verrou backend sur `transfer()`
- [ ] `DiningTableService::transfer()` : `lockForUpdate()` sur l'order + table cible.
- [ ] Test PHPUnit transactionnel `tests/Feature/DiningTableTransferConcurrencyTest.php`.

### 2.I — F-13 · Drift allergens flag KDS (selon G-4)
- [ ] Si gate validée : champ `allergens_snapshot_built_at` (migration) + `items.allergens_updated_at` ; flag UI KDS.

### 2.J — F-06 · Race `OrderCreated` ⇆ `ItemAvailabilityChanged`
- [ ] `OrderCreated.payload` enrichi de `items_became_unavailable: [...]` (générer dans le listener post-commit).
- [ ] Frontend traite en une passe.

---

## Phase 3 — P2 (dette technique)

- [ ] **F-16** Filtre statut serveur (`status[]=...`) pour `loadKioskCashOrders` — vérifier endpoint accepte.
- [ ] **F-17** Garde anti-double-subscription dans `eventContract.js::onEvents()`.
- [ ] **F-18** Toast « session panier expirée » au restore POS si TTL dépassé.
- [ ] **F-19** Purge `kiosk.lastReceipt` sur `OrderStatusChanged → DELIVERED` ou TTL 10 min.
- [ ] **F-20** Décision documentée parked = personnel, ou scope `branch_id` configurable.
- [ ] **F-23** `eventContract.SEEN_CORRELATION_CAP` 512 → 2048 et/ou TTL 5 min.
- [ ] **F-24** Grouping board KDS : intégrer `allergens_snapshot` hash dans la clé OU badge variantes (selon G-5).
- [ ] **F-25** Aligner timeout idempotency avec axios + spinner bloquant.
- [ ] **F-05 (cosmétique reclassée)** Numéro de ticket POS : ajouter suffixe device pour éviter doublons d'affichage entre cashiers.

---

## Critères de sortie globaux

- [ ] `bash scripts/check-invariants.sh` — 6/6 OK.
- [ ] `npx vitest run` — 100 % vert.
- [ ] `php artisan test --testsuite=Feature` — 100 % vert sur les fichiers ajoutés.
- [ ] `reports/execution/RUN_POS_KIOSK_KDS_SYNC_REPAIR_*.md` (un par phase) avec preuve avant/après.
- [ ] Mise à jour `memory/episodes/12_decisions_log.jsonl` (1 entrée par gate tranchée).
- [ ] Mise à jour `memory/episodes/02_architecture_invariants.jsonl` si `EVENT_BROADCAST_TOPOLOGY.md` est créé.

---

## Routing d'exécution suggéré

| Tâche | Profil | Justification |
|---|---|---|
| INV-01..05 | `foodking-planner-orchestrator` (lecture seule) | Investigation cross-fichiers, pas de code. |
| 1.A backend | `foodking-complex-implementer` (codex-terminal) | Touche événement / outbox, sensible. |
| 1.A frontend (test JS) | `foodking-routine-implementer` | Bornée. |
| 1.B (stock release listener) | `foodking-complex-implementer` | Lifecycle / events / frozen-adjacent. |
| 1.C (polling KDS) | `foodking-routine-implementer` | UI bornée. |
| 1.D (transfer event) | `foodking-complex-implementer` | After-commit dispatch sensible. |
| 1.E (auth Echo) | `foodking-complex-implementer` | Sécurité / WS. |
| Phase 2 | mix selon item | Certains routine (toasts/i18n), certains complex (lockForUpdate, finalizePaid). |
| Phase 3 | majorité `foodking-routine-implementer` | Dette UI / TTL / purge. |

---

## Rappel sur la suite

- **Audit GPT (codex)** : disponible en *second-opinion* sur ce plan une fois Phase 1 démarrée — j'injecterai uniquement les findings/correctifs (pas le code) pour rester dans le budget tokens. Confirme si tu veux que je le déclenche.
- **Claude design** (POS uniquement) : à lancer en parallèle de la Phase 1 backend, puisque ce sont des templates Vue isolés (pas de couplage avec les correctifs de sync).
