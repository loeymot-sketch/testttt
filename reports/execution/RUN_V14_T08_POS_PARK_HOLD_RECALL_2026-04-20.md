# RUN V14 T08 — POS Park/Hold/Recall

## Status

`BLOCKED`

Implémentation T08 réalisée, mais la clôture en `PASSED` est bloquée par la régression PHP globale demandée, qui reste rouge à cause de **3 échecs préexistants hors périmètre T08**.

## Execute Delegation

`foodking-complex-implementer (GPT-5.4)`

## Scope Executed

Implémenté strictement dans le périmètre autorisé :

- `database/migrations/2026_04_20_200000_create_pos_parked_orders_table.php`
- `app/Models/PosParkedOrder.php`
- `app/Services/PosParkedOrderService.php`
- `app/Http/Controllers/Admin/Pos/ParkedOrderController.php`
- `routes/api.php`
- `resources/js/store/modules/posParked.js`
- `resources/js/store/index.js`
- `resources/js/components/admin/pos/ParkedOrdersComponent.vue`
- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/languages/fr.json`
- `resources/js/languages/en.json`
- `resources/js/languages/ar.json`
- `app/Console/Commands/PosPurgeParkedOrders.php`
- `tests/Feature/PosParkedOrderTest.php`
- `tests/js/posParked.spec.js`

## DB Schema Final

Table créée : `pos_parked_orders`

Colonnes :

- `id`
- `branch_id`
- `user_id`
- `label`
- `payload_json` (`LONGTEXT`)
- `preview_total` (`decimal(12,2)`)
- `items_count` (`unsignedSmallInteger`)
- `idempotency_token`
- `created_at`
- `updated_at`

Contraintes :

- index `pos_parked_branch_user_idx` sur `(branch_id, user_id, created_at)`
- unique `pos_parked_user_idem_uniq` sur `(user_id, idempotency_token)`
- FK `branch_id -> branches.id`
- FK `user_id -> users.id`

Idempotence migration :

- `up()` sort immédiatement si la table existe déjà
- `down()` fait `Schema::dropIfExists('pos_parked_orders')`

## Endpoints Added

Sous `/api/admin/pos/parked-orders` :

- `GET /` → liste des commandes parkées de l'opérateur courant sur sa branche
- `POST /` → park du snapshot panier courant
- `GET /{id}` → recall transactionnel + suppression atomique
- `DELETE /{id}` → discard

Protection :

- middleware auth admin existant du groupe `/api/admin`
- middleware `permission:pos` sur le controller
- check explicite `request()->user()->id === auth()->id()` dans le controller
- filtres `user_id + branch_id` sur list/recall/discard

## Store Actions

Module Vuex `posParked` enregistré dans `resources/js/store/index.js`.

Actions livrées :

- `fetchList`
- `park`
- `recall`
- `discard`

Comportement :

- `park` sérialise le snapshot courant du panier
- `park` génère un `idempotency_token` côté front si absent
- `recall` restaure `posCart` via les actions existantes `resetCart`, `lists`, `discount`
- `discard` retire l'entrée du state local après suppression backend

## Flow Implemented

Flow park :

1. Bouton `Park` dans `PosComponent.vue`
2. prompt label optionnel
3. snapshot du panier courant + checkout local
4. POST backend
5. création `pos_parked_orders`
6. reset du panier courant côté POS

Flow recall :

1. Bouton `Parked (N)` ouvre le drawer `ParkedOrdersComponent`
2. `fetchList` au moment de l'ouverture
3. clic `Restore`
4. backend fait `lockForUpdate()` + clone + delete dans transaction
5. frontend restaure `posCart`
6. `PosComponent.vue` réhydrate `customer_id`, `order_type`, `table/address`, `delivery_inline`

Flow discard :

1. clic `Discard`
2. DELETE backend
3. retrait immédiat de la liste côté store

## Invariants Checked

- **Isolation operator + branch** : enforced dans le service sur `listForOperator`, `recall`, `discard`
- **Aucun write dans `orders` / `order_items`** : T08 n'écrit que dans `pos_parked_orders`
- **Recall transactionnel** : `DB::transaction()` + `lockForUpdate()`
- **Idempotency** : token frontend + contrainte unique backend + rattrapage duplicate key dans le service
- **Aucune zone gelée touchée** : `OrderService`, `FrontendOrderService`, `PricingService`, `PaymentService`, `PaymentComponent.vue`, `ItemComponent.vue` non modifiés
- **Dispatch ordering** : aucun nouvel event/job ajouté dans T08
- **Pricing SSOT** : non touché
- **OrderStatus enum** : non touché

## Tests

### T08 ciblés

- `php artisan test tests/Feature/PosParkedOrderTest.php` → **6/6 verts**
- `npx vitest run tests/js/posParked.spec.js` → **4/4 verts**

### Sous-suite Vitest demandée

- `npx vitest run tests/js/posParked.spec.js tests/js/posCart.spec.js tests/js/PosComponent.spec.js` → **8/8 verts**

### Régression PHP demandée

- `php artisan test --filter='Pos|Order|Pricing'` → **BLOCKED**

Résultat :

- **347 passed**
- **3 skipped**
- **3 failed**

Échecs observés, **hors scope T08** :

1. `Tests\Feature\DispatchAfterCommitTest`
   - `OrderCreated` dispatché malgré rollback
2. `Tests\Feature\DispatchAfterCommitTest`
   - `OrderStatusChanged` dispatché malgré rollback
3. `Tests\Feature\Orders\OrderAllergenSnapshotComposedTest`
   - sentinelle allergènes extras toujours rouge (`FINDING_BACK_DEFERRED`)

## Files Changed

- `database/migrations/2026_04_20_200000_create_pos_parked_orders_table.php`
- `app/Models/PosParkedOrder.php`
- `app/Services/PosParkedOrderService.php`
- `app/Http/Controllers/Admin/Pos/ParkedOrderController.php`
- `routes/api.php`
- `resources/js/store/modules/posParked.js`
- `resources/js/store/index.js`
- `resources/js/components/admin/pos/ParkedOrdersComponent.vue`
- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/languages/fr.json`
- `resources/js/languages/en.json`
- `resources/js/languages/ar.json`
- `app/Console/Commands/PosPurgeParkedOrders.php`
- `tests/Feature/PosParkedOrderTest.php`
- `tests/js/posParked.spec.js`

## Post-Execute

- `EXECUTE_DELEGATION: foodking-complex-implementer (V14_09_T08_POS_PARK_HOLD_RECALL)` écrit dans `reports/post_execute_latest.log`
- `.cursor/hooks/post-execute.sh` exécuté

## TODO

- `T08-bis` : auto-park sur logout / inactivity reste explicitement hors scope
- régression PHP globale à requalifier par validator/planner à cause des 3 échecs préexistants hors T08
