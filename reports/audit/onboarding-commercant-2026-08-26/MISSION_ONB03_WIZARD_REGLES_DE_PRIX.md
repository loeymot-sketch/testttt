# MISSION ONB-03 — WIZARD À RÈGLES DE PRIX · Rapport de mission
- GOAL : `plans/GOAL_ONB03_WIZARD_REGLES_DE_PRIX_2026-08-26.md` · Index : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md`
- État des lieux daté du **2026-08-26** (HEAD `43b120c7d`, `:8766`, base `foodking_e2e`)
- Port : **8803** · Voie : CENTRAL « composer » + zone partagée Pricing **sous LOCK** · **Vague B** : lancer après ONB-02 (stabilisé) ; **seul** sur la zone pricing pendant W4 (aucune autre session ne touche `PricingService`)

## 0. COMMENT LANCER
```
Tu es le chef de mission du GOAL ONB-03 (wizard à règles de prix). Lis : CONSTITUTION.md (§3 frozen/NF525), CLAUDE.md §7-§8, PROJECT_BRAIN.md §2, SYSTEM_MAP.md §6,
PARALLEL_PROTOCOL.md, plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md (§2, §3, §5), reports/audit/onboarding-commercant-2026-08-26/
MISSION_ONB03_WIZARD_REGLES_DE_PRIX.md, plans/GOAL_ONB03_WIZARD_REGLES_DE_PRIX_2026-08-26.md, puis recon/Z0_modele_catalogue_wizard_reglages.md (§A.2, §A.3, §B.2),
recon/Z1_catalogue_wizard.md, memory/episodes/04_pricing_ssot.jsonl (si présent), CLAUDE.md §7 (zones gelées). Pré-vol §0.1 : worktree .claude/worktrees/onb03-wizard depuis HEAD
(après ONB-02), APP_URL=http://127.0.0.1:8803, .env.testing, liens durs, serveur 8803, PLAYWRIGHT_BASE_URL, filet backup/pre-onb03 + dump item_wizard_*.
⛔ PricingService.php est GELÉ : aucune ligne sans LOCK contresigné (lock-plan → docs/gates/LOCK_ONB03_PRICING_INCLUDED_2026-08-26.md) ; avant même le LOCK,
fige la ligne de base des devis des 59 articles × 3 surfaces × 3 compositions (T-3.1.1). Jamais de commande ; jamais de prix côté client ; `price` reste interdit
dans les requêtes composer. Puis « lance le GOAL » : W0 (ligne de base) → W1 (brief Z1 scénario (b) sur 8803 ; inventaire des inclusions en dur ; lecture du
consommateur KioskWizardComponent.vue) → W2 (migration, gate G-DATA) → W3 (éditeur) → W4 (LOCK, seul) → W5 (migration des inclusions par lot, G-MIGR) → W6.
Pipeline ultra-audit-profond, Architecte + Fiscal + DBA en tête, implémenteur unique, ROUGE rejoue les 59 devis après chaque vague, Jalonneur, matrice §S,
deux cycles identiques. Jamais de push. Gates §G (9) : proposer, ne pas trancher. Compte rendu : FIXÉ / VÉRIFIÉ / BLOQUÉ.
```

## 1. CONTEXTE ET VISION
C'est le cœur de la demande du propriétaire : « personnalisation de plusieurs catégories pour chaque produit (sauce, viande, pain, boissons, formule…) avec une règle simple : choix unique,
choix gratuit, ou payant ». Le composer existant sait tout — sauf le prix : `price` est interdit par conception, et les inclusions (« N viandes incluses », « sauce incluse », « frites/boisson
au ratio 0,76 ») sont codées pour Le Cayenne dans `config/menu.php` et `config/kiosk.php`. Ce GOAL ajoute **une règle par étape**, l'applique dans le seul endroit légitime
(`PricingService`, sous LOCK), la fige dans le snapshot NF525, et migre les inclusions en dur sans changer un prix. Persona Karim (10 catégories de personnalisation).

## 2. ÉTAT MESURÉ / CONNU LE 2026-08-26
**Mesuré (Z1)** : composer par catégorie s'ouvre (`a1-11`, `a1b-12`) ; composer par article et wizard avancé = repli silencieux (`wizard_per_item_demo=false`) ; onglet Composition « Final : PricingService backend » ; bouton wizard Studio ~7 s (P3). **Non mesuré** : création de profil/étapes/template/publication/diff (scénario (b) — W1).
**Connu par le code (Z0 §A.2)** :
| Fait | Preuve |
|---|---|
| Étapes : `step_key` unique, `source_type enum(item_attribute, extra_group, addon, fixed)`, `min_select`, `max_select`, `allow_repeat`, `visible_on`, `stockable_choices`, `position`, `is_active`, `addon_role` ; CHECK `min<=max` | `2026_04_27_143110:12-36` |
| Profil : par article XOR par catégorie (CHECK), `template`, `version`, `is_published`, `branch_id_scope` ; catégorie gagne sur article | `2026_05_05_000020`, `ComposerProfileService::resolveForItem :104` |
| **Aucune colonne de prix / gratuité / inclusion** ; `price` prohibé | `ComposerStepRequest.php:32`, `ComposerProfileRequest.php:20,36` |
| Choix projetés à l'exécution depuis variations/extras/addons ; `fixed` → `[]` | `ComposerProfileProjection.php:82-177,176` |
| `PricingService` applique déjà min/max et l'appartenance au profil publié | `:110,557,602,659,701` |
| Inclusions en dur | `config/menu.php:155,172-196` ; `config/kiosk.php:219-220,248,334-335,360` |
| Drapeau par article false ; routes composer gatées `catalog.compose`, `wizard.per_item_demo`, `wizard.per_item_profile_guard`, `catalog.publish` | `config/catalog_v15.php:173-177` ; `routes/api.php:915-939` |
| Templates Cayenne | `ComposerTemplateService.php:19,56,71` |
| Versionnage immuable, publication mid-panier refusée, conflit 409 | `ItemWizardStepVersion*Test`, `ProfilePublishMidCartRejectionTest`, `ComposerProfileVersionConflictTest` |

## 3. CE QUI A DÉJÀ ÉTÉ FAIT
- 2026-04/05 : V1-PIVOT composer (profils, étapes, versions, projection, diff, templates, portée filiale, garde par article) — 21 tests `tests/Feature/Composer/` + 4 hors dossier + `WizardProfileBranchScopeTest` + `ComposerSchemaTest` ; Vitest `composerEditorV2`, `composerEditorVersionConflict`, `composerEditorApplyTemplateError`, `composerGuidanceCallout`, `categoryComposerEditorContract`, `catalogStudioCategoryWizardEntry`.
- 2026-07-18 `GOAL_PARITE_SYNC_MULTISAUCE`, 2026-08-03 `GOAL_VIANDE_NOMMEE_BORNE_PAIEMENT_UNIQUE` (viandes nommées, inclusions) — à relire pour les décisions prises sur les inclusions.
- Décisions en vigueur : pricing 100 % backend (CLAUDE.md §8), `composition_snapshot` figé, `pos-wizard.js` strict no-touch, trio kiosk gelé.

## 4. ANCRAGES CODE
| Rôle | Fichier | Lignes | Note |
|---|---|---|---|
| Migrations | `2026_04_27_143100_create_item_wizard_profiles_table.php:11-23` · `2026_05_05_000020_make_item_wizard_profiles_polymorphic_owner.php:12-20,106-117` · `2026_04_27_143110_create_item_wizard_steps_table.php:12-36` · `2026_05_03_200500:14-18` · `2026_05_04_000010:10-26` | | |
| Modèles | `app/Models/ItemWizardProfile.php:22-25` · `ItemWizardStep.php:46-50` · `ItemWizardStepVersion` · `app/Models/Scopes/WizardProfileBranchScope.php:39-55` | | |
| Services | `app/Services/Composer/ComposerProfileService.php` (`resolveForItem :104`, `publish :149`, `assertPublishable :175-222`) · `ComposerStepService.php` · `ComposerProfileProjection.php:33,82-177,179-191` · `ComposerDiffService.php` · `ComposerTemplateService.php:19,26,56,71` | | |
| Requêtes | `app/Http/Requests/ComposerProfileRequest.php:20,36,39-41,75-77` · `ComposerStepRequest.php:32,36-50` | `price` prohibé | |
| Contrôleurs / routes | `ComposerProfileController.php:26,43,50,67,74,82,89,96,111,131,159` · `ComposerStepController.php:19,26,33` · `routes/api.php:915-939` · `AdminController.php:29-40` | | |
| Pricing (gelé) | `app/Services/Pricing/PricingService.php:15,28,110,557,575,602,603,612,616,659,701,748,756` (814 l.) | LOCK | |
| Éditeur | `admin/items/composer/{ProductComposerEditorComponent (:3-8,:40-50),ComposerStepListSidebar,ComposerStepFormPanel,StepEditorComponent,StepPreviewComponent,ComposerTemplatePickerModal,ComposerPublishDiffModal,ComposerVersionConflictBanner}.vue` · `admin/items/{ProductComposerSummaryComponent,ComposerProfileWarningBadge}.vue` · `admin/demo/WizardAdvancedLauncherComponent.vue` · `itemRoutes.js:13-25,108-153` | | |
| Config | `config/catalog_v15.php:99-105` (`pos_wizard_composer_aware`), `:144-154` (conflit de version), `:173-177` (drapeau) · `config/menu.php:155,172-196` · `config/kiosk.php:219-220,248,334-335,360` | | |
| Consommateurs (lecture) | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (gelé) · `public/js/pos-wizard.js` (strict) · `NormalItemResource`, `MenuProjectionService`, `KioskMenuService` | | |
| Seeders Cayenne | `ComposerSeeder`, `ItemCategoryWizardSeeder`, `AlignFritesWizardProfilesSeeder`, `WizardCayenneAndBolsCorrectionsSeeder` · commandes `EnsureKidsMenuStepsCommand`, `EnsureCayenneMixteCommand` | | ONB-12 |

## 5. BASES CHIFFRÉES
`safe-test.sh --phpunit "Composer|Wizard|Pricing|Catalog"` → figer W0 · **ligne de base des devis** : 59 articles × 3 surfaces × 3 compositions (fichier `onb03-baseline.json`) → figer W0 · profils publiés (`SELECT COUNT(*) FROM item_wizard_profiles WHERE is_published=1`) → W0.

## 6. DÉCISIONS PROPRIÉTAIRE EN ATTENTE
| Gate | Question | Recommandation | Si non tranché |
|---|---|---|---|
| G-DATA | +3 colonnes sur `item_wizard_steps`, retrait de `fixed` | oui | W2 bloquée |
| G-PRIX | LOCK `PricingService` pour appliquer `free/included/paid` | oui | règles saisies mais non appliquées (interdit de livrer) |
| G-ORDER | Choix offerts dans `included` : les moins chers (recommandé) ou les premiers sélectionnés | les moins chers | T-3.1.2 bloquée |
| G-OVERRIDE | Prix unitaire de dépassement différent du catalogue | non en V1 (simplicité) | champ absent |
| G-FLAG | Lever `FEATURE_WIZARD_PER_ITEM_DEMO` → permission `catalog.compose` | oui | édition par article = développeur |
| G-COPY | « Copier vers la catégorie » | oui | manuel |
| G-MIGR | Bascule des inclusions en dur, lot par lot | oui, sauces d'abord | config Cayenne reste |
| G-LOCK-BORNE | Phrase de règle affichée dans `KioskWizardComponent.vue` (gelé) | fiche pour une session BORNE | borne affiche les prix des choix sans la phrase |
| G0 | Amendement constitutionnel | — | ne bloque pas |

## 7. RISQUES, PIÈGES, INSTRUMENTS
- **Le défaut de la nouvelle colonne doit reproduire le comportement actuel** (`paid`) : sinon C1 casse dès la migration.
- `PricingService` est consommé par borne, caisse, web : tout changement se prouve par la ligne de base des 59 devis, pas par un test unitaire seul.
- `composition_snapshot` : jamais réécrit ; une règle changée après commande n'altère pas la réimpression.
- Publication pendant un panier en cours : `ProfilePublishMidCartRejectionTest` — étendre au changement de règle.
- `pos-wizard.js` est strict no-touch : si la caisse doit afficher la phrase, c'est via `composer_profile` (`pos_wizard_composer_aware`), et seulement en lecture.
- Ne jamais éditer les profils publiés Le Cayenne pendant les tests (catégorie/article `GOAL-ONB03`).
- `:8000` = autre worktree ; ta session = **:8803**.

## 8. JOURNAL DE MISSION (rempli par la session)
| Date/heure | Vague | Tâche | Action | Preuve | Verdict | Commit |
|---|---|---|---|---|---|---|
| | W0 | | | | | |

Fiches de renvoi : ONB-04 (schéma de règle consommé par l'extraction IA) · ONB-02 (prix unitaires du catalogue, `ItemRequest`) · ONB-05 (visibilité, drapeau dans le menu) · ONB-12 (templates génériques, seeders) · session BORNE (LOCK `KioskWizardComponent.vue`) · voie CAISSE (`pos_wizard_composer_aware`) · État final : —
