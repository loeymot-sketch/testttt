/**
 * F-03 / NEW-02 / NEW-04
 * Authority: plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { KdsSyncService, KDS_SYNC_STATE } from '../../resources/js/services/KdsSyncService';

describe('KdsSyncService backoff', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.spyOn(Math, 'random').mockReturnValue(0);
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('doubles interval on 503 and resets after 200', async () => {
        const fetchFn = vi
            .fn()
            .mockResolvedValueOnce({ ok: false, status: 503 })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    server_now: '2026-04-23T10:00:00Z',
                    orders: [],
                    deleted_ids: [],
                }),
            });

        const service = new KdsSyncService({ wsService: { state: 'DISCONNECTED', on: () => () => {} }, fetchFn });
        service.start(1);
        const base = service.currentIntervalMs;

        await service.forceSync();
        expect(service.state).toBe(KDS_SYNC_STATE.BACKOFF);
        expect(service.currentIntervalMs).toBe(Math.min(base * 2, 30000));

        await service.forceSync();
        expect([KDS_SYNC_STATE.ACTIVE, KDS_SYNC_STATE.IDLE]).toContain(service.state);
        expect(service.currentIntervalMs).toBeGreaterThanOrEqual(10000);
        expect(service.currentIntervalMs).toBeLessThanOrEqual(13000);
    });

    it('caps backoff at 30000 after repeated 5xx', async () => {
        const fetchFn = vi.fn().mockResolvedValue({ ok: false, status: 503 });
        const service = new KdsSyncService({ wsService: { state: 'DISCONNECTED', on: () => () => {} }, fetchFn });
        service.start(1);

        for (let i = 0; i < 5; i += 1) {
            await service.forceSync();
        }

        expect(service.currentIntervalMs).toBeLessThanOrEqual(30000);
        expect(service.currentIntervalMs).toBe(30000);
    });

    // [F-03 / Lot 1.C / Audit G1 fix] Self-heal after a network-level error.
    // Without this guarantee, a DNS / TLS / ERR_NETWORK_CHANGED would halt the
    // poll loop until a WS state-change externally triggered a reschedule —
    // the KDS would go permanently blind during a concurrent outage.
    it('self-heals after a network-level error by rescheduling the loop', async () => {
        const fetchFn = vi
            .fn()
            .mockRejectedValueOnce(Object.assign(new Error('Network down'), { name: 'TypeError' }))
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    server_now: '2026-04-23T10:00:00Z',
                    orders: [{ id: 1, version: 1 }],
                    deleted_ids: [],
                }),
            });

        const service = new KdsSyncService({
            wsService: { state: 'DISCONNECTED', on: () => () => {} },
            fetchFn,
        });
        service.start(1);

        // [ultra-goal A7 heal 2026-05-13] KdsSyncService is designed to SWALLOW
        // network-level errors (DNS / TLS / ERR_NETWORK_CHANGED) and reschedule
        // the poll loop — without this, a transient outage would halt the KDS
        // permanently until an external trigger (F-03 / Lot 1.C / Audit G1).
        // forceSync() therefore returns null on network error (NOT rejects).
        // Self-heal guarantee = (1) returns null, (2) _timer rescheduled.
        const firstResult = await service.forceSync();
        expect(firstResult).toBeNull();
        expect(service._timer).not.toBeNull();

        await service.forceSync();
        expect(service.lastSyncAt).toBe('2026-04-23T10:00:00Z');
    });
});
