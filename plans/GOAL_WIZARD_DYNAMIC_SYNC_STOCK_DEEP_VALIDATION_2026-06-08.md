# GOAL — Wizard dynamique configurable + validation profonde Sync & Stock
**Mission slug:** `WIZARD_DYNAMIC_SYNC_STOCK_DEEP_VALIDATION` · **Date:** 2026-06-08
**Owner-mandat:** rendre la **gestion de wizard 100% dynamique et configurable par l'owner** (composer des pages par catégorie, logique de choix complète exposée, photo/description/prix par option), **plus** valider en profondeur **Synchronisation** et **Stock**. Sans dépasser la vision V1 LOCAL Le Cayenne.
**Statut:** PLAN-ONLY — à contresigner par l'owner avant exécution (le wizard touche 3 flags + 2 zones frozen + des migrations de schéma).
**Sources d'ancrage:** vérifs `grep/ls` directes (2026-06-08) + workflow read-only 7-cellules `w8e53n396` (7 agents, architecture-map adversariale). Tous les `file:line` ci-dessous sont vérifiés, 0 fiction.

---

## §0 — Préambule

### §0.1 Working-tree decision
- Worktree `pre-cloud-exec`, branche `heal/cms-pr1-quickwins-2026-05-18` (HEAD `b818e6506`). **Aucun push.**
- Bruit pré-existant (`.playwright-mcp/*.yml` supprimés, `.gitignore`) → **exclu** des commits (`git add <fichiers ciblés>`, §3quater).
- Commits par wave, préfixe `feat(wizard-Wn)` / `chore(sync-Wn)` / `chore(stock-Wn)`. Toute migration = commit isolé + test.

### §0.2 Le reframe central (lire AVANT décomposition)
Le système wizard **existe déjà à ~80%** (modèles, services, contrôleurs, builder Vue 962 l., 25 tests, versioning/publish/diff). **PAS un stub.** Il est **désactivé / partiel derrière 3 flags + un schéma incomplet** :
| Flag | Fichier:ligne | Défaut | Gate |
|---|---|---|---|
| `FEATURE_WIZARD_PER_ITEM_DEMO` | `config/catalog_v15.php:174-175` | **false** | builder **par-item** (`wizard.per_item_demo`→404). **Par-CATÉGORIE = LIVE, non gaté** |
| `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` | `config/catalog_v15.php:99-104` | **false** | rendu **POS** des profils composés (`pos-wizard.js:551-558`) |
| `FK_CATALOG_COMPOSER_VERSION_CHECK_ENABLED` | `config/catalog_v15.php:144-153` | **false** | garde prix/version dans `PricingService` (frozen, dé-frozen via GATE doc) |

**La vraie question (load-bearing) :** pourquoi est-ce OFF, et que manque-t-il pour l'allumer en sécurité + réaliser la vision owner ? Réponse vérifiée → §0.3. C'est ce qui donne la forme du GOAL.

### §0.3 Architecture vérifiée vs vision owner — l'écart EXACT
L'owner imagine un **CMS page-builder** (page = toile vierge, on tape options + photo + description + prix). L'architecture réelle est un modèle de **source-binding** (vérifié 7 agents) :
- `item_wizard_steps.source_type` ∈ `['item_attribute','extra_group','addon','fixed']` (`migration 2026_04_27_143110:17`). Une **page (step) se LIE à une construction catalogue** ; elle ne porte **aucune** option ni prix/photo/description.
- `ComposerProfileProjection::choices()` (`:75-169`) lit nom + dispo depuis la **source catalogue** (variations/extras/addons) et n'émet **que** `{id, name, source_type, availability}`.
- **Le prix est PROHIBÉ par design sur un step** : `ComposerStepRequest.php:32` → `'price' => ['prohibited']`. Le prix vit sur `item_variations.price` / `item_extras.price` (decimal 19,6). `PricingService` reprice **tout** serveur par IDs. **C'est NF525-correct et NON négociable** (§7/§8).

**Ce qui EXISTE déjà (à valider/exposer, pas construire) :**
| Demande owner | Mécanisme existant | État |
|---|---|---|
| single radio cocher/décocher | `min_select=0,max_select=1` | ✅ data-model |
| single obligatoire | `min_select=1,max_select=1` | ✅ data-model |
| multi (plusieurs) | `max_select>1` | ✅ data-model |
| quantité (plusieurs fois la même) | `allow_repeat=true` → stepper kiosk (`KioskStepGenericChoicesComponent.vue:69,86,112`) | ✅ data-model |
| gratuit/payant | prix source = 0 ⇒ gratuit (implicite) | ✅ (SSOT) |
| composer/ajouter/supprimer/réordonner page | `ComposerStepController` + `position` | ✅ (réordonner = destructif, voir GAP-D) |
| pages pré-construites | `ComposerTemplateService` (simple/sandwich/tacos/assiette/snacking/menu) | ⚠️ **6 shapes génériques, PAS par-catégorie** |
| rendu dynamique kiosk + POS | kiosk `KioskWizardComponent.vue:779` **LIVE** ; POS `pos-wizard.js:551` flag-OFF | ✅ kiosk / ⚠️ POS gaté |

**Les GAPS RÉELS vs vision (vérifiés, anchored) :**
| ID | Gap | Sév | Ancre |
|---|---|---|---|
| **GAP-A** | Logique de choix **non exposée dans le builder UI** — le form panel n'a que des sliders min/max ; pas de mode explicite *gratuit/payant · radio · multi · quantité* ; `allow_repeat` **pas surfacé** | **P1** | `ComposerStepFormPanel.vue:81-228` vs `ItemWizardStep.php:21` |
| **GAP-B** | Per-option **DESCRIPTION non modélisée** nulle part (aucune colonne sur `item_variations`/`item_extras`) | **P1** | `ItemExtra.php:15` ; `ItemVariation.php:19-27` |
| **GAP-C** | Per-option **PHOTO** = résolue par nom→fichier (`config/menu_images.php`), **pas d'upload/assignation libre** | **P1** | `ItemVariation.php:61-95` |
| **GAP-D** | Templates **6 shapes génériques, PAS par-catégorie** — burger/omelette/salade/galette → fallback `custom` vide | **P1** | `ComposerTemplateService.php:19,128` |
| **GAP-E** | Builder par-item **demo-gaté** ; par-catégorie LIVE mais `apply-template`/`available-sources` item-gatés ⇒ flux catégorie ne peut pas bootstrap un template proprement | **P2** | `EnsureProfileNotItemOwnedUnlessDemoEnabled.php:23-25` ; `routes/api.php:768-786` |
| **GAP-F** | `ComposerProfileService::update()` **destructif** : delete+recreate tous les steps ⇒ churn d'IDs à chaque save (réordonner non first-class) | **P2** | `ComposerProfileService.php:133-138` |
| **GAP-G** | Deux systèmes catégorie déconnectés : colonne legacy `wizard_template` STRING vs `item_wizard_profiles` polymorphe | **P2** | `ItemCategoryWizardSeeder.php:18-36` |
| **GAP-H** | `source_type='fixed'` = enum mort (autorisé migration, rejeté service `:85`, projection `[]` `:169`, non rendu) | **P3** | `ComposerStepService.php:12` |

### §0.4 Verdict binaire du rendu frozen (la question-clé)
**Composer une page qui réutilise les 3 source-kinds existants (`item_attribute`/`extra_group`/`addon`) = FROZEN-SAFE, autonome.** Les deux surfaces frozen construisent déjà les pages dynamiquement depuis `composer_profile.steps`, logique pilotée par `min/max/allow_repeat` — **aucune** ligne frozen à toucher pour ajouter/éditer une page de ces 3 types.
**Croise dans le frozen (⇒ LOCK gate obligatoire) :** (1) **rendre per-option photo/description** (le renderer ne reçoit pas ces champs aujourd'hui), (2) un **nouveau source-kind** (`fixed` ou autre) — `composerStepType()` skippe tout type inconnu (`KioskWizardComponent.vue:801-819`), (3) allumer le rendu POS (flip flag sur `pos-wizard.js` frozen).

### §0.5 Pipeline par tâche + convergence
- Chaque tâche → pipeline `ultra-audit-profond` (audit→test→visual→RED→heal). Non ré-décrit.
- **Convergence** = 2 cycles consécutifs P0+P1=0 ET findings sets identiques. Rejets (Axis 6 skill) : raw label, layout cassé, console error, ligne frozen-diff, P0 RED non traité, acceptance sans chemin de test.
- Mutations : **`:8766` foodking_e2e** (`DB_DATABASE=foodking_e2e APP_ENV=e2e`). **JAMAIS** `:8765`. **JAMAIS** `php artisan test` (wipe MySQL partagé) → `vendor/bin/phpunit --filter X`.

### §0.6 Hors-scope (vision-guard — NE PAS dépasser V1)
⛔ Prix libre par option (NF525 — `price` restera `prohibited` sur le step). ⛔ Divergence par-branche (le builder écrit **toujours `branch_id_scope=NULL`** global). ⛔ Marketplace/partage de templates. ⛔ Multi-tenant / cloud. ⛔ CMS générique. ⛔ Catégories inventées (bornage aux **11 catégories réelles**). ⛔ Mobile/web standalone (séparés, no-wireup).

---

## §1 — Map principal (3 systèmes, ancres vérifiées)
| Système | Maturité | Ancre code | Tests |
|---|---|---|---|
| **1. WIZARD/Composer** (★ ~70% du GOAL) | ~80% construit, flag/schéma partiel | `app/Services/Composer/{ComposerProfileService,ComposerStepService,ComposerProfileProjection,ComposerDiffService,ComposerTemplateService}.php` ; `ItemWizardProfile/Step/StepVersion` ; `Admin/ComposerProfile|StepController` ; `resources/js/components/admin/items/composer/*` (8 .vue) ; `routes/api.php:766-790` | **25** |
| **2. SYNCHRONISATION** | mature (deploy-gate ouvert) | outbox transactionnel : `HasDomainEvents`→`domain_events`→`DispatchDomainEventsJob`(`high`, claim `lockForUpdate`)→`EventContract`→soketi `private-branch.{id}`→KDS/OSS/POS + polling fallback | **~26 (20 back +6 front)** |
| **3. STOCK** | partiel (invariants durs prouvés, gate « rupture » soft) | `StockService`(on_hand `lockForUpdate`+idempotency) ‖ `AvailabilityService`(`is_available`+quota `Cache::add`) → gate `assertItemsOrderableForBranch` | **~83 fichiers** |

## §2 — Systèmes séparés
Aucun. Mobile + web restent **standalone no-wireup** (mandat owner). Non touchés.

---

## §3 — Système 1 : WIZARD / Composer dynamique (★ cœur)

### Contract
L'owner accède au builder de **chaque catégorie réelle** (et item), **compose des pages** (ajoute/édite/supprime/réordonne), choisit la **logique de choix EXPLICITE** par page (gratuit/payant · single radio · multi · quantité), gère **photo/description par option**, **publie** (versioning + diff + rejet mi-panier), et **kiosk + POS rendent** le profil à l'identique — sans jamais casser le SSOT prix NF525.

### Frozen zones (strict-no-touch sans gate §7)
- `KioskWizardComponent.vue` (consomme déjà `composer_profile` `:779/:801-819/:887`) — lecture seule sauf gate.
- `public/js/pos-wizard.js` (composer-aware codé `:444/:551-558`, gaté) — lecture seule sauf gate.
- `app/Services/Pricing/PricingService.php` — SSOT prix ; version-check dé-frozen **uniquement** via `docs/gates/GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK.md`.

### Décomposition (4 sous-systèmes)

#### Sub 1.1 — Builder : composer des pages + EXPOSER la logique de choix (GAP-A, GAP-E, GAP-F)
**Anchors :** `ProductComposerEditorComponent.vue`(962 l.), `ComposerStepFormPanel.vue:81-228`, `ComposerStepListSidebar.vue`, `StepPreviewComponent.vue`, `ComposerTemplatePickerModal.vue` ; `Admin/ComposerStepController.php` ; `ComposerProfileService.php:122-147` ; routes `api.php:779-786`.
**Tasks :**
- T-1.1.1 Valider CRUD page (create/rename/delete) sur **item** ET **catégorie** ; prouver le **flux catégorie LIVE sans flag** (GAP-E) — fixer le bootstrap template-catégorie si bloqué.
   • test: `tests/Feature/Composer/ComposerStepServiceContractTest.php` + `ComposerControllerCategoryRoutesTest.php` + `ComposerProfileServiceCategoryTest.php`
   • visual: `/admin/items` éditeur composer (catégorie)
- T-1.1.2 **(GAP-A, cœur « dynamique »)** EXPOSER dans le form panel des **modes de logique explicites** mappés sur les champs existants : *gratuit/payant* (lecture du prix source), *single radio* (`min0-1/max1`), *multi* (`max>1`, min req.), *quantité* (`allow_repeat`, non surfacé aujourd'hui). Pas de nouveau champ DB ; on **expose** le modèle. Garde `min<=max` respectée.
   • anchor: `ComposerStepFormPanel.vue:81-228`, `ItemWizardStep.php:21,46-49`
   • test: `(TO BE CREATED at tests/Feature/Composer/ComposerStepChoiceLogicMappingTest.php)` + `(TO BE CREATED at tests/js/composerStepFormPanelModes.spec.js)`
   • visual: form panel — radios/checkboxes/quantité selon mode
- T-1.1.3 **(GAP-F)** Rendre le réordonnancement **non destructif** ou prouver que le churn d'IDs est sans impact (versioning/diff/commandes en cours). Si impact → patch `update()` pour préserver l'identité de step.
   • test: `(TO BE CREATED at tests/Feature/Composer/ComposerStepReorderStableIdentityTest.php)`
- T-1.1.4 Surfacer en **lecture** les options réelles de la source (nom + prix catalogue) dans la preview, avec **deep-link** vers l'éditeur catalogue (le builder ne duplique jamais le prix — GAP §0.3).
   • test: `(TO BE CREATED at tests/Feature/Composer/ComposerStepSourcePreviewTest.php)`
   • visual: preview montre prix SSOT, aucun champ prix éditable
**Acceptance :** CRUD page vert item+catégorie, **modes de choix exposés + prouvés**, réordonnancement sûr, preview SSOT read-only. 0 frozen-diff. 0 raw label.

#### Sub 1.2 — Per-option photo + description (GAP-B, GAP-C) — **schéma + projection, rendu = gate**
**Le gap le plus profond de la vision** : photo/description par option n'existent **pas** en schéma. Travail data-layer d'abord ; le **rendu** dans les wizards frozen = LOCK gate (§0.4).
**Anchors :** `ItemVariation.php:19-27/61-95`, `ItemExtra.php:15/41-71`, `ComposerProfileProjection.php:91-163`.
**Tasks :**
- T-1.2.1 **ADR (Plan subagent)** : où vivent photo+description par option ? Recommandation : colonnes `description` (+ `image_path`/upload) sur `item_variations`/`item_extras` (SSOT catalogue), **jamais** sur le step. Sortie = ADR, **pas de code avant décision**.
- T-1.2.2 Migration additive : `description` nullable (+ chemin photo) sur les tables source ; backfill NULL ; éditable depuis l'éditeur catalogue.
   • test: `(TO BE CREATED at tests/Feature/Catalog/OptionDescriptionPhotoSchemaTest.php)`
- T-1.2.3 Étendre `ComposerProfileProjection::choices()` pour émettre `description` + `image` par choix (data-side ; rendu reste gaté).
   • test: `(TO BE CREATED at tests/Feature/Composer/ComposerChoiceMediaProjectionTest.php)`
- T-1.2.4 **LOCK gate** : rendu per-option photo/description dans `KioskWizardComponent`/`pos-wizard.js` (frozen) → `lock-plan` doc + triple-vert. **STOP** si non contresigné.
**Acceptance :** ADR commitée, migration additive verte + backfill, projection émet media, **rendu frozen NON touché sans LOCK**. Prix toujours SSOT.

#### Sub 1.3 — Templates par-catégorie (GAP-D, GAP-G) + publish/versioning/diff
**Anchors :** `ComposerTemplateService.php:19,128`, `ItemCategoryWizardSeeder.php:18-36`, `ComposerProfileService.php` (`publish`/`unpublish`), `ComposerDiffService.php`, `ItemWizardStepVersion.php`, modals `ComposerPublishDiffModal.vue`/`ComposerVersionConflictBanner.vue`.
**Tasks :**
- T-1.3.1 **(GAP-D)** Étendre `ComposerTemplateService` à **catégorie-keyed** pour les **11 catégories réelles** (burger/omelette/salade/galette inclus) — chaque catégorie a un starter non-vide. Bornage : 11 catégories réelles (grep la DB `item_categories`, ne pas inventer).
   • test: `(TO BE CREATED at tests/Feature/Composer/ComposerTemplatePerCategoryCoverageTest.php)` + `ComposerTemplateApplyTest.php`
- T-1.3.2 **(GAP-G)** Réconcilier ou retirer la colonne legacy `wizard_template` STRING (décider : map→profil, ou déprécier). ADR si destructif.
   • test: `(TO BE CREATED at tests/Feature/Composer/LegacyWizardTemplateReconcileTest.php)`
- T-1.3.3 Valider publish→version immuable→diff + **rejet mi-panier** + conflit concurrent + unpublish.
   • test: `ItemWizardStepVersionImmutabilityTest.php` + `ComposerDiffServiceProductionPathTest.php` + `ProfilePublishMidCartRejectionTest.php` + `ComposerProfileVersionConflictTest.php` + `ComposerProfileUnpublishTest.php`
**Acceptance :** 11 catégories couvertes par un template, legacy tranché (ADR), cycle publish/diff/rejet/conflit/unpublish vert, 0 frozen-diff.

#### Sub 1.4 — Prix SSOT + dispo + rendu cross-surface (NF525-critical)
**Anchors :** `PricingService.php`(FROZEN), flag `composer_profile_version_check` + GATE doc, `ComposerProfileProjection.php`(dispo `stockable_choices`), `OrderRequest.php`.
**Tasks :**
- T-1.4.1 **Verdict reprice (re-prouver) :** profil composé 100% repricé serveur ; front n'envoie que `item_id`+`option_ids` ; aucun prix client.
   • test: `tests/Feature/Pos/PosOrderRequestNoClientTotalsTest.php` + `tests/Feature/Services/Pricing/ComposerStepConstraintTest.php`
- T-1.4.2 Version-check prix périmé → rejet propre (flag `FK_CATALOG_COMPOSER_VERSION_CHECK_ENABLED`).
   • test: `tests/Feature/Catalog/ComposerSchemaTest.php` + `(étendre OrderRequest version test)`
- T-1.4.3 Dispo par choix (`stockable_choices`) — option en rupture grisée (croise §5).
   • test: `tests/Feature/Composer/ComposerProfileProjectionVariationRuptureTest.php`
- T-1.4.4 Parité **kiosk (live) ↔ POS (flag ON e2e)** d'un même profil composé (3 source-kinds = frozen-safe §0.4).
   • test: `ComposerPublishSyncTest.php` + `tests/Feature/Pos/FritesWizardComposerTest.php`
   • visual: kiosk `/kiosk/idle`→item composé ; POS `/admin/pos`→même item
**Acceptance :** reprice serveur prouvé (0 prix client), version-check rejette périmé, rupture grise, **parité kiosk↔POS capturée**, NF525 chain inchangée.

---

## §4 — Système 2 : Synchronisation (validation profonde + deploy-gate)

### Contract
emit → outbox (même tx) → `afterCommit` job `high` → claim exactly-once → contrat envelope → soketi `private-branch.{id}` → KDS/OSS/POS + fallback polling. Dégrade proprement si WS down.

### Frozen/sensibles
Aucun fichier core sync n'est frozen §7 ; `IdempotencyKeyMiddleware` (frozen) borde les POST — lecture seule.

### Décomposition (4 sous-systèmes)
#### Sub 2.1 — Outbox exactly-once + ordering (validation)
- T-2.1.1 Prouver claim atomique `lockForUpdate` + commit-before-broadcast + crash-recovery + fail-once sur violation contrat.
   • anchor: `DispatchDomainEventsJob.php:65-190`, `HasDomainEvents` , `DomainEvent.php`
   • test: suite outbox existante ; `(TO BE CREATED at tests/Feature/Sync/OutboxReplayIdempotentTest.php)` si trou.
#### Sub 2.2 — **DEPLOY GATE (headline P1)** : liveness worker + scheduler
- T-2.2.1 Prouver que la garantie exactly-once **dépend** du worker `queue:work` lane `high` + `schedule:run` (`outbox:rescue`) **réellement actifs** sur la box. launchd/supervisor existent mais **pas prouvés-live**.
   • anchor: `app/Console/Kernel.php:40-53`, `deploy/local/fr.lecayenne.queue-high.plist:5-24`
   • → **owner gate G-SYNC-1** (process manager en prod).
- T-2.2.2 Résoudre la **divergence `--tries`** : job `$tries=6` vs plist `--tries=1` vs ansible `--tries=3` — aligner (le job gagne, mais clarifier l'intention).
   • anchor: `DispatchDomainEventsJob.php:42`
#### Sub 2.3 — Dégradation WS → polling + version-gate
- T-2.3.1 Prouver fallback polling (finding mémoire SYNC-WS-01 : browser ws:6001 échoue→polling) sur KDS+POS.
   • visual: `/kds` + `/admin/pos` WS coupé → commande apparaît.
- T-2.3.2 **KDS version-gate skip status-only** : `computeOrderVersion`=updated_at unix ; un changement de statut sans bump → carte gatée. Prouver/corriger.
   • anchor: `KdsSyncService.php:165-181`
#### Sub 2.4 — Contrat d'event (parity) + escalade
- T-2.4.1 `eventContract.js`/`schema.json` ↔ payload backend (0 drift de clé).
   • test: `(TO BE CREATED at tests/js/syncEventContractParity.spec.js)`
- T-2.4.2 `OutboxBroadcastSwallowedEvent`→`EscalateOutboxBroadcastSwallowed` testé ; noter qu'en V1 LOCAL l'alerte = log-grep nocturne (pas de pager) → doc.
   • anchor: `EscalateOutboxBroadcastSwallowed.php:45`, `SYNC_CONTRACT.md:50`
**Acceptance §4 :** exactly-once prouvé, **deploy-gate documenté + G-SYNC-1 levé owner**, `--tries` aligné, fallback capturé live, status-only version-gate corrigé/prouvé, parity vert. 2 cycles identiques P0+P1=0.

---

## §5 — Système 3 : Stock (validation profonde + grading honnête)

### Contract
Deux ledgers → un flag : `StockService` (on_hand `lockForUpdate`+idempotency) ‖ `AvailabilityService` (`is_available` + quota `Cache::add`). Gate commande = `assertItemsOrderableForBranch`. Jamais de survente NF525-impactante.

### Anchors
`AvailabilityService.php:262-274/360-393`, `StockService.php:86-117`, `DecrementStockOnOrderCreated.php:36-61`, modèles `StockLevel/ItemBranchAvailability/StockMovement`, `StockRuptureDashboardComponent.vue`, route `/admin/stock/rupture`.

### Décomposition (4 sous-systèmes)
#### Sub 3.1 — Invariants durs (lock + prouver)
- T-3.1.1 Prouver no-negative on_hand + append-only movements + relâche idempotente (refund/cancel sans double-relâche).
   • test: suite stock existante (release tests) ; cibler refund/cancel.
#### Sub 3.2 — **Grading honnête du gate « rupture »** (P2, à trancher avec owner)
- T-3.2.1 Établir que « rupture bloque commande caisse+borne » est un **pré-flight SOFT** (lit `is_available`, flip POST-décrément, lag 1 commande au boundary).
   • anchor: `AvailabilityService.php:262-274,360-380`
- T-3.2.2 **on_hand-rupture avalé post-commit** : `StockService` throw mais `DecrementStockOnOrderCreated` catch → `OrderCreated` déjà commité ⇒ commande EXISTE, stock non-décrémenté, **aucun rejet surfacé**. Décider hard-vs-soft (owner) — ne PAS durcir le hot-path à l'aveugle.
   • anchor: `DecrementStockOnOrderCreated.php:36-61`
#### Sub 3.3 — Concurrence réelle (asserted-by-design → prouvé MySQL)
- T-3.3.1 Le test le plus fort est single-process SQLite (`test_serialized_concurrent_decrements`). Prouver `lockForUpdate`/`Cache::add` sous **MySQL multi-worker réel**.
   • anchor: `tests/Feature/Stock/AvailabilityDecrementConcurrencyTest.php:75-95`
   • test: `(TO BE CREATED at tests/Feature/Stock/ConcurrentDecrementMysqlTest.php)` (gate CI MySQL)
#### Sub 3.4 — Données réelles + cross-surface
- T-3.4.1 **VERIFIER live OVH** : V1 semble livrer **sans stock numérique seedé** (absent = illimité). Confirmer `stock_levels.on_hand`/`max_daily_qty` sur la vraie DB → STOCK-3-01 (cap oversell) **latent** si NULL.
   • anchor: `database/seeders` (aucune écriture on_hand) ; `AvailabilityService.php:264 vs 360`
- T-3.4.2 Chaîne cross-surface : toggle 86 admin → POS/kiosk/KDS sous latence bornée (croise §4).
   • visual: `/admin/stock/rupture` + propagation ; test `(TO BE CREATED at tests/Feature/Stock/EightySixCrossSurfaceTest.php)`
**Acceptance §5 :** invariants durs prouvés, gate « rupture » **gradé honnêtement + décision owner** (pas de durcissement aveugle), concurrence prouvée MySQL, données live vérifiées, dashboard propre. 2 cycles identiques P0+P1=0.

---

## §A — Agent army + fan-out
| Rôle | Subagent | Tools | Brief |
|---|---|---|---|
| Architect | `Plan` | RO | ADR media (T-1.2.1), legacy template (T-1.3.2), hard-vs-soft stock (T-3.2) |
| Security | `general-purpose` | RO | reprice serveur (T-1.4.1), authz composer (`ComposerAuthzMinimalTest`) |
| DBA | `general-purpose` | RO | migrations additives, FK cascade, concurrence MySQL (T-3.3) |
| SRE/Sync | `general-purpose` | RO | outbox/idempotence/deploy-gate/fallback (§4) |
| Implementer | `general-purpose` | Edit/Bash | **jamais 2 // en parallèle** ; TDD-first ; scope-minimal |
| RED-team | `general-purpose` | RO | réfute chaque P0/P1 ; vérifie file:line réel |
| QA Visual | `general-purpose` | Playwright | builder/kiosk/POS/KDS/rupture |
| RED Visual | `general-purpose` | RO | re-analyse + dispute |

**Fan-out :** audit d'une wave = 5 RO en **un message** (parallèle). Implementer séquentiel. QA+RED visual //. RED dispute **toujours** avant DONE. Reporting → `reports/test-e2e/wizard-dynamic-2026-06-08/<wave>/<role>.json`.

---

## §X — Waves
| Wave | Scope | Parallélisme | Checkpoint |
|---|---|---|---|
| **W0** | Préflight : flags OFF confirmés, `:8766` frais, **DB live cap/stock audit (T-3.4.1)**, grep 11 catégories réelles, `git status` propre | séquentiel | env prêt, catégories réelles listées |
| **W1** | Sub 1.1 builder + **exposer logique de choix** (GAP-A/E/F) | audit RO×5 // impl séq. | modes exposés+prouvés, réordonnancement sûr |
| **W2** | Sub 1.3 templates par-catégorie + publish/diff/rejet (GAP-D/G) | audit RO×5 | 11 catégories couvertes, cycle publish vert |
| **W3** | Sub 1.4 prix SSOT + parité kiosk↔POS | audit RO×5 // QA+RED visual | reprice prouvé, parité capturée, **NF525 chain inchangée** |
| **W4** | Sub 1.2 photo/description (GAP-B/C) : ADR→migration→projection ; rendu = **gate** | Plan→impl séq. | schéma+projection verts, rendu frozen NON touché sans LOCK |
| **W5** | §4 Sync : exactly-once + **deploy-gate** + fallback + version-gate | audit RO×3 // visual | gate documenté, G-SYNC-1 owner, fallback live |
| **W6** | §5 Stock : invariants + **grading honnête** + concurrence MySQL + live data | audit RO×3 // visual | décision hard/soft owner, concurrence prouvée |
| **W7** | Convergence + dossier d'allumage flags (préparé, NON allumé) | RED full | 2 cycles identiques P0+P1=0, `GATE-WIZARD-FLAGS.md` prêt |

**Checkpoint commun (6) :** tasks PASS/doc · frozen-diff=0 (`git diff --stat <range> -- <frozen-list>`) · NF525 `audit_logs count+MAX(current_hash)` inchangé · visual gate tiré · RED traité · BRAIN §2/§3 maj.
**Interrupt-resume :** commit `wip(Wn): partial` + manifeste `reports/test-e2e/wizard-dynamic-2026-06-08/INTERRUPT_Wn_<ts>.md` + BRAIN §2.
**Convergence-failure (3 loops) :** STOP → `Plan` subagent → `STUCK_Wn.md` → surface owner (A accept-doc / B pivot / C defer V1.0.X / D human). Pas d'auto-choix.

---

## §G — Owner gates (WHO / WHAT / WHERE)
| Gate | Description | WHO | WHAT | WHERE | Statut |
|---|---|---|---|---|---|
| **G-WIZ-1** | `FEATURE_WIZARD_PER_ITEM_DEMO=true` en prod (builder par-item) | Owner | décision + `.env` | commit + BRAIN §2 | PENDING |
| **G-WIZ-2** | `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true` — **change le comportement du frozen `pos-wizard.js`** (§7/§12) | Owner | LOCK contresigné | `docs/gates/GATE-WIZARD-FLAGS.md` (à créer) | PENDING |
| **G-WIZ-3** | `FK_CATALOG_COMPOSER_VERSION_CHECK_ENABLED=true` — dé-frozen garde prix | Owner | gate doc contresigné | `docs/gates/GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK.md` | PENDING |
| **G-WIZ-4** | Rendu per-option **photo/description** dans wizards frozen (T-1.2.4) | Owner | `LOCK_<id>.md` + triple-vert | `lock-plan` doc | CONDITIONNEL |
| **G-WIZ-5** | Tout **nouveau source-kind** (`fixed`/autre) qui croise le renderer frozen | Owner | LOCK frozen | `lock-plan` doc | CONDITIONNEL |
| **G-SYNC-1** | Process manager prod : worker lane `high` + `schedule:run` actifs (liveness exactly-once) | Owner/ops | preuve `supervisorctl status` / launchd | deploy report + BRAIN §2 | PENDING |
| **G-STOCK-1** | Décision **hard-vs-soft** sur le gate « rupture » + on_hand avalé (T-3.2) | Owner | décision écrite | BRAIN §6 DECISIONS | PENDING |

**Owner-gate-waiting :** W1–W6 (audit/validation/heal non-frozen + schéma additif) **tournent SANS attendre** les gates — elles préparent. Seuls **l'allumage prod**, **toute ligne frozen** (G-WIZ-2/4/5), le **process manager** (G-SYNC-1) et la **décision stock** (G-STOCK-1) attendent l'owner. Le GOAL livre « **prêt-à-allumer** », pas « allumé ».

---

## §R — Références
- Skills : `ultra-architect-planify`, `ultra-audit-profond` (par tâche), `superpower-gstack`, `test-e2e`, `lock-plan`, `verify-before-report`.
- Docs : `CONSTITUTION.md`, `PROJECT_BRAIN.md §2`, `SYSTEM_MAP.md`, `SYNC_CONTRACT.md`, `PARALLEL_PROTOCOL.md`, `CLAUDE.md §7/§8`, `docs/gates/GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK.md`.
- Mémoire : `reference_composer_wizard_hinge_2026-06-07.md`, `reference_e2e_harness_foodking_e2e_2026-06-07.md`.
- Évidence d'ancrage : workflow `w8e53n396` (7-cellules read-only architecture-map, 2026-06-08).

## §F — Règle finale (DONE)
DONE quand :
1. Builder permet **composer/éditer/supprimer/réordonner des pages** sur **chaque catégorie réelle + item**, avec **logique de choix EXPLICITE exposée** (gratuit/payant · radio · multi · quantité) prouvée par test ET capturée.
2. **Photo + description par option** ont un home schéma (catalogue SSOT) + sont projetées ; leur **rendu frozen attend LOCK**.
3. **11 catégories réelles** ont un template ; legacy `wizard_template` tranché ; publish/diff/rejet/conflit/unpublish verts.
4. Kiosk **et** POS rendent le même profil composé (parité capturée), **reprice 100% serveur prouvé**, NF525 chain intacte.
5. Sync + Stock **validés en profondeur** : exactly-once + deploy-gate + fallback (sync) ; invariants durs + grading honnête + concurrence MySQL (stock) ; 2 cycles identiques P0+P1=0.
6. **`GATE-WIZARD-FLAGS.md` prêt à contresigner** — 0 ligne frozen modifiée sans gate.

**Pas « presque ». Production-perfect dans l'enveloppe V1 LOCAL Le Cayenne — ou block + gate.**
