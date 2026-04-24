# Phase 0 — Investigations bloquantes (lecture filesystem)

**Date :** 2026-04-23  
**Méthode :** lecture directe (Claude Opus orchestrateur, accès filesystem complet).  
**Cible :** valider/infirmer les findings P0 avant tout fix.

---

## INV-01 — `PersistItemAvailabilityChangedToOutbox` (réf F-04, F-07-bis)

**Fichier lu intégralement :** `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` (76 lignes).

**Verdict :** F-04 dans sa formulation initiale est **infirmée** — mais une vraie faille subsiste.

### Ce qui est correct (audit initial = faux positif)
- Lignes 32–40 : quand `branchId === null`, le listener **itère explicitement sur toutes les branches `ACTIVE`** et construit un canal `private-branch.{id}` par branche.
- L'event `DispatchDomainEventsJob` est dispatché **after-commit** (ligne 54).
- Le `correlation_id` est résolu (header > shared context > UUID).

### Vraie faille (à reformuler en `F-04bis`)
- Lignes 19–24 : le payload global ne contient **que** `item_id`, `status`, `price`, `type`. Il **n'inclut ni `is_available`, ni `branch_id`, ni `reason`**.
- Lignes 26–30 : ce n'est qu'en mode branch-scoped que le payload reçoit `is_available`, `branch_id`, `reason`.

**Conséquence frontend :** le handler `_onItemAvailabilityChanged` (`PosComponent.vue:1204–1240`) calcule `isAvailable = payload.is_available === true || ...` → si la clé est absente (cas global), `isAvailable === false` → **`posCart/pruneUnavailable` est appelé pour tout event global**, y compris un simple changement de prix (`type === 'price'`) ou de structure (`type === 'full'`).

**Impact opérationnel :** un admin qui édite le prix d'un item depuis le central → tous les caissiers voient l'item disparaître de leur panier silencieusement.

### Correctif proposé (F-04bis, P0)
- **Backend** : enrichir le payload global avec `is_available` (déduit du `status`), explicitement absent ne vaut pas false.
- **Frontend** : ajouter dans `_onItemAvailabilityChanged` un guard `if (typeof payload.is_available === 'undefined') { /* refresh catalogue, ne rien prune */ return; }` avant tout pruning.
- Tests : duplicate delivery + payload global sans `is_available` → cart inchangé.

---

## INV-02 — Events `OrderCanceled` / `RefundCreated` (réf F-01)

**Recherches :**
- `Glob app/**/Events/OrderCanceled.php` → 0 fichier.
- `Glob app/**/Events/Refund*.php` → 0 fichier.
- `Grep "class (OrderCanceled|OrderCancelled|RefundCreated|RefundProcessed|OrderRefunded)"` dans `app/` → 0 occurrence.
- `app/Services/PaymentService.php:31` → `cashBack($order, $gatewaySlug, $transactionNo)` existe ; n'émet rien lié au stock.
- `app/Services/Menu/AvailabilityService.php:158` → `decrementForOrder(Model $order)` existe ; **aucune méthode `releaseForOrder`/`incrementForOrder`**.

**Verdict :** F-01 entièrement **confirmée**. Et plus largement : le plan 1.B doit **aussi créer** les events `OrderCanceled` / `RefundCreated` (ils n'existent pas) avant de pouvoir y attacher le listener compensateur.

### Sur-finding identifié (NEW-05 du second-opinion GPT)
La compensation ne peut **pas** être un simple miroir « release toute la commande » : il faut gérer
- les **annulations partielles** (1 article retiré sur 5),
- les **remboursements partiels** (1 article remboursé),
- l'**idempotence** (deux events `OrderCanceled` ou `RefundCreated` doivent libérer une fois).

Le listener doit lire `released_qty` / `cancel_state` persisté (à créer aussi) avant de toucher `daily_consumed_qty`.

---

## INV-03 — `WebSocketService` état exposé (réf F-03)

**Fichier lu intégralement :** `resources/js/services/WebSocketService.js` (157 lignes).

**Verdict :** F-03 implémentable directement.
- `isConnected(): boolean` est exposé (ligne 94).
- Émet les events bus `connected` (ligne 123) et `disconnected` (ligne 125).
- Heartbeat 30 s, reconnexion automatique avec back-off implicite Pusher.

**Mise en garde GPT (validée) :** un polling fallback **30 s** est trop laxiste pour la cuisine en service. Il faut :
- 5–10 s pendant déconnexion (pas 30 s),
- jitter pour éviter thundering herd à la reconnexion,
- annulation des polls en vol au moment de la reconnexion,
- gating des updates store par version/timestamp (pas écraser un état plus récent reçu via WS par une réponse de poll en retard).

---

## INV-04 — `allergens_snapshot_built_at` (réf F-13)

**Fichier lu :** `app/Http/Resources/OrderItemResource.php` (113 lignes).

**Verdict :**
- Le champ `allergens_snapshot` est exposé (ligne 36).
- Le champ `allergens_snapshot_built_at` **n'existe pas**.
- Aucun champ équivalent (`allergens_built_at`, `allergens_at`, etc.).

**Conséquence :** F-13 nécessite :
1. Migration colonne `order_items.allergens_snapshot_built_at TIMESTAMP NULL`.
2. Backfill (`= created_at` pour les rows existantes, ou explicite `null`).
3. Set côté `OrderItemAllergenSnapshot::buildAndPersist`.
4. Expose dans `OrderItemResource`.
5. Comparaison côté KDS contre `items.allergens_updated_at` (à vérifier que cette colonne existe — INV-04bis).

---

## INV-05 — Gates humaines G-1 à G-5

Reportées à la **conversation utilisateur** : ce sont des décisions produit (cf. reformulations GPT dans `SECOND_OPINION_GPT54PRO_SYNC_PLAN_2026-04-23.json`).

Je les remonte explicitement à l'utilisateur dans la version 2 du plan.

---

## Synthèse Phase 0

| INV | Verdict | Implication plan |
|-----|---------|------------------|
| 01 | F-04 reformulée → **F-04bis** (payload global incomplet) | 1.A à réécrire |
| 02 | F-01 confirmée + need event creation + idempotence quantitative | 1.B élargi |
| 03 | F-03 implémentable, mais polling 5–10 s + version-gating | 1.C reclassé complex |
| 04 | F-13 confirmée, migration colonne nécessaire | 2.I plus lourd qu'estimé |
| 05 | À trancher en humain (G-1..G-5) | bloque INV-05 |
