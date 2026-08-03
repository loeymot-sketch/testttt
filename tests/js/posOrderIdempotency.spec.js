/**
 * Lot 2.A — POS checkout sends X-Idempotency-Key aligned with payload (F-05).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', () => ({
    default: {
        post: vi.fn(() => Promise.resolve({ data: {} })),
    },
}));
/** `posOrder` imports `appService` which loads the full Vuex store — avoid in Vitest. */
vi.mock('../../resources/js/services/appService', () => ({ default: {} }));

import axios from 'axios';
import { posOrder } from '../../resources/js/store/modules/posOrder.js';

describe('posOrder idempotency (2.A)', () => {
    const save = posOrder.actions.save;

    beforeEach(() => {
        vi.mocked(axios.post).mockClear();
        vi.mocked(axios.post).mockResolvedValue({ data: {} });
    });

    it('POST admin/pos with X-Idempotency-Key equal to payload idempotency_key', async () => {
        const idem = 'idem-pos-test-8f2a-4c1b';
        const ctx = { commit: vi.fn() };
        const payload = { branch_id: 1, idempotency_key: idem, form: { foo: 1 } };
        await save(ctx, payload);
        expect(axios.post).toHaveBeenCalledWith(
            'admin/pos',
            payload,
            expect.objectContaining({
                headers: { 'X-Idempotency-Key': idem },
            })
        );
    });

    it('sends a non-empty X-Idempotency-Key when idempotency_key is absent', async () => {
        const ctx = { commit: vi.fn() };
        await save(ctx, { branch_id: 1 });
        const thirdArg = vi.mocked(axios.post).mock.calls[0][2];
        const key = thirdArg?.headers?.['X-Idempotency-Key'];
        expect(key).toBeTruthy();
        expect(String(key).length).toBeGreaterThan(4);
    });
});
