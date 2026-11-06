// [APP HYBRIDE W2a · 2026-11-03] Écran « Vitrine » : bandeaux, offres flash et couleurs de l'application des stores,
// publiés sans nouvelle version de l'application.
//
// `permissionUrl: "settings"` — même porte que l'API (`permission:settings`, StorefrontAdminController) : un écran ouvert
// qui répondrait 403 serait un piège. Permission EXISTANTE, déjà portée par le rôle Admin (aucun seeder à distribuer).
const StorefrontComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/storefront/StorefrontComponent");

export default [
    {
        path: "/admin/storefront",
        component: StorefrontComponent,
        name: "admin.storefront",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "settings",
            breadcrumb: "storefront",
        },
    },
];
