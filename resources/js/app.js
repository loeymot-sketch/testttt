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
                return store
                    .dispatch('kioskCart/kioskLogin', {
                        username: String(auto.username).trim(),
                        password: String(auto.password),
                    })
                    .then(() => axios.request({ ...cfg, __retry401Kiosk: true }))
                    .catch((e) => {
                        store.commit('kioskCart/CLEAR_KIOSK_TOKEN');
                        router.push({ name: 'kiosk.login' }).catch(() => {});
                        return Promise.reject(e);
                    });
            }

            store.commit('kioskCart/CLEAR_KIOSK_TOKEN');
            router.push({ name: 'kiosk.login' }).catch(() => {});
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
