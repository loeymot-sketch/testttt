// [BORNE-02] kioskCart.submitOrder builds its session idempotency key with crypto.getRandomValues,
// which is only guaranteed in a SECURE context. On a misconfigured plain-http webview crypto is
// undefined and the key generation would throw synchronously before the order POST. This spec proves
// the Math.random fallback: no throw, and a valid UUID-shaped X-Idempotency-Key is still sent.
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', () => ({ default: { post: vi.fn() } }));
vi.mock('../../resources/js/helpers/kioskMenuCache', () => ({
    isSnapshotStale: vi.fn(() => false),
    loadSnapshot: vi.fn(() => Promise.resolve(null)),
}));
vi.mock('../../resources/js/helpers/kioskOfflineQueue', () => ({
    getPendingCount: vi.fn(() => 0), markStaleItems: vi.fn(), saveOrder: vi.fn(() => 'offline_x'), startAutoSync: vi.fn(),
}));

import axios from 'axios';
import { kioskCart } from '../../resources/js/store/modules/kioskCart';

function context() {
    return {
        commit: vi.fn(),
        state: { items: [], idempotencyKey: null, branchId: 12, orderType: 25, loyaltyCustomer: null, promoCode: null },
    };
}
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

describe('kioskCart idempotency key — crypto-absent fallback (BORNE-02)', () => {
    beforeEach(() => vi.clearAllMocks());

    it('does NOT throw and still sends a valid UUID X-Idempotency-Key when crypto is undefined', async () => {
        const ctx = context();
        axios.post.mockResolvedValueOnce({ data: { data: { id: 1, queue_number: 'A1' } } });
        const orig = globalThis.crypto;
        Object.defineProperty(globalThis, 'crypto', { value: undefined, configurable: true }); // non-secure-context webview
        try {
            await expect(
                kioskCart.actions.submitOrder(ctx, { paymentMethod: 'cash', orderType: 25 }),
            ).resolves.toBeTruthy(); // (a) no synchronous throw before the POST
            const header = axios.post.mock.calls[0][2].headers['X-Idempotency-Key']; // (b) valid key still sent
            expect(header).toMatch(UUID_V4);
        } finally {
            Object.defineProperty(globalThis, 'crypto', { value: orig, configurable: true });
        }
    });

    it('still produces a valid key on the normal (crypto-present) happy path — behavior preserved', async () => {
        const ctx = context();
        axios.post.mockResolvedValueOnce({ data: { data: { id: 2, queue_number: 'A2' } } });
        await kioskCart.actions.submitOrder(ctx, { paymentMethod: 'cash', orderType: 25 });
        expect(axios.post.mock.calls[0][2].headers['X-Idempotency-Key']).toMatch(UUID_V4);
    });
});
