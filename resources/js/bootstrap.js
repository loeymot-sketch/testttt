import _ from 'lodash';
window._ = _;

import 'bootstrap';

/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

import axios from 'axios';
import { useToast as _useToast } from 'vue-toastification';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// [NEW-04 / Audit T G9] Capture the X-Correlation-ID echoed back by
// CorrelationIdMiddleware so MetricsBatcher.readCorrelationId() can attach
// the SAME id to client-metrics POSTs. Without this, frontend telemetry would
// be unjoinable to the originating backend trace. The interceptor is
// non-blocking (best-effort) and never rejects the request chain.
try {
    window.axios.interceptors.response.use((response) => {
        try {
            const cid = response?.headers?.['x-correlation-id']
                || response?.headers?.['X-Correlation-ID'];
            if (cid && typeof cid === 'string') {
                window.__correlationId = cid;
                try { localStorage.setItem('correlation_id', cid); } catch (_) { /* private mode */ }
            }
        } catch (_) {
            // Defensive — header parsing must never break the response chain.
        }
        return response;
    });
} catch (_) {
    // Axios not installed / interceptors unavailable — telemetry just falls
    // back to null correlation_id. Non-fatal.
}

// [test-e2e round-2 cluster-1 A-001/D-001 2026-05-10] Global axios response
// error interceptor — surface silent 4xx/5xx as cashier/operator-visible
// toasts. Background:
//   - A-001 (P0): POS shell silently swallowed 7+ HTTP 429 from
//     /api/admin/pos/walk-in-customer, /api/admin/users/address/*,
//     /api/admin/pos/parked-orders, /api/admin/pos/counter-collect/pending,
//     /api/admin/oss-order. Cashier had no signal at all.
//   - D-001 (P0): POS double-tap on pay produced POST /api/admin/pos 429
//     with no DOM alert; pay modal stayed open silently.
// We intentionally do NOT touch 401 (handled by app.js / pos-app.js with
// router redirect to /login — already gives strong visible feedback) and
// do NOT touch 422 (form validations render inline error states).
// We toast statuses {408, 425, 429, 502, 503, 504} only.
//
// Debounce by status bucket (429, 5xx, other) with a 3s minimum gap, because
// the A-001 evidence captured 7 concurrent 429s within 800ms on cold-boot;
// a per-error toast would replace silence with a wall of red. Bucketing by
// family — not URL — also de-spams subsequent bursts of the same kind.
//
// `useToast` is loaded lazily inside the callback because vue-toastification
// is registered later in app.js / pos-app.js (`app.use(Toast, ...)`). At the
// time an HTTP error fires, the app is already mounted.
try {
    let _toastInstance = null;
    const _lastToastAt = { rl: 0, srv: 0, oth: 0 };
    const _BUCKET_MIN_GAP_MS = 3000;

    function _resolveToast() {
        if (_toastInstance) return _toastInstance;
        try {
            // useToast() returns the global plugin singleton once
            // `app.use(Toast, options)` has run (in app.js / pos-app.js).
            // Calling it before that returns a no-op proxy — safe.
            if (typeof _useToast === 'function') {
                _toastInstance = _useToast();
                return _toastInstance;
            }
        } catch (_) { /* fallback to console */ }
        return null;
    }

    function _i18nMessage(key, fallback) {
        try {
            const i18n = window.__appI18n
                || window.app?.__VUE_DEVTOOLS_APP_RECORD__?.app?.config?.globalProperties?.$i18n;
            const t = i18n?.global?.t || i18n?.t;
            if (typeof t === 'function') {
                const out = t(key);
                if (out && out !== key) return out;
            }
        } catch (_) { /* noop */ }
        return fallback;
    }

    // Suppress toast for these path patterns (auth flows + form validations).
    const _ALLOWLIST_PATTERNS = [
        '/api/auth/logout',
        '/api/auth/login',
        '/login',
        '/logout',
        '/csp-report',
        '/api/broadcasting/auth',
    ];

    function _shouldAllowlist(error) {
        const url = String(error?.config?.url || '');
        return _ALLOWLIST_PATTERNS.some((p) => url.includes(p));
    }

    window.axios.interceptors.response.use(
        (response) => response,
        (error) => {
            try {
                if (axios.isCancel?.(error)
                    || error?.code === 'ERR_CANCELED'
                    || error?.message === 'canceled') {
                    return Promise.reject(error);
                }

                const status = Number(error?.response?.status || 0);
                // 401 is handled by surface-specific handlers (app.js / pos-app.js
                // — router push to /login). 422 is form-level validation. 304 / 204
                // are not errors.
                const TOAST_STATUSES = new Set([408, 425, 429, 502, 503, 504]);
                if (!TOAST_STATUSES.has(status)) {
                    return Promise.reject(error);
                }

                if (_shouldAllowlist(error)) {
                    return Promise.reject(error);
                }

                const now = Date.now();
                let bucket = 'oth';
                let message;
                if (status === 429) {
                    bucket = 'rl';
                    message = _i18nMessage(
                        'error.rate_limited',
                        'Trop de requêtes — patientez 30s avant de réessayer.'
                    );
                } else if (status >= 500) {
                    bucket = 'srv';
                    message = _i18nMessage(
                        'error.service_unavailable',
                        'Service indisponible — réessayez dans quelques instants.'
                    );
                } else {
                    message = _i18nMessage(
                        'error.network_issue',
                        'Problème réseau — réessayez.'
                    );
                }

                if (now - (_lastToastAt[bucket] || 0) < _BUCKET_MIN_GAP_MS) {
                    return Promise.reject(error);
                }
                _lastToastAt[bucket] = now;

                const toast = _resolveToast();
                if (toast && typeof toast.error === 'function') {
                    toast.error(message, { timeout: 6000 });
                } else if (typeof window !== 'undefined' && window.console?.warn) {
                    // Last-resort visibility — never silent.
                    window.console.warn('[axios-error-toast]', status, message);
                }
            } catch (_) {
                // Interceptor must never break the response chain.
            }
            return Promise.reject(error);
        }
    );
} catch (_) {
    // Defensive — interceptors not available, surface silently rejected.
}

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
import { selectSurfaceBearerToken } from './shared/axios-setup';
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
            return selectSurfaceBearerToken({
                kioskToken: vuex.kioskCart?.kioskToken || null,
                userToken: vuex.auth?.authToken || null,
            }) || '';
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
    window._refreshEchoAuth = function () {
        const token = _getEchoBearerToken();
        if (window.Echo && window.Echo.connector && window.Echo.connector.pusher) {
            window.Echo.connector.options.auth.headers['Authorization'] = `Bearer ${token}`;
        }
    };

    // [F-12] Reactively detect subscription/auth errors on Echo private/presence channels.
    // When Pusher emits `pusher:subscription_error` (token expired, signature mismatch),
    // we (1) reinject the latest local token (cheap retry — handles login-token rotation),
    // (2) forward the failure to wsService which tracks a sliding-window counter and
    // promotes the connection to SESSION_INVALID after 3 failures within 60s.
    // No timer-based proactive refresh: there is no backend refresh-token endpoint.
    function _bindSubscriptionErrorHandler(channel) {
        if (!channel || channel.__hasAuthErrorBinding) return channel;
        const subscription = channel.subscription || channel;
        if (subscription && typeof subscription.bind === 'function') {
            const handler = (payload) => {
                try { window._refreshEchoAuth?.(); } catch (_) { /* ignore */ }
                try { wsService.handleSubscriptionError(payload); } catch (_) { /* ignore */ }
            };
            subscription.bind('pusher:subscription_error', handler);
            subscription.bind('subscription_error', handler);
            channel.__hasAuthErrorBinding = true;
        }
        return channel;
    }

    if (typeof window.Echo.private === 'function') {
        const _origPrivate = window.Echo.private.bind(window.Echo);
        window.Echo.private = (...args) => _bindSubscriptionErrorHandler(_origPrivate(...args));
    }
    if (typeof window.Echo.encryptedPrivate === 'function') {
        const _origEncPrivate = window.Echo.encryptedPrivate.bind(window.Echo);
        window.Echo.encryptedPrivate = (...args) => _bindSubscriptionErrorHandler(_origEncPrivate(...args));
    }
    if (typeof window.Echo.join === 'function') {
        const _origJoin = window.Echo.join.bind(window.Echo);
        window.Echo.join = (...args) => _bindSubscriptionErrorHandler(_origJoin(...args));
    }

    wsService.start();
    window._wsService = wsService;
} else {
    wsService._setState(WS_STATE.UNAVAILABLE);
    window._wsService = wsService;
    console.warn('[WS] MIX_PUSHER_APP_KEY not set in .env — WebSocket disabled, polling-only mode.');
}
