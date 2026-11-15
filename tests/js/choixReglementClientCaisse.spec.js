import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('axios', () => ({
    default: { get: vi.fn().mockResolvedValue({ data: { data: [] } }), post: vi.fn() },
}));

import Modal from '../../resources/js/components/admin/pos/PosCounterCollectModal.vue';
import { choixReglementClient } from '../../resources/js/helpers/choixReglementClient';

/**
 * [RÈGLEMENT CLIENT 2026-11-15 · propriétaire] « Le client choisit carte bancaire à la livraison sur le site, et ma
 * caisse affiche toujours "à régler en espèces". » Le choix arrivait au serveur (door_payment_mode='card') mais la
 * fenêtre Encaisser repartait toujours sur Espèces et rien n'affichait le moyen choisi.
 */
const livraisonWeb = (over = {}) => ({
    id: 7, total: 23.5, queue_number: 'A07', order_type: 5, source: 5, source_surface: 'delivery',
    payment_method: 1, payment_status: 10, door_payment_mode: null, ...over,
});

describe('choixReglementClient — même vérité que le ticket imprimé', () => {
    it('carte bancaire à la livraison', () => {
        expect(choixReglementClient(livraisonWeb({ door_payment_mode: 'card' }))).toMatchObject({ mode: 'CARD', libelle: 'Carte bancaire à la livraison' });
    });
    it('espèces à la livraison', () => {
        expect(choixReglementClient(livraisonWeb())).toMatchObject({ mode: 'CASH', libelle: 'Espèces à la livraison' });
    });
    it('titre-restaurant', () => {
        expect(choixReglementClient(livraisonWeb({ payment_method: 5 }))).toMatchObject({ mode: 'TICKET', libelle: 'Titre-restaurant' });
    });
    it('livraison prise au téléphone à la caisse : aucun moyen inventé', () => {
        const c = choixReglementClient(livraisonWeb({ source: 15, source_surface: 'pos' }));
        expect(c.mode).toBeNull();
        expect(c.libelle).toBe('Au livreur (espèces ou carte)');
    });
    it('rien à annoncer : payée, carte en ligne, à emporter, Uber', () => {
        expect(choixReglementClient(livraisonWeb({ payment_status: 5, door_payment_mode: 'card' }))).toBeNull();
        expect(choixReglementClient(livraisonWeb({ payment_method: 4 }))).toBeNull();
        expect(choixReglementClient(livraisonWeb({ order_type: 10 }))).toBeNull();
        expect(choixReglementClient(livraisonWeb({ source_surface: 'uber_eats' }))).toBeNull();
        expect(choixReglementClient(null)).toBeNull();
    });
    it('champ door_payment_mode ABSENT (ressource incomplète) : jamais « espèces » supposé', () => {
        const o = livraisonWeb(); delete o.door_payment_mode;
        const c = choixReglementClient(o);
        expect(c.mode).toBeNull();
        expect(c.libelle).toBe('À régler à la livraison');
    });
    it('jamais d’abréviation « ESP » / « CB »', () => {
        for (const o of [livraisonWeb(), livraisonWeb({ door_payment_mode: 'card' }), livraisonWeb({ payment_method: 5 })]) {
            expect(choixReglementClient(o).libelle).not.toMatch(/\b(ESP|CB)\b/);
        }
    });
});

describe('PosCounterCollectModal — ouvre sur le moyen choisi par le client', () => {
    const monter = (order) => mount(Modal, {
        props: { order },
        global: { mocks: { $t: (k) => k }, stubs: { PosV5Numpad: true } },
    });

    it('carte à la livraison → Carte présélectionnée et annoncée', () => {
        const w = monter(livraisonWeb({ door_payment_mode: 'card' }));
        expect(w.vm.selectedMode).toBe('CARD');
        expect(w.find('[data-testid="pos-counter-collect-mode-CARD"]').attributes('aria-selected')).toBe('true');
        expect(w.find('[data-testid="cc-client-choice"]').text()).toContain('Carte bancaire à la livraison');
        expect(w.vm.cashReceivedRaw).toBe('23,50'); // montant carte prérempli = total
    });

    it('espèces à la livraison → Espèces, annoncé', () => {
        const w = monter(livraisonWeb());
        expect(w.vm.selectedMode).toBe('CASH');
        expect(w.find('[data-testid="cc-client-choice"]').text()).toContain('Espèces à la livraison');
    });

    it('titre-restaurant → Titres-resto présélectionné', () => {
        const w = monter(livraisonWeb({ payment_method: 5 }));
        expect(w.vm.selectedMode).toBe('TICKET');
    });

    it('commande à emporter → Espèces par défaut, sans bandeau (le client n’a pas choisi)', () => {
        const w = monter(livraisonWeb({ order_type: 10, source_surface: 'web' }));
        expect(w.vm.selectedMode).toBe('CASH');
        expect(w.find('[data-testid="cc-client-choice"]').exists()).toBe(false);
    });

    it('une 2ᵉ commande ouverte après une 1ʳᵉ reprend SON choix', async () => {
        const w = monter(livraisonWeb({ door_payment_mode: 'card' }));
        await w.setProps({ order: livraisonWeb({ id: 8 }) });
        expect(w.vm.selectedMode).toBe('CASH');
        await w.setProps({ order: livraisonWeb({ id: 9, door_payment_mode: 'card' }) });
        expect(w.vm.selectedMode).toBe('CARD');
    });
});
