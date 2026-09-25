/**
 * [OPTIM SOUSCRIPTION 2026-09-25 · owner] « le client a du mal à créer un compte
 * directement lorsqu'il a mis son numéro ». Avant ce fix : un numéro non trouvé
 * affichait une simple erreur, et le client devait remarquer le lien "S'inscrire"
 * PUIS RETAPER son numéro dans un formulaire à 3 champs — double saisie, abandon
 * fréquent (souscription perdue).
 *
 * Ce spec prouve le nouveau parcours :
 *  (a) un numéro (forme téléphone) non trouvé bascule AUTOMATIQUEMENT sur
 *      l'inscription, sans jamais redemander le téléphone (déjà connu) ;
 *  (b) un code fidélité mal tapé (PAS une forme téléphone) garde l'ancien
 *      comportement — simple message d'erreur, jamais une inscription forcée
 *      sur une valeur qui n'est pas un numéro ;
 *  (c) l'entrée manuelle "S'inscrire" affiche toujours le formulaire complet
 *      (3 champs), même après un essai automatique précédent ;
 *  (d) "Ce n'est pas mon numéro" ramène proprement à la saisie.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';

import KioskLoyaltyComponent from '../../resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const i18n = createI18n({
    legacy: false, locale: 'fr', fallbackLocale: 'fr', messages: { fr: frMessages },
});

function makeStore() {
    return createStore({
        modules: {
            kioskCart: {
                namespaced: true,
                getters: { total: () => 20, upsellShown: () => false, items: () => [] },
                actions: { setLoyalty: vi.fn(), markUpsellShown: vi.fn() },
            },
            kioskMenu: { namespaced: true, getters: { categories: () => [] } },
            kioskSettings: { namespaced: true, state: () => ({ locale: 'fr' }) },
        },
    });
}

async function mountLoyalty() {
    const axios = (await import('axios')).default;
    vi.spyOn(axios, 'get').mockResolvedValue({ data: { data: {} } }); // loadConfig() au mount
    const w = mount(KioskLoyaltyComponent, {
        global: {
            plugins: [makeStore(), i18n],
            mocks: { $router: { push: vi.fn(), replace: vi.fn() }, $route: { query: {}, params: {} } },
            stubs: { KsConsentModal: true, KsVirtualKeyboard: true },
        },
    });
    return { w, axios };
}

describe('KioskLoyaltyComponent.looksLikePhoneNumber — heuristique client', () => {
    it('accepte les formes téléphone plausibles, rejette le reste', () => {
        const fn = (v) => KioskLoyaltyComponent.methods.looksLikePhoneNumber.call(null, v);
        expect(fn('0612345678')).toBe(true);
        expect(fn('+33612345678')).toBe(true);
        expect(fn('06 12 34 56 78')).toBe(true);
        expect(fn('06-12-34-56-78')).toBe(true);
        expect(fn('ABC12345')).toBe(false);
        expect(fn('A1B2C3D4')).toBe(false);
        expect(fn('1234')).toBe(false); // trop court pour un numéro réel
    });
});

describe('KioskLoyaltyComponent — inscription rapide déclenchée par un numéro non trouvé', () => {
    afterEach(() => vi.restoreAllMocks());

    it('(a) numéro non trouvé → bascule directe sur l\'inscription, téléphone déjà rempli et masqué', async () => {
        const { w, axios } = await mountLoyalty();
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 404, data: { message: 'Non trouvé' } } });

        await w.setData({ code: '0612345678' });
        await w.vm.checkLoyalty();
        await w.vm.$nextTick();

        expect(w.vm.step).toBe('register');
        expect(w.vm.phoneCapturedFromCheck).toBe(true);
        expect(w.vm.registerPhone).toBe('0612345678');
        expect(w.vm.error).toBeNull();
        // Le champ téléphone du formulaire est masqué (déjà connu) ; le numéro est confirmé à l'écran.
        expect(w.find('[data-testid="kiosk-loyalty-register-phone"]').exists()).toBe(false);
        expect(w.find('[data-testid="kiosk-loyalty-phone-confirm"]').text()).toContain('0612345678');
        // Seul le prénom reste à saisir.
        expect(w.find('[data-testid="kiosk-loyalty-register-name"]').exists()).toBe(true);
    });

    it('(b) code fidélité mal tapé (pas une forme téléphone) → erreur classique, PAS d\'inscription forcée', async () => {
        const { w, axios } = await mountLoyalty();
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 404, data: { message: 'Non trouvé' } } });

        await w.setData({ code: 'ZZTOPFAKE' });
        await w.vm.checkLoyalty();
        await w.vm.$nextTick();

        expect(w.vm.step).toBe('input');
        expect(w.vm.phoneCapturedFromCheck).toBe(false);
        expect(w.vm.error).toBeTruthy();
    });

    it('(c) "S\'inscrire" manuel affiche toujours le formulaire complet à 3 champs', async () => {
        const { w, axios } = await mountLoyalty();
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 404, data: { message: 'Non trouvé' } } });

        // D'abord un essai automatique (phoneCapturedFromCheck devient true)…
        await w.setData({ code: '0612345678' });
        await w.vm.checkLoyalty();
        await w.vm.$nextTick();
        expect(w.vm.phoneCapturedFromCheck).toBe(true);

        // …puis retour et entrée manuelle : le formulaire complet doit revenir, jamais figé sur "rapide".
        w.vm.backFromRegister();
        await w.vm.$nextTick();
        w.vm.openManualRegister();
        await w.vm.$nextTick();

        expect(w.vm.phoneCapturedFromCheck).toBe(false);
        expect(w.find('[data-testid="kiosk-loyalty-register-phone"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-loyalty-phone-confirm"]').exists()).toBe(false);
    });

    it('(d) "Ce n\'est pas mon numéro" efface le téléphone capturé et revient à la saisie', async () => {
        const { w, axios } = await mountLoyalty();
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 404, data: { message: 'Non trouvé' } } });

        await w.setData({ code: '0612345678' });
        await w.vm.checkLoyalty();
        await w.vm.$nextTick();

        await w.find('[data-testid="kiosk-loyalty-not-my-number"]').trigger('click');
        await w.vm.$nextTick();

        expect(w.vm.step).toBe('input');
        expect(w.vm.phoneCapturedFromCheck).toBe(false);
        expect(w.vm.registerPhone).toBe('');
    });
});
