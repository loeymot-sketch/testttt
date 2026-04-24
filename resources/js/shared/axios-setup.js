/**
 * [POS-V4 W2 #1 FIX 2026-04-26] Shared axios setup for both Vue entries
 * (`app.js` and `pos-app.js`).
 *
 * Born from audit AUDIT_W2_DEDICATED_ENTRY_CLAUDE_2026-04-26.md D.3:
 * the duplicated axios block in app.js L52-L146 and pos-app.js L40-L90 was
 * mislabeled "frozen mirror" — in reality only the request interceptor +
 * `readTokenFromVuexLocalStorage()` are byte-identical. The 401 RESPONSE
 * handler genuinely diverges (app.js uses Vue Router push to auth.login;
 * pos-app.js uses window.location to /login). This module exposes the
 * identical parts; each entry installs its own response handler explicitly.
 *
 * Public API:
 *   - applySharedAxiosDefaults(axios, store, opts)  → installs request interceptor
 *   - readTokenFromVuexLocalStorage(store)          → returns { token, language }
 *
 * Risk if changed: every API call from both entries goes through this
 * interceptor (auth, branch_id propagation via Bearer token, x-api-key,
 * x-localization). Mutating header semantics here breaks /api/* contract
 * for ALL surfaces. Treat as a frozen-by-contract module.
 */

import ENV from '../config/env';
import { getCurrentLocale } from '../i18n';

/**
 * Reads the active Bearer token + language from the Vuex store, falling back
 * to the persisted Vuex snapshot in localStorage if the store hasn't been
 * hydrated yet (cold-start race). Identical to the original logic in app.js.
 *
 * @param {object} store  Vuex store instance
 * @returns {{ token: string|null, language: string|null }}
 */
export function readTokenFromVuexLocalStorage(store) {
    let kioskToken = store.state.kioskCart?.kioskToken || null;
    let userToken = store.state.auth?.authToken || null;
    let language = store.state.globalState?.lists?.language_code || null;

    if (!kioskToken && !userToken && localStorage.getItem('vuex')) {
        try {
            const vuex = JSON.parse(localStorage.getItem('vuex'));
            kioskToken = kioskToken || vuex.kioskCart?.kioskToken || null;
            userToken = userToken || vuex.auth?.authToken || null;
            language = language || vuex.globalState?.lists?.language_code || null;
        } catch (_) { /* ignore — corrupt JSON in localStorage falls through */ }
    }

    return { token: kioskToken || userToken, language };
}

/**
 * Installs the shared axios defaults + request interceptor on the provided
 * axios instance. Does NOT install a response (401) handler — each entry
 * defines its own with surface-specific redirect semantics.
 *
 * @param {import('axios').AxiosStatic} axios   axios module
 * @param {object} store                        Vuex store
 */
export function applySharedAxiosDefaults(axios, store) {
    const API_URL = ENV.API_URL;
    const API_KEY = ENV.API_KEY;
    axios.defaults.baseURL = API_URL + '/api';

    axios.interceptors.request.use(
        config => {
            config.headers['x-api-key'] = API_KEY;

            // [PHASE-37] Backend localization header — read from i18n live, not store snapshot.
            const currentLocale = getCurrentLocale();
            config.headers['Accept-Language'] = currentLocale;

            const { token, language } = readTokenFromVuexLocalStorage(store);
            config.headers['Authorization'] = token ? `Bearer ${token}` : '';

            const lang = currentLocale || language;
            if (lang) config.headers['x-localization'] = lang;

            return config;
        },
        error => Promise.reject(error),
    );
}
