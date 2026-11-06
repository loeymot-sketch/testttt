/**
 * [DÉCISION PROPRIÉTAIRE 2026-11-06 · borne « QR, téléphone, e-mail, plein de choix »]
 *  (a) trois onglets d'identification ; (b) le QR envoie le contenu SCANNÉ (au-delà de 20 caractères) ;
 *  (c) l'e-mail envoie l'adresse, un 404 dit « aucun compte avec cette adresse » et ne bascule JAMAIS vers
 *      l'inscription par téléphone ; (d) un QR rejeté vide le champ pour le scan suivant ;
 *  (e) le rachat ne porte que sur les points DÉPENSABLES et la borne dit ce qui attend la remise.
 */
import { describe, it, expect, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';

import KioskLoyaltyComponent from '../../resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'fr', messages: { fr: frMessages } });

function makeStore() {
    return createStore({
        modules: {
            kioskCart: { namespaced: true, getters: { total: () => 20, upsellShown: () => false, items: () => [] }, actions: { setLoyalty: vi.fn(), markUpsellShown: vi.fn() } },
            kioskMenu: { namespaced: true, getters: { categories: () => [] } },
            kioskSettings: { namespaced: true, state: () => ({ locale: 'fr' }) },
        },
    });
}

async function monter() {
    const axios = (await import('axios')).default;
    vi.spyOn(axios, 'get').mockResolvedValue({ data: { data: { min_redeem_points: 1000 } } });
    const w = mount(KioskLoyaltyComponent, {
        global: {
            plugins: [makeStore(), i18n],
            mocks: { $router: { push: vi.fn(), replace: vi.fn() }, $route: { query: {}, params: {} } },
            stubs: { KsConsentModal: true, KsVirtualKeyboard: true },
        },
    });
    return { w, axios };
}

describe('Borne — identification par QR, téléphone ou e-mail', () => {
    afterEach(() => vi.restoreAllMocks());

    it('(a) trois onglets', async () => {
        const { w } = await monter();
        expect(w.find('[data-testid="kiosk-loyalty-mode-tel"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-loyalty-mode-qr"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-loyalty-mode-email"]').exists()).toBe(true);
    });

    it('(b) QR : le contenu scanné entier est envoyé', async () => {
        const { w, axios } = await monter();
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { data: { name: 'Léa', points: 1200, points_depensables: 1200, loyalty_code: 'ABC' } } });
        await w.find('[data-testid="kiosk-loyalty-mode-qr"]').trigger('click');
        const jeton = 'lqr.' + 'x'.repeat(120) + '.signature';
        await w.find('[data-testid="kiosk-loyalty-qr-input"]').setValue(jeton);
        await w.vm.checkLoyalty();
        expect(post).toHaveBeenCalledWith('frontend/loyalty/check', { code: jeton }, expect.anything());
        expect(w.vm.step).toBe('balance');
    });

    it('(c) e-mail inconnu : message dédié, jamais l\'inscription par téléphone', async () => {
        const { w, axios } = await monter();
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 404, data: { message: 'Non trouvé' } } });
        await w.find('[data-testid="kiosk-loyalty-mode-email"]').trigger('click');
        await w.find('[data-testid="kiosk-loyalty-email-input"]').setValue('lea@example.fr');
        await w.vm.checkLoyalty();
        expect(w.vm.step).toBe('input');
        expect(w.vm.error).toBe(frMessages.kiosk.loyalty_screen.email_not_found);
    });

    it('(d) QR rejeté : le champ se vide pour le scan suivant', async () => {
        const { w, axios } = await monter();
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 422, data: { message: 'QR expiré ou déjà utilisé : affichez-le à nouveau dans votre application.' } } });
        await w.find('[data-testid="kiosk-loyalty-mode-qr"]').trigger('click');
        await w.find('[data-testid="kiosk-loyalty-qr-input"]').setValue('lqr.vieux.jeton');
        await w.vm.checkLoyalty();
        expect(w.vm.qrSaisie).toBe('');
        expect(w.vm.error).toContain('QR expiré');
    });

    it('(e) rachat sur les points dépensables, et la borne dit ce qui attend', async () => {
        const { w, axios } = await monter();
        vi.spyOn(axios, 'post').mockResolvedValue({ data: { data: { name: 'Léa', points: 1500, points_depensables: 600, points_pending: 900, loyalty_code: 'ABC' } } });
        await w.setData({ code: 'ABC12345', minRedeemPoints: 1000 });
        await w.vm.checkLoyalty();
        await w.vm.$nextTick();
        expect(w.vm.canRedeem).toBe(false);
        const t = w.text();
        expect(t).toContain('900');
        expect(t).toMatch(/dès la remise de vos commandes en cours/);
    });
});
