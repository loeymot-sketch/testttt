# CODEX -> CLAUDE MAX COMPLETION AUDIT - 2026-04-27

## Verdict court

VERDICT: NEEDS_CLAUDE_REVIEW_AND_REWORK_PLANNING

Codex a avance les demandes utilisateur sans forcer les zones verrouillees. Les correctifs runtime front/kiosk/POS et le nettoyage francais non destructif sont partiellement valides. Le dashboard catalogue a maintenant un vrai point d'entree de pilotage sur `/admin/items`, mais ce n'est pas encore le control plane complet Stock V2 / live orders / handover / KDS / OSS demande dans l'ultra-plan.

## Contraintes observees

- Cycle actif: `PHASE2_TRAIN_A_V1_RELEASE_PREP_2026-04-27`, masterplay Caisse V1 actif.
- Collision activity-log: scope large `app,tests` reserve par `CV1-LOT-P06-PARK-TTL`; Codex n'a donc pas modifie `app/` ni `tests/Feature` dans cette passe finale dashboard.
- Frozen order files: `app/Services/OrderService.php` et `app/Services/FrontendOrderService.php` ont ete unstaged precedemment de facon non destructive, mais leur contenu doit rester audite mission par mission avant restaging.
- Migration / stock quantitative: pas de migration nouvelle sans gate humain.

## Ce qui a ete implemente ou consolide

### 1. Hygiene frozen / gouvernance

- Gate cree: `docs/gates/GATE_FROZEN_ORDERSERVICE_UNLOCK_2026-04-27.md`.
- Rapports crees:
  - `reports/audit/FK_REM_T0_GOVERNANCE_UNLOCK_2026-04-27.md`
  - `reports/audit/FK_REM_EXECUTION_CONTROLLER_2026-04-27.md`
- Action appliquee: unstage non destructif des fichiers order sensibles, working-tree conserve.
- Preuve: `bash .cursor/hooks/safety-check.sh` PASS apres isolation.

### 2. Nettoyage francais / residue Bangladesh

- Ajoute `app/Console/Commands/CleanupDemoDataCommand.php`.
- Commande en dry-run uniquement: `php artisan foodking:cleanup-demo-data --dry-run --json`.
- Ajuste `database/seeders/CurrencyTableSeeder.php`: `EUR` devient la devise seed principale; suppression du seed demo `BDT/INR/NGN/ARS`.
- Ajuste `resources/js/i18n.js`: runtime charge `fr`, `en`, `ar`; `bn/de` ne sont plus bundles automatiquement.
- Ajoute `tests/Feature/Sentinels/FrenchRuntimeNoBangladeshDemoDataSentinelTest.php`.

Limite: le dry-run MySQL local direct a echoue sous sandbox (`SQLSTATE[HY000] [2002] Operation not permitted`), mais la logique a ete validee en PHPUnit SQLite.

### 3. Kiosk/POS blockers deja couverts par tests

Validations passees precedemment:

- `npx vitest run tests/js/kioskRtl.spec.js tests/js/kioskSettingsStore.spec.js tests/js/userReportedBlockersRuntime.spec.js`
- `php artisan test tests/Feature/Order/PosWalkInAndDeliveryFeeTest.php`
- `php artisan test tests/Feature/Order/QuoteBindingTest.php`
- `php artisan test tests/Feature/Order/PosOrderRequestNullableTotalTest.php`

Couverture verifiee:

- Kiosk sans header admin visible dans les tests runtime existants.
- POS walk-in sans `customer_id` manuel.
- Livraison: matrice fee couverte cote PHPUnit pour la regle validee par le user.
- Quote binding/pricing reste cote backend.

Limite: pas de preuve navigateur finale sur la session actuelle pour kiosk/POS/KDS/OSS.

### 4. Queue number

Validations passees precedemment:

- `php artisan test tests/Feature/Order/QueueNumberConcurrencyTest.php`
- `php artisan test tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php`

Limite critique: D-M13 doit etre clos par gate/migration unique DB avant de declarer "zero double numero" en production.

### 5. Dashboard catalogue / disponibilite

Fichier modifie:

- `resources/js/components/admin/items/ItemListComponent.vue`

Ajouts:

- Bandeau "Pilotage catalogue" au-dessus de la liste produits.
- Compteurs produits/categories/actifs/indisponibles.
- Acces direct produits, categories, offres, disponibilites.
- Table produits enrichie avec miniature image.
- Disponibilite conservee via `AvailabilityToggleComponent`.
- Prix affiche depuis `item.flat_price`; aucune logique de calcul prix cote frontend.

Validation:

- `git diff --check -- resources/js/components/admin/items/ItemListComponent.vue` PASS.
- `npx vitest run tests/js/adminAvailabilityToggle.spec.js tests/js/userReportedBlockersRuntime.spec.js` PASS: 2 files, 6 tests.
- `npm run production` PASS apres compilation; processus notifier Laravel Mix a du etre stoppe car il gardait npm ouvert.

Limite: ce n'est pas encore un module dashboard complet avec drawer avance stock/prix/offres/variations/extras. Il consolide l'interface existante et rend la gestion visible, mais le control plane complet reste T2/T3.

## Validation globale executee par Codex

PASS:

- `bash .cursor/hooks/safety-check.sh`
- `php -l app/Console/Commands/CleanupDemoDataCommand.php`
- `php -l tests/Feature/Sentinels/FrenchRuntimeNoBangladeshDemoDataSentinelTest.php`
- `php artisan test tests/Feature/Sentinels/FrenchRuntimeNoBangladeshDemoDataSentinelTest.php`
- `php artisan test tests/Feature/Order/QueueNumberConcurrencyTest.php`
- `php artisan test tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php`
- `php artisan test tests/Feature/Order/PosWalkInAndDeliveryFeeTest.php`
- `php artisan test tests/Feature/Order/QuoteBindingTest.php`
- `php artisan test tests/Feature/Order/PosOrderRequestNullableTotalTest.php`
- `npx vitest run tests/js/kioskRtl.spec.js tests/js/kioskSettingsStore.spec.js tests/js/userReportedBlockersRuntime.spec.js`
- `npx vitest run tests/js/adminAvailabilityToggle.spec.js tests/js/userReportedBlockersRuntime.spec.js`
- `npm run production`

WARN:

- `git diff --check` global echoue a cause d'un trailing whitespace preexistant dans `reports/audit/_TERMINAL_CONTEXT_BRIEF.md:66`.
- Le check cible sur `ItemListComponent.vue` passe.
- Browser E2E complet non execute dans cette passe finale.

## Demandes utilisateur restantes, partie par partie

### Borne client

Etat: partiellement valide par tests runtime, pas par navigateur final.

Points a verifier par Claude:

- Aucun logo/header admin/action caisse sur `/kiosk/idle` et `/kiosk/payment`.
- Aucun lien direct de la borne vers admin/POS.
- URL kiosk conserve une experience verrouillee client; l'acces admin doit passer par POS/dashboard, pas par un PIN kiosk.
- Le message "connexion perdue / aucune connexion" ne doit pas apparaitre si backend et broadcast sont sains.

### Caisse POS

Etat: tests backend POS walk-in passent.

Points a verifier par Claude:

- Creation commande emporter sans `customer_id`.
- Livraison avec adresse: confirmer le calcul geocoding/distance en environnement avec Google Maps key active.
- Pas de calcul de prix final cote front.
- Pas de blocage UI par faux etat offline.

### Livraison

Regle utilisateur retenue:

- `0 < distance <= 5 km`: 5 EUR.
- `distance > 5 km`: 5 EUR + 1 EUR par km commence au-dela de 5 km.

Point a auditer:

- Confirmer que l'arrondi kilometre commence correspond bien au business attendu dans `PosWalkInAndDeliveryFeeTest`.
- Confirmer le comportement si Google Maps/geocoding est indisponible.

### Queue

Etat: tests concurrence passent.

Point critique:

- Ne pas declarer production OK tant que la contrainte unique DB finale D-M13 n'est pas fermee/gatee.

### Paiement simulation

Etat: pas audite navigateur dans cette passe.

Points a verifier:

- Cash/takeaway POS.
- Kiosk payment simulation.
- Card/manual terminal si present.
- Aucune incoherence entre status paiement et status commande.

### Dashboard catalogue

Etat: interface `/admin/items` amelioree.

Points a verifier:

- UX du nouveau bandeau: route products/categories/offers/availability correcte.
- Les changements produit/categorie/offre declenchent bien les invalidations menu existantes.
- Le module doit-il rester integre a `/admin/items` ou devenir une route dashboard dediee?

### Stock

Etat: non complete quantitativement.

Point critique:

- Le stock actuel est surtout disponibilite/86d. Le vrai Stock V2 demande `stock_levels`, `stock_movements`, decrement atomique, release idempotent, reconcile, broadcast versionne. A ne pas improviser sans gate migration.

### KDS/OSS/live orders/handover

Etat: non finalise.

Points critiques:

- POS live board doit voir commandes kiosk/POS.
- KDS bump doit respecter state machine.
- Handover/remise client doit etre explicite et coherent NF525.

## Audit risques FoodKing

- Pricing SSOT: respecte dans les modifications dashboard; le front affiche `flat_price`, ne recalcule pas.
- Branch isolation: pas modifiee dans cette passe; a re-auditer sur stock/order/live endpoints.
- OrderStatus enum: pas touche dans cette passe finale.
- Dispatch after commit: pas touche dans cette passe finale.
- OrderService / FrontendOrderService symmetry: reste sujet sensible; les fichiers ont ete isoles mais pas consideres clos.
- Frozen zones: pas de modification backend sensible forcee dans cette passe.

## Questions decisives pour Claude

1. Valider ou refuser l'approche dashboard integree sur `/admin/items` au lieu d'une nouvelle route control plane.
2. Auditer les routes/actions utilisees: `admin.items.list`, `admin.settings.itemCategory.list`, `admin.offers.list`, `AvailabilityToggleComponent`.
3. Decider si le nettoyage Bangladesh doit rester dry-run ou passer en mission destructive controlee avec backup/gate.
4. Auditer le statut exact de D-M13 et la contrainte unique queue DB.
5. Trancher Stock V2: source de verite, migration, backfill, et visual contract rupture.
6. Exiger un E2E navigateur final: kiosk idle/payment, POS order, POS delivery, dashboard edit, KDS bump, OSS.

## Recommandation Codex pour la suite

Ne pas lancer une mega correction. Enchainer:

1. Claude audit ce rapport et le diff dashboard.
2. Rework court sur dashboard si Claude trouve un probleme route/UX.
3. Mission E2E navigateur critique.
4. Gate D-M13.
5. Mission Stock V2 schema/service.
6. Mission live orders/handover/KDS/OSS.

REPORT_VERDICT: NEEDS_CLAUDE_REVIEW_AND_REWORK_PLANNING
