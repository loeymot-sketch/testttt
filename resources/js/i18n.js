import {createI18n} from "vue-i18n";

/**
 * [PHASE-37] Multi-language support with auto-detection
 * Detects: 1. localStorage saved preference, 2. browser language, 3. default 'fr'
 */

const SUPPORTED_LOCALES = ['fr', 'en', 'ar'];
const DEFAULT_LOCALE = 'fr';
const STORAGE_KEY = 'kiosk_locale';

/**
 * Detect locale from localStorage or browser
 */
function detectLocale() {
    // 1. Check localStorage (saved preference)
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved && SUPPORTED_LOCALES.includes(saved)) {
        return saved;
    }

    // 2. Check browser language
    const browserLang = navigator.language?.split('-')[0];
    if (browserLang && SUPPORTED_LOCALES.includes(browserLang)) {
        return browserLang;
    }

    // 3. Default
    return DEFAULT_LOCALE;
}

/**
 * Set RTL direction for Arabic
 */
function setDocumentDirection(locale) {
    if (locale === 'ar') {
        document.documentElement.dir = 'rtl';
        document.documentElement.lang = 'ar';
    } else {
        document.documentElement.dir = 'ltr';
        document.documentElement.lang = locale;
    }
}

function loadMessages() {
    const context  = require.context("./languages", true, /[a-z0-9-_]+\.json$/i);
    const messages = context
        .keys()
        .map((key) => ({key, locale: key.match(/[a-z0-9-_]+/i)[0]}))
        .reduce((messages, {key, locale}) => ({
                ...messages, [locale]: context(key),
            }),
            {}
        );
    return {messages};
}

const {messages} = loadMessages();
const detectedLocale = detectLocale();

// Set RTL on initial load
setDocumentDirection(detectedLocale);

const i18n = createI18n({
    legacy: false,
    locale: detectedLocale,
    fallbackLocale: DEFAULT_LOCALE,
    messages: messages
});

/**
 * [PHASE-37] Change locale and persist to localStorage
 * @param {string} locale - 'fr', 'en', or 'ar'
 */
export function setLocale(locale) {
    if (!SUPPORTED_LOCALES.includes(locale)) {
        console.warn(`[i18n] Unsupported locale: ${locale}`);
        return;
    }

    // Update i18n
    i18n.global.locale.value = locale;

    // Persist preference
    localStorage.setItem(STORAGE_KEY, locale);

    // Update document direction (RTL for Arabic)
    setDocumentDirection(locale);

    console.log(`[i18n] Locale changed to: ${locale}`);
}

/**
 * [PHASE-37] Get current locale
 */
export function getCurrentLocale() {
    return i18n.global.locale.value;
}

/**
 * [PHASE-37] Check if locale is RTL
 */
export function isRTL() {
    return i18n.global.locale.value === 'ar';
}

export default i18n;
