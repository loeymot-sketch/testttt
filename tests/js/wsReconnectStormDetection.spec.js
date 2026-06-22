import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    STORM_DETECTION_WINDOW_MS,
    WebSocketService,
    WS_STATE,
} from '../../resources/js/services/WebSocketService.js';

// [NEW-02] Sliding-window reconnect-storm detection. Independent from the
// F-12 auth-failure window (different counters, different thresholds, no
// shared state).
describe('WebSocketService reconnect-storm detection (NEW-02)', () => {
    let service;
    let randomSpy;

    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-04-23T00:00:00.000Z'));
        randomSpy = vi.spyOn(Math, 'random').mockReturnValue(0.5);
        service = new WebSocketService();
        // Stub Pusher so the breaker can call disconnect/connect without crash.
        global.window = global.window || {};
        global.window.Echo = {
            connector: { pusher: { disconnect: vi.fn(), connect: vi.fn() } },
        };
    });

    afterEach(() => {
        service.destroy();
        randomSpy.mockRestore();
        vi.clearAllTimers();
        vi.useRealTimers();
        delete global.window.Echo;
    });

    const triggerDisconnect = () => {
        // Use UNAVAILABLE the first time so the listener fires (since we start
        // in INITIALIZED), then alternate to allow repeated state transitions.
        service._setState(service._state === WS_STATE.DISCONNECTED ? WS_STATE.UNAVAILABLE : WS_STATE.DISCONNECTED);
    };

    it('does NOT detect a storm under threshold (3 disconnects)', () => {
        const onStorm = vi.fn();
        service.on('reconnect_storm', onStorm);

        triggerDisconnect();
        triggerDisconnect();
        triggerDisconnect();

        expect(onStorm).not.toHaveBeenCalled();
        expect(service.isCircuitBreakerOpen()).toBe(false);
        expect(service.getDisconnectAttemptsInWindow()).toBe(3);
    });

    it('detects a storm at threshold (4 disconnects)', () => {
        const onStorm = vi.fn();
        service.on('reconnect_storm', onStorm);

        triggerDisconnect();
        triggerDisconnect();
        triggerDisconnect();
        triggerDisconnect();

        expect(onStorm).toHaveBeenCalledTimes(1);
        expect(service.isCircuitBreakerOpen()).toBe(true);

        const payload = onStorm.mock.calls[0][0];
        expect(payload).toEqual(expect.objectContaining({
            delay_ms: expect.any(Number),
            attempts_in_window: 4,
            next_reconnect_at: expect.any(Number),
        }));
        expect(payload.delay_ms).toBeGreaterThanOrEqual(5_000);
        expect(payload.delay_ms).toBeLessThanOrEqual(30_000);
    });

    it('prunes timestamps outside the 30-second window', () => {
        const onStorm = vi.fn();
        service.on('reconnect_storm', onStorm);

        triggerDisconnect();
        triggerDisconnect();
        triggerDisconnect();

        // Advance past the window so all 3 timestamps are stale.
        vi.advanceTimersByTime(STORM_DETECTION_WINDOW_MS + 1_000);

        triggerDisconnect();

        expect(onStorm).not.toHaveBeenCalled();
        expect(service.isCircuitBreakerOpen()).toBe(false);
        expect(service.getDisconnectAttemptsInWindow()).toBe(1);
    });
});
