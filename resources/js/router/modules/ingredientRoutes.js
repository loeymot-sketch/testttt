const IngredientList = () => import('../../components/admin/ingredients/IngredientListComponent.vue');

export default [
    {
        path: '/admin/ingredients',
        name: 'admin.ingredients.list',
        component: IngredientList,
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: 'ingredients_manage',
            breadcrumb: 'ingredients',
        },
    },
    {
        path: '/admin/ingredients/:type(attribute|extra|addon)',
        name: 'admin.ingredients.byType',
        component: IngredientList,
        props: true,
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: 'ingredients_manage',
            breadcrumb: 'ingredients',
        },
    },
];
