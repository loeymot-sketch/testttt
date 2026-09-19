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

// [ONB-08 2026-08-28] Declarer ses matieres premieres.
//
// Le domaine n'exposait que `movements` (lecture) et `adjust` (correction de
// quantite) : les seules sources de creation etaient un seeder et une commande
// console. Un nouveau commercant ne pouvait declarer AUCUN ingredient.
//
// ⚠️ Le commentaire de `RawMaterialAdjustComponent` ci-dessus affirme etre « la
// seule porte d'ecriture manquante du domaine matiere premiere ». C'etait faux :
// la declaration en etait une autre, et elle manquait depuis plus longtemps.
const RawMaterialListComponent = () =>
    import(/* webpackChunkName: "admin-shell" */ "../../components/admin/stock/RawMaterialListComponent");

export default [
    {
        path: "/admin/stock/matieres",
        name: "admin.stock.raw-materials",
        component: RawMaterialListComponent,
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "items",
            breadcrumb: "raw_materials_title",
        },
    },
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
