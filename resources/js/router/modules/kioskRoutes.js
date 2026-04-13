import store from "../../store/index.js";

// [C4] Lazy-load all kiosk components into a dedicated "kiosk" webpack chunk.
// This keeps the initial app.js lighter for non-kiosk surfaces (admin, POS, KDS, OSS).
// The kiosk chunk is prefetched on the idle screen so navigation feels instant.
const KioskAppComponent          = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskAppComponent.vue");
const KioskLoginComponent        = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskLoginComponent.vue");
const KioskIdleScreenComponent   = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskIdleScreenComponent.vue");
const KioskCategoriesComponent   = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskCategoriesComponent.vue");
const KioskWizardComponent       = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskWizardComponent.vue");
const KioskCartComponent         = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskCartComponent.vue");
const KioskLoyaltyComponent      = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskLoyaltyComponent.vue");
const KioskUpsellComponent       = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskUpsellComponent.vue");
const KioskPaymentComponent      = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskPaymentComponent.vue");
const KioskWaitingComponent      = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskWaitingComponent.vue");
const KioskConfirmationComponent = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskConfirmationComponent.vue");
const KioskAdminComponent        = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskAdminComponent.vue");

function getKioskAutoCredentials() {
    if (typeof window === 'undefined') return null;
    // [C5] Maintenance mode: staff activated via KioskAdminComponent — suspend auto-login
    // until the page is reloaded (sessionStorage is cleared on tab/browser close).
    try {
        if (sessionStorage.getItem('kiosk_maintenance_mode') === '1') return null;
    } catch (_) { /* ignore if sessionStorage unavailable */ }
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
            .catch(() => next({ name: 'kiosk.login' }));
        return;
    }
    next({ name: 'kiosk.login' });
}

/**
 * Sur /kiosk/login : on laisse le composant gérer l'initialisation automatique.
 * Il n'affiche plus de formulaire de saisie borne ; seulement un écran de retry/diagnostic.
 */
function kioskLoginRouteGuard(to, from, next) {
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
    const isValidParam = /^(offline_)?\d+$/.test(String(paramId || '').trim());
    if (!orderRef && !isValidParam) return next({ name: 'kiosk.idle' });
    next();
}

function requireConfirmationContext(to, from, next) {
    const orderRef = store.state.kioskCart?.orderRef;
    if (!orderRef) {
        return next({ name: 'kiosk.idle' });
    }
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
                // Clé stable du router-view (KioskApp) : ne pas refaire slide-left à chaque ?cat=
                meta: { isKiosk: true, kioskStableShell: true },
            },
            {
                path: "products/:categoryId",
                name: "kiosk.products",
                // Legacy deep-link kept for backward compatibility; the active catalogue
                // surface is `kiosk.categories` with query-driven category selection.
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
                beforeEnter: requireConfirmationContext,
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
