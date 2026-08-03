// [GOAL-CAISSE-UNIFIED W-ENC 2026-05-30] Unified collection page route.
// One /admin/encaissement queue: every order awaiting cash/card collection
// (Borne PENDING_COUNTER today; walk-in joins once delta-(B) routes POS
// walk-in through PENDING_COUNTER). Reuses the existing
// admin/pos/counter-collect/pending endpoint + PosCounterCollectModal.
// Lazy-loaded into the "admin-shell" chunk (same as posOrderRoutes.js).
const EncaissementComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/encaissement/EncaissementComponent");

export default [
    {
        path: "/admin/encaissement",
        component: EncaissementComponent,
        name: "admin.encaissement",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "encaissement",
        },
    },
];
