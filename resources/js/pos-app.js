/**
 * [POS-V4 W2 #1 2026-04-26] Dedicated POS Vue entry-point.
 *
 * Slim variant of `app.js` — see ADR_POS_V4_DEDICATED_ENTRY.md.
 *
 * Goal: serve the POS surface (URL `/admin/pos-v4`) from a Blade view that
 * does NOT load `app.js` (456 KB gz), only `pos-app.js` (target ~250 KB gz).
 *
 * Reuses the same `bootstrap.js` (Echo + axios + lodash), `store/`, `i18n.js`
 * — token, Vuex state, translations are persisted in localStorage and survive
 * across the two entry-points. Auth is performed via the legacy app on
 * `/login`; this entry assumes the user already has a valid token.
 *
 * Skipped vs `app.js` (saves ~80 KB gz):
 *   - vue3-apexcharts + apexcharts (admin reports only)
 *   - admin-only form widgets not used by POS
 *   - vue3-simple-alert (admin confirmations only)
 *   - KioskDesignSystem atoms + tokens CSS (kiosk only)
 *   - Full router with 30 modules (only POS routes here)
 *
 * Legacy `/admin/pos` and `app.js` are NOT modified. Rollback: delete this
 * file + admin-pos-v4.blade.php + AdminPosV4Controller + revert webpack.mix.js
 * + routes/web.php.
 */
import './bootstrap';

import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import axios from 'axios';

import DefaultComponent from './components/DefaultComponent';
import store from './store';
import i18n from './i18n';
import Toast from 'vue-toastification';
import 'vue-toastification/dist/index.css';
import VueNextSelect from 'vue-next-select';
import 'vue-next-select/dist/index.css';

// [POS-V4 W2 #1 FIX D.3] Shared request interceptor + token reader.
// Pre-fix this block was duplicated in app.js L52-L146 and labeled "frozen
// mirror" — in reality the 401 RESPONSE handler diverges (Vue Router push vs
// window.location). Extracting the identical parts removes the false safety
// signal: each entry now declares its own 401 handler explicitly below.
import { applySharedAxiosDefaults } from './shared/axios-setup';
import { installBlobErrorNormalizer } from './shared/blob-error';
import { installInFlightGetDedupe } from './shared/inflight-dedupe';
applySharedAxiosDefaults(axios, store);
// [GOAL-OPS-SWAP W1 2026-08-12] Jumeau de app.js — installé ici AUSSI, et pas
// « plus tard » : une entrée corrigée et l'autre pas, c'est la divergence
// programmée. Voir resources/js/shared/blob-error.js pour le défaut mesuré.
installBlobErrorNormalizer(axios);
// [GOAL-OPS-SWAP W3 2026-08-12] Jumeau de app.js — la caisse V4 partage
// exactement le même défaut de rafale à l'ouverture. Voir shared/inflight-dedupe.js.
installInFlightGetDedupe(axios);

// 401 RESPONSE handler — POS-only variant.
// Divergence vs app.js intentional: pos-app.js has no `auth.login` named route
// (it only knows /admin/pos-v4 + /floorplan), so we hard-redirect to the legacy
// Blade /login which is rendered by master.blade.php + app.js. There is no
// kiosk auto-login branch here because /admin/pos-v4 is staff-authenticated
// surface only. See app.js L97-L146 for the kiosk-aware variant used elsewhere.
let _401Handling = false;
axios.interceptors.response.use(
    response => response,
    error => {
        const status = error?.response?.status;
        if (status !== 401) return Promise.reject(error);
        if (!_401Handling) {
            _401Handling = true;
            setTimeout(() => { _401Handling = false; }, 3000);
            store.dispatch('logout').catch(() => {});
            window.location.href = '/login';
        }
        return Promise.reject(error);
    },
);

// POS-only router. Three routes: POS, floorplan, login fallback.
// Lazy chunks reuse the existing `pos-shell` chunk (same `webpackChunkName`).
const PosComponent = () =>
    import(/* webpackChunkName: "pos-shell" */ './components/admin/pos/PosComponent');
const FloorplanComponent = () =>
    import(/* webpackChunkName: "pos-shell" */ './components/admin/pos/FloorplanComponent');

const router = createRouter({
    history: createWebHistory(),
    linkActiveClass: 'active',
    routes: [
        {
            path: '/admin/pos-v4',
            component: PosComponent,
            name: 'admin.pos.v4',
            meta: { isFrontend: false, auth: true, permissionUrl: 'pos' },
        },
        {
            path: '/admin/pos-v4/voice-assistant',
            component: PosComponent,
            name: 'admin.pos.v4.voice-assistant',
            meta: { isFrontend: false, auth: true, permissionUrl: 'pos', voiceAssistant: true },
        },
        {
            path: '/admin/pos-v4/floorplan',
            component: FloorplanComponent,
            name: 'admin.pos.v4.floorplan',
            meta: { isFrontend: false, auth: true, permissionUrl: 'pos', breadcrumb: 'floorplan' },
        },
        // Compatibility names used by the shared backend layout. These prevent
        // Vue Router resolve errors in the slim POS entry without pulling the
        // full admin/frontend route graph into pos-app.js.
        { path: '/', name: 'frontend.home', redirect: { name: 'admin.pos.v4' } },
        { path: '/admin/dashboard', name: 'admin.dashboard', redirect: { name: 'admin.pos.v4' } },
        { path: '/admin/pos', name: 'admin.pos', redirect: { name: 'admin.pos.v4' } },
        { path: '/admin/pos/floorplan', name: 'admin.pos.floorplan', redirect: { name: 'admin.pos.v4.floorplan' } },
        { path: '/admin/profile/edit-profile', name: 'admin.profile.editProfile', redirect: { name: 'admin.pos.v4' } },
        { path: '/admin/profile/change-password', name: 'admin.profile.changePassword', redirect: { name: 'admin.pos.v4' } },
        // [rush-sync WB-R1-01 heal 2026-05-13] Compatibility stubs for route
        // names referenced by PosComponent.vue (tracker, customer screen,
        // pos-orders list/show). These pages live in the FULL app bundle, NOT
        // in pos-app.js's slim chunk. Without these stubs, Vue Router's
        // useLink/resolve throws MATCHER_NOT_FOUND on every PosV5Button mount,
        // producing ~37 unhandled-promise rejections at every POS V4 load
        // (rush-100 round-1 wave-B WB-R1-01). The `beforeEnter` forces a hard
        // navigation (window.location.assign) so the legacy app.js bundle
        // takes over and renders the actual screens. RouterLink resolves the
        // href correctly for both in-tab clicks and target="_blank" new tabs.
        {
            path: '/admin/pos-orders-tracker',
            name: 'admin.pos-orders.tracker',
            beforeEnter(to) { window.location.assign(to.fullPath); },
            component: { render: () => null },
        },
        {
            path: '/admin/order-status-screen',
            name: 'admin.order-status-screen',
            beforeEnter(to) { window.location.assign(to.fullPath); },
            component: { render: () => null },
        },
        {
            path: '/admin/pos-orders',
            name: 'admin.pos-orders.list',
            beforeEnter(to) { window.location.assign(to.fullPath); },
            component: { render: () => null },
        },
        {
            path: '/admin/pos-orders/show/:id',
            name: 'admin.pos-orders.show',
            beforeEnter(to) { window.location.assign(to.fullPath); },
            component: { render: () => null },
        },
        // [POS-V5 HEADER-REORG 2026-07-21] Stubs for the two access points added
        // to the operator-bar « Commandes » cluster in PosComponent.vue:
        // Encaissement (admin.encaissement / EncaissementComponent) and Archives
        // (admin.historique.list / HistoriqueListComponent). Both real pages live
        // in the FULL app.js bundle, NOT in pos-app.js's slim chunk. Without these
        // stubs, RouterLink's resolve() throws MATCHER_NOT_FOUND at every POS V4
        // mount (same failure mode as tracker/pos-orders WB-R1-01). beforeEnter
        // forces a hard navigation so the legacy bundle renders the actual screen;
        // href resolves for in-tab clicks too.
        {
            path: '/admin/encaissement',
            name: 'admin.encaissement',
            beforeEnter(to) { window.location.assign(to.fullPath); },
            component: { render: () => null },
        },
        {
            path: '/admin/historique',
            name: 'admin.historique.list',
            beforeEnter(to) { window.location.assign(to.fullPath); },
            component: { render: () => null },
        },
        // auth.login is read by DefaultComponent.vue L110 when staffOnlyMode
        // triggers a logout-redirect; in pos-app this code path is unused
        // (POS V4 is staff-authenticated) but we add the stub anyway to keep
        // resolve() side-effect-free for any future shared layout component.
        {
            path: '/login',
            name: 'auth.login',
            beforeEnter(to) { window.location.assign(to.fullPath); },
            component: { render: () => null },
        },
        // Fallback — anything under /admin/pos-v4/* goes to POS.
        {
            path: '/admin/pos-v4/:pathMatch(.*)*',
            redirect: { name: 'admin.pos.v4' },
        },
    ],
    scrollBehavior() { return { left: 0, top: 0 }; },
});

router.beforeEach((to, from, next) => {
    if (to.meta && to.meta.auth === true && !store.getters.authStatus) {
        // No valid token in localStorage → bounce to legacy login.
        window.location.href = '/login';
        return;
    }
    next();
});

router.afterEach(() => {
    try {
        document?.querySelector('.backdrop')?.classList?.remove('active');
        document.body.style.overflowY = 'auto';
    } catch (_) {
        /* no-op */
    }
});

const app = createApp({});
app.component('default-component', DefaultComponent);
app.component('vue-select', VueNextSelect);
app.use(router);
app.use(store);
app.use(i18n);
app.use(Toast, {
    timeout: 2000,
    closeOnClick: true,
    pauseOnFocusLoss: true,
    pauseOnHover: true,
    draggable: true,
    draggablePercent: 0.6,
    showCloseButtonOnHover: false,
    hideProgressBar: false,
    closeButton: 'button',
    icon: true,
    rtl: false,
});

app.mount('#app');

// [GOAL-2026-05-29 P-AUTH] Proactive Sanctum token refresh — the POS is an always-on
// surface; its Bearer (sanctum.expiration = 480min) is used by every axios call + the
// WS channel-auth. Without a refresh it dies at ~8h mid service-day. Refresh every 2h
// via /api/refresh-token (abilities preserved). Mirrors app.js (KDS/OSS/admin).
const POS_TOKEN_REFRESH_INTERVAL_MS = 2 * 60 * 60 * 1000;
setInterval(() => {
    try {
        if (store.state.auth && store.state.auth.authToken) {
            store.dispatch('refreshAuthToken').catch(() => {});
        }
    } catch (_) { /* never let the refresh timer break the app */ }
}, POS_TOKEN_REFRESH_INTERVAL_MS);

// [REG-2 2026-05-30] Cross-tab token-rotation sync — mirror of app.js. The 2h refresh in
// one tab revokes the shared Sanctum token; without this a second tab (e.g. POS + KDS open
// together) keeps the dead token and 401s -> forced logout. Adopt a rotated token from
// localStorage instead of dying; follow a cross-tab logout. See app.js for rationale.
window.addEventListener('storage', (e) => {
    if (e.key !== 'vuex' || !e.newValue) return;
    try {
        const persisted = JSON.parse(e.newValue);
        const freshToken = persisted && persisted.auth ? persisted.auth.authToken : null;
        const current = store.state.auth && store.state.auth.authToken;
        if (freshToken && current && freshToken !== current) {
            store.commit('authTokenRefreshed', freshToken);
        } else if (!freshToken && current) {
            store.commit('authLogout');
        }
    } catch (_) { /* never let a cross-tab sync error break the app */ }
});
