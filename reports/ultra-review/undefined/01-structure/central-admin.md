# Cartographie W1 — SYSTÈME CENTRAL (Admin / Gestion)

Lecteur : central-admin · Date : 2026-07-02 · Read-only, tout file:line vérifié dans cette session.

## 1. Rôle du système

Le système central est le back-office de gestion du restaurant Le Cayenne (V1 LOCAL, branch_id=1) :
dashboard chiffres du jour, catalogue produits (SSOT DB items), réglages (~26 sous-pages settings),
gestion des utilisateurs/staff, rapports (ventes, items, crédit, cash), historique unifié des
commandes, stock/rupture, et lecture des rapports fiscaux Z/X (NF525). La vitrine client publique
est DÉSACTIVÉE : `/`, `/menu`, `/offers` redirigent vers `/login` (routes/web.php:43-46), le SPA
admin est servi par le catch-all `RootController` (routes/web.php:71) et le POS V4 a une entrée
Blade dédiée déclarée AVANT le catch-all (routes/web.php:62-65).

## 2. Architecture & routing

### 2.1 Web (routes/web.php, 71 lignes)
- `install/*` → InstallerController (web.php:22-34).
- Storefront removal 2026-06-25 : redirects → /login (web.php:37-46).
- `payment/{order}/pay…` gateways front (web.php:47-54).
- `/admin/pos-v4/{any?}` → AdminPosV4Controller (hors périmètre, web.php:62).
- Lockdown bundles kiosk : `/js/kiosk(-admin).js` → 404 (web.php:68-69).
- Catch-all SPA (web.php:71).

### 2.2 API admin (routes/api.php, 1549 lignes)
Deux groupes `/api/admin` avec middlewares `['installed','apiKey','auth:sanctum','block_kiosk_token_admin','localization',throttle]` :
- api.php:281 — bucket `throttle:menu-availability` (toggles rupture item/extra/variation).
- api.php:302 — LE gros groupe `throttle:admin-mutation` : default-access, menu-projection,
  stock (api.php:319-334), `setting/*` (~26 clusters : company 334, site 339, order-setup 344,
  kiosk-setup 349, loyalty 354, mail 359, currency 364, tax 372, item-category 380,
  item-attribute 392, slider 400, branch 408, menu-section/template 418/422, page 430,
  license 438, theme 443, sms/payment-gateway 448/453, notification 458, social-media 463,
  analytic(+section) 468/476, otp 487, role 492, permission 500, cookies 505, time-slot 510,
  language 516, notification-alert 528, kiosk-machine 533), puis users (subscriber 544,
  customer 551, waiter 571, chef 591, employee 624, delivery-boy 644 + cash-sessions 681,
  administrator 1063), coupon 700, offer 711, item 725, ingredients 760
  (`permission:ingredients_manage`), composer 773 (`permission:catalog.compose` 774 /
  `catalog.publish` 796), printers 967, payment-terminals 977, order-history 989,
  push-notification 1055, timezone 1087, dashboard 1091-1112, sales-report 1114,
  items-report 1121, credit-balance-report 1127, cash-sessions-report 1136, cash-overview 1147,
  message 1151, transaction 1166, users 1171, dining-table 1183, observability 1221,
  fiscal/z-report + x-report 1252-1264. (POS/KDS/OSS = hors périmètre.)

RBAC route-level rare (grep `permission:` api.php : lignes 621 lookup mgmt, 760 ingredients,
774/796 composer) — le gros du RBAC est dans les **constructeurs des contrôleurs** (pattern Spatie
`$this->middleware(['permission:x'])->only(...)`).

### 2.3 Frontend (Vue 2, resources/js)
- Router : `resources/js/router/modules/*.js` (38 modules) — ex. historiqueRoutes.js:12
  `/admin/historique`, stockRoutes.js:7 `/admin/stock/rupture`, cashOverviewRoutes.js:11.
- Composants admin : `resources/js/components/admin/{dashboard,items,settings,administrators,
  employees,chefs,waiters,customers,coupons,offers,ingredients,stock,salesReport,itemsReport,
  creditBalanceReport,transactions,orderHistory,onlineOrders,deliveryBoys,deliveryBoyCashSession,
  diningTable,observability,profile,messages,pushNotification,subscribers,tableOrders}` (vu par ls).
- Sidebar : `resources/js/components/layouts/backend/BackendMenuComponent.vue` (367 l).

## 3. Sidebar & RBAC frontend (BackendMenuComponent.vue)

- Menus DB (store `authMenu`) fusionnés avec une bande V1 codée en dur
  `V1_PRIMARY_SIDEBAR_MENUS` (lignes 92-109) : stock/rupture, items(+studio), ingredients,
  pos-orders, historique, encaissement, cash-overview, delivery-boy-cash-sessions.
- Modules V2 masqués via `resources/js/config/v1-hidden-modules.js` :
  `V1_HIDDEN_MENU_MODULES` (customers, coupons, offers, creditBalanceReport, deliveryBoys,
  onlineOrders, tableOrders, waiters, diningTables + ~24 clés `settings.*`) — « restent
  accessibles par URL directe » (commentaire du fichier). `V1_HIDDEN_BACKEND_MENU_URLS=['items']`.
- Children virtuels « Catalogue » injectés sans toucher la table menus :
  `VIRTUAL_CHILDREN_BY_URL` (lignes 85-90) → items/studio + settings/item-attributes/list.
- Filtrage permission : `permissionUrlForSidebarPath()` (lignes 120-140) mappe url→perm Spatie
  (ingredients→ingredients_manage, historique/encaissement→pos-orders, settings/*→settings,
  stock/* et items/*→items). **Fail-open** : `userHasPermissionUrl` retourne `true` si la perm
  est inconnue ou si la liste est vide (lignes 235-248) — cosmétique seulement, le backend gate.
- Shell POS V4 : liens `<a href>` durs au lieu de router-link quand
  `location.pathname.startsWith('/admin/pos-v4')` (ligne 226-228).

## 4. Dashboard

- Contrôleur : `app/Http/Controllers/Admin/DashboardController.php` — 15 endpoints gated
  `permission:dashboard` (lignes 38-54) ; `eodPdf` gated séparément `permission:pos-manage-fiscal`
  (ligne 60, PDF clôture-jour = donnée fiscale).
- Service : `app/Services/DashboardService.php` (858 l). Scoping branche systématique via
  `orderQuery()`/`dashboardBranchId()` (lignes 19-45) : admin=cross-branch, staff=pinned.
- `realtimeReport()` (396) : CA jour net réalisé (`realizedRevenue` scope, exclut annulées-payées,
  remboursements nettés), ticket moyen = CA payé / commandes PAYÉES (fix H03-6, ligne 422).
- `eodSynthesis()` (586) : synthèse comptable read-only pure — « does NOT allocate a fiscal
  sequence, does NOT touch the HMAC chain » (DashboardController.php:207-210) ; miroirs de
  remboursement RETURNED+parent_order_id nettés (lignes 612-629).
- `slaAlerts()` (443) : commandes PREPARING >15 min.
- Composants : dashboard/DashboardComponent.vue + 14 widgets (RealtimeReport, SlaAlerts,
  ChannelStats, AuditTrail, LastZReportWidget, StockLowAlertsWidget…).

## 5. Catalogue (SSOT items DB)

- `app/Http/Controllers/Admin/ItemController.php` (324 l) : perms granulaire items/items_create/
  items_edit/items_delete/items_show (lignes 31-35) ; index/itemDetails/lookupBarcode acceptent
  aussi `pos` via canAny (lignes 36-41).
- Logique load-bearing :
  - `forcePosRuntimeBranchScope()` (290-304) : user `pos` sans `items_show` → branch_id forcé
    depuis le user (403 si branch<1).
  - surface=pos sans branch_id + perm pos → 422 (60-64) — anti tuile faussement dispo.
  - `applyDefaultPosSurfaceForPosRuntimeUser()` (311-323) : caller POS-only reçoit surface=pos
    par défaut (pas de fuite SKUs kiosk-only).
  - Admin branch_id=0 sans branch explicite → fallback première branche active (81-96) pour
    aligner /admin/items avec le dashboard rupture.
  - `itemDetails` filtre visibilité surface pos/kiosk/web → 404 (235-240).
  - `lookupBarcode` (252-288) : barcode exact + is_available + channel pos, log warning doublons.
- **DB vérifiée (tinker cette session)** : 59 items non-supprimés / 48 actifs (status=5) /
  13 catégories — la CONSTITUTION dit « 45 items V1 » : écart à confirmer en W2.
- UI : `items/CatalogStudioComponent.vue` (971 l) = studio catégories+produits+wizard de
  catégorie (drawer composer, data-testid partout) ; ItemComponent/ItemCreate/ItemList/
  AvailabilityToggle ; composer/ (profils wizard), wizard/, addon/extra/variation/.
- Warnings catalogue : `CatalogWarningService` exposé sur show si
  `config('catalog_v15.warnings.expose_to_admin_show')` (ItemController.php:121-126).

## 6. Rapports

- Sales : `SalesReportController.php` — `permission:sales-report` sur index/export/pdf/overview
  (ligne 40, heal REP-AUTHZ-01 : overview était non-gated). Réutilise OrderService::list.
- Items report : ItemsReportController (routes api.php:1121-1126, index/export/pdf).
- Credit balance : CreditBalanceReportController (api.php:1127).
- Cash sessions report (O4) + cash overview (X4) : api.php:1136-1150, perm réutilisée
  pos-manage-fiscal / cash-sessions-report (commentaires routes) — contrôleurs hors périmètre POS
  mais routes vues.
- Fiscal lecture :
  - `Admin/Fiscal/ZReportController.php` : tout gated `pos-manage-fiscal` (authorizeFiscal, 97-102).
    index read-only : admin non-pinné voit les 100 derniers Z toutes branches
    (resolveBranchIdForRead, 125-130) ; open/close/show/pdf exigent user pinné à une branche —
    « never trust a payload-side branch_id » (104-115). open/close throttle:10,1 (api.php:1256-1259).
    pdf = bundle JSON signé + `verifySignature` (79-95).
  - `Admin/Fiscal/XReportController.php` : GET snapshot intraday, même perm + branche pinnée
    obligatoire (24-30).

## 7. Historique unifié & stock

- `OrderHistoryController.php` (98 l) : /admin/historique — read-only sur OrderService::list ;
  authz `can('pos-orders') || can('pos')` (49-53, heal : online-orders retiré) ; show
  `withoutGlobalScope(BranchScope)` puis 403 unifié anti-énumération cross-branch (78-89).
- `StockRuptureDashboardController.php` (642 l) : lastSummary/lowAlerts/catalogOverview gated
  `items_show`, run gated `items_create` (46-47) ; catalogOverview = vue SSOT bulk sans N+1
  (routes api.php:326-331) ; labels groupes extras FR hardcodés (23-36).
- AvailabilityController : toggle item/extra/variation + max-daily-qty (api.php:282-292, 313-315).

## 8. Invariants observés (file:line)

1. Vitrine client désactivée : `/`→`/login` (routes/web.php:43).
2. Tout /api/admin passe `block_kiosk_token_admin` (routes/api.php:281,302) — un token borne ne
   peut pas appeler l'admin.
3. RBAC = constructeurs : dashboard (DashboardController.php:38), eodPdf séparé pos-manage-fiscal
   (:60), items granulaire (ItemController.php:31-35), sales-report incl. overview
   (SalesReportController.php:40), stock items_show/items_create (StockRuptureDashboardController.php:46-47).
4. Fiscal : mutation Z exige user pinné branche, jamais branch_id payload
   (ZReportController.php:104-115) ; EOD PDF = read-only hors chaîne HMAC (DashboardController.php:207-210).
5. Branch scope admin : `authorizeBranchScope` Admin/Tenant Admin bypass, staff = sa branche
   seule (AdminController.php:15-27) ; DashboardService scope toutes les requêtes
   (DashboardService.php:19-45).
6. POS-runtime callers du catalogue : branch forcé + surface=pos par défaut
   (ItemController.php:290-323).
7. CA dashboard = net réalisé (realizedRevenue, refunds nettés) (DashboardService.php:407,612-629).
8. Anti-énumération cross-branch : 403 unifié sur historique show (OrderHistoryController.php:81-89).

## 9. Risques préliminaires (à vérifier W2/W4 — PAS des findings certifiés)

- R1. `ItemController::store` : `if (env('DEMO'))` avec deux branches IDENTIQUES (lignes 137-141)
  — code mort + appel env() hors config (casse avec config:cache).
- R2. Sidebar fail-open : `userHasPermissionUrl` retourne true si perm absente de la liste
  (BackendMenuComponent.vue:243-247) — cosmétique, mais des modules « cachés V1 » restent
  accessibles par URL directe (v1-hidden-modules.js commentaire) → dépend 100% des gates backend ;
  certains contrôleurs settings à vérifier (ThemeController, PageController…).
- R3. `OrderHistoryController` sans middleware constructeur — authz inline abort_unless seulement
  (49-53) ; toute nouvelle méthode ajoutée serait non-gated par défaut.
- R4. Écart SSOT : CONSTITUTION « 45 items » vs DB réelle 59 non-supprimés / 48 actifs /
  13 catégories (tinker vérifié cette session).
- R5. `XReportController` : `Carbon::parse()` direct sur query params from/to (32-33) — pas de
  validation de format (contrairement à eodPdf qui regex la date, DashboardController.php:218).
- R6. Handler générique `catch → response(...,422)` renvoie `$exception->getMessage()` brut au
  client sur ~tous les contrôleurs admin (ex. ItemController.php:106) — fuite potentielle de
  message interne si APP_DEBUG=false ne suffit pas (le message d'exception passe quand même).
- R7. Beaucoup de routes settings mutantes (theme, page, slider, payment-gateway…) reposent sur
  la perm `settings` en constructeur — non lu individuellement ici (couvert par sentinels
  GatewaySecretIndexAuthzSentinelTest, MgmtReadAuthzGateSentinelTest) → W4.

## 10. Couverture de tests (ls vérifiés)

- tests/Feature/Dashboard/ : 8 (DashboardBranchScopeMatrixTest, DashboardRevenueNettingSentinelTest,
  EodPdfRecapSentinelTest, TotalOrdersCountSemanticsTest, SalesSummaryAvgPerDayDivisorSentinelTest,
  AuditTrailUsesAuditLogSentinelTest, ChannelStatisticsMirrorExcludedSentinelTest,
  TotalOrdersRealVolumeSentinelTest).
- tests/Feature/Admin/ : 20+ (StockRuptureDashboardEndpointsTest, StockCatalogOverviewControllerTest,
  AvailabilityControllerTest, MgmtReadAuthzGateSentinelTest, GatewaySecretIndexAuthzSentinelTest,
  AnalyticReadAuthzSentinelTest, LicenseKeyReadAuthzSentinelTest, PermissionControllerIndexAuthzTest…).
- tests/Feature/Catalog/ : 14 (CentralManagementAuthzMatrixTest, ItemDuplicationTest,
  ItemDeletionWithOrderHistoryTest, ItemUpdateInvalidatesKioskCacheSentinelTest…).
- tests/Feature/Fiscal/ : ~50 dont ZReportControllerTest, XReportTest, FiscalPermissionTest,
  ZReportAggregateFilterTest.
- tests/Feature/Reports/ : 4 sentinels (SalesReportFilterParity, SalesReportNetTotal,
  ItemsReportUnitsSold, CreditBalanceCustomersOnly).
- tests/Feature/Menu/ : 18 (AvailabilityToggleAuthzMatrixTest, SetMaxDailyQtyEndpointTest…).
- tests/Feature/Stock/ : 21 ; tests/Feature/Items/ : 3 ; tests/Feature/Settings/ : 1
  (SettingsUpdatedBroadcastTest — couverture settings mince côté Feature/Settings, mais les
  sentinels Admin/ compensent partiellement).

## 11. Questions ouvertes

- Q1. Pourquoi 59 items DB vs « 45 items V1 » CONSTITUTION — items V2/test non purgés ?
- Q2. Les ~24 modules settings masqués mais routables (v1-hidden-modules.js) sont-ils TOUS gated
  backend `permission:settings` ? (à matrix-tester W4.)
- Q3. `menu-projection` (api.php:310) : qui consomme côté central vs POS/kiosk ?
- Q4. `DefaultAccessController` (api.php:303-306) : rôle exact du default-access dans le shell admin.
