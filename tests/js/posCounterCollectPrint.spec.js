import { describe, it, expect, vi, beforeEach } from 'vitest';

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
function ctx(order = { id: 42 }) {
  return { order, submitting: false, printingTicket: null, $t: (k) => k };
}

describe('PosCounterCollectModal.printTicket — impression via pont RAW', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    axios.get.mockResolvedValue({ data: { escpos_b64: 'QUJD' } });
    isCaisseBridgeAvailable.mockResolvedValue(true);
    printEscPosViaCaisseBridge.mockResolvedValue({ ok: true });
  });

  it('CLIENT : GET /escpos?ticket=client → POST au pont → succès (jamais window.print)', async () => {
    const c = ctx();
    await printTicket.call(c, 'client');
    expect(axios.get).toHaveBeenCalledWith('admin/pos/orders/42/escpos', { params: { ticket: 'client' } });
    expect(printEscPosViaCaisseBridge).toHaveBeenCalledWith('QUJD', { orderRef: 42 });
    expect(alertService.success).toHaveBeenCalled();
    expect(alertService.error).not.toHaveBeenCalled();
    expect(c.printingTicket).toBe(null); // réinitialisé
  });

  it('CUISINE : ticket=kitchen', async () => {
    await printTicket.call(ctx(), 'kitchen');
    expect(axios.get).toHaveBeenCalledWith('admin/pos/orders/42/escpos', { params: { ticket: 'kitchen' } });
    expect(printEscPosViaCaisseBridge).toHaveBeenCalled();
  });

  it('PONT ABSENT : message d\'erreur, aucun fetch, JAMAIS window.print', async () => {
    isCaisseBridgeAvailable.mockResolvedValue(false);
    await printTicket.call(ctx(), 'client');
    expect(axios.get).not.toHaveBeenCalled();
    expect(printEscPosViaCaisseBridge).not.toHaveBeenCalled();
    expect(alertService.error).toHaveBeenCalled();
  });

  it('ÉCHEC pont (retour null) : message d\'erreur', async () => {
    printEscPosViaCaisseBridge.mockResolvedValue(null);
    await printTicket.call(ctx(), 'client');
    expect(alertService.error).toHaveBeenCalled();
    expect(alertService.success).not.toHaveBeenCalled();
  });

  it('octets serveur absents (escpos_b64 null) : erreur, pas d\'appel pont', async () => {
    axios.get.mockResolvedValue({ data: { escpos_b64: null } });
    await printTicket.call(ctx(), 'client');
    expect(printEscPosViaCaisseBridge).not.toHaveBeenCalled();
    expect(alertService.error).toHaveBeenCalled();
  });

  it('pas de commande / déjà en impression → no-op', async () => {
    await printTicket.call(ctx(null), 'client');
    await printTicket.call({ ...ctx(), printingTicket: 'client' }, 'client');
    expect(isCaisseBridgeAvailable).not.toHaveBeenCalled();
  });
});
