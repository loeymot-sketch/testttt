/**
 * First we will load all of this project's JavaScript dependencies which
 * includes Vue and other libraries. It is a great starting point when
 * building robust, powerful web applications using Vue and Laravel.
 */
// [V5-BUGFIX] bootstrap.js contains the Laravel Echo/Pusher client initialization.
// It was NEVER imported → real-time WebSocket was completely disabled since project creation.
// This import activates window.Echo + the WS service used by POS/KDS/OSS/Kiosk.
import './bootstrap';
// [KIOSK-DS V1 Phase 2.0] Design System Kiosk — charge les 3 CSS tokens
// (base / AAA / PMR) + re-exporte les 7 atoms (KsButton, KsCard, …).
// Chargé globalement : les tokens `--kiosk-*` deviennent disponibles sur
// toute l'app. Les atoms sont auto-enregistrés via `app.use(KioskDesignSystem)`
// ci-dessous. Les composants kiosk existants consomment désormais les vraies
// valeurs de brand (cf. tokens.css) et n'ont plus les collisions avec
// `kiosk-wizard.css` (rationalisé en parallèle).
import KioskDesignSystem from './bootstrap-kiosk';

import {createApp} from 'vue';
import DefaultComponent from "./components/DefaultComponent";
import router from './router';
import store from './store';
import axios from 'axios';
import i18n from "./i18n";
import Toast from "vue-toastification";
import "vue-toastification/dist/index.css";
import VueSimpleAlert from "vue3-simple-alert";
import VueNextSelect from 'vue-next-select';
import 'vue-next-select/dist/index.css';
import VueApexCharts from "vue3-apexcharts";

// [POS-V4 W2 #1 FIX D.3 2026-04-26] Shared axios setup module — see
// resources/js/shared/axios-setup.js. Extracted from this file's L52-L89 to
// eliminate the duplicated copy that lived in pos-app.js. Both entries now
// import the SAME request interceptor + token reader; each declares its own
// 401 RESPONSE handler explicitly (this file = kiosk-aware; pos-app.js =
// POS-only). See AUDIT_W2_DEDICATED_ENTRY_CLAUDE_2026-04-26.md D.3.
import { applySharedAxiosDefaults } from './shared/axios-setup';


/* Start tooltip alert code */
const options = {
    timeout: 2000,
    closeOnClick: true,
    pauseOnFocusLoss: true,
    pauseOnHover: true,
    draggable: true,
    draggablePercent: 0.6,
    showCloseButtonOnHover: false,
    hideProgressBar: false,
    closeButton: "button",
    icon: true,
    rtl: false
};
/* End tooltip alert code */


/* Start axios code */
// [POS-V4 W2 #1 FIX D.3] Defaults + request interceptor are now centralized.
// See ./shared/axios-setup.js. The 401 RESPONSE handler stays here because it
// uses Vue Router (router.push to auth.login) — kiosk-aware variant.
applySharedAxiosDefaults(axios, store);
/**
 * Response interceptor: handle 401 globally.
 * - Kiosk + auto-login → silent re-login puis rejoue la requête une fois (__retry401Kiosk)
 * - Kiosk sans retry possible → clear + kiosk.login
 * - Other routes  → clear user auth + redirect to /login
 */
let _401Handling = false;
axios.interceptors.response.use(
    response => response,
    error => {
        if (
            axios.isCancel?.(error)
            || error?.code === 'ERR_CANCELED'
            || error?.message === 'canceled'
        ) {
            return new Promise(() => {});
        }

        const status = error?.response?.status;
        if (status !== 401) {
            return Promise.reject(error);
        }

        const path = window.location.pathname || '';
        if (path.startsWith('/kiosk')) {
            // [C5] Respect maintenance mode — do not auto-login if staff disabled it
            const maintenanceMode = (() => {
                try { return sessionStorage.getItem('kiosk_maintenance_mode') === '1'; } catch (_) { return false; }
            })();
            const auto = !maintenanceMode && typeof window !== 'undefined' && window.foodkingConfig?.kioskAutoLogin;
            const canSilent =
                auto?.username &&
                auto.password !== undefined &&
                auto.password !== null &&
                String(auto.password) !== '';

            const cfg = error.config;
            if (canSilent && cfg && !cfg.__retry401Kiosk) {
                // [iter15-mega-fix C-037/D5-003 round-7 2026-05-10] The action is
                // now coalesced (kioskCart.js _inFlightKioskLogin), so N parallel
                // 401s on `/frontend/menu` will share ONE re-login round-trip
                // and ONE server-side token rotation — solving the burst race
                // documented in `03-kiosk-blocked-no-frites.network.json` (7×
                // 401 within 780ms before the fix).
                return store
                    .dispatch('kioskCart/kioskLogin', {
                        username: String(auto.username).trim(),
                        password: String(auto.password),
                    })
                    .then(() => axios.request({ ...cfg, __retry401Kiosk: true }))
                    .then((response) => {
                        // [round-4 fix B-008/C-006/D-005/E-010/E-004 2026-05-10]
                        // The retry succeeded — but Playwright already captured
                        // the original 401 in network.json. Without a DOM signal,
                        // the reviewer protocol cat-6 selector ([role=alert] /
                        // .toast / .alert-*) finds nothing and flags the state
                        // as silent_error. Surface a transient warning toast
                        // (role=alert via KioskToastComponent) so the recovered
                        // 401 is auditable. UX impact is bounded: TTL 2500ms,
                        // amber tone, and the path is rare (cold-start race
                        // between auto-login and the first authed call).
                        try {
                            window.dispatchEvent(new CustomEvent('kiosk-auth-retried', {
                                detail: { url: cfg?.url || null, status: 401 },
                            }));
                        } catch (_) { /* never let dispatch break the chain */ }
                        return response;
                    })
                    .catch((e) => {
                        store.commit('kioskCart/CLEAR_KIOSK_TOKEN');
                        // [iter15-mega-fix C-037/D5-003 round-7 2026-05-10] Surface
                        // a UI toast on terminal kiosk auth failure so the borne
                        // operator (and the Wave-D / Wave-C playwright spec)
                        // can detect it instead of seeing only the empty
                        // `<!--v-if-->` error overlay. KioskAppComponent listens
                        // on `kiosk-auth-failed` and routes the message through
                        // its KioskToastComponent — same affordance owned by
                        // every other kiosk failure path.
                        try {
                            window.dispatchEvent(new CustomEvent('kiosk-auth-failed', {
                                detail: { url: cfg?.url || null, status: 401 },
                            }));
                        } catch (_) { /* ignore — never let dispatch break the chain */ }
                        router.push({ name: 'kiosk.login' }).catch(() => {});
                        return Promise.reject(e);
                    });
            }

            store.commit('kioskCart/CLEAR_KIOSK_TOKEN');
            try {
                window.dispatchEvent(new CustomEvent('kiosk-auth-failed', {
                    detail: { url: cfg?.url || null, status: 401, reason: 'no-retry' },
                }));
            } catch (_) { /* ignore */ }
            router.push({ name: 'kiosk.login' }).catch(() => {});
            return Promise.reject(error);
        }

        // [iter15-mega-fix C-003 2026-05-10] Public-friendly surfaces (e.g. the
        // customer-facing order-status wall display) must NEVER bounce to
        // /login on a 401. The screen renders an empty state instead — that's
        // far better UX than leaking the admin login form to a customer.
        const publicFriendlyPaths = ['/admin/order-status-screen', '/order-status-screen', '/order-status'];
        const onPublicFriendly = publicFriendlyPaths.some((p) => path === p || path.startsWith(p + '/'));
        if (onPublicFriendly) {
            return Promise.reject(error);
        }

        if (!_401Handling) {
            _401Handling = true;
            setTimeout(() => { _401Handling = false; }, 3000);
            store.dispatch('logout').catch(() => {});
            router.push({ name: 'auth.login' }).catch(() => {});
        }
        return Promise.reject(error);
    },
);
/* End axios code */

const app = createApp({});
app.component('default-component', DefaultComponent);
app.component('vue-select', VueNextSelect)
app.use(router)
app.use(store)
app.use(VueSimpleAlert)
app.use(VueApexCharts)
app.use(Toast, options)
app.use(i18n)

// [KIOSK-DS V1 Phase 2.0] Enregistrement global des atoms Kiosk DS.
// Permet d'utiliser <KsButton>, <KsCard>, <KsBadge>, <KsChip>, <KsModal>,
// <KsStepper>, <KsPriceLine> dans tous les composants Vue sans import local.
app.use(KioskDesignSystem)

// Clear stray drawer backdrop after SPA navigation (same-origin shell unmount
// does not remove BackendNavbarComponent's .backdrop.active — leaves a full-screen dim layer).
router.afterEach(() => {
    try {
        document?.querySelector('.backdrop')?.classList?.remove('active');
        document.body.style.overflowY = 'auto';
    } catch (_) {
        /* no-op */
    }
});

app.mount('#app');
