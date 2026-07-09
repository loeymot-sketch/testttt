# Wave 2 — Static Code-Map (Dashboard / Sidebar / Profile)
Anchored live branch `heal/cms-pr1-quickwins` @ ad29e7875. Every row grep/Read-confirmed.

## Path corrections (vs GOAL/inventory — fold into final)
- Profile = `app/Http/Controllers/Frontend/ProfileController.php` (NOT Admin/) — routes api.php:60,249-253; pure `$user->save()`, **no mail/notif/event**.
- `v1-hidden-modules.js` = `resources/js/config/v1-hidden-modules.js`; `MENU_URL_TO_PERMISSION_URL:112`.
- DashboardService = `app/Services/DashboardService.php`.

## Dashboard controls → endpoints (all OK, no dead control)
15 read methods gated `permission:dashboard` (DashboardController.php:38-60); `eodPdf` gated `permission:pos-manage-fiscal`. All 13 KPI/widget axios calls resolve (total-sales/orders/menu-items/order-stats/sales-summary/order-summary/featured/popular/realtime/sla/channel/audit-trail + last-Z). 13 quick-access chips = `<router-link>` all resolve. **EOD PDF = read-only stream (no fiscal alloc, no audit write) — safe to fire.** No EXTERNAL side-effect anywhere on dashboard.
- Orphaned-but-valid (vision-filtered, not dead): CustomerStats/TopCustomers/StockLowAlerts components exist but not mounted; their routes resolve (customers is V1-hidden).

## Sidebar nav
V1-visible menu URLs + perm gates mapped (dashboard/pos/stock-rupture/items+studio/ingredients/pos-orders/historique/encaissement/cash-overview/delivery-cash/pos-orders-tracker + DB role menus). Pure `<router-link>` nav, no axios mutation, no EXTERNAL. V1-hidden 28-key filter via `hiddenMenuUrls` (BackendMenuComponent.vue:164).

## Profile
Edit + change-password + change-image → `Frontend\ProfileController` @update:33/@changePassword:42/@changeImage:52. No `permission:` (self-service, FormRequest authz). No external side-effect.

## FINDINGS
- [P2] Z-report widget RBAC mismatch — `LastZReportWidget.vue:85` client-gates `transactions`, server `ZReportController@index:24`→`authorizeFiscal()` requires `pos-manage-fiscal` → dangling control for transactions-but-not-fiscal role. Admin(0) holds all → low live impact.
- [P3] Stock-low widget RBAC mismatch — `StockLowAlertsWidget.vue:82` client `items`, server `StockRuptureDashboardController.php:46` requires `items_show`. Widget not mounted on dashboard → currently unreachable.
- [P3] Sidebar gate fail-open — `BackendMenuComponent.vue:240-247` over-shows links on empty/missing perms; every route server-enforced → UX dead-end only, not security.

Counts: P0=0 P1=0 P2=1 P3=2. No dead backend, no live-fire side-effects.
