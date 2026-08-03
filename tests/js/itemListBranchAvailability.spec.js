import { describe, it, expect, vi, beforeEach } from 'vitest';
import axios from 'axios';
import { item } from '../../resources/js/store/modules/item';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
    },
}));

vi.mock('../../resources/js/services/appService', () => ({
    default: {
        requestHandler(requests) {
            const params = new URLSearchParams();
            Object.keys(requests).forEach((key) => {
                if (requests[key] !== '' && requests[key] !== null && typeof requests[key] !== 'undefined') {
                    params.append(key, requests[key]);
                }
            });

            return params.toString() ? `?${params.toString()}` : '';
        },
    },
}));

describe('item/lists action - branch_id propagation', () => {
    beforeEach(() => {
        vi.mocked(axios.get).mockReset();
        vi.mocked(axios.get).mockResolvedValue({ data: { data: [], meta: {} } });
    });

    it('includes branch_id query param when user has a branch context', async () => {
        const commit = vi.fn();
        const context = {
            commit,
            rootState: { auth: { authBranchId: 7 } },
            rootGetters: {},
        };

        await item.actions.lists(context, {});

        expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('branch_id=7'));
    });

    it('omits branch_id when user has no branch context', async () => {
        const commit = vi.fn();
        const context = {
            commit,
            rootState: { auth: { authInfo: {} } },
            rootGetters: {},
        };

        await item.actions.lists(context, {});

        expect(axios.get).toHaveBeenCalledWith('admin/item');
    });

    it('preserves explicit branch_id from caller query', async () => {
        const commit = vi.fn();
        const context = {
            commit,
            rootState: { auth: { authBranchId: 7 } },
            rootGetters: {},
        };

        await item.actions.lists(context, { branch_id: 99 });

        expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('branch_id=99'));
        expect(axios.get).not.toHaveBeenCalledWith(expect.stringContaining('branch_id=7'));
    });
});
