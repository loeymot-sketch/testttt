// [ETIQUETTES 2026-10-16 · owner] Imprimante d'étiquettes Brother QL-820NWB : étiquettes d'hygiène (DLC),
// étiquettes par produit de commande, réglages de l'imprimante.
//
// `permissionUrl: "pos-orders"` — on réutilise une permission EXISTANTE plutôt que d'en créer une nouvelle :
// une permission inédite n'est portée par aucun rôle en base tant qu'un seeder ne l'a pas distribuée, l'écran
// serait donc inaccessible à tout le monde, propriétaire compris. Même raisonnement (et même porte) que l'écran
// « Uber photo » et que le contrôleur (`permission:pos-orders|pos|kitchen-display-system`).
const LabelOrdersComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/labels/LabelOrdersComponent");
const LabelDlcComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/labels/LabelDlcComponent");
const LabelPrinterSettingsComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/labels/LabelPrinterSettingsComponent");

export default [
    {
        path: "/admin/labels",
        redirect: "/admin/labels/orders",
    },
    {
        path: "/admin/labels/orders",
        component: LabelOrdersComponent,
        name: "admin.labels.orders",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "labels_orders",
        },
    },
    {
        path: "/admin/labels/dlc",
        component: LabelDlcComponent,
        name: "admin.labels.dlc",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "labels_dlc",
        },
    },
    {
        path: "/admin/labels/printer",
        component: LabelPrinterSettingsComponent,
        name: "admin.labels.printer",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "labels_printer",
        },
    },
];
