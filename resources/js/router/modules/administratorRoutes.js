// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const AdministratorComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/administrators/AdministratorComponent");
const AdministratorListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/administrators/AdministratorListComponent");
const AdministratorShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/administrators/AdministratorShowComponent");
const AdministratorOrderDetailsComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/administrators/AdministratorOrderDetailsComponent");
export default [
    {
        path: "/admin/administrators",
        component: AdministratorComponent,
        name: "admin.administrators",
        redirect: {name: "admin.administrators.list"},
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "administrators",
            breadcrumb: "administrators",
        },
        children: [
            {
                path: "",
                component: AdministratorListComponent,
                name: "admin.administrators.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "administrators",
                    breadcrumb: "",
                }
            },
            {
                path: "show/:id",
                component: AdministratorShowComponent,
                name: "admin.administrators.show",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "administrators",
                    breadcrumb: "view",
                }
            },
            {
                path: "show/:id/:orderId",
                component: AdministratorOrderDetailsComponent,
                name: "admin.administrators.order.details",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "administrators",
                    breadcrumb: "order_details",
                },
            },
        ],
    },
];
