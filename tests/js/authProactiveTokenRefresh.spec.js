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
        const state = { authToken: 'OLD' };
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
