// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const DeliveryBoyComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/deliveryBoys/DeliveryBoyComponent");
const DeliveryBoyListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/deliveryBoys/DeliveryBoyListComponent");
const DeliveryBoyShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/deliveryBoys/DeliveryBoyShowComponent");
const DeliveryBoyOrderDetailsComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/deliveryBoys/DeliveryBoyOrderDetailsComponent");
const DeliveredOrderShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/deliveryBoys/deliveredOrder/DeliveredOrderShowComponent");
export default [
    {
        path: "/admin/delivery-boys",
        component: DeliveryBoyComponent,
        name: "admin.delivery-boys",
        redirect: { name: "admin.delivery-boys.list" },
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "delivery-boys",
            breadcrumb: "delivery_boys",
        },
        children: [
            {
                path: "",
                component: DeliveryBoyListComponent,
                name: "admin.delivery-boys.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "delivery-boys",
                    breadcrumb: "",
                },
            },
            {
                path: "show/:id",
                component: DeliveryBoyShowComponent,
                name: "admin.delivery-boys.show",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "delivery-boys",
                    breadcrumb: "view",
                },
            },
            {
                path: "show/:id/:orderId",
                component: DeliveryBoyOrderDetailsComponent,
                name: "admin.delivery-boys.order.details",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "delivery-boys",
                    breadcrumb: "order_details",
                },
            },
            {
                path: "delivered-order/show/:id/:orderId",
                component: DeliveredOrderShowComponent,
                name: "admin.delivery-boys.delivered-order.details",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "delivery-boys",
                    breadcrumb: "delivered_order_details",
                },
            },
        ],
    },
];
