// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const PushNotificationComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/pushNotification/PushNotificationComponent");
const PushNotificationListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/pushNotification/PushNotificationListComponent");
const PushNotificationShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/pushNotification/PushNotificationShowComponent");
export default [
    {
        path: '/admin/push-notifications',
        component: PushNotificationComponent,
        name: 'admin.push-notifications',
        redirect: { name: 'admin.push-notification.list' },
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: 'push-notifications',
            breadcrumb: 'push_notifications'
        },
        children: [
            {
                path: '',
                component: PushNotificationListComponent,
                name: 'admin.push-notification.list',
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: 'push-notifications',
                    breadcrumb: ''
                },
            },
            {
                path: "show/:id",
                component: PushNotificationShowComponent,
                name: "admin.push-notification.show",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "push-notifications",
                    breadcrumb: "view",
                },
            },
        ]
    }
]
