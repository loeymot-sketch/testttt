// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const OnlineOrderComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/onlineOrders/OnlineOrderComponent");
const OnlineOrderListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/onlineOrders/OnlineOrderListComponent");
const OnlineOrderShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/onlineOrders/OnlineOrderShowComponent");
export default [
    {
        path: '/admin/online-orders',
        component: OnlineOrderComponent,
        name: 'admin.order',
        redirect: {name: 'admin.order.list'},
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: 'online-orders',
            breadcrumb: 'online_orders'
        },
        children: [
            {
                path: '',
                component: OnlineOrderListComponent,
                name: 'admin.order.list',
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: 'online-orders',
                    breadcrumb: ''
                },
            },
            {
                path: "show/:id",
                component: OnlineOrderShowComponent,
                name: "admin.order.show",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "online-orders",
                    breadcrumb: "view",
                },
            }
        ]
    }
]
