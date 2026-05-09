import {watch} from "vue";
import {createI18n} from "vue-i18n";

import ar from './languages/ar.json';
import en from './languages/en.json';
import fr from './languages/fr.json';

const SUPPORTED_LOCALES = Object.keys({ fr, en, ar });
const DEFAULT_LOCALE    = 'fr';
/** Défaut au boot `/kiosk` avant hydratation Vuex ; la langue réelle vient de `kioskSettings` + `setLocale`. */
const KIOSK_LOCALE      = 'fr';

function setDocumentDirection(locale) {
    if (typeof document === 'undefined') return;
    if (locale === 'ar') {
        document.documentElement.dir  = 'rtl';
        document.documentElement.lang = 'ar';
    } else {
        document.documentElement.dir  = 'ltr';
        document.documentElement.lang = locale;
    }
}

function isKioskPath() {
    return typeof window !== 'undefined' &&
           (window.location.pathname || '').includes('/kiosk');
}

/**
 * [BLUE 2026-05-08 / B5-UX P1] Surfaces admin (POS, KDS, dashboard, etc.) :
 * la caisse NF525 doit tourner en FR garantie, sinon un navigateur configuré
 * en EN ferait basculer le POS en EN (cf. RED-R1 CS1 : aria-label "Add Customer"
 * vs placeholder FR). Conséquence directe du bug detectLocale qui suivait
 * navigator.language sans contexte de surface.
 */
function isAdminPath() {
    return typeof window !== 'undefined' &&
           /^\/admin/.test(window.location.pathname || '');
}

/**
 * Locale initiale : KIOSK_LOCALE sur /kiosk, FR forcée sur /admin (POS NF525),
 * sinon langue du navigateur. On ne lit PAS localStorage — une valeur "en"
 * persistée ne doit jamais forcer l'anglais sur la borne ni en caisse.
 */
function detectLocale() {
    if (isKioskPath()) {
        return KIOSK_LOCALE;
    }
    // [BLUE 2026-05-08 / B5-UX P1] FR forcée pour les surfaces admin (POS NF525 = FR obligatoire).
    if (isAdminPath()) {
        return 'fr';
    }
    if (typeof navigator !== 'undefined') {
        const lang = navigator.language?.split('-')[0];
        if (lang && SUPPORTED_LOCALES.includes(lang)) return lang;
    }
    return DEFAULT_LOCALE;
}

function loadMessages() {
    /** Static imports — avoids webpack `require.context` (can be missing in some built bundles / tests). */
    const messages = { fr, en, ar };
    return { messages };
}

const { messages }   = loadMessages();
const detectedLocale = detectLocale();

setDocumentDirection(detectedLocale);

const i18n = createI18n({
    legacy:          false,
    globalInjection: true,
    locale:          detectedLocale,
    fallbackLocale:  DEFAULT_LOCALE,
    messages,
});

watch(
    () => i18n.global.locale.value,
    (locale) => setDocumentDirection(locale),
);

/**
 * Appelé par le router à chaque navigation vers /kiosk/*.
 * Ne force plus `KIOSK_LOCALE` : une locale persistée (ex. `ar`) serait écrasée
 * alors que `applyKioskA11yFromStore` / `setLocale` alignent déjà i18n sur le store.
 */
export function ensureKioskLocale() {
    /* no-op — kiosk UI locale = kioskSettings (persisté) */
}

/** Changer la langue (admin, frontend, etc. — pas la borne) */
export function setLocale(locale) {
    if (!SUPPORTED_LOCALES.includes(locale)) return;
    i18n.global.locale.value = locale;
    setDocumentDirection(locale);
}

export function getCurrentLocale() { return i18n.global.locale.value; }
export function isRTL()            { return i18n.global.locale.value === 'ar'; }

export default i18n;
