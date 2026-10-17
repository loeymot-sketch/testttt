// [CAYENNE HYGIÈNE 2026-10-17 · owner] Relevés de température (frigos positifs, congélateurs négatifs).
//
// `permissionUrl: "pos-orders"` — même gate que les étiquettes et le contrôleur (`pos-orders|pos|kitchen-display-system`) :
// le personnel qui tient la tablette. Une permission neuve ne serait portée par aucun rôle tant qu'un seeder ne l'aurait pas
// distribuée (écran inaccessible à tout le monde, propriétaire compris).
const HygieneTemperatureComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/hygiene/HygieneTemperatureComponent");
const HygieneTasksComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/hygiene/HygieneTasksComponent");
const HygieneReceptionComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/hygiene/HygieneReceptionComponent");
const HygieneTraceabilityComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/hygiene/HygieneTraceabilityComponent");
const HygieneOilComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/hygiene/HygieneOilComponent");
const HygieneReportComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/hygiene/HygieneReportComponent");
const HygieneAlertsComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/hygiene/HygieneAlertsComponent");
const HygieneHomeComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/hygiene/HygieneHomeComponent");

export default [
    {
        path: "/admin/hygiene",
        redirect: "/admin/hygiene/home",
    },
    {
        path: "/admin/hygiene/temperature",
        component: HygieneTemperatureComponent,
        name: "admin.hygiene.temperature",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "hygiene_temperature",
        },
    },
    {
        path: "/admin/hygiene/tasks",
        component: HygieneTasksComponent,
        name: "admin.hygiene.tasks",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "hygiene_tasks",
        },
    },
    {
        path: "/admin/hygiene/reception",
        component: HygieneReceptionComponent,
        name: "admin.hygiene.reception",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "hygiene_reception",
        },
    },
    {
        path: "/admin/hygiene/trace",
        component: HygieneTraceabilityComponent,
        name: "admin.hygiene.trace",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "hygiene_trace",
        },
    },
    {
        path: "/admin/hygiene/oil",
        component: HygieneOilComponent,
        name: "admin.hygiene.oil",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "hygiene_oil",
        },
    },
    {
        path: "/admin/hygiene/report",
        component: HygieneReportComponent,
        name: "admin.hygiene.report",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "hygiene_report",
        },
    },
    {
        path: "/admin/hygiene/home",
        component: HygieneHomeComponent,
        name: "admin.hygiene.home",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "hygiene_home",
        },
    },
    {
        // Réglage des alertes e-mail : la porte du module pour entrer, mais le serveur exige en plus `settings` (le responsable).
        path: "/admin/hygiene/alerts",
        component: HygieneAlertsComponent,
        name: "admin.hygiene.alerts",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "hygiene_alerts",
        },
    },
];
