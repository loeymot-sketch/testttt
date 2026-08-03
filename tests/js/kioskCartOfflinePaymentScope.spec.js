import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
    },
}));

vi.mock('../../resources/js/helpers/kioskMenuCache', () => ({
    isSnapshotStale: vi.fn(() => false),
    loadSnapshot: vi.fn(() => Promise.resolve(null)),
}));

vi.mock('../../resources/js/helpers/kioskOfflineQueue', () => ({
    getPendingCount: vi.fn(() => 0),
    markStaleItems: vi.fn(),
    saveOrder: vi.fn(() => 'offline_test_123'),
    startAutoSync: vi.fn(),
}));

import axios from 'axios';
import { saveOrder, startAutoSync } from '../../resources/js/helpers/kioskOfflineQueue';
import { kioskCart } from '../../resources/js/store/modules/kioskCart';

function context() {
    return {
        commit: vi.fn(),
        state: {
            items: [],
            idempotencyKey: 'idemp-fixed',
            branchId: 12,
            orderType: 25,
            loyaltyCustomer: null,
            promoCode: null,
        },
    };
}

describe('kioskCart offline payment scope', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('refuses card/TR offline network failures without queuing a local payment', async () => {
        const err = new Error('network');
        axios.post.mockRejectedValueOnce(err);

        await expect(
            kioskCart.actions.submitOrder(context(), { paymentMethod: 'card', orderType: 25 }),
        ).rejects.toMatchObject({ code: 'KIOSK_OFFLINE_ELECTRONIC_PAYMENT_REFUSED' });

        expect(saveOrder).not.toHaveBeenCalled();
        expect(startAutoSync).not.toHaveBeenCalled();
    });

    it('keeps existing cash offline queue behavior with an offline_ local id', async () => {
        const ctx = context();
        axios.post.mockRejectedValueOnce(new Error('network'));

        const res = await kioskCart.actions.submitOrder(ctx, { paymentMethod: 'cash', orderType: 25 });

        expect(saveOrder).toHaveBeenCalledTimes(1);
        expect(startAutoSync).toHaveBeenCalledTimes(1);
        expect(res.data.data.id).toBe('offline_test_123');
        expect(res.data.data._offline).toBe(true);
    });
});
