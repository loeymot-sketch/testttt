import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { readFileSync } from 'fs';
import { resolve } from 'path';

// [E2E stores · revue adverse round 4 · 2026-10-02] Côté caisse, une commande web dit la même chose
// partout : numéro APPELÉ, retrait et non livraison, heure programmée sur la carte d'encaissement.
//  · B4-R4-01 : fiche et facture ne montraient que la série « #0210267571 », jamais « N°A0032 ».
//  · B4-R4-02 : facture « Paiement à la livraison » / « Heure de livraison » pour un retrait.
//  · B2-R2-11 : carte d'encaissement sans l'heure d'une commande programmée.
//  · B2-R2-06 : fenêtre « Encaisser la commande borne » pour une commande web.
// Le rendu complet est prouvé par le rejeu de la vague B (captures) ; ici, la logique et le câblage.

vi.mock('vue3-print-nb', () => ({ default: { directive: {} } }));
vi.mock('../../resources/js/helpers/phoneDisplay', () => ({ safePhone: (v) => v }));

import OnlineOrderReceiptComponent from '../../resources/js/components/admin/onlineOrders/OnlineOrderReceiptComponent.vue';
import orderTypeEnum from '../../resources/js/enums/modules/orderTypeEnum';
import paymentTypeEnum from '../../resources/js/enums/modules/paymentTypeEnum';

const lire = (p) => readFileSync(resolve(process.cwd(), p), 'utf8');

describe('Facture d\'une commande web', () => {
  const recu = () => shallowMount({ ...OnlineOrderReceiptComponent, render: () => null }, {
    props: { order: {}, orderItems: [], orderUser: {}, orderAddress: {}, orderBranch: {} },
    global: { directives: { print: {} }, mocks: { $t: (k) => k, $store: { getters: {}, dispatch: vi.fn(() => Promise.resolve({ data: { data: {} } })), commit: vi.fn() } } },
  });

  it('dit « Paiement au comptoir » pour un retrait réglé sur place, garde le libellé d\'une livraison', () => {
    const vm = recu().vm;
    expect(vm.libellePaiement({ order_type: orderTypeEnum.TAKEAWAY, payment_method: paymentTypeEnum.CASH_ON_DELIVERY })).toBe('label.pay_at_counter');
    expect(vm.libellePaiement({ order_type: orderTypeEnum.DELIVERY, payment_method: paymentTypeEnum.CASH_ON_DELIVERY })).toBe('label.cash_on_delivery');
  });

  it('affiche le numéro appelé et l\'heure de retrait dans le gabarit', () => {
    const src = lire('resources/js/components/admin/onlineOrders/OnlineOrderReceiptComponent.vue');
    expect(src).toMatch(/v-if="order\.queue_number">N°\{\{ order\.queue_number \}\}/);
    expect(src).toMatch(/order\.order_type === enums\.orderTypeEnum\.DELIVERY \? \$t\('label\.delivery_time'\) : \$t\('label\.pickup_time'\)/);
  });
});

describe('Fiche, encaissement et fenêtre d\'encaissement', () => {
  it('la fiche d\'une commande web met le numéro appelé en tête', () => {
    const src = lire('resources/js/components/admin/onlineOrders/OnlineOrderShowComponent.vue');
    expect(src).toMatch(/v-if="order\.queue_number">N°\{\{ order\.queue_number \}\}/);
  });

  it('la carte d\'encaissement affiche l\'heure d\'une commande programmée', () => {
    const src = lire('resources/js/components/admin/encaissement/EncaissementComponent.vue');
    expect(src).toMatch(/v-if="order\.scheduled_at && order\.delivery_time"/);
    expect(src).toMatch(/⏰ \{\{ order\.delivery_time \}\}/);
  });

  it('la fenêtre d\'encaissement ne dit plus « borne » pour toutes les commandes', () => {
    const fr = JSON.parse(lire('resources/js/languages/fr.json'));
    const trouve = JSON.stringify(fr).match(/"encaisser_mode_title":"([^"]*)"/);
    expect(trouve && trouve[1]).toBe('Encaisser la commande');
  });
});
