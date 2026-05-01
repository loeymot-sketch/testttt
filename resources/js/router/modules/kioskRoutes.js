import store from "../../store/index.js";
import { trackLegacyRouteHit } from "../../helpers/kioskAnalytics.js";

// [C4] Lazy-load all kiosk components into a dedicated "kiosk" webpack chunk.
// This keeps the initial app.js lighter for non-kiosk surfaces (admin, POS, KDS, OSS).
// The kiosk chunk is prefetched on the idle screen so navigation feels instant.
const KioskAppComponent          = () => import(/* webpackChunkName: "kiosk-shell" */ "../../components/frontend/kiosk/KioskAppComponent.vue");
const KioskLoginComponent        = () => import(/* webpackChunkName: "kiosk-shell" */ "../../components/frontend/kiosk/KioskLoginComponent.vue");
const KioskIdleScreenComponent   = () => import(/* webpackChunkName: "kiosk-shell" */ "../../components/frontend/kiosk/KioskIdleScreenComponent.vue");
const KioskCategoriesComponent   = () => import(/* webpackChunkName: "kiosk-shell" */ "../../components/frontend/kiosk/KioskCategoriesComponent.vue");
const KioskWizardComponent       = () => import(/* webpackChunkName: "kiosk-wizard" */ "../../components/frontend/kiosk/KioskWizardComponent.vue");
const KioskPosWizardComponent    = () => import(/* webpackChunkName: "kiosk-wizard" */ "../../components/frontend/kiosk/KioskPosWizardComponent.vue");
const KioskCartComponent         = () => import(/* webpackChunkName: "kiosk-shell" */ "../../components/frontend/kiosk/KioskCartComponent.vue");
const KioskLoyaltyComponent      = () => import(/* webpackChunkName: "kiosk-shell" */ "../../components/frontend/kiosk/KioskLoyaltyComponent.vue");
const KioskUpsellComponent       = () => import(/* webpackChunkName: "kiosk-shell" */ "../../components/frontend/kiosk/KioskUpsellComponent.vue");
const KioskPaymentComponent      = () => import(/* webpackChunkName: "kiosk-shell" */ "../../components/frontend/kiosk/KioskPaymentComponent.vue");
const KioskWaitingComponent      = () => import(/* webpackChunkName: "kiosk-shell" */ "../../components/frontend/kiosk/KioskWaitingComponent.vue");
const KioskConfirmationComponent = () => import(/* webpackChunkName: "kiosk-shell" */ "../../components/frontend/kiosk/KioskConfirmationComponent.vue");
// [KIOSK-DS V1 Phase 3] Écrans UX critiques (cash + erreurs globales).
const KioskCashInstructionComponent      = () => import(/* webpackChunkName: "kiosk-shell" */ "../../components/frontend/kiosk/KioskCashInstructionComponent.vue");
const KioskErrorNetworkComponent         = () => import(/* webpackChunkName: "kiosk-errors" */ "../../components/frontend/kiosk/KioskErrorNetworkComponent.vue");
const KioskErrorMenuUnavailableComponent = () => import(/* webpackChunkName: "kiosk-errors" */ "../../components/frontend/kiosk/KioskErrorMenuUnavailableComponent.vue");
const KioskErrorProductRemovedComponent  = () => import(/* webpackChunkName: "kiosk-errors" */ "../../components/frontend/kiosk/KioskErrorProductRemovedComponent.vue");
const KioskErrorPaymentRefusedComponent  = () => import(/* webpackChunkName: "kiosk-errors" */ "../../components/frontend/kiosk/KioskErrorPaymentRefusedComponent.vue");

function getKioskAutoCredentials() {
    if (typeof window === 'undefined') return null;
    // Customer kiosk is locked. Staff maintenance is handled from the caisse/admin,
    // not from the customer kiosk surface.
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
    const proceed = () => {
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
    };

    try {
        if (!store.getters['kioskFilter/hydrated']) {
            store.dispatch('kioskFilter/init').then(proceed).catch(proceed);
            return;
        }
    } catch (_) {
        /* no-op — deep-link / wizard sans Categories ne doit pas bloquer la nav */
    }

    proceed();
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

function firstRouteParam(value) {
    return String(Array.isArray(value) ? value[0] : value || '');
}

function redirectLegacyProductsRoute(to) {
    const categoryId = firstRouteParam(to.params?.categoryId);
    trackLegacyRouteHit({
        from_route: 'kiosk.products',
        target_route: 'kiosk.categories',
        category_id: categoryId,
        query_keys: Object.keys(to.query || {}).sort(),
    });

    return {
        name: 'kiosk.categories',
        query: {
            ...(to.query || {}),
            cat: categoryId,
        },
    };
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
                redirect: redirectLegacyProductsRoute,
                meta: { isKiosk: true },
            },
            {
                path: "wizard/:itemId",
                name: "kiosk.wizard",
                // [STAFF-ONLY-V1][V4] Feature flag : KIOSK_USE_POS_WIZARD=true => wrapper POS (V4.1 déploiera la vraie unification)
                component: () => {
                    const usePosWizard = !!(window.foodkingConfig && window.foodkingConfig.kioskUsePosWizard);
                    return usePosWizard ? KioskPosWizardComponent() : KioskWizardComponent();
                },
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
                redirect: { name: "kiosk.idle" },
                meta: { isKiosk: true },
            },

            /* ============================================================
             * [KIOSK-DS V1 Phase 3] Écrans UX critiques
             * ------------------------------------------------------------
             * Navigables directement (deep-linkable) ET atteignables via
             * les évènements émis par les écrans de commande lorsque les
             * conditions le requièrent (TPE refusé, menu 503, etc.).
             * ============================================================ */
            {
                path: "cash-instruction",
                name: "kiosk.cash-instruction",
                component: KioskCashInstructionComponent,
                meta: { isKiosk: true },
                props: (route) => ({
                    orderNumber: route.query.number || '',
                    orderTotal: route.query.total !== undefined && route.query.total !== ''
                        ? parseFloat(route.query.total)
                        : null,
                    autoRedirectSeconds: route.query.timeout !== undefined
                        ? parseInt(route.query.timeout, 10) || 45
                        : 45,
                }),
            },
            {
                path: "error/network",
                name: "kiosk.error.network",
                component: KioskErrorNetworkComponent,
                meta: { isKiosk: true },
            },
            {
                path: "error/menu-unavailable",
                name: "kiosk.error.menu-unavailable",
                component: KioskErrorMenuUnavailableComponent,
                meta: { isKiosk: true },
            },
            {
                path: "error/product-removed",
                name: "kiosk.error.product-removed",
                component: KioskErrorProductRemovedComponent,
                meta: { isKiosk: true },
                props: (route) => ({
                    productName: route.query.name || null,
                    itemId: route.query.item_id || null,
                }),
            },
            {
                path: "error/payment-refused",
                name: "kiosk.error.payment-refused",
                component: KioskErrorPaymentRefusedComponent,
                meta: { isKiosk: true },
                props: (route) => ({
                    errorCode: route.query.code || null,
                    orderId: route.query.order_id || null,
                }),
            },
        ],
    },
];
