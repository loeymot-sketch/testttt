// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const DiningTableListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/diningTable/DiningTableListComponent");
const DiningTableComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/diningTable/DiningTableComponent");
const DiningTableShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/diningTable/DiningTableShowComponent");
export default [
    {
        path: "/admin/dining-tables",
        component: DiningTableComponent,
        name: "admin.diningTable",
        redirect: { name: "admin.diningTable.list" },
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "dining-tables",
            breadcrumb: "dining_tables",
        },
        children: [
            {
                path: "list",
                component: DiningTableListComponent,
                name: "admin.diningTable.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "dining-tables",
                    breadcrumb: "",
                },
            },
            {
                path: "show/:id",
                component: DiningTableShowComponent,
                name: "admin.diningTable.show",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "dining-tables",
                    breadcrumb: "view",
                },
            },
        ],
    },
]
