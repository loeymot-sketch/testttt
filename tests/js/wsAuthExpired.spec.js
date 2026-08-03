import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { WebSocketService, WS_STATE } from '../../resources/js/services/WebSocketService';

/**
 * [F-12] Reactive auth-error surfacing.
 *
 * Sentinel: when bootstrap.js (or any binder) forwards a Pusher
 * `subscription_error` payload to wsService.handleSubscriptionError, the
 * service MUST emit `auth_error` exactly once with the original payload.
 */
describe('WebSocketService auth_error surfacing', () => {
    let svc;

    beforeEach(() => {
        svc = new WebSocketService();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('exposes handleSubscriptionError and onAuthError as public API', () => {
        expect(typeof svc.handleSubscriptionError).toBe('function');
        expect(typeof svc.onAuthError).toBe('function');
        expect(svc.getState()).toBe(WS_STATE.INITIALIZED);
        expect(svc.authFailureCount).toBe(0);
    });

    it('emits auth_error once when handleSubscriptionError is called once', () => {
        const handler = vi.fn();
        svc.onAuthError(handler);

        svc.handleSubscriptionError({ status: 403, type: 'AuthError' });

        expect(handler).toHaveBeenCalledTimes(1);
        expect(handler).toHaveBeenCalledWith(expect.objectContaining({ status: 403 }));
        expect(svc.authFailureCount).toBe(1);
    });

    it('does NOT enter SESSION_INVALID after a single failure', () => {
        const sessionInvalid = vi.fn();
        svc.on('session_invalid', sessionInvalid);

        svc.handleSubscriptionError({ status: 403 });

        expect(svc.getState()).not.toBe(WS_STATE.SESSION_INVALID);
        expect(sessionInvalid).not.toHaveBeenCalled();
    });
});
