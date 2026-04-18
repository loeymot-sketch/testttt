/**
 * Kiosk Design V1 — Phase 5.4 : KsConsentModal RGPD
 *
 * Vérifie :
 *  - Modal ne rend rien si modelValue=false.
 *  - Checkboxes sont NON pré-cochées (§1.6 RGPD).
 *  - CTA "accept" bloqué si aucune case cochée.
 *  - Accept avec loyalty=true + phone→ POST /loyalty/opt-in (axios.post).
 *  - Decline → pas de POST opt-in, event `declined` émis.
 *  - Toggle checkbox met à jour consent dans le store.
 *  - role=dialog + aria-modal + aria-labelledby.
 *  - Lien privacy ouvre le sous-dialog.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';

import KsConsentModal from '../../resources/js/components/frontend/kiosk/ds/KsConsentModal.vue';
import { kioskSettings } from '../../resources/js/store/modules/kioskSettings';
import frMessages from '../../resources/js/languages/fr.json';

function makeI18n() { return createI18n({ legacy: false, locale: 'fr', messages: { fr: frMessages } }); }
function makeStore() { return createStore({ modules: { kioskSettings } }); }

async function flush() {
    await new Promise((r) => setTimeout(r, 0));
    await new Promise((r) => setTimeout(r, 0));
}

describe('KsConsentModal', () => {
    let postSpy;
    beforeEach(() => {
        postSpy = vi.fn().mockResolvedValue({ data: { status: true, data: {} } });
        window.axios = { post: postSpy };
    });

    it('ne rend rien si modelValue=false', () => {
        const store = makeStore();
        const w = mount(KsConsentModal, {
            props: { modelValue: false },
            global: { plugins: [store, makeI18n()] },
        });
        expect(w.find('[data-testid="kiosk-consent-modal"]').exists()).toBe(false);
    });

    it('checkboxes non pré-cochées par défaut', () => {
        const store = makeStore();
        const w = mount(KsConsentModal, {
            props: { modelValue: true },
            global: { plugins: [store, makeI18n()] },
        });
        const loyalty = w.find('[data-testid="kiosk-consent-loyalty"]').element;
        const analytics = w.find('[data-testid="kiosk-consent-analytics"]').element;
        expect(loyalty.checked).toBe(false);
        expect(analytics.checked).toBe(false);
    });

    it('role=dialog + aria-modal + aria-labelledby', () => {
        const store = makeStore();
        const w = mount(KsConsentModal, {
            props: { modelValue: true },
            global: { plugins: [store, makeI18n()] },
        });
        const dialog = w.find('[data-testid="kiosk-consent-modal"]');
        expect(dialog.attributes('role')).toBe('dialog');
        expect(dialog.attributes('aria-modal')).toBe('true');
        expect(dialog.attributes('aria-labelledby')).toBeTruthy();
    });

    it('accept sans aucune case cochée affiche erreur et ne ferme pas', async () => {
        const store = makeStore();
        const w = mount(KsConsentModal, {
            props: { modelValue: true },
            global: { plugins: [store, makeI18n()] },
        });
        await w.find('[data-testid="kiosk-consent-accept"]').trigger('click');
        await flush();
        expect(w.find('[data-testid="kiosk-consent-loyalty-error"]').exists()).toBe(true);
        expect(w.emitted('update:modelValue')).toBeFalsy();
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('accept avec loyalty=true + phone → POST /loyalty/opt-in', async () => {
        const store = makeStore();
        const w = mount(KsConsentModal, {
            props: { modelValue: true, phone: '+33600000000', email: null, name: 'Test' },
            global: { plugins: [store, makeI18n()] },
        });
        await w.find('[data-testid="kiosk-consent-loyalty"]').setValue(true);
        await w.find('[data-testid="kiosk-consent-accept"]').trigger('click');
        await flush();
        expect(postSpy).toHaveBeenCalled();
        const optInCall = postSpy.mock.calls.find(([url]) => url === 'frontend/loyalty/opt-in');
        expect(optInCall).toBeTruthy();
        expect(optInCall[1].consent_accepted).toBe(true);
        expect(optInCall[1].phone).toBe('+33600000000');
        expect(w.emitted('accepted')).toBeTruthy();
        expect(store.state.kioskSettings.consentLoyalty).toBe(true);
    });

    it('decline émet declined + ferme la modale sans POST opt-in', async () => {
        const store = makeStore();
        const w = mount(KsConsentModal, {
            props: { modelValue: true, phone: '+33600000000' },
            global: { plugins: [store, makeI18n()] },
        });
        await w.find('[data-testid="kiosk-consent-decline"]').trigger('click');
        await flush();
        const optInCall = postSpy.mock.calls.find(([url]) => url === 'frontend/loyalty/opt-in');
        expect(optInCall).toBeFalsy();
        expect(w.emitted('declined')).toBeTruthy();
        expect(w.emitted('update:modelValue')[0]).toEqual([false]);
        expect(store.state.kioskSettings.consentLoyalty).toBe(false);
    });

    it('toggle analytics uniquement → consent loyalty reste false côté store', async () => {
        const store = makeStore();
        const w = mount(KsConsentModal, {
            props: { modelValue: true, phone: '+33600000000', persistLoyalty: true },
            global: { plugins: [store, makeI18n()] },
        });
        await w.find('[data-testid="kiosk-consent-analytics"]').setValue(true);
        await w.find('[data-testid="kiosk-consent-accept"]').trigger('click');
        await flush();
        expect(store.state.kioskSettings.consentAnalytics).toBe(true);
        expect(store.state.kioskSettings.consentLoyalty).toBe(false);
        const optInCall = postSpy.mock.calls.find(([url]) => url === 'frontend/loyalty/opt-in');
        expect(optInCall).toBeFalsy();
    });

    it('ouvre le sous-dialog privacy', async () => {
        const store = makeStore();
        const w = mount(KsConsentModal, {
            props: { modelValue: true },
            global: { plugins: [store, makeI18n()] },
        });
        await w.find('[data-testid="kiosk-consent-privacy-link"]').trigger('click');
        expect(w.find('[data-testid="kiosk-consent-privacy-modal"]').exists()).toBe(true);
        await w.find('[data-testid="kiosk-consent-privacy-close"]').trigger('click');
        expect(w.find('[data-testid="kiosk-consent-privacy-modal"]').exists()).toBe(false);
    });
});
