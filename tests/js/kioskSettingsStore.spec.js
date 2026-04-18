/**
 * Kiosk Design V1 — Phase 4.1 : module Vuex kioskSettings
 *
 * Vérifie :
 *  - Defaults (fr / aa / pmr:false / audio:false)
 *  - Coercion types (string/bool/invalid → valeurs sûres)
 *  - Actions : setLocale/setContrast/setPmr/setAudio/reset
 *  - Getters dérivés : isRtl (ar only), isAAA, supportedLocales, analyticsSnapshot
 *  - analyticsSnapshot ne contient aucune PII (seuls flags/codes).
 *  - Reset restaure les defaults strictement.
 */
import { describe, it, expect } from 'vitest';
import { createStore } from 'vuex';
import { kioskSettings, KIOSK_SETTINGS_CONSTANTS } from '../../resources/js/store/modules/kioskSettings';

function makeStore() {
    return createStore({ modules: { kioskSettings } });
}

describe('kioskSettings Vuex module', () => {
    it('exposes safe defaults', () => {
        const store = makeStore();
        expect(store.state.kioskSettings.locale).toBe('fr');
        expect(store.state.kioskSettings.contrast).toBe('aa');
        expect(store.state.kioskSettings.pmr).toBe(false);
        expect(store.state.kioskSettings.audio).toBe(false);
        expect(store.state.kioskSettings.keyboardEnabled).toBe(true);
    });

    it('setLocale coerces invalid values to fr', async () => {
        const store = makeStore();
        await store.dispatch('kioskSettings/setLocale', 'zz');
        expect(store.state.kioskSettings.locale).toBe('fr');
        await store.dispatch('kioskSettings/setLocale', null);
        expect(store.state.kioskSettings.locale).toBe('fr');
        await store.dispatch('kioskSettings/setLocale', 'EN');
        expect(store.state.kioskSettings.locale).toBe('en');
        await store.dispatch('kioskSettings/setLocale', 'ar-SA');
        expect(store.state.kioskSettings.locale).toBe('ar');
    });

    it('setContrast accepts aa/aaa and rejects others', async () => {
        const store = makeStore();
        await store.dispatch('kioskSettings/setContrast', 'aaa');
        expect(store.state.kioskSettings.contrast).toBe('aaa');
        await store.dispatch('kioskSettings/setContrast', 'xxx');
        expect(store.state.kioskSettings.contrast).toBe('aa');
    });

    it('setPmr/setAudio coerce "true" strings and numbers', async () => {
        const store = makeStore();
        await store.dispatch('kioskSettings/setPmr', 'true');
        expect(store.state.kioskSettings.pmr).toBe(true);
        await store.dispatch('kioskSettings/setPmr', 0);
        expect(store.state.kioskSettings.pmr).toBe(false);
        await store.dispatch('kioskSettings/setAudio', 1);
        expect(store.state.kioskSettings.audio).toBe(true);
    });

    it('isRtl and isAAA derived getters', async () => {
        const store = makeStore();
        expect(store.getters['kioskSettings/isRtl']).toBe(false);
        expect(store.getters['kioskSettings/isAAA']).toBe(false);
        await store.dispatch('kioskSettings/setLocale', 'ar');
        expect(store.getters['kioskSettings/isRtl']).toBe(true);
        await store.dispatch('kioskSettings/setContrast', 'aaa');
        expect(store.getters['kioskSettings/isAAA']).toBe(true);
    });

    it('supportedLocales matches constants', () => {
        const store = makeStore();
        expect(store.getters['kioskSettings/supportedLocales']).toEqual(['fr', 'en', 'ar']);
        expect(KIOSK_SETTINGS_CONSTANTS.SUPPORTED_LOCALES).toEqual(['fr', 'en', 'ar']);
        expect(KIOSK_SETTINGS_CONSTANTS.SUPPORTED_CONTRASTS).toEqual(['aa', 'aaa']);
    });

    it('analyticsSnapshot contains no PII', async () => {
        const store = makeStore();
        await store.dispatch('kioskSettings/setLocale', 'en');
        await store.dispatch('kioskSettings/setContrast', 'aaa');
        await store.dispatch('kioskSettings/setPmr', true);
        await store.dispatch('kioskSettings/setAudio', true);
        const snap = store.getters['kioskSettings/analyticsSnapshot'];
        expect(snap).toEqual({
            locale: 'en',
            contrast: 'aaa',
            pmr: true,
            audio: true,
        });
        // Strict whitelist — aucune clé inattendue
        expect(Object.keys(snap).sort()).toEqual(['audio', 'contrast', 'locale', 'pmr']);
    });

    it('reset restaures les defaults', async () => {
        const store = makeStore();
        await store.dispatch('kioskSettings/setLocale', 'ar');
        await store.dispatch('kioskSettings/setContrast', 'aaa');
        await store.dispatch('kioskSettings/setPmr', true);
        await store.dispatch('kioskSettings/setAudio', true);
        await store.dispatch('kioskSettings/reset');
        expect(store.state.kioskSettings.locale).toBe('fr');
        expect(store.state.kioskSettings.contrast).toBe('aa');
        expect(store.state.kioskSettings.pmr).toBe(false);
        expect(store.state.kioskSettings.audio).toBe(false);
        expect(store.state.kioskSettings.keyboardEnabled).toBe(true);
    });
});
