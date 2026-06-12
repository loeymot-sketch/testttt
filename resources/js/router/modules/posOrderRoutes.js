// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const PosOrderComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/posOrders/PosOrderComponent");
const PosOrderListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/posOrders/PosOrderListComponent");
const PosOrderShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/posOrders/PosOrderShowComponent");
// [POS-V4-ORDERS-TRACKER 2026-05-02] Lazy-load tracker dans le chunk pos-shell pour
// éviter de gonfler app.js — il est consommé uniquement depuis l'écran caisse.
const PosOrdersTrackerComponent = () => import(/* webpackChunkName: "pos-shell" */ "../../components/admin/pos/PosOrdersTrackerComponent");
export default [
    {
        path: "/admin/pos-orders",
        component: PosOrderComponent,
        name: "admin.pos-orders",
        // [GOAL 2026-06-12] le nom cible était « admin.pos.orders.list » (points),
        // route inexistante → Vue Router avalait l'erreur → « Commandes Caisse »
        // rendait une page BLANCHE sans erreur console. Sentinel :
        // tests/js/routerRedirectIntegrity.spec.js
        redirect: { name: "admin.pos-orders.list" },
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: 'pos-orders',
            breadcrumb: 'pos_orders'
        },
        children: [
            {
                path: "",
                component: PosOrderListComponent,
                name: "admin.pos-orders.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "pos-orders",
                    breadcrumb: "",
                },
            },
            {
                path: "show/:id",
                component: PosOrderShowComponent,
                name: "admin.pos-orders.show",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "pos-orders",
                    breadcrumb: "view",
                },
            }
        ],
    },
    {
        // [POS-V4-ORDERS-TRACKER 2026-05-02] Écran caisse plein écran : kanban suivi
        // commandes en cours (ACCEPT / PREPARING / PREPARED / DELIVERED), live Echo,
        // filtres source. Distinct de l'OSS client (admin.order-status-screen).
        path: "/admin/pos-orders-tracker",
        component: PosOrdersTrackerComponent,
        name: "admin.pos-orders.tracker",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "pos_orders_tracker",
        },
    },
];
