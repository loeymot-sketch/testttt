// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-oss".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const OrderStatusScreenComponent = () => import(/* webpackChunkName: "admin-oss" */ "../../components/admin/orderStatusScreen/OrderStatusScreenComponent");
export default [
    {
        path: "/admin/order-status-screen",
        alias: "/order-status-screen",
        component: OrderStatusScreenComponent,
        name: "admin.order-status-screen",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "order-status-screen",
        },
    },
];
