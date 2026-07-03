import { describe, it, expect, vi, beforeEach } from 'vitest';

// [HARDWARE-HARDENING 2026-07-03] tryCaisseBridge doit distinguer 3 cas pour NE JAMAIS
// retomber sur window.print (page grise) quand le pont est présent mais échoue.
vi.mock('../../resources/js/helpers/posLocalPrinter', () => ({
  isCaisseBridgeAvailable: vi.fn(),
  printEscPosViaCaisseBridge: vi.fn(),
}));
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

import axios from 'axios';
import { isCaisseBridgeAvailable, printEscPosViaCaisseBridge } from '../../resources/js/helpers/posLocalPrinter';
import Receipt from '../../resources/js/components/admin/pos/ReceiptComponent.vue';

const tryCaisseBridge = Receipt.methods.tryCaisseBridge;
const ctx = (order = { id: 7 }) => ({ order });

describe('ReceiptComponent.tryCaisseBridge — statut (pas de page grise si pont présent)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    axios.get.mockResolvedValue({ data: { escpos_b64: 'QUJD' } });
    isCaisseBridgeAvailable.mockResolvedValue(true);
    printEscPosViaCaisseBridge.mockResolvedValue({ ok: true });
  });

  it("'printed' quand le pont imprime", async () => {
    expect(await tryCaisseBridge.call(ctx(), 'client')).toBe('printed');
    expect(printEscPosViaCaisseBridge).toHaveBeenCalledWith('QUJD', { orderRef: 7 });
  });

  it("'no-bridge' quand aucun pont (health KO) → window.print reste autorisé (dev)", async () => {
    isCaisseBridgeAvailable.mockResolvedValue(false);
    expect(await tryCaisseBridge.call(ctx(), 'client')).toBe('no-bridge');
    expect(axios.get).not.toHaveBeenCalled();
  });

  it("'failed' quand le pont est LÀ mais l'impression échoue (mauvaise imprimante) → PAS window.print", async () => {
    printEscPosViaCaisseBridge.mockResolvedValue(null);
    expect(await tryCaisseBridge.call(ctx(), 'client')).toBe('failed');
  });

  it("'failed' quand octets serveur absents", async () => {
    axios.get.mockResolvedValue({ data: { escpos_b64: null } });
    expect(await tryCaisseBridge.call(ctx(), 'client')).toBe('failed');
  });

  it("'failed' quand une étape jette après health OK (pont présent)", async () => {
    axios.get.mockRejectedValue(new Error('server 500'));
    expect(await tryCaisseBridge.call(ctx(), 'client')).toBe('failed');
  });

  it("'no-bridge' sans commande (rien à imprimer)", async () => {
    expect(await tryCaisseBridge.call(ctx(null), 'client')).toBe('no-bridge');
  });
});
