import store from "../../store/index.js";
import KioskAppComponent from "../../components/frontend/kiosk/KioskAppComponent.vue";
import KioskLoginComponent from "../../components/frontend/kiosk/KioskLoginComponent.vue";
import KioskIdleScreenComponent from "../../components/frontend/kiosk/KioskIdleScreenComponent.vue";
import KioskCategoriesComponent from "../../components/frontend/kiosk/KioskCategoriesComponent.vue";
import KioskProductListComponent from "../../components/frontend/kiosk/KioskProductListComponent.vue";
import KioskWizardComponent from "../../components/frontend/kiosk/KioskWizardComponent.vue";
import KioskCartComponent from "../../components/frontend/kiosk/KioskCartComponent.vue";
import KioskLoyaltyComponent from "../../components/frontend/kiosk/KioskLoyaltyComponent.vue";
import KioskUpsellComponent from "../../components/frontend/kiosk/KioskUpsellComponent.vue";
import KioskPaymentComponent from "../../components/frontend/kiosk/KioskPaymentComponent.vue";
import KioskWaitingComponent from "../../components/frontend/kiosk/KioskWaitingComponent.vue";
import KioskConfirmationComponent from "../../components/frontend/kiosk/KioskConfirmationComponent.vue";

/**
 * Guard: redirect to kiosk.login if the machine token is absent.
 * The login page itself is excluded from this guard.
 */
function requireKioskAuth(to, from, next) {
    if (to.name === 'kiosk.login') return next();
    const token = store.state.kioskCart?.kioskToken;
    if (!token) return next({ name: 'kiosk.login' });
    next();
}

/**
 * Guard: redirect to cart if trying to access payment/loyalty/upsell with empty cart.
 * Redirect to idle if waiting/confirmation without an active orderId.
 */
function requireCart(to, from, next) {
    const isEmpty = store.getters['kioskCart/isEmpty'];
    if (isEmpty) return next({ name: 'kiosk.cart' });
    next();
}

function requireOrderRef(to, from, next) {
    const orderRef = store.state.kioskCart?.orderRef;
    if (!orderRef && !to.params.orderId) return next({ name: 'kiosk.idle' });
    next();
}

export default [
    // Standalone login page — outside KioskAppComponent to avoid idle timer
    {
        path: "/kiosk/login",
        name: "kiosk.login",
        component: KioskLoginComponent,
        meta: { isKiosk: true, requiresAuth: false },
    },
    {
        path: "/kiosk",
        component: KioskAppComponent,
        meta: { isKiosk: true, auth: false },
        beforeEnter: requireKioskAuth,
        children: [
            {
                path: "",
                redirect: { name: "kiosk.idle" },
            },
            {
                path: "idle",
                name: "kiosk.idle",
                component: KioskIdleScreenComponent,
                meta: { isKiosk: true },
            },
            {
                path: "categories",
                name: "kiosk.categories",
                component: KioskCategoriesComponent,
                meta: { isKiosk: true },
            },
            {
                path: "products/:categoryId",
                name: "kiosk.products",
                component: KioskProductListComponent,
                meta: { isKiosk: true },
                props: true,
            },
            {
                path: "wizard/:itemId",
                name: "kiosk.wizard",
                component: KioskWizardComponent,
                meta: { isKiosk: true },
                props: true,
            },
            {
                path: "cart",
                name: "kiosk.cart",
                component: KioskCartComponent,
                meta: { isKiosk: true },
            },
            {
                path: "loyalty",
                name: "kiosk.loyalty",
                component: KioskLoyaltyComponent,
                meta: { isKiosk: true },
                beforeEnter: requireCart,
            },
            {
                path: "upsell",
                name: "kiosk.upsell",
                component: KioskUpsellComponent,
                meta: { isKiosk: true },
                beforeEnter: requireCart,
            },
            {
                path: "payment",
                name: "kiosk.payment",
                component: KioskPaymentComponent,
                meta: { isKiosk: true },
                beforeEnter: requireCart,
            },
            {
                path: "waiting/:orderId",
                name: "kiosk.waiting",
                component: KioskWaitingComponent,
                meta: { isKiosk: true },
                props: true,
                beforeEnter: requireOrderRef,
            },
            {
                path: "confirmation",
                name: "kiosk.confirmation",
                component: KioskConfirmationComponent,
                meta: { isKiosk: true },
                props: (route) => ({
                    orderNumber: route.query.number || '',
                    orderTotal: parseFloat(route.query.total) || null,
                }),
            },
        ],
    },
];
