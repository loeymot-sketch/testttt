// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const TableOrderComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/tableOrders/TableOrderComponent");
const TableOrderListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/tableOrders/TableOrderListComponent");
const TableOrderShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/tableOrders/TableOrderShowComponent");
export default [
    {
        path: '/admin/table-orders',
        component: TableOrderComponent,
        name: 'admin.table.order',
        redirect: {name: 'admin.table.order.list'},
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: 'table-orders',
            breadcrumb: 'table_orders'
        },
        children: [
            {
                path: '',
                component: TableOrderListComponent,
                name: 'admin.table.order.list',
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: 'table-orders',
                    breadcrumb: ''
                },
            },
            {
                path: "show/:id",
                component: TableOrderShowComponent,
                name: "admin.table.order.show",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "table-orders",
                    breadcrumb: "view",
                },
            }
        ]
    }
]
