// [Wave O — O4 2026-05-20] Admin daily cash sessions read-only report route.
// Owner request : profil admin doit pouvoir consulter chaque jour les caisses
// (début + fin) et le nombre de transactions. Réutilise la permission
// `pos-manage-fiscal` (Admin + Branch Manager) — cohérent avec Z/X reports.
const CashSessionReportListComponent = () =>
    import(/* webpackChunkName: "admin-reports" */ "../../components/admin/cashSessionReport/CashSessionReportListComponent");

export default [
    {
        path: "/admin/cash-sessions-report",
        component: CashSessionReportListComponent,
        name: "admin.cash-sessions-report",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "cash-sessions-report",
            breadcrumb: "cash_sessions_report",
        },
    },
];
