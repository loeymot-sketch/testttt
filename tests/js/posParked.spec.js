import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
    },
}));

import axios from 'axios';
import { posParked } from '../../resources/js/store/modules/posParked';

describe('posParked store', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        Object.defineProperty(globalThis, 'crypto', {
            value: { randomUUID: vi.fn(() => 'uuid-parked-1') },
            configurable: true,
        });
    });

    it('park POSTs the payload with a generated idempotency token', async () => {
        axios.post.mockResolvedValue({
            data: {
                data: {
                    id: 11,
                    created_at: '2026-04-20T12:00:00Z',
                },
            },
        });

        const commit = vi.fn();
        const context = {
            rootGetters: {
                'posCart/lists': [{ item_id: 101, quantity: 1 }],
                'posCart/subtotal': 25.5,
                'posCart/discount': 2,
            },
            commit,
        };

        const response = await posParked.actions.park(context, {
            label: 'Client A',
            checkout_form: { customer_id: 7 },
            delivery_charge: 0,
        });

        expect(axios.post).toHaveBeenCalledWith('admin/pos/parked-orders', {
            payload: {
                lists: [{ item_id: 101, quantity: 1 }],
                subtotal: 25.5,
                discount: 2,
                total: 23.5,
                checkout_form: { customer_id: 7 },
                selected_address: null,
                delivery_inline: null,
                parked_at: expect.any(String),
            },
            label: 'Client A',
            idempotency_token: 'uuid-parked-1',
        });
        expect(commit).toHaveBeenCalledWith('UPSERT_PARKED', {
            id: 11,
            created_at: '2026-04-20T12:00:00Z',
        });
        expect(response.idempotencyToken).toBe('uuid-parked-1');
    });

    it('recall updates posCart through existing root actions', async () => {
        axios.get.mockResolvedValue({
            data: {
                data: {
                    id: 44,
                    payload_json: {
                        lists: [{ item_id: 202, quantity: 2 }],
                        discount: 1.5,
                        checkout_form: { customer_id: 9 },
                    },
                },
            },
        });

        const dispatch = vi.fn(() => Promise.resolve());
        const commit = vi.fn();

        const restored = await posParked.actions.recall({ dispatch, commit }, 44);

        expect(dispatch).toHaveBeenNthCalledWith(1, 'posCart/resetCart', null, { root: true });
        expect(dispatch).toHaveBeenNthCalledWith(2, 'posCart/lists', [{ item_id: 202, quantity: 2 }], { root: true });
        expect(dispatch).toHaveBeenNthCalledWith(3, 'posCart/discount', 1.5, { root: true });
        expect(commit).toHaveBeenCalledWith('REMOVE_PARKED', 44);
        expect(commit).toHaveBeenCalledWith('SET_LAST_RESTORED_PAYLOAD', restored);
        expect(restored.checkout_form.customer_id).toBe(9);
    });

    it('discard removes the parked entry from state', async () => {
        axios.delete.mockResolvedValue({ status: 204 });

        const commit = vi.fn();

        await posParked.actions.discard({ commit }, 18);

        expect(axios.delete).toHaveBeenCalledWith('admin/pos/parked-orders/18');
        expect(commit).toHaveBeenCalledWith('REMOVE_PARKED', 18);
    });

    /**
     * [V14 C-α / FINDING C-1 P1 sentinel]
     * Recall MUST prune any restored line whose item became 86'd while parked
     * (using the live item catalog as SSOT). Otherwise the operator silently
     * recalls a polluted cart and only discovers it at submit time (422).
     *
     * - Item 100 is in catalog but is_available=false → must be pruned
     * - Item 200 is in catalog and available → kept
     * - Item 300 is NOT in catalog (paginated/not loaded) → kept (defensive)
     */
    it('recall prunes lines whose item is now unavailable in the live catalog', async () => {
        axios.get.mockResolvedValue({
            data: {
                data: {
                    id: 77,
                    payload_json: {
                        lists: [
                            { item_id: 100, quantity: 1 },
                            { item_id: 200, quantity: 2 },
                            { item_id: 300, quantity: 1 },
                        ],
                        discount: 0,
                    },
                },
            },
        });

        const dispatch = vi.fn(() => Promise.resolve());
        const commit = vi.fn();
        const context = {
            dispatch,
            commit,
            rootGetters: {
                'item/lists': [
                    { id: 100, is_available: false, name: 'Sold-out burger' },
                    { id: 200, is_available: true, name: 'Available pizza' },
                ],
            },
        };

        const restored = await posParked.actions.recall(context, 77);

        // Sentinel: pruneUnavailable was called for item 100 only
        const pruneCalls = dispatch.mock.calls.filter(
            (call) => call[0] === 'posCart/pruneUnavailable'
        );
        expect(pruneCalls).toHaveLength(1);
        expect(pruneCalls[0][1]).toBe(100);

        // Sentinel: payload reports the purge (downstream UI can toast)
        expect(restored._recall_purged_item_ids).toEqual([100]);
    });

    /**
     * [V14 C-α / FINDING C-1 P1 sentinel]
     * Backward compat: when no item catalog is available (rootGetters absent
     * or item/lists empty), recall MUST NOT crash and MUST NOT purge anything.
     */
    it('recall does not crash when item catalog is empty (backward compat)', async () => {
        axios.get.mockResolvedValue({
            data: {
                data: {
                    id: 78,
                    payload_json: {
                        lists: [{ item_id: 999, quantity: 1 }],
                        discount: 0,
                    },
                },
            },
        });

        const dispatch = vi.fn(() => Promise.resolve());
        const commit = vi.fn();
        const context = {
            dispatch,
            commit,
            rootGetters: { 'item/lists': [] },
        };

        const restored = await posParked.actions.recall(context, 78);

        const pruneCalls = dispatch.mock.calls.filter(
            (call) => call[0] === 'posCart/pruneUnavailable'
        );
        expect(pruneCalls).toHaveLength(0);
        expect(restored.lists[0].item_id).toBe(999);
    });

    it('multi-park keeps three parked orders side by side', () => {
        const state = { list: [], lastRestoredPayload: null };

        posParked.mutations.UPSERT_PARKED(state, { id: 1, created_at: '2026-04-20T10:00:00Z' });
        posParked.mutations.UPSERT_PARKED(state, { id: 2, created_at: '2026-04-20T10:01:00Z' });
        posParked.mutations.UPSERT_PARKED(state, { id: 3, created_at: '2026-04-20T10:02:00Z' });

        expect(state.list.map((entry) => entry.id)).toEqual([3, 2, 1]);
        expect(posParked.getters.count(state)).toBe(3);
    });
});
