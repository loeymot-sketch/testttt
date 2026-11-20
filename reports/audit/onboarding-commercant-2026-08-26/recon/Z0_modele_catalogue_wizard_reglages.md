# Z0 — MODÈLE DE DONNÉES CATALOGUE / WIZARD / RÉGLAGES / UTILISATEURS — exploration code vérifiée (2026-08-26)

> Produite par un agent d'exploration lecture seule (104 lectures directes, Bash indisponible : aucun grep, donc les
> « introuvable » signifient « non localisé par lecture », à re-vérifier par grep). Arbre principal
> `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt` (abrégé `<R>`), HEAD `43b120c7d`.

# A) MODÈLE CATALOGUE

## A.1 Cœur
| Entité | Modèle | Table / migration | Colonnes clés | Relations |
|---|---|---|---|---|
| **Item** | `app/Models/Item.php` | `items` — `database/migrations/2022_11_17_110514_create_items_table.php:19-38` | `item_category_id`, `tax_id` nullable, `name`, `slug`, `caution`, `description`, `price decimal(19,6)`, `status`, `item_type`, `order`, `is_featured`, créateur/éditeur polymorphes, softDeletes | `variations()` `:140` (ACTIVE, eager `itemAttribute`), `extras()` `:145`, `addons()` `:150`, `category()` `:155`, `tax()` `:160`, `orders()` `:165`, `offer()` `:170`, `allergens()` pivot `item_allergen` (`is_trace`) `:181` |
| Item — colonnes additives | | `is_upsell` (`2026_03_25_002927`) ; `is_chef_pick` (`2026_04_18_120007`) ; `channels json`, `allergen_flags json`, `kiosk_emoji` (`2026_04_16_200000:26,29,32`) ; `is_new,is_available,is_spicy,is_vegetarian,is_pork_free,is_halal,is_gluten_free,chef_pick_order` (`2026_04_18_130001:34-56`) ; `barcode` index (`2026_04_20_160000`) ; **`kds_station enum('bar','cuisine_chaude','cuisine_froide','none') default 'none'`** (`2026_04_20_230000_add_kds_station_to_items.php:16-22`) | fillable `Item.php:20-47`, casts `:49-77` | |
| Item — image | Spatie MediaLibrary, collection `item` | conversions `thumb 168×180`, `cover 390×270`, `preview w600` (`Item.php:133-138`) ; repli par **slug** via `config/menu_images.php` (`getThumbAttribute()` `:88-113`, `getCoverAttribute()` `:115`) | |
| **ItemCategory** | `app/Models/ItemCategory.php` | `item_categories` — `2022_11_17_110428:16-27` (⚠️ `deleted_at` absent de la migration de base bien que `SoftDeletes` déclaré `:18` — migration d'ajout non localisée) | `+sort` (`2024_02_29_095727`) ; `+wizard_template varchar(20) default 'simple'`, `has_menu`, `default_menu_kiosk`, `sauce_included_menu` (`2026_03_12_080617:21-28`) ; `+kiosk_upsell_include`, `kiosk_upsell_skip_after_cart` (`2026_03_27_120000`) ; `+channels`, `kiosk_sort`, `pos_sort`, `kiosk_label` (`2026_04_16_200000:37-47`) ; `+parent_id` (`2026_04_18_120001:29`) ; **`+wizard_profile_id` FK nullOnDelete** (`2026_05_05_000010:12-18`) | `items()` `:152`, `wizardProfile()` `:157`, `parent()/children()` `:172/:177`, profondeur max 2 `:186`, média `item-category` `:146-150` |
| **Tax** | `app/Models/Tax.php` | `taxes` — `2022_11_17_110459:16-28` : `name`, `code`, `tax_rate decimal(13,6)`, `type` (pourcentage/fixe), `status` | `items()` `:23` |
| **ItemAttribute** | `app/Models/ItemAttribute.php` | `item_attributes` (`2022_11_17_110541:16-25`) ; `+min_select,max_select,allow_repeat` (`2026_04_22_000010:11-17`) ; `+is_available,unavailable_reason` (`2026_05_05_000030`) | aucune relation déclarée |
| **ItemVariation** | `app/Models/ItemVariation.php` | `item_variations` (`2022_11_17_110621:16-30`) : `item_id`, `item_attribute_id`, `name`, `price`, `caution`, `status`, softDeletes ; `+visible_on json` (`2026_03_26_090651`) | `item()` `:51`, `itemAttribute()` `:56`, média `option` `:175-190`, `isVisibleOn()` `:46` |
| **ItemExtra** | `app/Models/ItemExtra.php` | `item_extras` (`2022_11_17_110650:16-28`) ; `+visible_on`, `+group_label` (`2026_03_26_090640:12-18`) ; `+is_available`, `+unavailable_reason` (`2026_05_05_000040`) | `item()` `:100`, média `option` `:115-119` |
| **ItemAddon** | `app/Models/ItemAddon.php` | `item_addons` (`2022_11_17_120627:16-27`) : `item_id`, `addon_item_id`, `addon_item_variation json` ; `+role` (`2026_04_27_143140:12`) — `ROLES = drink,side,dessert,menu_component,upsell` `:13`, contrôlé `:25` | `item()`, `addonItem()` `:34,:38` |
| **ItemBranchAvailability** | `app/Models/ItemBranchAvailability.php` | `item_branch_availability` (`2026_04_15_230100:15-28`) : `is_available`, `unavailable_reason(32)`, `unavailable_since`, `max_daily_qty`, `daily_consumed_qty`, `daily_reset_at`, unique(item,branch) ; `+manual_unavailable_since` (`2026_07_23_100000`) | `BranchScope` `:45-48` |
| **StockLevel** | `app/Models/StockLevel.php` | `stock_levels` (`2026_04_27_143120:12-30`) : polymorphe `stockable_*`, `branch_id`, `on_hand`, `reserved`, `threshold_low`, CHECKs `:26-30` ; `+manual_unavailable_reason(32)`, `manual_unavailable_since` (`2026_05_08_150000:34-36`) | `BranchScope` `:25` ; `movements()` `:82` ; raisons `:35-41` |
| **Ingredient** | **aucun modèle** : façade virtuelle sur 3 tables | `IngredientService::TYPES = attribute,extra,addon`, id global `"{type}:{id}"` (`app/Services/Ingredients/IngredientService.php:14-36`), dédoublonnage par nom logique `:53-77` (535 extras → ~43 noms) | disponibilité via `IngredientAvailabilityService` ; addons en lecture seule (`IngredientController.php:72`) |

Validation article : `app/Http/Requests/ItemRequest.php:42-89` (`channels.* in kiosk,pos,web` `:69` ; `kds_station max:32` `:79` ; `barcode unique` `:78` ;
variations/extras postés en **blobs JSON**, validés dans `withValidator` `:100-178`).

## A.2 WIZARD (le point crucial)
| Table | Migration | Colonnes |
|---|---|---|
| **`item_wizard_profiles`** | `2026_04_27_143100_create_item_wizard_profiles_table.php:11-23` | `item_id` FK cascade, `template enum('simple','sandwich','tacos','assiette','snacking','menu','custom')`, `version`, `is_published`, `published_at`, **`branch_id_scope`** FK nullOnDelete |
| ↳ propriétaire polymorphe | `2026_05_05_000020_make_item_wizard_profiles_polymorphic_owner.php:12-20,106-117` | `+item_category_id` ; `item_id` nullable ; **CHECK XOR** `(item_id IS NOT NULL) <> (item_category_id IS NOT NULL)` (sauté sur sqlite `:108`) |
| **`item_wizard_steps`** | `2026_04_27_143110_create_item_wizard_steps_table.php:12-36` | `profile_id` FK, **`step_key`** (unique par profil `:30`), `label`, `source_type enum('item_attribute','extra_group','addon','fixed')`, `source_ref`, **`min_select` default 0**, **`max_select` default 1**, `allow_repeat`, `visible_on json`, `stockable_choices`, **`position`**, `is_active`, `addon_role enum(drink,side,dessert,menu_component,upsell)` ; CHECK `min_select <= max_select`, `position >= 0` `:34-35` |
| ↳ | `2026_05_03_200500:14-18` | `+source_item_attribute_id` FK nullOnDelete (+ backfill `:24-67`) |
| **`item_wizard_step_versions`** | `2026_05_04_000010:10-26` | `profile_id`, `version`, `snapshot json`, `published_at`, `published_by_id` ; unique(profile,version) |

| Colonne demandée | Verdict |
|---|---|
| `min_select`, `max_select` | ✅ (`item_wizard_steps`, garde modèle `app/Models/ItemWizardStep.php:46-50`, garde requête `ComposerStepRequest.php:36-50`) |
| `is_required` | ❌ dérivé : `min_select > 0` (`app/Services/Composer/ComposerProfileService.php:215`) |
| `free_quantity` / `included_count` / `price_delta` / `is_paid` | ❌ **inexistants**. Le composer est **sans prix** : `'price' => ['prohibited']` (`ComposerProfileRequest.php:20,36`, `ComposerStepRequest.php:32`). Les prix viennent de `ItemVariation.price` / `ItemExtra.price` / prix de l'article addon. L'« inclus » vit dans `config/menu.php` (`viandes => N`, `has_sauce`, `has_crudites` par article, ex. `:167-199`) et `config/kiosk.php` (`frites_included_category_ids` `:159-162`, `menu_pricing.fries_ratio/drink_ratio = 0.76` `:331-336`) |
| `step_key` | ✅ unique par profil |
| tri | ✅ `position` |
| propriété profil | étapes → `profile_id` ; profil → `item_id` XOR `item_category_id` ; résolution : **profil de catégorie gagne, profil d'article en repli** (`ComposerProfileService::resolveForItem()` `:104-120`) ; créer un profil de catégorie écrit `item_categories.wizard_profile_id` (`:94`) |
| portée globale / filiale | `app/Models/Scopes/WizardProfileBranchScope.php` : Admin (`branch_id=0`) sans filtre `:47` ; staff → `branch_id_scope IS NULL OR = sa filiale` `:51-55` ; appliqué `ItemWizardProfile.php:22-25` ; test `tests/Feature/Multitenant/WizardProfileBranchScopeTest.php` |

**Choix d'étape** : aucun modèle/table de choix. Projetés à l'exécution depuis variations/extras/addons de l'article —
`app/Services/Composer/ComposerProfileProjection.php:82-177` : `item_attribute` → variations ACTIVE + `isVisibleOn(surface)` + attribut apparié par id OU nom `:179-191` ;
`extra_group` → extras par `group_label == source_ref` (vide = tous) `:112-134` ; `addon` → addons par `addon_role` `:138-140` ; **`fixed` non géré** (retourne `[]` `:176`)
et refusé par les FormRequests (valeur d'enum morte). Disponibilité par choix : `ChoiceAvailabilityResolver::snapshotForItem()` `:33`.

## A.3 Composer / CatalogStudio / démo
| Chose | Ce que c'est | Où |
|---|---|---|
| **CatalogStudio** | page V1 du catalogue (barre de catégories + grille + ajout + entrée wizard par catégorie) ; atterrissage de `/admin/items` | `admin/items/CatalogStudioComponent.vue` (`:4-24`, `:39-51`) ; route `itemRoutes.js:41-50` ; ligne de menu injectée `BackendMenuComponent.vue:94-99,120-127` |
| **CatalogHub** | onglets CatalogStudio + StockRupture sur `/admin/catalog-hub` | `itemRoutes.js:94-107` ; `BackendMenuComponent.vue:109` |
| **`composer_profile`** | nom API d'un `ItemWizardProfile` ; exposé `item.composer_profile.steps` ; consommé par le wizard POS si `catalog_v15.pos_wizard_composer_aware.enabled` | `config/catalog_v15.php:99-105`, conflit de version `:144-154` |
| **ComposerProfileProjection** | read-model profil+article → arbre étapes/choix par surface ; appelé par `NormalItemResource`, `MenuProjectionService`, `KioskMenuService`, `PricingService` | `app/Services/Composer/ComposerProfileProjection.php` |
| **WizardAdvancedLauncher** | page démo V2 derrière drapeau ; garde `requireWizardPerItemDemo` redirige vers Studio si off | `admin/demo/WizardAdvancedLauncherComponent.vue` ; `itemRoutes.js:13-25,124-137` ; `settings/MenuComponent.vue:131-139,190-193` ; **drapeau** `catalog_v15.features.wizard_per_item_demo = env('FEATURE_WIZARD_PER_ITEM_DEMO', false)` (`config/catalog_v15.php:173-177`) → `window.foodkingConfig.features.wizard_per_item_demo` |

**Conséquence pour un commerçant « de zéro »** : l'édition du wizard **par article** est OFF par défaut ; seules les routes composer **par catégorie** sont sans drapeau (`routes/api.php:925-927`).

# B) UI ADMIN
## B.1 `resources/js/components/admin/items/**` (liste complète)
`ItemComponent.vue` (shell) · `ItemListComponent.vue` (liste + drawer `?create=1`, `itemRoutes.js:62-80`) · `ItemCreateComponent.vue` (drawer création/édition `:14-90`) ·
`ItemShowComponent.vue` (**7 onglets** `:12-48`) · `ItemPreviewComponent.vue` · `ItemPhotoUpload.vue` (`POST /api/admin/items/{item}/photo`, `api.php:900`) ·
`ItemUploadComponent.vue` (import Excel) · `CatalogStudioComponent.vue` · `CatalogHubComponent.vue` · `CatalogConceptHelpComponent.vue` (explication des concepts) ·
`AvailabilityToggleComponent.vue` · `ProductComposerSummaryComponent.vue` · `ComposerProfileWarningBadge.vue` (`CatalogWarningService`, `ItemShowComponent.vue:5-10`) ·
`variation/ItemVariation{List,Create}Component.vue` · `extra/ItemExtra{List,Create}Component.vue` · `addon/ItemAddon{List,Create}Component.vue` ·
`wizard/ProductCreateWizardComponent.vue` (**docblock « Status : SKELETON — implementation TODO Codex »** `:1-19`) ·
`composer/ProductComposerEditorComponent.vue` (éditeur de profil, sélecteur de portée `:40-50`) · `composer/ComposerStepListSidebar.vue` · `composer/ComposerStepFormPanel.vue` ·
`composer/StepEditorComponent.vue` · `composer/StepPreviewComponent.vue` · `composer/ComposerTemplatePickerModal.vue` · `composer/ComposerPublishDiffModal.vue` ·
`composer/ComposerVersionConflictBanner.vue` (409 `ProductComposerEditorComponent.vue:3-8`).
Catégories/attributs/taxes sous `settings/` : `settings/ItemCategory/{ItemCateogryListComponent,ItemCategoryComponent,ItemCategoryCreateComponent,ItemCategoryShowComponent,CategoryUploadComponent}.vue`,
`settings/ItemAttribute/*`, `settings/Tax/*`. Pas de page « profils de wizard » hors `ProductComposerEditorComponent` + démo.

## B.2 API composer — `routes/api.php:915-939` (groupe `/api/admin` : `installed, apiKey, auth:sanctum, block_kiosk_token_admin, localization, throttle:admin-mutation` `:378`)
| Méthode + URI | Contrôleur::action | Gates | FormRequest |
|---|---|---|---|
| `GET /admin/composer/items/{item}/profile` | `ComposerProfileController::show` `:26` | `permission:catalog.compose` `:916` + `wizard.per_item_demo` `:917` | — |
| `POST /admin/composer/items/{item}/profile` | `::store` `:43` | idem | `ComposerProfileRequest` |
| `POST /admin/composer/items/{item}/apply-template` | `::applyTemplate` `:111` | idem | inline `:113-116` |
| `GET /admin/composer/items/{item}/available-sources` | `::availableSources` `:159` | idem | — |
| `GET /admin/composer/categories/{category}/profile` | `::showForCategory` `:50` | `catalog.compose` | — |
| `POST /admin/composer/categories/{category}/profile` | `::storeForCategory` `:67` | `catalog.compose` | `ComposerProfileRequest` |
| `POST /admin/composer/categories/{category}/apply-template` | `::applyTemplateToCategory` `:131` | `catalog.compose` | inline ; 422 si catégorie vide `:143` |
| `PUT/PATCH /admin/composer/profiles/{profile}` | `::update` `:74` | `catalog.compose` + `wizard.per_item_profile_guard` `:928` | `ComposerProfileRequest` (+`version` `:39-41`) |
| `GET …/profiles/{profile}/diff` · `POST …/unpublish` | `::diff` `:96` · `::unpublish` `:89` | idem | — |
| `POST …/profiles/{profile}/steps` · `PUT/PATCH …/steps/{step}` · `DELETE …/steps/{step}` | `ComposerStepController::store/update/destroy` `:19/:26/:33` | idem | `ComposerStepRequest` |
| `POST …/profiles/{profile}/publish` | `ComposerProfileController::publish` `:82` | **`permission:catalog.publish`** `:937-938` | `ComposerProfileRequest` (version `:75-77`) |
Authz de portée : `AdminController::authorizeWritableBranchScope()` `:29-40`. Invariants de publication : `ComposerProfileService::assertPublishable()` `:175-222`.

## B.3 CRUD article / catégorie / taxe / attribut — `routes/api.php`
`item.` `:858-899` (index, `lookup-barcode/{code}`, show, store, `{item}/duplicate`, update, destroy, `change-image/{item}`, export, download-sample, import/file, details ; variations `:873-878` ; extras `:880-884` ; photos d'option `:891-894` ; addons `:896-898`) · `POST /items/{item}/photo` `:900` ·
item-category `:513-523` · item-attribute `:525-531` · tax `:505-511` · currency `:497-503` · branch `:541-549` · ingrédients `:902-913` (`permission:ingredients_manage`) ·
disponibilité `:357-369` (`menu.availability.toggle/.extra/.variation`, `throttle:menu-availability`) + `:388` `max-daily-qty`.

# C) RÉGLAGES
## C.1 `app/Services/Pilotage/InterrupteurService.php` — `CATALOGUE` `:43-90` = **6 booléens**
| Clé | `cle` (écrite dans `Settings::group('pilotage')`) | Libellé | Défaut fichier |
|---|---|---|---|
| `split_payment` | `split_payment.enabled` `:45` | Paiement en plusieurs fois | `config/split_payment.php` |
| `wheel` | `wheel.enabled` `:51` | Roue promotionnelle | `config/wheel.php` |
| `remise_manuelle` | `pos.manual_discount_enabled` `:67` | Remise manuelle en caisse | **false** (`config/pos.php:196-200`) |
| `fidelite` | `pos.loyalty_enabled` `:73` | Programme fidélité | **true** (`config/pos.php:233-237`) |
| `kiosk_promo` | `kiosk.promo_enabled` `:79` | Codes promo sur la borne | **false** (`config/kiosk.php:70`) |
| `impression_ticket_client_auto` | `printing.auto_print_client_receipt` `:85` | Impression auto du ticket client | false |
Mécanique : `appliquerAuDemarrage()` `:153-165` ; `regler()` `:114-126`. Exclu volontairement : `idempotency.enabled` (`:27-33`). Commentaire `:56-65` : catalogue **booléen seulement** —
numérique/texte/horaire (tolérance caisse, barème livraison, mention légale, seuil stock, heures de service) **hors périmètre tant qu'un mécanisme de réglages typés n'existe pas**.
API `GET/PUT /api/admin/observability/interrupteurs[/{nom}]` (`routes/api.php:1669-1670`) ; contrôleur `Admin/Pilotage/InterrupteurController.php` (écriture Admin/Tenant Admin `:38`, `Log::info` `:49-55`) ;
UI `admin/observability/SystemHealthComponent.vue`, route `admin.observability.system` (`observabilityRoutes.js:26-35`, perm `dashboard`).

## C.2 Menu Réglages — 31 entrées, 22 cachées (voir `Z0_carte_dashboard.md §4`)

## C.3 Clés de config qu'un commerçant devrait pouvoir régler (défauts)
`pos.manual_discount_enabled` false (`config/pos.php:196-200`) · `pos.loyalty_enabled` true (`:233-237`) · `pos.coupon_codes_enabled` false (`:271-275`) · `pos.auto_prepare_on_paid` true (`:150-154`) ·
`pos.walkin_route_to_counter` false (`:301-305`) · `pos.simulation_hardware` (`:37`) · `pos.cash_session_stale_hours` 24 (`:319`) · `pos.featured_category_slugs` [] (`:113-119`) ·
`kiosk.payment_route_all_to_counter` true (`config/kiosk.php:54,368`) · `kiosk.promo_enabled` false (`:70,370`) · `kiosk.loyalty_redeem_enabled` true (`:102-106,372`) ·
`kiosk.queue_start_number` 32 (`:134,355`) · `kiosk.stale_collect_ttl_minutes` 180 / `stale_phone_collect_ttl_minutes` 360 (`:120,127`) · `kiosk.default_locale` fr / `locale_switch_allowed` false (`:16-19,31`) ·
`kiosk.max_item_qty` 20, `order_rate_limit` 30, `quote_rate_limit` 120 (`:343,347,348`) · `dashboard.sla_alerts_window_hours` 24 / `sla_alerts_threshold_minutes` 15 (`config/dashboard.php:24,29`) ·
`menu.settings.tax_rate` 10.00 / `default_tax_id` 3 / `price_format` `'%s €'` (`config/menu.php:73,80,84`) · `features.staff_only_mode` true / `features.offers_enabled` false (`config/features.php:50,27`) ·
`catalog_v15.*` (14 drapeaux, presque tous false ; exceptions `:70,116,130,160`).
`config/menu.php` : `restaurant` `:24-30`, `locale/currency/timezone` `:37-40`, `categories` (11, `wizard_template` + `has_menu`) `:47-65`, `settings` `:72-85`, `meats` (4) `:92-97`,
`sauces` (13) `:107-121`, `crudites` (4) `:129-134`, `supplements` `:141-148`, `supplement_sauce_price = 0.50` `:155`, `items` par slug avec `viandes`/`has_sauce`/`has_crudites` `:162+`.

# D) `resources/js/config/v1-hidden-modules.js`
`V1_HIDDEN_MENU_MODULES` (`:11-55`) — 34 clés : `customers, coupons, offers, creditBalanceReport, deliveryBoys, onlineOrders, tableOrders, waiters, diningTables, settings.mail,
settings.notification, settings.theme, settings.item-categories, settings.item-attributes, settings.permission, settings.role, settings.tax, settings.charge, settings.translation,
settings.activity-log, settings.languages, settings.otp, settings.notification-alert, settings.social-media, settings.cookies, settings.analytics, settings.time-slots,
settings.sliders, settings.pages, settings.sms-gateway, settings.payment-gateway, settings.license`. `settings.loyalty-setup` commenté (`:32`, dé-caché 2026-08-10, motif `:24-31`).
`V1_HIDDEN_BACKEND_MENU_URLS = ['items']` (`:66`). Appliqué dans `BackendMenuComponent.vue:58,68-78,239-251,252-269,388,424-427` et `settings/MenuComponent.vue:146,154-179,181-185,199-201`.
**Les routes restent enregistrées** : modules cachés atteignables par URL directe (`v1-hidden-modules.js:6-9`). Dé-cacher = supprimer la clé.

# E) Profil entreprise / filiale / marque
| Donnée | Modèle / stockage | UI | Backend |
|---|---|---|---|
| **Branch** (name, email, phone, lat/long, city, state, zip, address, zone, status, `available_locales`, **`siret`, `vat_intra`, `register_id`, `legal_footer`**, `delivery_fee_base/_per_km/_minimum/_free_km`, `delivery_minimum_order`) | `app/Models/Branch.php:14-52` | `settings/Branch/{BranchComponent,BranchListComponent,BranchCreateComponent,BranchShowComponent}.vue`, `settingRoutes.js:90-125` | `BranchController` `routes/api.php:541-549` |
| **Company** (`company_name`, `_email`, `_phone`, `_website`, `_city`, `_state`, `_country_code`, `_zip_code`, `_address`) | `Smartisan\Settings` + **`APP_NAME` écrit dans `.env`** | `settings/Company/CompanyComponent.vue` | `CompanyController.php` (`permission:settings` `:19`), `CompanyRequest.php:29-42` (garde regex anti-injection `.env` `:33`), `SettingsUpdated::dispatch(['company'])` `:36` |
| ⚠️ SIRET / TVA intra | **filiale seulement**, pas Entreprise | formulaire filiale | `BranchController` |
| ⚠️ Horaires d'ouverture / business_date | **introuvable** comme champ éditable. Voisins : `TimeSlot` CRUD (`api.php:643-647`, caché), `order_setup_schedule_order_slot_duration` (`OrderSetupRequest.php:29`), `oss.stale_window_hours`. Date métier pilotée par le cron Z NF525 (`app/Console/Kernel.php:495-549`, 23:59 / 00:01 Europe/Paris) | | |
| **Site** (formats date/heure, fuseau, filiale par défaut, devise, position, décimales, vérifications e-mail/téléphone, langue, bascule de langue, debug, auto update, clé Google Map, liens apps, copyright, passerelle en ligne, SMS par défaut, invité, longueur téléphone) | `.env` via `SiteService` + EnvEditor | `settings/Site/SiteComponent.vue` | `SiteController.php`, `SiteRequest.php:38-60` (`$noEnvInjection` `:36`) |
| **Thème** (`theme_logo`, `theme_favicon_logo`, `theme_footer_logo` — jpg/png ≤ 2 Mo) | `ThemeSetting` + média | `settings/Theme/ThemeComponent.vue` — **caché** | `ThemeController.php`, `ThemeRequest.php:33-37` ; logo sidebar `BackendMenuComponent.vue:6,9` |
| ⚠️ Couleurs de thème | **introuvables** dans `ThemeRequest` — compilées (`tailwind.config.js`, CSS kiosk) | | |
| **Marque borne** (`kiosk_idle_video`, `kiosk_welcome_title`, `kiosk_welcome_subtitle`, `kiosk_tap_hint`, `kiosk_admin_pin` 4 chiffres) | groupe Settings | `settings/KioskSetup/KioskSetupComponent.vue` (visible) | `KioskSetupController`, `KioskSetupRequest.php:16-24` |
| **Configuration des commandes** (temps de préparation, durée de créneau, à emporter, livraison ; 3 champs de frais gardés `sometimes` pour compatibilité) | groupe Settings | `settings/OrderSetup/*` | `OrderSetupRequest.php:26-49` — docblock `:32-45` : **le vrai calcul des frais lit les colonnes `branches` et n'a aucun écran** |

# F) Import / export & IA
Excel (Maatwebsite `^3.1`, `composer.json:25`) : articles export `api.php:868` (`ItemController::export` `:200-207`, `App\Exports\ItemExport`), sample `:869` (`public/file/itemImportSample.xlsx`),
**import** `:870` (`::import` `:218-226`, `App\Imports\ItemImport`, `ItemImportRequest`, gate `items_create` `ItemController.php:32`) ; catégories `:515-517` (UI `settings/ItemCategory/CategoryUploadComponent.vue`).
`menu:reset-le-cayenne` : cité `config/menu.php:51` ; classe **`app/Console/Commands/MenuResetLeCayenneCommand.php` (vérifiée par `ls` par le chef de projet)** ; `database/seeders/MenuSeeder.php:19-41` = seeder SSOT menu ; `EXECUTE_MENU_FIX.sh:15-20`.
IA : **OpenAI seul** (`config/services.php:83-90`, `OPENAI_VISION_ENABLED` défaut **false**, `gpt-4o-mini`) ; factures (`PurchasingServiceProvider`, `config/services.php:71-82`, routes `api.php:417-426`,
`PurchaseDocument`, `PurchaseLine`) ; Uber (`UberVisionServiceProvider.php:30-42`, `config/uber_photo.php:31-38`, `vision_enabled` défaut false, `max_files` 6, `max_kb` 12288).
**Anthropic / chatbot : introuvables** dans le code applicatif (`config/services.php` sans entrée `anthropic`).

# G) Utilisateurs, rôles, permissions
Rôles (`database/seeders/RolePermissionTableSeeder.php`) : **Admin** = toutes (`:18-19`) · **Branch Manager** = 44 permissions (`:25-97`) · **POS Operator** = dashboard, pos, pos-orders,
pos-discount-up-to-10, pos.redeem-loyalty, online-orders, pos-flyer-print + kitchen-display-system, order-status-screen (`:99-126`, `:144-151`) · **Chef** (`:128-138`) · **Stuff** (`:157-165`) ·
**Waiter** (`:169-178`). Filtre de garde `permissionsForRole()` `:199-208` (ajouté 2026-08-25 : permissions `web` vs rôles `sanctum`, `GuardDoesNotMatch` `:181-194`).
Permissions aussi créées par migrations (`2026_08_13_190000_grant_pos_flyer_print_to_cashier`, `2026_07_15_170000_grant_online_orders_to_pos_operator`) et gates code (`catalog.compose`,
`catalog.publish`, `ingredients_manage`, `availability_toggle`, `pos/manage-fiscal`).
Groupes CRUD `routes/api.php` : administrator `:1444-1466`, employee `:757-775`, chef `:724-742`, waiter `:704-722`, delivery-boy `:777-801` (+caisse `:814-831`), customer `:684-702`,
`my-order` `:744-755` (IDOR corrigé, OR de 6 `*_show` `:754`). **`EnforcesOwnBranchScope`** = `app/Services/Concerns/EnforcesOwnBranchScope.php` (`effectiveBranchId()` `:19-26` :
sans permission `settings`, le `branch_id` demandé est ignoré ; utilisateurs `:7-16` : Employee/Chef/Waiter/DeliveryBoy services ; `CustomerService` force `branch_id=0`).
Gardes complémentaires : `AdminController::authorizeBranchScope/authorizeWritableBranchScope` (`:15-40`), `BranchScope` (StockLevel `:25`, ItemBranchAvailability `:47`), `WizardProfileBranchScope`.

# LACUNES qu'un audit « commerçant de zéro » doit traiter comme constats
1. **Aucune sémantique de prix dans le wizard** (`price` interdit) — « N inclus / supplément X » vit dans `config/menu.php` + `config/kiosk.php` : non éditable.
2. **Édition du wizard par article derrière `FEATURE_WIZARD_PER_ITEM_DEMO` (false)** ; seul le composer par catégorie est actif.
3. `ProductCreateWizardComponent.vue` = **squelette**.
4. **22 des 31 sous-pages Réglages cachées** (taxes, catégories, attributs, rôles, langues, passerelle, thème…).
5. `InterrupteurService::CATALOGUE` **booléen par conception** — numérique/texte/horaire = déploiement.
6. **Pas d'éditeur d'horaires** ; barème livraison = colonnes `branches` **sans écran** (`OrderSetupRequest.php:32-45`).
7. **Couleurs de thème non éditables** (3 logos).
8. `item_categories.deleted_at` : migration d'ajout non localisée par lecture (à grep).
