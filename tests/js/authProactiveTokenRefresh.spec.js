import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('axios', () => ({ default: { post: vi.fn() } }));
import axios from 'axios';
import { auth } from '../../resources/js/store/modules/auth.js';

/**
 * [GOAL-2026-05-29 P-AUTH] Proactive Sanctum token refresh.
 *
 * The SPA is Bearer-everywhere: the KDS/OSS delta-poll AND the WebSocket channel-auth
 * both use the Sanctum token (TTL 480min). Without a proactive refresh the ENTIRE live
 * sync (WS + poll) dies at ~8h — an always-on KDS/OSS would silently stop receiving
 * orders mid service-day. app.js/pos-app.js install a 2h timer dispatching this action,
 * which re-issues a fresh abilities-preserved Bearer via the existing /api/refresh-token.
 * This locks the action + mutation contract.
 */
describe('[P-AUTH] auth.refreshAuthToken — proactive token refresh', () => {
    beforeEach(() => { axios.post.mockReset(); });

    it('refreshes the Bearer via /refresh-token and commits the fresh token', async () => {
        axios.post.mockResolvedValue({ data: { token: 'NEW-789|fresh' } });
        const committed = [];
        const ctx = { state: { authToken: 'OLD-788|stale' }, commit: (m, p) => committed.push([m, p]) };

        const ok = await auth.actions.refreshAuthToken(ctx);

        expect(ok).toBe(true);
        expect(axios.post).toHaveBeenCalledWith('refresh-token', { token: 'OLD-788|stale' });
        expect(committed).toContainEqual(['authTokenRefreshed', 'NEW-789|fresh']);
    });

    it('authTokenRefreshed mutation replaces state.authToken with the fresh token', () => {
        const state = { authStatus: true, authToken: 'OLD' };
        auth.mutations.authTokenRefreshed(state, 'FRESH');
        expect(state.authToken).toBe('FRESH');
    });

    it('no-ops (no request) when there is no current token', async () => {
        const ctx = { state: { authToken: null }, commit: vi.fn() };
        const ok = await auth.actions.refreshAuthToken(ctx);
        expect(ok).toBe(false);
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('keeps the existing token (resolves false, never throws) when the refresh fails', async () => {
        axios.post.mockRejectedValue(new Error('network down'));
        const ctx = { state: { authToken: 'OLD' }, commit: vi.fn() };

        const ok = await auth.actions.refreshAuthToken(ctx);

        expect(ok).toBe(false);
        expect(ctx.commit).not.toHaveBeenCalled(); // existing token untouched -> reactive net + 401->login handle it
    });
});

/**
 * [GOAL-2026-05-29 P-AUTH-SYNC] The Echo auth header must be re-injected with the
 * token the mutation JUST set — NOT re-read from localStorage, which vuex-persist
 * writes only AFTER the mutation. A stale token here makes the first private-channel
 * subscribe after login fail (chef KDS silently degrades to 60s poll). Verified live:
 * pre-fix `private-branch.1` subscribed:false after a fresh chef login; post-fix it
 * subscribes on the first try (6ms push latency measured). These tests lock the
 * contract that the mutations pass the fresh token explicitly.
 */
describe('[P-AUTH-SYNC] mutations re-inject Echo auth with the FRESH token (no stale-by-one)', () => {
    let calls;
    beforeEach(() => {
        calls = [];
        window._refreshEchoAuth = (t) => { calls.push(t); };
    });
    afterEach(() => { delete window._refreshEchoAuth; });

    it('authLogin passes the new login token to _refreshEchoAuth (not undefined)', () => {
        const state = {};
        auth.mutations.authLogin(state, { token: 'LOGIN-FRESH-123', branch_id: 1, user: {}, menu: [], permission: {}, defaultPermission: {}, defaultMenu: {} });
        expect(calls).toEqual(['LOGIN-FRESH-123']);
        expect(state.authToken).toBe('LOGIN-FRESH-123');
    });

    it('authTokenRefreshed passes the refreshed token to _refreshEchoAuth', () => {
        const state = { authStatus: true, authToken: 'OLD' };
        auth.mutations.authTokenRefreshed(state, 'REFRESH-FRESH-456');
        expect(calls).toEqual(['REFRESH-FRESH-456']);
        expect(state.authToken).toBe('REFRESH-FRESH-456');
    });
});

/**
 * [REG-1 2026-05-30] Logout-during-refresh must NOT resurrect the session, and a
 * cross-tab/late-resolving refresh must not clobber a deliberately-ended session.
 * Found by the post-fix adversarial audit (regression introduced by the 2h proactive
 * refresh timer). The action re-checks the token identity at resolve time; the mutation
 * hard-guards on authStatus.
 */
describe('[REG-1] proactive refresh never resurrects a logged-out session', () => {
    beforeEach(() => { axios.post.mockReset(); });

    it('authTokenRefreshed no-ops when logged out (authStatus false)', () => {
        const state = { authStatus: false, authToken: null };
        auth.mutations.authTokenRefreshed(state, 'RESURRECT-ME');
        expect(state.authToken).toBe(null); // session stays ended
    });

    it('refreshAuthToken does NOT commit if the token changed mid-flight (logout race)', async () => {
        const ctx = { state: { authToken: 'OLD' }, commit: vi.fn() };
        axios.post.mockImplementation(() => {
            ctx.state.authToken = null; // operator logged out while the POST was in flight
            return Promise.resolve({ data: { token: 'NEW' } });
        });
        const ok = await auth.actions.refreshAuthToken(ctx);
        expect(ok).toBe(false);
        expect(ctx.commit).not.toHaveBeenCalled();
    });

    it('refreshAuthToken still commits normally when the token is unchanged mid-flight', async () => {
        const committed = [];
        const ctx = { state: { authToken: 'OLD' }, commit: (m, p) => committed.push([m, p]) };
        axios.post.mockResolvedValue({ data: { token: 'NEW' } });
        const ok = await auth.actions.refreshAuthToken(ctx);
        expect(ok).toBe(true);
        expect(committed).toContainEqual(['authTokenRefreshed', 'NEW']);
    });
});
