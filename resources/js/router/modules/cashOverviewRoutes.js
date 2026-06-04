// [Wave X — X4 2026-05-21] Admin unified cash & transactions overview route.
// Owner mandate: « toutes les commandes encaissées (POS direct, borne
// cash-collected, livreur) au MÊME ENDROIT en base ». Sibling to Wave O O4
// /admin/cash-sessions-report. Reuses the `cash-sessions-report` permission
// (Admin + Branch Manager) — see CashOverviewController + RolePermissionTableSeeder.
const CashOverviewComponent = () =>
    import(/* webpackChunkName: "admin-reports" */ "../../components/admin/cashOverview/CashOverviewComponent");

export default [
    {
        path: "/admin/cash-overview",
        component: CashOverviewComponent,
        name: "admin.cash-overview",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "cash-sessions-report",
            breadcrumb: "cash_overview",
        },
    },
];
