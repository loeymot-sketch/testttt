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
window.Pusher = Pusher;

if (import.meta.env.VITE_PUSHER_APP_KEY) {
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
        key: import.meta.env.VITE_PUSHER_APP_KEY,
        wsHost: import.meta.env.VITE_PUSHER_HOST ?? `ws-${import.meta.env.VITE_PUSHER_APP_CLUSTER}.pusher.com`,
        wsPort: import.meta.env.VITE_PUSHER_PORT ?? 80,
        wssPort: import.meta.env.VITE_PUSHER_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_PUSHER_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
        // Auth endpoint protected by auth:sanctum (see BroadcastServiceProvider)
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
    window._refreshEchoAuth = function () {
        const token = _getEchoBearerToken();
        if (window.Echo && window.Echo.connector && window.Echo.connector.pusher) {
            window.Echo.connector.options.auth.headers['Authorization'] = `Bearer ${token}`;
        }
    };
}
