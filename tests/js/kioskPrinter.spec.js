import { afterEach, describe, expect, it, vi } from 'vitest';

const hwMock = vi.hoisted(() => ({
  isKioskBridge: vi.fn(() => false),
  printReceipt: vi.fn(),
  printEscPos: vi.fn(),
}));

vi.mock('../../resources/js/config/kioskHardware.js', () => ({
  KIOSK_HARDWARE: {
    PRINTER_RETRY_MAX: 3,
    PRINTER_RETRY_MS: 10,
  },
}));

vi.mock('../../resources/js/services/kioskHardware.js', () => ({
  default: {
    isKioskBridge: () => hwMock.isKioskBridge(),
    printReceipt: (...a) => hwMock.printReceipt(...a),
    printEscPos: (...a) => hwMock.printEscPos(...a),
  },
}));

import { buildEscPosReceipt, printReceipt } from '../../resources/js/helpers/kioskPrinter';

describe('kioskPrinter', () => {
  afterEach(() => {
    document.body.innerHTML = '';
    hwMock.isKioskBridge.mockReset();
    hwMock.printReceipt.mockReset();
    hwMock.printEscPos.mockReset();
    hwMock.isKioskBridge.mockImplementation(() => false);
    vi.restoreAllMocks();
  });

  it('builds an ESC/POS receipt with queue number and totals', () => {
    const lines = buildEscPosReceipt({
      restaurantName: 'Le Cayenne',
      queueNumber: 'A042',
      orderDate: '31/03/2026 10:00',
      items: [{ name: 'Burger', quantity: 2, unitPrice: 5 }],
      subtotal: 10,
      discount: 0,
      total: 10,
      paymentMethod: 'Cash',
    }).join('');

    expect(lines).toContain('Le Cayenne');
    expect(lines).toContain('A042');
    expect(lines).toContain('TOTAL');
  });

  it('falls back to browser printing when a receipt element exists', async () => {
    document.body.innerHTML = '<div id="kiosk-print-receipt"></div>';
    window.print = vi.fn();

    const result = await printReceipt({
      restaurantName: 'Le Cayenne',
      queueNumber: 'A001',
      items: [],
      total: 0,
    });

    expect(window.print).toHaveBeenCalled();
    expect(result).toEqual({ method: 'browser' });
  });

  it('retries bridge print N times then falls through to none when browser unavailable', async () => {
    hwMock.isKioskBridge.mockReturnValue(true);
    hwMock.printReceipt.mockResolvedValue({ ok: false });
    hwMock.printEscPos.mockResolvedValue({ ok: false });
    document.body.innerHTML = '';

    const result = await printReceipt({
      restaurantName: 'X',
      queueNumber: 'N1',
      items: [],
      total: 0,
    });

    expect(hwMock.printReceipt).toHaveBeenCalledTimes(3);
    expect(hwMock.printEscPos).toHaveBeenCalledTimes(3);
    expect(result.method).toBe('none');
  });

  it('[BORNE-PRINT] ne masque PAS un échec bridge en succès navigateur même si l\'élément reçu existe', async () => {
    // Cas réel borne Electron : #kiosk-print-receipt est TOUJOURS rendu. Un échec
    // imprimante réel (papier épuisé) ne doit pas tomber sur window.print() et être
    // rapporté { method:'browser' } (faux « Imprimé » → le filet on-screen ne s'affiche
    // jamais, reportPrinterFailure jamais appelé).
    hwMock.isKioskBridge.mockReturnValue(true);
    hwMock.printReceipt.mockResolvedValue({ ok: false, error: 'paper_out' });
    hwMock.printEscPos.mockResolvedValue({ ok: false, error: 'paper_out' });
    document.body.innerHTML = '<div id="kiosk-print-receipt"></div>';
    window.print = vi.fn();

    const result = await printReceipt({ restaurantName: 'X', queueNumber: 'N1', items: [], total: 0 });

    expect(window.print).not.toHaveBeenCalled();
    expect(result.method).toBe('none');
  });

  it('[BORNE-PRINT] un retour bridge {ok:true, success:false} ne compte PAS comme imprimé (tente l\'ESC/POS)', async () => {
    // IPC transporté OK mais job d'impression échoué → ne pas court-circuiter en
    // { method:'electron' } via `|| r.ok` ; doit tenter le fallback ESC/POS.
    hwMock.isKioskBridge.mockReturnValue(true);
    hwMock.printReceipt.mockResolvedValue({ ok: true, success: false });
    hwMock.printEscPos.mockResolvedValue({ ok: true });
    document.body.innerHTML = '';

    const result = await printReceipt({ restaurantName: 'X', queueNumber: 'N1', items: [], total: 0 });

    expect(hwMock.printEscPos).toHaveBeenCalled();
    expect(result.method).toBe('electron-escpos');
  });

  it('respects PRINTER_RETRY_DELAY_MS between bridge attempts', async () => {
    vi.useFakeTimers();
    hwMock.isKioskBridge.mockReturnValue(true);
    hwMock.printReceipt.mockResolvedValue({ ok: false });
    hwMock.printEscPos.mockResolvedValue({ ok: false });
    document.body.innerHTML = '';

    const p = printReceipt({
      restaurantName: 'X',
      queueNumber: 'N1',
      items: [],
      total: 0,
    });

    await vi.advanceTimersByTimeAsync(0);
    await Promise.resolve();
    expect(hwMock.printReceipt).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(10);
    await Promise.resolve();
    expect(hwMock.printReceipt).toHaveBeenCalledTimes(2);

    await vi.advanceTimersByTimeAsync(10);
    await Promise.resolve();
    expect(hwMock.printReceipt).toHaveBeenCalledTimes(3);

    await p;
    vi.useRealTimers();
  });

  it('[BORNE-LOCAL-BRIDGE] hors Electron, imprime via le pont local AVANT window.print', async () => {
    hwMock.isKioskBridge.mockReturnValue(false);
    // pont local dispo : /health → UP, POST / → PRINTED
    global.fetch = vi.fn().mockResolvedValue({ ok: true, text: () => Promise.resolve('PRINTED') });
    document.body.innerHTML = '<div id="kiosk-print-receipt"></div>';
    window.print = vi.fn();

    const result = await printReceipt({ restaurantName: 'Le Cayenne', queueNumber: 'A0001', items: [], total: 1.9 });

    expect(result).toEqual({ method: 'local-bridge' });
    expect(window.print).not.toHaveBeenCalled(); // le pont a court-circuité le fallback
    expect(global.fetch).toHaveBeenCalledWith('http://127.0.0.1:9100/', expect.objectContaining({ method: 'POST' }));
    vi.restoreAllMocks();
  });

  it('[BORNE-LOCAL-BRIDGE] si le pont échoue, retombe sur window.print', async () => {
    hwMock.isKioskBridge.mockReturnValue(false);
    global.fetch = vi.fn().mockRejectedValue(new Error('ECONNREFUSED')); // pont absent
    document.body.innerHTML = '<div id="kiosk-print-receipt"></div>';
    window.print = vi.fn();

    const result = await printReceipt({ restaurantName: 'Le Cayenne', queueNumber: 'A0001', items: [], total: 1.9 });

    expect(result).toEqual({ method: 'browser' });
    expect(window.print).toHaveBeenCalledTimes(1);
    vi.restoreAllMocks();
  });
});
