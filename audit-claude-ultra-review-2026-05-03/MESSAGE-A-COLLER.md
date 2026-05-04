# Ultra-Review GLOBALE — Catalog Tree FoodKing (design Claude v2 + base technique + cohérence vision)

> **À toi, Claude (Opus 4.7 ou supérieur, raisonnement maximum, effort high)**
> Tu démarres avec **zéro contexte conversationnel**. Tout ce que tu dois savoir est dans ce message + le package de fichiers attaché + le **MCP Graphiti** (group_id : `foodking`). Aucun autre contexte n'est implicite.
>
> **Ne te précipite pas.** Lis tout le message une fois avant d'agir. Cet audit est ce qui décide si on intègre la livraison design en Phase β ou si on retravaille. Une review complaisante = échec de mission. Une review rigoureuse = valeur réelle.

---

## 1. QUI TU ES

Tu es l'**ultra-reviewer indépendant** mandaté pour auditer **toute la chaîne** du Catalog Studio FoodKing :

1. **La vision produit** (ce que je veux comme expérience plug-and-play, type Shopify mais pour la restauration).
2. **L'arbre central** (centralisation depuis la racine BDD jusqu'aux feuilles runtime POS/Kiosk, avec sync, stock, modifications).
3. **La base technique actuelle** (DB schema, services backend, composants Vue admin, runtime POS/Kiosk).
4. **La livraison design Claude v2** (19 artboards livrés en 3 itérations).
5. **La cohérence entre les 4 niveaux** : la design est-elle vraiment branchée sur la base ? La base supporte-t-elle vraiment la vision ? L'expérience finale est-elle vraiment plug-and-play ?

Ton autorité :
- Tu peux **refuser** un livrable design qui ne reflète pas le schéma BDD.
- Tu peux **flag** un service backend qui viole un invariant FoodKing.
- Tu peux **proposer** un re-routing de la Phase β si l'audit montre que l'ordre β1→β5 actuel est sous-optimal.
- Tu **ne peux pas** te complaire ; pas de "tout va bien". Si tu n'es pas sûr, dis-le.
- Tu **ne peux pas** approuver un gate humain (pricing, schema, frozen, branch_id) — escalade.

Ton interdiction :
- Tu ne **modifies aucun fichier** du package. Lecture seule.
- Tu ne **lances aucune migration DB**, aucun script.
- Tu n'écris **qu'un seul artéfact** : le rapport final markdown (§14).

---

## 2. CONTEXTE FOODKING — Tout ce que tu dois savoir en 2 minutes

**FoodKing** est une **plateforme SaaS de restauration multi-surface, multi-branche, fiscalement compliante (NF525 France)**.

### Surfaces (canaux de vente / opération)

| Surface | Cible | Stack | Caractéristique |
|---|---|---|---|
| **Admin / Centrale** | gérant siège ou de filiale | Laravel + Vue 3 SPA admin | source de gestion catalogue, stock, prix, équipe |
| **POS (Caisse)** | caissier en boutique | Vue 3 SPA `pos-shell.js` | wizard **monolithique single-page** ultra-dense, raccourcis clavier, performance critique |
| **Kiosk (Borne)** | client final en libre-service | Vue 3 SPA `kiosk-shell.js` | wizard **multi-pages**, stepper visuel, beige/rouge brand, lent OK car UX guidée |
| **KDS (Cuisine)** | cuisinier brigade | Vue 3 SPA `admin-kds.js` | tracker temps réel statuts orders |
| **OSS (Order Status Screen)** | client en attente | Vue 3 SPA `admin-oss.js` | écran display salle |

Toutes ces surfaces partagent **un seul backend Laravel** + **une seule DB MySQL/Postgres** (schémas dans `00-base-foodking/db-migrations/`).

### Multi-branche (la donnée la plus critique)

- Une instance FoodKing héberge **plusieurs filiales** (= `branch_id`).
- Chaque catégorie / produit / wizard / stock peut être **scopé global** (branch_id NULL = toutes filiales) ou **scopé par filiale** (`branch_id = N`).
- **`branch_id` n'est pas un concept git, c'est une donnée business** : aucune query ne doit jamais retourner des données d'une filiale à un user qui n'y a pas accès.
- **C'est l'invariant le plus violable et le plus dangereux** s'il l'est.

### Fiscal NF525 (France, certification caisse enregistreuse)

- Les snapshots `composition_snapshot` + `allergens_snapshot` sur `order_items` = ce qui a été vendu doit être **immuable** post-paiement.
- Les `z_reports` quotidiens sont signés cryptographiquement.
- Toute modif catalogue **après** un order ne doit jamais altérer ce qui a déjà été snapshoté.

### Stack technique

- **Backend** : Laravel 10, MySQL/Postgres, Redis (cache + queue), Pusher (WebSocket events).
- **Frontend** : Vue 3 + Vuex (legacy admin) + Pinia (kiosk recent), Laravel Mix (Webpack).
- **Permissions** : Spatie Permission (rôles : `Admin`, `Manager`, `Cashier`, `Waiter`, `Branch Manager`).
- **Media** : Spatie Media Library (collections `item`, etc.).
- **Auth** : Laravel Sanctum (admin SPA) + Pusher channels privés.

---

## 3. LA VISION ARBRE — ce que je veux que tu valides

Voici **comment je vois** le système, du bas vers le haut. Ton job est de challenger si la base + le design réalisent réellement cette vision.

```
                        ┌──────────────────────────────────────┐
                        │  EXPÉRIENCE PLUG-AND-PLAY            │
                        │  "Shopify pour la restauration"      │
                        │                                       │
                        │  - 1 page = tout gérer                │
                        │  - drag & drop, modif inline          │
                        │  - sync temps réel POS/Kiosk          │
                        │  - stock central visible partout      │
                        │  - wizard custom POS-mono Kiosk-multi│
                        └──────────────┬───────────────────────┘
                                       │
                ┌──────────────────────┼──────────────────────┐
                │                      │                      │
        ┌───────▼────────┐    ┌────────▼────────┐    ┌────────▼────────┐
        │ POS RUNTIME    │    │ KIOSK RUNTIME   │    │ ADMIN STUDIO    │  (FEUILLES)
        │ pos-wizard.js  │    │ KioskPosWizard  │    │ CatalogStudio   │
        │ (monolithique) │    │ (multi-pages)   │    │ (single page)   │
        └───────┬────────┘    └────────┬────────┘    └────────┬────────┘
                │                      │                      │
                └──────────────────────┼──────────────────────┘
                                       │
                       ┌───────────────▼────────────────┐
                       │ MENU PROJECTION (Backend)      │  (BRANCHES)
                       │ MenuProjectionService          │
                       │ PosMenuProjection              │
                       │ ComposerProfileProjection      │
                       │ AvailabilityService            │
                       │ ChoiceAvailabilityResolver     │
                       └───────────────┬────────────────┘
                                       │
                       ┌───────────────▼────────────────┐
                       │ COMPOSITION & STOCK (Backend)  │  (TRONC)
                       │ ComposerStepService (CRUD)     │
                       │ ComposerProfileService         │
                       │ ComposerTemplateService        │
                       │ ComposerDiffService (NEW α4)   │
                       │ StockService                   │
                       │ ItemPhotoController (NEW α5)   │
                       └───────────────┬────────────────┘
                                       │
                       ┌───────────────▼────────────────┐
                       │ DATA MODEL — DB (SSOT)         │  (RACINES)
                       │                                 │
                       │ items, item_categories          │
                       │ item_attributes (avec min/max)  │
                       │ item_extras, item_addons        │
                       │ item_branch_availability        │
                       │ item_wizard_profiles            │
                       │ item_wizard_steps               │
                       │   + source_item_attribute_id    │
                       │     (FK typée nullable, NEW)    │
                       │ stock_levels, stock_movements   │
                       └─────────────────────────────────┘
```

### Ce que je veux que cet arbre garantisse

- **Centralisation** : modifier un attribut (ex : "Viande" avec ses 4 choix `Steak / Poulet / Saumon / Veggie`) **une fois** depuis l'admin Studio doit propager à **tous** les wizards qui le référencent (POS + Kiosk + Centrale), sur **toutes les filiales** où il est actif, **en moins de 5 secondes** via Pusher.
- **Synchronisation** : un changement de stock (rupture sur "Saumon" filiale 2) doit faire disparaître l'option du wizard runtime POS/Kiosk de cette filiale **immédiatement**, sans que l'admin ait à faire autre chose qu'un clic "marquer indispo".
- **Plug-and-play** : ajouter une nouvelle catégorie + 3 produits + 1 wizard tacos (5 étapes) doit prendre **moins de 5 minutes** depuis la page Catalog Studio, sans naviguer entre 10 pages différentes.
- **POS mono / Kiosk multi** : le **même profil wizard** (`item_wizard_profiles.id`) doit s'afficher **monolithique** dans le runtime POS (caissier voit tout sur 1 écran) et **multi-pages** dans le runtime Kiosk (client clique étape par étape). Donc 1 source, 2 projections.
- **Stock central visible partout** : la quantité disponible d'un attribut "Saumon" filiale 2 doit être visible dans le panneau Stock parallèle de la page Studio (pas une page séparée), et dans le Stock Rupture Dashboard.
- **Diff publish** : avant de publier un changement de wizard, l'admin doit voir un diff clair (3 champs ajoutés / 2 supprimés / 1 modifié), pas un dump JSON.
- **Multi-langue + RTL** : FR / EN / DE / BN / AR (avec direction RTL pour l'arabe quand activé).

---

## 4. CE QUE LA BASE LIVRE ACTUELLEMENT — état réel

### 4.1 Couche données (`00-base-foodking/db-migrations/`)

12 migrations livrées :
- `2022_11_17_*_create_items_table.php` — items (legacy 2022, stable)
- `2022_11_17_*_create_item_attributes_table.php` — sources d'attributs choisissables (Viande, Sauce, Fromage…)
- `2022_11_17_*_create_item_extras_table.php` — extras (suppléments tarifés)
- `2022_11_17_*_create_item_addons_table.php` — add-ons (boissons, desserts en menu)
- `2026_03_12_*_add_wizard_config_to_item_categories.php` — config wizard par catégorie
- `2026_04_15_*_create_item_branch_availability_table.php` — 86 par filiale (ex : "Saumon indispo filiale 2")
- `2026_04_22_*_add_min_max_repeat_to_item_attributes.php` — min/max par attribut
- `2026_04_27_*_create_item_wizard_profiles_table.php` — profil wizard (rattaché à un item ou catégorie)
- `2026_04_27_*_create_item_wizard_steps_table.php` — étapes du wizard (clé : `step_key`, `source_type`, `source_ref`, `min_select`, `max_select`, `visible_on`, `stockable_choices`, `addon_role`)
- `2026_04_27_*_create_stock_levels_table.php` — niveaux stock par item/branch
- `2026_04_27_*_create_stock_movements_table.php` — mouvements stock (idempotents)
- `2026_05_03_*_add_source_item_attribute_id_to_item_wizard_steps_table.php` — **NEW Phase α** : FK typée nullable vers `item_attributes` (modèle-correction du polymorphisme `source_ref`)

**Question clé pour toi** : ce schéma porte-t-il vraiment la vision arbre ? Y a-t-il des trous (ex : pas de table `wizard_step_branch_overrides` pour les overrides par filiale) ?

### 4.2 Couche services backend (`00-base-foodking/backend-services/`)

- **Composer/** :
  - `ComposerStepService.php` — CRUD wizard steps + dual-write `source_item_attribute_id` (Phase α)
  - `ComposerProfileService.php` — CRUD wizard profile + publish/unpublish
  - `ComposerTemplateService.php` — 7 templates prêts (`tacos`, `assiette`, `menu`, `simple`, etc.)
  - `ComposerDiffService.php` — **NEW α4** : diff between draft et published version du profil
  - `ComposerProfileProjection.php` — projette un profil → vue runtime (POS/Kiosk/web)
- **Menu/** :
  - `MenuProjectionService.php` — orchestre la projection par catégorie / surface
  - `PosMenuProjection.php` — projection spécifique POS (mono-page wizard)
  - `MenuSnapshot.php` — snapshot d'un menu pour cache
  - `AvailabilityService.php` — calcule dispo runtime (stock + branch_availability)
- **Stock/** :
  - `StockService.php` — décrement atomique (atomic conditional UPDATE — choix validé option A)
  - `ChoiceAvailabilityResolver.php` — résout dispo des choix wizard par stock + 86

**Question clé** : le pipeline est `Service CRUD → ComposerProfileChanged event → cache invalidation → menu projection → frontend`. Est-il vraiment robuste end-to-end ? L'event est-il `DispatchableAfterCommit` ?

### 4.3 Couche admin Vue (`00-base-foodking/frontend-vue-admin/`)

- `CatalogStudioComponent.vue` (~700 LOC) — **page centrale**, fusion catégories + produits + drawer composer + stock inline. C'est elle qui matérialise le "single page" promesse.
- `composer/` (6 fichiers) :
  - `ProductComposerEditorComponent.vue` — éditeur principal du wizard
  - `ComposerStepListSidebar.vue` — liste des steps draggable
  - `StepEditorComponent.vue` — édition d'une step
  - `ComposerStepFormPanel.vue` — formulaire step
  - `ComposerTemplatePickerModal.vue` — picker template
  - `StepPreviewComponent.vue` — preview live step
- `wizard/ProductCreateWizardComponent.vue` — wizard création produit guidé
- `AvailabilityToggleComponent.vue` — toggle dispo (86)
- `ItemListComponent.vue` — page legacy (encore utilisée pour edit complet)
- `stock/StockRuptureDashboardComponent.vue` — dashboard rupture stock
- `ItemPreviewComponent.vue` — preview item

### 4.4 Couche runtime (`00-base-foodking/frontend-vue-kiosk/` + `runtime/`)

- `KioskPosWizardComponent.vue` — wizard kiosk (multi-pages)
- `kiosk-steps/` — composants par type d'étape
- `runtime/pos-wizard.js` — wizard POS (monolithique single-page, vanilla JS, perf-critical) — refactoré en 3 batches Phase α (Batch A baseline, B extraction internal helpers, C adapter seam + malformed payload guards)

### 4.5 Tests livrés Phase α (`00-base-foodking/tests/`)

- `ComposerDiffServiceTest.php` — 6/6 PASS
- `ComposerStepServiceContractTest.php` — 5/5 PASS (dont 2 nouveaux dérivation `source_item_attribute_id`)
- `ItemPhotoUploadTest.php` — 5/5 PASS
- `catalog-studio-create-product-flow.spec.js` — Playwright critical-flow 1 PASS 12.2s
- `catalog-studio-a11y-axe.spec.js` — sentinelle a11y (axe-core en deps optionnel)
- `catalogStudioRouting.spec.js` — sentinelle routing UI

**Vitest global** : 1054 passed / 0 failed / 2 skipped (163 fichiers).
**PHPUnit Composer + Items** : 50 passed / 0 failed / 2 skipped.

---

## 5. CE QUE CLAUDE DESIGN A LIVRÉ — design v2 (`01-design-claude-v2/`)

### 5.1 Inventaire physique

12 fichiers dans `01-design-claude-v2/` :
- `Catalog Studio.html` (16 KB) — canvas pannable HTML interactif (preview navigateur)
- `design-canvas.jsx` (31 KB) — 7 sections, 12 artboards initiaux (S1 Liste catégories, S2 Quick Create, S3 Wizard Editor avec POS+Kiosk previews, S4 Diff Modal, S5 Stock Dashboard, S6 États partagés, README handoff)
- `studio-iter1.jsx` (29 KB) — 5 artboards Critiques (drag&drop, branch overrides, image upload, diff modal, conflict)
- `studio-iter2.jsx` (38 KB) — 7 artboards Importants (source picker, autosave, empty/loading/error/no-perm, toasts, permission matrix, stock resolve, search)
- `studio-iter3.jsx` (36 KB) — 7 artboards Polish (cheatsheet keyboard, responsive 1280px, RTL Arabic, onboarding, photos guidance, micro-interactions × reduced-motion, README v2)
- `studio-screen{1,2,3}.jsx` — composants par écran réutilisables
- `studio-data.jsx` (17 KB) — fixtures fidèles au schéma `ItemWizardStep`
- `studio-extras.jsx` (28 KB) — composants secondaires (badges, pills, etc.)
- `tokens.css` (10 KB) — additions seules `--studio-*` (pas de redéfinition CV1 / brand)
- `uploads/` — copie du brief original soumis à Claude Design + screenshots

### 5.2 Mapping artboard → fichier Vue cible (selon README v2 livré)

| Écran design | Artboard | Fichier Vue cible côté base |
|---|---|---|
| S1 Liste catégories + grille produits | initial | `CatalogStudioComponent.vue` |
| S2 Quick Create catégorie/produit | initial | `<CatalogQuickCreate>` (inline dans CatalogStudio) |
| S3 Wizard Editor | initial + iter 1 ① ② ⑤ | `ProductComposerEditorComponent.vue` + `ComposerStepListSidebar.vue` + `StepEditorComponent.vue` |
| S4 Diff Modal | iter 1 ④ | `ComposerPublishDiffModal.vue` (NEW) — branché sur α4 endpoint |
| S5 Stock Rupture Dashboard | initial | `StockRuptureDashboardComponent.vue` + `AvailabilityToggleComponent.vue` |
| S6 États partagés | iter 2 ⑦ ⑧ ⑨ | composables `useEmptyState`, `useToast`, `useAutoSave` |
| Variants 1280px / RTL / Onboarding | iter 3 | tous les composants ci-dessus |

### 5.3 Caveats déclarés par Claude Design (à NE PAS re-flag, déjà acté)

1. Side-sheet ⑥ source-create = démo, à brancher sur `ItemAttributeService::create()` / `ExtraGroupService::create()` côté Vue → β1 trivial
2. Police Noto Sans Arabic non chargée → β4 config `@font-face`
3. Keybindings J/K/D/⌫ wizard = proposition à valider avec équipe POS → décision humaine

---

## 6. INVARIANTS FOODKING — non-négociables

Ces 6 invariants sont la ligne rouge. Toute violation = `ESCALATE` automatique avec gate humain à ouvrir.

| # | Invariant | Définition courte | Cible audit |
|---|---|---|---|
| **I1** | **Backend = SSOT pricing** | Aucun prix calculé / dérivé / overridé en Vue. Le frontend affiche ce que le backend retourne. | Vérifier `CatalogStudioComponent.vue` + `ProductComposerEditorComponent.vue` + design Claude → aucune logique prix Vue |
| **I2** | **OrderStatus enum autoritaire** | Pas de string littéral pour status order. | Hors scope direct, mais vérifier que `ComposerDiffService` ou Studio ne réintroduit aucun status string |
| **I3** | **`branch_id` = isolation business stricte** | Aucune query / mutation cross-branch sans autorisation explicite. | **POINT CHAUD** : `ItemPhotoController::store(Item $item)` n'a pas de check explicite que admin a accès à `$item->branch_id` |
| **I4** | **Dispatch après DB commit** | Events / jobs dispatchés strictement après commit. Jamais dans une tx avant commit. | `ComposerStepService::create/update/delete` dispatche `ComposerProfileChanged` — vérifier `DispatchableAfterCommit` trait |
| **I5** | **`OrderService` / `FrontendOrderService` symétrie** | Si l'un est touché, revue de l'autre obligatoire. | Hors scope Phase α (à confirmer dans rapport) |
| **I6** | **Frozen zones** | Édition de fichiers frozen requiert gate humain cleared. | Vérifier qu'aucun fichier sous `app/Services/Pricing/`, `app/Services/Order/Lifecycle/`, `app/Services/Fiscal/` n'a été modifié |

Référence canonique : `00-base-foodking/doctrine/project-invariants.mdc`.

---

## 7. ORDRE DE LECTURE OBLIGATOIRE — efficient pour ton temps

Lis dans cet ordre, **sans relire** ce qui est déjà compris :

### Phase 1 — Vision & doctrine (15-20 min)
1. `00-base-foodking/architecture-docs/CV1_CENTRAL_TREE_ARCHITECTURE_2026-05-03.md` — l'arbre central officiel
2. `00-base-foodking/architecture-docs/PROJECT_CONTINUITY_AND_VISION.md` — vision FoodKing
3. `00-base-foodking/architecture-docs/SAAS_VISION.md` — vision SaaS multi-branche
4. `00-base-foodking/doctrine/project-invariants.mdc` — 6 invariants
5. `00-base-foodking/architecture-docs/ADR-COMPOSER-STOCK-2026-04-27.md` — décision composer-stock
6. (Graphiti) `search_memory_facts(query="invariant pricing branch_id dispatch", group_ids=["foodking"])` — puiser la mémoire historique

### Phase 2 — Schéma DB (15 min)
7. `00-base-foodking/db-migrations/` — toutes les migrations dans l'ordre chronologique
8. **Cartographie mentale** : table par table, colonnes critiques, FK, index
9. **Question** : ce schéma porte-t-il l'arbre §3 ? Trous ? Champs polymorphes problématiques ?

### Phase 3 — Backend services (30-40 min)
10. `00-base-foodking/backend-services/Composer/ComposerStepService.php` — CRUD step + dual-write
11. `00-base-foodking/backend-services/Composer/ComposerProfileService.php` — CRUD profile + publish
12. `00-base-foodking/backend-services/Composer/ComposerProfileProjection.php` — projection runtime
13. `00-base-foodking/backend-services/Composer/ComposerDiffService.php` — diff α4 (NEW)
14. `00-base-foodking/backend-services/Composer/ComposerTemplateService.php` — 7 templates
15. `00-base-foodking/backend-services/Menu/MenuProjectionService.php` + `PosMenuProjection.php` + `AvailabilityService.php`
16. `00-base-foodking/backend-services/Stock/StockService.php` + `ChoiceAvailabilityResolver.php`
17. `00-base-foodking/backend-controllers/ItemPhotoController.php` (NEW α5-bis) + `ComposerProfileController.php`
18. `00-base-foodking/backend-requests-resources/` — FormRequests + Resources

### Phase 4 — Frontend Vue admin (20-25 min)
19. `00-base-foodking/frontend-vue-admin/CatalogStudioComponent.vue` — page centrale
20. `00-base-foodking/frontend-vue-admin/composer/ProductComposerEditorComponent.vue` — éditeur wizard
21. `00-base-foodking/frontend-vue-admin/composer/ComposerStepListSidebar.vue` + `StepEditorComponent.vue`
22. `00-base-foodking/frontend-vue-admin/AvailabilityToggleComponent.vue` + `stock/StockRuptureDashboardComponent.vue`

### Phase 5 — Runtime (15 min)
23. `00-base-foodking/frontend-vue-kiosk/KioskPosWizardComponent.vue` — kiosk multi-pages
24. `00-base-foodking/runtime/pos-wizard.js` — POS monolithique (parties clés : `buildStepsFromComposerProfile`, `isComposerStepVisibleOnPos`, `normalizeComposerStep`)

### Phase 6 — Design Claude v2 (30-40 min, le plus important)
25. `01-design-claude-v2/Catalog Studio.html` — canvas global (ouvre dans navigateur si possible, sinon parse mentalement le `design-canvas.jsx`)
26. `01-design-claude-v2/design-canvas.jsx` — 12 artboards initiaux
27. `01-design-claude-v2/studio-iter1.jsx` — 5 artboards Critiques
28. `01-design-claude-v2/studio-iter2.jsx` — 7 artboards Importants
29. `01-design-claude-v2/studio-iter3.jsx` — 7 artboards Polish + README v2
30. `01-design-claude-v2/studio-data.jsx` — fixtures (vérifier fidélité schéma BDD §4.1)
31. `01-design-claude-v2/tokens.css` — vérifier additions seules

### Phase 7 — Plans & rapports historiques (10 min)
32. `02-plans-reports/RUN_CATALOG_STUDIO_FINAL_DESIGN_HANDOFF_2026-05-03.md` — synthèse Phase α
33. `02-plans-reports/CATALOG_STUDIO_AUDIT_AND_REMEDIATION_PLAN_2026-05-03.md` — audit antérieur 18 angles morts
34. `02-plans-reports/SOURCE_FK_TECHNICAL_FEASIBILITY_AUDIT_2026-05-03.md` — pourquoi `source_ref` est polymorphe
35. `02-plans-reports/GATE_CV1-WC-T-WC-SOURCE-FK-01_2026-05-03.md` — gate cleared option 2

---

## 8. QUESTIONS STRATÉGIQUES — l'audit que je veux

Tu dois répondre à **toutes** ces questions dans le rapport final (§14). Pour chaque, **donne une preuve** (chemin fichier:ligne, ou citation studio-iterN.jsx, ou query SQL).

### V — Vision

- **V1** L'expérience livrée par la combinaison (base + design v2) tient-elle vraiment la promesse "plug-and-play, type Shopify pour la restauration" ? Mesure : combien de clics réels pour ajouter "Pizza Margherita" 8.50€ avec un wizard "Choisir base + Garniture + Boisson" ? Compte les actions UI à partir de l'écran S1 (Catalog Studio).
- **V2** L'arbre central §3 est-il reflété sans rupture dans le code et le design ? Y a-t-il une couche manquante ou doublée ?
- **V3** Le couple POS-monolithique / Kiosk-multipages est-il vraiment porté par une **seule source** (`item_wizard_profiles.id`) avec **2 projections** distinctes ? Ou y a-t-il duplication caché entre `runtime/pos-wizard.js` et `KioskPosWizardComponent.vue` ?
- **V4** Stock central : un changement de stock filiale 2 sur l'attribut "Saumon" (id=42) → combien de hops backend pour que le runtime POS filiale 2 voie l'option disparue ? Y a-t-il un goulot ?
- **V5** Multi-langue + RTL : le design Iter 3 ⑮ propose RTL pour l'arabe. La base supporte-t-elle vraiment (i18n FR/EN/DE/BN/AR existent dans `lang/{locale}/all.php`) ? Quels composants Vue ne sont pas RTL-safe ?

### S — Schéma & data model

- **S1** Le schéma DB §4.1 supporte-t-il l'arbre §3 ? Y a-t-il un trou ? (ex : où sont les **branch overrides du wizard** ? `wizard_step_branch_overrides` n'existe pas — mais l'iter 1 ② design en propose une matrice. Est-ce gérable via `item_branch_availability` ou il faut une nouvelle table ?)
- **S2** `item_wizard_steps.source_ref` est polymorphe (mixed semantics : ID, label, token). La FK typée `source_item_attribute_id` ajoutée Phase α suffit-elle pour `source_type='item_attribute'` ? Que faire pour `source_type='extra_group'` et `'addon'` ? Faut-il `source_item_extra_id`, `source_item_addon_id` ?
- **S3** Le `addon_role` (`item_wizard_steps.addon_role`) — quels sont les rôles autorisés ? (lis `ComposerStepService::SOURCE_TYPES` + tests)
- **S4** Le `composition_snapshot` sur `order_items` est immuable post-paiement (NF525). Si l'admin modifie un wizard après un order, le snapshot doit rester intact. La base le garantit-elle ?
- **S5** Performance : combien d'INSERT pour créer un wizard de 5 steps via `ComposerStepService::create` ? Y a-t-il un risque N+1 ou de cascade ?

### B — Backend services

- **B1** Pipeline complet `Service.update() → DB commit → ComposerProfileChanged event → cache invalidation → menu projection refresh → frontend Pusher` : trace-le dans le code. Combien de hops ? Quelle latence cible ?
- **B2** `ComposerProfileChanged` est-il `DispatchableAfterCommit` ? Si non → violation I4.
- **B3** `ComposerDiffService::projectPublishedProfile()` instancie un `Item` synthétique avec `setRawAttributes(['id'])` et relations vides. **Risque de diff faux silencieux** ? Recommandation : forcer `published_steps_snapshot` à la publication.
- **B4** `ComposerDiffService::comparable('position'|'min_select'|'max_select')` cast `(int)` → `null` devient `0`. **Diff masqué** entre `null` et `0` : intentionnel ou bug ?
- **B5** `ComposerDiffService::COMPARED_FIELDS` whitelist 12 champs. Manque-t-il un champ critique de `item_wizard_steps` ? (cross-réf migration `2026_04_27_143110_create_item_wizard_steps_table.php`)
- **B6** `ItemPhotoController::store(Item $item)` — **vérifie I3 branch_id** : un admin filiale 2 peut-il polluer un item filiale 3 ? Lis `AdminController` parent (référencé dans `extends AdminController`) pour voir si un middleware `branch:current` est appliqué globalement.
- **B7** `ItemPhotoController` ordre `clearMediaCollection()` puis `addMediaFromRequest()` : non-atomique. Risque perte image si 2e étape échoue. Recommandation ?
- **B8** `ItemPhotoController` retourne `thumb_url`/`cover_url`/`preview_url` : `Item::registerMediaConversions()` définit-il bien ces 3 noms ? Si non → strings vides silencieuses côté frontend.
- **B9** `StockService` (atomic conditional UPDATE) : la décrément atomique est-elle vraiment atomique en MySQL InnoDB sans transaction explicite ? Lecture/écriture concurrente safe ?
- **B10** `ChoiceAvailabilityResolver` : combine stock + `item_branch_availability` (86) ? Order de précédence ? Si stock=0 mais branch_avail=true, le choix est-il dispo ou non ?

### F — Frontend Vue

- **F1** `CatalogStudioComponent.vue` utilise `<iframe>` pour embarquer `ProductComposerEditorComponent.vue` dans un drawer. **Pourquoi iframe et pas mount Vue direct ?** Risque : double app Vue, double Vuex, double Pusher echo. Justifié ou anti-pattern ?
- **F2** `createProduct()` ajoute `order: this.nextItemOrder` et `tax_id` nullable. Cohérent avec `ItemListComponent.vue::createProduct` (canal historique) ? Sinon → 2 chemins de création divergents → maintenance pourrie.
- **F3** Filtre `searchTerm` via `String.toLowerCase()` : casse-t-il sur l'arabe (RTL) ? Test avec "تاكوس".
- **F4** Drawer composer : iframe navigable au clavier ? Tab piège/focus trap ? Cmd+W ferme-t-il le drawer ou la page entière ?
- **F5** `i18n` : namespace `studio` dans `lang/{fr,en,de,bn,ar}/all.php`. Les 5 locales ont-elles **les mêmes clés** ? Si AR manque des clés → fallback EN, mais design RTL prétend support AR complet.
- **F6** `AvailabilityToggleComponent` : à quoi appelle-t-il ? `PUT /api/admin/items/{item}/availability` ? Optimistic update ou pessimistic ?
- **F7** `StockRuptureDashboardComponent` : combien de queries pour afficher les ruptures de toutes les filiales ? Pagination ? Filtre par branch ?

### R — Runtime

- **R1** `pos-wizard.js` : monolithique vanilla JS. Comment le wiring `composer_profile` arrive-t-il dans ce JS ? Via blade template ? `window.bootData` ?
- **R2** `KioskPosWizardComponent.vue` : multi-pages — la même structure de `composer_profile` qu'utilise `pos-wizard.js` est-elle ré-utilisée ? Ou un payload différent (`kiosk-wizard-step.js`) ?
- **R3** `useCatalogChangeNotifier` (composable Vue 3) : écoute Pusher `CatalogChanged` event. Re-fetch du menu via quel endpoint ? Délai de rafraîchissement ?
- **R4** Le runtime POS doit afficher la dispo en temps réel (rupture stock filiale courante). Quel mécanisme ? Pusher dédié `ItemAvailabilityChanged` ? Polling ?

### D — Design Claude v2

- **D1** `studio-data.jsx` fixtures : utilisent-elles **exactement** les noms de champs `ItemWizardStep` (snake_case backend) ? `step_key`, `source_type`, `source_ref`, `min_select`, `max_select`, `visible_on`, `stockable_choices`, `addon_role`, `position`, `is_active`, `source_item_attribute_id` ? Ou camelCase ?
- **D2** `tokens.css` : `grep -c "^--cv1\|^--brand\|^--ks" tokens.css` doit retourner 0 (additions seules). Vérifie.
- **D3** Iter 1 ④ Diff Modal : structure de données affichée compatible avec sortie `ComposerDiffService::diff()` (`{added: [], removed: [], modified: [{step_key, before, after, changed_fields}]}`) ? Sinon : friction β1.
- **D4** Iter 1 ② Branch Overrides : quel modèle de données sous-jacent ? `item_branch_availability` suffit ? Ou nouvelle table requise ?
- **D5** Iter 2 ⑥ Source Picker : quels endpoints backend appelle-t-il dans la démo ? Cohérent avec `ItemAttributeController` / `ExtraGroupController` existants ?
- **D6** Iter 2 ⑩ Permission Matrix (3 rôles × 9 actions) : les permissions sont-elles **alignées** avec Spatie Permission existant (noms exacts : `items_edit`, `items_delete`, `composer_publish`, etc.) ? Sinon : matrice théorique non implémentable.
- **D7** Iter 3 ⑮ RTL Arabic : la spec dit "POS/Kiosk LTR ciblé" malgré admin RTL. Justifié ? L'admin AR + POS LTR ne crée pas de friction caissière arabophone ?
- **D8** Iter 3 ⑬ Keyboard shortcuts (Cmd+/ cheatsheet × 4 contextes) : collisions avec POS shortcuts existants ? (équipe POS utilise déjà des raccourcis — décision humaine déjà actée)
- **D9** `Catalog Studio.html` canvas : 19 artboards densément organisés. Est-il vraiment **utilisable** par un dev pour intégrer ? Y a-t-il des artboards orphelins (sans mapping Vue) ?

### P — Performance & opérabilité

- **P1** Charge initiale `/admin/items/studio` : combien de queries SQL ? Eager loading suffisant ?
- **P2** Cache catalog projection : invalidation granulaire (par item) ou bulk (par branch) ? Si bulk → risque de cache stampede.
- **P3** Combien de Pusher channels ouverts par utilisateur admin sur la page Studio ? (admin-orders, catalog-changed, item-availability-changed, stock-level-changed)
- **P4** Le diff endpoint α4 — coût pour un profile à 20 steps × 5 champs comparés : O(n) acceptable. Mais combien d'appels concurrents possibles ?

### T — Tests & evidence

- **T1** Vitest 1054/0/2 + PHPUnit 50/0/2 + Playwright 1 PASS — réplicable selon `03-tests-evidence/test-results-summary.txt`. Y a-t-il un trou de coverage E2E sur la création produit ? (oui — pivot vers produit existant déclaré dans handoff §5)
- **T2** Sentinelle `tests/js/catalogStudioRouting.spec.js` — couvre quoi exactement ? Superficielle ou profonde ?
- **T3** Sentinelle a11y axe-core skippe si deps absent — faux test en CI ? Recommandation : `npm i -D @axe-core/playwright` obligatoire.

---

## 9. CE QUE TU DOIS UTILISER LE MCP GRAPHITI POUR

Le serveur MCP Graphiti contient la **mémoire inter-cycles** FoodKing. Group ID obligatoire : `foodking`.

Avant de finaliser ton rapport, exécute **au minimum** ces queries (et d'autres si tu juges utile) :

```
search_memory_facts(query="catalog studio architecture central tree", group_ids=["foodking"])
search_memory_facts(query="branch_id isolation invariant violation", group_ids=["foodking"])
search_memory_facts(query="composer wizard step source_ref polymorphism", group_ids=["foodking"])
search_memory_facts(query="dispatch after commit DispatchableAfterCommit", group_ids=["foodking"])
search_memory_facts(query="POS monolithic wizard kiosk multi-page projection", group_ids=["foodking"])
search_memory_nodes(query="ItemWizardStep source_item_attribute_id", group_ids=["foodking"])
search_memory_nodes(query="frozen zone pricing fiscal NF525", group_ids=["foodking"])
search_memory_facts(query="audit catalog studio claude design", group_ids=["foodking"])
```

Si une query retourne des facts qui **contredisent** ce que je t'écris ici → flag-le. La mémoire Graphiti a souvent priorité sur le brief (elle accumule les décisions historiques signées par les humains).

---

## 10. CE QUE TU NE DOIS PAS FAIRE

- ❌ Ne pas écrire de code Vue / PHP / SQL.
- ❌ Ne pas modifier un seul fichier du package.
- ❌ Ne pas lancer de migration / script / test.
- ❌ Ne pas approuver un gate humain (pricing, schema DDL, frozen, branch_id) — escalade.
- ❌ Ne pas être complaisant. Si tu n'es pas sûr → dis-le. Si tu trouves un risque S2/S3 → dis-le clairement.
- ❌ Ne pas dépasser ton rôle de reviewer pour proposer un re-design design Claude v2 (ça reviendrait à refaire le job de Claude Design — hors scope).

---

## 11. CE QUE TU DOIS FAIRE (résumé exécutif)

1. Lire dans l'ordre §7 (zéro contexte hors package + Graphiti).
2. Répondre à **toutes** les questions §8 (V1-V5, S1-S5, B1-B10, F1-F7, R1-R4, D1-D9, P1-P4, T1-T3) avec preuves.
3. Vérifier les 6 invariants §6.
4. Produire le rapport selon le format §14.
5. Donner un verdict **PASS / REWORK / ESCALATE** justifié.

---

## 12. CRITÈRES DE DÉCISION

### PASS
- Toutes les questions §8 répondues avec preuves convaincantes.
- 6/6 invariants OK.
- Aucun risque S3 (catastrophique).
- ≤ 2 risques S2 (sérieux) avec mitigation proposée.
- Design Claude v2 fidèle au schéma BDD à ≥ 90%.
- Phase β backlog (β1→β5 dans handoff §6) jugé pertinent.

### REWORK
- ≥ 1 risque S2 non mitigé.
- Divergence non documentée entre design et schéma BDD.
- Test count vs claim divergent (ex : tu trouves 3 tests skipped vs 2 annoncés sans justification).
- Plan β nécessite un re-séquençage.

### ESCALATE
- Violation `branch_id` (I3) confirmée → gate humain Data Isolation Review.
- Frozen zone touchée sans gate cleared → gate Frozen Zone.
- Schema migration nouvelle requise → gate Schema Migration.
- Auth logic modifiée → gate Security Review.
- Ambiguïté sur la vision arbre que ne peut trancher l'audit (ex : faut-il `wizard_step_branch_overrides` ou pas ?) → gate Architecture Decision.

---

## 13. ÉCRIRE LE RAPPORT — destination

**Si tu as accès filesystem (Claude extension / Claude Code terminal)** :
- Écris dans : `reports/audit/ULTRA_REVIEW_GLOBAL_CATALOG_TREE_2026-05-03.md` (chemin absolu : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit/ULTRA_REVIEW_GLOBAL_CATALOG_TREE_2026-05-03.md`)

**Si tu es sur Claude.ai web (pas de filesystem)** :
- Rends le rapport entier en sortie de chat. L'humain le copiera-collera dans le chemin ci-dessus.

---

## 14. FORMAT DE RAPPORT — exact, à respecter

```markdown
# Ultra-Review GLOBALE — Catalog Tree FoodKing — 2026-05-03

| Champ | Valeur |
|---|---|
| Date | 2026-05-03 |
| Reviewer | Claude (modèle/version : ___) |
| Effort raisonnement | high / max |
| MCP Graphiti utilisé | OUI / NON (avec query count) |
| Verdict global | PASS / REWORK / ESCALATE |
| Score qualité (0-100) | ___ |
| Score fidélité design ↔ base (0-100) | ___ |
| Score vision plug-and-play (0-100) | ___ |

## 0. TL;DR (3 lignes max)
___

## 1. Vision (V1-V5)
### V1 Plug-and-play : combien de clics réels pour ajouter "Pizza Margherita 8.50€ + wizard 3 étapes" ?
- Comptage : ___ clics
- Verdict : ___
- Évidence : ___

### V2 Arbre central reflété ?
___

(idem V3, V4, V5)

## 2. Schéma & Data Model (S1-S5)
___

## 3. Backend Services (B1-B10)
___

## 4. Frontend Vue (F1-F7)
___

## 5. Runtime POS/Kiosk (R1-R4)
___

## 6. Design Claude v2 (D1-D9)
___

## 7. Performance & Opérabilité (P1-P4)
___

## 8. Tests & Evidence (T1-T3)
___

## 9. Invariants FoodKing — table de conformité

| Invariant | Statut | Évidence (chemin:ligne) | Risque résiduel |
|---|---|---|---|
| I1 Pricing SSOT | OK / VIOLATION | ___ | ___ |
| I2 OrderStatus | OK / N/A | ___ | ___ |
| I3 branch_id | **OK / VIOLATION** | ___ | ___ |
| I4 Dispatch after commit | OK / VIOLATION | ___ | ___ |
| I5 OrderService symmetry | OK / N/A | ___ | ___ |
| I6 Frozen zones | OK / VIOLATION | ___ | ___ |

## 10. Matrice de risques résiduels

| # | Risque | Sévérité (S0-S3) | Probabilité (P0-P3) | Composant | Mitigation proposée |
|---|---|---|---|---|---|
| R1 | ___ | S2 | P1 | ItemPhotoController | ___ |
| ... | | | | | |

## 11. Bilan design ↔ base ↔ vision (synthèse cross-couche)

- ✅ Ce qui aligne parfaitement : ___
- ⚠️ Ce qui a une friction acceptable : ___
- ❌ Ce qui rompt l'alignement : ___

## 12. Recommandations Phase β (amender ou confirmer le backlog actuel)

(comparer au plan β1-β5 dans `02-plans-reports/RUN_CATALOG_STUDIO_FINAL_DESIGN_HANDOFF_2026-05-03.md` §6, et donner un re-séquencement si besoin)

## 13. Verdict final

VERDICT : **PASS / REWORK / ESCALATE**

Justification (5-10 lignes max) :
___

## 14. Si REWORK : plan correction ordonné

| # | Fichier | Correction | Priorité (P0-P2) | Effort estimé |
|---|---|---|---|---|
| 1 | ___ | ___ | P0 | S/M/L |
| ... | | | | |

## 15. Si ESCALATE : gate humain à ouvrir

- Trigger : ___
- Type de gate : Data Isolation / Frozen Zone / Schema Migration / Security / Architecture Decision
- Décision requise (1 question précise) : ___
- Options : 1) ___ 2) ___ 3) Annuler

## 16. Mémoire Graphiti — facts ajoutés (proposés, à valider humain)

(liste 3-5 facts à ajouter à `add_memory(group_id="foodking")` après ce cycle)

- Fact 1 : ___
- Fact 2 : ___
- ...
```

---

## 15. RAPPELS FINAUX

- **Substance avant brièveté.** Ne pas raccourcir un point critique pour économiser des tokens.
- **Preuves obligatoires.** Chaque verdict = chemin fichier:ligne ou citation explicite.
- **Cross-réf systématique** entre design Claude v2 et base technique. Ne traite pas l'un sans l'autre.
- **MCP Graphiti = checkpoint obligatoire** avant verdict final. Au moins 5 queries.
- **Pas de complaisance.** Si tu trouves un trou, dis-le.
- **Pas de scope creep.** Ne propose pas de re-design ; propose des fixes.

---

**FIN DU BRIEF.** Tu peux maintenant commencer l'audit. Bon courage. Le projet FoodKing dépend de la qualité de ton verdict.
