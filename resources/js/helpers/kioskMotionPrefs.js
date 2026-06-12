/**
 * kioskMotionPrefs — [dispute-r1 D-005 2026-06-12]
 * -----------------------------------------------------------------------------
 * Round-1 adversarial (D-borne-robustesse, probe D-005b) : l'option
 * « Animations réduites » du drawer a11y était TRIPLEMENT inerte :
 *   1. pas de watcher runtime — `KioskAppComponent._wireA11yWatchers()` (frozen)
 *      ne câble que contrast/pmr/audio ;
 *   2. le composable `useKioskA11y()` (qui contient les watchers
 *      reducedMotion/audioDescription) n'a AUCUN consommateur en prod ;
 *   3. `kioskSettings.reducedMotion`/`audioDescription` sont ABSENTS des paths
 *      persistés vuex-persistedstate → même un F5 ne ré-appliquait rien.
 * Un switch a11y affiché + annoncé WCAG 2.3.3 qui ne fait RIEN = placebo pour
 * l'utilisateur vestibulaire qui en a besoin.
 *
 * KioskAppComponent étant FROZEN, le câblage vit ici (helper) et est invoqué :
 *   - par KsA11ySettings.vue à chaque toggle (application LIVE + persistance) ;
 *   - par le guard kiosk (kioskRoutes.requireKioskAuth) au boot pour
 *     ré-hydrater le store depuis localStorage AVANT le mount du shell — le
 *     `applyKioskA11yFromStore` one-shot du shell (frozen) relit alors le
 *     store déjà hydraté.
 *
 * Effets appliqués :
 *   - attributs <html> data-kiosk-reduced-motion / data-kiosk-audio-description
 *     (sélecteurs CSS par-composant déjà présents) ;
 *   - classe globale `html.ks-reduced-motion` consommée par la règle
 *     kill-animations de resources/css/kiosk/tokens.css.
 */
import { applyKioskA11yFromStore } from '../composables/useKioskA11y';

export const KIOSK_MOTION_PREFS_STORAGE_KEY = 'foodking:kiosk-a11y-motion';
export const KIOSK_REDUCED_MOTION_CLASS = 'ks-reduced-motion';

/**
 * Applique l'état courant du store sur <html> : attributs data-kiosk-* +
 * classe globale ks-reduced-motion. Idempotent.
 */
export function applyKioskMotionPrefs(store) {
    if (!store || typeof document === 'undefined' || !document.documentElement) return;
    // Ré-applique TOUS les attributs a11y (contrast/pmr/audio inclus) — la
    // fonction est idempotente et skip les attributs déjà à jour.
    applyKioskA11yFromStore(store);
    const reduced = !!store.state?.kioskSettings?.reducedMotion;
    try {
        document.documentElement.classList.toggle(KIOSK_REDUCED_MOTION_CLASS, reduced);
    } catch (_) { /* defensive */ }
}

/**
 * Persiste reducedMotion/audioDescription dans localStorage (les paths
 * vuex-persistedstate ne couvrent pas ces deux toggles — hors périmètre
 * frozen-safe de ce heal).
 */
export function persistKioskMotionPrefs(store) {
    if (!store || typeof window === 'undefined') return;
    try {
        const s = store.state?.kioskSettings || {};
        window.localStorage?.setItem(KIOSK_MOTION_PREFS_STORAGE_KEY, JSON.stringify({
            reducedMotion: !!s.reducedMotion,
            audioDescription: !!s.audioDescription,
        }));
    } catch (_) { /* private mode / quota — best-effort */ }
}

/**
 * Ré-hydrate le store depuis localStorage puis applique. Idempotent par
 * session (garde window.__kioskMotionPrefsHydrated) — appelé par le guard
 * kiosk à chaque navigation, ne travaille qu'une fois.
 */
export function hydrateKioskMotionPrefs(store) {
    if (!store || typeof window === 'undefined') return;
    if (window.__kioskMotionPrefsHydrated) return;
    window.__kioskMotionPrefsHydrated = true;
    let stored = null;
    try {
        const raw = window.localStorage?.getItem(KIOSK_MOTION_PREFS_STORAGE_KEY);
        if (raw) stored = JSON.parse(raw);
    } catch (_) { stored = null; }
    if (stored && typeof stored === 'object') {
        try {
            store.dispatch('kioskSettings/setReducedMotion', !!stored.reducedMotion);
            store.dispatch('kioskSettings/setAudioDescription', !!stored.audioDescription);
        } catch (_) { /* module absent (tests partiels) — no-op */ }
    }
    applyKioskMotionPrefs(store);
}

/** Test-only : ré-arme la garde d'hydratation. */
export function _resetKioskMotionPrefsForTests() {
    if (typeof window !== 'undefined') {
        delete window.__kioskMotionPrefsHydrated;
    }
}
