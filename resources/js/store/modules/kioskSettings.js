/**
 * kioskSettings.js — Vuex store module for per-kiosk a11y & locale preferences.
 * -----------------------------------------------------------------------------
 * FoodKing Kiosk — Phase 4 (European Accessibility Act compliance).
 *
 * Scope :
 *  - locale  : 'fr' | 'en' | 'ar'          (default 'fr')
 *  - contrast: 'aa'  | 'aaa'               (default 'aa')
 *  - pmr     : boolean (+20% sizing, 64px tap targets) (default false)
 *  - audio   : boolean (Web Speech API on/off)        (default false)
 *  - keyboardEnabled: boolean (virtual keyboard)      (default true)
 *
 * Persistance : déléguée à `createPersistedState` dans store/index.js via
 *               l'entrée `"kioskSettings"`. Cela permet de garder les
 *               préférences d'accessibilité entre sessions (Electron reload,
 *               page refresh, ou toute nouvelle session).
 *
 * Invariants :
 *  - Ce store NE DOIT JAMAIS contenir de PII (nom, email, téléphone). Il stocke
 *    uniquement les préférences machine — aucune donnée client.
 *  - Les mutations notifient les autres modules via des commits plain Vuex ;
 *    aucun side-effect DOM ici — c'est le rôle du composable useKioskA11y
 *    d'appliquer les attributs HTML au root.
 *  - `setLocale` n'écrit pas `<html lang>` ni `<html dir>` : c'est le
 *    composable qui observe l'état et réagit.
 *  - Pas d'appel réseau ici. Un endpoint admin peut surcharger les valeurs
 *    par défaut de la borne, mais il va dans kioskSetup (déjà existant), pas
 *    ici.
 */

const SUPPORTED_LOCALES = ['fr', 'en', 'ar'];
const RTL_LOCALES = new Set(['ar']);
const SUPPORTED_CONTRASTS = ['aa', 'aaa'];

/**
 * Phase 5.3 — Idle timeouts configurables.
 * Valeurs par défaut alignées sur KioskAppComponent + brief §5.3.
 * Bornes : idleMs ∈ [10s, 10min], confirmMs ∈ [3s, 60s], receiptMs ∈ [5s, 5min].
 */
const IDLE_DEFAULTS = {
    idleMs: 180000,     // 3 min sans interaction → reset wizard
    confirmMs: 30000,   // 30 s "Toujours là ?" warning
    receiptMs: 30000,   // 30 s sur écran confirmation puis retour idle
};
const IDLE_BOUNDS = {
    idleMs: { min: 10000, max: 600000 },
    confirmMs: { min: 3000, max: 60000 },
    receiptMs: { min: 5000, max: 300000 },
};

function coerceIdleValue(key, value) {
    const n = Number(value);
    const bounds = IDLE_BOUNDS[key];
    if (!Number.isFinite(n)) return IDLE_DEFAULTS[key];
    if (!bounds) return IDLE_DEFAULTS[key];
    if (n < bounds.min) return bounds.min;
    if (n > bounds.max) return bounds.max;
    return Math.round(n);
}

function coerceLocale(value) {
    if (typeof value !== 'string') return 'fr';
    const normalized = value.trim().toLowerCase().slice(0, 2);
    return SUPPORTED_LOCALES.includes(normalized) ? normalized : 'fr';
}

function coerceContrast(value) {
    if (typeof value !== 'string') return 'aa';
    const normalized = value.trim().toLowerCase();
    return SUPPORTED_CONTRASTS.includes(normalized) ? normalized : 'aa';
}

function coerceBool(value, fallback = false) {
    if (typeof value === 'boolean') return value;
    if (value === 'true' || value === 1 || value === '1') return true;
    if (value === 'false' || value === 0 || value === '0') return false;
    return fallback;
}

export const kioskSettings = {
    namespaced: true,
    state: () => ({
        locale: 'fr',
        contrast: 'aa',
        pmr: false,
        audio: false,
        keyboardEnabled: true,
        // Phase 8.9 — Accessibility Act 2025 : audio_description et reduced_motion
        // indépendants de `audio` (qui est le TTS basique).
        //  - audioDescription : readouts verbeux sur hover/focus des CTA.
        //  - reducedMotion    : désactive animations, marquees, transitions.
        // Défaut false : respecte `prefers-reduced-motion` via CSS sinon.
        audioDescription: false,
        reducedMotion: false,
        // Session-scoped: si l'utilisateur a explicitement ouvert le drawer
        // au moins une fois (pour masquer la nudge "AAA disponible" après).
        settingsOpened: false,
        // Phase 5.3 — Idle timeouts configurables par borne.
        idleMs: IDLE_DEFAULTS.idleMs,
        confirmMs: IDLE_DEFAULTS.confirmMs,
        receiptMs: IDLE_DEFAULTS.receiptMs,
        // Phase 5.4 — RGPD consent (loyalty + analytics).
        // false = aucun POST analytics. L'utilisateur doit explicitement accepter.
        consentAnalytics: false,
        consentLoyalty: false,
        // Phase 8.11 — Consent transfert mobile (parcours "continuer sur mon
        // téléphone" via QR). Séparé du consent analytics/loyalty.
        consentMobileTransfer: false,
        // Phase 8.11 — Profil client session-scoped (scan loyalty). Wipé dès
        // qu'on revient à l'idle. Pas de PII autre que display_name (prénom).
        // Stocké en mémoire Vuex uniquement (persistence désactivée cf.
        // store/index.js qui filtre les clefs persistées).
        customerProfile: null,
    }),
    getters: {
        locale: (state) => state.locale,
        contrast: (state) => state.contrast,
        pmr: (state) => !!state.pmr,
        audio: (state) => !!state.audio,
        audioDescription: (state) => !!state.audioDescription,
        reducedMotion: (state) => !!state.reducedMotion,
        keyboardEnabled: (state) => !!state.keyboardEnabled,
        isRtl: (state) => RTL_LOCALES.has(state.locale),
        isAAA: (state) => state.contrast === 'aaa',
        supportedLocales: () => SUPPORTED_LOCALES.slice(),
        settingsOpened: (state) => !!state.settingsOpened,
        idleMs: (state) => state.idleMs,
        confirmMs: (state) => state.confirmMs,
        receiptMs: (state) => state.receiptMs,
        idleTimeouts: (state) => ({
            idleMs: state.idleMs,
            confirmMs: state.confirmMs,
            receiptMs: state.receiptMs,
        }),
        consentAnalytics: (state) => !!state.consentAnalytics,
        consentLoyalty: (state) => !!state.consentLoyalty,
        consentMobileTransfer: (state) => !!state.consentMobileTransfer,
        customerProfile: (state) => state.customerProfile || null,
        hasCustomerScanned: (state) => !!(state.customerProfile && state.customerProfile.customer_token),
        /**
         * Objet plat utilisé par les events analytics (/api/frontend/kiosk/event).
         * Aucune PII — uniquement des flags booléens/énumérés.
         */
        analyticsSnapshot: (state) => ({
            locale: state.locale,
            contrast: state.contrast,
            pmr: !!state.pmr,
            audio: !!state.audio,
        }),
    },
    mutations: {
        SET_LOCALE(state, value) {
            state.locale = coerceLocale(value);
        },
        SET_CONTRAST(state, value) {
            state.contrast = coerceContrast(value);
        },
        SET_PMR(state, value) {
            state.pmr = coerceBool(value, false);
        },
        SET_AUDIO(state, value) {
            state.audio = coerceBool(value, false);
        },
        SET_AUDIO_DESCRIPTION(state, value) {
            state.audioDescription = coerceBool(value, false);
        },
        SET_REDUCED_MOTION(state, value) {
            state.reducedMotion = coerceBool(value, false);
        },
        SET_KEYBOARD_ENABLED(state, value) {
            state.keyboardEnabled = coerceBool(value, true);
        },
        SET_SETTINGS_OPENED(state, value) {
            state.settingsOpened = !!value;
        },
        SET_IDLE_TIMEOUTS(state, values) {
            if (!values || typeof values !== 'object') return;
            if ('idleMs' in values) state.idleMs = coerceIdleValue('idleMs', values.idleMs);
            if ('confirmMs' in values) state.confirmMs = coerceIdleValue('confirmMs', values.confirmMs);
            if ('receiptMs' in values) state.receiptMs = coerceIdleValue('receiptMs', values.receiptMs);
        },
        SET_CONSENT_ANALYTICS(state, value) {
            state.consentAnalytics = coerceBool(value, false);
        },
        SET_CONSENT_LOYALTY(state, value) {
            state.consentLoyalty = coerceBool(value, false);
        },
        SET_CONSENT_MOBILE_TRANSFER(state, value) {
            state.consentMobileTransfer = coerceBool(value, false);
        },
        SET_CUSTOMER_PROFILE(state, profile) {
            if (!profile || typeof profile !== 'object') {
                state.customerProfile = null;
                return;
            }
            // Ne stocker QUE les champs whitelist — jamais de PII autre que
            // `display_name` (prénom court, déjà cap 40 chars côté serveur).
            state.customerProfile = {
                customer_token:         profile.customer_token || null,
                display_name:           profile.display_name || null,
                loyalty_balance_points: Number.isFinite(+profile.loyalty_balance_points)
                    ? +profile.loyalty_balance_points
                    : 0,
                declared_allergens: Array.isArray(profile.declared_allergens)
                    ? profile.declared_allergens.slice(0, 14).map(String)
                    : [],
                last_order: profile.last_order || null,
                scanned_at: profile.scanned_at || Date.now(),
            };
        },
        CLEAR_CUSTOMER_PROFILE(state) {
            state.customerProfile = null;
        },
        RESET_PREFERENCES(state) {
            state.locale = 'fr';
            state.contrast = 'aa';
            state.pmr = false;
            state.audio = false;
            state.keyboardEnabled = true;
            state.audioDescription = false;
            state.reducedMotion = false;
            state.idleMs = IDLE_DEFAULTS.idleMs;
            state.confirmMs = IDLE_DEFAULTS.confirmMs;
            state.receiptMs = IDLE_DEFAULTS.receiptMs;
            // Note : consent* ne sont PAS reset par ce hook — ils sont liés
            // à des actions légales explicites (opt-in/out) et doivent
            // survivre à un "reset préférences".
        },
    },
    actions: {
        setLocale({ commit }, value) {
            commit('SET_LOCALE', value);
        },
        setContrast({ commit }, value) {
            commit('SET_CONTRAST', value);
        },
        setPmr({ commit }, value) {
            commit('SET_PMR', value);
        },
        setAudio({ commit }, value) {
            commit('SET_AUDIO', value);
        },
        setAudioDescription({ commit }, value) {
            commit('SET_AUDIO_DESCRIPTION', value);
        },
        setReducedMotion({ commit }, value) {
            commit('SET_REDUCED_MOTION', value);
        },
        setKeyboardEnabled({ commit }, value) {
            commit('SET_KEYBOARD_ENABLED', value);
        },
        markSettingsOpened({ commit }) {
            commit('SET_SETTINGS_OPENED', true);
        },
        setIdleTimeouts({ commit }, values) {
            commit('SET_IDLE_TIMEOUTS', values);
        },
        setConsentAnalytics({ commit }, value) {
            commit('SET_CONSENT_ANALYTICS', value);
        },
        setConsentLoyalty({ commit }, value) {
            commit('SET_CONSENT_LOYALTY', value);
        },
        setConsentMobileTransfer({ commit }, value) {
            commit('SET_CONSENT_MOBILE_TRANSFER', value);
        },
        setCustomerProfile({ commit }, profile) {
            commit('SET_CUSTOMER_PROFILE', profile);
        },
        clearCustomerProfile({ commit }) {
            commit('CLEAR_CUSTOMER_PROFILE');
        },
        reset({ commit }) {
            commit('RESET_PREFERENCES');
            // Wipe profil client sur reset explicite (fin de parcours / idle).
            commit('CLEAR_CUSTOMER_PROFILE');
        },
    },
};

export const KIOSK_SETTINGS_CONSTANTS = {
    SUPPORTED_LOCALES: SUPPORTED_LOCALES.slice(),
    RTL_LOCALES: Array.from(RTL_LOCALES),
    SUPPORTED_CONTRASTS: SUPPORTED_CONTRASTS.slice(),
    IDLE_DEFAULTS: { ...IDLE_DEFAULTS },
    IDLE_BOUNDS: { ...IDLE_BOUNDS },
};

export default kioskSettings;
