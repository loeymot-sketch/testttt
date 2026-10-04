/**
 * borneBolSaucesMultiples.spec.js
 * -----------------------------------------------------------------------------
 * [owner 2026-09-02] « Pour les bols, j'arrive pas à choisir d'autres sauces :
 * parfois les gens veulent la sauce fromagère ET une barbecue en plus. »
 *
 * Où le blocage se produisait RÉELLEMENT : sur la BORNE, pas à la caisse.
 * `KioskStepGenericChoicesComponent.toggleChoice()` traite `max_select === 1`
 * comme un choix EXCLUSIF (:142) — il efface la sélection précédente avant de
 * poser la nouvelle. Les profils composeur publiés des bols portaient
 * `max_select = 1` : le client ne pouvait donc JAMAIS cumuler deux sauces.
 *
 * Vérifié pendant l'enquête : la caisse (`public/js/pos-wizard.js`) n'applique
 * PAS ce plafond sur ses puces de sauce — c'est bien la borne qui portait le
 * défaut. Ce banc garde donc le composant qui décide vraiment.
 *
 * Décision propriétaire 2026-09-02 : sauces ILLIMITÉES sur les bols
 * (`max_select = 99`), 1ère offerte puis +0,50 € par sauce supplémentaire
 * (facturation déjà assurée par l'extra « Sauce supplémentaire »).
 *
 * Le composant est CONTRÔLÉ : il n'a pas d'état interne, il émet
 * `update('composerChoices', all)` et c'est le parent qui ré-injecte
 * `selections`. Le harnais ci-dessous rejoue fidèlement cette boucle, sinon le
 * 2e clic repartirait d'un état vide et le test ne prouverait rien.
 */
import { describe, it, expect } from 'vitest';
import { createI18n } from 'vue-i18n';
import { mount } from '@vue/test-utils';
import KioskStepGenericChoicesComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'fr',
    messages: { fr: frMessages },
});

const SAUCES_BOL = [
    { id: 9101, name: 'Fromagère maison' },
    { id: 9102, name: 'Barbecue' },
    { id: 9103, name: 'Algérienne' },
];

/**
 * Monte l'étape sauce d'un bol avec le plafond voulu et rejoue la boucle
 * parent → enfant : chaque `update` émis redevient l'état `selections`.
 * Retourne un cliqueur et un lecteur du nombre de sauces réellement retenues.
 */
async function monterEtapeSauce(maxSelect) {
    const step = {
        type: 'generic_choices',
        id: 'sauce',
        step_key: 'sauce',
        label: 'Choix de la sauce',
        min_select: 1,
        max_select: maxSelect,
        allow_repeat: false,
        choices: SAUCES_BOL,
    };

    const wrapper = mount(KioskStepGenericChoicesComponent, {
        global: { plugins: [i18n] },
        props: { step, selections: { composerChoices: {} } },
    });

    // Boucle de contrôle : on applique l'émission comme le ferait le wizard borne.
    wrapper.vm.$.vnode.props = wrapper.vm.$.vnode.props || {};
    const appliquerEmissions = async () => {
        const evts = wrapper.emitted('update') || [];
        if (evts.length === 0) return;
        const [, all] = evts[evts.length - 1];
        await wrapper.setProps({ selections: { composerChoices: all } });
    };

    const cliquer = async (index) => {
        const cartes = wrapper.findAll('button.kiosk-generic-choice');
        await cartes[index].trigger('click');
        await appliquerEmissions();
    };

    const saucesRetenues = () => {
        const evts = wrapper.emitted('update') || [];
        if (evts.length === 0) return 0;
        const [, all] = evts[evts.length - 1];
        return Object.keys(all?.sauce?.choices || {}).length;
    };

    return { wrapper, cliquer, saucesRetenues };
}

describe('borne — sauces multiples sur un bol (défaut owner 2026-09-02)', () => {
    it('TÉMOIN : avec max_select=1 la borne n’en retient qu’une (le défaut constaté)', async () => {
        const { wrapper, cliquer, saucesRetenues } = await monterEtapeSauce(1);
        expect(
            wrapper.findAll('button.kiosk-generic-choice').length,
            'les sauces du bol sont proposées',
        ).toBeGreaterThanOrEqual(2);

        await cliquer(0); // Fromagère maison
        await cliquer(1); // Barbecue

        expect(
            saucesRetenues(),
            'témoin du défaut : max_select=1 rend le choix exclusif — impossible de cumuler deux sauces',
        ).toBe(1);
    });

    it('CORRIGÉ : avec max_select=99 le client cumule « fromagère + barbecue »', async () => {
        const { cliquer, saucesRetenues } = await monterEtapeSauce(99);

        await cliquer(0); // Fromagère maison
        await cliquer(1); // Barbecue

        expect(
            saucesRetenues(),
            'RÉGRESSION : le bol doit accepter plusieurs sauces (1ère offerte, +0,50 € ensuite)',
        ).toBeGreaterThanOrEqual(2);
    });

    it('CORRIGÉ : une 3e sauce reste possible (plafond réellement levé, pas juste passé à 2)', async () => {
        const { cliquer, saucesRetenues } = await monterEtapeSauce(99);
        await cliquer(0);
        await cliquer(1);
        await cliquer(2);
        expect(saucesRetenues(), 'le plafond 99 doit autoriser au moins 3 sauces').toBeGreaterThanOrEqual(3);
    });
});
