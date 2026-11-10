// [GOAL LIVRAISON-TICKETS 2026-11-10 · T-1.2.1] Le reçu HTML de la caisse dit la même chose que le ticket thermique
// pour une commande du site : nom, téléphone, adresse de livraison, et le règlement CHOISI à la porte — jamais
// « à régler en caisse » pour une livraison. Le serveur (OrderDetailsResource) a déjà replié nom/téléphone sur le
// compte du client ; le composant n'invente rien et n'affiche rien pour une commande de caisse sans saisie.
import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import ReceiptComponent from '../../resources/js/components/admin/pos/ReceiptComponent.vue';

const FR = {
  'label.customer': 'Client', 'label.phone': 'Téléphone', 'label.deliver_to': 'Livrer à',
  'label.pay_at_delivery': 'À régler à la livraison', 'label.card_to_courier': 'Carte au livreur',
  'label.cash': 'Espèces', 'label.ticket_restaurant': 'Titre-restaurant',
};

function monter(order) {
  return shallowMount({ ...ReceiptComponent, render: () => null }, {
    props: { order },
    global: {
      directives: { print: {} },
      mocks: {
        $t: (k) => FR[k] || k,
        $store: { getters: { 'company/lists': {}, 'backendGlobalState/branchShow': {}, 'posOrder/orderItems': [], 'frontendLanguage/show': {} }, dispatch: vi.fn(() => Promise.resolve({ data: { data: {} } })), commit: vi.fn() },
      },
    },
  });
}

const livraisonWeb = (over = {}) => ({
  id: 9001, order_type: 5 /* orderTypeEnum.DELIVERY */, payment_method: 1, payment_status: 10, door_payment_mode: null,
  pos_customer_name: 'Karim Test', pos_customer_phone: '0612345678',
  order_address: { address: '10 Rue Test, Hénin-Beaumont', apartment: '2B', city: 'Hénin-Beaumont', postal_code: '62110' },
  ...over,
});

describe('reçu caisse — commande du site', () => {
  it('livraison espèces : nom, téléphone, adresse (sans répéter la commune) et « Espèces » à la livraison', () => {
    const w = monter(livraisonWeb());
    expect(w.vm.clientLines).toEqual(['Client : Karim Test', 'Téléphone : 0612345678', 'Livrer à : 10 Rue Test, Hénin-Beaumont', '2B']);
    expect(w.vm.doorPaymentLabel).toBe('Espèces');
  });

  it('carte au livreur et titre-restaurant sont nommés ; payée en ligne → rien à régler', () => {
    expect(monter(livraisonWeb({ door_payment_mode: 'card' })).vm.doorPaymentLabel).toBe('Carte au livreur');
    expect(monter(livraisonWeb({ payment_method: 5 })).vm.doorPaymentLabel).toBe('Titre-restaurant');
    expect(monter(livraisonWeb({ payment_method: 4, payment_status: 5 })).vm.doorPaymentLabel).toBeNull();
    expect(monter(livraisonWeb({ payment_method: 4, payment_status: 10 })).vm.doorPaymentLabel).toBeNull();
  });

  it('commune déjà dans la voie, écrite sans accents ni casse en base → pas de doublon', () => {
    const w = monter(livraisonWeb({ order_address: { address: '20 Place de la République 62110 Hénin-Beaumont', apartment: '', city: 'HENIN-BEAUMONT', postal_code: '62110' } }));
    expect(w.vm.clientLines.filter((l) => /62110/.test(l))).toEqual(['Livrer à : 20 Place de la République 62110 Hénin-Beaumont']);
  });

  it('commune absente de la voie → ligne « CP ville » ajoutée', () => {
    const w = monter(livraisonWeb({ order_address: { address: '10 Rue Test', apartment: '', city: 'Hénin-Beaumont', postal_code: '62110' } }));
    expect(w.vm.clientLines).toContain('62110 Hénin-Beaumont');
  });

  it('retrait du site : téléphone oui, adresse non, rien « à la livraison »', () => {
    const w = monter(livraisonWeb({ order_type: 10 /* orderTypeEnum.TAKEAWAY */ }));
    expect(w.vm.clientLines).toEqual(['Client : Karim Test', 'Téléphone : 0612345678']);
    expect(w.vm.doorPaymentLabel).toBeNull();
  });

  it('commande de caisse sans saisie : aucun bloc client', () => {
    const w = monter({ id: 1, order_type: 15 /* orderTypeEnum.POS */, payment_method: 1, payment_status: 5, pos_customer_name: null, pos_customer_phone: null, order_address: null });
    expect(w.vm.clientLines).toEqual([]);
    expect(w.vm.doorPaymentLabel).toBeNull();
  });
});
