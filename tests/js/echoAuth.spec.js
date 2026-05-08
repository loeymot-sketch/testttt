import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// [V1 SYNC_ROBUSTNESS 3.1] _refreshEchoAuth defensive guard
// We re-implement the helper locally because importing bootstrap.js boots Echo
// + Pusher and fights happy-dom. NOTE: this is a SIBLING COPY — there is no
// import wiring it to bootstrap.js, so drift is NOT auto-detected. Reviewers
// editing bootstrap.js must update this helper manually. The four tests below
// pin the four branches of the contract so the helper stays a faithful mirror.
function makeRefreshEchoAuth(getToken) {
    return function _refreshEchoAuth() {
        if (!window.Echo) {
            console.warn('[Echo] _refreshEchoAuth called but window.Echo not initialized');
            return false;
        }
        if (!window.Echo.connector?.options?.auth?.headers) {
            console.warn('[Echo] _refreshEchoAuth: connector.options.auth.headers not available');
            return false;
        }
        const token = getToken();
        if (!token) {
            console.warn('[Echo] _refreshEchoAuth: no token available');
            return false;
        }
        window.Echo.connector.options.auth.headers['Authorization'] = `Bearer ${token}`;
        return true;
    };
}

describe('_refreshEchoAuth defensive guards', () => {
    let warnSpy;

    beforeEach(() => {
        warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
        delete window.Echo;
    });

    afterEach(() => {
        warnSpy.mockRestore();
        delete window.Echo;
    });

    it('returns false when window.Echo is undefined', () => {
        const refresh = makeRefreshEchoAuth(() => 'tok');
        expect(refresh()).toBe(false);
        expect(warnSpy).toHaveBeenCalledWith(
            expect.stringContaining('window.Echo not initialized'),
        );
    });

    it('returns false when connector.options.auth.headers is missing', () => {
        window.Echo = { connector: {} };
        const refresh = makeRefreshEchoAuth(() => 'tok');
        expect(refresh()).toBe(false);
        expect(warnSpy).toHaveBeenCalledWith(
            expect.stringContaining('connector.options.auth.headers not available'),
        );
    });

    it('returns false when token is missing/empty', () => {
        window.Echo = {
            connector: { options: { auth: { headers: {} } } },
        };
        const refresh = makeRefreshEchoAuth(() => '');
        expect(refresh()).toBe(false);
        expect(warnSpy).toHaveBeenCalledWith(
            expect.stringContaining('no token available'),
        );
    });

    it('returns true and injects Bearer token when all preconditions met', () => {
        const headers = {};
        window.Echo = {
            connector: { options: { auth: { headers } } },
        };
        const refresh = makeRefreshEchoAuth(() => 'abc123');
        expect(refresh()).toBe(true);
        expect(headers.Authorization).toBe('Bearer abc123');
    });
});
