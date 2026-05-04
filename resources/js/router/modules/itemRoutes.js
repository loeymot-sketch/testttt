// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const ItemComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/items/ItemComponent.vue");
const ItemListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/items/ItemListComponent.vue");
const ItemShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/items/ItemShowComponent.vue");
const CatalogStudioComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/items/CatalogStudioComponent.vue");
const ProductComposerEditorComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/items/composer/ProductComposerEditorComponent.vue");
const WizardAdvancedLauncherComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/demo/WizardAdvancedLauncherComponent.vue");

export const isWizardPerItemDemoEnabled = () => (
    typeof window !== 'undefined'
    && window.foodkingConfig?.features?.wizard_per_item_demo === true
);

export const requireWizardPerItemDemo = (to, from, next) => {
    if (isWizardPerItemDemoEnabled()) {
        next();
        return;
    }

    next({ name: 'admin.items.studio' });
};

export default [
    {
        path: '/admin/items',
        component: ItemComponent,
        name: 'admin.items',
        redirect: { name: 'admin.items.studio' },
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
                    breadcrumb: 'catalog'
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
    },
    {
        path: '/admin/items/:id/composer',
        component: ProductComposerEditorComponent,
        name: 'admin.items.composer',
        props: (route) => ({ entityType: 'item', entityId: route.params.id }),
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: 'catalog.compose',
            breadcrumb: 'composer',
            entityType: 'item',
            title: 'Wizard avancé (Demo V2)',
            v2Demo: true,
        },
        beforeEnter: requireWizardPerItemDemo,
    },
    {
        path: '/admin/demo/wizard-launcher',
        component: WizardAdvancedLauncherComponent,
        name: 'admin.demo.wizard-launcher',
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: 'catalog.compose',
            breadcrumb: 'wizard_advanced_launcher',
            title: 'Wizard avancé (Demo V2)',
            v2Demo: true,
        },
        beforeEnter: requireWizardPerItemDemo,
    },
    {
        path: '/admin/categories/:id/composer',
        component: ProductComposerEditorComponent,
        name: 'admin.categories.composer',
        props: (route) => ({
            entityType: route.meta.entityType,
            entityId: route.params.id,
        }),
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: 'catalog.compose',
            breadcrumb: 'composer',
            entityType: 'category',
        },
    }
]
