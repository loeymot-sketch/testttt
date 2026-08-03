// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const ChefComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/chefs/ChefComponent.vue");
const ChefListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/chefs/ChefListComponent.vue");
const ChefShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/chefs/ChefShowComponent.vue");
const ChefOrderDetailsComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/chefs/ChefOrderDetailsComponent.vue");
export default [
    {
        path: "/admin/chefs",
        component: ChefComponent,
        name: "admin.chefs",
        redirect: {name: "admin.chefs.list"},
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "chefs",
            breadcrumb: "chefs",
        },
        children: [
            {
                path: "",
                component: ChefListComponent,
                name: "admin.chefs.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "chefs",
                    breadcrumb: "",
                }
            },
            {
                path: "show/:id",
                component: ChefShowComponent,
                name: "admin.chefs.show",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "chefs",
                    breadcrumb: "view",
                }
            },
            {
                path: "show/:id/:orderId",
                component: ChefOrderDetailsComponent,
                name: "admin.chefs.order.details",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "chefs",
                    breadcrumb: "order_details",
                }
            },
        ],
    },
];
