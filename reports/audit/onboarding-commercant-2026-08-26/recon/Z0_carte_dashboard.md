# Z0 — CARTE COMPLÈTE DU DASHBOARD (CENTRAL) — exploration code vérifiée file:line (2026-08-26)

> Produite par un agent d'exploration lecture seule (62 lectures) sur l'arbre principal
> `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt` (HEAD `43b120c7d`). Tout chemin cité a été lu.
> Les rédacteurs de GOAL s'appuient dessus comme ancrage vérifié ; ils re-vérifient toute ligne qu'ils citent.

## 0. Où le menu latéral est défini et comment il est piloté

| Fait | Emplacement |
|---|---|
| Composant sidebar (`aside .db-sidebar`) | `resources/js/components/layouts/backend/BackendMenuComponent.vue:2` |
| Boucle de rendu `menusForSidebar` | `BackendMenuComponent.vue:15` (parents `:16`/`:22`, enfants `:41`) |
| Entrées V1 **CODÉES EN DUR** | `BackendMenuComponent.vue:101-169` (`V1_PRIMARY_SIDEBAR_MENUS`) |
| Dashboard + POS toujours poussés en premier | `BackendMenuComponent.vue:378-379` |
| Menus **PILOTÉS PAR LA BASE** (getter `authMenu`, table `menus`) | `BackendMenuComponent.vue:236-238` ; seedés par `database/seeders/MenuTableSeeder.php:19-372` |
| Injection d'enfants virtuels (Catalogue) | `BackendMenuComponent.vue:94-99` (`VIRTUAL_CHILDREN_BY_URL`) |
| Fusion/dédoublonnage | `BackendMenuComponent.vue:358-409` (`buildMergedSidebarMenus`) |
| **Gate de permission (Spatie)** url→permission | `BackendMenuComponent.vue:172-199` (`MENU_URL_TO_PERMISSION_URL`) + résolveur `:201-221` |
| SSOT d'appariement de permission (partagé avec le routeur) | `resources/js/shared/permission-match.js`, importé `BackendMenuComponent.vue:59`, utilisé `:321-323` |
| Liste de masquage V1 (clés de modules) | `resources/js/config/v1-hidden-modules.js:11-56` ; mapping kebab `BackendMenuComponent.vue:68-78` |
| Liste de masquage V1 (urls DB héritées) | `v1-hidden-modules.js:66` → `V1_HIDDEN_BACKEND_MENU_URLS = ['items']` ; appliqué `BackendMenuComponent.vue:424-427` |
| Gate au niveau routeur | `resources/js/router/index.js:277-289` (`meta.access === false` → `handlePermissionDenied` `:114-133`) ; calculé par `appService.recursiveRouter(routes, permission)` `index.js:230` |

**Verdict : HYBRIDE.** Table `menus` (filtrée Spatie côté client) **+** liste codée en dur dans le composant. Les deux passent par le
même résolveur Spatie. Libellés FR : `resources/js/languages/fr.json:429` (bloc `"menu"`). Pas de `lang/fr/menu.php`.

## 1. Menu latéral — niveau 1 : codé en dur (`V1_PRIMARY_SIDEBAR_MENUS`)

| # | Libellé FR | Déf. sidebar | Permission | Route Vue / chemin | Composant page | API + Contrôleur | FormRequest |
|---|---|---|---|---|---|---|---|
| 1 | Tableau de bord | `:378` | `dashboard` | `admin.dashboard` `/admin/dashboard` | `admin/dashboard/DashboardComponent.vue` | `routes/api.php:1472-1493` → `Admin/DashboardController` (11 GET + `POST /eod-pdf` gate `pos-manage-fiscal`) | aucune |
| 2 | POS | `:379` | `pos` | `admin.pos` `/admin/pos` | `admin/pos/PosComponent.vue` | `api.php:965-1300` (voie CAISSE) | `PosOrderRequest`, … |
| 3 | Produits & Stock | `:109` (`catalog-hub?tab=stock`) | `items` (`:178`) | `admin.catalog.hub` `/admin/catalog-hub` | `admin/items/CatalogHubComponent.vue` (onglets Catalogue/Stock `:8-9,23-66`) | `api.php:396-409` → `Admin/StockRuptureDashboardController` | **aucune** (Request nu) |
| 4 | Conso & Stock | `:114` | `items` | `admin.stock.unified` `/admin/stock/unified` | `admin/stock/UnifiedStockViewComponent.vue` | `api.php:411` → `UnifiedStockViewController@overview` | aucune (lecture) |
| 5 | Ajustement stock | `:119` | `items` | `admin.stock.raw-material-adjust` | `admin/stock/RawMaterialAdjustComponent.vue` | `api.php:435-437` → `RawMaterialAdjustController@history/@adjust` | **aucune** — à signaler |
| 6 | Articles (parent supprimé `:426`) | `:120-127` | `items` | `admin.items` → redirect `admin.items.studio` | `admin/items/ItemComponent.vue` | `api.php:858-900` → `ItemController` + Variation/Extra/Addon/Photo | `ItemRequest`, `ItemImportRequest`, `ChangeImageRequest`, `ItemVariationRequest`, `ItemExtraRequest`, `ItemAddonRequest`, `ItemPhotoUploadRequest`, `ItemOptionPhotoRequest` |
| 6a | ↳ Catalogue | `:96`/`:125` | `items` | `admin.items.studio` `/admin/items/studio` | `admin/items/CatalogStudioComponent.vue` | idem + `api.php:513-523` `ItemCategoryController` | `ItemCategoryRequest` |
| 6b | ↳ Attribut d'articles (injecté) | `:97` | `settings` (`:208-210`) | `admin.settings.itemAttribute.list` | `settings/ItemAttribute/ItemAttributeListComponent.vue` | `api.php:525-531` → `ItemAttributeController` | `ItemAttributeRequest` |
| 7 | Ingrédients | `:128` | `ingredients_manage` | `admin.ingredients.list` `/admin/ingredients` (+`/:type`) | `admin/ingredients/IngredientListComponent.vue` | `api.php:902-913` → `IngredientController` (index/usage/show) | aucune (lecture) |
| 8 | Scan Facture | `:130` | `items_create` | `admin.purchasing.scan` `/admin/purchasing/scan` | `admin/purchasing/PurchaseScanComponent.vue` | `api.php:417-425` → `PurchasingScanController@scan/@targets/@apply` | **aucune** (`$request->validate` inline) |
| 9 | Commandes caisse | `:131` | `pos-orders` | `admin.pos-orders` (+list/show) | `admin/posOrders/*` | `api.php:1357-1405` → `PosOrderController` (voie CAISSE) | `OrderStatusRequest`, `PaymentStatusRequest`, … |
| 10 | Historique | `:133` | `pos-orders` | `admin.historique` | `admin/orderHistory/*` | `api.php:1352-1355` → `OrderHistoryController` | aucune |
| 11 | Encaissement | `:134` | `pos-orders` | `admin.encaissement` | `admin/encaissement/EncaissementComponent.vue` | `api.php:973-1144`, `:1170-1260` (closures dans le groupe `pos`) | aucune |
| 12 | Vue caisse unifiée | `:135` | `cash-sessions-report` | `admin.cash-overview` | `admin/cashOverview/*` | `api.php:1528-1530` → `CashOverviewController` | aucune |
| 13 | Caisse livreur | `:136` | `delivery-boys` | `admin.delivery-boy-cash-sessions.list` (+show) | `admin/deliveryBoyCashSession/*` | `api.php:814-831` | Open/Close/Reconcile requests |
| 14 | Ticket promo (+ Réglages ticket promo) | `:142-149` | `pos-orders` | `admin.promoFlyer` (+`.settings`) | `admin/promo/PromoFlyer{,Settings}Component.vue` | `api.php:1306-1328` → `PromoFlyerController` | **aucune** (inline) |
| 15 | Commande Uber (photo) | `:153` | `pos-orders` | `admin.uberPhoto` | `admin/uber/UberPhotoCaptureComponent.vue` | `api.php:453-463` → `UberPhotoCaptureController` | **aucune** |
| 16 | La roue (externe, Blade, `_blank`) | `:168` | `pos-orders` | Blade `/admin/roue` | `Wheel/WheelAccessController@show` | `routes/web.php:161-231` (Access/Counter/Settings/Prize) ; API `api.php:945,961` | **aucune** |

Sous-pages roue (Blade, middleware `wheel.access`) : `admin.wheel.home` `/admin/roue` (`web.php:161`) · `.counter` `/admin/roue-validation` (`:186`) ·
`.settings` `/admin/roue-reglages` (`:208`) · `.kiosk` `/admin/roue-borne` (`:213`) · `.prize` `/admin/roue-lot` (`:217`) · `.history` `/admin/roue-historique` (`:229`) · `.pass` (`:179`).

## 2. Menu latéral — niveau 2 : seedé en base (`MenuTableSeeder`), après masquage V1

Masqués par `v1-hidden-modules.js:11-56` → **customers, coupons, offers, credit-balance-report, delivery-boys, online-orders, table-orders, waiters, dining-tables**.
Le groupe `Promo` (`MenuTableSeeder.php:129-162`) perd ses deux enfants et disparaît (`BackendMenuComponent.vue:397`).

| Libellé FR | Seeder | Perm | Route / chemin | Composant | Contrôleur + API | FormRequest |
|---|---|---|---|---|---|---|
| Caisse et commandes (groupe) | `:53-56` | enfants | — | — | — | — |
| ↳ Écran cuisine | `:106-109` | `kitchen-display-system` | `admin.kitchen-display-system` (+`/kds` redirect `router/index.js:141`) | `admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` | `api.php:1619-1654` → `KitchenDisplaySystemController` + `KdsSyncController` | `Kds/KdsOrderStatusRequest`, `Kds/KdsOrderRecallRequest` |
| ↳ Suivi client | `:117-120` | `order-status-screen` | `admin.order-status-screen` (+`/order-status` `index.js:153`) | `admin/orderStatusScreen/OrderStatusScreenComponent.vue` | `api.php:1687-1690` → `OrderStatusScreenController` | aucune |
| ↳ Commandes en ligne | `:84-87` | **CACHÉ V1** | `admin.order` `/admin/online-orders` | `admin/onlineOrders/*` | `api.php:1407-1420` → `OnlineOrderController` | `OrderStatusRequest`, `PaymentStatusRequest` |
| ↳ Commandes table | `:95-98` | **CACHÉ V1** | `admin.table.order` `/admin/table-orders` | `admin/tableOrders/*` | `api.php:1422-1434` → `TableOrderController` | `TableOrderRequest`, `TableOrderTokenRequest` |
| Promo ↳ Coupons | `:140-143` | **CACHÉ V1** | `admin.coupons` (+list/show) | `admin/coupons/*` | `api.php:833-842` → `CouponController` | `CouponRequest` |
| Promo ↳ Offres | `:151-154` | **CACHÉ V1** | `admin.offers` (+list/show) | `admin/offers/*` | `api.php:844-856` → `OfferController`, `OfferItemController` | `OfferRequest`, `OfferItemRequest` |
| Communications ↳ Notification pushs | `:174-177` | `push-notifications` | `admin.push-notifications` (+list/show) | `admin/pushNotification/*` | `api.php:1436-1442` → `PushNotificationController` | `PushNotificationRequest` |
| ↳ Messages | `:185-188` | `messages` | `admin.messages` (+list) | `admin/messages/*` | `api.php:1532-1540` → `MessageController` (pas d'`update`) | `MessageRequest` |
| ↳ Abonnés | `:196-199` | `subscribers` | `admin.subscribers` (+list) | `admin/subscribers/*` | `api.php:677-682` → `SubscriberController` | `SubscriberRequest`, `SubscriberEmailRequest` |
| Utilisateurs ↳ Administrateurs | `:219-222` | `administrators` | `admin.administrators` (+list/show/order.details) | `admin/administrators/*` | `api.php:1444-1466` → `AdministratorController`, `AdministratorAddressController` | `AdministratorRequest`, `AdministratorAddressRequest`, `ChangePasswordRequest`, `ChangeImageRequest` |
| ↳ Livreurs | `:229-232` | **CACHÉ V1** | `admin.delivery-boys` | `admin/deliveryBoys/*` | `api.php:777-808` | `DeliveryBoyRequest`, `DeliveryBoyAddressRequest` |
| ↳ Clients | `:239-242` | **CACHÉ V1** | `admin.customers` | `admin/customers/*` | `api.php:684-702` | `CustomerRequest`, `CustomerAddressRequest` |
| ↳ Employés | `:249-252` | `employees` | `admin.employees` | `admin/employees/*` | `api.php:757-775` | `EmployeeRequest`, `EmployeeAddressRequest` |
| ↳ Serveurs | `:259-262` | **CACHÉ V1** | `admin.waiters` | `admin/waiters/*` | `api.php:704-722` | `WaiterRequest`, `WaiterAddressRequest` |
| ↳ Chefs | `:269-272` | `chefs` | `admin.chefs` | `admin/chefs/*` | `api.php:724-742` | `ChefRequest`, `ChefAddressRequest` |
| Comptes ↳ Transactions | `:291-294` | `transactions` | `admin.transactions.list` | `admin/transactions/TransactionListComponent.vue` | `api.php:1547-1550` → `TransactionController` (index, export) | aucune |
| Rapports ↳ Rapport des ventes | `:314-317` | `sales-report` | `admin.sales-report` (+list) | `admin/salesReport/*` | `api.php:1495-1500` → `SalesReportController` (index, export, pdf, overview) | `PaginateRequest` |
| ↳ Rapport articles | `:326-329` | `items-report` | `admin.items-report` | `admin/itemsReport/*` | `api.php:1502-1506` → `ItemsReportController` | `PaginateRequest` |
| ↳ Rapport solde crédit | `:336-339` | **CACHÉ V1** | `admin.credit-balance-report` | `admin/creditBalanceReport/*` | `api.php:1508-1511` | `PaginateRequest` |
| Configuration ↳ Paramètres | `:359-362` | `settings` | `admin.settings` → redirect `admin.settings.company` | `admin/settings/SettingsComponent.vue` (shell `:1-15`) | voir §4 | voir §4 |
| Tables | `:42-45` | **CACHÉ V1** | `admin.diningTable` | `admin/diningTable/*` | `api.php:1611-1618` → `DiningTableController` | `DiningTableRequest` |

## 3. Deuxième surface — « Accès rapide » du tableau de bord
`admin/dashboard/DashboardComponent.vue:133-188`, rendu `:30-46`. Gate permission avec **repli permissif** (`:143-145` : permission inconnue ⇒ affiché).
POS `:155` · Commandes caisse `:167` · **Suivi caisse (kanban)** `/admin/pos-orders-tracker` `:168` (route `posOrderRoutes.js:52-58`, `admin/pos/PosOrdersTrackerComponent.vue`) ·
Encaissement `:172` · Historique `:173` · Écran cuisine `:174` · Suivi client `:175` · Catalogue `:176` · Ingrédients `:177` · Produits & Stock `/admin/stock/rupture` `:178`
(route `stockRoutes.js:19-28`, `StockRuptureDashboardComponent.vue`) · **Rapport caisses quotidien** `/admin/cash-sessions-report` `:182` (`cashSessionReportRoutes.js:10-17`,
`api.php:1517-1519`) · Vue caisse unifiée `:186` · **Bouton PDF clôture jour (EOD)** `POST admin/dashboard/eod-pdf` (`:17-27`, handler `:219-249`, `api.php:1492`).
`/admin/pos-v4` commenté `:166` (route vivante `routes/web.php:110-113`).
Widgets montés `DashboardComponent.vue:48-69` : Overview, RealtimeReport, SlaAlerts, ChannelStats, AuditTrail, OrderStatistics, SalesSummary, OrderSummary, LastZReportWidget,
FeaturedItems, MostPopularItems, StockLowAlertsWidget. **`CustomerStatsComponent.vue` et `TopCustomersComponent.vue` existent mais ne sont PAS montés** (orphelins ; API `api.php:1480-1481`).

## 4. Menu Réglages — `admin/settings/MenuComponent.vue:9-139` (liste plate codée en dur, visibilité via `isSettingHidden()` `:154-201`)
Toutes les routes portent `permissionUrl:"settings"` sauf Rapports Z (`pos/manage-fiscal`, `settingRoutes.js:579`).

| # | Libellé | Ligne menu | Caché V1 ? | Route (`settingRoutes.js`) | Composant | Contrôleur (`api.php`) | FormRequest |
|---|---|---|---|---|---|---|---|
| 1 | Entreprise | `:9` | non | `admin.settings.company` (`:69-78`) | `settings/Company/CompanyComponent.vue` | `:467-470` `CompanyController` | `CompanyRequest` |
| 2 | Site | `:13` | non | `admin.settings.site` (`:80-89`) | `settings/Site/SiteComponent.vue` | `:472-475` `SiteController` | `SiteRequest` |
| 3 | Filiales | `:17` | non | `admin.settings.branch` (+list/show `:91-125`) | `settings/Branch/*` | `:541-549` `BranchController` | `BranchRequest` |
| 4 | Bornes | `:21` | non | `admin.settings.kioskMachines` (`:617-641`) | `settings/KioskMachine/*` | `:666-674` `KioskMachineController` | `KioskMachineRequest` |
| 5 | Rapports Z (NF525) | `:27` | non | `admin.settings.zReports` (`:565-582`, perm `pos/manage-fiscal`) | `settings/Fiscal/ZReportListComponent.vue` | `:1700-1712` `Fiscal/ZReportController` + `XReportController@show` | aucune |
| 6 | Imprimantes | `:31` | non | `admin.settings.printers` (`:583-594`) | `settings/Printers/PrintersComponent.vue` | `:1330-1338` `PrinterController` (+test-print) | `Admin/PrinterRequest` |
| 7 | Terminaux de paiement | `:35` | non | `admin.settings.paymentTerminals` (`:553-564`) | `settings/PaymentTerminals/*` | `:1340-1346` `PaymentTerminalController` | `Admin/PaymentTerminalRequest` |
| 8 | Mail | `:39` | **CACHÉ** | `admin.settings.mail` (`:126-136`) | `settings/Mail/*` | `:492-495` `MailController` | `MailRequest` |
| 9 | Configuration des commandes | `:43` | non | `admin.settings.orderSetup` (`:137-147`) | `settings/OrderSetup/*` | `:477-480` `OrderSetupController` | `OrderSetupRequest` |
| 10 | Configuration borne | `:47` | non | `admin.settings.kioskSetup` (`:148-158`) | `settings/KioskSetup/*` | `:482-485` `KioskSetupController` | `KioskSetupRequest` |
| 11 | Fidélité | `:51` | non (dé-caché 2026-08-10) | `admin.settings.loyaltySetup` (`:159-169`) | `settings/LoyaltySetup/*` | `:487-490` `LoyaltySetupController` | `LoyaltySetupRequest` |
| 12 | OTP | `:55` | **CACHÉ** | `admin.settings.otp` (`:170-180`) | `settings/Otp/*` | `:620-623` `OtpController` | `OtpRequest` |
| 13 | Notification | `:59` | **CACHÉ** | `admin.settings.notification` (`:181-191`) | `settings/Notification/*` | `:591-594` `NotificationController` | `NotificationRequest` |
| 14 | Alerte notification | `:63` | **CACHÉ** | `admin.settings.notificationAlert` (`:606-616`) | `settings/NotificationAlert/*` | `:661-664` `NotificationAlertController` | **introuvable** |
| 15 | Réseaux sociaux | `:67` | **CACHÉ** | `admin.settings.socialMedia` (`:192-202`) | `settings/SocialMedia/*` | `:596-599` | `SocialMediaRequest` |
| 16 | Cookies | `:71` | **CACHÉ** | `admin.settings.cookies` (`:203-213`) | `settings/Cookies/*` | `:638-641` | `CookiesRequest`, `CookiesSetRequest` |
| 17 | Analytique | `:75` | **CACHÉ** | `admin.settings.analytic` (+list/show `:214-250`) | `settings/analytics/*` | `:601-618` `AnalyticController`, `AnalyticSectionController` | `AnalyticRequest`, `AnalyticSectionRequest` |
| 18 | Thème | `:79` | **CACHÉ** | `admin.settings.theme` (`:251-261`) | `settings/Theme/*` | `:576-579` `ThemeController` | `ThemeRequest` |
| 19 | Créneaux horaires | `:83` | **CACHÉ** | `admin.settings.timeSlot` (`:262-272`) | `settings/TimeSlot/TimeSlotListComponent.vue` | `:643-647` `TimeSlotController` | `TimeSlotRequest` |
| 20 | Bannières | `:87` | **CACHÉ** | `admin.settings.slider` (+list/show `:273-308`) | `settings/Slider/*` | `:533-539` `SliderController` | `SliderRequest` |
| 21 | Devises | `:91` | non | `admin.settings.currency` (+list `:309-333`) | `settings/Currency/*` | `:497-503` `CurrencyController` | `CurrencyRequest` |
| 22 | Catégories | `:95` | **CACHÉ** | `admin.settings.itemCategory` (+list ; `show/:id` → redirect Studio `:357-370`) | `settings/ItemCategory/*` (`ItemCateogryListComponent.vue` — faute dans le nom) | `:513-523` `ItemCategoryController` (+export/sample/import/sort) | `ItemCategoryRequest`, `ItemCategoryImportRequest` |
| 23 | Attribut d'articles | `:99` | **CACHÉ** (mais réinjecté dans le menu principal `BackendMenuComponent.vue:97`) | `admin.settings.itemAttribute` (`:373-397`) | `settings/ItemAttribute/*` | `:525-531` | `ItemAttributeRequest` |
| 24 | Taxes | `:103` | **CACHÉ** | `admin.settings.tax` (+list `:398-422`) | `settings/Tax/*` | `:505-511` `TaxController` | `TaxRequest` |
| 25 | Pages | `:107` | **CACHÉ** | `admin.settings.page` (+list/show `:423-458`) | `settings/Page/*` | `:563-569` `PageController` | `PageRequest` |
| 26 | Rôle & Autorisations | `:111` | **CACHÉ** | `admin.settings.role` — **chemin réel `/admin/settings/role/list` et `role/show/:id` (singulier, `settingRoutes.js:460-494`)** ; ⚠️ `roles/list` (pluriel) tombe sur le catch-all « Page non trouvée » (erreur d'instrument Z3, corrigée) | `settings/Role/{RoleComponent,RoleListComponent,RoleShowComponent,RoleCreateComponent}.vue` | `:625-636` `RoleController` + `PermissionController` | `RoleRequest`, `PermissionRequest` |
| 27 | Langues | `:115` | **CACHÉ** | `admin.settings.language` (+list/show `:495-530`) | `settings/Language/*` | `:649-659` `LanguageController` (+file-list, file-text, store) | `LanguageRequest`, `LanguageFileTextGetRequest` |
| 28 | Passerelle SMS | `:119` | **CACHÉ** | `admin.settings.smsGateway` (`:531-541`) | `settings/SmsGateway/*` | `:581-584` | `SmsGatewayRequest` |
| 29 | Passerelle de paiement | `:123` | **CACHÉ** | `admin.settings.paymentGateway` (`:542-552`) | `settings/PaymentGateway/*` | `:586-589` `PaymentGatewayController` | non vérifié |
| 30 | Licence | `:127` | **CACHÉ** | `admin.settings.license` (`:595-605`) | `settings/License/*` | `:571-574` `LicenseController` | `LicenseRequest` |
| 31 | Outils avancés → Demo Wizard avancé | `:131-139` | drapeau `features.wizard_per_item_demo` (`:190-193`) | `admin.demo.wizard-launcher` (`itemRoutes.js:124-137`, perm `catalog.compose`) | `admin/demo/WizardAdvancedLauncherComponent.vue` | `api.php:915-940` `ComposerProfileController`, `ComposerStepController` | `ComposerProfileRequest`, `ComposerStepRequest` |

## 5. Barre de navigation / menu profil — `layouts/backend/BackendNavbarComponent.vue`
Sélecteur de filiale (Admin `authBranch === 0`) `:18-40` · sélecteur de langue `:53-70` · raccourci POS `:72-85` · bouton KDS/OSS `:44-50` ·
Modifier le profil `:149-160` → `admin.profile.editProfile` (`profileRoutes.js:14-23`, `Auth/ProfileController`, `api.php:332-336`) ·
Changer le mot de passe `:162-173` → `admin.profile.changePassword` (`profileRoutes.js:25-34`) · **Appareils connectés** `:183-194` → `admin.profile.devices`
(`profileRoutes.js:36-45`, `Auth/DeviceSessionController`, `api.php:279-283`) · Déconnexion `:196-201` (`api.php:255`).

## 6. Liens morts / orphelins / squelettes
| Gravité | Constat | Preuve |
|---|---|---|
| ORPHELIN (aucun lien) | `admin.observability.system` `/admin/observability/system` — « État du système » (`SystemHealthComponent.vue`) ; clé FR `menu.system_health` existe, référencée par aucun menu | `observabilityRoutes.js:26-35` ; API `api.php:1664` |
| ORPHELIN (aucun lien) | `admin.observability.outbox` `/admin/observability/outbox` (`OutboxOverviewComponent.vue`) | `observabilityRoutes.js:36-46` ; API `api.php:1679-1685` |
| ORPHELIN (composants jamais montés) | `admin/dashboard/CustomerStatsComponent.vue`, `TopCustomersComponent.vue` | absents de `DashboardComponent.vue:73-106` ; API `api.php:1480-1481` |
| ORPHELIN (pas de page) | Sorties de stock / pertes — `PosStockOutflowController` (`api.php:1164-1168`) uniquement via modale POS `admin/pos/PosStockOutflowModal.vue:131-147` | — |
| ORPHELIN (pas de page) | Interrupteurs — `Admin/Pilotage/InterrupteurController` (`api.php:1669-1670`) : aucune route Vue dédiée dans le menu | — |
| ORPHELIN (pas de page) | Rapport X (NF525) — `Fiscal/XReportController@show` (`api.php:1710-1711`) | — |
| INCOHÉRENCE | `settings.item-attributes` caché (`v1-hidden-modules.js:41`, `MenuComponent.vue:99`) **mais** réinjecté dans le menu principal (`BackendMenuComponent.vue:97`), non filtré par `HIDDEN_KEY_TO_MENU_URL` (`:68-78`) | — |
| RISQUE repli permissif | `hasPermissionAccess` renvoie **true** si la permission est inconnue (`router/index.js:106-110`, `DashboardComponent.vue:143-145`) : une entrée dont la `permissionUrl` n'a pas de ligne Spatie est visible par tous ; l'API est la seule vraie barrière | — |
| SHELL parallèle | `/admin/pos-v4` (`routes/web.php:110-113`) monte le même `PosComponent.vue` ; lien commenté (`DashboardComponent.vue:156-166`) | — |
| SANS FormRequest | `RawMaterialAdjustController`, `PurchasingScanController`, `UberPhotoCaptureController`, `PromoFlyerController`, `StockRuptureDashboardController`, tous `Wheel/*`, `NotificationAlertController` | §1/§4 |
| Nom fautif | `settings/ItemCategory/ItemCateogryListComponent.vue` (importé tel quel `settingRoutes.js:8`) | — |

## 7. Permissions Spatie — `database/seeders/PermissionTableSeeder.php`
**80 permissions** = 36 groupes + 44 enfants, `guard_name = sanctum`, upsert `(name, guard_name)` `:731-735`, cache vidé `:739`.
Groupes (ligne) : dashboard `:21` · items `:29` (+create/edit/delete/show) · dining-tables `:71` (+4) · pos `:113` · pos-orders `:121` · pos-flyer-print `:132` ·
pos-discount-up-to-10 `:141` · pos-discount-over-10-requires-manager `:149` · pos-discount-unlimited `:157` · pos-destroy-paid `:166` · pos-manage-fiscal `:175` ·
pos-reopen-z `:184` · pos.redeem-loyalty `:196` · pos-refund `:212` · online-orders `:220` · table-orders `:228` · kitchen-display-system `:236` · order-status-screen `:244` ·
coupons `:252` (+4) · offers `:294` (+4) · push-notifications `:336` (+4) · messages `:378` · subscribers `:386` · administrators `:394` (+4) · delivery-boys `:436` (+4) ·
customers `:478` (+4) · employees `:520` (+4) · waiters `:562` (+4) · chefs `:604` (+4) · transactions `:646` · sales-report `:654` · items-report `:662` ·
credit-balance-report `:670` · cash-sessions-report `:684` · settings `:692` · cash.reconcile.variance.override `:705`.
Hors seeder : `ingredients_manage` (`IngredientPermissionSeeder.php:20`), `availability_toggle` (`AvailabilityTogglePermissionSeeder.php:32`), `catalog.compose`/`catalog.publish`
(`ComposerPermissionsMinimalSeeder.php:11`). Autres seeders : `PermissionTableSeederVersionTwo`, `AdminWebGuardPermissionsSyncSeeder`, `E2EPlaywrightPermissionsHealSeeder`,
`LeCayenneRoleLandingUrlSeeder`. Rôles : `RolePermissionTableSeeder.php`, `RoleTableSeeder.php`, `SpatieRoleLookup.php`.

## 8. Onboarding / profil entreprise / nouveau restaurant
| Flux | Statut | Emplacement |
|---|---|---|
| Installeur (licence → site → base → final) | Existe — Blade, pré-auth, hors Dashboard | `routes/web.php:22-33` → `Installer/InstallerController` |
| Profil Entreprise | Sous-page Réglages (nom/logo/adresse/id fiscal) | `settingRoutes.js:69-78`, `settings/Company/CompanyComponent.vue`, `api.php:467-470`, `CompanyRequest`, `CompanyTableSeeder` |
| Créer une filiale (« nouveau restaurant ») | CRUD liste + drawer, aucun parcours guidé | `settingRoutes.js:102-112`, `settings/Branch/BranchListComponent.vue`, `api.php:541-549`, `BranchRequest`, `BranchTableSeeder` |
| **Assistant d'onboarding commerçant** | **INTROUVABLE** (grep `onboarding|setup-wizard|premier démarrage|first.run` : rien de pertinent) | — |
| Wizard de création de produit | Existe | `admin/items/wizard/ProductCreateWizardComponent.vue` (squelette, cf. Z0 modèle) |
| Profils de wizard / composer (article & catégorie) | Existe, **derrière drapeau** `features.wizard_per_item_demo` (`itemRoutes.js:13-25`) | `admin.items.composer` (`itemRoutes.js:108-123`), `admin.categories.composer` (`:138-153`), `admin.demo.wizard-launcher` (`:124-137`) ; composants `items/composer/*` ; API `api.php:915-940` ; seeders `ComposerSeeder`, `ItemCategoryWizardSeeder`, `AlignFritesWizardProfilesSeeder`, `WizardCayenneAndBolsCorrectionsSeeder` |
| Entrée composer par catégorie | `CatalogStudioComponent.vue:39-51` (`openCategoryComposerDrawer`) | — |
| Règles d'upsell | Pas de page dédiée — sur les formulaires article/catégorie ; seeder `KioskUpsellCategoryFix20260705Seeder.php` | — |

## 9. IA / extraction de menu / OCR / import
| Fonction | Statut | Emplacement |
|---|---|---|
| OpenAI Vision — lecture de factures (Scan Facture) | Vivant, gaté par clé | `config/services.php:83-89` (`OPENAI_API_KEY`, `OPENAI_VISION_ENABLED`, `gpt-4o-mini`, `OPENAI_BASE_URL`, `OPENAI_TIMEOUT`, `OPENAI_MOCK_FIXTURE`) ; `PurchasingServiceProvider.php:30-34` ; `app/Services/Purchasing/Vision/{OpenAiInvoiceVisionService,InvoiceVisionContract,MockInvoiceVisionService}.php` ; `PurchasingScanController.php:12,87-89` |
| OpenAI Vision — ticket Uber photo | Vivant, même clé | `UberVisionServiceProvider.php:20,33-37` ; `config/uber_photo.php:28` ; `app/Services/Uber/Vision/*` ; `UberPhotoCaptureController` ; `UberTicketCapture` |
| Classification de lignes (non-LLM) | Vivant | `app/Services/Purchasing/InvoiceClassificationService.php`, `PurchaseService.php` |
| Anthropic / chat / chatbot | **INTROUVABLE** (seul `.env.anthropic.example`, outillage dev) | — |
| OCR classique (Tesseract) | **INTROUVABLE** | — |
| Extraction de menu depuis une photo | **INTROUVABLE** comme fonction produit | — |
| Import/export Excel-CSV (Maatwebsite) | Vivant | articles `api.php:868-870` (`ItemController::export/import` `:200-221`, `ItemImportRequest`, `app/Imports/ItemImport`, `app/Exports/ItemExport`) ; catégories `api.php:515-517` ; exports seuls : clients `:691`, serveurs `:711`, chefs `:731`, employés `:764`, livreurs `:784`, administrateurs `:1451`, coupons `:839`, offres `:850`, abonnés `:680`, tables `:1617`, commandes caisse `:1369`, en ligne `:1412`, table `:1427`, push `:1441`, transactions `:1549`, ventes `:1497`, articles `:1504`, crédit `:1510` |
| PDF | ventes `api.php:1498`, articles `:1505`, commande en ligne `:1413`, Z `:1708`, EOD `:1492` | — |

## 10. Surfaces admin adjacentes hors menu
Plan de salle POS `admin.pos.floorplan` (`posRoutes.js:25-32`) · POS V4 `/admin/pos-v4/{any?}` (`web.php:110-113`) · suivi public `/suivi/:trackingToken`
(`orderTrackingRoutes.js:13-15`) · Carnet de bord `/carnet` (`web.php:59-75`, PIN, non-SPA) · Stock mobile `/m` (`web.php:84-101`, PIN) ·
téléchargement des ponts `/dl/{bridge}` (`web.php:124-137` : `tools/caisse-bridge`, `tools/borne`, `tools/kitchen-bridge`) · accès par défaut par rôle
`api.php:379-383` (`DefaultAccessController`, `router/index.js:58-62`) · projection de menu `api.php:385` (`MenuProjectionController@show`) ·
bascules de disponibilité `api.php:358-393` (`AvailabilityController`, requêtes `AvailabilityToggleRequest`, `ToggleExtraAvailabilityRequest`,
`ToggleVariationAvailabilityRequest` ; UI `items/AvailabilityToggleComponent.vue`, `ingredients/IngredientAvailabilityToggleComponent.vue`) ·
santé non authentifiée `api.php:144-152` (`HealthController` full/live/ready, `HealthzController`).
