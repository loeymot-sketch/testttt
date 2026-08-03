import { describe, it, expect, vi } from 'vitest';

// [BORNE-LOCAL-BRIDGE 2026-06-28] Le mode « paiement à la caisse » (Plan B) finit sur
// /kiosk/cash-instruction (PAS /confirmation) — le e2e LIVE a révélé que l'auto-print
// n'y était pas câblé. Ce test fige le déclenchement + la garde module-level.
const printSpy = vi.hoisted(() => vi.fn(() => Promise.resolve({ method: 'local-bridge' })));
const markSpy = vi.hoisted(() => {
  const seen = new Set();
  return vi.fn((ref) => { const k = String(ref == null ? '' : ref).trim(); if (k === '' || seen.has(k)) return false; seen.add(k); return true; });
});

vi.mock('../../resources/js/helpers/kioskPrinter', () => ({
  printReceipt: printSpy,
  buildReceiptData: (x) => ({ ...x }),
  reportPrinterFailure: vi.fn(),
  isLocalBridgeAvailable: vi.fn(() => Promise.resolve(true)),
  markPrintedOnce: markSpy,
  // [TICKET-BORNE-SERVEUR 2026-07-06] pas d'orderId dans ces tests → jamais appelé.
  printServerTicketsViaBridge: vi.fn(() => Promise.resolve(false)),
}));
vi.mock('../../resources/js/services/kioskHardware', () => ({ default: { isKioskBridge: () => false } }));

import Comp from '../../resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue';

describe('KioskCashInstruction — auto-print ticket caisse', () => {
  const ctx = (orderNumber, over = {}) => ({
    orderNumber, orderTotal: 1.9, printFailed: false,
    $store: { state: { kioskCart: { items: [{ name: 'Coca', quantity: 1, convert_price: 1.9 }] }, frontendSetting: { company_name: 'Le Cayenne' } } },
    $t: (k) => k,
    // méthodes réelles du composant (le `this` simulé doit les porter)
    printGuardKey: Comp.methods.printGuardKey,
    buildTicketReceipt: Comp.methods.buildTicketReceipt,
    _printCounterTicket: Comp.methods._printCounterTicket,
    orderId: null, // pas d'orderId → chemin legacy (builder client)
    ...over,
  });

  it('imprime le ticket une seule fois par commande (garde module-level)', () => {
    printSpy.mockClear();
    const c = ctx('A-T1');
    Comp.methods.autoPrintCounterTicket.call(c);
    Comp.methods.autoPrintCounterTicket.call(c); // 2e appel → markPrintedOnce=false → skip
    expect(printSpy).toHaveBeenCalledTimes(1);
  });

  it('construit un reçu avec numéro + total même sans items (panier déjà vidé)', () => {
    printSpy.mockClear();
    const c = ctx('A-T2', { $store: { state: { kioskCart: { items: [] } } } });
    Comp.methods.autoPrintCounterTicket.call(c);
    expect(printSpy).toHaveBeenCalledTimes(1);
    const receipt = printSpy.mock.calls[0][0];
    expect(receipt.queueNumber).toBe('A-T2');
    expect(receipt.total).toBe(1.9);
    expect(receipt.paymentMethod).toBe('A regler en caisse');
  });

  it('expose la méthode auto-print', () => {
    expect(typeof Comp.methods.autoPrintCounterTicket).toBe('function');
  });
});
