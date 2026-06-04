# WS Channel-Auth × Proactive Token-Refresh — Read-Only Security Audit

Scope: commit `3c1fa0eb7` (proactive Sanctum refresh) vs Laravel Echo / pusher-js
private-channel auth. READ-ONLY. Versions verified installed: `laravel-echo@2.3.1`,
`pusher-js@8.4.3` (package.json + dist).

Reference chain proven (so findings are not theoretical):
- Echo config passes `auth.headers.Authorization = "Bearer <token>"` — `resources/js/bootstrap.js:344-349`.
- laravel-echo spreads opts shallowly (`this.options={...defaults,...e}`) then `new window.Pusher(this.options.key,this.options)` — `node_modules/laravel-echo/dist/echo.common.js` (PusherConnector `setOptions`/`connect`). So `Echo.connector.options.auth.headers` is the SAME object reference handed to Pusher.
- pusher-js `buildChannelAuth`: `channelAuthorization.headers = opts.auth.headers` (reference, not copy) — `node_modules/pusher-js/dist/node/pusher.js:10467-10468`.
- ajax authorizer iterates `authOptions.headers` PER auth request — `pusher.js:9929-9930`.
- Therefore in-place mutation of `connector.options.auth.headers['Authorization']` (`bootstrap.js:357-359`) IS observed by the next channel-auth POST. Mutation propagation = TRUE.

The propagation is correct. The defect is the VALUE that mutation writes, and WHEN.

---

## [P2-A] bootstrap.js:355-360 + auth.js:183-195 — `_refreshEchoAuth` re-injects a STALE (just-deleted) token at the instant of refresh/login (stale-by-one)

Evidence:
- `authTokenRefreshed` mutation sets `state.authToken = token` then calls `window._refreshEchoAuth()` **synchronously, inside the mutation body** — `resources/js/store/modules/auth.js:191-195`.
- `_refreshEchoAuth()` does NOT receive the fresh token. It calls `_getEchoBearerToken()`, which reads the token ONLY from `localStorage.getItem('vuex').auth.authToken` — `resources/js/bootstrap.js:313-323, 355-359`. It never reads `store.state`.
- Persistence is written by a POST-mutation subscriber: vuex-persistedstate registers via `store.subscribe(cb)` and writes storage inside that callback (`(r.subscriber||a)(n)(function(n,i){...setState...})`, `a(r)=fn(e){return r.subscribe(e)}`) — `node_modules/vuex-persistedstate/dist/vuex-persistedstate.es.js`. Vuex subscribers fire AFTER the mutation handler returns. Store config: `resources/js/store/index.js:244-245` (`createPersistedState`, `"auth"` in `paths` L273).
- Deterministic consequence: while the mutation body is still executing, `localStorage` still holds the PREVIOUS token — the exact one `RefreshTokenController->refreshToken()` just `->delete()`'d at `app/Http/Controllers/Auth/RefreshTokenController.php:47`. So `_refreshEchoAuth` writes a **deleted** token into Echo's auth headers.
- Same defect at login: `authLogin` mutation calls argument-less `window._refreshEchoAuth()` with the identical in-mutation timing — `auth.js:183-184` — so it injects the PRIOR (pre-login) localStorage token at login too. This is almost certainly why the reactive `subscription_error` re-inject handler exists in the first place.
- Asymmetry confirming the bug: axios reads `store.state` FIRST (`readTokenFromVuexLocalStorage`, `resources/js/shared/axios-setup.js:43-61`, returns `store.state.auth.authToken` before any localStorage fallback) — so HTTP/delta-poll gets the FRESH token, while Echo gets the STALE one at the moment of injection. Same refresh, two different tokens.

Impact (corrected — bounded, self-recovering): vuex-persistedstate's `setItem` is SYNCHRONOUS and runs in the `store.subscribe` callback immediately after the mutation returns, so localStorage holds the FRESH token within the same tick — long before any reconnect. The window is therefore "stale-by-one": Echo's header always lags one refresh behind, holding the last-deleted token. On the FIRST reconnect after a refresh — soketi/Pusher restart, network blip, or this app's own circuit-breaker `pusher.connect()` at `resources/js/services/WebSocketService.js:319-340` — the re-subscribe sends the deleted token → `/api/broadcasting/auth` returns 403 (not resolvable by `auth:sanctum`, `BroadcastServiceProvider.php:22`) → `pusher:subscription_error`. BUT the reactive handler (`bootstrap.js:374-387`) then calls `_refreshEchoAuth()` again, which now reads the FRESH token from localStorage and corrects the header; the subsequent re-subscribe succeeds. Net effect: one failed re-subscribe cycle per reconnect-after-refresh, then recovery — NOT a break-until-reload. The delta-poll (fresh token from `store.state`) masks delivery throughout, so no orders are lost.

Why P2 not P1: self-recovering on the next reconnect + poll-masked delivery + the `SESSION_INVALID` promotion needs ≥3 failures within 60s, i.e. ≥3 private channels re-subscribing simultaneously on one reconnect. KDS/OSS/POS typically hold only 1-2 private channels (`branch.{id}` + the user channel), so the threshold usually will NOT trip — the symptom is transient per-reconnect auth errors, not a session kill. Still a real, deterministic defect that defeats the feature's intent and adds avoidable reconnect-auth churn.

Scope-minimal recommendation: pass the fresh token explicitly to BOTH call sites. (a) Make `_refreshEchoAuth(token)` use the passed token when present, falling back to `_getEchoBearerToken()` only when none is given (`bootstrap.js:355-360`). (b) `authTokenRefreshed` → `window._refreshEchoAuth(token)` (`auth.js:193`). (c) `authLogin` → `window._refreshEchoAuth(payload.token)` (`auth.js:183-184`). This eliminates the localStorage read-after-write race at both refresh and login. No frozen-zone file touched (bootstrap.js / auth.js are not in §7).

---

## [P2-B] RefreshTokenController.php:15-19,47 + routes/api.php:155 — an EXPIRED token can be refreshed into a fresh valid one (no expiry gate)

Evidence:
- Route middleware is `['installed', 'apiKey']` ONLY — NOT `auth:sanctum` — `routes/api.php:155`.
- Controller resolves the token via `PersonalAccessToken::findToken($sanctumToken)` — `RefreshTokenController.php:19`. `findToken` matches on the SHA-256 hash and performs NO `expires_at` / created-at window check — `vendor/laravel/sanctum/src/PersonalAccessToken.php:58-69`.
- Sanctum's expiry enforcement lives in the GUARD (`vendor/laravel/sanctum/src/Guard.php:159-160`: `created_at->gt(now()->subMinutes($expiration))` AND `!expires_at->isPast()`), which this endpoint bypasses by not using `auth:sanctum`.
- Expired rows are pruned only daily with a 24h grace: `sanctum:prune-expired --hours=24` at 04:30 — `app/Console/Kernel.php:193-199`.

Impact: A token can be refreshed up to ~(expiry + 24h) after it expires, as long as the DB row survives — a captured-but-expired Bearer + the (static, shipped) `x-api-key` yields a brand-new 480-min token. Contradicts the audit assumption that an expired token returns 401. For V1 LOCAL Le Cayenne (single box, trusted LAN) the practical risk is low, hence P2; for the V2 SaaS posture it is a real token-resurrection window.

Scope-minimal recommendation: after `findToken`, reject if `$token->expires_at && $token->expires_at->isPast()` (and optionally the created-at window vs `config('sanctum.expiration')`) → return 401 before re-issuing. One guard clause; no signature change.

---

## [P3] auth.js:86-100 — refresh is fire-and-forget; a transient failure silently leaves the OLD token until next 2h tick

Evidence: `refreshAuthToken` `.catch(() => false)` (`auth.js:100`); the timers swallow rejection too (`.catch(() => {})`, `app.js:222`, `pos-app.js:202`). On a failed refresh nothing retries before +2h.

Impact: if a refresh fails near the 480-min boundary, the token can lapse before the next attempt; WS + poll then both 401 until re-login. Low likelihood (4 attempts/TTL), hence P3.

Recommendation: on refresh failure, schedule one short-delay retry (e.g. +5 min) rather than waiting the full interval. Out of strict scope; note only.

---

## Confirmed SAFE (no finding)

- ABILITIES (Q4): `RefreshTokenController.php:42-53` preserves `$token->abilities` verbatim, coerces non-array → `[]` (least privilege), NEVER `['*']`. A kiosk token (`kiosk:order`) refreshes to `kiosk:order` only — no escalation. Channel authz further pins kiosk tokens by token NAME (`kiosk-token`), immune to `'*'` wildcard — `routes/channels.php:41-61`. Correct.
- Auth header IS sent as `Authorization: Bearer <token>` and read per-request from the shared options object — `bootstrap.js:344-349`; `pusher.js:9929-9930, 10467-10468`. Correct.
- `/api/broadcasting/auth` is Sanctum-Bearer protected (not session) — `BroadcastServiceProvider.php:22`. Correct.
