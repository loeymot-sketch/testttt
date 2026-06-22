// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const CustomerComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/customers/CustomerComponent");
const CustomerListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/customers/CustomerListComponent");
const CustomerShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/customers/CustomerShowComponent");
const CustomerOrderDetailsComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/customers/CustomerOrderDetailsComponent");
export default [
    {
        path: "/admin/customers",
        component: CustomerComponent,
        name: "admin.customers",
        redirect: {name: "admin.customers.list"},
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "customers",
            breadcrumb: "customers",
        },
        children: [
            {
                path: "",
                component: CustomerListComponent,
                name: "admin.customers.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "customers",
                    breadcrumb: "",
                }
            },
            {
                path: "show/:id",
                component: CustomerShowComponent,
                name: "admin.customers.show",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "customers",
                    breadcrumb: "view",
                }
            },
            {
                path: "show/:id/:orderId",
                component: CustomerOrderDetailsComponent,
                name: "admin.customers.order.details",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "customers",
                    breadcrumb: "order_details",
                }
            },
        ],
    },
];
