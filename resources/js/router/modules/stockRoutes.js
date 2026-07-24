// [CV1-V2-CATALOG-VISION-CLEANUP-001 Lot T] Admin stock rupture dashboard (SPA).
const StockRuptureDashboardComponent = () =>
    import(/* webpackChunkName: "admin-shell" */ "../../components/admin/stock/StockRuptureDashboardComponent");

// [PHASE 3d-UI — VUE CONSO & STOCK UNIFIÉE 2026-07-24] Écran lecture seule :
// matières premières + boissons revendues + « à acheter » (SSOT UnifiedStockViewService).
const UnifiedStockViewComponent = () =>
    import(/* webpackChunkName: "admin-shell" */ "../../components/admin/stock/UnifiedStockViewComponent");

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
    {
        path: "/admin/stock/unified",
        name: "admin.stock.unified",
        component: UnifiedStockViewComponent,
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "items",
            breadcrumb: "stock_unified",
        },
    },
];
