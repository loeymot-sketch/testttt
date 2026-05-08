import StockManagerMinimalPage from "../../components/admin/stockManager/StockManagerMinimalPage.vue";

/**
 * [F-016b minimal]
 *
 * Single admin route for the manual rupture stock manager.
 * Reuses `permissionUrl: "items"` so an owner who can already edit
 * items unlocks the page without seeding a new permission row.
 */
export default [
    {
        path: "/admin/stock-manager",
        component: StockManagerMinimalPage,
        name: "admin.stockManager",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "items",
            breadcrumb: "stock_manager",
        },
    },
];
