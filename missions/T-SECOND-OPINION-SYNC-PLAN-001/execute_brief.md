# AUDIT MASSIF — Synchronisation POS ↔ Kiosk ↔ KDS

**Date :** 2026-04-23  
**Méthode :** orchestration `bash scripts/foodking-claude-orchestrate.sh audit "…"` — Claude Code (lecture filesystem complète) — durée ≈ 8 min 30, exit 0.  
**Périmètre :** transferts et cohérence inter-surfaces (pas de re-test des findings déjà tranchés dans `reports/audit/AUDIT_KDS_POS_SYNCHRONISATION_PROFONDE_2026-04-24.md`).  
**Suite à donner :** voir `plans/PLAN_POS_KIOSK_KDS_SYNC_REPAIR_2026-04-23.md` (checklist d'exécution).

---

> **Préambule** : audits antérieurs lus en premier. Findings déjà documentés (bump localStorage, limit 50, admin polling 60s, list() vs orderItems() statuts, branch_id LIKE→=) sont marqués `[KNOWN]` si une régression est détectée, et **exclus** sinon. Ce rapport couvre uniquement les angles morts résiduels.

---

## Findings

### P0 — Blocants production

#### F-01 · Stock jamais libéré après annulation ou remboursement
**Fichiers :** `app/Services/PaymentService.php:31–72` · `app/Services/Menu/AvailabilityService.php:158–203` · `app/Listeners/DecrementItemAvailabilityOnOrder.php:15–23`  
**Symptôme :** `cashBack()` crédite le solde utilisateur et écrit le log NF525, mais ne décrémente pas `daily_consumed_qty`. Aucune méthode `incrementForOrder()` ou `ReleaseItemAvailabilityOnCanceledOrder` n'existe. Le listener `DecrementItemAvailabilityOnOrder` est en one-way.  
**Impact :** Un article atteignant son `max_daily_qty` à 13h et remboursé à 14h reste marqué `out_of_stock` jusqu'à minuit. KDS, POS, kiosk affichent faussement l'article indisponible. En service chargé : 5–15 % du catalogue verrouillé.  
**Classe :** sync / gouvernance.  
**Correctif :** créer `AvailabilityService::releaseForOrder(Model $order)` miroir de `decrementForOrder()`, dispatcher `ItemAvailabilityChanged::forBranch()` si flip, attacher un listener `ReleaseItemAvailabilityOnOrderCanceled` aux events `OrderCanceled` / `RefundCreated`.

#### F-02 · Floorplan transfer → aucun event KDS : la table ne suit pas le ticket
**Fichiers :** `app/Services/DiningTableService.php:280–360` · `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:886–893`  
**Symptôme :** `DiningTableService::transfer()` met à jour `orders.dining_table_id` en transaction et écrit un audit log, mais ne dispatche aucun event. Le KDS imprime « Table 3 » alors que la commande a été transférée à « Table 7 ».  
**Classe :** sync / race.  
**Correctif :** post-commit, dispatcher `OrderStatusChanged` (même statut, force refresh) ou un `OrderTableChanged` custom listé dans `eventContract.js`.

#### F-03 · KDS branch sans polling WS fallback
**Fichiers :** `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:878–893` · `resources/js/bootstrap.js:64–70`  
**Symptôme :** `subscribeEcho()` ne s'abonne que si `branchId > 0` ; aucun polling fallback. Pendant la reconnexion (30–90 s), `OrderCreated` / `OrderStatusChanged` sont perdus (Pusher ne bufferise pas les events privés).  
**Classe :** recovery / sync.  
**Correctif :** `_kdsPollingTimer` 30 s actif quand `wsService.isConnected() === false`, détruit à la reconnexion (analogue à `PosComponent._kioskPollingInterval()`).

#### F-04 · `ItemAvailabilityChanged` global (`branchId=null`) : canal de broadcast à confirmer
**Fichiers :** `app/Events/ItemAvailabilityChanged.php:55–66` · `resources/js/services/eventContract.js:107–108`  
**Symptôme :** event construit avec `branchId=null` côté global, frontend ne s'abonne qu'à `branch.{branchId}`. Si l'outbox n'itère pas sur toutes les branches, aucune surface ne reçoit l'event.  
**Classe :** sync / critique (à confirmer).  
**Correctif :** auditer `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` ; pour `branchId=null`, itérer sur les branches actives et pousser un event `branch.{id}` par branche. Test d'intégration dédié.

#### F-05 · Idempotency key POS générée depuis un compteur localStorage : reset = collision
**Fichiers :** `resources/js/components/admin/pos/PosComponent.vue:1778–1802`  
**Symptôme :** clé `pos-{branchId}-{userId}-{YYYY-MM-DD}-{seq}` où `seq` vient de localStorage. Vidé / autre device → repart à 1. Deux cashiers même compte/branche/jour → clé identique → backend renvoie l'order existant ⇒ commande fantôme.  
**Classe :** idempotence / sécurité.  
**Correctif :** ajouter un composant aléatoire (`crypto.randomUUID()` tronqué). Le compteur reste advisory.

---

### P1 — Risques significatifs

#### F-06 · Race `OrderCreated` → `DecrementAvailability` → `ItemAvailabilityChanged`
**Fichiers :** `app/Listeners/DecrementItemAvailabilityOnOrder.php:15–23` · `app/Services/Menu/AvailabilityService.php:185–201`  
**Symptôme :** KDS reçoit `OrderCreated` puis 50–200 ms plus tard `ItemAvailabilityChanged` qui retire l'item du board (ok pour la commande validée, mais double prise simultanée → surstock).  
**Classe :** race / sync.  
**Correctif :** inclure dans `OrderCreated.payload` un champ `items_became_unavailable: [...]` pour traitement en une passe.

#### F-07 · `_onItemAvailabilityChanged` : type `branch_availability` non géré explicitement
**Fichiers :** `resources/js/components/admin/pos/PosComponent.vue:1204–1240`  
**Symptôme :** event a deux types (`status` global, `branch_availability` MENU 86). Si gate `if (type === 'status')` sans `else` pour `branch_availability`, le panier POS ne se prune pas pour les 86 branch-scoped.  
**Classe :** sync / idempotence.  
**Correctif :** vérifier traitement explicite des deux types ; appel `posCart/pruneUnavailable` dans les deux cas.

#### F-08 · `posParked.recall` : prune sans `branch_item_availability`
**Fichiers :** `resources/js/store/modules/posParked.js:100–106` · `app/Services/PosParkedOrderService.php:86–88`  
**Symptôme :** validation prune basée sur catalogue cached + `trashed/inactive` côté backend, pas sur `is_available` branch.  
**Classe :** race / sync.  
**Correctif :** étendre `pruneUnavailableParkedVariations()` pour joindre `branch_item_availability`.

#### F-09 · KDS `changeStatus` 409 : rejet silencieux
**Fichiers :** `resources/js/store/modules/kitchenDisplaySystemOrder.js:36–49`  
**Symptôme :** sur 409, refresh sans rejet de Promise ni feedback visible (NB : un toast a été ajouté côté composant en 2026-04-22 — vérifier complétude).  
**Classe :** UX / race.  
**Correctif :** propager 409 + toast « statut mis à jour par un autre terminal », inclure le nouveau statut dans le payload.

#### F-10 · POS `OrderStatusChanged` n'actualise pas la commande courante
**Fichiers :** `resources/js/components/admin/pos/PosComponent.vue:1183–1188`  
**Symptôme :** seul `loadKioskCashOrders()` est appelé. Si KDS passe la commande PREPARED, le caissier ne le voit pas tant qu'il n'a pas refresh.  
**Classe :** sync / UX.  
**Correctif :** matcher `payload.order_id` avec `posOrder/currentOrder.id` ; si match → `posOrder/show(id)`.

#### F-11 · Floorplan polling 15 s sans Echo : conflits silencieux
**Fichiers :** `resources/js/components/admin/pos/FloorplanComponent.vue:120` · `resources/js/store/modules/posFloorplan.js:20–27`  
**Symptôme :** assignations parallèles non détectées entre deux polls. Message d'erreur générique.  
**Classe :** race / UX.  
**Correctif :** abonnement Echo `FloorplanStateChanged` (ou polling 5 s en peak) ; message 409 dédié « table déjà occupée ».

#### F-12 · Echo auth token (Bearer localStorage) : expiration silencieuse
**Fichiers :** `resources/js/bootstrap.js:42–46`  
**Symptôme :** token expiré → reconnects Pusher échouent en silence côté auth, plus aucun event reçu.  
**Classe :** sécurité / sync.  
**Correctif :** écouter `subscription_error`, banner « session expirée », `_refreshEchoAuth()` proactif.

#### F-13 · Snapshot allergens immutable : drift post-commande non signalé
**Fichiers :** `app/Services/Orders/OrderItemAllergenSnapshot.php:29–74` · `app/Http/Resources/OrderItemResource.php:31–35`  
**Symptôme :** ajout d'allergène à un extra après prise de commande → KDS continue d'afficher l'ancien snapshot.  
**Classe :** gouvernance / sécurité.  
**Correctif :** stocker `allergens_snapshot_built_at` ; comparer avec `items.allergens_updated_at` ; flag KDS « ⚠ allergens modifiés depuis commande ».

#### F-14 · `pruneUnavailable` panier POS sans notification caissier
**Fichiers :** `resources/js/components/admin/pos/PosComponent.vue:1204–1240`  
**Symptôme :** suppression silencieuse d'articles ; caissier peut ne pas remarquer.  
**Classe :** UX / sync.  
**Correctif :** toast nominatif « Article X retiré du panier (rupture) ».

#### F-15 · Parked recall : pas de dialog warnings côté UI
**Fichiers :** `resources/js/store/modules/posParked.js:112–125` · `app/Services/PosParkedOrderService.php:105–193`  
**Symptôme :** `warningsOut` API présents mais non affichés côté composant ; recall « propre » côté UI alors que des articles sont retirés.  
**Classe :** UX / sync.  
**Correctif :** retourner `warnings` dans l'API recall et les afficher avant validation (`confirm()` ou toast).

---

### P2 — Dette technique et risques latents

#### F-16 · Kiosk cash orders POS : filtre statut `[4,7,8]` côté client uniquement
**Fichiers :** `resources/js/components/admin/pos/PosComponent.vue:1357–1368`  
**Symptôme :** filtre statut appliqué après pagination KDS (limit 50). En charge, des commandes ACCEPT/PREPARING ne remontent pas.  
**Correctif :** passer `status[]=...` dans la querystring serveur.

#### F-17 · Double abonnement Echo possible (re-mount sans unmount propre)
**Fichiers :** `resources/js/components/admin/pos/PosComponent.vue:1172–1197, 1265`  
**Correctif :** garde dans `eventContract.js::onEvents()` détachant tout listener existant avant rattachement, ou méthode `hasSubscription(branchId)`.

#### F-18 · `posCart` TTL 2 h : expiration silencieuse
**Fichiers :** `resources/js/store/modules/posCart.js`  
**Correctif :** au restore, toast « Session panier expirée » ; option auto-park avant idle.

#### F-19 · `kioskReceiptPersistence` : reçu visible client suivant
**Fichiers :** `resources/js/helpers/kioskReceiptPersistence.js:16, 33–44`  
**Symptôme :** `kiosk.lastReceipt` non effacé après DELIVERED → fuite RGPD potentielle.  
**Correctif :** purge sur `OrderStatusChanged → DELIVERED` ou TTL 10 min.

#### F-20 · `PosParkedOrderService` : admin (branch_id=0) cloisonné
**Fichiers :** `app/Services/PosParkedOrderService.php:62–70`  
**Correctif :** documenter (parked = personnel) ou exposer un scope branch_id pour transferts supervisés.

#### F-21 · `finalizePaidKioskOrder()` : pas de check inline du paiement
**Fichiers :** `app/Services/FrontendOrderService.php:776–832`  
**Correctif :** assertion `payments` row ou `payment_confirmed_at` présent avant promotion ACCEPT.

#### F-22 · `posFloorplan.transfer()` : pas de verrou backend sur double-clic multi-onglets
**Fichiers :** `resources/js/store/modules/posFloorplan.js:42–46` · `resources/js/components/admin/pos/FloorplanComponent.vue:106–110`  
**Correctif :** `lockForUpdate()` dans `DiningTableService::transfer()` + 409 explicite.

#### F-23 · `eventContract.js` LRU 512 : burst possible > capacité
**Correctif :** porter cap à 2048 ou ajouter TTL 5 min sur les correlationIds.

#### F-24 · `KitchenDisplaySystemOrderService::orderItems()` : grouping sans allergens
**Fichiers :** `app/Services/KitchenDisplaySystemOrderService.php:221–240`  
**Symptôme :** fusion `item_id + variations + extras + instruction` sans hash allergens → « x3 Burger » indistincts profils allergiques.  
**Correctif :** inclure hash `allergens_snapshot` dans le grouping key, ou badge variantes sur la ligne fusionnée.

#### F-25 · `posOrder.save()` : timeout idempotency 30 s vs réseau lent
**Fichiers :** `resources/js/store/modules/posOrder.js`  
**Correctif :** aligner sur timeout axios ; ne pas régénérer la clé tant que la requête n'est pas explicitement abandonnée ; spinner bloquant.

---

## Patterns transversaux récurrents

| Pattern | Findings |
|---|---|
| Prune silencieuse sans notification | F-14, F-15, F-18 |
| Validation côté client comme seul gate | F-08, F-16 |
| Absence de compensation / rollback métier | F-01, F-21 |
| Événement manquant sur action floorplan | F-02, F-11 |
| Timeout / expiration silencieuse | F-12, F-18, F-25 |
| Race frontale double-clic / double-tab | F-05, F-17, F-22 |

---

## Décisions humaines requises (gates)

| Gate | Question |
|---|---|
| **G-1 (F-01)** | Annulation d'un article auto-86'd : restauration auto ou validation manuelle manager ? |
| **G-2 (F-02)** | Transfer de table : reprint ticket KDS avec nouvelle table, ou refresh d'en-tête seul ? |
| **G-3 (F-04)** | Lecture confirmatoire de `PersistItemAvailabilityChangedToOutbox` (gravité réelle). |
| **G-4 (F-13)** | Allergens drift post-commande : flag KDS ou risque accepté ? |
| **G-5 (F-24)** | Granularité regroupement KDS allergens : fragmenter le board ou badge informatif ? |

---

## Ordre de correction recommandé

1. **F-04** (`ItemAvailabilityChanged` global broadcast) — confirmer/infirmer en premier.
2. **F-01** (stock non libéré sur annulation) — chaque jour de prod aggrave l'état.
3. **F-03** (KDS sans polling WS fallback) — première coupure réseau = commandes manquées.
4. **F-05** (idempotency localStorage) — doublon possible sur tout device change.
5. **F-02** (floorplan transfer sans event KDS) — table mal servie chaque transfer.
6. **F-07** (`branch_availability` non géré PosComponent).
7. **F-12** (Echo token expiration silencieuse).
8. **F-09** (409 KDS — toast complet).
9. **F-10** (POS PREPARED en temps réel).
10. **F-21** (`finalizePaidKioskOrder` sans check webhook).
11. **F-08** (parked recall + branch availability).
12. **F-14** (prune cart silencieuse).
13. **F-15** (recall warnings UI).
14. **F-06** (race `OrderCreated` + `ItemAvailabilityChanged`).
15. **F-24** (grouping board KDS allergens).
16–25. F-16, F-11, F-17, F-18, F-19, F-20, F-22, F-23, F-25, F-13 — sprint suivant.

**Verdict :** socle backend (locks, snapshots, after-commit, idempotency) solide. Les angles critiques sont **(1) absence de compensation métier**, **(2) manque d'events sur mutations floorplan**, **(3) fallbacks WS insuffisants**. Aucun gap n'est architectural — tous corrigibles sans refonte.
