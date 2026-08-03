# Kiosk Wizard Backend Risk Audit — 2026-05-01

Status: `PASS_BACKEND_NO_REWORK`
Release implication: `READY_FOR_MANUAL_HARDWARE_CHECK_ON_THIS_SCOPE`

## 1. Scope

Audit cible après les corrections UX kiosk :

- Barre catégories borne : suppression du rail horizontal + nettoyage des fixtures `PW-*`.
- Wizard borne : composition live, design viande, suppression du libellé `Inclus` répété, suppression des chips automatiques non choisis.
- Risque à valider : le nouveau confort visuel ne doit pas devenir une source de vérité métier, ni casser pricing, quote, composition snapshot, stock, KDS, branch isolation ou paiement simulé.

Fichiers backend relus :

- `app/Http/Requests/OrderRequest.php`
- `app/Http/Requests/Concerns/ValidatesOrderItemVariations.php`
- `app/Rules/ValidJsonOrder.php`
- `app/Http/Controllers/Frontend/OrderController.php`
- `app/Services/FrontendOrderService.php`
- `app/Services/Pricing/PricingService.php`
- `app/Services/Pricing/CompositionSnapshotBuilder.php`
- `app/Services/Order/OrderQuoteService.php`
- `app/Services/Stock/StockService.php` via tests Stock
- `app/Console/Commands/CleanupTestFixturesCommand.php`
- `routes/api.php`

Fichiers frontend relus pour le contrat payload :

- `resources/js/store/modules/kioskCart.js`
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue`

## 2. Verdict

`AUDIT_VERDICT: PASS`

Aucun P0/P1 backend trouvé. Les modifications UX sont confinées à l'affichage et ne modifient pas la vérité métier. Le backend continue de reconstruire la commande depuis les IDs, le quote signé, les prix DB, les profils composer publiés, la disponibilité branch-scoped et le snapshot immutable.

## 3. Raisonnement Backend

### 3.1 UI composition != source de vérité

Le bandeau "Votre composition" lit `_wizardSelections` pour aider le client, mais ce champ n'est pas envoyé au backend. `sanitizeKioskOrderItem()` ne conserve que :

- `item_id`
- `instruction`
- `quantity`
- `item_variations`
- `item_extras`
- `item_addons` si présent

Les champs UI/prix locaux comme `_wizardSelections`, `total`, `convert_price`, `item_variation_total` local ou labels d'affichage ne deviennent pas autoritaires.

### 3.2 Branch isolation kiosk

Le client kiosk n'envoie pas `branch_id` dans le quote. Le backend résout la branche depuis `KioskMachine` :

- `OrderRequest::prepareForValidation()` merge `branch_id` serveur.
- `OrderQuoteService::resolveBranchId()` force `KioskMachine.branch_id`.
- `FrontendOrderService::myOrderStore()` réécrit aussi `branch_id` depuis la machine.

Conclusion : injection `branch_id` côté borne rejetée par architecture.

### 3.3 Pricing SSOT et anti-forge

Le frontend peut afficher un total local, mais le commit unset `total/subtotal/discount` avant création. Le prix vient de `PricingService` :

- item price depuis DB
- variation price depuis DB
- extra price depuis DB
- addon item price depuis DB
- tax recalculée backend
- discount recalculé backend

Le quote HMAC lie le payload canonique, les modifiers, les taxes et les totaux. Si le client change les items entre quote et commit, `OrderQuoteService::sealForCommit()` rejette.

### 3.4 Composer/wizard server-side

`PricingService::assertComposerStepConstraints()` relit le profil publié branch-scoped :

- profil branche prioritaire sur global
- choix sélectionnés doivent appartenir au profil publié
- min/max sélection imposés
- repeat refusé si `allow_repeat=false`
- choix indisponible/rupture rejeté
- variations/extras/addons invisibles sur kiosk/POS rejetés

Conclusion : le design du wizard peut changer, mais les règles de composition restent backend.

### 3.5 Snapshot immutable

`CompositionSnapshotBuilder` écrit un `composition_snapshot` au moment de la commande avec :

- variations + attributs
- extras
- addons
- quantités
- prix unitaires
- totaux ligne

Les ressources KDS/reçu préfèrent ce snapshot. Les renommages ou ruptures postérieures ne mutent pas les commandes historiques.

### 3.6 Stock et rupture

Les tests Stock valident :

- décrément POS et kiosk
- décrément de stockables polymorphes : item, variation, extra, addon item
- rollback si une ligne échoue
- release sur cancel/refund idempotent
- rupture auto quand stock atteint zéro
- restauration sans écraser une rupture manuelle admin
- branch isolation des stocks

Conclusion : la gestion stock centralisée reste cohérente avec la composition.

### 3.7 Paiement kiosk simulé

Le paiement card kiosk crée une commande `PENDING/UNPAID`, puis `payment-confirm` marque `PAID` et appelle `finalizePaidKioskOrder()` qui promeut vers `ACCEPT`.

Garde vérifiée :

- non-kiosk staff ne peut pas confirmer
- kiosk autre branche ne peut pas confirmer
- cash order ne peut pas être confirmé comme card
- commande unpaid ne peut pas être finalisée
- kiosk card reste non fiscalisé côté borne (`fiscal_sequence_no = null`) jusqu'au chemin fiscal POS prévu

## 4. Validation Exécutée

### Backend targeted suites

| Zone | Commande | Résultat |
| --- | --- | --- |
| Quote kiosk anti-tamper | `php artisan test tests/Feature/KioskQuoteIntegrityTest.php --stop-on-failure` | 2 passed |
| Quote replay/idempotence | `php artisan test --filter=QuoteReplayIdempotencyTest --stop-on-failure` | 3 passed |
| Composition snapshot | `php artisan test --filter=OrderItemCompositionSnapshotTest --stop-on-failure` | 6 passed |
| Pricing forge | `php artisan test --filter=PricingIntegrityTest --stop-on-failure` | 1 passed |
| Kiosk payment state | `php artisan test --filter=KioskPaymentStateMachineTest --stop-on-failure` | 5 passed |
| Cleanup fixtures | `php artisan test --filter=PlaywrightFixtureCleanupCommandTest --stop-on-failure` | 3 passed |
| Kiosk security | `php artisan test tests/Feature/KioskSecurityTest.php --stop-on-failure` | 6 passed |
| Rupture catalogue | `php artisan test tests/Feature/Menu/OrderRejectsUnavailableBranchItemTest.php --stop-on-failure` | 3 passed |
| POS/Kiosk symmetry | `php artisan test tests/Feature/Symmetry/OrderServicesContractTest.php --stop-on-failure` | 5 passed |
| Sync comprehensive | `php artisan test tests/Feature/SyncComprehensiveTest.php --stop-on-failure` | 6 passed |
| KDS immutable snapshot | `php artisan test tests/Feature/KDS/KdsSnapshotImmutableTest.php --stop-on-failure` | 4 passed |
| KDS allergen split | `php artisan test tests/Feature/KDS/KdsAllergenAggregationSplitTest.php --stop-on-failure` | 5 passed |
| Queue unique sentinel | `php artisan test tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php --stop-on-failure` | 1 passed |
| Payment confirm ability | `php artisan test tests/Feature/Sentinels/PaymentConfirmAbilitySentinelTest.php --stop-on-failure` | 1 passed |
| Payment confirm branch | `php artisan test tests/Feature/Sentinels/PaymentConfirmCrossBranchSentinelTest.php --stop-on-failure` | 1 passed |
| Payment confirm cash guard | `php artisan test tests/Feature/Sentinels/PaymentConfirmCashOrderSentinelTest.php --stop-on-failure` | 1 passed |
| Composer constraints | `php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php --stop-on-failure` | 13 passed |
| Multi-qty pricing | `php artisan test tests/Feature/Services/Pricing/PricingServiceMultiQtyTest.php --stop-on-failure` | 12 passed |
| Photo invalidation | `php artisan test tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php --stop-on-failure` | 1 passed |
| CatalogChanged | `php artisan test tests/Feature/Catalog/CatalogChangedDispatchTest.php --stop-on-failure` | 2 passed |
| Item image refresh | `php artisan test tests/Feature/Menu/ItemImageCatalogRefreshTest.php --stop-on-failure` | 1 passed |
| Composer authz | `php artisan test tests/Feature/Composer/ComposerAuthzMinimalTest.php --stop-on-failure` | 11 passed |
| Menu projection parity | `php artisan test tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php --stop-on-failure` | 6 passed |
| Menu projection composer | `php artisan test tests/Feature/Services/Menu/MenuProjectionComposerProfileTest.php --stop-on-failure` | 5 passed |
| Menu projection controller | `php artisan test tests/Feature/Http/Admin/MenuProjectionControllerTest.php --stop-on-failure` | 9 passed |
| Stock full feature dir | `php artisan test tests/Feature/Stock --stop-on-failure` | 21 passed |
| Outbox production-like | `php artisan test tests/Feature/Outbox --stop-on-failure` | 14 passed |
| Outbox rescue | `php artisan test tests/Feature/OutboxRescueTest.php --stop-on-failure` | 2 passed |

Total backend targeted: `150 passed`.

### Runtime trace

Commande :

```bash
npx playwright test tests/e2e/global-pos-kiosk-order-trace.spec.js --repeat-each=2 --reporter=line
```

Résultat : `2 passed`.

Rapport produit : `reports/antigravity/global-pos-kiosk-order-trace.json`

Preuves du dernier run :

- POS order: queue `A0001`
- Kiosk order: queue `A0002`
- POS total: `12.50`
- Kiosk total: `12.50`
- KDS reçoit les deux commandes
- KDS passe les deux commandes à `PREPARED`
- `composition_snapshot` contient variation + extra + addon
- Stock décrémenté sur 4 cibles : item, variation, extra, addon item
- Domain events présents : `OrderCreated`, `OrderStatusChanged`
- Kiosk card `transaction_id` présent, `fiscal_sequence_no = null`
- Queue counts uniques : `A0001:1`, `A0002:1`

### Fixture hygiene

Après le runtime trace :

```bash
php artisan foodking:cleanup-test-fixtures --prefix=PW-GLOBAL-TRACE --dry-run --json
php artisan foodking:cleanup-test-fixtures --prefix=PW- --dry-run --json
```

Résultat : `0` fixture résiduelle sur catégories, items, orders, stock, wizard profiles, outbox. Le test global nettoie ses fixtures correctement.

## 5. Findings

### P0

Aucun.

### P1

Aucun.

### P2

Aucun bloquant logiciel trouvé sur ce scope.

### P3 / Discipline

Le nettoyage `PW-*` doit rester une étape de fin de campagne Playwright locale. La commande de cleanup est protégée (`--apply` + `--confirm=PW-FIXTURES` + refus production), mais les tests de gestion centrale doivent continuer à auto-nettoyer leurs fixtures pour éviter le retour des catégories techniques dans la borne.

## 6. Décision

`BACKEND_SCOPE_DECISION: PASS`

Le backend supporte correctement le wizard amélioré :

- l'affichage live ne pollue pas le payload,
- le quote signé bloque les modifications de panier entre preview et commit,
- les prix restent backend,
- les règles composer restent backend,
- la rupture stock des produits/ingrédients/suppléments/addons est appliquée,
- les commandes POS et kiosk restent synchronisées vers KDS,
- les snapshots de composition sont immuables,
- les queue numbers restent uniques,
- le paiement simulé kiosk suit la state machine attendue.

Reste hors scope logiciel local : validation matérielle TPE, imprimante, lockdown OS, réseau réel, écrans KDS physiques.
