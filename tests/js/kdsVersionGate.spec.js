/**
 * F-03 / NEW-02 / NEW-04
 * Authority: plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { KdsSyncService } from '../../resources/js/services/KdsSyncService';

describe('KdsSyncService version gate', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        // [GOAL-2026-05-29] Seed Sanctum token so forceSync() passes the auth-guard
        // (a46ec7df7) in the version-gate tests (test-1 also seeds inline; harmless overwrite).
        localStorage.setItem('vuex', JSON.stringify({ auth: { authToken: 'staff-token' } }));
    });

    afterEach(() => {
        localStorage.clear();
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('sends the persisted Sanctum bearer token when fallback sync uses fetch', async () => {
        localStorage.setItem('vuex', JSON.stringify({
            auth: { authToken: 'staff-token' },
            globalState: { lists: { language_code: 'fr' } },
        }));

        const fetchFn = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                server_now: '2026-04-23T10:00:00Z',
                orders: [],
                deleted_ids: [],
            }),
        });

        const service = new KdsSyncService({ wsService: { state: 'DISCONNECTED', on: () => () => {} }, fetchFn });
        service.start(1);

        await service.forceSync();

        expect(fetchFn).toHaveBeenCalledTimes(1);
        expect(fetchFn.mock.calls[0][1].headers.Authorization).toBe('Bearer staff-token');
        expect(fetchFn.mock.calls[0][1].headers['x-localization']).toBe('fr');
    });

    it('gates same version on second sync', async () => {
        const fetchFn = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                server_now: '2026-04-23T10:00:00Z',
                orders: [{ id: 1, version: 100 }],
                deleted_ids: [],
            }),
        });

        const service = new KdsSyncService({ wsService: { state: 'DISCONNECTED', on: () => () => {} }, fetchFn });
        const events = [];
        service.on('sync', (payload) => events.push(payload));
        service.start(1);

        await service.forceSync();
        await service.forceSync();

        expect(events[1].gatedIds).toContain(1);
    });

    it('gates older version on subsequent sync', async () => {
        const fetchFn = vi
            .fn()
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    server_now: '2026-04-23T10:00:00Z',
                    orders: [{ id: 1, version: 100 }],
                    deleted_ids: [],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    server_now: '2026-04-23T10:00:05Z',
                    orders: [{ id: 1, version: 99 }],
                    deleted_ids: [],
                }),
            });

        const service = new KdsSyncService({ wsService: { state: 'DISCONNECTED', on: () => () => {} }, fetchFn });
        const events = [];
        service.on('sync', (payload) => events.push(payload));
        service.start(1);

        await service.forceSync();
        await service.forceSync();

        expect(events[1].gatedIds).toContain(1);
    });
});
