/**
 * First we will load all of this project's JavaScript dependencies which
 * includes Vue and other libraries. It is a great starting point when
 * building robust, powerful web applications using Vue and Laravel.
 */
// [V5-BUGFIX] bootstrap.js contains the Laravel Echo/Pusher client initialization.
// It was NEVER imported → real-time WebSocket was completely disabled since project creation.
// This import activates window.Echo + the WS service used by POS/KDS/OSS/Kiosk.
import './bootstrap';
import {createApp} from 'vue';
import DefaultComponent from "./components/DefaultComponent";
import router from './router';
import store from './store';
import axios from 'axios';
import i18n, { getCurrentLocale } from "./i18n";
import Toast from "vue-toastification";
import "vue-toastification/dist/index.css";
import VueSimpleAlert from "vue3-simple-alert";
import VueNextSelect from 'vue-next-select';
import 'vue-next-select/dist/index.css';
import VueApexCharts from "vue3-apexcharts";
import ENV from './config/env';


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


/* Start axios code*/
const API_URL = ENV.API_URL;
const API_KEY = ENV.API_KEY;

axios.defaults.baseURL = API_URL + '/api';

function readTokenFromVuexLocalStorage() {
    let kioskToken = store.state.kioskCart?.kioskToken || null;
    let userToken = store.state.auth?.authToken || null;
    let language = store.state.globalState?.lists?.language_code || null;
    if (!kioskToken && !userToken && localStorage.getItem('vuex')) {
        try {
            const vuex = JSON.parse(localStorage.getItem('vuex'));
            kioskToken = kioskToken || vuex.kioskCart?.kioskToken || null;
            userToken = userToken || vuex.auth?.authToken || null;
            language = language || vuex.globalState?.lists?.language_code || null;
        } catch (_) { /* ignore */ }
    }
    const token = kioskToken || userToken;
    return { token, language };
}

axios.interceptors.request.use(
    config => {
        config.headers['x-api-key'] = API_KEY;

        // [PHASE-37] Add Accept-Language header for backend localization
        const currentLocale = getCurrentLocale();
        config.headers['Accept-Language'] = currentLocale;

        const { token, language } = readTokenFromVuexLocalStorage();
        config.headers['Authorization'] = token ? `Bearer ${token}` : '';
        const lang = currentLocale || language;
        if (lang) config.headers['x-localization'] = lang;

        return config;
    },
    error => Promise.reject(error),
);
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
            store.dispatch('auth/logout').catch(() => {});
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
app.mount('#app');
