# Round 2 GPT Pro - Challenge adversarial Master Play POS / Borne / KDS

## SECTION_A - Attaques contre le V0

1. P0 - Idempotence borne probablement sur-vendue: `FrontendOrderService` fait un lookup `FrontendOrder::where('idempotency_key', $idempotencyKey)->first()` sans scope explicite branche, alors que la contrainte DB est `(branch_id, idempotency_key)`. Preuve attendue: ouvrir `app/Services/FrontendOrderService.php:126` et `:610`, comparer `tests/Feature/OrderPipeline/KioskFullFlowE2ETest.php:171` et `tests/Feature/Orders/IdempotencyBranchScopedTest.php:67`. Grep: `rg -n "where\('idempotency_key'|orders_branch_id_idempotency_key_unique" app tests database`.

2. P0 - Le V0 dit prix backend SSOT, mais ne distingue pas assez affichage local, preview serveur et montant de paiement. Le wizard garde un fallback local et construit `total` côté client pour affichage; la persistance est protégée côté serveur, mais le parcours terminal de paiement doit prouver qu'il utilise toujours le total backend post-order. Preuve attendue: `resources/js/helpers/kioskPricingPreview.js:27`, `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:1038`, `:1156`, `resources/js/store/modules/kioskCart.js:79`, puis grep `total_cents` dans `KioskPaymentComponent.vue`. Statut: UNVERIFIED pour le terminal sans lecture complète paiement.

3. P1 - La politique admin `branch_id=0` est plus subtile que V0. Le backend autorise un admin à souscrire à n'importe quel canal branche, mais l'UI KDS refuse Echo si `branchId <= 0` et passe par refresh / sync global. Preuve attendue: `routes/channels.php:32`, `KitchenDisplaySystemComponent.vue:1135`, `KdsSyncController.php:55`, `KdsSyncService.js:117`. V1 doit documenter capacité backend vs choix UI global.

4. P1 - Couverture after-commit incomplète: `OrderTableChanged` utilise bien `DispatchableAfterCommit`, mais `DispatchAfterCommitTest` ne couvre que `OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged`. Preuve attendue: `tests/Feature/DispatchAfterCommitTest.php:21` vs `app/Events/OrderTableChanged.php:17`. Ajouter le cas au futur cycle avant d'augmenter les surfaces table/KDS.

5. P1 - Sync HTTP KDS peut perdre des changements si le version-gate s'appuie seulement sur `updated_at` en secondes; le code porte déjà un TODO `status_changed_at`. Preuve attendue: `app/Services/KdsSyncService.php:129` et `:141`; ouvrir aussi `resources/js/services/KdsSyncService.js` autour du version map. V0 parle WS vs polling, mais pas assez du risque de version.

6. P1 - Bump KDS local-only est un risque opérationnel central, pas une simple dette UI. Le store persiste dans `localStorage`, `isReadyOrder` déclenche ensuite `PREPARED`; deux écrans KDS peuvent diverger. Preuve attendue: `resources/js/store/modules/kds.js:1`, `:28`, `KitchenDisplaySystemComponent.vue:1079`. V1 doit forcer décision: serveur ou règle un seul poste actif.

7. P2 - Matrice événement->surface du V0 sous-estime OSS. OSS consomme `OrderCreated` et `OrderStatusChanged`, mais pas `ItemAvailabilityChanged` ni `OrderTableChanged`. Preuve attendue: `PreparingAndReadyComponent.vue:123` et grep `ItemAvailabilityChanged` dans le dossier OSS.

## SECTION_B - Validations où le V0 est correct

1. Dérive documentaire Firebase confirmée: `docs/DEVICE_FLOW.md` dit que POS écoute Firebase pour les entrées kiosk, alors que le code moderne POS utilise Echo via `onEvents`. Sources: `docs/DEVICE_FLOW.md:16`, `resources/js/components/admin/pos/PosComponent.vue:1173`.

2. KDS écoute bien les quatre événements listés par V0: `OrderStatusChanged`, `OrderCreated`, `ItemAvailabilityChanged`, `OrderTableChanged`. Source: `KitchenDisplaySystemComponent.vue:1142`.

3. Le filtre KDS `branch_id` est exact et non `LIKE`, avec limite 50 assumée. Sources: `KitchenDisplaySystemOrderService.php:58`, `:84`, `:106`; test de régression: `tests/Feature/KdsBranchFilterExactTest.php:17`.

4. Les événements majeurs de commande sont conçus pour après commit. Sources: `OrderCreated.php:14`, `OrderStatusChanged.php:11`, `ItemAvailabilityChanged.php:23`, plus test `DispatchAfterCommitTest.php:44`.

5. `OrderStatus` est utilisé comme enum côté backend KDS et comme enum JS côté surfaces, pas comme chaîne magique dans le flux principal. Sources: `KitchenDisplaySystemOrderService.php:53`, `resources/js/enums/modules/orderStatusEnum.js:3`, `KioskWaitingComponent.vue:155`.

6. Pricing backend SSOT est défendu à la persistance: `FrontendOrderService` retire `total/subtotal/discount` du payload et recalcule; `PricingPreviewController` annonce aucun prix accepté du client. Sources: `FrontendOrderService.php:192`, `PricingPreviewController.php:17`.

7. Le V0 a raison sur l'idempotency key client borne: le store génère et réutilise `X-Idempotency-Key`. Source: `resources/js/store/modules/kioskCart.js:447`.

## SECTION_C - Matrice événement -> surface complétée

| Event | KDS | Borne | POS | OSS | Verdict |
|---|---|---|---|---|---|
| `OrderStatusChanged` | Oui: refresh debounced | Oui: `KioskWaitingComponent`, commande courante seulement | Oui: recharge commandes kiosk cash | Oui: liste + chime PREPARED | Confirmé, branch-scoped |
| `OrderCreated` | Oui: refresh debounced | Oui: confirme queue number de sa commande | Oui: notification + reload kiosk cash orders | Oui: reload liste | Confirmé, mais Borne n'est pas consommateur global |
| `ItemAvailabilityChanged` | Oui: refresh KDS | Oui: `KioskAppComponent`, prune menu/cart/offline | Oui: grise item + prune POS cart | Non trouvé | Confirmé KDS/Borne/POS; OSS N/A |
| `OrderTableChanged` | Oui: handler table/flash | Non | Non consommateur direct; POS/floorplan est producteur probable | Non | KDS-only consumer; clarifier producteur POS dans V1 |

## SECTION_D - Métriques mesurables V1

1. Latence commit->surface p95 par event et branche: WS p95 <= 2s; mode dégradé KDS/POS <= 13s; OSS <= 65s si polling/WS connecté selon config.

2. Couverture contractuelle event x surface: 100% des events de la matrice ont au moins un test producteur payload, un test consumer JS, un test branch isolation, et un test after-commit; inclure `OrderTableChanged`.

3. Anti-duplication listeners: sur 10 remounts d'une surface, un seul listener actif par `(branch,event)`; zéro double chime/toast sur deux broadcasts avec même `correlation_id` en 10 minutes.

## VERDICT

GPT_CHALLENGE_VERDICT: MERGE_V0_WITH_REVISIONS

Le V0 est solide comme carte d'architecture, mais il est trop confiant sur trois angles: idempotence borne branch-scoped, statut réel du fallback pricing côté UI/paiement, et couverture after-commit/matrice pour `OrderTableChanged` et OSS. Il ne faut pas réécrire le breakdown; il faut durcir V1 avec preuves fichier+test.

## Liste priorisée Master Play V1

P0 - Vérifier/corriger lookup idempotency `FrontendOrderService` en `(branch_id, idempotency_key)`.
P0 - Prouver que le montant envoyé au terminal paiement vient du backend après création, pas du fallback local.
P0 - Décider gouvernance bump KDS: serveur ou règle opératoire un seul écran actif.
P0 - Ajouter `OrderTableChanged` au test after-commit.
P1 - Finaliser la matrice événement->surface avec owners et tests.
P1 - Documenter admin `branch_id=0`: pas Echo global UI, sync/polling global, override API possible.
P1 - Traiter TODO `status_changed_at` / version KDS sync.
P1 - Ajouter scénario 2 onglets KDS + double remount listener.
P1 - Aligner `docs/DEVICE_FLOW.md` sur Echo/Pusher + FCM séparé.
P2 - Définir explicitement OSS hors `ItemAvailabilityChanged` / `OrderTableChanged`.
P2 - Mesurer cap KDS 50 et alerte saturation.
