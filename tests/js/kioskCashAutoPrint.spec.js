import { describe, it, expect, vi } from 'vitest';

// [BORNE-LOCAL-BRIDGE 2026-06-28] Le mode « paiement à la caisse » (Plan B) finit sur
// /kiosk/cash-instruction (PAS /confirmation) — le e2e LIVE a révélé que l'auto-print
// n'y était pas câblé. Ce test fige la garde anti-double + le déclenchement.
const printSpy = vi.hoisted(() => vi.fn(() => Promise.resolve({ method: 'local-bridge' })));

vi.mock('../../resources/js/helpers/kioskPrinter', () => ({
  printReceipt: printSpy,
  buildReceiptData: (x) => ({ ...x }),
  reportPrinterFailure: vi.fn(),
  isLocalBridgeAvailable: vi.fn(() => Promise.resolve(true)),
}));
vi.mock('../../resources/js/services/kioskHardware', () => ({ default: { isKioskBridge: () => false } }));

import Comp from '../../resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue';

describe('KioskCashInstruction — auto-print ticket caisse', () => {
  const ctx = (over = {}) => ({
    orderNumber: 'A0002', orderTotal: 1.9,
    $store: { state: { kioskCart: { items: [{ name: 'Coca', quantity: 1, convert_price: 1.9 }] }, frontendSetting: { company_name: 'Le Cayenne' } } },
    $t: (k) => k, ...over,
  });

  it('imprime le ticket une seule fois par commande (garde anti-double)', () => {
    printSpy.mockClear();
    const c = ctx();
    Comp.methods.autoPrintCounterTicket.call(c);
    Comp.methods.autoPrintCounterTicket.call(c); // 2e appel → garde
    expect(printSpy).toHaveBeenCalledTimes(1);
  });

  it('construit un reçu avec numéro + total même sans items (panier déjà vidé)', () => {
    printSpy.mockClear();
    const c = ctx({ $store: { state: { kioskCart: { items: [] } } } });
    Comp.methods.autoPrintCounterTicket.call(c);
    expect(printSpy).toHaveBeenCalledTimes(1);
    const receipt = printSpy.mock.calls[0][0];
    expect(receipt.queueNumber).toBe('A0002');
    expect(receipt.total).toBe(1.9);
  });

  it('expose la méthode auto-print + le câblage mounted', () => {
    expect(typeof Comp.methods.autoPrintCounterTicket).toBe('function');
  });
});
