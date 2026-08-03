// [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3c] Écran admin de scan de facture.
// Lazy-load dans le chunk admin-shell (pattern itemRoutes/stockRoutes). Gate
// `items_create` (même famille que le scan stock). Domaine ADDITIF, HORS NF525.
const PurchaseScanComponent = () =>
    import(/* webpackChunkName: "admin-shell" */ "../../components/admin/purchasing/PurchaseScanComponent.vue");

export default [
    {
        path: "/admin/purchasing/scan",
        name: "admin.purchasing.scan",
        component: PurchaseScanComponent,
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "items_create",
            breadcrumb: "purchasing_scan",
        },
    },
];
