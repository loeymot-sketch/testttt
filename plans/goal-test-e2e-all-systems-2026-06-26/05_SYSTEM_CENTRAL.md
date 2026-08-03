# 05 — SYSTÈME CENTRAL (gestion/admin) — plan test-e2e abusif

**Contract** : back-office commerçant — catalogue, dashboard, rapports, utilisateurs,
réglages, stock, coupons, historique. Lentille = 🧑‍💼 **COMMERÇANT** (« fait-il
confiance aux chiffres ? un employé peut-il abuser ses droits ? »). **Peut tourner
en parallèle de la vague WEB+APP** (arbres disjoints). Lit surtout le SHARED.

**Shared (lecture)** : Dashboard polls ~60s, `realizedRevenue` (scope Order),
`LastZReportWidget` lit `z_reports`, `StockLowAlertsWidget` lit StockLevel ;
gates RBAC Spatie (`permission:settings` + par-ressource), BranchScope (admin
branch_id=0 bypass / staff scoped), middleware `block_kiosk_token_admin`.

**Anchors (vérifiés)** : `app/Http/Controllers/Admin/**` (~100, subdirs Fiscal/,
Observability/) sauf POS/KDS ; `DashboardService` (totalSales:351/totalOrders:366),
`AppLibrary.php:289-437` (money format) ; front `components/admin/**` (37 dirs) ;
sidebar `layouts/backend/BackendMenuComponent.vue` ; tests `tests/Feature/
{Dashboard,Reports,Catalog,Coupon,Items,Stock,Ingredients,OrderHistory,Admin,Settings}`.

---

## INVENTAIRE PAGES/SURFACES (extrait — 27 router modules)

| Route | Composant | Rôle |
|---|---|---|
| `/admin/dashboard` | `dashboard/DashboardComponent.vue` + 14 widgets | KPIs, OrderStats, SalesSummary, **LastZReportWidget**, **StockLowAlerts**, TopCustomers |
| `/admin/items`→studio, `/create`, `/show/:id` | `items/CatalogStudioComponent` + List/Create/Show | CRUD produits |
| `/admin/items/:id/composer`, `/admin/categories/:id/composer` | `items/composer/*` | composer profile (viandes/options) |
| `/admin/ingredients/:type(attribute\|extra\|addon)` | `ingredients/*` | ingrédients + dispo toggle |
| `/admin/stock/rupture` | `stock/StockRuptureDashboardComponent.vue` | alertes rupture |
| `/admin/coupons/`, `/show/:id` | `coupons/*` | CRUD coupons |
| `/admin/offers/` | `offers/*` | offres (**DÉSACTIVÉ V1**, sentinel) |
| `/admin/sales-report/`, `/admin/items-report/` | `salesReport/*`, `itemsReport/*` | rapports ventes + export/pdf |
| `/admin/transactions`, `/admin/historique/`, `/admin/online-orders/` | `transactions/*`, `orderHistory/*`, `onlineOrders/*` | transactions, historique, online |
| `/admin/{administrators,employees,chefs,waiters,customers,delivery-boys}` | dossiers respectifs | CRUD users par rôle |
| `/admin/dining-tables/`, `/admin/delivery-boy-cash-sessions/`, `/admin/credit-balance-report` | resp. | tables QR, caisse livreur, crédit |
| `/admin/messages/`, `/push-notifications/`, `/subscribers/` | resp. | messagerie/push |
| `/admin/observability/outbox` | `observability/*` | outbox sync (retry/drain) |
| `/admin/settings` (+~30 sous-routes : company/site/branches/mail/order-setup/kiosk-setup/loyalty-setup/otp/notification/theme/taxes/role/payment-gateway/payment-terminals/**license**/kiosk-machines…) | `settings/*` + `settings/MenuComponent.vue` | réglages (~26 contrôleurs, gate `permission:settings` sur `update`) |
| `/admin/profile/*` | `profile/*` | profil admin |

---

## DÉCOMPOSITION (4 sous-systèmes)

### Sub 5.a — Dashboard + KPIs + Rapports
- T-5.a.1 Audit KPIs dashboard — chiffres NETS cohérents (exclut miroirs refund `parent_order_id`, `DashboardService:62/351/366`).
- T-5.a.2 Audit sales-report net/export — revenu réalisé == Z.
- T-5.a.3 Audit items-report — unités vendues excluent refunds.
- T-5.a.4 Audit avg_per_day divisor (off-by-one borne).
- T-5.a.5 Audit cohérence format prix dashboard (virgule FR) vs report (point) — 2 écrans comparés.
**Acceptance** : `Dashboard/` (DashboardRevenueNettingSentinel, TotalOrdersCountSemantics, SalesSummaryAvgPerDayDivisorSentinel, ChannelStatisticsMirrorExcludedSentinel, DashboardBranchScopeMatrix), `Reports/` (SalesReportNetTotalSentinel, ItemsReportUnitsSoldSentinel) PASS + *(À CRÉER `tests/Feature/Admin/TransactionsOverviewReconciliationTest.php`)*.

### Sub 5.b — Catalogue + Composer + Stock
- T-5.b.1 Audit item CRUD + variations/extras — prix≥0, snapshot protège commande en cours (NF525).
- T-5.b.2 Audit composer profile — viandes MIN/MAX.
- T-5.b.3 Audit ingredient availability — toggle propage cache kiosk/pos.
- T-5.b.4 Audit stock rupture — pas d'oversell/négatif.
**Acceptance** : `Catalog/` (CentralManagementAuthzMatrix, ItemDeletionWithOrderHistory, ItemUpdateInvalidatesKioskCacheSentinel, ComposerSchema, AddonRolePersistence), `Stock/` (~22), `Ingredients/` (4) PASS.

### Sub 5.c — Utilisateurs + RBAC + Settings
- T-5.c.1 Audit user CRUD par rôle — gates create/edit/delete/show.
- T-5.c.2 Audit settings ~26 contrôleurs — `permission:settings` sur `update`.
- T-5.c.3 Audit RBAC drift — POS Operator ne voit/édite PAS settings/users/exports.
- T-5.c.4 Audit license/gateway secrets — pas de fuite read.
**Acceptance** : `Admin/` (MgmtReadAuthzGateSentinel, GatewaySecretIndexAuthzSentinel, PermissionControllerIndexAuthz, EmployeeRequestAuthorize, StaffServicesOwnBranchScope), `CentralManagementAuthzMatrixTest` PASS + *(À CRÉER `tests/Feature/Admin/LicenseKeyReadAuthzSentinelTest.php`)*.

### Sub 5.d — Historique + Commandes + Coupons/Offers
- T-5.d.1 Audit order-history — cross-branch 403, snapshot integrity.
- T-5.d.2 Audit coupon CRUD — cap/cumul/min≥discount.
- T-5.d.3 Audit offer — désactivé V1 (POST→403 pas 404), prix négatif refusé.
**Acceptance** : `OrderHistory/` (5), `Coupon/` (CouponCrud, CouponMaxUsesGlobalEnforcement, CouponCheckNegativeTotal), `OffersDisabledV1SentinelTest` PASS.

---

## GERMES ADVERSAIRES (🧑‍💼 COMMERÇANT)
- **Confiance aux chiffres** : Total Commandes/Sales comptent-ils miroirs refund/cancelled-paid ? (heal DASH-SEM-03 — abuser : vente+refund → dashboard net ~0 ET report==Z) ; avg off-by-one ; format prix incohérent entre 2 écrans ; Z reflète order →PAID hors séquence fiscale (FISCAL-CPS) → orphelin invisible.
- **Catalogue/stock** : éditer prix/variation pendant commande en cours → snapshot frozen protège (vérifier order garde l'ancien) ; variation/extra prix négatif accepté ? ; stock négatif/oversell burst ; toggle ingredient ne propage pas cache kiosk.
- **RBAC/fuite** : **POS Operator voit-il settings/users/exports ?** ; **license_key NON gaté** (`LicenseController::index` sans `permission:settings`, route `api.php:431` auth seul → tout staff admin lit la clé) ; secret FCM `notification_fcm_api_key` (`SettingResource:66`) read-gate ? ; **user-enumeration** (`/admin/customers` emails) ; export CSV full-fetch sans scope.
- **Coupons/offers/historique** : coupon réutilisation > cap, `min_order < discount` bloqué, offer POST malgré `offers_enabled=false` → 403 attendu, order-history staff branche B lisant branche A.

---

## PIÈGES & DÉFAUTS CONNUS (file:line)
1. **Money en-US / arrondi entier** — `AppLibrary.php:308-316` `flatAmountFormat` (env() null post `config:cache` → entier) **DÉJÀ HEAL 2026-06-25** `?? 2` ; vérifier non-régression (`currencyAmountFormatPrecision:409`).
2. **license_key read-gate manquant (P2)** — `LicenseController.php:20` `index` sans `->only`/permission ; `api.php:431-433` auth seul → asymétrie read/write. *(À CRÉER sentinel.)*
3. **Secret FCM** — `SettingResource.php:66` ; `CompanyController:19` gate seulement `update`.
4. **User-enumeration** — `/admin/customers`/`/administrators` emails ; `MgmtReadAuthzGateSentinel` couvre l'authz pas la fuite email à operator légitime.
5. **Glyphes `lab-*`** — widgets dashboard (`OrderStatisticsComponent.vue:19-123`) → boutons vides si fonte manque. Vérifier visuellement.
6. **Offers V1 disabled** — `OffersDisabledV1SentinelTest` : create → 403 (feature guard) pas 404.
7. **FormRequest return-true baseline** — `FormRequestAuthzDriftSentinelTest` (baseline 69, observé 66 ; CLAUDE.md §9).
