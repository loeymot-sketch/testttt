import { describe, it, expect, vi, beforeEach } from 'vitest';

// [HARDWARE-HARDENING 2026-07-03] tryCaisseBridge doit distinguer 3 cas pour NE JAMAIS
// retomber sur window.print (page grise) quand le pont est présent mais échoue.
vi.mock('../../resources/js/helpers/posLocalPrinter', () => ({
  isCaisseBridgeAvailable: vi.fn(),
  printEscPosViaCaisseBridge: vi.fn(),
}));
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../resources/js/services/alertService', () => ({
  default: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}));

import axios from 'axios';
import alertService from '../../resources/js/services/alertService';
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

// [PRINT-INSTANT 2026-07-06] window.print() n'est plus JAMAIS automatique : le fallback
// navigateur est un bouton MANUEL explicite (manualBrowserPrint). Plus d'écran gris surprise.
describe('ReceiptComponent.manualBrowserPrint — fallback navigateur MANUEL uniquement', () => {
  const manual = Receipt.methods.manualBrowserPrint;
  const mkCtx = (refs = {}) => ({ $t: (k) => k, $nextTick: () => Promise.resolve(), $refs: refs });
  let printSpy;
  beforeEach(() => { vi.clearAllMocks(); printSpy = vi.fn(); global.window = global.window || {}; window.print = printSpy; });

  it("l'ancien fallback AUTOMATIQUE _browserPrintFallback n'existe plus", () => {
    expect(Receipt.methods._browserPrintFallback).toBeUndefined();
  });

  it('clic manuel client + bouton caché présent → click du v-print (aperçu navigateur)', async () => {
    const clickSpy = vi.fn();
    await manual.call(mkCtx({ hiddenPrintClientButton: { click: clickSpy } }), 'client');
    expect(clickSpy).toHaveBeenCalled();
    expect(printSpy).not.toHaveBeenCalled();
  });

  it('clic manuel kitchen → cible le bouton cuisine', async () => {
    const clickSpy = vi.fn();
    await manual.call(mkCtx({ hiddenPrintKitchenButton: { click: clickSpy } }), 'kitchen');
    expect(clickSpy).toHaveBeenCalled();
  });

  it('aucun bouton caché → window.print (mais uniquement suite au CLIC manuel)', async () => {
    await manual.call(mkCtx({}), 'client');
    expect(printSpy).toHaveBeenCalled();
  });
});
