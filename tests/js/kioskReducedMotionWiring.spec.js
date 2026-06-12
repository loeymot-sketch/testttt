/**
 * [dispute-r1 D-005 2026-06-12] — « Animations réduites » TRIPLEMENT inerte.
 * -----------------------------------------------------------------------------
 * Round-1 adversarial (probe D-005b, live) : clic switch → aria-checked=true,
 * store Vuex LIVE reducedMotion=true, MAIS data-kiosk-reduced-motion reste
 * "false" ; F5 → le store retombe à false (toggle absent des paths persistés).
 * Triple trou : (1) pas de watcher runtime (shell frozen ne câble que
 * contrast/pmr/audio), (2) composable useKioskA11y jamais monté en prod,
 * (3) pas de persistance. Placebo pur.
 *
 * Invariants verrouillés :
 *  1. Le toggle du drawer applique LIVE : data-kiosk-reduced-motion="true" +
 *     classe html.ks-reduced-motion.
 *  2. La préférence est persistée (localStorage) et ré-hydratée au boot via
 *     le guard kiosk (kioskRoutes) — survit au F5.
 *  3. tokens.css contient la règle kill-animations html.ks-reduced-motion.
 *  4. Même câblage pour audioDescription (même trou de watcher).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { readFileSync } from 'fs';
import { resolve } from 'path';

import KsA11ySettings from '../../resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue';
import {
    applyKioskMotionPrefs,
    persistKioskMotionPrefs,
    hydrateKioskMotionPrefs,
    _resetKioskMotionPrefsForTests,
    KIOSK_MOTION_PREFS_STORAGE_KEY,
    KIOSK_REDUCED_MOTION_CLASS,
} from '../../resources/js/helpers/kioskMotionPrefs';
import frMessages from '../../resources/js/languages/fr.json';

const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'fr',
    messages: { fr: frMessages },
});

/** Store factice : dispatch applique réellement la mutation sur state. */
function makeStore(initial = {}) {
    const state = {
        kioskSettings: {
            contrast: 'aa',
            pmr: false,
            audio: false,
            audioDescription: false,
            reducedMotion: false,
            locale: 'fr',
            ...initial,
        },
    };
    const dispatched = [];
    return {
        state,
        dispatched,
        dispatch: vi.fn((type, payload) => {
            dispatched.push({ type, payload });
            if (type === 'kioskSettings/setReducedMotion') state.kioskSettings.reducedMotion = !!payload;
            if (type === 'kioskSettings/setAudioDescription') state.kioskSettings.audioDescription = !!payload;
            if (type === 'kioskSettings/reset') {
                state.kioskSettings.reducedMotion = false;
                state.kioskSettings.audioDescription = false;
            }
            return Promise.resolve();
        }),
    };
}

function cleanDom() {
    const el = document.documentElement;
    el.classList.remove(KIOSK_REDUCED_MOTION_CLASS);
    el.removeAttribute('data-kiosk-reduced-motion');
    el.removeAttribute('data-kiosk-audio-description');
}

beforeEach(() => {
    cleanDom();
    window.localStorage.clear();
    _resetKioskMotionPrefsForTests();
});

describe('[D-005] toggle drawer → application LIVE', () => {
    function mountDrawer(store) {
        return mount(KsA11ySettings, {
            props: { modelValue: true },
            global: {
                plugins: [i18n],
                mocks: { $store: store },
            },
        });
    }

    it('activer « Animations réduites » pose data-kiosk-reduced-motion + html.ks-reduced-motion SANS F5', async () => {
        const store = makeStore();
        const wrapper = mountDrawer(store);

        await wrapper.find('[data-testid="kiosk-a11y-reduced-motion-toggle"]').trigger('click');

        expect(store.dispatch).toHaveBeenCalledWith('kioskSettings/setReducedMotion', true);
        expect(document.documentElement.getAttribute('data-kiosk-reduced-motion')).toBe('true');
        expect(document.documentElement.classList.contains(KIOSK_REDUCED_MOTION_CLASS)).toBe(true);
    });

    it('désactiver retire classe + attribut', async () => {
        const store = makeStore({ reducedMotion: true });
        applyKioskMotionPrefs(store);
        expect(document.documentElement.classList.contains(KIOSK_REDUCED_MOTION_CLASS)).toBe(true);

        const wrapper = mountDrawer(store);
        await wrapper.find('[data-testid="kiosk-a11y-reduced-motion-toggle"]').trigger('click');

        expect(document.documentElement.getAttribute('data-kiosk-reduced-motion')).toBe('false');
        expect(document.documentElement.classList.contains(KIOSK_REDUCED_MOTION_CLASS)).toBe(false);
    });

    it('le toggle audioDescription applique data-kiosk-audio-description (même trou de watcher)', async () => {
        const store = makeStore();
        const wrapper = mountDrawer(store);

        await wrapper.find('[data-testid="kiosk-a11y-audio-description-toggle"]').trigger('click');

        expect(document.documentElement.getAttribute('data-kiosk-audio-description')).toBe('true');
    });

    it('reset du drawer nettoie classe + persiste l’état neutre', async () => {
        const store = makeStore({ reducedMotion: true });
        applyKioskMotionPrefs(store);
        const wrapper = mountDrawer(store);

        await wrapper.find('[data-testid="kiosk-a11y-reset"]').trigger('click');

        expect(document.documentElement.classList.contains(KIOSK_REDUCED_MOTION_CLASS)).toBe(false);
        const stored = JSON.parse(window.localStorage.getItem(KIOSK_MOTION_PREFS_STORAGE_KEY));
        expect(stored.reducedMotion).toBe(false);
    });
});

describe('[D-005] persistance + ré-hydratation boot (survit au F5)', () => {
    it('persist écrit reducedMotion + audioDescription', () => {
        const store = makeStore({ reducedMotion: true, audioDescription: true });
        persistKioskMotionPrefs(store);
        const stored = JSON.parse(window.localStorage.getItem(KIOSK_MOTION_PREFS_STORAGE_KEY));
        expect(stored).toEqual({ reducedMotion: true, audioDescription: true });
    });

    it('hydrate relit localStorage, redispatch et ré-applique classe + attribut', () => {
        window.localStorage.setItem(
            KIOSK_MOTION_PREFS_STORAGE_KEY,
            JSON.stringify({ reducedMotion: true, audioDescription: false }),
        );
        const store = makeStore(); // état boot par défaut : false (probe D-005b)

        hydrateKioskMotionPrefs(store);

        expect(store.dispatch).toHaveBeenCalledWith('kioskSettings/setReducedMotion', true);
        expect(document.documentElement.getAttribute('data-kiosk-reduced-motion')).toBe('true');
        expect(document.documentElement.classList.contains(KIOSK_REDUCED_MOTION_CLASS)).toBe(true);
    });

    it('hydrate est idempotent par session (garde window)', () => {
        const store = makeStore();
        hydrateKioskMotionPrefs(store);
        hydrateKioskMotionPrefs(store);
        // setReducedMotion ne doit pas être redispatché au 2e passage
        const calls = store.dispatched.filter((d) => d.type === 'kioskSettings/setReducedMotion');
        expect(calls.length).toBeLessThanOrEqual(1);
    });

    it('le guard kiosk (kioskRoutes) appelle hydrateKioskMotionPrefs', () => {
        const src = readFileSync(
            resolve(process.cwd(), 'resources/js/router/modules/kioskRoutes.js'),
            'utf-8',
        );
        expect(src).toMatch(/hydrateKioskMotionPrefs\(store\)/);
    });
});

describe('[D-005] règle CSS kill-animations', () => {
    it('tokens.css neutralise animations/transitions sous html.ks-reduced-motion', () => {
        const css = readFileSync(resolve(process.cwd(), 'resources/css/kiosk/tokens.css'), 'utf-8');
        expect(css).toMatch(/html\.ks-reduced-motion \*,/);
        expect(css).toMatch(/animation-duration:\s*0\.001ms !important/);
        expect(css).toMatch(/transition-duration:\s*0\.001ms !important/);
    });
});
