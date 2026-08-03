import { describe, it, expect, vi } from 'vitest';
import 'fake-indexeddb/auto';

// kioskCart pulls axios + analytics at import; stub them (offline queue uses fake-indexeddb).
vi.mock('axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }));
vi.mock('../../resources/js/helpers/kioskAnalytics', () => ({
  KIOSK_ERROR_CODES: { NETWORK: 'network', MENU_UNAVAILABLE: 'menu', PRODUCT_REMOVED: 'removed', PAYMENT_REFUSED: 'refused' },
  normalizeKioskErrorCode: (c) => c,
}));

import { kioskCart } from '../../resources/js/store/modules/kioskCart.js';

// MAX_ITEM_QTY defaults to 20 (window.foodkingConfig?.maxItemQty ?? 20).
const MAX = 20;
const UNIT = 5.0; // 3.00 base + 1.50 variations + 0.50 extras
const line = (quantity, total) => ({
  item_id: 1, item_variations: [], item_extras: [],
  convert_price: 3.0, item_variation_total: 1.5, item_extra_total: 0.5,
  quantity, total,
});

describe('F1 · kioskCart clamp + line-total integrity', () => {
  it('ADD_ITEM clamps qty to MAX and recomputes total from the CLAMPED qty (drops stale total)', () => {
    const state = { items: [], orderQuote: { x: 1 } };
    kioskCart.mutations.ADD_ITEM(state, line(25, UNIT * 25)); // wizard shipped 25 + stale total
    expect(state.items[0].quantity).toBe(MAX);
    expect(state.items[0].total).toBeCloseTo(UNIT * MAX, 2); // NOT UNIT*25
  });

  it('REPLACE_ITEM_AT applies the same clamp+recompute on the edit path', () => {
    const state = { items: [line(1, UNIT)], orderQuote: null };
    kioskCart.mutations.REPLACE_ITEM_AT(state, { index: 0, item: line(99, UNIT * 99) });
    expect(state.items[0].quantity).toBe(MAX);
    expect(state.items[0].total).toBeCloseTo(UNIT * MAX, 2);
  });

  it('leaves a within-bounds qty + total untouched (no regression)', () => {
    const state = { items: [], orderQuote: null };
    kioskCart.mutations.ADD_ITEM(state, line(3, UNIT * 3));
    expect(state.items[0].quantity).toBe(3);
    expect(state.items[0].total).toBeCloseTo(UNIT * 3, 2);
  });

  it('subtotal getter reconciles with the clamped line total', () => {
    const state = { items: [], orderQuote: null, loyaltyDiscount: 0, promoDiscount: 0 };
    kioskCart.mutations.ADD_ITEM(state, line(25, UNIT * 25));
    expect(kioskCart.getters.subtotal(state)).toBeCloseTo(state.items[0].total, 2);
  });
});
