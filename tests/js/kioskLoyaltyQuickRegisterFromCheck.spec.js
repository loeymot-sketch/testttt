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

/**
 * [OPTIM SOUSCRIPTION 2026-09-25 · test-e2e et optimise] Deux taps de moins,
 * sur le trajet qui compte le plus (première interaction + seul champ requis) :
 *  (e) le numpad déclenche lui-même la vérification dès un numéro FR complet
 *      (10 chiffres) — jamais le champ texte v-model (tests, clavier externe) ;
 *  (f) l'écran d'inscription rapide ouvre tout seul le clavier virtuel sur le
 *      prénom — le client n'a rien à toucher pour commencer à taper.
 *  (g) le clavier ne doit jamais rester ouvert, orphelin, en quittant l'écran
 *      d'inscription (retour, "pas mon numéro", ou inscription réussie).
 */
describe('KioskLoyaltyComponent — deux taps économisés (numpad + auto-focus)', () => {
    afterEach(() => vi.restoreAllMocks());

    it('(e) le numpad déclenche la vérification tout seul au 10e chiffre', async () => {
        const { w, axios } = await mountLoyalty();
        const postSpy = vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 404, data: { message: 'Non trouvé' } } });

        for (const digit of '061234567') w.vm.handleNumpad(digit); // 9 chiffres : rien ne part encore
        expect(postSpy).not.toHaveBeenCalled();

        w.vm.handleNumpad('8'); // 10e chiffre : la vérification part toute seule
        await w.vm.$nextTick();

        expect(postSpy).toHaveBeenCalledWith('frontend/loyalty/check', { code: '0612345678' }, expect.anything());
    });

    it('(e-bis) taper via v-model (pas le numpad) ne déclenche JAMAIS l\'auto-vérification', async () => {
        const { w, axios } = await mountLoyalty();
        const postSpy = vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 404, data: { message: 'Non trouvé' } } });

        await w.setData({ code: '0612345678' }); // 10 chiffres posés directement, jamais via handleNumpad
        await w.vm.$nextTick();

        expect(postSpy, 'seul le numpad auto-soumet ; le champ texte reste un canal manuel').not.toHaveBeenCalled();
    });

    it('(e-ter) un code fidélité de 10 caractères (pas une forme téléphone) ne déclenche pas l\'auto-vérification', async () => {
        const { w, axios } = await mountLoyalty();
        const postSpy = vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 404, data: { message: 'Non trouvé' } } });

        // 10 touches numpad, mais un del a produit une valeur alphanumérique — n'arrive jamais
        // en pratique via un numpad numérique seul ; on force l'état pour couvrir la garde.
        await w.setData({ code: '061234567' });
        w.vm.handleNumpad('A'); // valeur hypothétique non numérique — la garde regex doit refuser
        await w.vm.$nextTick();
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('(f) l\'inscription rapide ouvre le clavier virtuel sur le prénom sans action du client', async () => {
        const { w, axios } = await mountLoyalty();
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 404, data: { message: 'Non trouvé' } } });

        await w.setData({ code: '0612345678' });
        await w.vm.checkLoyalty();
        await w.vm.$nextTick();
        await w.vm.$nextTick(); // le second tick correspond au $nextTick() interne qui ouvre le clavier

        expect(w.vm.vkeybActiveField).toBe('registerName');
    });

    it('(g) "Ce n\'est pas mon numéro" referme le clavier virtuel (jamais orphelin sur l\'écran de saisie)', async () => {
        const { w, axios } = await mountLoyalty();
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 404, data: { message: 'Non trouvé' } } });

        await w.setData({ code: '0612345678' });
        await w.vm.checkLoyalty();
        await w.vm.$nextTick();
        await w.vm.$nextTick();
        expect(w.vm.vkeybActiveField).toBe('registerName');

        w.vm.backFromRegister();
        expect(w.vm.vkeybActiveField, 'le clavier ne doit jamais flotter par-dessus l\'écran de saisie du numéro').toBeNull();
    });

    it('(g-bis) une inscription réussie referme le clavier virtuel avant d\'afficher le solde', async () => {
        const { w, axios } = await mountLoyalty();
        vi.spyOn(axios, 'post')
            .mockResolvedValueOnce({ data: { data: { name: 'Rapide', points: 0, loyalty_code: 'ABCDEF12' } } });

        await w.setData({
            step: 'register',
            phoneCapturedFromCheck: true,
            vkeybActiveField: 'registerName',
            registerName: 'Rapide',
            registerPhone: '0612345678',
        });
        w.vm._pendingRegister = null;
        // Consentement déjà donné pour isoler ce comportement de la modale RGPD.
        w.vm.$store.state.kioskSettings = { consentLoyalty: true, locale: 'fr' };
        await w.vm.submitRegister();
        await w.vm.$nextTick();

        expect(w.vm.step).toBe('balance');
        expect(w.vm.vkeybActiveField, 'le clavier ne doit jamais flotter par-dessus le solde').toBeNull();
    });

    /**
     * [Root cause 2026-09-25 · trouvé par le test-e2e réel] Le clavier virtuel
     * n'a pas de fermeture "tap outside" — sa touche ✓ (onVkeybSubmit) est
     * l'unique geste qui le ferme. Sa logique de routage (prénom → téléphone →
     * email → soumettre) suppose l'ORDRE COMPLET à 3 champs. En parcours rapide,
     * le téléphone est déjà rempli : ✓ après le prénom sautait donc vers un champ
     * email TOUJOURS vide (jamais visité), forçant un second ✓ juste pour le
     * sauter — un clavier resté ouvert masque en prime le bouton "Créer mon
     * compte" en dessous (repro E2E réelle : clic qui boucle indéfiniment).
     */
    it('(h) ✓ du clavier après le prénom soumet DIRECTEMENT en parcours rapide (jamais un détour par l\'email vide)', async () => {
        const { w } = await mountLoyalty();
        const submitSpy = vi.spyOn(w.vm, 'submitRegister').mockResolvedValue();
        await w.setData({
            step: 'register',
            phoneCapturedFromCheck: true,
            vkeybActiveField: 'registerName',
            registerName: 'Rapide',
            registerPhone: '0612345678',
            registerEmail: '',
        });

        w.vm.onVkeybSubmit();

        expect(w.vm.vkeybActiveField, 'le clavier doit se fermer directement, jamais router vers l\'email').toBeNull();
        expect(submitSpy).toHaveBeenCalledTimes(1);
    });

    it('(h-bis) en formulaire complet (manuel), ✓ après le prénom route toujours vers le téléphone (comportement inchangé)', async () => {
        const { w } = await mountLoyalty();
        const submitSpy = vi.spyOn(w.vm, 'submitRegister').mockResolvedValue();
        await w.setData({
            step: 'register',
            phoneCapturedFromCheck: false,
            vkeybActiveField: 'registerName',
            registerName: 'Jean',
            registerPhone: '',
        });

        w.vm.onVkeybSubmit();

        expect(w.vm.vkeybActiveField).toBe('registerPhone');
        expect(submitSpy).not.toHaveBeenCalled();
    });
});
