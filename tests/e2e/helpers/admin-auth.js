/**
 * Admin API auth helper — Supervisor Wave C / Z4 LATENCY infra.
 *
 * Wave B latency agent failed because the Node-side login attempt missed
 * the X-API-KEY header (ApiKeyMiddleware → 400 invalid_api_key) and the
 * in-browser kiosk login was racy (token revocation mid-flow on retry).
 *
 * This helper provides a stable, browser-less admin Sanctum token issuance
 * using Playwright's APIRequest context, so latency/stress specs can drive
 * the API directly without spawning a full browser per scenario.
 *
 * Endpoint contract (verified empirically 2026-05-28):
 *   POST /api/auth/login                              (routes/api.php:159)
 *     Headers: X-API-KEY: <MIX_API_KEY>, Content-Type: application/json
 *     Body:    { "email": "...", "password": "..." }
 *     Success: HTTP 201
 *       { message, token: "<id>|<plain>", branch_id, user: {...},
 *         menu: [...], permission: [...], defaultPermission, defaultMenu }
 *     Failure: HTTP 400 invalid_api_key | HTTP 422 validation | HTTP 401 creds
 *
 * Notes for next agents:
 *   - MIX_API_KEY is read from process.env first; falls back to direct
 *     parsing of the project .env file (zero dependency on dotenv).
 *   - The returned `token` is a plain Sanctum bearer (no `Bearer ` prefix);
 *     callers add the prefix themselves when injecting Authorization.
 *   - `apiContext.dispose()` is the caller's responsibility — the returned
 *     context is reusable across many calls in a single test run.
 *   - DO NOT log the token to stdout/stderr in CI — it grants admin scope.
 */

const fs = require('fs');
const path = require('path');
const { request } = require('@playwright/test');

const REPO_ROOT = path.resolve(__dirname, '../../..');
const ENV_PATH = path.join(REPO_ROOT, '.env');

let cachedApiKey = null;

/**
 * Read MIX_API_KEY with precedence:
 *   1. process.env.MIX_API_KEY (export ahead of `npx playwright test`)
 *   2. parse REPO_ROOT/.env for `MIX_API_KEY=...`
 * Result is memoized for the life of the Node process.
 *
 * @returns {string} the raw API key
 * @throws {Error} when neither source provides a value
 */
function resolveApiKey() {
  if (cachedApiKey) return cachedApiKey;
  if (process.env.MIX_API_KEY && process.env.MIX_API_KEY.trim().length > 0) {
    cachedApiKey = process.env.MIX_API_KEY.trim();
    return cachedApiKey;
  }
  try {
    const contents = fs.readFileSync(ENV_PATH, 'utf8');
    const match = contents.match(/^MIX_API_KEY\s*=\s*(.+)$/m);
    if (match && match[1]) {
      cachedApiKey = match[1].trim().replace(/^"(.*)"$/, '$1');
      return cachedApiKey;
    }
  } catch (err) {
    // fall through to throw below
  }
  throw new Error(
    'MIX_API_KEY missing: export it before running the spec, or ensure it is set in .env',
  );
}

const DEFAULT_BASE_URL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';
const DEFAULT_ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const DEFAULT_ADMIN_PASS = process.env.E2E_ADMIN_PASS || '123456';

/**
 * Build a Playwright APIRequest context pre-loaded with the FoodKing API
 * headers required for every authenticated call (X-API-KEY + JSON Accept).
 * No login is performed — callers use this to issue further calls (e.g.
 * order list) without re-stamping headers each time.
 *
 * @param {object} [opts]
 * @param {string} [opts.baseURL] overrides DEFAULT_BASE_URL
 * @param {string} [opts.bearerToken] optional admin token; sets Authorization
 * @returns {Promise<import('@playwright/test').APIRequestContext>}
 */
async function createApiContext({ baseURL = DEFAULT_BASE_URL, bearerToken = null } = {}) {
  const headers = {
    'X-API-KEY': resolveApiKey(),
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };
  if (bearerToken) headers.Authorization = `Bearer ${bearerToken}`;
  return request.newContext({
    baseURL,
    extraHTTPHeaders: headers,
  });
}

/**
 * Authenticate as an admin user and return a usable token + APIRequest
 * context already wired with X-API-KEY + Authorization for follow-up calls.
 *
 * @param {object} [opts]
 * @param {string} [opts.email] default `admin@lecayenne.fr`
 * @param {string} [opts.password] default `123456`
 * @param {string} [opts.baseURL] default `http://127.0.0.1:8000`
 * @returns {Promise<{
 *   token: string,
 *   user: object,
 *   branchId: number,
 *   apiContext: import('@playwright/test').APIRequestContext,
 * }>}
 * @throws {Error} on non-201 or missing token in response
 */
async function loginAdmin({
  email = DEFAULT_ADMIN_EMAIL,
  password = DEFAULT_ADMIN_PASS,
  baseURL = DEFAULT_BASE_URL,
} = {}) {
  const loginContext = await createApiContext({ baseURL });
  let response;
  try {
    response = await loginContext.post('/api/auth/login', {
      data: { email, password },
    });
  } catch (err) {
    await loginContext.dispose();
    throw new Error(`Login request failed before reaching server: ${err?.message || err}`);
  }

  const status = response.status();
  if (status !== 201) {
    const bodyText = await response.text().catch(() => '');
    await loginContext.dispose();
    throw new Error(
      `Login HTTP ${status}: ${bodyText.slice(0, 400)} (baseURL=${baseURL}, email=${email})`,
    );
  }

  const body = await response.json();
  // Verified empirically: { token, user, branch_id, ... } at ROOT (no nested .data).
  if (!body || typeof body.token !== 'string' || body.token.length === 0) {
    await loginContext.dispose();
    throw new Error(`Login succeeded but token missing in body: ${JSON.stringify(body).slice(0, 400)}`);
  }

  await loginContext.dispose();
  const apiContext = await createApiContext({ baseURL, bearerToken: body.token });

  return {
    token: body.token,
    user: body.user || null,
    branchId: typeof body.branch_id === 'number' ? body.branch_id : null,
    apiContext,
  };
}

module.exports = {
  loginAdmin,
  createApiContext,
  resolveApiKey,
  DEFAULT_BASE_URL,
  DEFAULT_ADMIN_EMAIL,
};
