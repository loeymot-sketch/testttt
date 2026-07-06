import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * [PRINT-INSTANT 2026-07-06] FIX A — impression caisse FIRE-AND-FORGET :
 *  - le clic « Imprimer » toast IMMÉDIATEMENT « Ticket envoyé » et rend la main
 *    (le handler ne bloque jamais l'UI le temps du POST fiscal + pont) ;
 *  - le résultat réel est toasté en async (✓ / erreur claire) ;
 *  - client + cuisine tournent EN PARALLÈLE (verrous séparés, plus de série 10-25 s) ;
 *  - window.print() n'est plus JAMAIS déclenché automatiquement (bouton manuel only).
 */
vi.mock('../../../resources/js/helpers/posLocalPrinter', () => ({
  isCaisseBridgeAvailable: vi.fn(),
  printEscPosViaCaisseBridge: vi.fn(),
}));
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../../resources/js/services/alertService', () => ({
  default: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}));

import axios from 'axios';
import alertService from '../../../resources/js/services/alertService';
import { isCaisseBridgeAvailable, printEscPosViaCaisseBridge } from '../../../resources/js/helpers/posLocalPrinter';
import Receipt from '../../../resources/js/components/admin/pos/ReceiptComponent.vue';

function ctx(over = {}) {
  return {
    order: { id: 7 },
    printingClient: false,
    printingKitchen: false,
    showBrowserPrintFallback: false,
    localPrintCount: 0,
    $t: (k) => k,
    $refs: {},
    $nextTick: () => Promise.resolve(),
    tryCaisseBridge: Receipt.methods.tryCaisseBridge,
    _printClientPipeline: Receipt.methods._printClientPipeline,
    _printKitchenPipeline: Receipt.methods._printKitchenPipeline,
    ...over,
  };
}

describe('ReceiptComponent — impression fire-and-forget (jamais de gel UI)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    axios.post.mockResolvedValue({ data: { receipt_print_count: 1, audit_emitted: true, printed_escpos: false } });
    axios.get.mockResolvedValue({ data: { escpos_b64: 'QUJD' } });
    isCaisseBridgeAvailable.mockResolvedValue(true);
    printEscPosViaCaisseBridge.mockResolvedValue({ ok: true });
  });

  it('CLIENT : toast « Ticket envoyé » IMMÉDIAT, le handler rend la main sans await', () => {
    const c = ctx();
    const ret = Receipt.methods.handlePrintClientClick.call(c);
    // Rend la main tout de suite (fire-and-forget) — pas de promesse à awaiter côté UI.
    expect(ret).toBeUndefined();
    // Toast posé de façon SYNCHRONE, avant toute résolution réseau.
    expect(alertService.success).toHaveBeenCalledWith('pos.ticket_sent');
    expect(c.printingClient).toBe(true);
    return c._lastClientPrintPromise.then(() => {
      expect(c.printingClient).toBe(false);
      expect(alertService.success).toHaveBeenCalledWith(expect.stringContaining('✓'));
    });
  });

  it('le handler N\'attend PAS le POST fiscal : toast immédiat même si le réseau pend', () => {
    let resolvePost;
    axios.post.mockImplementation(() => new Promise((r) => { resolvePost = r; }));
    const c = ctx();
    Receipt.methods.handlePrintClientClick.call(c);
    // Le POST est toujours pendu — le toast est déjà là et l'UI n'est pas bloquée.
    expect(alertService.success).toHaveBeenCalledWith('pos.ticket_sent');
    resolvePost({ data: { receipt_print_count: 1, audit_emitted: true, printed_escpos: true } });
    return c._lastClientPrintPromise;
  });

  it('CLIENT + CUISINE en PARALLÈLE : verrous séparés, les deux pipelines partent ensemble', async () => {
    let resolveClientPost;
    axios.post.mockImplementation((url) => {
      if (String(url).includes('print-receipt')) {
        return new Promise((r) => { resolveClientPost = r; }); // client PEND
      }
      return Promise.resolve({ data: { printed_escpos: true } }); // cuisine répond
    });
    const c = ctx();
    Receipt.methods.handlePrintClientClick.call(c);
    Receipt.methods.handlePrintKitchenClick.call(c); // n'est PLUS bloqué par le client
    expect(c.printingClient).toBe(true);
    expect(c.printingKitchen).toBe(true);
    await c._lastKitchenPrintPromise; // la cuisine finit PENDANT que le client pend
    expect(c.printingKitchen).toBe(false);
    expect(c.printingClient).toBe(true);
    resolveClientPost({ data: { receipt_print_count: 1, audit_emitted: true, printed_escpos: true } });
    await c._lastClientPrintPromise;
    expect(c.printingClient).toBe(false);
  });

  it('double-clic même bouton → dédupliqué (1 seul POST fiscal)', async () => {
    const c = ctx();
    Receipt.methods.handlePrintClientClick.call(c);
    Receipt.methods.handlePrintClientClick.call(c);
    await c._lastClientPrintPromise;
    const fiscalPosts = axios.post.mock.calls.filter((call) => String(call[0]).includes('print-receipt'));
    expect(fiscalPosts).toHaveLength(1);
  });

  it('pont ABSENT → erreur claire + bouton navigateur MANUEL, window.print JAMAIS auto', async () => {
    isCaisseBridgeAvailable.mockResolvedValue(false);
    const printSpy = vi.fn();
    global.window = global.window || {};
    window.print = printSpy;
    const c = ctx();
    Receipt.methods.handlePrintClientClick.call(c);
    await c._lastClientPrintPromise;
    expect(alertService.error).toHaveBeenCalledWith('pos.print_bridge_offline');
    expect(c.showBrowserPrintFallback).toBe(true);
    expect(printSpy).not.toHaveBeenCalled();
  });

  it('pont PRÉSENT mais échec impression → erreur claire, pas de page grise', async () => {
    printEscPosViaCaisseBridge.mockResolvedValue(null);
    const c = ctx();
    Receipt.methods.handlePrintKitchenClick.call(c);
    await c._lastKitchenPrintPromise;
    expect(alertService.error).toHaveBeenCalledWith('pos.reprint_error');
    expect(c.showBrowserPrintFallback).toBe(true);
  });
});
