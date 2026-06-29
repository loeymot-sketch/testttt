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

// [GOAL-F2-HEAL-01 2026-05-23] Global axios timeout 30s. Without this,
// browser default (~5min OS-level TCP keepalive) made stalled FPM responses
// appear to "hang" to the cashier with no escape hatch besides page reload
// (which orphans the in-flight promise). 30s is generous for fiscal_sequence
// allocation under contention (Cache::lock TTL 5s + DB FOR UPDATE) while
// still surfacing pathological hangs to the operator within useful time.
// Per-call overrides still possible via { timeout: N } in axios.post(...).
window.axios.defaults.timeout = 30000;

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
    const _lastToastAt = { rl: 0, srv: 0, oth: 0, crit: 0 };
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

    function _i18nMessage(key, fallback, params) {
        try {
            const i18n = window.__appI18n
                || window.app?.__VUE_DEVTOOLS_APP_RECORD__?.app?.config?.globalProperties?.$i18n;
            const t = i18n?.global?.t || i18n?.t;
            if (typeof t === 'function') {
                const out = params ? t(key, params) : t(key);
                if (out && out !== key) return out;
            }
        } catch (_) { /* noop */ }
        // [Wave Y RATE-LIMIT 2026-05-21] Interpolate `{placeholder}` in fallback so
        // i18n-less paths still surface the dynamic value (e.g. real Retry-After
        // seconds) instead of the literal placeholder.
        if (params && typeof fallback === 'string') {
            return fallback.replace(/\{(\w+)\}/g, (m, k) => (k in params ? String(params[k]) : m));
        }
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

    // [Wave T R3 F1 — silent 422 visibility for critical paths] Paths where a
    // 4xx SHOULD be visible to the operator because the failure is not handled
    // by a local form error state. POS catalog (/api/admin/item) is the SSOT
    // for tile clickability — a silent 422 ships kiosk-only or stale tiles.
    // Fiscal endpoints (/api/admin/fiscal/*) are NF525 chain-critical — any
    // 4xx must surface so the cashier escalates instead of silently drifting.
    // We intentionally toast 422 here ONLY for these patterns (matching the
    // mission STEP 3 ask). All other 422 stay silent (form-level validation
    // is handled inline by the FormRequest field-error rendering).
    const _CRITICAL_4XX_PATTERNS = [
        '/api/admin/item',
        '/api/admin/fiscal/',
    ];
    function _isCriticalPath(error) {
        const url = String(error?.config?.url || '');
        return _CRITICAL_4XX_PATTERNS.some((p) => url.includes(p));
    }

    // [GOAL-D1 TELEMETRY 2026-05-23] Telemetry endpoints intentionally accept
    // 429 silently (the call-site already wraps in `.catch(()=>{})` and the
    // event is fire-and-forget — losing a single event is acceptable). Without
    // this allowlist, a kiosk burst of /kiosk/event POSTs surfaces a customer-
    // facing "Trop de requêtes" toast on the borne idle screen — UX regression
    // surfaced by Wave Final 2026-05-23 S1-002 P1. The allowlist suppresses
    // the toast ONLY; the rejection chain stays intact so per-call .catch()
    // still runs and metrics can drop the event.
    //
    // [GOAL-D1-FIX 2026-05-23 mega-S1 round-1] The initial allowlist matched
    // only `'/api/frontend/kiosk/event'` (the absolute path). Axios stores
    // `baseURL` separately from `config.url`, so at runtime `error.config.url`
    // is the relative form passed at the call site (`'frontend/kiosk-event'`,
    // `'/frontend/kiosk/event'`, etc.) WITHOUT the `/api` prefix. Empirical
    // 35-call burst confirmed the toast still surfaced on both hyphen and
    // slash aliases despite the source patch. The corrected list matches
    // every relative form actually emitted by kiosk call sites + a defensive
    // absolute form for any direct fetch helper that bypasses `baseURL`.
    const _TELEMETRY_ALLOWLIST_PATTERNS = [
        // Slash alias (current preferred path) — relative + leading-slash + absolute.
        'frontend/kiosk/event',
        // Hyphen alias (legacy, still active per routes/api.php) — relative + absolute.
        'frontend/kiosk-event',
        // Admin client metrics (PostHog batch sender).
        'admin/client-metrics',
        // Defensive: kiosk a11y settings + consent + offline-queue all hit the
        // hyphen variant via the same axios instance. Already covered by
        // 'frontend/kiosk-event' (includes() substring match).
    ];
    function _isTelemetryEndpoint(error) {
        const url = String(error?.config?.url || '');
        return _TELEMETRY_ALLOWLIST_PATTERNS.some((p) => url.includes(p));
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
                // [Wave T R3 F1] Critical-path 4xx (400/403/404/422) must also toast
                // so silent failures on POS catalog / fiscal endpoints surface to
                // the operator. Auth (401) is handled by router push — skip it.
                const isCriticalPathError = status !== 401
                    && status >= 400
                    && status < 500
                    && _isCriticalPath(error);
                if (!TOAST_STATUSES.has(status) && !isCriticalPathError) {
                    return Promise.reject(error);
                }

                if (_shouldAllowlist(error)) {
                    return Promise.reject(error);
                }

                const now = Date.now();
                let bucket = 'oth';
                let message;
                if (isCriticalPathError) {
                    bucket = 'crit';
                    const url = String(error?.config?.url || '');
                    if (url.includes('/api/admin/fiscal/')) {
                        message = _i18nMessage(
                            'error.fiscal_unavailable',
                            'Service fiscal indisponible — alertez le manager.'
                        );
                    } else {
                        message = _i18nMessage(
                            'error.catalog_unavailable',
                            'Catalogue produits indisponible — actualisez la page.'
                        );
                    }
                } else if (status === 429) {
                    // [GOAL-D1 2026-05-23] Suppress toast for telemetry endpoints.
                    // Rejection still propagates so per-call .catch() handler can
                    // drop the metric event without surfacing UX noise.
                    if (_isTelemetryEndpoint(error)) {
                        return Promise.reject(error);
                    }
                    bucket = 'rl';
                    // [Wave Y RATE-LIMIT 2026-05-21] Read real Retry-After header
                    // instead of hardcoding "30s" — Laravel's perMinute limiter
                    // returns up to 60s, so the prior hardcoded copy misled
                    // owners into restarting too early and re-hitting the wall.
                    const _retryAfterRaw = error?.response?.headers?.['retry-after']
                        ?? error?.response?.headers?.['Retry-After'];
                    const _retryAfter = parseInt(_retryAfterRaw, 10);
                    const _seconds = Number.isFinite(_retryAfter) && _retryAfter > 0
                        ? _retryAfter
                        : null;
                    message = _i18nMessage(
                        'error.rate_limited',
                        _seconds !== null
                            ? `Trop de requêtes — patientez ${_seconds}s avant de réessayer.`
                            : 'Trop de requêtes. Veuillez patienter quelques instants.',
                        _seconds !== null ? { seconds: _seconds } : undefined
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
    // [GOAL-2026-05-29 P-AUTH-SYNC] `explicitToken` lets the caller pass the token it
    // JUST set in the Vuex store. vuex-persistedstate writes localStorage in a
    // post-mutation `store.subscribe`, so `_getEchoBearerToken()` (which reads
    // localStorage) is STALE-BY-ONE when called synchronously inside the
    // authLogin/authTokenRefreshed mutations — it injects the PRIOR token, the very
    // next private-channel subscribe (e.g. KDS `private-branch.N` right after a chef
    // login) fails auth, and Pusher does NOT auto-retry a terminally-failed
    // subscription → the kitchen silently degrades to the 60s poll instead of
    // sub-second push (verified live 2026-05-29: `subscribed:false` after a fresh chef
    // login; forced re-subscribe with the correct token → `subscribed:true` → broadcast
    // received). Passing the fresh token makes the FIRST subscribe succeed. Callers that
    // pass nothing (the reactive subscription_error net L379; kiosk login kioskCart.js,
    // where localStorage is already written by call time) keep the localStorage path.
    window._refreshEchoAuth = function (explicitToken) {
        const token = (typeof explicitToken === 'string' && explicitToken)
            ? explicitToken
            : _getEchoBearerToken();
        if (window.Echo && window.Echo.connector && window.Echo.connector.pusher) {
            window.Echo.connector.options.auth.headers['Authorization'] = `Bearer ${token}`;
        }
    };

    // [F-12] Reactively detect subscription/auth errors on Echo private/presence channels.
    // When Pusher emits `pusher:subscription_error` (token expired, signature mismatch),
    // we (1) reinject the latest local token (cheap retry — handles login-token rotation),
    // (2) forward the failure to wsService which tracks a sliding-window counter and
    // promotes the connection to SESSION_INVALID after 3 failures within 60s.
    // [GOAL-2026-05-29 P-AUTH] Proactive refresh IS now wired (app.js/pos-app.js: a 2h
    // timer dispatches the 'refreshAuthToken' Vuex action -> POST /api/refresh-token,
    // re-issuing a fresh abilities-preserved Bearer) so always-on KDS/OSS/POS survive
    // past the 480-min TTL — both WS channel-auth and the delta-poll use this token.
    // This reactive handler remains the secondary net (re-inject local token + the
    // sliding-window SESSION_INVALID promotion). (The earlier "no refresh endpoint"
    // note was stale — RefreshTokenController has existed at routes/api.php:155.)
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

    // [WS-GRACEFUL 2026-06-29] Quand AUCUN serveur temps-réel (soketi/reverb) n'est
    // joignable — cas V1 LOCAL où le serveur WS n'est pas déployé — Pusher retente la
    // connexion en boucle et spamme la console de 401. On coupe PROPREMENT après 4
    // échecs consécutifs SANS s'être jamais connecté → le KDS/POS bascule sur son repli
    // polling (5 s), sans bruit. Si un vrai serveur répond une fois ('connected'), on ne
    // coupe JAMAIS (forward-compatible : le jour où soketi est monté, le temps réel marche
    // sans rien changer). Best-effort, ne casse jamais le boot.
    try {
        const _conn = window.Echo.connector?.pusher?.connection;
        if (_conn && typeof _conn.bind === 'function') {
            let _everConnected = false;
            let _connFails = 0;
            _conn.bind('connected', () => { _everConnected = true; _connFails = 0; });
            const _giveUpIfDead = () => {
                if (_everConnected) return; // vrai serveur présent → on garde le WS
                if (++_connFails >= 4) {
                    try { window.Echo.disconnect(); } catch (_) { /* ignore */ }
                    try { wsService._setState(WS_STATE.UNAVAILABLE); } catch (_) { /* ignore */ }
                    console.info('[WS] Aucun serveur temps-réel joignable → mode polling (normal en V1).');
                }
            };
            _conn.bind('unavailable', _giveUpIfDead);
            _conn.bind('failed', _giveUpIfDead);
            _conn.bind('error', _giveUpIfDead);
        }
    } catch (_) { /* best-effort */ }

    wsService.start();
    window._wsService = wsService;
} else {
    wsService._setState(WS_STATE.UNAVAILABLE);
    window._wsService = wsService;
    console.warn('[WS] MIX_PUSHER_APP_KEY not set in .env — WebSocket disabled, polling-only mode.');
}
