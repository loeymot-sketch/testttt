/**
 * [SEC-FALSIFY-2026-06-08 P1] Kiosk promo false-zero regression.
 *
 * The backend KioskPromoService::validate() returns the amount as `discount_amount`
 * (wrapped by PromoController as { status, data: {...}, message }). The store action
 * previously read `data.discount` — an undefined key — so every valid promo committed
 * SET_PROMO with discount 0: the UI showed "Code appliqué" while the total was never
 * reduced and the customer paid full price. This proves the action now reads the real
 * field and the discount is non-zero.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', () => ({ default: { post: vi.fn() } }));

import axios from 'axios';
import { kioskCart } from '../../resources/js/store/modules/kioskCart';

function findSetPromo(commit) {
    return commit.mock.calls.find(([m]) => m === 'SET_PROMO');
}

describe('kioskCart.validatePromo — backend discount_amount field', () => {
    beforeEach(() => {
        axios.post.mockReset();
    });

    it('applies the discount from the real backend `discount_amount` (not a false-zero)', async () => {
        // Exact shape of PromoController::validate wrapping KioskPromoService::validate.
        axios.post.mockResolvedValue({
            data: {
                status: true,
                message: null,
                data: {
                    valid: true,
                    code: 'PROMO5',
                    source: 'kiosk_promo',
                    type: 'amount',
                    value: 5,
                    discount_amount: 5,
                },
            },
        });

        const commit = vi.fn();
        const res = await kioskCart.actions.validatePromo({ commit, getters: { subtotal: 20 } }, 'PROMO5');

        expect(res.valid).toBe(true);
        const setPromo = findSetPromo(commit);
        expect(setPromo, 'SET_PROMO must be committed on a valid promo').toBeTruthy();
        expect(setPromo[1].discount).toBe(5); // the bug made this 0
        expect(setPromo[1].meta.kind).toBe('kiosk_promo'); // backend exposes origin as `source`
    });

    it('a payload carrying ONLY discount_amount must not collapse to 0', async () => {
        axios.post.mockResolvedValue({
            data: { status: true, message: null, data: { valid: true, discount_amount: 2.5 } },
        });

        const commit = vi.fn();
        const res = await kioskCart.actions.validatePromo({ commit, getters: { subtotal: 10 } }, 'X');

        expect(res.valid).toBe(true);
        expect(findSetPromo(commit)[1].discount).toBe(2.5);
    });
});
