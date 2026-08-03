import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * [TICKET-BORNE-SERVEUR 2026-07-06] FIX B — l'écran cash-instruction (flux Plan B
 * réel de la borne) doit imprimer via le RENDERER SERVEUR (helper partagé
 * printServerTicketsViaBridge, ticket CLIENT « A REGLER EN CAISSE » design caisse)
 * quand l'orderId est présent, et ne garder le builder client legacy (ASCII-fold)
 * qu'en FALLBACK (pas d'orderId, échec serveur/pont).
 */
const legacyPrintSpy = vi.hoisted(() => vi.fn(() => Promise.resolve({ method: 'local-bridge' })));
const serverPrintSpy = vi.hoisted(() => vi.fn(() => Promise.resolve(true)));

vi.mock('../../../resources/js/helpers/kioskPrinter', () => ({
  printReceipt: legacyPrintSpy,
  buildReceiptData: (x) => ({ ...x }),
  reportPrinterFailure: vi.fn(),
  isLocalBridgeAvailable: vi.fn(() => Promise.resolve(true)),
  markPrintedOnce: vi.fn(() => true),
  printServerTicketsViaBridge: serverPrintSpy,
}));
vi.mock('../../../resources/js/services/kioskHardware', () => ({ default: { isKioskBridge: () => false } }));

import Comp from '../../../resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue';

const ctx = (over = {}) => ({
  orderNumber: 'A0007',
  orderTotal: 12.4,
  orderId: '5312',
  printFailed: false,
  $store: { state: { kioskCart: { items: [] }, frontendSetting: { company_name: 'Le Cayenne' } } },
  $t: (k) => k,
  printGuardKey: Comp.methods.printGuardKey,
  buildTicketReceipt: Comp.methods.buildTicketReceipt,
  _printCounterTicket: Comp.methods._printCounterTicket,
  ...over,
});

describe('KioskCashInstruction — ticket borne via renderer SERVEUR (design caisse)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    serverPrintSpy.mockResolvedValue(true);
    legacyPrintSpy.mockResolvedValue({ method: 'local-bridge' });
  });

  it('la prop orderId est déclarée (câblée depuis la query par kioskRoutes)', () => {
    expect(Comp.props.orderId).toBeTruthy();
  });

  it('orderId présent → helper SERVEUR ticket CLIENT seul, legacy PAS appelé', async () => {
    const c = ctx();
    const printed = await Comp.methods._printCounterTicket.call(c);
    expect(printed).toBe(true);
    expect(serverPrintSpy).toHaveBeenCalledWith('5312', { tickets: ['client'] });
    expect(legacyPrintSpy).not.toHaveBeenCalled();
  });

  it('échec serveur (false) → FALLBACK builder legacy (zéro régression)', async () => {
    serverPrintSpy.mockResolvedValue(false);
    const c = ctx();
    const printed = await Comp.methods._printCounterTicket.call(c);
    expect(printed).toBe(true);
    expect(serverPrintSpy).toHaveBeenCalled();
    expect(legacyPrintSpy).toHaveBeenCalledWith(
      expect.objectContaining({ queueNumber: 'A0007', paymentMethod: 'A regler en caisse' }),
      'kiosk-print-receipt',
      { allowBrowserPrint: false }, // jamais window.print auto sur la borne
    );
  });

  it('pas d\'orderId (deep-link / offline) → legacy directement, serveur PAS appelé', async () => {
    const c = ctx({ orderId: null });
    const printed = await Comp.methods._printCounterTicket.call(c);
    expect(printed).toBe(true);
    expect(serverPrintSpy).not.toHaveBeenCalled();
    expect(legacyPrintSpy).toHaveBeenCalled();
  });

  it('reprintTicket (filet écran) passe par le MÊME pipeline serveur-primaire', async () => {
    const c = ctx();
    await Comp.methods.reprintTicket.call(c);
    expect(serverPrintSpy).toHaveBeenCalledWith('5312', { tickets: ['client'] });
    expect(c.printFailed).toBe(false);
  });

  it('reprintTicket : tout échoue (serveur false + legacy none) → printFailed=true', async () => {
    serverPrintSpy.mockResolvedValue(false);
    legacyPrintSpy.mockResolvedValue({ method: 'none' });
    const c = ctx();
    await Comp.methods.reprintTicket.call(c);
    expect(c.printFailed).toBe(true);
  });
});
