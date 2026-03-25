/**
 * First we will load all of this project's JavaScript dependencies which
 * includes Vue and other libraries. It is a great starting point when
 * building robust, powerful web applications using Vue and Laravel.
 */
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
axios.interceptors.request.use(
    config => {
        config.headers['x-api-key'] = API_KEY;
        if (localStorage.getItem('vuex')) {
            try {
                const vuex = JSON.parse(localStorage.getItem('vuex'));
                // Kiosk machine token takes priority over regular user session
                const kioskToken = vuex.kioskCart?.kioskToken;
                const userToken   = vuex.auth?.authToken;
                const token       = kioskToken || userToken;
                const language    = vuex.globalState?.lists?.language_code;
                config.headers['Authorization'] = token ? `Bearer ${token}` : '';
                if (language) config.headers['x-localization'] = language;
            } catch (_) { /* malformed localStorage — ignore */ }
        }
        return config;
    },
    error => Promise.reject(error),
);
/**
 * Response interceptor: handle 401 globally.
 * - Kiosk routes → clear kiosk token + redirect to kiosk.login
 * - Other routes  → clear user auth + redirect to /login
 * Uses a flag to prevent infinite redirect loops.
 */
let _401Handling = false;
axios.interceptors.response.use(
    response => response,
    error => {
        const status = error?.response?.status;
        if (status === 401 && !_401Handling) {
            _401Handling = true;
            setTimeout(() => { _401Handling = false; }, 3000);

            const path = window.location.pathname || '';
            if (path.startsWith('/kiosk')) {
                // Clear kiosk token from Vuex store
                store.commit('kioskCart/CLEAR_KIOSK_TOKEN');
                router.push({ name: 'kiosk.login' }).catch(() => {});
            } else {
                // Clear regular auth session
                store.dispatch('auth/logout').catch(() => {});
                router.push({ name: 'auth.login' }).catch(() => {});
            }
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
