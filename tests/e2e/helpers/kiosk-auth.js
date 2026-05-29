/**
 * Kiosk-machine API auth helper — Supervisor Wave C / Z4 LATENCY infra.
 *
 * Distinct from the existing browser-bound `kiosk-order.js` (which drives
 * window.axios inside page.evaluate). This helper uses Playwright's
 * APIRequest context so latency / stress / concurrency specs can issue
 * kiosk-scoped Sanctum tokens without spawning a Chromium instance per
 * scenario — critical to measure backend-only latency cleanly.
 *
 * Endpoint contract (verified empirically 2026-05-28):
 *   POST /api/auth/kiosk-login                        (routes/api.php:166)
 *     Headers: X-API-KEY: <MIX_API_KEY>, Content-Type: application/json
 *     Body:    { "username": "kiosk-lecayenne", "password": "kiosk123" }
 *     Success: HTTP 201
 *       { message, token: "<id>|<plain>",
 *         kiosk: { id, user_id, branch_id, machine_id, username,
 *                  user_name, branch_name, is_login, status } }
 *     Throttle: throttle:kiosk-login (30/min by IP) — clear via
 *               helpers/rate-limit.js#clearFoodKingRateLimits() if needed.
 *
 * Token abilities: `kiosk:order` only (verified via OrderRequest::authorize
 * + iter15-P0-08 comment in routes/api.php). DO NOT attempt admin scopes.
 *
 * Env vars consumed:
 *   - KIOSK_MACHINE_USERNAME (default `kiosk-lecayenne`)
 *   - KIOSK_MACHINE_PASSWORD (default `kiosk123`)
 *   - KIOSK_E2E_USERNAME / KIOSK_E2E_PASSWORD also accepted for parity with
 *     the legacy `kiosk-order.js` helper (same precedence as that file).
 */

const { request } = require('@playwright/test');
const {
  resolveApiKey,
  DEFAULT_BASE_URL,
  createApiContext,
} = require('./admin-auth');

const DEFAULT_KIOSK_USERNAME =
  process.env.KIOSK_MACHINE_USERNAME || process.env.KIOSK_E2E_USERNAME || 'kiosk-lecayenne';
const DEFAULT_KIOSK_PASSWORD =
  process.env.KIOSK_MACHINE_PASSWORD || process.env.KIOSK_E2E_PASSWORD || 'kiosk123';

/**
 * Authenticate as a kiosk machine and return a Sanctum token (`kiosk:order`
 * ability) plus an APIRequest context pre-wired with X-API-KEY + Bearer.
 *
 * @param {object} [opts]
 * @param {string} [opts.username] default `kiosk-lecayenne`
 * @param {string} [opts.password] default `kiosk123`
 * @param {string} [opts.baseURL] default `http://127.0.0.1:8000`
 * @returns {Promise<{
 *   token: string,
 *   kiosk: {
 *     id: number, user_id: number, branch_id: number,
 *     machine_id: string, username: string, status: number,
 *   },
 *   apiContext: import('@playwright/test').APIRequestContext,
 * }>}
 * @throws {Error} on non-201, throttle 429, or missing token in response
 */
async function loginKiosk({
  username = DEFAULT_KIOSK_USERNAME,
  password = DEFAULT_KIOSK_PASSWORD,
  baseURL = DEFAULT_BASE_URL,
} = {}) {
  const loginContext = await request.newContext({
    baseURL,
    extraHTTPHeaders: {
      'X-API-KEY': resolveApiKey(),
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
  });

  let response;
  try {
    response = await loginContext.post('/api/auth/kiosk-login', {
      data: { username, password },
    });
  } catch (err) {
    await loginContext.dispose();
    throw new Error(`Kiosk login request failed before reaching server: ${err?.message || err}`);
  }

  const status = response.status();
  if (status === 429) {
    const retryAfter = response.headers()['retry-after'] || 'unknown';
    await loginContext.dispose();
    throw new Error(
      `Kiosk login throttled (HTTP 429, Retry-After=${retryAfter}). ` +
        'Call clearFoodKingRateLimits() from helpers/rate-limit.js before retrying.',
    );
  }
  if (status !== 201) {
    const bodyText = await response.text().catch(() => '');
    await loginContext.dispose();
    throw new Error(
      `Kiosk login HTTP ${status}: ${bodyText.slice(0, 400)} ` +
        `(username=${username}, baseURL=${baseURL})`,
    );
  }

  const body = await response.json();
  if (!body || typeof body.token !== 'string' || body.token.length === 0) {
    await loginContext.dispose();
    throw new Error(
      `Kiosk login succeeded but token missing in body: ${JSON.stringify(body).slice(0, 400)}`,
    );
  }
  if (!body.kiosk || typeof body.kiosk.branch_id !== 'number') {
    await loginContext.dispose();
    throw new Error(
      `Kiosk login response shape unexpected (no kiosk.branch_id): ${JSON.stringify(body).slice(0, 400)}`,
    );
  }

  await loginContext.dispose();
  const apiContext = await createApiContext({ baseURL, bearerToken: body.token });

  return {
    token: body.token,
    kiosk: body.kiosk,
    apiContext,
  };
}

module.exports = {
  loginKiosk,
  DEFAULT_KIOSK_USERNAME,
};
