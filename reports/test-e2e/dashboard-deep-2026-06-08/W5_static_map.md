# Wave 5 — Static Code-Map (Rapports/Analytics + Users/RBAC) — LIVE branch
9/9 surfaces mapped, 40+ controls, 0 dead controls, RBAC gated everywhere.

## Surfaces → endpoints (all OK)
1. SALES-REPORT (`permission:sales-report`): index/overview/filters(status/paid/payment_type/delivery/source/date)/export-xls/pdf. Rows use server `*_price` + `paymentTypeEnumArray` (correct FR pattern).
2. ITEMS-REPORT (`permission:items-report`): index/filters/export/pdf. (No "top-items" control — not present, not a defect.)
3. TRANSACTIONS (`permission:transactions`): index/filters(transaction_no/order_serial/payment_method/date)/export-xls/pagination.
4. OBSERVABILITY/OUTBOX (`role:Admin|Tenant Admin`): outboxOverview load; **retry-failed** (re-dispatch DispatchDomainEventsJob) + **drain-failed** (delete failed_jobs>24h) = EXTERNAL live-fire mutating, throttled 10/5-min.
5. SETTINGS/ANALYTIC (`permission:settings`, route `setting/analytic` singular): list/save/show/destroy. AnalyticService pure DB, DEMO-gated; GA snippet fires only at storefront render — no external side-effect from admin CRUD.
6. ADMINISTRATORS (RBAC administrators/_create/_edit/_delete/_show): list/save/destroy/show/changePassword/changeImage/myOrders. Role = hardcoded ADMIN (no user-chosen role).
7. EMPLOYEES (RBAC employees/*): list/save/destroy/changePassword/changeImage. **role_id select privilege-escalation guarded** (`blockRoles`:29 + `callerMayGrantRole`:82/105/145).
8. CHEFS (RBAC chefs/*): list/save/destroy/changePassword/changeImage/export. Role hardcoded CHEF.
9. SETTINGS/ROLES (`permission:settings`): role list/save/destroy + **permission matrix load (`PermissionController@index`) + save (`@update`)**.

## FINDINGS
- [P2] Transactions raw enum + unformatted amount — `TransactionListComponent.vue:103/110/113` + `TransactionResource.php:24-25` (`strtoupper($payment_method)` raw + `flatAmountFormat` = `number_format(.,'.','')` → bare `12.50`, no €) vs FR-canonical `currencyAmountFormat`/`formatPrice`. **DEDUPS with W2-P2 (cross-confirmed, deeper root cause).**
- [P3] Observability outbox retry-failed/drain-failed = live-fire mutating — `OutboxOverviewComponent.vue:376/385` → `SyncOverviewController.php:387/437`. Role-gated Admin|Tenant + throttled. QA must NOT fire vs operating; flag.

Counts: P0=0 P1=0 P2=1(dedup W2) P3=1. Dead controls: 0. RBAC: 9/9. External live-fire: 1 (outbox, gated). No welcome-mail/SMS on user-create.
