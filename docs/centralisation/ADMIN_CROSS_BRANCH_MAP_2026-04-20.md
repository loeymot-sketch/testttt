# Admin cross-branch map — 2026-04-20

> Snapshot statique. Source : inventaire `app/Http/Controllers/Admin/*.php` + sous-namespace `App\Http\Controllers\Admin\Fiscal\`, et lecture ciblée des services injectés (grep `branch_id` / `auth()->user()->branch_id` / patterns plan).
> Cible : centralisation globale FoodKing — cohérence multi-branch.

**PARTIAL_COVERAGE** : classification détaillée pour le périmètre sensible (commandes, transactions, KDS/OSS, fiscal, items/rapports articles, settings société & accès, utilisateurs). **52** fichiers `app/Http/Controllers/Admin/*.php` restent listés en annexe sans catégorie A/B/C (à traiter dans un cycle dédié). Les contrôleurs `Admin\Fiscal\*` sont traités en section A en complément de cet inventaire racine (**77** fichiers au total à la racine).

## A. Controllers bornés par `branch_id`

| Controller | Méthode de filter | Service associé | Note |
|---|---|---|---|
| `KitchenDisplaySystemController` | `auth()->user()->branch_id` ; `where('branch_id', $userBranchId)` si `branch_id > 0` | `KitchenDisplaySystemOrderService` | Admin `branch_id = 0` voit toutes les branches (intentionnel dans le service). |
| `OrderStatusScreenController` | Idem filtre branche pour staff | `OrderStatusScreenOrderService` | Même schéma admin global / staff borné. |
| `AvailabilityController` | `resolveScopedBranchIds`, `where('branch_id', $branchId)`, garde si `branch_id` hors périmètre | (logique contrôleur + `ItemAvailabilityChanged::forBranch`) | Comportement multi-branche explicite avec validation de périmètre. |
| `Fiscal\ZReportController` | `resolveBranchId()` depuis utilisateur épinglé (`branch_id > 0`) ; requêtes `where('branch_id', $branchId)` ; `abort_if` sur modèle | `Fiscal\ZReportService` | Cross-branch refusé : admin sans branche épinglée → 422. |
| `Fiscal\XReportController` | `branchId` depuis `user->branch_id` ; `abort_if <= 0` | `Fiscal\XReportService` | Lecture snapshot intraday par branche épinglée. |
| `DashboardController` | Filtre acteur dans `DashboardService` (`actorBranchId` > 0 → `where('branch_id', …)`) | `DashboardService`, `ItemService` (méthodes dashboard) | Aligné commentaire service : admin `branch_id=0` voit tout ; staff voit sa branche. |

## B. Controllers volontairement cross-branch

| Controller | Justification | Rôles requis |
|---|---|---|
| `BranchController` | Gestion du répertoire des succursales (CRUD) — périmètre métier multi-branche natif | `permission:settings` sur mutations ; lecture selon garde `AdminController` |
| `ItemController` | Catalogue articles sans filtre `branch_id` dans `ItemService` (liste / détail globaux au tenant) | Permissions Spatie `items_*` |
| `ItemsReportController` | Rapport agrégé sur items (`itemReport`) — pas de scope branche dans la requête item | `permission:items-report` |
| `RoleController` | Rôles Spatie — dimension tenant / app, pas par succursale | Selon middleware contrôleur |
| `PermissionController` | Idem permissions globales | Selon middleware contrôleur |
| `AnalyticController` | Entités analytics (config pistage) sans dimension branche dans `AnalyticService::list` | Selon middleware |
| `OrderSetupController` | Paramètres globaux de commande (`OrderSetupService`) | `permission:settings` sur `update` |
| `CompanyController` | Données société (`CompanyService`) — singleton / global | `permission:settings` sur `update` |
| `DefaultAccessController` | Accès par défaut (`DefaultAccessService`) — configuration globale | Selon middleware |

## C. Controllers non classés (à investiguer)

| Controller | Pourquoi ambigu | Action proposée |
|---|---|---|
| `PosOrderController` | Délègue `OrderService::list` : **aucun** filtre branche implicite ; `show` utilise `OrderService::show($order, false)` sans vérif branche | Cycle review : imposer scope liste + policy `Order` pour `show` / aligner matrice doc |
| `OnlineOrderController` | Même dépendance `OrderService::list` / `show` | Idem |
| `TableOrderController` | Idem `list` + `show` | Idem |
| `TransactionController` | `TransactionService::list` ne filtre par branche **que** si `branch_id` présent dans la requête | Cycle : défaut `branch_id` = branche acteur pour rôles non-admin |
| `SalesReportController` | S’appuie sur `OrderService::list` pour agrégats / exports | Idem que contrôleurs commande |
| `PosController` | Aucune occurrence des motifs grep dans le contrôleur — comportement branche à tracer (POS / tiroir / wizard) | Cycle : lire service injecté et flux caisse |
| `AdministratorController` | `AdministratorService` expose `branch_id` comme **filtre** optionnel (pas de borne par défaut sur `list`) | Cycle : policy + scope liste par rôle |
| `SimpleUserController` | Même schéma probable (utilisateurs) | Idem |
| `EmployeeController` | `EmployeeService` : `branch_id` en filtre requête, pas de défaut acteur | Idem |
| `CreditBalanceReportController` | `UserService::list` : `branch_id` filtre optionnel uniquement | Cycle : rapport crédit — confirmer exposition cross-branch |
| `DeliveryBoyOrderController` | Liste via `delivery_boy_id`, pas de filtre `branch_id` explicite (livreur peut couvrir plusieurs branches) | Cycle : clarifier risque métier / ajouter borne si requis |
| `MyOrderDetailsController` | `orderDetails(User, Order)` borne par `user_id` de la commande, pas par branche acteur | Cycle : abuse par choix de `User` en route — vérifier policies |

## D. Écarts vs `docs/AUTHZ_MATRIX.md`

Fichier présent et lu (matrice acteurs, permissions POS/fiscal, rate-limit Z).

- **Couverture** : la matrice affirme que le **Chef (KDS)** ne doit pas voir une autre succursale — cohérent avec `KitchenDisplaySystemOrderService` (staff `branch_id > 0`).
- **Lacune** : aucune mention que les listes **POS / online / table** s’appuient sur `OrderService::list` **sans** contrainte automatique `branch_id` côté service (filtrage seulement si paramètre présent).
- **Lacune** : les transactions (`TransactionService::list`) peuvent être listées sans filtre branche si le client n’envoie pas `branch_id`.
- **Lacune** : `OrderService::show` en mode admin (`$auth = false`) ne vérifie pas la branche — non documenté dans la matrice.
- **OSS** : la matrice décrit l’acteur OSS (api-key) mais pas le comportement branche côté `OrderStatusScreenOrderService` (voir section A pour le détail code).

## E. Recommandations cycles futurs

- **P13 / sécurité** : revue ciblée `OrderService::list` + `show` + routes `PosOrder` / `OnlineOrder` / `TableOrder` — catégorie **C** (3 contrôleurs + `SalesReportController`).
- **Transactions** : cycle durcissement `TransactionService::list` (défaut branche acteur) — **C**.
- **Utilisateurs / crédit** : `AdministratorController`, `EmployeeController`, `SimpleUserController`, `CreditBalanceReportController` — **C**.
- **POS core** : `PosController` — **C** (trace services + drawer).
- **Livreur / détail commande** : `DeliveryBoyOrderController`, `MyOrderDetailsController` — **C**.
- **Documentation** : cycle mise à jour `docs/AUTHZ_MATRIX.md` (hors scope de ce snapshot) pour refléter listes commandes/transactions et `show` order.
- **Annexe** : classifier les **52** contrôleurs racine restants (menus, offres, kiosk, notifications, etc.) dans un cycle « inventaire admin complet ».

### Annexe — Contrôleurs admin non classés dans ce document (à classer ultérieurement)

`AddressController.php`, `AdminController.php`, `AdministratorAddressController.php`, `AnalyticSectionController.php`, `ChefAddressController.php`, `ChefController.php`, `CookiesController.php`, `CountryCodeController.php`, `CouponController.php`, `CurrencyController.php`, `CustomerAddressController.php`, `CustomerController.php`, `DeliveryBoyAddressController.php`, `DeliveryBoyController.php`, `DiningTableController.php`, `EmployeeAddressController.php`, `ItemAddonController.php`, `ItemAttributeController.php`, `ItemCategoryController.php`, `ItemExtraController.php`, `ItemVariationController.php`, `KioskMachineController.php`, `KioskSetupController.php`, `LanguageController.php`, `LicenseController.php`, `LoyaltySetupController.php`, `MailController.php`, `MenuController.php`, `MenuProjectionController.php`, `MenuSectionController.php`, `MenuTemplateController.php`, `MessageController.php`, `NotificationAlertController.php`, `NotificationController.php`, `OfferController.php`, `OfferItemController.php`, `OtpController.php`, `PageController.php`, `PaymentGatewayController.php`, `PosCategoryController.php`, `PushNotificationController.php`, `SiteController.php`, `SliderController.php`, `SmsGatewayController.php`, `SocialMediaController.php`, `SubscriberController.php`, `TaxController.php`, `ThemeController.php`, `TimeSlotController.php`, `TimezoneController.php`, `WaiterAddressController.php`, `WaiterController.php`.
