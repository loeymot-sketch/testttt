// [CV1-V2-CATALOG-VISION-CLEANUP-001 Lot T] Admin stock rupture dashboard (SPA).
const StockRuptureDashboardComponent = () =>
    import(/* webpackChunkName: "admin-shell" */ "../../components/admin/stock/StockRuptureDashboardComponent");

export default [
    {
        path: "/admin/stock/rupture",
        name: "admin.stock.rupture",
        component: StockRuptureDashboardComponent,
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "items",
            breadcrumb: "stock_rupture",
        },
    },
];
