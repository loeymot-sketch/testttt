import { describe, expect, it } from 'vitest';
import { calculateKioskRunningTotal } from '../../resources/js/helpers/kioskPricing';

describe('calculateKioskRunningTotal', () => {
  it('sauces_with_heterogeneous_prices_charged_individually', () => {
    const item = {
      convert_price: 10,
      itemAttributes: [{ id: 7, name: 'Sos', role: 'sauce' }],
      variations: {
        7: [
          { id: 101, name: 'Blanche', price: 0.5 },
          { id: 102, name: 'Samourai', price: 1.25 },
          { id: 103, name: 'Harissa', price: 2 },
        ],
      },
      extras: [],
    };

    const total = calculateKioskRunningTotal(item, {
      sauceOrder: [101, 102, 103],
      quantity: 1,
    });

    expect(total).toBe(13.25);
  });
});
