# EXECUTE BRIEF — CV1-M02-SENTINEL-BASELINE (M-02)

## INVIOLABLE
1. Lis dans cet ordre :
   - `AGENTS.md` (parcours obligatoire)
   - `missions/CV1-M02-SENTINEL-BASELINE/input.json`
   - `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` (sections 0, 2 ancrages file:line, mission M-02)
   - `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md` (matrice produite par M-01 si dispo)
2. **Allowlist stricte** : uniquement les chemins listés `input.json.allowlist`. Hors liste → `risks: ["SCOPE_PRESSURE: ..."]` + stop.
3. **Tu ne touches AUCUN code produit.** Pas de `app/`, `resources/`, `routes/`, `database/migrations/`, `config/`. Les sentinels sont des **tests** + des **scripts shell**.
4. **Tu n'approuves aucun gate.**

## OBJECTIF EXACT

Créer **18 sentinels fail-first** + **4 lint statiques**, avec baseline rouge documentée. Chaque sentinel doit :
- échouer **pour la raison documentée** (pas une erreur d'environnement, pas un crash de bootstrap)
- citer dans son docblock le `FK-ID`, le rapport source, et la mission qui apportera le fix (`M-XX`)
- avoir une commande exécutable précise dans la baseline log

Quand un fix sera implémenté plus tard (M-04…M-21), le sentinel correspondant **passera au vert** sans modification du test.

## CARTOGRAPHIE PRÉ-ANALYSÉE (file:line — utilise-la)

Issue de `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` § 2. À utiliser directement, ne pas redécouvrir.

### `payment-confirm` (sentinels #1-#4, #6)
- Route : `routes/api.php:889-895`
- Controller : `app/Http/Controllers/Frontend/OrderController.php:77-151`
- Sanctum check : L85-96 (insuffisant — pas de `kiosk:order` ability check, pas de re-vérif `branch_id` machine)
- Transaction `PAID` : L101-118
- Service : `app/Services/FrontendOrderService.php:791` (`finalizePaidKioskOrder`)

### Branch isolation (sentinels #7-#11)
- Fuites LIKE : `app/Services/OrderService.php:151,194,230,267,1920` ; `app/Services/FrontendOrderService.php:99`
- OK strict : `app/Services/TransactionService.php:33-35` ; `app/Services/KitchenDisplaySystemOrderService.php:84-90` (cast int + `=`)
- `branch_id=0` à scoper : `app/Services/OrderService.php:610` (posOrderStore), L1793-1795 (destroy)

### KDS (sentinels #12-#13)
- Request : `app/Http/Requests/OrderStatusRequest.php:15-35` (authorize) + L45-47 (`status: required|numeric` SEUL — **pas** d'`expected_status` body)
- Service : `app/Services/KitchenDisplaySystemOrderService.php:117-168` (`$expectedFrom = $locked->status` L122 — vient du modèle, pas du body)
- Front : `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` — 0 occurrence `expected_status`

### POS cash kiosk (sentinel #14)
- Pas de route POS dédiée pour collecte cash kiosk → utilise actuellement `kds-order/change-status` (à interdire)

### POS subtotal forgery (sentinel #15)
- `PosOrderRequest` accepte subtotal client → `PricingService` doit recalculer

### Queue number (sentinel #16)
- `OrderService::posOrderStore` ~L828-854 — fallback microtime sans unique index

### Kiosk (sentinels #17-#18)
- Offline ID : `resources/js/helpers/kioskOfflineQueue.js:135,330` — `offline_${savedAt}_...`
- Détection : `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:292` — `startsWith('offline_')`
- `status: 16` littéral : `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:392`

### `PaymentComponent.vue` mutations (sentinel JS prop mutation)
- 16+ mutations : `resources/js/components/admin/pos/PaymentComponent.vue:179, 192-193, 205-217, 221, 237-239, 250-265`

## SPÉCIFICATION DÉTAILLÉE — CHAQUE SENTINEL

Pour **chaque** test PHP Feature, classe `extends Tests\TestCase`, namespace `Tests\Feature\Sentinels`, méthodes `setUp` → `RefreshDatabase` ou équivalent. Chaque test :
- 1 docblock en tête : `@FK-ID FK-XXX | @source <rapport> | @fix-mission CV1-MXX-... | @reason <pourquoi rouge maintenant>`
- 1 méthode `test_*` qui assert l'invariant attendu
- assertion qui échoue actuellement → assertion qui passera après fix

### #1 `PaymentConfirmAbilitySentinelTest.php`
- Crée user **non-kiosk** (rôle `cashier`), token Sanctum standard.
- Crée commande PENDING/UNPAID branch X.
- POST `frontend/order/{order}/payment-confirm` avec body valide (`transaction_id`, `card_type`, `payment_method`).
- **Assert** : `response()->assertStatus(403)` ET `Order::find($id)->payment_status !== 'PAID'`.
- Actuellement : controller ne check pas l'ability `kiosk:order` (cf. cartographie L85-96) → test échoue (réponse 200, statut muté).

### #2 `PaymentConfirmCrossBranchSentinelTest.php`
- Crée KioskMachine branche A + token avec ability `kiosk:order`.
- Crée commande branche B.
- POST `payment-confirm` avec token machine A.
- **Assert** : 403 + payment_status inchangé.

### #3 `PaymentConfirmCashOrderSentinelTest.php`
- Crée commande `payment_method=cash`.
- POST `payment-confirm` avec body `payment_method=card`.
- **Assert** : 422 + payment_status inchangé.

### #4 `PaymentConfirmConcurrencySentinelTest.php`
- 2 requêtes parallèles (utiliser `\Illuminate\Support\Facades\DB::transaction` + `Event::fake()`) sur même `transaction_id`.
- **Assert** : exactement **1** event `OrderStatusChanged` dispatché ; idempotent.

### #5 `OrderStatusNoopSideEffectsSentinelTest.php`
- `OrderService::changeStatus($order, CANCELED)` 2× successifs.
- **Assert** : `PaymentService::cashBack` appelé exactement **1** fois (mock/spy).
- Ancrage : `OrderService.php:1505,1568` — risque double cashback.

### #6 `CleanupVsConfirmRaceSentinelTest.php`
- Setup commande PENDING ; lancer `CleanupStalePendingKioskOrders` qui marque REJECTED.
- Puis POST `payment-confirm` tardif.
- **Assert** : 422 + audit log `payment_late_after_cleanup` créé.

### #7 `OrderListBranchExactnessSentinelTest.php`
- Crée commandes branch_id=1, branch_id=10, branch_id=100.
- GET `/admin/order?branch_id=1` (route admin standard).
- **Assert** : retourne uniquement branch_id=1 ; PAS branch_id=10 ni 100.
- Actuellement échoue car `OrderService.php:151` utilise `LIKE %1%` → matche 10, 100, 1000.

### #8 `OrderShowBranchGuardSentinelTest.php`
- User staff branche A.
- GET `/admin/order/{id}` où order.branch_id = B.
- **Assert** : 403.

### #9 `TransactionBranchExactnessSentinelTest.php`
- Idem #7 sur `TransactionService::list`.

### #10 `FiscalZBranchExactnessSentinelTest.php`
- Crée commandes payées branche A et B le même jour.
- Génère Z branche A.
- **Assert** : Z agrège uniquement branche A.

### #11 `OssAdminBranchPolicySentinelTest.php`
- Staff branche A tente `branch_id=0` (global) sur OSS endpoint.
- **Assert** : 403. Admin global avec `branch_id=0` autorisé.

### #12 `KdsTransitionWhitelistSentinelTest.php`
- Chef KDS POST `kds-order/change-status` avec `status=CANCELED` (16).
- **Assert** : 422 (whitelist KDS = ACCEPT/PREPARING/PREPARED uniquement).

### #13 `KdsExpectedStatusConflictSentinelTest.php`
- Order status PREPARING (versioned).
- POST 2× simultanément avec `expected_status=PREPARING` → second doit recevoir 409.
- Actuellement : `OrderStatusRequest.php:45-47` ne lit pas `expected_status` du body → test échoue.

### #14 `PosCashEndpointSentinelTest.php`
- Vérifie qu'il existe une route `POST /api/admin/pos/collect-kiosk-cash/{order}` (ou équivalent dédié).
- **Assert** : `Route::has('admin.pos.collect-kiosk-cash')` true OU 404 sur la route attendue.
- Actuellement : route absente → test échoue ; après M-06, route existera.

### #15 `PosSubtotalForgerySentinelTest.php`
- POST `/api/admin/pos` avec subtotal client = 1€ alors que items réels = 100€.
- **Assert** : commande créée avec subtotal **backend** = 100€, et permission discount appliquée sur 100€ pas 1€.

### #16 `QueueNumberUniquenessSentinelTest.php`
- Création concurrente (utilise `\Illuminate\Support\Facades\Queue::fake()` + parallel) de 50 commandes même branche, même jour.
- **Assert** : 50 `queue_number` distincts.

### #17 `tests/js/sentinels/kioskOfflineIdPrefix.spec.js` (Vitest)
- Stub `kioskOfflineQueue.saveOrder` ; vérifier que tout id retourné `startsWith('offline_')`.
- Ancrage : `kioskOfflineQueue.js:135,330`.

### #18 `tests/Playwright/sentinels/kioskCbTrOfflineRefused.spec.js`
- Lance kiosk en mode offline (intercepte requêtes via Playwright).
- Tente CB/TR.
- **Assert** : bouton CB désactivé OU message d'erreur affiché.

### Bonus statiques (lints)

#### `scripts/lint-fk-enum-status.sh`
- `grep -RnE "status[^a-zA-Z_]*[:=][^a-zA-Z_]*1[567]" resources/js/ app/` (catch `status: 16`, `status=15`, etc.)
- exit 1 si trouvé hors fichiers d'enum/test.
- Doit lever **au moins** la ligne `KioskWaitingComponent.vue:392`.

#### `scripts/lint-fk-legacy-imports.sh`
- `grep -RnE "from ['\"](.*kiosk_implementation|borne \(Remix\)|pos-wizard)" resources/`
- exit 1 si trouvé.

#### `scripts/lint-fk-branch-isolation.sh`
- `grep -RnE "where\(\s*['\"]branch_id['\"]\s*,\s*['\"]like['\"]" app/`
- exit 1 si trouvé.
- Doit lever `OrderService.php:151,194,230,267,1920` et `FrontendOrderService.php:99`.

#### `scripts/lint-fk-bundle-legacy.sh`
- Si `public/build/` existe : `grep -l "kiosk_implementation" public/build/*.js` → exit 1.
- Sinon : exit 0 avec message `[skip] no build present`.

#### `tests/js/sentinels/paymentComponentPropMutation.spec.js`
- Lit `resources/js/components/admin/pos/PaymentComponent.vue` en raw string.
- Compte occurrences regex `this\.\$props\.props\.|this\.props\.form\.\w+\s*=` → assert >= 16 (baseline actuelle).
- Documenter dans le test : "ce test inverse-passera vert quand mutation === 0 après M-06b".

## BASELINE LOG

`reports/sentinels/CAISSE_V1_BASELINE_RUN_2026-04-25.log` — format ligne par ligne :

```
[2026-04-25] CAISSE V1 — Sentinel Baseline Run

#01 PaymentConfirmAbilitySentinelTest          STATUS=RED  CMD=`php artisan test --filter=PaymentConfirmAbilitySentinelTest`  FK=FK-XXX  FIX=CV1-M06  REASON=controller ne check pas ability kiosk:order (OrderController.php:85-96)
#02 PaymentConfirmCrossBranchSentinelTest      STATUS=RED  ...
...
#22 paymentComponentPropMutation               STATUS=RED  CMD=`npx vitest run tests/js/sentinels/paymentComponentPropMutation.spec.js`  FK=FK-XXX  FIX=CV1-M06b  REASON=16+ mutations directes de props
```

## INDEX SENTINELS

`reports/sentinels/CAISSE_V1_SENTINEL_INDEX.md` — table Markdown :
| # | Sentinel | Type | Cible (file:line) | FK-ID | Mission de fix | Status |

## INTERDITS

- Toucher `app/`, `resources/`, `routes/`, `database/migrations/`, `config/`.
- Marquer un sentinel `@skip` ou `@todo` (s'il ne peut pas exister, mettre dans `risks` du JSON).
- Faire passer un sentinel au vert artificiellement (mocks qui contournent l'invariant).
- Inventer des fichiers source : si un fichier cité n'existe pas, lever `risks` et stopper le sentinel concerné.

## SI BLOCAGE

- Test ne peut pas être écrit (manque modèle/factory) → `risks: ["ESCALATION: <sentinel> nécessite factory <X> ou seed <Y>"]`, sentinel non créé, baseline log marque `STATUS=BLOCKED`.
- Lint bash incompatible macOS BSD vs GNU → utiliser `grep -E` et `awk` POSIX.
