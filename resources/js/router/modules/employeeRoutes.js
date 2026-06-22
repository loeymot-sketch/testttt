// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const EmployeeComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/employees/EmployeeComponent");
const EmployeeListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/employees/EmployeeListComponent");
const EmployeeShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/employees/EmployeeShowComponent");
const EmployeeOrderDetailsComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/employees/EmployeeOrderDetailsComponent");
export default [
    {
        path: "/admin/employees",
        component: EmployeeComponent,
        name: "admin.employees",
        redirect: {name: "admin.employees.list"},
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "employees",
            breadcrumb: "employees",
        },
        children: [
            {
                path: "",
                component: EmployeeListComponent,
                name: "admin.employees.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "employees",
                    breadcrumb: "",
                },
            },
            {
                path: "show/:id",
                component: EmployeeShowComponent,
                name: "admin.employees.show",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "employees",
                    breadcrumb: "view",
                },
            },
            {
                path: "show/:id/:orderId",
                component: EmployeeOrderDetailsComponent,
                name: "admin.employees.order.details",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "employees",
                    breadcrumb: "order_details",
                },
            },
        ],
    },
];
