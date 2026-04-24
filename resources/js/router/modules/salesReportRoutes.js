// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-reports".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const SalesReportComponent = () => import(/* webpackChunkName: "admin-reports" */ "../../components/admin/salesReport/SalesReportComponent");
const SalesReportListComponent = () => import(/* webpackChunkName: "admin-reports" */ "../../components/admin/salesReport/SalesReportListComponent");
export default [
    {
        path: "/admin/sales-report",
        component: SalesReportComponent,
        name: "admin.sales-report",
        redirect: { name: "admin.sales-report.list" },
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "sales-report",
            breadcrumb: "sales_report",
        },
        children: [
            {
                path: "",
                component: SalesReportListComponent,
                name: "admin.sales-report.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "sales-report",
                    breadcrumb: "",
                },
            },
        ],
    },
];
