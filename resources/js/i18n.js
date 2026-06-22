import {watch} from "vue";
import {createI18n} from "vue-i18n";

import ar from './languages/ar.json';
import en from './languages/en.json';
import fr from './languages/fr.json';

const SUPPORTED_LOCALES = Object.keys({ fr, en, ar });
const DEFAULT_LOCALE    = 'fr';
/**
 * [ADR-007 / iter15-P1a] Borne FR-lock immutable au runtime.
 *  - Boot `/kiosk` : `detectLocale()` retourne `KIOSK_LOCALE` ('fr') quel que
 *    soit `navigator.language`. Aucun localStorage n'est lu (cf. `detectLocale`).
 *  - Runtime : les composants kiosk (KioskIdleScreenComponent / KioskAppComponent)
 *    n'appellent plus `setLocale()` — voir tests/js/kioskFrLockImmutable.spec.js.
 *  - Résiduel : `applyKioskA11yFromStore(store)` dans `composables/useKioskA11y.js`
 *    propage encore `kioskSettings.locale` au boot ; un store persisté en `ar`/`en`
 *    forcerait alors une locale non-FR. Hors scope iter15-P1a — à durcir si une
 *    politique stricte FR-only doit être appliquée même contre le store.
 */
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
 *
 * [Wave P R2 KDS-i18n 2026-05-20] Étendu à `/kds` et `/order-status-screen` :
 * le router Vue redirige `/kds` → `/admin/kitchen-display-system` côté client
 * MAIS i18n.js est évalué AVANT le router (module-load time), donc le pathname
 * lu est encore `/kds` → fallback navigator.language. Conséquence Wave P R1 P-3 :
 * KDS CTA rendu "Ready" au lieu de "Prêt" sur Playwright headless (navigator FR-FR
 * absent). Même cause potentielle pour OSS (`/order-status-screen` est aussi
 * une surface staff/cuisine FR-only V1 Le Cayenne).
 */
function isAdminPath() {
    if (typeof window === 'undefined') return false;
    const p = window.location.pathname || '';
    return /^\/admin/.test(p) ||
           /^\/kds(\/|$)/.test(p) ||
           /^\/order-status-screen(\/|$)/.test(p);
}

/**
 * [test-e2e round-2 cluster-2 A-003 2026-05-10] Surfaces auth (login,
 * forget-password, signup, verify-email) : Le Cayenne V1 est FR-only, donc
 * `detectLocale()` doit retourner FR sur ces routes pour éviter la FOUC i18n.
 * Cause initiale du flash : `detectLocale()` tombait sur `navigator.language`
 * (souvent EN sur Playwright headless), peignait l'écran en EN, puis
 * `FrontendNavBarComponent.mounted()` réécrivait `$i18n.locale = 'fr'` après
 * la requête `frontendSetting/lists` (~300-700 ms plus tard). Le verrou FR
 * boot supprime entièrement la fenêtre de flash visible côté client.
 */
function isAuthPath() {
    if (typeof window === 'undefined') return false;
    const p = window.location.pathname || '';
    return /^\/(login|forget-password|signup|verify-email|reset-password)(\/|$)/.test(p);
}

/**
 * Locale initiale : KIOSK_LOCALE sur /kiosk, FR forcée sur /admin (POS NF525)
 * et sur les surfaces auth (login/signup/forget — V1 FR-only Le Cayenne),
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
    // [test-e2e round-2 A-003] FR forcée pour /login & co. — supprime la FOUC EN→FR.
    if (isAuthPath()) {
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
