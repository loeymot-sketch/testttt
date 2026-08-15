import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

/**
 * [T-4.4 BILLETS-ABSENTS 2026-08-15 · GOAL_CONFORT_MAX] `PosV5Numpad.vue` n'avait
 * QUE le pavé 0-9 — aucun raccourci billet (5/10/20/50€), forçant le caissier à
 * taper chiffre par chiffre un montant reçu qu'il pourrait composer d'un coup en
 * empilant les coupures reçues (usage réel comptoir). Ajout opt-in via la prop
 * `denominations` (défaut [] = rien ne change pour PaymentComponent.vue, FROZEN
 * §7, qui ne passe pas cette prop) + branchement dans PosCounterCollectModal.vue
 * (non gelé — file "à encaisser").
 */
import PosV5Numpad from '../../resources/js/components/admin/pos/v5/PosV5Numpad.vue';
import PosCounterCollectModal from '../../resources/js/components/admin/pos/PosCounterCollectModal.vue';

describe('PosV5Numpad — coupures rapides opt-in', () => {
    it('sans la prop denominations : aucune ligne de billets rendue (PaymentComponent.vue inchangé)', () => {
        const wrapper = mount(PosV5Numpad);
        expect(wrapper.find('.pos-v5-numpad__bills').exists()).toBe(false);
        // Le pavé numérique lui-même reste intact (16 touches).
        expect(wrapper.findAll('.pos-v5-numpad__key')).toHaveLength(16);
    });

    it('avec denominations=[5,10,20,50] : 4 boutons rendus, un clic émet "denomination"', async () => {
        const wrapper = mount(PosV5Numpad, { props: { denominations: [5, 10, 20, 50] } });
        const bills = wrapper.findAll('.pos-v5-numpad__bill');
        expect(bills).toHaveLength(4);
        expect(bills.map((b) => b.text().replace(/\s/g, ''))).toEqual(['5€', '10€', '20€', '50€']);

        await bills[2].trigger('click');
        expect(wrapper.emitted('denomination')).toEqual([[20]]);
    });
});

describe('PosCounterCollectModal.onDenomination — empile les billets comme un vrai comptoir', () => {
    const onDenomination = PosCounterCollectModal.methods.onDenomination;

    function ctx(overrides = {}) {
        return {
            submitting: false,
            cashFieldPristine: true,
            cashReceivedRaw: '8,50',
            cashReceivedNumber: 8.5,
            ...overrides,
        };
    }

    it('1er appui après pré-remplissage (pristine) : démarre FRAIS au montant du billet, ne s\'ajoute pas au total pré-rempli', () => {
        const c = ctx({ cashFieldPristine: true, cashReceivedRaw: '8,50', cashReceivedNumber: 8.5 });
        onDenomination.call(c, 20);
        expect(c.cashReceivedRaw).toBe('20,00');
        expect(c.cashFieldPristine).toBe(false);
    });

    it('appuis suivants (non pristine) : les billets S\'ADDITIONNENT (empiler 20 + 20 + 10 = 50)', () => {
        const c = ctx({ cashFieldPristine: false, cashReceivedRaw: '20,00', cashReceivedNumber: 20 });
        onDenomination.call(c, 20);
        expect(c.cashReceivedRaw).toBe('40,00');

        const c2 = ctx({ cashFieldPristine: false, cashReceivedRaw: '40,00', cashReceivedNumber: 40 });
        onDenomination.call(c2, 10);
        expect(c2.cashReceivedRaw).toBe('50,00');
    });

    it('pendant submitting=true : aucun effet (garde anti double-appui, cohérent avec numpadInput/numpadBack)', () => {
        const c = ctx({ submitting: true, cashReceivedRaw: '8,50' });
        onDenomination.call(c, 20);
        expect(c.cashReceivedRaw).toBe('8,50');
    });
});
