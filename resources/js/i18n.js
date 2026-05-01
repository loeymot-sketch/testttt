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
 * Locale initiale : KIOSK_LOCALE sur /kiosk, sinon langue du navigateur.
 * On ne lit PAS localStorage — une valeur "en" persistée ne doit jamais
 * forcer l'anglais sur la borne.
 */
function detectLocale() {
    if (isKioskPath()) {
        return KIOSK_LOCALE;
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
