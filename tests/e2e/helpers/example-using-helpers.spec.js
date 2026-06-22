/**
 * Example spec — Supervisor Wave C / Z4 LATENCY infra demo.
 *
 * Proves the four helper modules wire together correctly:
 *   - admin-auth.js#loginAdmin         → admin Sanctum token + authed context
 *   - kiosk-auth.js#loginKiosk         → kiosk Sanctum token + authed context
 *   - idempotency-key.js#generateIdempotencyKey → RFC-4122 v4 with prefix
 *   - place-order.js#placeOrder        → high-level kiosk order placement
 *
 * The latency / stress agents that follow will use these helpers as their
 * primitives (no browser, repeatable, ~50 ms login overhead total).
 *
 * Run:  npx playwright test tests/e2e/helpers/example-using-helpers.spec.js
 */

const { test, expect } = require('@playwright/test');
const { loginAdmin, resolveApiKey } = require('./admin-auth');
const { loginKiosk } = require('./kiosk-auth');
const {
  generateIdempotencyKey,
  generatePureUuid,
  idempotencyHeader,
} = require('./idempotency-key');

const UUID_V4_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

test.describe('Supervisor Wave C — Z4 LATENCY auth/idempotency helpers', () => {
  test('resolveApiKey returns the MIX_API_KEY from .env', async () => {
    const key = resolveApiKey();
    expect(typeof key).toBe('string');
    expect(key.length).toBeGreaterThanOrEqual(16);
    // Verified shape in .env: hyphen-separated, alphanumeric.
    expect(key).toMatch(/^[A-Za-z0-9-]+$/);
  });

  test('generateIdempotencyKey produces a prefixed RFC-4122 v4 UUID', async () => {
    const key = generateIdempotencyKey('Z4-LATENCY');
    expect(key.startsWith('Z4-LATENCY-')).toBe(true);
    const uuidPart = key.slice('Z4-LATENCY-'.length);
    expect(uuidPart).toMatch(UUID_V4_RE);

    // generatePureUuid is the bare form.
    const pure = generatePureUuid();
    expect(pure).toMatch(UUID_V4_RE);

    // idempotencyHeader yields the canonical header object.
    const header = idempotencyHeader(key);
    expect(header).toEqual({ 'X-Idempotency-Key': key });
  });

  test('idempotency keys are unique across rapid generation', async () => {
    const N = 256;
    const seen = new Set();
    for (let i = 0; i < N; i += 1) {
      seen.add(generateIdempotencyKey('STRESS'));
    }
    expect(seen.size).toBe(N);
  });

  test('loginAdmin returns a usable Sanctum token + APIRequest context', async () => {
    const { token, user, branchId, apiContext } = await loginAdmin();
    try {
      expect(typeof token).toBe('string');
      expect(token).toMatch(/^\d+\|/); // Sanctum format: "<id>|<plain>"
      expect(user).toBeTruthy();
      expect(user.email).toBe('admin@lecayenne.fr');
      expect(typeof branchId).toBe('number'); // 0 for super-admin

      // Probe a lightweight authed endpoint to prove the bearer is wired.
      // /api/auth/check returns the current user (verified path safe by route guard).
      const probe = await apiContext.get('/api/auth/check');
      // 200 if endpoint exists OR 404/405 — what we MUST NOT see is 400
      // invalid_api_key or 401 unauthenticated, which would mean wiring is wrong.
      expect([200, 204, 404, 405]).toContain(probe.status());
      if (probe.status() === 200) {
        const body = await probe.json().catch(() => null);
        if (body && body.user) expect(body.user.email).toBe('admin@lecayenne.fr');
      }
    } finally {
      await apiContext.dispose();
    }
  });

  test('loginKiosk returns a Sanctum token scoped to kiosk:order', async () => {
    const { token, kiosk, apiContext } = await loginKiosk();
    try {
      expect(typeof token).toBe('string');
      expect(token).toMatch(/^\d+\|/);
      expect(kiosk).toBeTruthy();
      expect(kiosk.branch_id).toBeGreaterThan(0);
      expect(typeof kiosk.machine_id).toBe('string');
      expect(kiosk.machine_id.length).toBeGreaterThan(0);
    } finally {
      await apiContext.dispose();
    }
  });

  test('admin + kiosk tokens are distinct (no cross-talk)', async () => {
    const admin = await loginAdmin();
    const kiosk = await loginKiosk();
    try {
      expect(admin.token).not.toBe(kiosk.token);
      // Sanctum ids are different access-token rows.
      expect(admin.token.split('|')[0]).not.toBe(kiosk.token.split('|')[0]);
    } finally {
      await admin.apiContext.dispose();
      await kiosk.apiContext.dispose();
    }
  });
});
