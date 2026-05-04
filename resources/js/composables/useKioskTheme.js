/**
 * useKioskTheme.js — Composable theme switching pour la borne FoodKing.
 * -----------------------------------------------------------------------------
 * Plan        : plans/PLAN_CV1-KIOSK-VISUAL-REDESIGN-001_2026-05-02.md §V1.3
 * Direction   : Bold Appétissant — light + dark
 * État        : 'light' | 'dark' | 'auto'
 * Persistance : kioskSettings store (Vuex) + cascade `data-kiosk-theme` sur <html>
 *
 * Cascade :
 *   - User pick `auto` → résout selon `prefers-color-scheme` + écoute le change
 *   - User pick `light`/`dark` → applique direct, ignore système
 *   - Réécrit `data-kiosk-theme="light"|"dark"` (jamais 'auto' — toujours résolu)
 *     sur <html>, ce qui active la cascade CSS dans tokens-bold.css
 *
 * A11y :
 *   - Pas de FOUC : la résolution `auto` est synchrone via matchMedia au mount
 *   - Compatible reduced-motion : le toggle ne déclenche aucune animation propre
 *   - Coexiste avec `data-kiosk-contrast='aaa'` (combinable AAA + dark)
 *
 * Discipline :
 *   - Ne touche PAS le DOM en dehors de l'attribut `data-kiosk-theme` sur <html>
 *   - Ne stocke aucune PII (préférence machine uniquement)
 *   - Vue 2.7 / Vue 3 Composition API (cohérent useKioskA11y existant)
 */

import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';

const SUPPORTED_THEMES = ['light', 'dark', 'auto'];
const HTML_ATTR = 'data-kiosk-theme';
const MEDIA_QUERY = '(prefers-color-scheme: dark)';

/**
 * Résout 'auto' en 'light' ou 'dark' selon prefers-color-scheme.
 * Retourne 'light' par défaut côté SSR ou environnement sans matchMedia.
 */
function resolveAuto() {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return 'light';
    }
    try {
        return window.matchMedia(MEDIA_QUERY).matches ? 'dark' : 'light';
    } catch (_) {
        return 'light';
    }
}

/**
 * Applique l'attribut HTML. 'auto' est toujours résolu en concret avant écriture.
 */
function applyHtmlTheme(resolvedTheme) {
    if (typeof document === 'undefined' || !document.documentElement) return;
    if (resolvedTheme !== 'light' && resolvedTheme !== 'dark') return;
    document.documentElement.setAttribute(HTML_ATTR, resolvedTheme);
}

/**
 * Composable principal. Optionnellement, on peut passer un store Vuex injecté
 * (utile pour tests Vitest sans accès à $store global).
 */
export function useKioskTheme(injectedStore = null) {
    const store = injectedStore || (typeof window !== 'undefined' && window.$nuxt?.$store) || null;

    /** Pref utilisateur stockée ('light' | 'dark' | 'auto') */
    const preference = ref('auto');

    /** Résolution courante (toujours 'light' ou 'dark') */
    const resolved = ref('light');

    /** matchMedia listener handle pour cleanup */
    let mediaQueryList = null;
    let mediaListener = null;

    /**
     * Coerce une valeur arbitraire vers une préférence valide.
     */
    function coerce(value) {
        if (typeof value !== 'string') return 'auto';
        const normalized = value.trim().toLowerCase();
        return SUPPORTED_THEMES.includes(normalized) ? normalized : 'auto';
    }

    /**
     * Recalcule `resolved` depuis `preference`.
     */
    function recompute() {
        if (preference.value === 'auto') {
            resolved.value = resolveAuto();
        } else {
            resolved.value = preference.value;
        }
        applyHtmlTheme(resolved.value);
    }

    /**
     * Set theme programmatically. Persiste via store si dispo, sinon localStorage.
     */
    function setTheme(value) {
        const next = coerce(value);
        preference.value = next;

        // Persistance store-first
        if (store?.dispatch) {
            try { store.dispatch('kioskSettings/setTheme', next); } catch (_) {}
        } else if (typeof window !== 'undefined' && window.localStorage) {
            try { window.localStorage.setItem('kiosk_theme_v1', next); } catch (_) {}
        }

        recompute();
    }

    /**
     * Lit la préférence initiale depuis le store ou localStorage (fallback).
     */
    function readInitial() {
        let initial = 'auto';

        if (store?.state?.kioskSettings?.theme) {
            initial = coerce(store.state.kioskSettings.theme);
        } else if (typeof window !== 'undefined' && window.localStorage) {
            try {
                const fromLs = window.localStorage.getItem('kiosk_theme_v1');
                if (fromLs) initial = coerce(fromLs);
            } catch (_) {}
        }

        preference.value = initial;
    }

    /**
     * Attache l'écoute matchMedia pour les changements système (uniquement
     * pertinent en mode 'auto').
     */
    function attachMediaListener() {
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return;
        try {
            mediaQueryList = window.matchMedia(MEDIA_QUERY);
            mediaListener = () => {
                // Ne réagit que si l'utilisateur est en mode 'auto'.
                if (preference.value === 'auto') recompute();
            };
            // addEventListener sur Chrome/Firefox modernes ; addListener pour Safari ancien
            if (typeof mediaQueryList.addEventListener === 'function') {
                mediaQueryList.addEventListener('change', mediaListener);
            } else if (typeof mediaQueryList.addListener === 'function') {
                mediaQueryList.addListener(mediaListener);
            }
        } catch (_) {
            // Silently ignore — kiosk reste en light
        }
    }

    function detachMediaListener() {
        if (!mediaQueryList || !mediaListener) return;
        try {
            if (typeof mediaQueryList.removeEventListener === 'function') {
                mediaQueryList.removeEventListener('change', mediaListener);
            } else if (typeof mediaQueryList.removeListener === 'function') {
                mediaQueryList.removeListener(mediaListener);
            }
        } catch (_) {}
        mediaQueryList = null;
        mediaListener = null;
    }

    /**
     * Si le store change la préférence depuis ailleurs (admin push, sync), on suit.
     */
    let unwatchStore = null;
    function watchStore() {
        if (!store?.watch) return;
        try {
            unwatchStore = store.watch(
                (state) => state.kioskSettings?.theme,
                (next) => {
                    const coerced = coerce(next);
                    if (coerced !== preference.value) {
                        preference.value = coerced;
                        recompute();
                    }
                },
            );
        } catch (_) {}
    }

    onMounted(() => {
        readInitial();
        recompute();
        attachMediaListener();
        watchStore();
    });

    onBeforeUnmount(() => {
        detachMediaListener();
        if (typeof unwatchStore === 'function') {
            try { unwatchStore(); } catch (_) {}
        }
    });

    /** Helper : true si le rendu actuel est en dark (utile pour conditionner du JS) */
    const isDark = computed(() => resolved.value === 'dark');

    /** Helper : true si l'utilisateur a explicitement choisi (pas 'auto') */
    const isManualPick = computed(() => preference.value !== 'auto');

    return {
        /** Préférence utilisateur — 'light' | 'dark' | 'auto' */
        preference,
        /** Thème effectif appliqué — 'light' | 'dark' (jamais 'auto') */
        resolved,
        /** true si dark mode actif */
        isDark,
        /** true si l'utilisateur a explicitement choisi (non 'auto') */
        isManualPick,
        /** Setter — accepte 'light' | 'dark' | 'auto' */
        setTheme,
        /** Force un recalcul manuel (debug) */
        recompute,
        /** Liste des valeurs supportées */
        supportedThemes: SUPPORTED_THEMES.slice(),
    };
}

/**
 * Bootstrap synchrone — à appeler depuis l'entry kiosk AVANT le mount Vue
 * pour éviter le FOUC. Lit localStorage et applique l'attribut <html> tout
 * de suite (avant que le bundle Vue n'initialise).
 *
 * Usage (resources/js/bootstrap-kiosk.js) :
 *   import { bootstrapKioskThemeEarly } from './composables/useKioskTheme';
 *   bootstrapKioskThemeEarly();
 */
export function bootstrapKioskThemeEarly() {
    if (typeof window === 'undefined' || typeof document === 'undefined') return;
    let initial = 'auto';
    try {
        const fromLs = window.localStorage?.getItem('kiosk_theme_v1');
        if (fromLs && SUPPORTED_THEMES.includes(fromLs)) initial = fromLs;
    } catch (_) {}
    const resolved = initial === 'auto' ? resolveAuto() : initial;
    applyHtmlTheme(resolved);
}

export default useKioskTheme;
