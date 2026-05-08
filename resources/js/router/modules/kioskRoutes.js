import store from "../../store/index.js";

// [P0-5 / KIO-1] Mandatory boot healthcheck — gates tpeCharge() against
// phantom transactions when Electron bridge is present but TPE drivers
// failed silent init. The performBootHealthcheck() result is cached at
// module level via a singleton promise so we run it ONCE per kiosk
// session, then short-circuit on every subsequent /kiosk navigation.
//
// Permissive in non-bridge environments (browser dev / staging) — see
// kioskHardware.requireHealthCheck() for the runtime gate semantics.
import kioskHardware from "../../services/kioskHardware";
import { performBootHealthcheck } from "../../services/kioskBootHealthcheck";

let _bootHealthcheckPromise = null;
function ensureBootHealthcheck() {
    if (!kioskHardware.isKioskBridge()) {
        // Browser/dev/tests : never block, never run the healthcheck. The
        // tpeCharge gate is permissive in this mode — safe to fall through.
        return Promise.resolve({ ok: true, stub: true });
    }
    // If the runtime gate has been opened by a prior successful run (e.g.
    // the overlay's retry succeeded), the cached promise is stale — drop
    // it so subsequent navigations short-circuit on the *current* state.
    if (_bootHealthcheckPromise && kioskHardware.isBootHealthcheckPassed()) {
        return Promise.resolve({ ok: true, cached: true });
    }
    // If the gate is closed but we held a previous failed promise, rerun
    // a fresh check (operator may have power-cycled the TPE between nav
    // events). We only memoize the in-flight promise to avoid concurrent
    // duplicate calls during a single navigation burst.
    if (!_bootHealthcheckPromise || (_bootHealthcheckPromise.__settled && !kioskHardware.isBootHealthcheckPassed())) {
        const p = performBootHealthcheck().catch(() => ({
            ok: false,
            critical_failures: ['boot_healthcheck_runtime_error'],
        }));
        // Tag the promise so we can detect "already settled, rerun" above.
        p.then(() => { p.__settled = true; }, () => { p.__settled = true; });
        _bootHealthcheckPromise = p;
    }
    return _bootHealthcheckPromise;
}

// [C4] Lazy-load all kiosk components into a dedicated "kiosk" webpack chunk.
// This keeps the initial app.js lighter for non-kiosk surfaces (admin, POS, KDS, OSS).
// The kiosk chunk is prefetched on the idle screen so navigation feels instant.
const KioskAppComponent          = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskAppComponent.vue");
const KioskLoginComponent        = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskLoginComponent.vue");
const KioskIdleScreenComponent   = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskIdleScreenComponent.vue");
const KioskCategoriesComponent   = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskCategoriesComponent.vue");
const KioskWizardComponent       = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskWizardComponent.vue");
const KioskPosWizardComponent    = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskPosWizardComponent.vue");
const KioskCartComponent         = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskCartComponent.vue");
const KioskLoyaltyComponent      = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskLoyaltyComponent.vue");
const KioskUpsellComponent       = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskUpsellComponent.vue");
const KioskPaymentComponent      = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskPaymentComponent.vue");
const KioskWaitingComponent      = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskWaitingComponent.vue");
const KioskConfirmationComponent = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskConfirmationComponent.vue");
const KioskAdminComponent        = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskAdminComponent.vue");
// [KIOSK-DS V1 Phase 3] Écrans UX critiques (cash + erreurs globales).
const KioskCashInstructionComponent      = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskCashInstructionComponent.vue");
const KioskErrorNetworkComponent         = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskErrorNetworkComponent.vue");
const KioskErrorMenuUnavailableComponent = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskErrorMenuUnavailableComponent.vue");
const KioskErrorProductRemovedComponent  = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskErrorProductRemovedComponent.vue");
const KioskErrorPaymentRefusedComponent  = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskErrorPaymentRefusedComponent.vue");
// [P0-5 / KIO-1] Boot healthcheck overlay — surfaced when bridge present
// but a critical sub-check (TPE/backend) fails. UX retry + staff alert.
const KioskBootHealthcheckOverlay = () => import(/* webpackChunkName: "kiosk" */ "../../components/frontend/kiosk/KioskBootHealthcheckOverlay.vue");

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
 *
 * [P0-5 / KIO-1] Wraps the original auth check with a mandatory boot
 * healthcheck when the Electron bridge is present. The healthcheck is
 * cached as a singleton promise (run once per kiosk session) so this
 * adds zero overhead on subsequent navigations. If the healthcheck
 * critical-fails (e.g. TPE unresponsive), the user is sent to the
 * /kiosk/boot-failure overlay instead of the auth flow — preventing
 * any payment session from opening on a broken kiosk.
 */
function requireKioskAuth(to, from, next) {
    if (to.name === 'kiosk.login' || to.name === 'kiosk.boot-failure') return next();

    // [P0-5] Run the boot healthcheck once per session. Permissive in
    // non-bridge mode (Promise resolves to {ok:true,stub:true}) so dev
    // browsers and Playwright runs are unaffected.
    ensureBootHealthcheck().then((hc) => {
        if (hc && hc.ok === false) {
            return next({
                name: 'kiosk.boot-failure',
                query: { reason: (hc.critical_failures || []).join(',') || 'unknown' },
            });
        }

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
    }).catch(() => {
        // performBootHealthcheck never throws by contract, but if anything
        // truly catastrophic happens (e.g. ESM loader fault), don't trap
        // the user — fall through to the original auth flow rather than
        // hard-block the kiosk.
        const token = store.state.kioskCart?.kioskToken;
        if (token) return next();
        next({ name: 'kiosk.login' });
    });
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
    /*
     * [P0-5 / KIO-1] Boot healthcheck failure surface.
     * Reached when `requireKioskAuth` detects critical sub-check failures
     * (TPE unresponsive, backend unreachable, hardware healthcheck threw).
     * The overlay re-runs `performBootHealthcheck()` on its mounted hook
     * AND on the "Réessayer" button — so a transient backend hiccup can
     * recover without operator intervention.
     */
    {
        path: "/kiosk/boot-failure",
        name: "kiosk.boot-failure",
        component: KioskBootHealthcheckOverlay,
        meta: { isKiosk: true, requiresAuth: false },
        // On `ready` (gate opened), fall through to the kiosk root which
        // will re-run requireKioskAuth and short-circuit on the cached
        // singleton promise — no double network hit.
        // The component emits `ready` and `contact-staff` — see overlay.
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
                component: KioskAdminComponent,
                meta: { isKiosk: true },
                beforeEnter: requireKioskAuth,
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
