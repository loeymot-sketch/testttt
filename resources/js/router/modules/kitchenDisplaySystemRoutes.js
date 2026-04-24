// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-kds".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const KitchenDisplaySystemComponent = () => import(/* webpackChunkName: "admin-kds" */ "../../components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent");
export default [
    {
        path: "/admin/kitchen-display-system",
        component: KitchenDisplaySystemComponent,
        name: "admin.kitchen-display-system",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "kitchen-display-system",
        },
    },
];
