import { afterEach, describe, expect, it, vi } from 'vitest';
import { buildEscPosReceipt, printReceipt } from '../../resources/js/helpers/kioskPrinter';

describe('kioskPrinter', () => {
  afterEach(() => {
    document.body.innerHTML = '';
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
});
