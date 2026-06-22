// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const SubscriberComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/subscribers/SubscriberComponent");
const SubscriberListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/subscribers/SubscriberListComponent");
export default [
    {
        path: "/admin/subscribers",
        component: SubscriberComponent,
        name: "admin.subscribers",
        redirect: { name: "admin.subscribers.list" },
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "subscribers",
            breadcrumb: "subscribers",
        },
        children: [
            {
                path: "",
                component: SubscriberListComponent,
                name: "admin.subscribers.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "subscribers",
                    breadcrumb: "",
                },
            },
        ],
    },
];
