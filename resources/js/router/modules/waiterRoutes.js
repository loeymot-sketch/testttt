// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const WaiterComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/waiters/WaiterComponent.vue");
const WaiterListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/waiters/WaiterListComponent.vue");
const WaiterShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/waiters/WaiterShowComponent.vue");
const WaiterOrderDetailsComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/waiters/WaiterOrderDetailsComponent.vue");
export default [
    {
        path: "/admin/waiters",
        component: WaiterComponent,
        name: "admin.waiters",
        redirect: {name: "admin.waiters.list"},
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "waiters",
            breadcrumb: "waiters",
        },
        children: [
            {
                path: "",
                component: WaiterListComponent,
                name: "admin.waiters.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "waiters",
                    breadcrumb: "",
                }
            },
            {
                path: "show/:id",
                component: WaiterShowComponent,
                name: "admin.waiters.show",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "waiters",
                    breadcrumb: "view",
                }
            },
            {
                path: "show/:id/:orderId",
                component: WaiterOrderDetailsComponent,
                name: "admin.waiters.order.details",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "waiters",
                    breadcrumb: "order_details",
                }
            },
        ],
    },
];
