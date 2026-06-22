import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
    },
}));

import axios from 'axios';
import { posFloorplan } from '../../resources/js/store/modules/posFloorplan';

describe('posFloorplan store', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('fetchState GETs the floorplan and commits SET_TABLES', async () => {
        axios.get.mockResolvedValue({
            data: {
                data: [{ id: 1, occupancy_status: 'free' }],
            },
        });

        const commit = vi.fn();
        const response = await posFloorplan.actions.fetchState({ commit });

        expect(axios.get).toHaveBeenCalledWith('admin/pos/floorplan/state');
        expect(commit).toHaveBeenCalledWith('SET_TABLES', [{ id: 1, occupancy_status: 'free' }]);
        expect(response.data.data).toHaveLength(1);
    });

    it('assign POSTs the order id then reloads state', async () => {
        axios.post.mockResolvedValue({ data: { data: { id: 9 } } });

        const dispatch = vi.fn(() => Promise.resolve());
        await posFloorplan.actions.assign({ dispatch }, { tableId: 9, orderId: 77 });

        expect(axios.post).toHaveBeenCalledWith('admin/pos/floorplan/9/assign', {
            order_id: 77,
        });
        expect(dispatch).toHaveBeenCalledWith('fetchState');
    });

    it('transfer POSTs source and target ids then reloads state', async () => {
        axios.post.mockResolvedValue({ data: { data: { id: 12 } } });

        const dispatch = vi.fn(() => Promise.resolve());
        await posFloorplan.actions.transfer({ dispatch }, { sourceId: 4, targetId: 12 });

        expect(axios.post).toHaveBeenCalledWith('admin/pos/floorplan/transfer', {
            source_table_id: 4,
            target_table_id: 12,
        });
        expect(dispatch).toHaveBeenCalledWith('fetchState');
    });

    it('getter byStatus filters tables by occupancy_status', () => {
        const state = {
            tables: [
                { id: 1, occupancy_status: 'free' },
                { id: 2, occupancy_status: 'occupied' },
                { id: 3, occupancy_status: 'occupied' },
            ],
        };

        expect(posFloorplan.getters.byStatus(state)('occupied').map((table) => table.id)).toEqual([2, 3]);
    });
});
