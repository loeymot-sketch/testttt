// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const ItemComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/items/ItemComponent");
const ItemListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/items/ItemListComponent");
const ItemShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/items/ItemShowComponent");
const CatalogStudioComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/items/CatalogStudioComponent");
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
                path: 'studio',
                component: CatalogStudioComponent,
                name: 'admin.items.studio',
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: 'items',
                    breadcrumb: 'product_list'
                },
            },
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
            // [CV1-WIZARD-COMPOSABLE-001 T-WC-CREATE-URL-01] Dedicated /admin/items/create
            // entry point. We do not render a distinct Create page: redirect to the list with
            // ?create=1 so the existing ItemCreateComponent drawer (mounted inside the list)
            // opens via mounted() hook in ItemListComponent. Keeps /admin/items/create
            // bookmarkable, share-able, and breadcrumb-traceable while reusing the drawer UX.
            {
                path: 'create',
                component: ItemListComponent,
                name: 'admin.items.create',
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: 'items_create',
                    breadcrumb: 'create',
                },
                beforeEnter: (to, from, next) => {
                    next({ name: 'admin.items.list', query: { create: '1' } });
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
