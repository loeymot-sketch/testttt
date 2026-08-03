# Round4 — Lane Performance / N+1 / index (endpoints chauds)

DB: foodking_e2e (READ-ONLY). orders=2968, order_items=3006, audit_logs=4637.
Index présents: orders → idx_orders_datetime, idx_orders_status, idx_orders_branch_status, idx_orders_user_id + uniques fiscal/business_date. order_items → idx_order_items_order_id. audit_logs → branch_id+created_at, resource+resource_id, user_id. **Aucun index manquant prouvé sur les WHERE/ORDER BY chauds.**

Endpoints vérifiés PROPRES (eager-load correct, pas de N+1):
- KDS feed `KitchenDisplaySystemOrderService::list/orderItems/historyToday` → `Order::with(['orderItems','address','user'])`, fenêtre `order_datetime` en range (sargable, idx_orders_datetime), cap 50/51. OK.
- Catalogue `ItemService::simpleList` → `Item::with('media','category','offer')` + overlay availability batché (`ItemBranchAvailability::whereIn('item_id',$ids)`). OK.
- Listes commandes online/pos/historique → `OrderService::list` eager-load `transaction, orderItems.orderItem.media, orderItems.orderItem.category, branch, user` ; rendu par `SimpleOrderResource` qui consomme bien `orderItems` (resolveItemsForTracker) → eager-load justifié, pas N+1.
- `DashboardService::topItemsOfDay` / `auditTrail` → résolution des noms/users en un seul `whereIn` (commentés "avoid N+1"). OK.

---

## [P2] app/Services/DashboardService.php:309-314 — customerStates: 18× full-scan + `->get()->count()` (hydratation modèles inutile)
- **Repro**: widget dashboard `GET .../dashboard/customer-states`. Boucle sur 18 créneaux horaires ; chaque itération exécute `(clone $order)->where('order_datetime'>=..)->where('order_datetime'<..)->whereTime('order_datetime',>=)->whereTime('order_datetime',<=)->get()->count()`.
- **Evidence**:
  - `EXPLAIN SELECT * FROM orders WHERE order_datetime>='2026-06-01' AND order_datetime<'2026-07-01' AND TIME(order_datetime)>='12:00:00' AND TIME(order_datetime)<='12:59:00'` → `Table scan on orders (cost=322 rows=2975)`. La range par défaut = mois courant = 2184/2975 lignes (73%), donc l'optimiseur ignore idx_orders_datetime ; `TIME(order_datetime)` est **non-sargable** (fonction sur colonne).
  - `->get()->count()` **hydrate des modèles Eloquent Order** (toutes colonnes) juste pour les compter, au lieu d'un `COUNT(*)` SQL. Répété **18 fois** par chargement du dashboard.
  - Coût total ≈ 18 table-scans + hydratation de ~2184 lignes réparties sur les buckets. Sur 2968 orders mono-poste c'est tolérable mais c'est le pattern le plus coûteux du dashboard et il grossit linéairement avec l'historique.
- **Lentille**: full-scan répété (rubrique = P2) + anti-pattern `get()->count()`.
- **Reco** (non-frozen, DashboardService non listé frozen): remplacer la boucle par **une seule requête** `selectRaw('HOUR(order_datetime) AS h, COUNT(*) AS c')->where(range)->groupBy('h')` puis remapper sur `$timeArray`. À défaut, au minimum remplacer `->get()->count()` par `->count()` (élimine l'hydratation). Garde la sémantique Paris-local (whereTime déjà documenté backlog KDS-ADV3C-12).
- **frozen_touch**: non.

---

## [P3] app/Http/Resources/OrderResource.php:41-42 — N+1 par ligne (`->load('roles','media')` + `transaction->load('order')`) dans une collection
- **Repro**: `TableOrderController@index` (l.39) et `EmployeeController@index` (l.111) font `OrderResource::collection($orderService->list/userOrder(...))`. `OrderService::list` eager-load `user` mais **pas** `user.roles`/`user.media` ni `transaction.order`. Pour CHAQUE order du résultat, `OrderResource::toArray` exécute `$this->user->load('roles','media')` (jusqu'à 2 requêtes) et `$this->transaction?->load('order')` (1 requête).
- **Evidence**: `OrderService::list` l.134-140 charge `['transaction','orderItems.orderItem.media','orderItems.orderItem.category','branch','user']` — `roles`/`media`/`transaction.order` absents. `OrderResource.php:41` `new OrderUserResource($this->user->load('roles','media'))`, `:42` `new TransactionResource($this->transaction?->load('order'))`. → ~3 requêtes × N lignes.
- **Lentille**: N+1 sur liste bornée (dine-in: order_type=25 → 31 lignes) → rubrique "N+1 sur 50 lignes = P3".
- **Reco**: eager-load `user.roles`, `user.media`, `transaction.order` dans `OrderService::list/userOrder` (ou un `->with()` ciblé pour ces 2 contrôleurs), supprimer les `->load()` par ligne dans le Resource. (Note hors-lane: `$this->user->load()` plante si `user` NULL sur order POS/kiosk — correctness, à signaler à la lane sécurité/robustesse.)
- **frozen_touch**: non.

---

## [P3] app/Services/DashboardService.php:99-108 — orderStatistics: 10 requêtes COUNT séparées (1 GROUP BY possible)
- **Repro**: `GET .../dashboard/order-statistics` exécute 10 `COUNT(*)` clonés (total + 9 statuts).
- **Evidence**: `EXPLAIN` d'un count individuel = `Index lookup on orders using idx_orders_status (status=7)` → **chaque requête est indexée** (pas de full-scan), donc impact faible. Mais 10 allers-retours là où `GROUP BY status` (filtré par range, parent_order_id NULL) en ferait 1.
- **Lentille**: micro-dette perf, indexé, mono-poste → P3.
- **Reco**: optionnel — collapser en un seul `selectRaw('status, COUNT(*)')->groupBy('status')` + total. Faible priorité (déjà indexé).
- **frozen_touch**: non.
