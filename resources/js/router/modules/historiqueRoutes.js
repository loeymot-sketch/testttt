// [GOAL-CAISSE-UNIFIED W-HIST 2026-05-30] Unified order history route.
// One page /admin/historique listing EVERY order (Borne / Caisse / walk-in /
// delivery / online) with an origin badge + NF525 columns (fiscal_seq, refund
// link). Lazy-loaded into the "admin-shell" chunk, same pattern as
// posOrderRoutes.js. Detail reuses the existing admin.pos-orders.show page
// (PosOrderController::show is order-type-agnostic) — no new show component.
const HistoriqueComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/orderHistory/HistoriqueComponent");
const HistoriqueListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/orderHistory/HistoriqueListComponent");

export default [
    {
        path: "/admin/historique",
        component: HistoriqueComponent,
        name: "admin.historique",
        redirect: { name: "admin.historique.list" },
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "historique",
        },
        children: [
            {
                path: "",
                component: HistoriqueListComponent,
                name: "admin.historique.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "pos-orders",
                    breadcrumb: "",
                },
            },
        ],
    },
];
