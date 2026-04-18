import {createI18n} from "vue-i18n";

const SUPPORTED_LOCALES = ['fr', 'en', 'ar'];
const DEFAULT_LOCALE    = 'fr';
const KIOSK_LOCALE      = 'fr';   // langue borne : toujours français, immuable

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
    const context  = require.context('./languages', true, /[a-z0-9-_]+\.json$/i);
    const messages = context
        .keys()
        .map((key) => ({ key, locale: key.match(/[a-z0-9-_]+/i)[0] }))
        .reduce((acc, { key, locale }) => ({ ...acc, [locale]: context(key) }), {});
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

/**
 * Appelé par le router à chaque navigation vers /kiosk/* :
 * garantit que la locale est fr même si l'app a démarré sur une autre URL.
 */
export function ensureKioskLocale() {
    if (i18n.global.locale.value !== KIOSK_LOCALE) {
        i18n.global.locale.value = KIOSK_LOCALE;
        setDocumentDirection(KIOSK_LOCALE);
    }
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
