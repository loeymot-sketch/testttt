import _ from 'lodash';
window._ = _;

import 'bootstrap';

/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allows your team to easily build robust real-time web applications.
 */

// [P4-1] Laravel Echo + Pusher/Soketi for real-time KDS/OSS updates
// Requires VITE_PUSHER_* env vars and a running Soketi/Pusher server
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { wsService, WS_STATE } from './services/WebSocketService';
window.Pusher = Pusher;

// [V5-BUGFIX] Laravel Mix (webpack) expose env via process.env.MIX_* — PAS import.meta.env.VITE_*
// L'ancien code utilisait VITE_PUSHER_APP_KEY qui est toujours undefined sous webpack,
// donc Echo n'était jamais initialisé → temps réel silencieusement cassé depuis le début.
const _MIX_PUSHER_APP_KEY     = process.env.MIX_PUSHER_APP_KEY;
const _MIX_PUSHER_APP_CLUSTER = process.env.MIX_PUSHER_APP_CLUSTER;
const _MIX_PUSHER_HOST        = process.env.MIX_PUSHER_HOST;
const _MIX_PUSHER_PORT        = process.env.MIX_PUSHER_PORT;
const _MIX_PUSHER_SCHEME      = process.env.MIX_PUSHER_SCHEME;

if (_MIX_PUSHER_APP_KEY) {
    // [GAP-34-2] Echo must send the Sanctum Bearer token when authenticating private channels.
    // Default Echo auth uses cookies (session) — this SPA uses Bearer tokens.
    // We read the token from localStorage (same source as the axios interceptor in app.js).
    // authEndpoint points to /api/broadcasting/auth (Sanctum-protected, see BroadcastServiceProvider).
    function _getEchoBearerToken() {
        try {
            const vuex = JSON.parse(localStorage.getItem('vuex') || '{}');
            return vuex.kioskCart?.kioskToken || vuex.auth?.authToken || '';
        } catch (_) {
            return '';
        }
    }

    const _baseUrl = (window.foodkingConfig?.baseUrl || window.location.origin).replace(/\/$/, '');

    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: _MIX_PUSHER_APP_KEY,
        wsHost: _MIX_PUSHER_HOST || `ws-${_MIX_PUSHER_APP_CLUSTER || 'mt1'}.pusher.com`,
        wsPort: Number(_MIX_PUSHER_PORT || 80),
        wssPort: Number(_MIX_PUSHER_PORT || 443),
        forceTLS: (_MIX_PUSHER_SCHEME || 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        cluster: _MIX_PUSHER_APP_CLUSTER || 'mt1',
        // [V1 SYNC_BACKBONE] Aggressive liveness detection:
        // - activityTimeout: if no activity for 30s, client sends ping.
        // - pongTimeout: if pong doesn't come back in 5s, client reconnects.
        // This guarantees a stale-connection is detected within ~35s max and
        // triggers Pusher's internal exponential backoff (1s → 2s → 4s → … → 30s).
        activityTimeout: 30000,
        pongTimeout: 5000,
        authEndpoint: `${_baseUrl}/api/broadcasting/auth`,
        auth: {
            headers: {
                Authorization: `Bearer ${_getEchoBearerToken()}`,
                'x-api-key': window.foodkingConfig?.apiKey || '',
            },
        },
    });

    // [GAP-34-2] Re-inject the token after login (token not available at page load).
    // When the user logs in, the store updates authToken — Echo must pick it up.
    // We expose a helper so auth.js can call window._refreshEchoAuth() after login.
    // [V1 SYNC_ROBUSTNESS 3.1] Defensive guards — return false on every failure
    // path so callers can detect a no-op (current callers ignore return value,
    // but tests assert each branch + console.warn breadcrumb is emitted for ops).
    window._refreshEchoAuth = function () {
        if (!window.Echo) {
            console.warn('[Echo] _refreshEchoAuth called but window.Echo not initialized');
            return false;
        }
        if (!window.Echo.connector?.options?.auth?.headers) {
            console.warn('[Echo] _refreshEchoAuth: connector.options.auth.headers not available');
            return false;
        }
        const token = _getEchoBearerToken();
        if (!token) {
            console.warn('[Echo] _refreshEchoAuth: no token available');
            return false;
        }
        window.Echo.connector.options.auth.headers['Authorization'] = `Bearer ${token}`;
        return true;
    };

    wsService.start();
    window._wsService = wsService;
} else {
    wsService._setState(WS_STATE.UNAVAILABLE);
    window._wsService = wsService;
    console.warn('[WS] MIX_PUSHER_APP_KEY not set in .env — WebSocket disabled, polling-only mode.');
}
