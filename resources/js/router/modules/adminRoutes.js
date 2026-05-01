const ProductComposerEditorComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/items/composer/ProductComposerEditorComponent");

export default [
    {
        path: '/admin/items/show/:id/composer',
        component: ProductComposerEditorComponent,
        name: 'admin.items.composer',
        props: (route) => ({ itemId: route.params.id }),
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: 'catalog.compose',
            breadcrumb: 'composer',
        },
    },
];
