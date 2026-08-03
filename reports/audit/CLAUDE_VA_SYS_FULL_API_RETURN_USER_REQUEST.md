# Audit API Messages (sans CLI `claude`)

- **Base** : `https://api.orcai.cc`
- **Modèle demandé** : `claude-opus-4-7-20250514`
- **max_tokens** : 200000
- **usage** (API) : `{"input_tokens":4802,"output_tokens":7537}`
- **stop_reason** : `end_turn`
- **Limite** : pas d’outils — le modèle ne peut pas ouvrir le disque ; tout contenu utile doit être **dans** le prompt (ex. assemble-mega-api-context-va-sys.mjs).

---

# Audit externe senior — Centralisation data/sync/gestion produit FoodKing Version A

Auditeur : Kiro (externe, adversarial)
Date : 2026-04-30
Scope : logiciel central uniquement, hardware/TPE/imprimante/Maps déférés à UAT séparée.

---

## 1. Cohérence du verdict Codex

Le verdict Codex est **cohérent et honnête**. Voici pourquoi :

VA-SYS-06 à VA-SYS-09 couvrent le noyau data/sync : rupture produit+choix, authz branch, outbox/realtime, docs. Chaque rapport cite des tests précis avec comptages, des invariants vérifiés, et des limites explicites (SQLite vs MySQL, timing KDS ~5.88s, pas de provider cloud réel). VA-SYS-10 agrège ces preuves (175 PHP, 42 Vitest, 4 Playwright C3, build PASS) et conclut correctement `PASS_CORE_SYNC_VALIDATION_WITH_REMAINING_SYSTEM_GATES` — pas un PASS total.

Le `HOLD` système complet est justifié : VA-SYS-00 à VA-SYS-05 ne sont pas des détails cosmétiques. VA-SYS-05 en particulier (E2E dashboard→kiosk/POS/KDS) est la preuve intégration qui manque pour affirmer que le pipeline central fonctionne de bout en bout dans un navigateur réel. Sans elle, les tests unitaires/feature prouvent les pièces individuellement mais pas l'assemblage opérateur.

Verdict cohérence : **PASS — le Codex ne sur-vend pas.**

Risque identifié : le rapport VA-SYS-10 ne relance pas la suite PHPUnit complète (seulement les suites ciblées sync/core). Les 939+ tests PHPUnit globaux ne sont pas re-validés dans ce run. Ce n'est pas un blocage P0 car les missions n'ont pas touché OrderService/PaymentService/frozen zones, mais c'est un point à couvrir dans le VA-SYS-10 final post-VA-SYS-05.

---

## 2. Audit par domaine de centralisation

### 2.1 Ajout/modification/suppression produit

**PASS prouvé.**

Preuves :

- `ItemController` → `ItemService` → events `ItemCreated`/`ItemUpdated` → `CatalogChanged` via listener chain (doc `CATALOG_COMPOSER_DATA_FLOW.md`).
- Tests `tests/Feature/Catalog` : 14 PASS (VA-SYS-10).
- `PhotoEndToEndKioskInvalidationTest` : 1 PASS — prouve que la mutation photo invalide le cache kiosk.
- Projection menu : `tests/Feature/Services/Menu` : 23 PASS.

Risque résiduel P2 : la suppression produit (soft delete vs hard delete) n'est pas explicitement testée dans les rapports VA-SYS. Le flow `ItemDeleted` → `CatalogChanged` → projection refresh est documenté mais le test de suppression avec commandes historiques liées (snapshots intacts) n'est pas cité. Couvert implicitement par l'invariant `composition_snapshot` immutable, mais un test sentinel dédié serait plus solide.

### 2.2 Catégories

**PASS prouvé.**

Preuves :

- `CategoryCreated`/`CategoryUpdated`/`CategoryDeleted` → `CatalogChanged` (doc `CATALOG_COMPOSER_DATA_FLOW.md`).
- `CatalogChangedDispatchTest` : 2 PASS — prouve le fanout par branche active.
- `CatalogOutboxIdempotencyTest` : 1 PASS.
- Projection menu filtre par surface/branch : couvert par `MenuProjectionService` tests.

Pas de risque P0/P1 identifié.

### 2.3 Photos produit

**PASS prouvé.**

Preuves :

- VA-SYS-07B : `ProductPhotoAuthzTest` 1 PASS — seuls Admin/Tenant Admin peuvent muter les photos (politique globale V1).
- `PhotoEndToEndKioskInvalidationTest` : 1 PASS — invalidation cache kiosk après changement photo.
- Policy documentée dans `CATALOG_COMPOSER_DATA_FLOW.md`.

Risque résiduel P2 : le test photo ne couvre pas le cas "branch user tente upload photo → 403" explicitement dans le rapport VA-SYS-07B (le test `ProductPhotoAuthzTest` est cité comme 1 PASS, mais le détail des assertions n'est pas visible). Probablement couvert, mais à confirmer par lecture du test.

### 2.4 Stock produit (rupture complète)

**PASS prouvé.**

Preuves :

- `tests/Feature/Stock` : 21 PASS (VA-SYS-06 et VA-SYS-10).
- `AvailabilityService` gère `item_branch_availability`.
- `ItemAvailabilityChanged` → outbox → POS/Kiosk/KDS (mémoire épisode SYNC-001).
- `StockService::decrementForOrder()` dans le path transactionnel.
- Cancel/refund release idempotent via `released_qty` ledger (décision log lot 1.B).
- POS/Kiosk UX : `posRuptureUx.spec.js`, `kioskRuptureUx.spec.js` PASS.

Pas de risque P0 identifié.

### 2.5 Stock choix wizard (rupture granulaire)

**PASS prouvé.**

Preuves :

- `ChoiceAvailabilityResolver` résout variations, extras, addon targets par branche (VA-SYS-06).
- `PricingService` rejette choix indisponibles et choix absents du profil publié courant (adversarial audit VA-SYS-06 a trouvé et corrigé les deux P1 : stale choices + restored selections).
- `ComposerStepConstraintTest` : 12 PASS.
- `posWizardComposerProfile.spec.js` + `kioskWizardGenericComposer.spec.js` : PASS.
- Addon target stock décrémenté depuis `composition_snapshot.addons` (VA-SYS-06).

Risque résiduel P1 : le test de rupture choix wizard ne couvre pas explicitement le cas "choix devient indisponible PENDANT que l'utilisateur est dans le wizard" (race condition UX). Le backend rejette, donc pas de corruption, mais l'UX pourrait être confuse (soumission → 422 → message d'erreur). Ce n'est pas un bug de données mais un edge case UX à documenter ou gérer côté frontend avec un refresh léger.

### 2.6 Composer/wizard : produit simple vs complexe

**PASS prouvé.**

Preuves :

- `WIZARD_PRODUCT_MODEL.md` distingue clairement : ready product (pas de wizard), simple option (1-2 steps), complex composed (multi-step, min/max/repeat), addon target.
- `ComposerAuthzMinimalTest` : 11 PASS (VA-SYS-07B).
- `ComposerStepConstraintTest` : 12 PASS — valide min/max/repeat/surface.
- `ItemAttributeComposerResourceTest` : 5 PASS.
- Composer show déterministe après VA-SYS-07B : branch actor → own branch, global actor → global, pas de leak "latest foreign profile".

Risque résiduel P1 : le passage d'un produit de "sans wizard" à "avec wizard publié" (et inversement) en runtime n'est pas explicitement testé dans les rapports. Le modèle le supporte (pas de profil publié = pas de wizard forcé), mais un test de transition serait une preuve plus forte.

### 2.7 Projection POS/Kiosk/KDS/OSS

**PARTIAL — prouvé unitairement, pas prouvé E2E intégré.**

Preuves unitaires :

- `MenuProjectionService` tests : 23 PASS.
- `KioskMenuService` : couvert par menu tests.
- Resources (`ItemResource`, `NormalItemResource`, `ItemExtraResource`, `ItemAddonResource`) : décorées avec availability metadata.
- KDS/OSS : consomment les snapshots commande, pas le catalogue live (correct).

Ce qui manque (VA-SYS-05) :

- Aucun test E2E prouve : dashboard crée produit → publie composer → kiosk voit le produit → POS voit le produit → commande → KDS reçoit → stock décrémenté. C'est exactement VA-SYS-05.
- Les projections sont testées en isolation (service → response), pas en chaîne (dashboard mutation → event → cache invalidation → surface refresh → DOM visible).

Verdict : **PASS unitaire, NOT_VALIDATED intégration E2E.** C'est le trou principal.

### 2.8 Outbox/realtime/fallback

**PASS prouvé fortement.**

Preuves :

- `OutboxProductionLikeSimulationTest` : 5 tests × 3 runs (VA-SYS-08).
- Outbox directory : 14 tests PASS.
- `EventContractTest` : 9 feature + 12 unit PASS.
- `AfterCommitDispatchTest` : 14 PASS.
- `DispatchAfterCommitTest` : 8 PASS.
- `KioskRealtimeBroadcastTest` : 2 PASS.
- `SyncComprehensiveTest` : 6 PASS.
- Vitest realtime : 29 tests (dedupe, persistence, capacity, fallback, reconnect storm, backoff, cadence, version gate).
- C3 Playwright : 4 PASS (repeat-each=2, retries=0).
- Claim-then-broadcast-then-finalize pattern (NEW-01).
- Reconnect storm circuit breaker (NEW-02).
- Queue topology 3 lanes (NEW-03).
- Observability surface sync_metrics (NEW-04).

Risques résiduels documentés honnêtement :

- KDS timing local ~5.88s (P2, à surveiller en staging).
- Pas de test provider cloud réel (déféré UAT).
- SQLite `lockForUpdate` no-op (documenté, MySQL integration test backlog).

Verdict : **PASS_RUNTIME_LOCAL_STRONG** confirmé.

### 2.9 Branch isolation

**PASS prouvé.**

Preuves :

- `BranchIsolationTest` (mémoire épisode 02).
- `ChannelAuthorizationTest` (mémoire épisode 03).
- VA-SYS-07B : `DashboardBranchScopeMatrixTest` 3 PASS, `AvailabilityToggleAuthzMatrixTest` 3 PASS.
- `HasBranchScope` global scope sur tous les modèles métier.
- Kiosk branch résolu côté serveur depuis machine token, pas depuis query client.
- NEW-04 audit a corrigé le leak cross-branch sur `/sync-overview` (G2 critical, fixé).

Pas de risque P0 identifié.

### 2.10 Historique/snapshots

**PASS prouvé.**

Preuves :

- `composition_snapshot` immutable NF525 (mémoire épisode 02, T07).
- `allergens_snapshot` immutable (mémoire épisode 02, T05).
- `WIZARD_PRODUCT_MODEL.md` : "Historical order snapshots must read snapshots first; they must not recompute old order meaning from the live catalog."
- KDS/OSS consomment les snapshots commande, pas le catalogue courant.
- `normalizeReceiptVariations` / `normalizeReceiptExtras` lisent le snapshot.

Pas de risque P0 identifié.

---

## 3. Risques P0/P1/P2 restants


| #   | Niveau | Risque                                                                                                                                                                         | Mission de couverture                            |
| --- | ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------ |
| R1  | P0     | Pas de preuve E2E intégrée dashboard→kiosk/POS/KDS                                                                                                                             | VA-SYS-05                                        |
| R2  | P1     | Dashboard workflow discovery incomplet : les sélecteurs/formulaires réels du dashboard admin ne sont pas cartographiés, ce qui rend VA-SYS-05 fragile                          | VA-SYS-01                                        |
| R3  | P1     | Composer request payloads non verrouillés : un opérateur ou un client API pourrait envoyer un payload malformé au dashboard et corrompre un profil                             | VA-SYS-02                                        |
| R4  | P1     | Wizard runtime contract pas formalisé : la frontière "pas de wizard / wizard simple / wizard complexe" est documentée mais pas contractualisée par un test d'invariant backend | VA-SYS-03                                        |
| R5  | P1     | Race condition UX : choix devient indisponible pendant wizard ouvert → 422 sans message clair                                                                                  | VA-SYS-06 résiduel, à couvrir dans VA-SYS-05 E2E |
| R6  | P1/P2  | Dashboard builder UX : erreurs opérateur (step sans options, profil publié vide, min > max) pas toutes gardées côté UI                                                         | VA-SYS-04                                        |
| R7  | P2     | Suppression produit avec commandes historiques : pas de test sentinel dédié                                                                                                    | VA-SYS-05 ou test séparé                         |
| R8  | P2     | Transition produit sans-wizard → avec-wizard en runtime : pas de test explicite                                                                                                | VA-SYS-03                                        |
| R9  | P2     | MySQL JSON surface filtering : 6 tests SKIP sous SQLite, non validés dans ce run                                                                                               | CI MySQL ou staging                              |
| R10 | P2     | Suite PHPUnit complète (939+) non relancée dans VA-SYS-10 ciblé                                                                                                                | VA-SYS-10 final post-VA-SYS-05                   |


---

## 4. Plan d'exécution VA-SYS-00 à VA-SYS-05

### Ordre d'exécution recommandé

```
VA-SYS-00 (scope lock, 30 min)
    ↓
VA-SYS-01 (discovery, 2-4h)
    ↓
VA-SYS-02 (request contract, 2-3h) ← dépend de VA-SYS-01 pour connaître les payloads réels
    ↓
VA-SYS-03 (wizard contract, 2-3h) ← peut paralléliser avec VA-SYS-02
    ↓
VA-SYS-04 (dashboard UX, 2-4h) ← dépend de VA-SYS-01+02
    ↓
VA-SYS-05 (E2E intégré, 4-6h) ← dépend de VA-SYS-01+02+03+04
    ↓
VA-SYS-10 re-run (validation finale massive, 1-2h)
```

---

## 5. Détail par mission

### VA-SYS-00 — Scope lock / hardware deferral

**Objectif exact** : produire un gate note formel qui verrouille le scope logiciel Version A et liste explicitement ce qui est déféré à UAT matériel. Ce document devient la référence pour tout auditeur externe ou décideur business.

**Fichiers à lire** :

- `missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md`
- `docs/sync/VERSION_A_SYNC_VALIDATION_MATRIX_2026-04-30.md`
- `docs/gates/` (conventions existantes)

**Fichiers à créer/modifier** :

- `docs/gates/GATE_VA_SYS_00_SCOPE_LOCK_HARDWARE_DEFERRAL.md`
- `missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md` (status → PASS)

**Tests** : aucun test code. Validation = relecture humaine du gate note.

**Critères PASS** :

- Le document liste exhaustivement : TPE, imprimante fiscale, kiosk OS lockdown, provider realtime cloud, Google Maps live, réseau réel perte/reconnect.
- Le document confirme que le logiciel central est le scope actif.
- Pas de langage ambigu ("devrait", "pourrait") — que du factuel.

**Critères REWORK** : omission d'un élément hardware connu, ou langage qui laisse croire que hardware est validé.

**Risques business** : aucun risque technique. Risque process : sans ce document, un stakeholder pourrait croire que Version A = prêt pour production commerciale.

**Durée estimée** : 30 minutes.

---

### VA-SYS-01 — Dashboard workflow discovery

**Objectif exact** : cartographier tous les workflows opérateur du dashboard admin qui touchent le catalogue, le composer, le stock, les photos, les catégories. Produire un rapport de discovery avec : routes, contrôleurs, composants Vue, sélecteurs CSS/data-testid, payloads request/response, et une carte des dépendances entre écrans.

**Fichiers à lire** :

- `routes/web.php`, `routes/api.php` (routes admin)
- `app/Http/Controllers/Admin/ItemController.php`
- `app/Http/Controllers/Admin/ItemCategoryController.php`
- `app/Http/Controllers/Admin/ComposerProfileController.php`
- `app/Http/Controllers/Admin/ComposerStepController.php`
- `app/Http/Controllers/Admin/ItemVariationController.php`
- `app/Http/Controllers/Admin/ItemExtraController.php`
- `app/Http/Controllers/Admin/ItemAddonController.php`
- `resources/js/components/admin/items/` (tous les composants Vue dashboard produit)
- `resources/js/components/admin/composer/` (si existant)
- `resources/js/components/admin/categories/`

**Fichiers à créer** :

- `reports/discovery/VA_SYS_01_DASHBOARD_WORKFLOW_MAP.md` — carte complète
- `reports/discovery/VA_SYS_01_SELECTOR_MAP.md` — sélecteurs pour Playwright

**Tests à créer** : aucun test code dans cette mission. Le livrable est le rapport de discovery.

**Tests à lancer** : `php artisan route:list --path=admin` pour vérifier les routes.

**Critères PASS** :

- Chaque workflow CRUD produit/catégorie/photo/composer/stock est documenté avec route, contrôleur, composant Vue, et payload.
- Les sélecteurs Playwright (data-testid ou CSS) sont identifiés pour chaque action critique.
- Les dépendances entre écrans sont claires (ex : "publier composer" nécessite "créer steps" qui nécessite "créer profil").

**Critères REWORK** :

- Workflow manquant (ex : addon management oublié).
- Sélecteurs non identifiables (composants sans data-testid ni sélecteur stable → REWORK avec ajout de data-testid).

**Risques business** : sans cette carte, VA-SYS-05 E2E sera fragile et cassera à chaque changement UI mineur.

**Durée estimée** : 2-4 heures.

---

### VA-SYS-02 — Composer request contract hardening

**Objectif exact** : verrouiller les payloads de création/modification de profil composer, steps, et options via des FormRequest Laravel stricts. Empêcher qu'un opérateur ou un appel API malformé corrompe un profil publié.

**Fichiers à lire** :

- `app/Http/Controllers/Admin/ComposerProfileController.php`
- `app/Http/Controllers/Admin/ComposerStepController.php`
- `app/Http/Requests/Admin/Composer/` (si existant)
- `app/Services/Composer/ComposerProfileService.php`
- `app/Services/Composer/ComposerStepService.php`
- Rapport VA-SYS-01 (pour connaître les payloads réels)

**Fichiers à créer/modifier** :

- `app/Http/Requests/Admin/Composer/StoreComposerProfileRequest.php` (ou hardening existant)
- `app/Http/Requests/Admin/Composer/UpdateComposerProfileRequest.php`
- `app/Http/Requests/Admin/Composer/StoreComposerStepRequest.php`
- `app/Http/Requests/Admin/Composer/UpdateComposerStepRequest.php`
- `tests/Feature/Composer/ComposerRequestValidationTest.php`

**Tests à créer** :

- Payload vide → 422.
- `min` > `max` → 422.
- `step_kind` invalide → 422.
- Profil publié sans steps → 422 ou warning.
- Step avec 0 options → 422.
- Payload avec `branch_id` forgé (branch user tente de créer sur une autre branche) → 403.
- Payload avec champs inattendus (mass assignment) → ignorés.

**Tests à lancer** :

- `php artisan test tests/Feature/Composer`
- `php artisan test tests/Feature/Catalog`
- Nouveau `ComposerRequestValidationTest`

**Critères PASS** :

- Chaque endpoint composer a un FormRequest dédié avec rules strictes.
- Les tests couvrent les cas limites ci-dessus.
- Aucune régression sur `ComposerAuthzMinimalTest` (11 tests).

**Critères REWORK** :

- Un endpoint accepte un payload malformé sans 422.
- Mass assignment possible sur un champ sensible.

**Risques business** : un opérateur qui publie un profil corrompu rend le wizard inutilisable sur kiosk/POS jusqu'à correction manuelle.

**Durée estimée** : 2-3 heures.

---

### VA-SYS-03 — Wizard runtime contract

**Objectif exact** : formaliser par des tests d'invariant backend la frontière entre les trois modes produit (sans wizard, wizard simple, wizard complexe). Garantir que le runtime ne force jamais un wizard sur un produit sans profil publié, et qu'un produit avec profil publié ne peut pas être commandé sans passer par le wizard.

**Fichiers à lire** :

- `docs/sync/WIZARD_PRODUCT_MODEL.md`
- `app/Services/Pricing/PricingService.php` (section validation composer)
- `app/Services/Kiosk/KioskMenuService.php`
- `app/Services/Menu/MenuProjectionService.php`
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/admin/pos/ItemComponent.vue`

**Fichiers à créer/modifier** :

- `tests/Feature/Wizard/WizardRuntimeContractTest.php`
- `tests/js/wizardRuntimeContract.spec.js` (optionnel, si le frontend a une logique de routing wizard)

**Tests à créer** :

- Produit sans profil publié → ajout direct au panier sans wizard (kiosk + POS).
- Produit avec profil publié → soumission sans selections wizard → 422 backend.
- Produit avec profil publié → soumission avec selections valides → 200.
- Transition : produit passe de sans-profil à avec-profil publié → prochaine commande exige wizard.
- Transition inverse : profil dépublié → produit redevient "direct add".
- Profil publié avec 0 steps (edge case) → comportement défini (erreur ou direct add).

**Tests à lancer** :

- Nouveau `WizardRuntimeContractTest`
- `php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php`
- `php artisan test tests/Feature/Composer`

**Critères PASS** :

- Les 3 modes sont testés avec assertions claires.
- Les transitions sont couvertes.
- Aucune régression pricing/composer.

**Critères REWORK** :

- Un mode n'est pas testé.
- Le backend accepte une commande sans wizard sur un produit avec profil publié actif.

**Risques business** : sans ce contrat, un changement futur de composer pourrait silencieusement casser le flow commande pour une catégorie entière de produits.

**Durée estimée** : 2-3 heures.

---

### VA-SYS-04 — Dashboard builder UX hardening

**Objectif exact** : empêcher les erreurs opérateur les plus courantes lors de la création/édition de produits et profils composer dans le dashboard. Ajouter des gardes côté frontend (validation formulaire, messages d'erreur clairs, confirmations) et côté backend (FormRequest, si pas déjà couvert par VA-SYS-02).

**Fichiers à lire** :

- Rapport VA-SYS-01 (carte des workflows)
- Composants Vue dashboard produit/composer (identifiés dans VA-SYS-01)
- FormRequests existants pour Item/Category/Composer

**Fichiers à modifier** :

- Composants Vue dashboard : ajout de validation côté client, messages d'erreur, confirmations de suppression.
- `resources/js/languages/fr.json`, `en.json`, `ar.json` : messages d'erreur UX.
- Éventuellement : FormRequests backend si VA-SYS-02 n'a pas tout couvert.

**Tests à créer** :

- `tests/js/dashboardComposerValidation.spec.js` : validation formulaire côté client.
- Optionnel : Playwright smoke test dashboard si les sélecteurs VA-SYS-01 le permettent.

**Tests à lancer** :

- `npx vitest run tests/js/dashboardComposerValidation.spec.js`
- `npm run production` (build ne casse pas)
- `php artisan test tests/Feature/Composer` (régression)

**Critères PASS** :

- Les erreurs opérateur identifiées (step vide, min>max, profil sans steps publié, suppression sans confirmation) sont gardées.
- Les messages d'erreur sont en fr/en/ar.
- Build production PASS.

**Critères REWORK** :

- Un cas d'erreur opérateur courant n'est pas gardé.
- Message d'erreur manquant ou non traduit.

**Risques business** : un opérateur non technique qui crée un produit mal configuré peut bloquer les commandes kiosk/POS jusqu'à intervention.

**Durée estimée** : 2-4 heures.

---

### VA-SYS-05 — Full dashboard-to-kiosk/POS/KDS E2E

**Objectif exact** : prouver par un test Playwright E2E complet que le pipeline central fonctionne de bout en bout : dashboard crée un produit → ajoute photo → configure stock → crée/publie profil composer → kiosk voit le produit avec wizard → POS voit le produit → commande kiosk → KDS reçoit → stock décrémenté → rupture stock → kiosk/POS reflètent la rupture.

**Fichiers à lire** :

- Rapport VA-SYS-01 (sélecteurs)
- `tests/e2e/c3-runtime-multi-surface.spec.js` (pattern existant)
- `docs/sync/CATALOG_COMPOSER_DATA_FLOW.md`
- `docs/sync/STOCK_SYNC_AND_AVAILABILITY.md`

**Fichiers à créer** :

- `tests/e2e/va-sys-05-central-management-e2e.spec.js`
- `reports/antigravity/va-sys-05-central-e2e.json` (artifact résultat)

**Tests à créer** (scénario Playwright) :

1. Login dashboard admin.
2. Créer catégorie (ou utiliser existante).
3. Créer produit dans la catégorie, visible kiosk+POS.
4. Upload photo produit.
5. Créer profil composer avec 2+ steps, options stockables.
6. Publier le profil.
7. Ouvrir kiosk (autre contexte navigateur) → vérifier produit visible avec wizard.
8. Commander via kiosk avec sélections wizard.
9. Vérifier KDS reçoit la commande (DOM update sans reload).
10. Vérifier POS voit la commande.
11. Vérifier stock décrémenté (ou simuler rupture).
12. Vérifier kiosk/POS reflètent la rupture (choix grisé ou produit indisponible).

**Tests à lancer** :

- `npx playwright test tests/e2e/va-sys-05-central-management-e2e.spec.js --repeat-each=2 --retries=0`
- `php artisan test tests/Feature/Stock` (régression)
- `php artisan test tests/Feature/Composer` (régression)

**Critères PASS** :

- Le scénario E2E passe 2 fois consécutives sans retry.
- Chaque étape est vérifiable par assertion DOM (pas de sleep arbitraire > 10s).
- L'artifact JSON est produit avec timings.

**Critères REWORK** :

- Une étape échoue de manière reproductible.
- Le test dépend de sélecteurs instables (→ retour VA-SYS-01 pour ajouter data-testid).
- Le timing KDS dépasse 15s (seuil dégradé, pas le 5s idéal mais acceptable en local).

**Risques business** : c'est LE test qui prouve que le système central fonctionne. Sans lui, on a des pièces validées individuellement mais pas la preuve que l'opérateur peut créer un produit et le vendre.

**Durée estimée** : 4-6 heures (le plus long, car dépend de la stabilité des sélecteurs et du serveur local).

---

## 6. Liste UAT matériel (séparée, hors scope actuel)

Pour mémoire, à exécuter après `VERSION_A_SYSTEM_SOFTWARE: PASS` :

- TPE paiement réel (Stripe terminal, SumUp, ou autre)
- Imprimante fiscale ESC/POS
- Kiosk OS lockdown (mode kiosque Chrome/Android)
- Provider realtime cloud (Pusher/Reverb production)
- Google Maps live (livraison/localisation)

