/**
 * [KDS-02 FIX] Fallback poller must NOT permanently halt on a non-5xx HTTP
 * error (401 / 403 / 404 / 429). Before the fix, _handleHttpError() only
 * rescheduled inside the is5xx branch; a transient 401 (token mid-rotation)
 * or a 429 (rate-limit) killed the poll loop forever → the kitchen silently
 * lost its degradation safety-net.
 *
 * Source defect: reports/test-e2e/all-systems-2026-06-06/WAVE1_POS_AUDIT_FINDINGS.md
 * (KDS-02 P2). Authority for the file: plans/MEGA_PLAN_SYNC_HARDENING_v3.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { KdsSyncService, KDS_SYNC_STATE } from '../../resources/js/services/KdsSyncService';

describe('KdsSyncService — KDS-02 reschedule on non-5xx HTTP errors', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.spyOn(Math, 'random').mockReturnValue(0);
        // forceSync() short-circuits before fetch unless a token is hydrated
        // (auth-guard added in a46ec7df7). Seed it so the error path runs.
        localStorage.setItem('vuex', JSON.stringify({ auth: { authToken: 'staff-token' } }));
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
        localStorage.clear();
    });

    function serviceFor(status) {
        const fetchFn = vi.fn().mockResolvedValue({ ok: false, status });
        // Realistic transport: getState() + lowercase, NO public .state.
        const ws = { getState: () => 'disconnected', isConnected: () => false, on: () => () => {} };
        const service = new KdsSyncService({ wsService: ws, fetchFn });
        return { service, fetchFn };
    }

    // DISCRIMINATING: clear the stale start() timer first, so a non-null _timer
    // after the error can ONLY come from the fix's reschedule (not start()).
    it('arms a fresh timer after a 401 (does not halt forever)', async () => {
        const { service } = serviceFor(401);
        service.start(1);
        service._clearTimers();
        expect(service._timer, 'sanity: cleared').toBeNull();

        await service.forceSync();

        // The safety-net: the error path itself must re-arm the poll loop.
        expect(service._timer, 'a 401 must re-arm the poll loop').not.toBeNull();
        // 401 is not a 5xx → state must NOT be flipped to BACKOFF by the error.
        expect(service.state).not.toBe(KDS_SYNC_STATE.BACKOFF);
    });

    it('arms a fresh timer after a 429 (rate-limit, transient)', async () => {
        const { service } = serviceFor(429);
        service.start(1);
        service._clearTimers();

        await service.forceSync();

        expect(service._timer, 'a 429 must re-arm the poll loop').not.toBeNull();
        expect(service.state).not.toBe(KDS_SYNC_STATE.BACKOFF);
    });

    it('arms a fresh timer after a 403 / 404', async () => {
        for (const status of [403, 404]) {
            const { service } = serviceFor(status);
            service.start(1);
            service._clearTimers();
            await service.forceSync();
            expect(service._timer, `status ${status} must re-arm the loop`).not.toBeNull();
            service.stop();
        }
    });

    // DISCRIMINATING: drive PURELY by the one-shot timer (never call forceSync
    // manually) across multiple cadence periods. The finite _timer is one-shot —
    // continuation depends on forceSync() rescheduling itself. Buggy code: the
    // single start() tick fires once on a 401, never reschedules → count === 1.
    // Fixed code: each tick re-arms → count grows across periods.
    it('keeps polling across multiple periods on a persistent 401 (self-heal proof)', async () => {
        const { service, fetchFn } = serviceFor(401);
        service.start(1);

        await vi.advanceTimersByTimeAsync(service.currentIntervalMs * 3 + 200);

        expect(fetchFn.mock.calls.length).toBeGreaterThanOrEqual(3);
    });

    it('still backs off (doubles + BACKOFF) on a genuine 5xx (regression guard)', async () => {
        const { service } = serviceFor(503);
        service.start(1);
        const base = service.currentIntervalMs;

        await service.forceSync();

        expect(service.state).toBe(KDS_SYNC_STATE.BACKOFF);
        expect(service.currentIntervalMs).toBe(Math.min(base * 2, 30000));
        expect(service._timer).not.toBeNull();
    });
});
