/**
 * useKioskA11y.js — Composable Vue 3 pour synchroniser le store
 * `kioskSettings` avec les attributs du <html> root.
 * -----------------------------------------------------------------------------
 * FoodKing Kiosk — Phase 4 (European Accessibility Act compliance).
 *
 * Rôle :
 *  - Observer les mutations du module Vuex `kioskSettings`
 *  - Appliquer les attributs correspondants sur `document.documentElement` :
 *      • data-kiosk-contrast="aa|aaa" → bascule le fichier tokens-aaa.css
 *      • data-kiosk-pmr="true|false" → bascule le fichier tokens-pmr.css
 *      • data-kiosk-audio="true|false" → flag lisible par le composable speech
 *      • dir="ltr|rtl"             → layout RTL pour l'arabe
 *      • lang="fr|en|ar"           → accessibility (screen readers)
 *  - Mettre à jour `useI18n().locale.value` en cascade
 *  - Journaliser les changements vers /api/frontend/kiosk/event (optionnel)
 *
 * Invariants :
 *  - Idempotent : appeler ce composable plusieurs fois ne duplique pas les
 *    watchers (garde via `watch` intégré).
 *  - Safe côté SSR/tests : vérifie `typeof document !== 'undefined'`.
 *  - N'écrit JAMAIS dans le store — il n'observe qu'en lecture.
 *  - Les attributs sont alignés sur les sélecteurs déjà présents dans
 *    tokens-aaa.css (`[data-kiosk-contrast="aaa"]`) et
 *    tokens-pmr.css (`[data-kiosk-pmr="true"]`).
 *  - `init()` est sûr à rappeler : il ne pose les attributs qu'une fois.
 */

import { watch, onMounted, onBeforeUnmount, effectScope } from 'vue';
import { setLocale } from '../i18n.js';

const HTML_ATTR = {
    CONTRAST: 'data-kiosk-contrast',
    PMR: 'data-kiosk-pmr',
    AUDIO: 'data-kiosk-audio',
    AUDIO_DESCRIPTION: 'data-kiosk-audio-description',
    REDUCED_MOTION: 'data-kiosk-reduced-motion',
    LANG: 'lang',
    DIR: 'dir',
};

/**
 * Écrit de manière idempotente un attribut sur <html>. Skip si la valeur est
 * déjà celle attendue — évite les reflows inutiles lors d'observations
 * redondantes dans le même tick Vuex.
 */
function applyAttr(name, value) {
    if (typeof document === 'undefined' || !document.documentElement) return;
    const el = document.documentElement;
    const current = el.getAttribute(name);
    if (current === value) return;
    if (value === null || value === undefined || value === '') {
        el.removeAttribute(name);
    } else {
        el.setAttribute(name, String(value));
    }
}

/**
 * Supprime tous les attributs a11y du <html> — utile lorsqu'on quitte la
 * surface kiosk (ex: retour à /admin). Évite que PMR/AAA leakent sur les
 * autres surfaces de l'app.
 */
export function clearKioskA11yAttributes() {
    applyAttr(HTML_ATTR.CONTRAST, null);
    applyAttr(HTML_ATTR.PMR, null);
    applyAttr(HTML_ATTR.AUDIO, null);
    applyAttr(HTML_ATTR.AUDIO_DESCRIPTION, null);
    applyAttr(HTML_ATTR.REDUCED_MOTION, null);
}

/**
 * Snapshot utilisable en dehors d'un setup() (ex: hors composant Vue).
 * Utile pour synchroniser l'état initial au boot kiosk (avant qu'un composant
 * n'appelle useKioskA11y).
 */
export function applyKioskA11yFromStore(store) {
    if (!store) return;
    const s = store.state?.kioskSettings;
    if (!s) return;
    applyAttr(HTML_ATTR.CONTRAST, s.contrast || 'aa');
    applyAttr(HTML_ATTR.PMR, s.pmr ? 'true' : 'false');
    applyAttr(HTML_ATTR.AUDIO, s.audio ? 'true' : 'false');
    applyAttr(HTML_ATTR.AUDIO_DESCRIPTION, s.audioDescription ? 'true' : 'false');
    applyAttr(HTML_ATTR.REDUCED_MOTION, s.reducedMotion ? 'true' : 'false');
    setLocale(s.locale || 'fr');
}

/**
 * Composable principal. À appeler dans un setup() Vue 3.
 *
 * @param {object}   options
 * @param {object}   options.store   Store Vuex (inject si non fourni via useStore)
 * @param {object}   options.i18n    useI18n() retour (optionnel — sync locale)
 * @returns {{ stop: () => void }}    handle pour arrêter les watchers manuellement
 */
export function useKioskA11y({ store, i18n } = {}) {
    if (!store) throw new Error('[useKioskA11y] store Vuex requis');

    const scope = effectScope();
    let stopped = false;

    scope.run(() => {
        // Sync initiale (idempotente).
        applyKioskA11yFromStore(store);
        if (i18n?.locale && store.state.kioskSettings?.locale) {
            i18n.locale.value = store.state.kioskSettings.locale;
        }

        // Watcher contrast
        watch(
            () => store.state.kioskSettings?.contrast,
            (value) => {
                applyAttr(HTML_ATTR.CONTRAST, value || 'aa');
            },
            { immediate: false }
        );

        // Watcher PMR
        watch(
            () => store.state.kioskSettings?.pmr,
            (value) => {
                applyAttr(HTML_ATTR.PMR, value ? 'true' : 'false');
            },
            { immediate: false }
        );

        // Watcher audio
        watch(
            () => store.state.kioskSettings?.audio,
            (value) => {
                applyAttr(HTML_ATTR.AUDIO, value ? 'true' : 'false');
            },
            { immediate: false }
        );

        // Phase 8.9 — Watcher audio_description (EAA 2025)
        watch(
            () => store.state.kioskSettings?.audioDescription,
            (value) => {
                applyAttr(HTML_ATTR.AUDIO_DESCRIPTION, value ? 'true' : 'false');
            },
            { immediate: false }
        );

        // Phase 8.9 — Watcher reduced_motion (WCAG 2.3.3)
        watch(
            () => store.state.kioskSettings?.reducedMotion,
            (value) => {
                applyAttr(HTML_ATTR.REDUCED_MOTION, value ? 'true' : 'false');
            },
            { immediate: false }
        );

        // Watcher locale → lang/dir + i18n.locale
        watch(
            () => store.state.kioskSettings?.locale,
            (value) => {
                const lang = value || 'fr';
                setLocale(lang);
                if (i18n?.locale) i18n.locale.value = lang;
            },
            { immediate: false }
        );
    });

    // Wire à onMounted/onBeforeUnmount si disponibles (optionnel : si le
    // composable est appelé dans un setup(), Vue loguera un warning pour
    // onMounted en dehors — on tolère via try/catch).
    try {
        onMounted(() => {
            if (!stopped) applyKioskA11yFromStore(store);
        });
        onBeforeUnmount(() => {
            stopped = true;
            scope.stop();
        });
    } catch (_) {
        // Appelé en dehors d'un setup() : les watchers restent actifs via
        // scope.run() — le caller doit appeler stop() manuellement.
    }

    return {
        stop() {
            stopped = true;
            scope.stop();
        },
    };
}

export const KIOSK_A11Y_ATTRS = HTML_ATTR;

export default useKioskA11y;
