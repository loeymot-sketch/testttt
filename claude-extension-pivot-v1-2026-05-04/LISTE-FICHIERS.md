# Fichiers à attacher à Claude (extension Anthropic) — Pivot V1 FoodKing

Ce dossier contient le message principal (`MESSAGE-A-COLLER.md`) et la liste exacte des fichiers du repo à attacher / copier-coller.

## Mode d'emploi

1. Ouvre Claude (extension Anthropic) avec **thinking étendu activé** (modèle Opus 4.7 ou équivalent reasoning high).
2. Colle le contenu de **`MESSAGE-A-COLLER.md`** comme message principal.
3. Attache les fichiers ci-dessous **en pièce jointe** (drag & drop dans la conversation Claude).
4. Si la limite d'upload est atteinte, priorise par tier ↓.

---

## Tier 1 — IMPÉRATIF (à attacher en priorité)

Ces fichiers sont indispensables pour comprendre l'état + les contraintes :

```
AGENTS.md
CLAUDE.md
.cursor/rules/project-invariants.mdc
.cursor/rules/global.mdc
.cursor/routing.md

app/Models/Item.php
app/Models/ItemCategory.php
app/Models/ItemAttribute.php
app/Models/ItemExtra.php
app/Models/ItemAddon.php
app/Models/ItemWizardProfile.php
app/Models/ItemWizardStep.php
app/Models/StockLevel.php
app/Models/StockMovement.php
app/Models/ItemBranchAvailability.php

app/Services/Stock/StockService.php
app/Services/Stock/ChoiceAvailabilityResolver.php
app/Services/Composer/ComposerProfileService.php
app/Services/Composer/ComposerStepService.php
app/Services/Composer/ComposerTemplateService.php

resources/js/components/admin/items/CatalogStudioComponent.vue
resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue
resources/js/components/admin/stock/StockRuptureDashboardComponent.vue
resources/js/config/v1-hidden-modules.js

database/migrations/2022_11_17_110428_create_item_categories_table.php
database/migrations/2022_11_17_110514_create_items_table.php
database/migrations/2026_03_12_080617_add_wizard_config_to_item_categories.php
database/migrations/2026_04_27_143100_create_item_wizard_profiles_table.php
database/migrations/2026_04_27_143110_create_item_wizard_steps_table.php
database/migrations/2026_04_27_143120_create_stock_levels_table.php
database/migrations/2026_04_27_143130_create_stock_movements_table.php
```

## Tier 2 — TRÈS UTILE (si la limite le permet)

```
app/Services/Composer/ComposerProfileProjection.php
app/Services/Composer/ComposerDiffService.php
app/Services/Menu/PosMenuProjection.php
app/Services/Menu/MenuProjectionService.php
app/Services/Menu/AvailabilityService.php
app/Http/Controllers/Admin/ComposerProfileController.php
app/Http/Controllers/Admin/PosCategoryController.php

app/Models/ItemVariation.php
app/Models/ItemWizardStepVersion.php

resources/js/components/admin/items/ItemPreviewComponent.vue
resources/js/components/admin/items/AvailabilityToggleComponent.vue
resources/js/components/admin/items/composer/ComposerStepListSidebar.vue
resources/js/components/admin/items/composer/ComposerStepFormPanel.vue
resources/js/components/admin/items/composer/ComposerTemplatePickerModal.vue
resources/js/components/layouts/backend/BackendMenuComponent.vue

database/migrations/2022_11_17_110541_create_item_attributes_table.php
database/migrations/2022_11_17_110650_create_item_extras_table.php
database/migrations/2022_11_17_120627_create_item_addons_table.php
database/migrations/2026_04_15_230100_create_item_branch_availability_table.php
database/migrations/2026_04_22_000010_add_min_max_repeat_to_item_attributes.php
database/migrations/2026_05_04_000010_create_item_wizard_step_versions_table.php

plans/PLAN_CV1-V2-CATALOG-VISION-CLEANUP-001_2026-05-04.md
plans/PLAN_CV1-V2-CATALOG-WIZARD-CORE-001_2026-05-04.md
reports/execution/RUN_CV1-V2-CATALOG-WIZARD-CORE-001_2026-05-04.md
```

## Tier 3 — CONTEXTE PROJET (si large fenêtre)

```
docs/PROJECT_CONTINUITY_AND_VISION.md
docs/ARCHITECTURE.md
docs/BUSINESS_RULES.md
docs/SAAS_VISION.md
docs/orchestration/MEMORY_MATRIX.md
docs/orchestration/MULTI_AGENT_LOOP_2026-05-02.md

audit-claude-ultra-review-2026-05-03/00-base-foodking/architecture-docs/CV1_CENTRAL_TREE_ARCHITECTURE_2026-05-03.md

resources/js/components/admin/items/composer/StepEditorComponent.vue
resources/js/components/admin/items/composer/StepPreviewComponent.vue
resources/js/components/admin/items/composer/ComposerPublishDiffModal.vue
resources/js/components/admin/items/composer/ComposerVersionConflictBanner.vue

resources/js/router/modules/itemRoutes.js
resources/js/router/modules/stockRoutes.js
resources/js/router/modules/settingRoutes.js
```

---

## Si Claude pose des questions

C'est attendu et **bon signe** : signifie qu'il a vraiment lu et trouvé des ambiguïtés. Réponds-lui précisément, **ne le laisse pas inventer** des hypothèses.

Questions probables :
- « Voie A ou Voie B pour le wizard catégorie ? » → ta préférence métier (laisse-le recommander mais tranche).
- « Option I.1 / I.2 / I.3 pour les ingrédients ? » → idem.
- « Est-ce que multi-filiale est V1 ou V2 ? » → V2 probablement (1 filiale aujourd'hui).
- « Le bouton Demo affiche le wizard per-item existant sans changement, ou avec une étiquette `BETA` ? » → à toi.

---

## Après réception du livrable Claude

Tu auras 3 documents :
1. AUDIT
2. PLAN
3. ULTRA REVIEW

**Ne lance PAS l'exécution immédiatement**. Reviens d'abord vers moi (Cursor Claude) pour :
- Que je relise et challenge le plan Claude.
- Qu'on tranche les "Top 3 questions humaines".
- Qu'on ajuste / consolide en un plan exécutable par sub-agents.
- Qu'on démarre **cycle par cycle** dans l'ordre proposé.

C'est la même discipline que pour le précédent ultra-review (`audit-claude-ultra-review-2026-05-03/`).
