import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { WebSocketService, WS_STATE } from '../../resources/js/services/WebSocketService';

/**
 * [F-12] Sliding-window auth failure detection + idempotent SESSION_INVALID.
 *
 * Behavior contract:
 *   - 3 subscription_error within 60s → state SESSION_INVALID, event 'session_invalid'
 *     emitted exactly once.
 *   - A 4th failure does NOT re-emit 'session_invalid'.
 *   - A successful CONNECTED state transition resets the failure counter.
 */
describe('WebSocketService auth failure loop', () => {
    let svc;

    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-04-23T00:00:00Z'));
        svc = new WebSocketService();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('transitions to SESSION_INVALID after 3 failures within 60s and emits session_invalid once', () => {
        const sessionInvalid = vi.fn();
        const authError = vi.fn();
        svc.on('session_invalid', sessionInvalid);
        svc.onAuthError(authError);

        svc.handleSubscriptionError({ seq: 1 });
        vi.advanceTimersByTime(1000);
        svc.handleSubscriptionError({ seq: 2 });
        vi.advanceTimersByTime(1000);
        svc.handleSubscriptionError({ seq: 3 });

        expect(svc.getState()).toBe(WS_STATE.SESSION_INVALID);
        expect(svc.authFailureCount).toBe(3);
        expect(sessionInvalid).toHaveBeenCalledTimes(1);
        expect(authError).toHaveBeenCalledTimes(3);

        vi.advanceTimersByTime(1000);
        svc.handleSubscriptionError({ seq: 4 });

        expect(svc.authFailureCount).toBe(4);
        expect(svc.getState()).toBe(WS_STATE.SESSION_INVALID);
        expect(sessionInvalid).toHaveBeenCalledTimes(1);
        expect(authError).toHaveBeenCalledTimes(4);
    });

    it('does NOT count failures older than 60s in the sliding window', () => {
        svc.handleSubscriptionError({ seq: 'a' });
        expect(svc.authFailureCount).toBe(1);

        vi.advanceTimersByTime(60001);
        expect(svc.authFailureCount).toBe(0);

        svc.handleSubscriptionError({ seq: 'b' });
        svc.handleSubscriptionError({ seq: 'c' });
        expect(svc.authFailureCount).toBe(2);
        expect(svc.getState()).not.toBe(WS_STATE.SESSION_INVALID);
    });

    it('resets authFailureCount and re-arms session_invalid emission on a successful CONNECTED transition', () => {
        const sessionInvalid = vi.fn();
        svc.on('session_invalid', sessionInvalid);

        svc.handleSubscriptionError({ seq: 1 });
        svc.handleSubscriptionError({ seq: 2 });
        expect(svc.authFailureCount).toBe(2);

        svc._setState(WS_STATE.CONNECTED);

        expect(svc.authFailureCount).toBe(0);
        expect(svc.getState()).toBe(WS_STATE.CONNECTED);

        svc.handleSubscriptionError({ seq: 3 });
        svc.handleSubscriptionError({ seq: 4 });
        svc.handleSubscriptionError({ seq: 5 });

        expect(svc.getState()).toBe(WS_STATE.SESSION_INVALID);
        expect(sessionInvalid).toHaveBeenCalledTimes(1);
    });
});
