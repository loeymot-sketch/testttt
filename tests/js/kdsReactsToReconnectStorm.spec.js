import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { KdsSyncService } from '../../resources/js/services/KdsSyncService.js';

// [NEW-02] Cross-service contract: when WebSocketService emits
// 'reconnect_storm', KdsSyncService must immediately run a forceSync() so the
// kitchen does not go blind during the breaker cool-down.
describe('KdsSyncService reaction to reconnect_storm (NEW-02)', () => {
    let wsService;
    let listeners;
    let fetchFn;
    let kds;

    const okPayload = () => Promise.resolve({
        ok: true,
        json: () => Promise.resolve({
            orders: [],
            deleted_ids: [],
            server_now: '2026-04-23T00:00:01.000Z',
        }),
    });

    let randomSpy;

    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-04-23T00:00:00.000Z'));
        // [NEW-02 audit G10] Force jitter to 0 so forceSync() runs synchronously
        // and these unit tests stay deterministic. Production keeps the 0–500ms
        // jitter to spread the storm-triggered request burst across the fleet.
        randomSpy = vi.spyOn(Math, 'random').mockReturnValue(0);

        listeners = new Map();
        wsService = {
            state: 'DEGRADED',
            on: vi.fn((event, handler) => {
                if (!listeners.has(event)) listeners.set(event, []);
                listeners.get(event).push(handler);
                return () => {
                    const arr = listeners.get(event) || [];
                    const i = arr.indexOf(handler);
                    if (i >= 0) arr.splice(i, 1);
                };
            }),
        };

        fetchFn = vi.fn(okPayload);
        kds = new KdsSyncService({ wsService, fetchFn, now: () => Date.now() });
        kds.start(42);
    });

    afterEach(() => {
        kds.stop();
        randomSpy.mockRestore();
        vi.clearAllTimers();
        vi.useRealTimers();
    });

    const emitStorm = (payload) => {
        const handlers = listeners.get('reconnect_storm') || [];
        handlers.forEach((h) => h(payload));
    };

    it('runs forceSync() exactly once when reconnect_storm fires', async () => {
        const before = fetchFn.mock.calls.length;
        emitStorm({ delay_ms: 5000, attempts_in_window: 4, next_reconnect_at: Date.now() + 5000 });

        // Yield microtasks so the async forceSync() body runs.
        await Promise.resolve();
        await Promise.resolve();

        expect(fetchFn.mock.calls.length).toBe(before + 1);
        expect(fetchFn.mock.calls[before][0]).toMatch(/\/api\/admin\/kds-order\/sync\?since=/);
    });

    it('re-emits reconnect_storm to KdsSyncService subscribers', () => {
        const onStorm = vi.fn();
        kds.on('reconnect_storm', onStorm);

        const payload = { delay_ms: 7500, attempts_in_window: 4, next_reconnect_at: Date.now() + 7500 };
        emitStorm(payload);

        expect(onStorm).toHaveBeenCalledTimes(1);
        expect(onStorm).toHaveBeenCalledWith(payload);
    });

    it('does NOT crash when forceSync() rejects (defensive guard on event handler)', async () => {
        fetchFn.mockImplementationOnce(() => Promise.reject(new Error('network down')));
        const onError = vi.fn();
        kds.on('error', onError);

        // The emitStorm() call MUST itself be synchronous-safe (no unhandled rejection).
        expect(() => emitStorm({ delay_ms: 5000, attempts_in_window: 4 })).not.toThrow();

        // Drain pending microtasks AND fake timers so the awaited rejection
        // resolves and the catch block emits 'error'.
        for (let i = 0; i < 10; i += 1) {
            await Promise.resolve();
        }
        await vi.runOnlyPendingTimersAsync();

        expect(onError).toHaveBeenCalledTimes(1);
        expect(onError.mock.calls[0][0].message).toMatch(/network down/);
    });
});
