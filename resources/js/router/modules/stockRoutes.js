// [CV1-V2-CATALOG-VISION-CLEANUP-001 Lot T] Admin stock rupture dashboard (SPA).
const StockRuptureDashboardComponent = () =>
    import(/* webpackChunkName: "admin-shell" */ "../../components/admin/stock/StockRuptureDashboardComponent");

// [PHASE 3d-UI — VUE CONSO & STOCK UNIFIÉE 2026-07-24] Écran lecture seule :
// matières premières + boissons revendues + « à acheter » (SSOT UnifiedStockViewService).
const UnifiedStockViewComponent = () =>
    import(/* webpackChunkName: "admin-shell" */ "../../components/admin/stock/UnifiedStockViewComponent");

// [GOAL_CAYENNE_FINITION_2026-08-13 / §6 Vague 5] Ajustement inventaire manuel
// (casse / vol / pesée fausse) — la seule porte d'écriture manquante du domaine
// matière première (RawMaterialStockService::adjust() existait, testée, sans
// appelant avant cette vague).
const RawMaterialAdjustComponent = () =>
    import(/* webpackChunkName: "admin-shell" */ "../../components/admin/stock/RawMaterialAdjustComponent");

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
    {
        path: "/admin/stock/raw-material-adjust",
        name: "admin.stock.raw-material-adjust",
        component: RawMaterialAdjustComponent,
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "items",
            breadcrumb: "raw_material_adjust",
        },
    },
];
