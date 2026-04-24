// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const ItemComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/items/ItemComponent");
const ItemListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/items/ItemListComponent");
const ItemShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/items/ItemShowComponent");
export default [
    {
        path: '/admin/items',
        component: ItemComponent,
        name: 'admin.items',
        redirect: {name: 'admin.items.list'},
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: 'items',
            breadcrumb: 'items'
        },
        children: [
            {
                path: '',
                component: ItemListComponent,
                name: 'admin.items.list',
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: 'items',
                    breadcrumb: ''
                },
            },
            {
                path: "show/:id",
                component: ItemShowComponent,
                name: "admin.item.show",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "items",
                    breadcrumb: "view",
                },
            }
        ]
    }
]
