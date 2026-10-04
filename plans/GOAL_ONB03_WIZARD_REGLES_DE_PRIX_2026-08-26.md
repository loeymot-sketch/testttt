# GOAL — ONB-03 WIZARD À RÈGLES DE PRIX
## FoodKing — Onboarding commerçant · personnalisation par catégories (sauce, viande, pain, boisson, formule, suppléments…) avec « choix unique · inclus N gratuit · supplément payant », par article ET par catégorie, prix toujours calculé par le backend

- **Slug** : `ONB03_WIZARD_REGLES_DE_PRIX_20260826` · **Auteur** : Claude Code (chef de projet + rédacteur) · **Date** : 2026-08-26
- **HEAD** : `43b120c7d` · **Branche de base** : `pos/category-first-caisse-2026-06-23`
- **Voie SYSTEM_MAP** : CENTRAL — sous-voie « composer » (`admin/items/composer/**`, `app/Services/Composer/**`, `ItemWizard*`) ; **zone partagée Pricing sous LOCK** (`app/Services/Pricing/PricingService.php`, gelé)
- **Index parent** : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · **Rapport de mission** : `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB03_WIZARD_REGLES_DE_PRIX.md`
- **Port de session** : **8803** · **Dépend de** : ONB-02 (catalogue stable) · **Persona** : Karim veut « Sauce : 1 incluse, la 2e à 0,50 € · Viande : 1 obligatoire · Pain : choix unique gratuit · Boisson : formule +2 € · Suppléments : payants », jusqu'à 10 catégories par produit.

> **En cinq lignes.** Le problème, prouvé par lecture de code (`Z0_modele §A.2`) et non contredit à l'écran (Z1) : les étapes du wizard (`item_wizard_steps`) portent
> `min_select`, `max_select`, `position`, `visible_on`, une source (attribut / groupe d'extras / addon) — mais **aucune sémantique de prix** : `price` est **interdit**
> (`ComposerStepRequest.php:32`, `ComposerProfileRequest.php:20,36`). « N viandes incluses », « sauce incluse », « frites/boisson au ratio 0,76 » vivent en dur dans
> `config/menu.php:155,172-196` et `config/kiosk.php:219-220,248,334-335,360` ; l'édition par article est derrière `FEATURE_WIZARD_PER_ITEM_DEMO=false` ; les templates sont ceux
> de Le Cayenne. FINI = une règle de prix par étape (`free` / `included` N / `paid`), éditable, projetée vers borne/caisse/web, **appliquée par `PricingService` sous LOCK**,
> figée dans le devis et `composition_snapshot`, sans changer un seul prix Le Cayenne (C1..C7). Premier geste : W0 puis le test de caractérisation des 59 devis actuels.

# §0 — PRÉAMBULE

## §0.1 — Décision arbre de travail + PRÉ-VOL DE SESSION
- **Worktree dédié** `.claude/worktrees/onb03-wizard`, branche `goal/onb03-wizard-2026-08-26`, depuis **HEAD** (après fusion/stabilisation de ONB-02 : sinon depuis HEAD courant en déclarant l'écart).
- Pré-vol : `.env` → `APP_URL=http://127.0.0.1:8803` ; `.env.testing` ; liens durs ; `ReflectionClass(App\Services\Composer\ComposerProfileService::class)` → worktree ; serveur 8803 ; `PLAYWRIGHT_BASE_URL`.
- Base partagée : profils/étapes de test sur une **catégorie et un article `GOAL-ONB03`** (jamais sur les profils publiés Le Cayenne — `AlignFritesWizardProfilesSeeder`, `WizardCayenneAndBolsCorrectionsSeeder`) ; ⛔ jamais de commande ; jamais `migrate:fresh` ; `safe-test.sh --phpunit "Composer|Wizard|Pricing|Catalog|Kiosk"`.
- **LOCK obligatoire** avant toute ligne dans `PricingService.php` : `lock-plan` → `docs/gates/LOCK_ONB03_PRICING_INCLUDED_2026-08-26.md` contresigné (G-PRIX). Avant le LOCK : **tests de caractérisation** (devis actuels des 59 articles × surfaces borne/caisse/web = ligne de base figée).
- Filet : `git branch backup/pre-onb03-2026-08-26` + `mysqldump foodking_e2e item_wizard_profiles item_wizard_steps item_wizard_step_versions`.

## §0.2 — Périmètre : DANS / HORS / voisins
| DANS | Fichiers POSSÉDÉS |
|---|---|
| S1 Modèle de règles | migration `item_wizard_steps` (+ `pricing_mode`, `included_count`, `unit_price_override`), `app/Models/{ItemWizardProfile,ItemWizardStep,ItemWizardStepVersion}.php`, `app/Http/Requests/{ComposerProfileRequest,ComposerStepRequest}.php`, `app/Services/Composer/{ComposerProfileService,ComposerStepService,ComposerProfileProjection,ComposerDiffService,ComposerTemplateService}.php`, `app/Models/Scopes/WizardProfileBranchScope.php` |
| S2 Éditeur | `resources/js/components/admin/items/composer/{ProductComposerEditorComponent,ComposerStepListSidebar,ComposerStepFormPanel,StepEditorComponent,StepPreviewComponent,ComposerTemplatePickerModal,ComposerPublishDiffModal,ComposerVersionConflictBanner}.vue`, `admin/items/{ProductComposerSummaryComponent,ComposerProfileWarningBadge}.vue`, `admin/demo/WizardAdvancedLauncherComponent.vue`, `itemRoutes.js:13-25,108-153`, `config/catalog_v15.php` (drapeaux wizard) |
| S3 Tarification & NF525 | **sous LOCK** : `app/Services/Pricing/PricingService.php:110,557-701` (contraintes composer) ; `app/Http/Controllers/Admin/{ComposerProfileController,ComposerStepController}.php` ; projection consommée par `NormalItemResource`, `MenuProjectionService`, `KioskMenuService` (lecture) |
| S4 Migration des inclusions en dur | `config/menu.php` (`supplement_sauce_price :155`, `viandes :172-196`, `has_sauce`, `has_crudites`), `config/kiosk.php` (`fries_ratio/drink_ratio :219-220,334-335`, `frites_included_category_ids :248,360`), seeders `ComposerSeeder`, `ItemCategoryWizardSeeder`, `AlignFritesWizardProfilesSeeder`, `WizardCayenneAndBolsCorrectionsSeeder` (lecture + plan) |

| HORS | Porté par |
|---|---|
| Fiche produit, variations/extras/addons, taxes, import (`ItemRequest`, `ItemController`) | ONB-02 (ce GOAL consomme) |
| `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue` (gelés) — **consommateurs** de la projection : lecture + tests ; toute édition = LOCK + gate BORNE | jamais sans LOCK |
| `public/js/pos-wizard.js` (strict no-touch) — consomme `composer_profile` si `catalog_v15.pos_wizard_composer_aware.enabled` | jamais |
| `composition_snapshot`, `OrderService`/`FrontendOrderService` (partagés) | coordination : ce GOAL prouve, ne modifie pas |
| Extraction IA (produit des règles via l'API de ce GOAL) | ONB-04 |
| Dé-cachage / drapeau `wizard_per_item_demo` dans le menu | ONB-05 (visibilité) ; le **drapeau** lui-même est ici (G-FLAG) |

Zones à coordonner : `routes/api.php` (aucune route nouvelle attendue : les routes composer existent `:915-939`), `fr.json` (bloc `label.composer_*`), `DatabaseSeeder.php`.

## §0.3 — Drapeaux d'expansion
SCOPE-1 **PricingService = gelé** : toute ligne sans LOCK contresigné = STOP · SCOPE-2 3 boucles · SCOPE-3 migration prévue (G-DATA) ; toute autre = STOP · SCOPE-4 **NF525** : `composition_snapshot` jamais réécrit ; un devis signé en cours n'est jamais affecté par une publication (`ProfilePublishMidCartRejectionTest`) · SCOPE-5 kiosk/POS gelés.

## §0.4 — Pipeline
`ultra-audit-profond` · `lock-plan` (S3) · `test-e2e` · `verify-before-report` · TDD · `systematic-debugging`. Non redécrit.

## §0.5 — Convergence et critères chiffrés
Rejets Axe 6 · **deux cycles consécutifs P0+P1 = 0 aux constats identiques** · **règle d'or : aucun prix Le Cayenne ne change** (C1).

| # | Critère | Mesure | Seuil |
|---|---|---|---|
| C1 | Zéro régression de prix | devis des 59 articles actifs × 3 surfaces × 3 compositions représentatives, avant/après (ligne de base figée en W0) | **identiques au centime** |
| C2 | Règles exprimables | pour chaque étape : `free` / `included` (N) / `paid` (+ `unit_price_override`) ; « 1 sauce incluse, la 2e à 0,50 € » = 1 étape | **VRAI** |
| C3 | Prix backend | devis borne/caisse/web = `PricingService::calculateOrder` ; 0 calcul de prix dans les composants (grep) | **0** |
| C4 | Parité des surfaces | même composition → même total borne / caisse / web (`tests/Feature/Pricing*`, parité existante) | **3/3** |
| C5 | NF525 | `composition_snapshot` porte la règle appliquée ; devis en cours non affecté par une publication ; version immuable | **VRAI** |
| C6 | Éditeur lisible | phrase générée par étape (« 1 sauce incluse, puis 0,50 € »), aperçu prix via devis, 10 étapes triables | **VRAI** |
| C7 | Inclusions en dur migrées | `config/menu.php`/`config/kiosk.php` : plan de bascule exécuté ou différé par écrit, prix identiques (C1) | **VRAI** |

## §0.6 — Base héritée
PHPUnit 5 194 · Vitest 3 644 · gelé 0 · `tests/Feature/Composer/` = **21** (`ComposerProfileApiTest`, `ComposerProfileServiceCategoryTest`, `ComposerProfileServicePublishableCategoryTest`, `ComposerProfileUnpublishTest`, `ComposerProfileVersionConflictTest`, `ComposerPublishSyncTest`, `ComposerStepServiceContractTest`, `ComposerTemplateApplyTest`, `ComposerTemplateBranchScopedTest`, `ComposerDiffServiceTest` (+ production path), `ComposerAvailableSourcesTest`, `ComposerAuthzMinimalTest`, `ComposerControllerCategoryRoutesTest`, `ComposerProfileProjectionReusesChoiceAvailabilityTest`, `ComposerProfileProjectionVariationRuptureTest`, `ItemAttributeRenamePropagationTest`, `ItemWizardStepVersion{Immutability,Persistence,UniqueConstraint}Test`, `ProfilePublishMidCartRejectionTest`) ·
`tests/Feature/{WizardPerItemDemoMiddlewareTest,WizardPerItemProfileGuardTest,ItemAttributeComposerResourceTest}.php` · `tests/Feature/Multitenant/WizardProfileBranchScopeTest.php` · `tests/Feature/Catalog/ComposerSchemaTest.php` · Vitest `composerEditorV2`, `composerEditorApplyTemplateError`, `composerEditorVersionConflict`, `composerGuidanceCallout`, `categoryComposerEditorContract`, `catalogStudioCategoryWizardEntry` ·
`PricingService.php` = 814 lignes ; hooks composer `:110` (`assertComposerStepConstraints`), `:557-701` (`compareComposerProfiles :748`, projection `:602`, `composerSelectedCountsForStep :701`).

## §0.7 — Contradictions tranchées
- **C-CONST** (index) : G0.
- **C-PRIX-INTERDIT** — `price` prohibé dans les requêtes composer : décision d'architecture (« le composer est sans prix, les prix viennent des variations/extras/addons »). Le mandat exige « gratuit / inclus / payant ». Tranché : **la règle est par étape et porte sur la gratuité/l'inclusion, jamais sur un prix nouveau** ; le prix unitaire reste celui de la variation/extra/addon (`unit_price_override` = exception explicite, gate) ; `price` reste interdit. C'est une extension, pas une contradiction.
- **C-INCLUS-EN-DUR** — `config/menu.php` (`viandes`, `has_sauce`, `has_crudites`, `supplement_sauce_price 0,50`) et `config/kiosk.php` (`fries_ratio`, `drink_ratio` 0,76, `frites_included_category_ids`) codent des inclusions Le Cayenne consommées par la borne et `PricingService`. Tranché : **caractériser d'abord** (C1), migrer ensuite étape par étape vers les règles, chaque bascule prouvée sans changement de prix — G-MIGR.
- **C-FLAG** — `FEATURE_WIZARD_PER_ITEM_DEMO=false` (`config/catalog_v15.php:173-177`) + middleware `wizard.per_item_demo` (`routes/api.php:917`) + garde `wizard.per_item_profile_guard` (`:928`). Tranché : **lever le drapeau** = décision produit (G-FLAG) ; recommandation : remplacer le drapeau par la permission `catalog.compose` (déjà requise) et garder la garde de profil.
- **C-FIXED** — `source_type='fixed'` existe dans l'enum, absent de la projection (`ComposerProfileProjection.php:176`) et refusé par les requêtes. Tranché : retirer de l'enum (migration, G-DATA) ou documenter comme réservé.
- **C-TEMPLATES** — `ComposerTemplateService::TEMPLATES` `:19` (`simple, sandwich, tacos, assiette, snacking, menu, custom`), contenus `:56-71` = carte Le Cayenne. Tranché : templates **génériques** (« Sandwich/burger », « Tacos/wrap », « Assiette », « Formule ») + import de règles depuis l'IA (ONB-04) — fiche ONB-12 pour les seeders.

## §0.8 — Le commerçant-type et ses questions
Karim : 1. « Ma sauce : la première offerte, la deuxième 0,50 € — je le règle où ? » 2. « Formule : boisson incluse si menu, sinon +2 € — possible ? » 3. « Est-ce que le prix affiché sur la borne est le même qu'en caisse ? »
4. « Si je change une règle ce soir, le client qui compose son tacos maintenant paie quoi ? » 5. « Je peux copier les règles d'un produit sur toute la catégorie ? »

# §1 — CARTE DU SYSTÈME (ancrages vérifiés)

| Sous-système | Maturité | Ancrage réel | Tests |
|---|---|---|---|
| S1 Modèle | **SOLIDE, SANS PRIX** | migrations `2026_04_27_143100` (profils : `template`, `version`, `is_published`, `branch_id_scope`), `2026_05_05_000020` (XOR item/catégorie), `2026_04_27_143110:12-36` (étapes : `step_key` unique, `source_type`, `min/max_select`, `allow_repeat`, `visible_on`, `stockable_choices`, `position`, `is_active`, `addon_role` ; CHECK `min<=max`), `2026_05_03_200500` (`source_item_attribute_id`), `2026_05_04_000010` (versions) · `ItemWizardStep.php:46-50` · `ComposerProfileService.php` (`resolveForItem :104` catégorie gagne, `publish :149`, `assertPublishable :175-222`) · `WizardProfileBranchScope.php:39-55` · requêtes `:20,32,36` (`price` prohibé) | 21 + 4 |
| S2 Éditeur | **FONCTIONNEL PAR CATÉGORIE, BRIDÉ PAR ARTICLE** | 8 composants `composer/*`, `ProductComposerEditorComponent.vue:3-8` (409), `:40-50` (portée filiale) · `itemRoutes.js:13-25` (garde), `:108-123` (article), `:138-153` (catégorie), `:124-137` (démo) · `config/catalog_v15.php:99-105,144-154,173-177` · `CatalogStudioComponent.vue:39-51` (entrée catégorie) | 6 specs Vitest |
| S3 Tarification | **APPLIQUE min/max, PAS l'inclusion** | `PricingService.php:15,28,110,557-701,748,756` · `ComposerProfileProjection.php:33,82-177,179-191` (`fixed` → `[]` `:176`) · routes composer `routes/api.php:915-939` (gates `catalog.compose`, `wizard.per_item_demo`, `wizard.per_item_profile_guard`, `catalog.publish`) · `AdminController::authorizeWritableBranchScope :29-40` | `ProfilePublishMidCartRejectionTest`, `ComposerProfileProjection*Test`, parité pricing (à identifier W1) |
| S4 Inclusions en dur | **CAYENNE DANS LA CONFIG** | `config/menu.php:155,172,180,188,196` · `config/kiosk.php:219-220,248,334-335,360` · seeders `ComposerSeeder`, `ItemCategoryWizardSeeder`, `AlignFritesWizardProfilesSeeder`, `WizardCayenneAndBolsCorrectionsSeeder`, `EnsureKidsMenuStepsCommand`, `EnsureCayenneMixteCommand` | `tests/Feature/Menu/` (32, partiel) |

**Sortie d'ancrage brute** : `ls tests/Feature/Composer | wc -l` → 21 · `grep -n prohibited ComposerProfileRequest.php ComposerStepRequest.php` → `:20`, `:36`, `:32` · `grep -n "function assertPublishable\|resolveForItem\|function publish" ComposerProfileService.php` → `:104`, `:149`, `:175` ·
`grep -n "composer\|Composer" PricingService.php` → 12 lignes (`:15,28,110,557,575,602,603,612,616,659,701,748,756`) ; `wc -l` → 814 · `grep -n "fries_ratio\|frites_included" config/kiosk.php` → `:219,220,248,334,335,360` · `grep -n "supplement_sauce_price\|'viandes'" config/menu.php` → `:155,172,180,188,196` · `grep -n "TEMPLATES\|'sandwich'\|'tacos'" ComposerTemplateService.php` → `:19,26,56,71`.

# §2 — ÉTAT MESURÉ LE 2026-08-26 (`recon/Z1_catalogue_wizard.md` §3 P2, `Z0_modele §A.2-A.3`)
Mesuré : `/admin/categories/:id/composer` s'ouvre (captures `a1-11`, `a1b-12`) ; `/admin/items/:id/composer` et `/admin/demo/wizard-launcher` = écrans de repli silencieux (`wizard_per_item_demo=false`, captures `a1-06`, `a1-07`) ; onglet Composition : « Final : PricingService backend », « Aucun groupe de variations configuré » ; bouton wizard du Studio : superposition ~7 s puis pleine page (P3).
**Non mesuré (W1)** : scénario (b) du brief Z1 — création de profil, étapes, template, publication, diff, dé-publication, `max<min`, étape obligatoire sans choix ; effet sur le devis borne/caisse.
Lecture de code : aucune sémantique de prix ; `fixed` mort ; templates Cayenne ; inclusions en config.

# §3 — SOUS-SYSTÈME 1 : LE MODÈLE DE RÈGLES

### Contrat (décisions fermes du chef de projet)
- Une **règle par étape** : `pricing_mode ∈ {free, included, paid}` · `included_count` (uint ≥ 0, « les N premiers choix de cette étape sont offerts », pertinent pour `included`) · `unit_price_override` (decimal nullable, ≥ 0 : prix unitaire des choix au-delà de l'inclusion, **à la place** du prix de la variation/extra/addon — exception explicite, gate G-OVERRIDE).
- « Choix unique » = `max_select = 1` ; « obligatoire » = `min_select ≥ 1` (existant) ; `free` ignore les prix des choix ; `paid` = chaque choix à son prix ; `included` = N gratuits puis prix (ou override).
- `price` reste **interdit** dans les requêtes ; les prix unitaires viennent du catalogue (ONB-02).
- Le snapshot de version (`item_wizard_step_versions.snapshot`) et `composition_snapshot` portent la règle appliquée (NF525).

## Sub 1.1 — Migration, modèle, requêtes
**Ancrages** : `2026_04_27_143110_create_item_wizard_steps_table.php:12-36`, `ItemWizardStep.php:46-50`, `ComposerStepRequest.php:32,36-50`, `ComposerProfileRequest.php:20,36`, `ItemWizardStepVersion*Test`.
**Tâches**
- **T-1.1.1** — Caractérisation ROUGE : le schéma actuel ne connaît aucune règle (test qui échoue tant que les colonnes n'existent pas) ; snapshot de version ne contient pas de règle.
  • test : (À CRÉER à `tests/Feature/Composer/StepPricingRuleSchemaTest.php`)
- **T-1.1.2** — Migration `add_pricing_rule_to_item_wizard_steps` : `pricing_mode enum default 'paid'` (**le défaut reproduit le comportement actuel** : chaque choix à son prix = C1), `included_count uint default 0`, `unit_price_override decimal(19,6) nullable`, CHECKs (`included_count <= max_select` quand `max_select > 0`, `unit_price_override >= 0`) ; modèle (casts, guards) ; requêtes (`in:free,included,paid`, bornes, `unit_price_override` autorisé seulement si G-OVERRIDE) ; `ComposerStepService` ; versionnage inclut la règle (`ItemWizardStepVersionPersistenceTest` étendu).
  • test : le même, VERT + `tests/Feature/Composer/ComposerStepServiceContractTest.php` (existant, étendre) · gate **G-DATA**
  • au-delà : profil publié sans règle (défaut `paid`) → identique ; `included_count > max_select` → 422 ; `free` avec override → 422 ; XOR item/catégorie intact.
- **T-1.1.3** — `source_type='fixed'` : retirer de l'enum (même migration) ou documenter — G-DATA.
- **T-1.1.4** — Diff de publication (`ComposerDiffService`) montre les changements de règle en français (« Sauce : 0 → 1 incluse »).
  • test : `tests/Feature/Composer/ComposerDiffServiceTest.php` (existant, étendre)
**Acceptation** : 3 tests VERTS · schéma migré · C1 préservé par défaut.

## Sub 1.2 — Projection
**Ancrages** : `ComposerProfileProjection.php:82-177`, `ChoiceAvailabilityResolver` (`:33`), `NormalItemResource`, `MenuProjectionService`, `KioskMenuService`.
**Tâches**
- **T-1.2.1** — La projection expose par étape `pricing_mode`, `included_count`, `unit_price` effectif par choix (après override), et une `pricing_sentence` FR (« 1 incluse, puis 0,50 € ») — **affichage seulement**, jamais un total.
  • test : (À CRÉER à `tests/Feature/Composer/ProjectionExposesPricingRuleTest.php`) + `ComposerProfileProjectionReusesChoiceAvailabilityTest.php` (existant)
- **T-1.2.2** — Consommateurs : `KioskWizardComponent.vue` (gelé) affiche-t-il déjà un prix par choix ? Lire ; si la phrase doit apparaître, **fiche LOCK BORNE** (pas d'édition ici) ; POS `pos-wizard.js` strict no-touch : la phrase passe par `composer_profile` si `pos_wizard_composer_aware` (lecture, test de non-régression).
  • test : (À CRÉER à `tests/js/kioskWizardShowsPricingSentence.spec.js` — **sauté avec motif** tant que le LOCK BORNE n'est pas accordé)
**Acceptation** : test VERT · fiches LOCK écrites.

# §4 — SOUS-SYSTÈME 2 : L'ÉDITEUR

**Ancrages** : `ProductComposerEditorComponent.vue`, `ComposerStepFormPanel.vue`, `StepEditorComponent.vue`, `StepPreviewComponent.vue`, `ComposerTemplatePickerModal.vue`, `ComposerPublishDiffModal.vue`, `ComposerVersionConflictBanner.vue`, Vitest `composerEditorV2.spec.js`, `composerEditorVersionConflict.spec.js`.
**Tâches**
- **T-2.1.1** — Panneau d'étape : sélecteur de règle en trois boutons (Offert / N inclus puis payant / Payant), champ N, override (si G-OVERRIDE), phrase générée en direct, avertissements (`min>0` + `free` = « obligatoire et offert », `included_count` ≥ `max_select` = « tout est offert »).
  • test : (À CRÉER à `tests/js/composerStepPricingRuleForm.spec.js`) · visuel : `http://127.0.0.1:8803/admin/categories/<id>/composer` à 1366/1024/768
  • au-delà : annulation → règle inchangée ; deux onglets → conflit de version (bannière existante) ; rechargement pendant l'enregistrement.
- **T-2.1.2** — Aperçu prix : `StepPreviewComponent` appelle le **devis backend** avec une composition témoin (jamais un calcul local) ; affiche « Tacos 2 viandes + 2 sauces = 9,50 € » ; C3.
  • test : (À CRÉER à `tests/js/composerPreviewUsesBackendQuote.spec.js`)
- **T-2.1.3** — 10 étapes triables (`position`), visibilité par surface, copie des règles d'un profil d'article vers la catégorie (question 5 de Karim) — via `applyTemplate` ou nouvelle action « copier vers la catégorie » (G-COPY).
  • test : `tests/Feature/Composer/ComposerTemplateApplyTest.php` (existant, étendre)
- **T-2.1.4** — Drapeau par article : G-FLAG → si levé, `itemRoutes.js:13-25` garde retirée au profit de `catalog.compose` ; `WizardPerItemDemoMiddlewareTest`, `WizardPerItemProfileGuardTest` mis à jour ; écran de repli remplacé par un vrai écran ou une explication.
  • test : `tests/Feature/WizardPerItemDemoMiddlewareTest.php` (existant, reformulé)
**Acceptation** : C2, C6 · 3 tests VERTS · captures lues · questions 1, 2, 5 = OUI.

# §5 — SOUS-SYSTÈME 3 : TARIFICATION & NF525 (sous LOCK)

**Ancrages** : `PricingService.php:110,557-701` (`assertComposerStepConstraints`, projection `:602`, `assertComposerSelectionsBelongToPublishedProfile :659`, `composerSelectedCountsForStep :701`), `composition_snapshot` (OrderService — partagé), `ProfilePublishMidCartRejectionTest`, `tests/Feature/Pricing*` (à identifier W1).
**Tâches**
- **T-3.1.1** — **Ligne de base de caractérisation AVANT LOCK** : devis des 59 articles actifs × borne/caisse/web × 3 compositions (simple, tout inclus, dépassement) = fichier de référence commité (`tests/Feature/Pricing/fixtures/onb03-baseline.json`, À CRÉER) + test qui échoue si un total change.
  • test : (À CRÉER à `tests/Feature/Pricing/PricingBaselineCharacterizationTest.php`) · C1
- **T-3.1.2** — LOCK G-PRIX : dans `assertComposerStepConstraints`/le calcul des lignes, appliquer la règle : `free` → prix des choix de l'étape = 0 ; `included N` → N premiers choix (ordre : les moins chers ? les premiers sélectionnés ? — **décision G-ORDER**, recommandation : les moins chers offerts, comme le client s'y attend) à 0, les suivants au prix (ou override) ; `paid` → inchangé. Tests unitaires exhaustifs (12 cas par mode), parité des 3 surfaces.
  • test : (À CRÉER à `tests/Feature/Pricing/ComposerPricingRulesTest.php`) · C3, C4
  • au-delà : `included` avec `allow_repeat` (2 × la même sauce) ; choix indisponible au moment du devis (`ChoiceAvailabilityResolver`) ; règle changée entre devis et commit → devis signé fait foi (test) ; surface `web` avec `visible_on` restreint.
- **T-3.1.3** — NF525 : `composition_snapshot` porte `pricing_mode`, `included_count`, prix appliqué par choix ; réimpression d'un ticket historique inchangée ; `ProfilePublishMidCartRejectionTest` étendu à un changement de règle.
  • test : (À CRÉER à `tests/Feature/Pricing/CompositionSnapshotCarriesPricingRuleTest.php`) · C5
- **T-3.1.4** — Idempotence et devis lié (`X-Idempotency-Key`, quote binding — `tests/Feature/Pos/QuoteBindingTest`, `PosOrderRequestNoClientTotalsTest` existants) : aucun total client accepté.
**Acceptation** : C1, C3, C4, C5 · 3 tests VERTS · LOCK contresigné, diff gelé = **uniquement** les lignes du LOCK · question 3, 4 = OUI.

# §6 — SOUS-SYSTÈME 4 : MIGRER LES INCLUSIONS EN DUR

**Ancrages** : `config/menu.php:155,172-196`, `config/kiosk.php:219-220,248,334-335,360`, seeders Cayenne, `EnsureKidsMenuStepsCommand`, `EnsureCayenneMixteCommand`.
**Tâches**
- **T-4.1.1** — Inventaire : chaque clé de config d'inclusion → consommateur (fichier:ligne) → équivalent en règle d'étape → article(s) concerné(s) ; plan de bascule par lot (sauces, viandes, crudités, frites/boisson de formule).
  • livrable : MISSION §8 · test : (À CRÉER à `tests/Feature/Menu/HardcodedInclusionsInventorySentinelTest.php` — cliquet : toute nouvelle clé d'inclusion en config = rouge)
- **T-4.1.2** — Bascule lot par lot (G-MIGR) : créer les règles sur les profils publiés Le Cayenne (versionnées), retirer la lecture de config chez le consommateur, **C1 identique** avant/après chaque lot, seeders mis à jour (fiche ONB-12).
  • test : `PricingBaselineCharacterizationTest` (T-3.1.1) à chaque lot
- **T-4.1.3** — Templates génériques (`ComposerTemplateService::TEMPLATES` renommés/neutres, contenus sans produit Cayenne) ; les anciens templates conservés pour les profils existants.
  • test : `tests/Feature/Composer/ComposerTemplateApplyTest.php` (existant, étendre)
**Acceptation** : C7 · 2 tests VERTS · G-MIGR tranché par lot.

# §S — SCÉNARIOS ADVERSES OBLIGATOIRES
| Fonction \ scénario | annulation | rechargement | double soumission | deux onglets | rôle inférieur | données vides | volume | réseau coupé | effet borne / caisse / web / ticket | retour arrière | valeurs limites |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Règle d'étape | `composerStepPricingRuleForm.spec.js` | conflit de version (`ComposerProfileVersionConflictTest`) | idem | idem | `catalog.compose` 403 (`ComposerAuthzMinimalTest`) | `included_count` vide → 0 | 10 étapes × 20 choix | — | `ComposerPricingRulesTest` parité 3 surfaces | dé-publier (`ComposerProfileUnpublishTest`) | N > max, override négatif, `free`+override |
| Publication | diff annulé → rien | — | idempotent (version) | conflit 409 | `catalog.publish` | profil sans étape → refus (`assertPublishable`) | — | — | devis en cours intact (`ProfilePublishMidCartRejectionTest`) | version précédente restaurable ? (G) | étape obligatoire sans choix → refus |
| Devis | — | — | idempotence clé | — | jeton borne sur route admin → 403 | composition vide | 59 × 3 × 3 | — | **C1 baseline** | — | 2 × même sauce, choix indisponible, dépassement d'inclusion |
| Migration config | — | — | — | — | — | — | 59 articles | — | prix identiques | lot rejoué | article sans profil |
| Ticket / snapshot | — | — | — | — | — | — | — | — | `CompositionSnapshotCarriesPricingRuleTest` | réimpression identique | règle changée après commande |

# §A — ARMÉE D'AGENTS
**Architecte** (modèle de règle, ordre des inclusions, frontière projection/pricing) · Sécurité (override = manipulation de prix ? gate) · UX/A11y (panneau d'étape, phrase, aperçu) · **Psychologie commerçant** (« offert / inclus / payant » en trois boutons, aperçu chiffré, peur de casser les prix) ·
**DBA** (migration, CHECKs, snapshots) · SRE (invalidation menu borne après publication, `ComposerPublishSyncTest`) · **Fiscal** (NF525 : snapshot, devis signé) · Implémenteur unique (**sous LOCK** pour S3) · ROUGE (rejoue les 59 devis et le brief Z1 (b) après chaque vague) · QA visuel + ROUGE visuel · **Jalonneur**.
Disque `reports/test-e2e/ONB03_WIZARD_REGLES_DE_PRIX/<round>/wave-<W>-<rôle>.json` ; contrat de constat ; ~1 200-1 500 mots.

# §X — VAGUES DE CONVERGENCE
| Vague | Portée | Parallélisme | Bloquée par |
|---|---|---|---|
| **W0** | Pré-vol, filet, bases, **ligne de base des 59 devis** (T-3.1.1) | séquentiel | ONB-02 stabilisé |
| **W1** | Reconnaissance : brief Z1 scénario (b) sur :8803 ; inventaire des inclusions (T-4.1.1) ; identification des tests de parité pricing ; lecture `KioskWizardComponent.vue` (consommateur) | fan-out lecture seule | — |
| **W2** | S1 modèle + projection (T-1.*) | séquentiel | **G-DATA** |
| **W3** | S2 éditeur (T-2.*) | séquentiel | G-FLAG, G-OVERRIDE, G-COPY |
| **W4** | S3 tarification **sous LOCK** (T-3.1.2..4) | **seul** sur la zone pricing | **G-PRIX**, G-ORDER |
| **W5** | S4 migration des inclusions par lot (T-4.*) | séquentiel | G-MIGR |
| **W6** | Convergence : deux cycles, `safe-test.sh --phpunit "Composer|Wizard|Pricing|Catalog|Kiosk|Pos"`, Vitest, Playwright `tests/e2e/onb03-*.spec.js` (composition borne + caisse, prix affiché = devis), diff gelé = lignes du LOCK seulement, `fiscal:verify-chain`, BRAIN | séquentiel | — |
**§X.8** 6 points (+ « diff gelé = LOCK seulement ») · **§X.9** STOP/`STUCK_*`/4 options · **§X.10** `wip`/`INTERRUPT_*`/BRAIN.

# §G — GATES PROPRIÉTAIRE
| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G0** | Amendement constitutionnel (index) | Propriétaire | ligne | `CONSTITUTION.md` | EN ATTENTE — ne bloque pas |
| **G-DATA** | Migration `item_wizard_steps` (+3 colonnes, CHECKs), retrait de `fixed` | Propriétaire | accord | `docs/gates/GATE_LOG.md` | EN ATTENTE — bloque W2 |
| **G-PRIX** | LOCK `PricingService.php` (règles d'inclusion) | Propriétaire | `LOCK_ONB03_PRICING_INCLUDED_2026-08-26.md` contresigné | `docs/gates/` | EN ATTENTE — **bloque W4** |
| **G-ORDER** | Ordre des choix offerts dans `included` (les moins chers — recommandé — ou les premiers sélectionnés) | Propriétaire | choix | MISSION §6 | EN ATTENTE — bloque T-3.1.2 |
| **G-OVERRIDE** | Autoriser `unit_price_override` (prix des choix au-delà de l'inclusion différent du catalogue) | Propriétaire | choix | MISSION §6 | EN ATTENTE — bloque le champ, pas le reste |
| **G-FLAG** | Lever `FEATURE_WIZARD_PER_ITEM_DEMO` (remplacé par `catalog.compose`) | Propriétaire | accord | MISSION §6 | EN ATTENTE — bloque T-2.1.4 |
| **G-COPY** | Action « copier les règles vers la catégorie » | Propriétaire | accord | MISSION §6 | EN ATTENTE |
| **G-MIGR** | Bascule des inclusions en dur, lot par lot (sauces / viandes / crudités / formule) | Propriétaire | accord par lot | `GATE_LOG.md` | EN ATTENTE — bloque W5 |
| **G-LOCK-BORNE** | Afficher la phrase de règle dans `KioskWizardComponent.vue` (gelé) | Propriétaire | LOCK BORNE | `docs/gates/` | EN ATTENTE — hors de ce GOAL (fiche) |

# §R — RÉFÉRENCES
`ultra-audit-profond` · `lock-plan` · `test-e2e` · `verify-before-report` · `CLAUDE.md §7-8` (frozen, NF525, pricing SSOT) · `memory/episodes/04_pricing_ssot.jsonl` (à vérifier `ls`) · `CLAUDE.md §7` (liste canonique des zones gelées) · `SYSTEM_MAP.md §6` · `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · `_FICHES_GOAL.md` (ONB-03) · `recon/Z0_modele_catalogue_wizard_reglages.md §A.2-A.3, §B.2` · `recon/Z1_catalogue_wizard.md` ·
`plans/GOAL_VIANDE_NOMMEE_BORNE_PAIEMENT_UNIQUE_2026-08-03.md` · `plans/GOAL_PARITE_SYNC_MULTISAUCE_2026-07-18.md` · `plans/GOAL_INTELLIGENCE_TOTALE_2026-07-18.md` · `tests/Feature/Composer/*` · `tests/Feature/Pos/{QuoteBindingTest,PosOrderRequestNoClientTotalsTest}.php`.

# §F — RÈGLE FINALE
TERMINÉ quand et seulement quand : 1. 6 vagues closes ; 2. **C1 identique au centime** + C2..C7 ; 3. PHPUnit ≥ 5 194 + ≥ 12 tests créés VERTS, Vitest ≥ 3 644 ; 4. diff gelé = **exactement** les lignes du LOCK contresigné ; 5. NF525 ajout seul, snapshot enrichi jamais réécrit ; 6. 9 gates tranchés ou différés ; 7. BRAIN §6 (décision d'architecture : règle par étape) ; 8. deux cycles identiques ; 9. fiches (ONB-02 catalogue, ONB-04 schéma de règle pour l'IA, ONB-05 visibilité, ONB-12 templates/seeders, BORNE LOCK).
**Interdit** : une ligne dans `PricingService` sans LOCK · un prix calculé côté client · `price` dans une requête composer · toucher `pos-wizard.js` ou le trio kiosk · changer un prix Le Cayenne · approuver un gate.
> Le sens : Karim règle « 1 sauce incluse, puis 0,50 € » en trois boutons, voit 9,50 € dans l'aperçu, et la borne, la caisse et le ticket disent 9,50 € — parce qu'un seul service l'a calculé.
