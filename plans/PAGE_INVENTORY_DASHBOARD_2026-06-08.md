# PAGE INVENTORY — Dashboard Admin (provable spine for GOAL_DASHBOARD_E2E_DEEP)
**Date:** 2026-06-08 · **Anchored against LIVE branch** `heal/cms-pr1-quickwins-2026-05-18` (main checkout, NOT the stale worktree).
**Reconciliation sources (3, cross-checked):** (a) sidebar SSOT `resources/js/components/layouts/backend/BackendMenuComponent.vue` (`V1_PRIMARY_SIDEBAR_MENUS:92`, `VIRTUAL_CHILDREN_BY_URL:85`, `MENU_URL_TO_PERMISSION_URL:112`) + DB `authMenu`; (b) 38 router modules `resources/js/router/modules/`; (c) 37 admin component dirs `resources/js/components/admin/`.
**V1 hidden (config `resources/js/config/v1-hidden-modules.js:11`):** `customers, coupons, offers, creditBalanceReport, deliveryBoys, onlineOrders, tableOrders, waiters, diningTables` → NOT in the responsable's sidebar in V1. `V1_HIDDEN_BACKEND_MENU_URLS=['items']` (parent row hidden, virtual Catalogue children shown).

> **Rule:** "redirections / pages qui se redirigent dedans" = BOTH sidebar `children` AND **in-page drill-downs** (table row → `show/:id` detail, tab → sub-view, modal flow, action → confirm). Listed per page below as **Drill-downs**.

---

## Cluster → page map (every dir + every router module accounted for)

### C1 — PILOTAGE / DASHBOARD  `(V1 ACTIVE — the page the responsable opens first)`
| Page | Route | Component dir | Controller | Drill-downs |
|---|---|---|---|---|
| Tableau de bord | `/admin/dashboard` | `dashboard/` | `Admin/DashboardController` (+`DashboardService`) | Accès-rapides chips → POS/encaissement/historique/écran-cuisine/suivi-client/catalogue/ingrédients/cash; "PDF Clôture du jour" → download; SLA alert rows → order; audit-trail rows; channel-split widget |

### C2 — CATALOGUE  `(V1 ACTIVE)`
| Page | Route | Component dir | Controller | Drill-downs |
|---|---|---|---|---|
| Articles (liste) | `/admin/items` | `items/` | `Admin/ItemController` | tabs Produits/Catégories/Offres/Disponibilités; row view/edit/delete; Ajouter; Filtrer; Exporter; Importer; pagination |
| Catalog Studio | `/admin/items/studio` | `items/` (CatalogStudio) | `Admin/ItemController` / CatalogStudio | item composer; variations/extras/supplements; allergens; save/publish |
| Ingrédients | `/admin/ingredients` | `ingredients/` | `Admin/IngredientController` | list; add/edit/delete; stock link |
| Attributs d'articles | `/admin/settings/item-attributes/list` | `settings/ItemAttribute/` | settings cluster | list → show/:id; add/edit/delete; multi-select |
| Catégories | `/admin/settings/item-categories/list` | `settings/ItemCategory/` | settings cluster | list → `show/:id`; add/edit/delete; reorder |
| _coupons, offers_ | — | `coupons/`, `offers/` | `CouponController`, `OfferController(disabled V1)` | **HIDDEN V1** — render-smoke only, not in sidebar |

### C3 — STOCK & DISPONIBILITÉS  `(V1 ACTIVE)`
| Page | Route | Component dir | Controller | Drill-downs |
|---|---|---|---|---|
| Rupture stock dashboard | `/admin/stock/rupture` | `stock/` | `Admin/Stock*` (AvailabilityService) | per-item availability toggles; 86400/branch filters; mark rupture/restock |
| Stock (gestion) | `/admin/stock` | `stock/` | `Admin/Stock*` | levels; movements; adjust |

### C4 — COMMANDES  `(V1 ACTIVE — management/reading views)`
| Page | Route | Component dir | Controller | Drill-downs |
|---|---|---|---|---|
| Commandes caisse | `/admin/pos-orders` | `posOrders/` | `Admin/PosOrderController` | row → order detail; filters; reprint; status |
| Historique (unifié) | `/admin/historique` | `orderHistory/` | `Admin/OrderHistoryController` | row → detail; date/channel filters; export; reprint receipt |
| POS orders tracker | `/admin/pos-orders-tracker` | `posOrders/` | `PosOrderController` | live tracker; status drill |
| _online-orders, table-orders_ | — | `onlineOrders/`, `tableOrders/` | `OnlineOrderController`, `TableOrderController` | **HIDDEN V1 / dine-in dormant** — render-smoke only |

### C5 — CAISSE & ENCAISSEMENT  `(V1 ACTIVE — money management surfaces; operational POS = CAISSE lane, ref only)`
| Page | Route | Component dir | Controller | Drill-downs |
|---|---|---|---|---|
| Encaissement (unifié) | `/admin/encaissement` | `encaissement/` | (pos-orders perm) | collect modal per order; method Espèces/TR/Terminal-manuel; confirm |
| Vue caisse (overview) | `/admin/cash-overview` | `cashOverview/` | `Admin/CashOverviewController` | session cards → detail; open/close; movements |
| Rapport caisse quotidien | `/admin/cash-session-report` | `cashSessionReport/` | `Admin/CashSessionReportController` | session → report; Z-link; export |
| Caisse livreur | `/admin/delivery-boy-cash-sessions` | `deliveryBoyCashSession/` | `Admin/DeliveryBoyCashSession*` | session → detail; reconcile |
| _credit-balance-report_ | — | `creditBalanceReport/` | `CreditBalanceReportController` | **HIDDEN V1** — render-smoke |

### C6 — RAPPORTS & ANALYTICS  `(V1 ACTIVE)`
| Page | Route | Component dir | Controller | Drill-downs |
|---|---|---|---|---|
| Rapport des ventes | `/admin/sales-report` | `salesReport/` | `Admin/SalesReportController` | date range; channel; export; chart drill |
| Rapport articles | `/admin/items-report` | `itemsReport/` | `Admin/ItemsReportController` | top items; date; export |
| Transactions | `/admin/transactions` | `transactions/` | `Admin/TransactionController` | row → detail; filters; export |
| Observabilité | `/admin/observability` | `observability/` | `Admin/Observability/*` | SLI/SLO panels; outbox; health (perm-gated) |
| Analytics (settings) | `/admin/settings/analytics/list` | `settings/analytics/` | settings | list → `show/:id` |

### C7 — UTILISATEURS & RBAC  `(V1 ACTIVE)`
| Page | Route | Component dir | Controller | Drill-downs |
|---|---|---|---|---|
| Administrateurs | `/admin/administrators` | `administrators/` | `Admin/AdministratorController` | list; add/edit/delete; role assign |
| Employés | `/admin/employees` | `employees/` | `Admin/EmployeeController` | list; add/edit/delete |
| Chefs | `/admin/chefs` | `chefs/` | `Admin/ChefController` | list; add/edit/delete |
| Rôles (settings) | `/admin/settings/roles/list` | `settings/Role/` | settings/Role | list → show/:id; permission matrix |
| _customers, delivery-boys, waiters_ | — | `customers/`,`deliveryBoys/`,`waiters/` | resp. controllers | **HIDDEN V1** — render-smoke |

### C8 — COMMUNICATIONS  `(V1 ACTIVE — ⚠ external-side-effect heavy)`
| Page | Route | Component dir | Controller | Drill-downs |
|---|---|---|---|---|
| Messages | `/admin/messages` | `messages/` | `Admin/MessageController` | thread → detail; reply (⚠ may send) |
| Notifications push | `/admin/push-notification` | `pushNotification/` | `Admin/PushNotificationController` | compose; **Send (⚠ EXTERNAL — `SendFcmNotificationJob`)** |
| Abonnés | `/admin/subscribers` | `subscribers/` | `Admin/SubscriberController` | list; export; **notify (⚠ EXTERNAL)** |

### C9 — RÉGLAGES (Settings — ~28 sub-pages, button-heavy, ⚠ external + destructive)  `(V1 ACTIVE)`
Root `/admin/settings` (`settings/SettingsComponent.vue` + `settings/MenuComponent.vue`). Sub-pages (verified `settingRoutes.js` + `settings/` dirs):
`company, site, branches(list→show/:id), mail(⚠ test-send), order-setup, kiosk-setup, loyalty-setup, otp(⚠ sms), notification(⚠), notification-alert, social-media, cookies, analytics(list→show/:id), theme, time-slots, sliders(list→show/:id), currencies(list), tax, item-categories(list→show/:id), item-attributes(list→show/:id), languages, license, payment-gateway(⚠ secrets), payment-terminals, sms-gateway(⚠ test-send), kiosk-machines, pages`.
**Drill-downs:** each entity = list → `show/:id` → edit form → save; many have toggles + secret fields + test-send buttons.

### C10 — PROFIL & SHELL DE NAVIGATION  `(V1 ACTIVE — the responsable's frame)`
| Surface | Route/source | Component | Notes |
|---|---|---|---|
| Profil | `/admin/profile` | `profile/` | `Admin/ProfileController` — edit, password change |
| Sidebar nav | (shell) | `layouts/backend/BackendMenuComponent.vue` | menu render + RBAC gating + collapse; every link target must render |
| Header / topbar | (shell) | `layouts/backend/*` | branch switch, language, logout, notifications bell |
| Launch-points (ref only) | `/admin/pos`,`/kds`,`/admin/order-status-screen`,`suivi-client` | pos/kds/oss | **FROZEN/other-lane** — render-smoke + link to separate audits, NOT deep-audited here |

---

## Reconciliation check (no orphan)
- **37 admin dirs:** all mapped above. `components/`=shared widgets (audited as cross-cutting in C10 shell). `demo/`=non-prod demo (excluded, flagged). `pos/`+`posOrders/`→C4/C5 ref; `kitchenDisplaySystem/`+`orderStatusScreen/`→C10 launch-ref (separate audit). `diningTable/`,`tableOrders/`→hidden dormant. ✅
- **38 router modules:** all mapped (`adminRoutes`=shell, `authRoutes`=login/forgot (audited as entry), `frontendRoutes`/`customerRoutes`/`kioskRoutes`=customer/borne lanes NOT dashboard → excluded with flag). ✅
- **V1-hidden (9 modules):** listed render-smoke-only, not primary. ✅
- **Holes:** none. Any page rendered at execution-time NOT in this list = a discovered finding (inventory gap) to append.

**Counts (V1-active dashboard pages to deep-audit):** ~C1:1, C2:5, C3:2, C4:3, C5:4, C6:5, C7:4, C8:3, C9:~27, C10:4 = **~58 active pages** + ~12 hidden render-smoke. Provable; revised against runtime DOM at execution.
