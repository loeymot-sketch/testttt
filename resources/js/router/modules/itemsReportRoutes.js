// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-reports".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const ItemsReportComponent = () => import(/* webpackChunkName: "admin-reports" */ "../../components/admin/itemsReport/ItemsReportComponent");
const ItemsReportListComponent = () => import(/* webpackChunkName: "admin-reports" */ "../../components/admin/itemsReport/ItemsReportListComponent");
export default [
    {
        path: "/admin/items-report",
        component: ItemsReportComponent,
        name: "admin.items-report",
        redirect: { name: "admin.items-report.list" },
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "items-report",
            breadcrumb: "items_report",
        },
        children: [
            {
                path: "",
                component: ItemsReportListComponent,
                name: "admin.items-report.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "items-report",
                    breadcrumb: "",
                },
            },
        ],
    },
];
