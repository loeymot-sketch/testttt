import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

// [PRINT-AENCAISSER 2026-07-03] Teste la méthode printTicket() du modal à-encaisser :
// imprime client/cuisine via le PONT RAW (jamais window.print), messages clairs.
vi.mock('../../resources/js/helpers/posLocalPrinter', () => ({
  isCaisseBridgeAvailable: vi.fn(),
  printEscPosViaCaisseBridge: vi.fn(),
}));
vi.mock('axios', () => ({ default: { get: vi.fn() } }));
vi.mock('../../resources/js/services/alertService', () => ({
  default: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}));

import axios from 'axios';
import alertService from '../../resources/js/services/alertService';
import { isCaisseBridgeAvailable, printEscPosViaCaisseBridge } from '../../resources/js/helpers/posLocalPrinter';
import Modal from '../../resources/js/components/admin/pos/PosCounterCollectModal.vue';

const printTicket = Modal.methods.printTicket;
// [PRINT-INSTANT 2026-07-06] printTicket est désormais FIRE-AND-FORGET : le clic toast
// immédiatement « Ticket envoyé » et rend la main ; le pipeline async toaste le résultat.
// Les tests awaitent le hook `_lastPrintTicketPromise` pour observer la fin réelle.
async function callAndSettle(c, type) {
  printTicket.call(c, type);
  await (c._lastPrintTicketPromise || Promise.resolve());
}
function ctx(order = { id: 42 }) {
  return {
    order, submitting: false, printingTicket: null, $t: (k) => k,
    _printTicketPipeline: Modal.methods._printTicketPipeline,
  };
}

describe('PosCounterCollectModal.printTicket — impression via pont RAW', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    axios.get.mockResolvedValue({ data: { escpos_b64: 'QUJD' } });
    isCaisseBridgeAvailable.mockResolvedValue(true);
    printEscPosViaCaisseBridge.mockResolvedValue({ ok: true });
  });

  it('CLIENT : toast IMMÉDIAT « ticket envoyé » puis GET /escpos → POST au pont → succès (jamais window.print)', async () => {
    const c = ctx();
    printTicket.call(c, 'client');
    // Toast immédiat, AVANT toute résolution async (fire-and-forget).
    expect(alertService.success).toHaveBeenCalledWith('pos.ticket_sent');
    await c._lastPrintTicketPromise;
    expect(axios.get).toHaveBeenCalledWith('admin/pos/orders/42/escpos', { params: { ticket: 'client' } });
    expect(printEscPosViaCaisseBridge).toHaveBeenCalledWith('QUJD', { orderRef: 42 });
    // Résultat réel toasté en async (label ✓).
    expect(alertService.success).toHaveBeenCalledWith(expect.stringContaining('✓'));
    expect(alertService.error).not.toHaveBeenCalled();
    expect(c.printingTicket).toBe(null); // réinitialisé
  });

  it('CUISINE : ticket=kitchen', async () => {
    await callAndSettle(ctx(), 'kitchen');
    expect(axios.get).toHaveBeenCalledWith('admin/pos/orders/42/escpos', { params: { ticket: 'kitchen' } });
    expect(printEscPosViaCaisseBridge).toHaveBeenCalled();
  });

  it('PONT ABSENT : message d\'erreur, aucun fetch, JAMAIS window.print', async () => {
    isCaisseBridgeAvailable.mockResolvedValue(false);
    await callAndSettle(ctx(), 'client');
    expect(axios.get).not.toHaveBeenCalled();
    expect(printEscPosViaCaisseBridge).not.toHaveBeenCalled();
    expect(alertService.error).toHaveBeenCalled();
  });

  it('ÉCHEC pont (retour null) : message d\'erreur async, pas de faux « ✓ »', async () => {
    printEscPosViaCaisseBridge.mockResolvedValue(null);
    await callAndSettle(ctx(), 'client');
    expect(alertService.error).toHaveBeenCalled();
    expect(alertService.success).not.toHaveBeenCalledWith(expect.stringContaining('✓'));
  });

  it('octets serveur absents (escpos_b64 null) : erreur, pas d\'appel pont', async () => {
    axios.get.mockResolvedValue({ data: { escpos_b64: null } });
    await callAndSettle(ctx(), 'client');
    expect(printEscPosViaCaisseBridge).not.toHaveBeenCalled();
    expect(alertService.error).toHaveBeenCalled();
  });

  it('pas de commande / déjà en impression → no-op', async () => {
    await callAndSettle(ctx(null), 'client');
    await callAndSettle({ ...ctx(), printingTicket: 'client' }, 'client');
    expect(isCaisseBridgeAvailable).not.toHaveBeenCalled();
  });
});

// [RÉGRESSION e2e 2026-07-03] L'audit browser a montré que les boutons ne RENDAIENT PAS :
// le `v-if` pointait un computed inexistant (`hasOrder` au lieu de `visible`) → rangée jamais
// montée. Les tests de méthode ne l'attrapaient pas → il FAUT un test de rendu (montage réel).
describe('PosCounterCollectModal — RENDU des boutons impression (montage réel)', () => {
  // Résout les VRAIES clés i18n (namespace pos.) → un mauvais namespace (label.*) laisserait
  // fuiter la clé brute, que la regex ci-dessous rejette (finding P1 i18n leak de l'audit).
  const T = { 'pos.print_ticket_client': 'Ticket client', 'pos.print_ticket_kitchen': 'Ticket cuisine' };
  const mountModal = (order) => mount(Modal, {
    props: { order },
    global: { mocks: { $t: (k) => T[k] || k }, stubs: { PosV5Numpad: true } },
  });
  const RAW_KEY = /^[a-z]+\.[a-z_.]+$/; // clé i18n non résolue

  it('affiche « Ticket client » ET « Ticket cuisine » (libellés RÉSOLUS, pas de clé brute)', () => {
    const w = mountModal({ id: 7, total: 6.9, queue_number: 'A0017' });
    const pc = w.find('[data-testid="pos-counter-collect-print-client"]');
    const pk = w.find('[data-testid="pos-counter-collect-print-kitchen"]');
    expect(pc.exists()).toBe(true);
    expect(pk.exists()).toBe(true);
    expect(w.find('.cc-print-row').exists()).toBe(true);
    // i18n résolu (pas "label.print_ticket_client" brut)
    expect(pc.text()).toContain('Ticket client');
    expect(pk.text()).toContain('Ticket cuisine');
    expect(pc.text().split(/\s+/).some((t) => RAW_KEY.test(t))).toBe(false);
    expect(pk.text().split(/\s+/).some((t) => RAW_KEY.test(t))).toBe(false);
  });

  it('ne les affiche PAS sans commande (order=null)', () => {
    const w = mountModal(null);
    expect(w.find('[data-testid="pos-counter-collect-print-client"]').exists()).toBe(false);
  });
});
