import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    STORM_MAX_DELAY_MS,
    STORM_MIN_DELAY_MS,
    WebSocketService,
    WS_STATE,
} from '../../resources/js/services/WebSocketService.js';

// [NEW-02] Decorrelated-jitter circuit breaker behaviour: pusher.disconnect()
// fires immediately, pusher.connect() fires once after the jitter delay, the
// breaker is single-open, resets on CONNECTED, and the delay never exceeds
// STORM_MAX_DELAY_MS.
describe('WebSocketService reconnect-storm circuit breaker (NEW-02)', () => {
    let service;
    let pusher;
    let randomSpy;

    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-04-23T00:00:00.000Z'));
        randomSpy = vi.spyOn(Math, 'random').mockReturnValue(0.5);
        service = new WebSocketService();
        pusher = { disconnect: vi.fn(), connect: vi.fn() };
        global.window = global.window || {};
        global.window.Echo = { connector: { pusher } };
    });

    afterEach(() => {
        service.destroy();
        randomSpy.mockRestore();
        vi.clearAllTimers();
        vi.useRealTimers();
        delete global.window.Echo;
    });

    const triggerStorm = () => {
        service._setState(WS_STATE.DISCONNECTED);
        service._setState(WS_STATE.UNAVAILABLE);
        service._setState(WS_STATE.DISCONNECTED);
        service._setState(WS_STATE.UNAVAILABLE);
    };

    it('calls pusher.disconnect() then schedules pusher.connect() after the jitter delay', () => {
        const onStorm = vi.fn();
        service.on('reconnect_storm', onStorm);

        triggerStorm();

        expect(pusher.disconnect).toHaveBeenCalledTimes(1);
        expect(pusher.connect).not.toHaveBeenCalled();

        const delay = onStorm.mock.calls[0][0].delay_ms;
        vi.advanceTimersByTime(delay - 1);
        expect(pusher.connect).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(pusher.connect).toHaveBeenCalledTimes(1);
        // Breaker flag flips back to false after timer fires (so a fresh
        // outage can immediately open a NEW breaker).
        expect(service.isCircuitBreakerOpen()).toBe(false);
    });

    it('does NOT open a second breaker while one is already open', () => {
        const onStorm = vi.fn();
        service.on('reconnect_storm', onStorm);

        triggerStorm();
        // Hammer further disconnects while the breaker is open: NO new emission.
        service._setState(WS_STATE.DISCONNECTED);
        service._setState(WS_STATE.UNAVAILABLE);
        service._setState(WS_STATE.DISCONNECTED);
        service._setState(WS_STATE.UNAVAILABLE);

        expect(onStorm).toHaveBeenCalledTimes(1);
        expect(pusher.disconnect).toHaveBeenCalledTimes(1);
        expect(service.isCircuitBreakerOpen()).toBe(true);
    });

    it('resets the breaker on successful CONNECTED transition AND clears the timestamp window', () => {
        const onStorm = vi.fn();
        service.on('reconnect_storm', onStorm);

        triggerStorm();
        const delay = onStorm.mock.calls[0][0].delay_ms;
        vi.advanceTimersByTime(delay);

        service._setState(WS_STATE.CONNECTED);

        expect(service.isCircuitBreakerOpen()).toBe(false);
        expect(service.getDisconnectAttemptsInWindow()).toBe(0);

        // After reset, 3 fresh disconnects must NOT trigger a storm (window cleared).
        service._setState(WS_STATE.DISCONNECTED);
        service._setState(WS_STATE.UNAVAILABLE);
        service._setState(WS_STATE.DISCONNECTED);
        expect(onStorm).toHaveBeenCalledTimes(1);

        // 4th disconnect → fresh storm.
        service._setState(WS_STATE.UNAVAILABLE);
        expect(onStorm).toHaveBeenCalledTimes(2);
    });

    // [NEW-02 audit T-MISS-A] destroy() during open breaker MUST cancel the
    // pending pusher.connect() so we never reconnect after teardown.
    it('cancels the pending pusher.connect() if destroy() is called while breaker is open', () => {
        triggerStorm();
        expect(service.isCircuitBreakerOpen()).toBe(true);

        service.destroy();

        vi.advanceTimersByTime(STORM_MAX_DELAY_MS + 1_000);
        expect(pusher.connect).not.toHaveBeenCalled();
        expect(service.isCircuitBreakerOpen()).toBe(false);
    });

    // [NEW-02 audit T-MISS-D] pusher.disconnect() throws → breaker MUST still
    // open and the timer MUST still schedule the reconnect attempt.
    it('opens the breaker and schedules reconnect even when pusher.disconnect() throws', () => {
        const onStorm = vi.fn();
        service.on('reconnect_storm', onStorm);
        pusher.disconnect.mockImplementation(() => { throw new Error('boom'); });

        triggerStorm();

        expect(onStorm).toHaveBeenCalledTimes(1);
        expect(service.isCircuitBreakerOpen()).toBe(true);

        const delay = onStorm.mock.calls[0][0].delay_ms;
        vi.advanceTimersByTime(delay);
        expect(pusher.connect).toHaveBeenCalledTimes(1);
    });

    // [NEW-02 audit T-MISS-E] window.Echo absent → breaker MUST still open
    // (so reconnect_storm fires for polling fallbacks) and the timer MUST
    // fire safely without crashing.
    it('safely opens and closes the breaker when window.Echo is unavailable', () => {
        delete global.window.Echo;
        const onStorm = vi.fn();
        service.on('reconnect_storm', onStorm);

        triggerStorm();

        expect(onStorm).toHaveBeenCalledTimes(1);
        expect(service.isCircuitBreakerOpen()).toBe(true);

        const delay = onStorm.mock.calls[0][0].delay_ms;
        expect(() => vi.advanceTimersByTime(delay + 1)).not.toThrow();
        expect(service.isCircuitBreakerOpen()).toBe(false);
    });

    it('uses decorrelated-jitter formula bounded by STORM_MAX_DELAY_MS', () => {
        randomSpy.mockReturnValue(0.99);
        const onStorm = vi.fn();
        service.on('reconnect_storm', onStorm);

        for (let i = 0; i < 5; i += 1) {
            triggerStorm();
            const payload = onStorm.mock.calls[i][0];
            expect(payload.delay_ms).toBeGreaterThanOrEqual(STORM_MIN_DELAY_MS);
            expect(payload.delay_ms).toBeLessThanOrEqual(STORM_MAX_DELAY_MS);
            vi.advanceTimersByTime(payload.delay_ms);
            // Simulate the next CONNECTED so the breaker resets and the loop
            // can open a fresh one with growing _lastStormDelayMs.
            service._setState(WS_STATE.CONNECTED);
        }

        expect(onStorm).toHaveBeenCalledTimes(5);
    });

    // [NEW-02 audit T-MISS-B] _lastStormDelayMs intentionally survives the
    // CONNECTED reset, so successive storms in one page session ramp up the
    // backoff per the AWS canonical decorrelated-jitter recipe (base = prev).
    it('grows decorrelated-jitter delay across successive storms in one session', () => {
        randomSpy.mockReturnValue(0.99);
        const onStorm = vi.fn();
        service.on('reconnect_storm', onStorm);

        // 1st storm: prev = MIN(5_000), upper = max(MIN, 5_000*3)=15_000.
        // delay = MIN + 0.99 * (15_000 - MIN) = 5_000 + 9_900 = 14_900.
        triggerStorm();
        const delay1 = onStorm.mock.calls[0][0].delay_ms;
        expect(delay1).toBeGreaterThanOrEqual(STORM_MIN_DELAY_MS);
        expect(delay1).toBeLessThanOrEqual(STORM_MAX_DELAY_MS);

        vi.advanceTimersByTime(delay1);
        service._setState(WS_STATE.CONNECTED);

        // 2nd storm: prev = delay1 (~14_900), upper = min(MAX, 14_900*3) = MAX(30_000).
        // delay grows to nearly MAX with high random.
        triggerStorm();
        const delay2 = onStorm.mock.calls[1][0].delay_ms;

        expect(delay2).toBeGreaterThan(delay1);
        expect(delay2).toBeLessThanOrEqual(STORM_MAX_DELAY_MS);
    });
});
