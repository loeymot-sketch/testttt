# FRONTIERES — voie GROK vs voie CLAUDE

Règle dure : **aucun fichier n'est édité par les deux voies**.
Si un chemin n'est ni GROK ni CLAUDE ci-dessous, il est **SHARED-until-assigned** :
ne pas l'éditer en parallèle. Déclarer le doute dans `reports/grok/JOURNAL.md`.

Les blocs fencés `grok-owned` / `never-touch` sont la SSOT machine
(`tests/Unit/Grok/MissionGrokContractTest.php`).

---

## Grok owned (globs)

```grok-owned
resources/js/components/admin/items/addon/**
resources/js/components/admin/items/extra/**
resources/js/components/admin/items/variation/**
resources/js/components/admin/items/composer/**
resources/js/components/admin/settings/ItemAttribute/**
resources/js/components/admin/settings/ItemCategory/**
resources/js/components/admin/settings/Role/**
resources/js/components/admin/settings/Page/**
app/Http/Requests/ItemAddonRequest.php
app/Http/Requests/ItemExtraRequest.php
app/Http/Requests/ItemVariationRequest.php
app/Services/ItemAddonService.php
app/Services/ItemExtraService.php
app/Services/ItemVariationService.php
app/Services/ItemAttributeService.php
app/Services/ItemCategoryService.php
app/Services/ItemCategoryHierarchyService.php
app/Services/Composer/**
app/Http/Controllers/Admin/ItemAddonController.php
app/Http/Controllers/Admin/ItemExtraController.php
app/Http/Controllers/Admin/ItemVariationController.php
app/Http/Controllers/Admin/ItemAttributeController.php
app/Http/Controllers/Admin/ItemCategoryController.php
app/Http/Controllers/Admin/ComposerProfileController.php
app/Http/Controllers/Admin/ComposerStepController.php
app/Http/Controllers/Admin/RoleController.php
app/Http/Controllers/Admin/PermissionController.php
app/Http/Controllers/Admin/PageController.php
app/Http/Resources/ItemCategoryResource.php
app/Http/Requests/RoleRequest.php
app/Services/RoleService.php
database/seeders/RoleTableSeeder.php
database/seeders/PermissionTableSeeder.php
database/seeders/RolePermissionTableSeeder.php
docs/grok/**
reports/grok/**
tests/Unit/Grok/**
tests/Feature/Grok/**
```

Le dossier `app/Http/Controllers/Admin/Settings/` **n'existe pas** dans ce dépôt :
les contrôleurs de réglages vivent à plat sous `Admin/`. Grok ne les prend pas
tous d'un coup (collision avec BORNE / NF525). Cluster réglages **autorisé ensuite
un écran à la fois**, déclaré dans le JOURNAL, hors `PrinterController`,
`PaymentTerminalController`, `Fiscal/*`.

Vue `settings/**` : Grok possède les sous-dossiers listés ci-dessus. Les autres
sous-dossiers (`Company`, `Site`, `Tax`, …) sont **claim-on-journal**, jamais
`Printers/`, `PaymentTerminals/`, `Fiscal/`.

---

## Never touch (globs)

```never-touch
resources/js/components/frontend/kiosk/KioskWizardComponent.vue
resources/js/components/frontend/kiosk/KioskAppComponent.vue
resources/js/components/frontend/kiosk/KioskUpsellComponent.vue
resources/js/components/admin/pos/PaymentComponent.vue
resources/js/components/admin/pos/v5/PosV5TrancheRow.vue
public/js/pos-wizard.js
public/css/pos-wizard.css
resources/views/admin-pos-v4.blade.php
app/Services/Fiscal/FiscalSequenceService.php
app/Services/Fiscal/ZReportService.php
app/Services/Fiscal/AuditLogService.php
app/Models/Scopes/BranchScope.php
app/Http/Middleware/IdempotencyKeyMiddleware.php
app/Services/Pricing/PricingService.php
app/Domain/Order/OrderStateMachine.php
CLAUDE.md
Agents.md
AGENTS.md
resources/js/components/admin/settings/Printers/**
resources/js/components/admin/settings/PaymentTerminals/**
resources/js/components/admin/settings/Fiscal/**
app/Http/Controllers/Admin/PrinterController.php
app/Http/Controllers/Admin/PaymentTerminalController.php
app/Http/Controllers/Admin/Fiscal/**
app/Http/Controllers/Admin/ItemController.php
resources/js/components/admin/items/ItemComponent.vue
resources/js/components/admin/items/ItemCreateComponent.vue
resources/js/components/admin/items/ItemListComponent.vue
resources/js/components/admin/items/CatalogStudioComponent.vue
```

Claude (voie parallèle) : POS, borne, KDS/OSS, fiche produit (`ItemController` +
list/create/studio), fiscal, imprimantes, TPE. Grok n'y entre pas.

---

## i18n — bloc réservé Grok

Dans `resources/js/languages/{fr,en}.json` Grok ne crée / n'édite que des clés
dont le chemin JSON commence par :

```i18n-prefix
itemAttribute
itemCategory
itemAddon
itemExtra
itemVariation
composer
role
```

Toute autre clé = voie Claude ou SHARED. `lang/fr/all.php` : seulement
`item_match` et messages déjà liés aux services Grok, pas de nouveau fichier.

---

## Registries partagés (pas de parallèle)

`routes/api.php`, `resources/js/router/index.js`, `resources/js/store/index.js`,
`webpack.mix.js` : Grok peut **ajouter une ligne** dans SON cluster (variation /
catégorie / rôle) en le déclarant au JOURNAL. Jamais de réécriture du fichier.
