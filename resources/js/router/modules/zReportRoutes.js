// [HEAL dispute-r3 B-R1-16 2026-06-12] Page lecture-seule des clôtures Z —
// cible honnête du widget dashboard « Voir les clôtures Z » qui atterrissait
// sur /admin/transactions (0 Z affiché, B-R1-16 P1). API consommée :
// GET /api/admin/fiscal/z-report (POS-9.4.9, permission backend
// pos-manage-fiscal — Admin + Branch Manager). permissionUrl 'transactions'
// = même gate frontend que le widget (LastZReportWidget.canFetchReports) ;
// le backend reste l'autorité finale (403 → état indisponible propre).
const ZReportListComponent = () =>
    import(/* webpackChunkName: "admin-reports" */ "../../components/admin/fiscal/ZReportListComponent");

export default [
    {
        path: "/admin/z-reports",
        component: ZReportListComponent,
        name: "admin.zReports.list",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "transactions",
            breadcrumb: "z_reports",
        },
    },
];
