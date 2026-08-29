// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-oss".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const OrderStatusScreenComponent = () => import(/* webpackChunkName: "admin-oss" */ "../../components/admin/orderStatusScreen/OrderStatusScreenComponent");
export default [
    {
        path: "/admin/order-status-screen",
        alias: "/order-status-screen",
        component: OrderStatusScreenComponent,
        name: "admin.order-status-screen",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "order-status-screen",
            // [AUDIT-SUPERVISEUR 2026-08-25 · E-005] Cet écran est un MUR TOURNÉ VERS LA
            // SALLE : le client y lit son numéro de commande. Sans ce drapeau, la route
            // tombait dans le `else` de DefaultComponent → theme "backend", qui monte la
            // navbar et le menu d'admin. Mesuré dans le DOM capturé du mur : « Déconnexion »
            // et l'adresse du compte d'administration connecté — affichées au-dessus de la
            // tête des clients, la sortie de session à un clic.
            //
            // [AUDIT-COMPTA 2026-08-29] L'adresse était citée en toutes lettres ici ; la
            // sentinelle `AucunIdentifiantEnDurDansLeFrontTest` l'a relevée, à juste titre :
            // ce fichier part dans `public/js/*.js`, servi à tout visiteur. Décrire le
            // défaut suffit, le reproduire ne servait à rien.
            isWall: true,
        },
    },
];
