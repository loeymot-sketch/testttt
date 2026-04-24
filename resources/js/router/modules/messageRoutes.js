// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const MessageComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/messages/MessageComponent");
const MessageListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/messages/MessageListComponent");
export default [
    {
        path: "/admin/messages",
        component: MessageComponent,
        name: "admin.messages",
        redirect: { name: "admin.messages.list" },
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "messages",
            breadcrumb: "messages",
        },
        children: [
            {
                path: "",
                component: MessageListComponent,
                name: "admin.messages.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "messages",
                    breadcrumb: "",
                },
            },
        ],
    },
];
