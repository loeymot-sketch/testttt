import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { WebSocketService, WS_STATE } from '../../resources/js/services/WebSocketService.js';

// [NEW-02 audit T-MISS-C] F-12 (auth-failure → SESSION_INVALID) and NEW-02
// (disconnect storm → reconnect_storm) live in the same WebSocketService.
// They must NOT share counters, NOT share cleanup paths, and NOT corrupt each
// other when both fire in the same session.
describe('WebSocketService — F-12 and NEW-02 cohabitation', () => {
    let service;
    let randomSpy;

    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-04-23T00:00:00.000Z'));
        randomSpy = vi.spyOn(Math, 'random').mockReturnValue(0.5);
        service = new WebSocketService();
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

    it('emits reconnect_storm WITHOUT triggering session_invalid (independent counters)', () => {
        const onStorm = vi.fn();
        const onSessionInvalid = vi.fn();
        const onAuthError = vi.fn();
        service.on('reconnect_storm', onStorm);
        service.on('session_invalid', onSessionInvalid);
        service.on('auth_error', onAuthError);

        // 4 disconnects → storm fires.
        service._setState(WS_STATE.DISCONNECTED);
        service._setState(WS_STATE.UNAVAILABLE);
        service._setState(WS_STATE.DISCONNECTED);
        service._setState(WS_STATE.UNAVAILABLE);

        expect(onStorm).toHaveBeenCalledTimes(1);
        expect(onSessionInvalid).not.toHaveBeenCalled();
        expect(onAuthError).not.toHaveBeenCalled();
        expect(service.authFailureCount).toBe(0);
    });

    it('keeps F-12 auth-failure counter intact after a CONNECTED reset clears the storm window', () => {
        const onStorm = vi.fn();
        const onSessionInvalid = vi.fn();
        service.on('reconnect_storm', onStorm);
        service.on('session_invalid', onSessionInvalid);

        // 2 auth failures (under the F-12 threshold of 3).
        service.handleSubscriptionError({ reason: 'auth_failed' });
        service.handleSubscriptionError({ reason: 'auth_failed' });
        expect(service.authFailureCount).toBe(2);
        expect(onSessionInvalid).not.toHaveBeenCalled();

        // 4 disconnects → storm fires.
        service._setState(WS_STATE.DISCONNECTED);
        service._setState(WS_STATE.UNAVAILABLE);
        service._setState(WS_STATE.DISCONNECTED);
        service._setState(WS_STATE.UNAVAILABLE);
        expect(onStorm).toHaveBeenCalledTimes(1);

        // CONNECTED → resets BOTH auth failures (F-12 contract) AND storm
        // window (NEW-02 contract). Confirm both counters dropped to 0.
        service._setState(WS_STATE.CONNECTED);
        expect(service.authFailureCount).toBe(0);
        expect(service.getDisconnectAttemptsInWindow()).toBe(0);
        expect(service.isCircuitBreakerOpen()).toBe(false);
        expect(onSessionInvalid).not.toHaveBeenCalled();
    });

    // [NEW-02 audit-2 T-NEW-1] If F-12 promotes the session to
    // SESSION_INVALID while the storm breaker is still open, the timer-fire
    // MUST NOT call pusher.connect() (token revoked, doomed reconnect spin).
    // The breaker still self-clears so a future re-login can re-open it.
    it('cancels the pending pusher.connect() when SESSION_INVALID fires during the cool-down', () => {
        const onStorm = vi.fn();
        const onSessionInvalid = vi.fn();
        service.on('reconnect_storm', onStorm);
        service.on('session_invalid', onSessionInvalid);

        service._setState(WS_STATE.DISCONNECTED);
        service._setState(WS_STATE.UNAVAILABLE);
        service._setState(WS_STATE.DISCONNECTED);
        service._setState(WS_STATE.UNAVAILABLE);
        expect(onStorm).toHaveBeenCalledTimes(1);
        expect(service.isCircuitBreakerOpen()).toBe(true);

        const pusher = global.window.Echo.connector.pusher;
        const delay = onStorm.mock.calls[0][0].delay_ms;

        service.handleSubscriptionError({ reason: 'auth_failed' });
        service.handleSubscriptionError({ reason: 'auth_failed' });
        service.handleSubscriptionError({ reason: 'auth_failed' });
        expect(onSessionInvalid).toHaveBeenCalledTimes(1);
        expect(service.getState()).toBe('session_invalid');
        expect(service.isCircuitBreakerOpen()).toBe(false);

        vi.advanceTimersByTime(delay + 1_000);

        expect(pusher.connect).not.toHaveBeenCalled();
        expect(service.isCircuitBreakerOpen()).toBe(false);
    });

    it('crosses the F-12 threshold AFTER a storm without sharing state', () => {
        const onSessionInvalid = vi.fn();
        const onStorm = vi.fn();
        service.on('session_invalid', onSessionInvalid);
        service.on('reconnect_storm', onStorm);

        // Storm first.
        service._setState(WS_STATE.DISCONNECTED);
        service._setState(WS_STATE.UNAVAILABLE);
        service._setState(WS_STATE.DISCONNECTED);
        service._setState(WS_STATE.UNAVAILABLE);
        expect(onStorm).toHaveBeenCalledTimes(1);
        expect(onSessionInvalid).not.toHaveBeenCalled();

        // Then 3 auth failures → SESSION_INVALID.
        service.handleSubscriptionError({ reason: 'auth_failed' });
        service.handleSubscriptionError({ reason: 'auth_failed' });
        service.handleSubscriptionError({ reason: 'auth_failed' });

        expect(onSessionInvalid).toHaveBeenCalledTimes(1);
        // The storm event MUST NOT fire again from the auth path.
        expect(onStorm).toHaveBeenCalledTimes(1);
        // SESSION_INVALID is its own state; storm timestamps are not affected.
        expect(service.getState()).toBe('session_invalid');
    });
});
