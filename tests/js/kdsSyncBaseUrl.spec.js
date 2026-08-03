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

/**
 * [visual-round-2 N3 fix 2026-07-07] The sync poll must carry the SAME auth
 * headers the shared axios instance carries — a Bearer token AND x-api-key —
 * otherwise /api/admin/kds-order/sync returns 401 and the KDS goes silently
 * blind. The R1 baseURL fix moved the request to the right origin but the poll
 * kept a kiosk-token-first precedence that diverged from axios
 * (`selectSurfaceBearerToken`): a stale `kiosk:order` token shadowed the staff
 * token and produced the 401 seen in the split-host dev env.
 */
describe('KdsSyncService poll auth headers', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        localStorage.clear();
    });

    afterEach(() => {
        localStorage.clear();
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    const okFetch = () => vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ server_now: '2026-07-07T10:00:00Z', orders: [], deleted_ids: [] }),
    });

    it('sends Authorization Bearer + x-api-key on the sync request (parity with axios)', async () => {
        localStorage.setItem('vuex', JSON.stringify({ auth: { authToken: 'staff-token' } }));
        const fetchFn = okFetch();

        const service = new KdsSyncService({ wsService: { state: 'DISCONNECTED', on: () => () => {} }, fetchFn });
        service.start(0);
        await service.forceSync();

        expect(fetchFn).toHaveBeenCalledTimes(1);
        const headers = fetchFn.mock.calls[0][1].headers;
        expect(headers.Authorization).toBe('Bearer staff-token');
        // x-api-key must mirror the same source axios reads (ENV.API_KEY).
        expect(headers).toHaveProperty('x-api-key');
        expect(headers['x-api-key']).toBe(ENV.API_KEY);
    });

    it('prefers the staff token over a stale kiosk token on the admin/KDS surface (N3 regression)', async () => {
        // A prior kiosk session left a kiosk:order token behind. On the KDS
        // (non-/kiosk) surface the poll must ship the STAFF token — the kiosk
        // token would 401 on /api/admin/kds-order/sync.
        localStorage.setItem('vuex', JSON.stringify({
            auth: { authToken: 'staff-token' },
            kioskCart: { kioskToken: 'kiosk-order-token' },
        }));
        const fetchFn = okFetch();

        const service = new KdsSyncService({ wsService: { state: 'DISCONNECTED', on: () => () => {} }, fetchFn });
        service.start(0);
        await service.forceSync();

        expect(fetchFn).toHaveBeenCalledTimes(1);
        const headers = fetchFn.mock.calls[0][1].headers;
        expect(headers.Authorization).toBe('Bearer staff-token');
        expect(headers.Authorization).not.toBe('Bearer kiosk-order-token');
    });

    it('skips the request (no 401) when no token is hydrated yet', async () => {
        // Empty localStorage → no Authorization → guard skips the poll so the
        // very first tick before Vuex-persistedstate rehydration cannot 401.
        const fetchFn = okFetch();

        const service = new KdsSyncService({ wsService: { state: 'DISCONNECTED', on: () => () => {} }, fetchFn });
        service.start(0);
        const result = await service.forceSync();

        expect(result).toBeNull();
        expect(fetchFn).not.toHaveBeenCalled();
    });
});
