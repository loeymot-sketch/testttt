/**
 * [visual-round-1 P2 fix 2026-07-07] KDS delta-sync must poll the SAME origin
 * the rest of the admin app uses (axios baseURL = ENV.API_URL + '/api'), not a
 * bare relative `/api/...` that resolves against window.location.origin. In a
 * split-host dev setup the relative form sent the authenticated Bearer poll to
 * the wrong port → 401 and a silently blind KDS.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { KdsSyncService } from '../../resources/js/services/KdsSyncService';
import ENV from '../../resources/js/config/env';

describe('KdsSyncService poll URL base', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        localStorage.setItem('vuex', JSON.stringify({ auth: { authToken: 'staff-token' } }));
    });

    afterEach(() => {
        localStorage.clear();
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('prefixes the sync request with ENV.API_URL (same origin as axios), not a bare relative path', async () => {
        const fetchFn = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ server_now: '2026-07-07T10:00:00Z', orders: [], deleted_ids: [] }),
        });

        const service = new KdsSyncService({ wsService: { state: 'DISCONNECTED', on: () => () => {} }, fetchFn });
        service.start(1);
        await service.forceSync();

        expect(fetchFn).toHaveBeenCalledTimes(1);
        const url = fetchFn.mock.calls[0][0];
        const expectedBase = `${(ENV.API_URL || '').replace(/\/$/, '')}/api/admin/kds-order/sync`;
        expect(url.startsWith(expectedBase)).toBe(true);
        expect(url).toContain('include_deleted=true');
        expect(url).toContain('branch_id=1');
    });

    it('resolves to the exact same origin axios uses for admin calls', async () => {
        // axios baseURL is `ENV.API_URL + '/api'` (see shared/axios-setup.js). The
        // KDS poll URL must share that origin so the Sanctum context is present.
        const fetchFn = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ server_now: '2026-07-07T10:00:00Z', orders: [], deleted_ids: [] }),
        });

        const service = new KdsSyncService({ wsService: { state: 'DISCONNECTED', on: () => () => {} }, fetchFn });
        service.start(1);
        await service.forceSync();

        const url = fetchFn.mock.calls[0][0];
        const axiosOrigin = `${(ENV.API_URL || '').replace(/\/$/, '')}/api`;
        expect(url.startsWith(axiosOrigin)).toBe(true);
    });
});
