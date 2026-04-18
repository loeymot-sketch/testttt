/**
 * Kiosk Design V1 — Phase 5.3 : idle timeouts configurables
 *
 * Vérifie :
 *  - Defaults : idleMs=180000, confirmMs=30000, receiptMs=30000.
 *  - setIdleTimeouts accepte un patch partiel (ex: juste idleMs).
 *  - Bornes min/max appliquées (clamp au lieu de rejet).
 *  - NaN / non-fini → reset à la default.
 *  - reset() restaure les defaults.
 *  - Getters dérivés idleTimeouts.
 */
import { describe, it, expect } from 'vitest';
import { createStore } from 'vuex';
import { kioskSettings, KIOSK_SETTINGS_CONSTANTS } from '../../resources/js/store/modules/kioskSettings';

function makeStore() { return createStore({ modules: { kioskSettings } }); }

describe('kioskSettings idle timeouts (Phase 5.3)', () => {
    it('expose les defaults', () => {
        const store = makeStore();
        expect(store.state.kioskSettings.idleMs).toBe(KIOSK_SETTINGS_CONSTANTS.IDLE_DEFAULTS.idleMs);
        expect(store.state.kioskSettings.confirmMs).toBe(KIOSK_SETTINGS_CONSTANTS.IDLE_DEFAULTS.confirmMs);
        expect(store.state.kioskSettings.receiptMs).toBe(KIOSK_SETTINGS_CONSTANTS.IDLE_DEFAULTS.receiptMs);
    });

    it('setIdleTimeouts applique un patch partiel', async () => {
        const store = makeStore();
        await store.dispatch('kioskSettings/setIdleTimeouts', { idleMs: 90000 });
        expect(store.state.kioskSettings.idleMs).toBe(90000);
        expect(store.state.kioskSettings.confirmMs).toBe(KIOSK_SETTINGS_CONSTANTS.IDLE_DEFAULTS.confirmMs);
    });

    it('clamp au min/max des bornes', async () => {
        const store = makeStore();
        await store.dispatch('kioskSettings/setIdleTimeouts', { idleMs: 1000, confirmMs: 999999, receiptMs: -500 });
        expect(store.state.kioskSettings.idleMs).toBe(KIOSK_SETTINGS_CONSTANTS.IDLE_BOUNDS.idleMs.min);
        expect(store.state.kioskSettings.confirmMs).toBe(KIOSK_SETTINGS_CONSTANTS.IDLE_BOUNDS.confirmMs.max);
        expect(store.state.kioskSettings.receiptMs).toBe(KIOSK_SETTINGS_CONSTANTS.IDLE_BOUNDS.receiptMs.min);
    });

    it('NaN → default', async () => {
        const store = makeStore();
        await store.dispatch('kioskSettings/setIdleTimeouts', { idleMs: 'abc' });
        expect(store.state.kioskSettings.idleMs).toBe(KIOSK_SETTINGS_CONSTANTS.IDLE_DEFAULTS.idleMs);
    });

    it('reset() restaure les defaults', async () => {
        const store = makeStore();
        await store.dispatch('kioskSettings/setIdleTimeouts', { idleMs: 60000 });
        expect(store.state.kioskSettings.idleMs).toBe(60000);
        await store.dispatch('kioskSettings/reset');
        expect(store.state.kioskSettings.idleMs).toBe(KIOSK_SETTINGS_CONSTANTS.IDLE_DEFAULTS.idleMs);
    });

    it('getter idleTimeouts retourne l\'objet composite', async () => {
        const store = makeStore();
        await store.dispatch('kioskSettings/setIdleTimeouts', { idleMs: 120000 });
        const t = store.getters['kioskSettings/idleTimeouts'];
        expect(t.idleMs).toBe(120000);
        expect(t.confirmMs).toBeGreaterThan(0);
        expect(t.receiptMs).toBeGreaterThan(0);
    });

    it('setConsentAnalytics / setConsentLoyalty indépendants', async () => {
        const store = makeStore();
        expect(store.state.kioskSettings.consentAnalytics).toBe(false);
        expect(store.state.kioskSettings.consentLoyalty).toBe(false);
        await store.dispatch('kioskSettings/setConsentAnalytics', true);
        expect(store.state.kioskSettings.consentAnalytics).toBe(true);
        expect(store.state.kioskSettings.consentLoyalty).toBe(false);
    });

    it('reset() ne touche PAS aux consents (choix légal)', async () => {
        const store = makeStore();
        await store.dispatch('kioskSettings/setConsentLoyalty', true);
        await store.dispatch('kioskSettings/reset');
        expect(store.state.kioskSettings.consentLoyalty).toBe(true);
    });
});
