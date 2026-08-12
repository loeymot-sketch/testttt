// [UBER-PHOTO 2026-08-10 · owner] Écran tablette : photographier un ticket Uber et l'envoyer
// en cuisine.
//
// `permissionUrl: "pos-orders"` — on réutilise une permission EXISTANTE plutôt que d'en créer
// une nouvelle : une permission inédite n'est portée par aucun rôle en base tant qu'un seeder
// ne l'a pas distribuée, l'écran serait donc inaccessible à tout le monde, propriétaire compris.
// Même raisonnement (et même porte) que le contrôleur : `permission:pos-orders|pos`.
const UberPhotoCaptureComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/uber/UberPhotoCaptureComponent");

export default [
    {
        path: "/admin/uber-photo",
        component: UberPhotoCaptureComponent,
        name: "admin.uberPhoto",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "uber_photo",
        },
    },
];
