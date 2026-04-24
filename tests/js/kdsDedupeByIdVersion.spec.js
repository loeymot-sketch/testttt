/**
 * F-03 / NEW-02 / NEW-04
 * Authority: plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { KdsSyncService } from '../../resources/js/services/KdsSyncService';

describe('KdsSyncService dedupe map', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('never exceeds 256 entries and evicts oldest ids', async () => {
        const payloads = Array.from({ length: 300 }, (_, i) => ({
            ok: true,
            json: async () => ({
                server_now: `2026-04-23T10:00:${String(i).padStart(2, '0')}Z`,
                orders: [{ id: i + 1, version: i + 1 }],
                deleted_ids: [],
            }),
        }));

        const fetchFn = vi.fn();
        payloads.forEach((payload) => fetchFn.mockResolvedValueOnce(payload));

        const service = new KdsSyncService({ wsService: { state: 'DISCONNECTED', on: () => () => {} }, fetchFn });
        service.start(1);

        for (let i = 0; i < 300; i += 1) {
            await service.forceSync();
        }

        expect(service._versionMap.size).toBeLessThanOrEqual(256);
        expect(service._versionMap.has(1)).toBe(false);
        expect(service._versionMap.has(300)).toBe(true);
    });
});
