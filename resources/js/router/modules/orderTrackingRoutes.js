// [T-C SUIVI-CLIENT 2026-08-16 · GOAL owner] Page publique de suivi de commande,
// ouverte depuis le téléphone du client via le lien/QR remis à la borne.
// Volontairement SANS meta.auth ni meta.isFrontend=true : le guard STAFF_ONLY
// (resources/js/router/index.js) ne bloque que les routes isFrontend=true, et
// le guard auth ne bloque que meta.auth=true — cette route tombe dans le
// dernier `else { next() }`, publique dans tous les modes, comme kiosk mais
// sans porter le comportement kiosk (idle timer, ensureKioskLocale, etc.)
// qui ne s'applique pas à un client sur son propre téléphone.
const OrderTrackingPageComponent = () => import(/* webpackChunkName: "order-tracking" */ "../../components/frontend/tracking/OrderTrackingPageComponent.vue");

export default [
    {
        path: "/suivi/:trackingToken",
        name: "order.tracking.public",
        component: OrderTrackingPageComponent,
        props: true,
        // meta.isTracking → DefaultComponent.vue theme="tracking" : router-view nu, sans
        // AUCUN habillage (ni sidebar admin si l'onglet a une session staff active, ni
        // navbar vitrine/table, ni kiosk-shell verrouillé). La page compose 100% son layout.
        meta: { isTracking: true },
    },
];
