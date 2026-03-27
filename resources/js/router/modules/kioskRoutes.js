import store from "../../store/index.js";
import KioskAppComponent from "../../components/frontend/kiosk/KioskAppComponent.vue";
import KioskLoginComponent from "../../components/frontend/kiosk/KioskLoginComponent.vue";
import KioskIdleScreenComponent from "../../components/frontend/kiosk/KioskIdleScreenComponent.vue";
import KioskCategoriesComponent from "../../components/frontend/kiosk/KioskCategoriesComponent.vue";
import KioskWizardComponent from "../../components/frontend/kiosk/KioskWizardComponent.vue";
import KioskCartComponent from "../../components/frontend/kiosk/KioskCartComponent.vue";
import KioskLoyaltyComponent from "../../components/frontend/kiosk/KioskLoyaltyComponent.vue";
import KioskUpsellComponent from "../../components/frontend/kiosk/KioskUpsellComponent.vue";
import KioskPaymentComponent from "../../components/frontend/kiosk/KioskPaymentComponent.vue";
import KioskWaitingComponent from "../../components/frontend/kiosk/KioskWaitingComponent.vue";
import KioskConfirmationComponent from "../../components/frontend/kiosk/KioskConfirmationComponent.vue";
import KioskAdminComponent from "../../components/frontend/kiosk/KioskAdminComponent.vue";

function getKioskAutoCredentials() {
    if (typeof window === 'undefined') return null;
    const a = window.foodkingConfig?.kioskAutoLogin;
    if (a?.username && a.password !== undefined && a.password !== null && String(a.password) !== '') {
        return { username: String(a.username).trim(), password: String(a.password) };
    }
    return null;
}

/**
 * Guard: redirect to kiosk.login if the machine token is absent.
 * Si window.foodkingConfig.kioskAutoLogin est défini (config/kiosk.php) : login API silencieux.
 */
function requireKioskAuth(to, from, next) {
    if (to.name === 'kiosk.login') return next();
    const token = store.state.kioskCart?.kioskToken;
    if (token) return next();

    const auto = getKioskAutoCredentials();
    if (auto) {
        store
            .dispatch('kioskCart/kioskLogin', auto)
            .then(() => next())
            .catch(() => next({ name: 'kiosk.login', query: { auto_failed: '1' } }));
        return;
    }
    next({ name: 'kiosk.login' });
}

/** Sur /kiosk/login : si auto-login configuré, ne jamais afficher le formulaire. */
function kioskLoginRouteGuard(to, from, next) {
    const auto = getKioskAutoCredentials();
    if (auto) {
        store
            .dispatch('kioskCart/kioskLogin', auto)
            .then(() => next({ name: 'kiosk.idle', replace: true }))
            .catch(() => next());
        return;
    }
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
    const paramId  = to.params.orderId;
    // [AUDIT-P1] Reject navigation if:
    //   - No orderRef in store AND
    //   - No valid orderId param (undefined, "undefined", "null", empty string)
    // This prevents a raw URL like /kiosk/waiting/undefined from loading the waiting screen
    // and polling GET frontend/order/undefined in an infinite loop.
    const isValidParam = paramId && paramId !== 'undefined' && paramId !== 'null' && paramId !== '';
    if (!orderRef && !isValidParam) return next({ name: 'kiosk.idle' });
    next();
}

export default [
    // Standalone login page — outside KioskAppComponent to avoid idle timer
    {
        path: "/kiosk/login",
        name: "kiosk.login",
        component: KioskLoginComponent,
        meta: { isKiosk: true, requiresAuth: false },
        beforeEnter: kioskLoginRouteGuard,
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
                redirect: (to) => ({
                    name: 'kiosk.categories',
                    query: {
                        cat: to.params.categoryId,
                        ...(to.query || {}),
                    },
                }),
                meta: { isKiosk: true },
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
                    orderTotal: route.query.total !== undefined && route.query.total !== '' ? parseFloat(route.query.total) : null, // [AUDIT-P47-BUG6] preserve zero (0 is valid total, not "null")
                }),
            },
            {
                path: "admin",
                name: "kiosk.admin",
                component: KioskAdminComponent,
                meta: { isKiosk: true },
                beforeEnter: requireKioskAuth,
            },
        ],
    },
];
