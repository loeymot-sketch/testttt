// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const PosOrderComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/posOrders/PosOrderComponent");
const PosOrderListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/posOrders/PosOrderListComponent");
const PosOrderShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/posOrders/PosOrderShowComponent");
export default [
    {
        path: "/admin/pos-orders",
        component: PosOrderComponent,
        name: "admin.pos-orders",
        redirect: { name: "admin.pos.orders.list" },
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
];
