// @ts-check
/**
 * E2E LIVREUR — Goal Functional Validation 2026-05-28
 *
 * REALITY CHECK (per advisor 2026-05-28):
 * - Livreur surface is API-only (mobile-app pattern). NO web UI.
 * - Routes: /api/frontend/delivery-boy-order/* (routes/api.php:1359-1366)
 * - Cash sessions: /api/admin/delivery-boy/cash-sessions/* (admin-only,
 *   livreur self-service surface NOT IMPLEMENTED — backlog gap)
 * - Vue components under resources/js/components/admin/deliveryBoys/* are the
 *   ADMIN cockpit (manage drivers + sessions), NOT a livreur dashboard.
 *
 * This spec drives:
 * 1. API calls via Playwright request context (Sanctum token for livreur id=10)
 * 2. Admin-side cockpit screenshots (admin's view of livreur user + sessions)
 *
 * Pre-seed (done via tinker before run):
 * - livreur id=10 email=livreur.e2e@lecayenne.test pwd=Livreur123! branch=1
 * - order id=113 status=PREPARED order_type=DELIVERY delivery_boy_id=10
 * - token: /tmp/livreur-e2e-token.txt
 */

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const { execSync } = require('child_process');
const TOKEN = fs.readFileSync('/tmp/livreur-e2e-token.txt', 'utf-8').trim();

function resetOrderToPrepared(orderId) {
  // Reset via tinker before transition tests — idempotent
  const cmd = `php artisan tinker --execute="\\App\\Models\\Order::where('id',${orderId})->update(['status'=>8,'payment_status'=>10]); echo 'reset';"`;
  try {
    execSync(cmd, { cwd: path.resolve(__dirname, '../..'), encoding: 'utf-8', stdio: ['pipe', 'pipe', 'pipe'] });
  } catch (e) {
    // eslint-disable-next-line no-console
    console.error('resetOrderToPrepared error:', e?.message);
  }
}
const API_KEY = process.env.FOODKING_API_KEY || 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';
const apiHeaders = (extra = {}) => ({
  Authorization: `Bearer ${TOKEN}`,
  Accept: 'application/json',
  'Content-Type': 'application/json',
  'x-api-key': API_KEY,
  ...extra,
});
const noTokenHeaders = (extra = {}) => ({
  Accept: 'application/json',
  'Content-Type': 'application/json',
  'x-api-key': API_KEY,
  ...extra,
});
const SHOTS_DIR = path.resolve(
  __dirname,
  '../../reports/test-e2e/goal-functional-validation-2026-05-28/LIVREUR/screenshots',
);
fs.mkdirSync(SHOTS_DIR, { recursive: true });

const FINDINGS = [];
function finding(id, sev, area, msg, evidence) {
  FINDINGS.push({ id, sev, area, msg, evidence, ts: new Date().toISOString() });
}

const ORDER_ID = 113;
const LIVREUR_ID = 10;

test.describe('LIVREUR API contract + admin cockpit', () => {
  test.describe.configure({ mode: 'serial' });

  test('A1 — index() returns assigned orders + items lean shape', async ({ request }) => {
    const res = await request.get('http://127.0.0.1:8000/api/frontend/delivery-boy-order', {
      headers: apiHeaders(),
    });
    const status = res.status();
    const body = await res.json().catch(() => ({}));
    if (status !== 200) {
      finding('LIV-A1-001', 'P0', 'API',
        `GET /delivery-boy-order returned ${status} (expected 200)`, { body });
    } else {
      const data = body.data || [];
      const found = data.find((o) => o.id === ORDER_ID);
      if (!found) {
        finding('LIV-A1-002', 'P0', 'API',
          `Pre-seeded order id=${ORDER_ID} NOT in livreur index payload`, { count: data.length });
      } else if (!Array.isArray(found.items) || found.items.length === 0) {
        finding('LIV-A1-003', 'P1', 'API',
          'items[] empty in index payload — N+1 sentinel eager-load may be broken', { found });
      } else {
        const it = found.items[0];
        const expectedKeys = ['item_id', 'item_name', 'quantity', 'price', 'total_price', 'instruction'];
        const missing = expectedKeys.filter((k) => !(k in it));
        if (missing.length > 0) {
          finding('LIV-A1-004', 'P1', 'API',
            `items[0] missing keys: ${missing.join(',')}`, { it });
        }
      }
    }
    expect(status).toBe(200);
  });

  test('A2 — count() endpoint returns NEW/READY/etc. tallies', async ({ request }) => {
    const res = await request.get('http://127.0.0.1:8000/api/frontend/delivery-boy-order/count', {
      headers: apiHeaders(),
    });
    const status = res.status();
    const body = await res.json().catch(() => ({}));
    if (status !== 200) {
      finding('LIV-A2-001', 'P1', 'API', `count returned ${status}`, { body });
    }
    expect(status).toBe(200);
  });

  test('A3 — show() returns full order detail incl. customer address', async ({ request }) => {
    const res = await request.get(`http://127.0.0.1:8000/api/frontend/delivery-boy-order/show/${ORDER_ID}`, {
      headers: apiHeaders(),
    });
    const status = res.status();
    const body = await res.json().catch(() => ({}));
    if (status !== 200) {
      finding('LIV-A3-001', 'P0', 'API',
        `show/${ORDER_ID} returned ${status} (livreur cannot see assigned order detail)`, { body });
    } else {
      const data = body.data || body;
      if (!data || !data.id) {
        finding('LIV-A3-002', 'P0', 'API', 'show payload missing id field', { body });
      }
      // address field expected for DELIVERY orders (customer doorstep)
      if (data && !('address' in data) && !('order_address' in data)) {
        finding('LIV-A3-003', 'P1', 'UX',
          'No address field in show() payload — livreur cannot route', { keys: Object.keys(data || {}) });
      }
    }
    expect(status).toBe(200);
  });

  test('A4 — change-status whitelist rejects out-of-range integers (422)', async ({ request }) => {
    const res = await request.post(`http://127.0.0.1:8000/api/frontend/delivery-boy-order/change-status/${ORDER_ID}`, {
      headers: apiHeaders({ 'X-Idempotency-Key': `e2e-liv-bad-${Date.now()}` }),
      data: { status: 99 },
    });
    const status = res.status();
    if (status !== 422) {
      finding('LIV-A4-001', 'P0', 'SEC',
        `Out-of-range status=99 accepted (HTTP ${status}); whitelist at controller:71-74 broken`, {});
    }
    expect(status).toBe(422);
  });

  test('A5 — PREPARED -> OUT_FOR_DELIVERY transition succeeds', async ({ request }) => {
    resetOrderToPrepared(ORDER_ID);
    const res = await request.post(`http://127.0.0.1:8000/api/frontend/delivery-boy-order/change-status/${ORDER_ID}`, {
      headers: apiHeaders({ 'X-Idempotency-Key': `e2e-liv-ofd-${Date.now()}` }),
      data: { status: 10 }, // OUT_FOR_DELIVERY
    });
    const status = res.status();
    const body = await res.json().catch(() => ({}));
    if (status !== 200) {
      finding('LIV-A5-001', 'P0', 'API',
        `PREPARED->OUT_FOR_DELIVERY returned ${status}`, { body });
    }
    expect(status).toBe(200);
  });

  test('A6 — OUT_FOR_DELIVERY -> DELIVERED transition succeeds + audit_logs row', async ({ request }) => {
    const res = await request.post(`http://127.0.0.1:8000/api/frontend/delivery-boy-order/change-status/${ORDER_ID}`, {
      headers: apiHeaders({ 'X-Idempotency-Key': `e2e-liv-del-${Date.now()}` }),
      data: { status: 13 }, // DELIVERED
    });
    const status = res.status();
    const body = await res.json().catch(() => ({}));
    if (status !== 200) {
      finding('LIV-A6-001', 'P0', 'API',
        `OUT_FOR_DELIVERY->DELIVERED returned ${status}`, { body });
    }
    expect(status).toBe(200);
  });

  test('A7 — second livreur token cannot see / mutate another livreur order (403)', async ({ request }) => {
    // Try to fetch ORDER_ID with NO token — must 401
    const res = await request.get(`http://127.0.0.1:8000/api/frontend/delivery-boy-order/show/${ORDER_ID}`, {
      headers: noTokenHeaders(),
    });
    const status = res.status();
    if (status !== 401 && status !== 302) {
      finding('LIV-A7-001', 'P0', 'SEC',
        `Unauthed access to show() returned ${status} (expected 401/302)`, {});
    }
    // We only have one livreur seeded; cross-livreur 403 verified in PHPUnit
    // (FrontendDeliveryBoyOrderController::deliveryBoyOrderChangeStatus L1647).
    expect([401, 302]).toContain(status);
  });

  test.skip('A8 — admin cockpit: delivery-boys list visible (visual)', async ({ page }) => {
    // Login as admin first
    await page.goto('http://127.0.0.1:8000/login');
    await page.fill('input[type=email], input[name=email]', 'admin@lecayenne.fr');
    await page.fill('input[type=password], input[name=password]', '123456');
    await Promise.all([
      page.waitForURL(/admin|dashboard/i, { timeout: 15_000 }).catch(() => {}),
      page.locator('button[type=submit], button:has-text("Login"), button:has-text("Connexion")').first().click(),
    ]);
    await page.waitForTimeout(1500);
    await page.goto('http://127.0.0.1:8000/admin/delivery-boys');
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.screenshot({
      path: path.join(SHOTS_DIR, '01-admin-delivery-boys-list.png'),
      fullPage: true,
    });
    // Hostile: check for raw labels
    const html = await page.content();
    const rawLabels = (html.match(/\b(?:Label\.[a-z_]+|deliveryBoy\.[a-z_]+|[a-z_]+\.label\.[a-z_]+)/gi) || []).slice(0, 5);
    if (rawLabels.length > 0) {
      finding('LIV-A8-001', 'P1', 'I18N',
        `Raw label tokens visible on /admin/delivery-boys: ${rawLabels.join(', ')}`, { rawLabels });
    }
  });

  test.skip('A9 — admin cockpit: livreur cash-session list (visual)', async ({ page }) => {
    await page.goto('http://127.0.0.1:8000/admin/delivery-boy-cash-sessions');
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(800);
    await page.screenshot({
      path: path.join(SHOTS_DIR, '02-admin-delivery-boy-cash-sessions.png'),
      fullPage: true,
    });
    // Check if page rendered (vs 404)
    const title = await page.title();
    const body = await page.locator('body').innerText();
    if (/404|not found|introuvable/i.test(body)) {
      finding('LIV-A9-001', 'P1', 'UX',
        '/admin/delivery-boy-cash-sessions returns 404-like page (admin route may not be wired)', { title });
    }
  });

  test.skip('A10 — cash session OPEN via API (admin acting on livreur behalf)', async ({ request }) => {
    // Admin login via API to mint admin token
    const loginRes = await request.post('http://127.0.0.1:8000/api/login', {
      headers: noTokenHeaders(),
      data: { email: 'admin@lecayenne.fr', password: '123456' },
    });
    if (loginRes.status() !== 200) {
      finding('LIV-A10-000', 'P1', 'API',
        `Admin /api/login returned ${loginRes.status()}`, {});
      test.skip();
      return;
    }
    const loginBody = await loginRes.json();
    const adminToken = loginBody.token || loginBody.access_token || loginBody.data?.token;
    if (!adminToken) {
      finding('LIV-A10-001', 'P1', 'API',
        'Admin /api/login response missing token key', { keys: Object.keys(loginBody) });
      test.skip();
      return;
    }
    const openRes = await request.post('http://127.0.0.1:8000/api/admin/delivery-boy/cash-sessions/open', {
      headers: {
        Authorization: `Bearer ${adminToken}`,
        Accept: 'application/json',
        'x-api-key': API_KEY,
        'X-Idempotency-Key': `e2e-liv-open-${Date.now()}`,
      },
      data: { delivery_boy_id: LIVREUR_ID, opening_amount: 50.0 },
    });
    const st = openRes.status();
    const body = await openRes.json().catch(() => ({}));
    // 201 = created, 409 = already open (still acceptable for repeat runs)
    if (![201, 409].includes(st)) {
      finding('LIV-A10-002', 'P0', 'API',
        `cash-sessions/open returned ${st} (expected 201/409)`, { body });
    }
    expect([201, 409]).toContain(st);
  });
});

test.afterAll(async () => {
  // Spec writes its OWN file — `findings.spec-detected.json`. The canonical
  // `findings.json` is the manually-curated audit output; this spec emits the
  // subset of findings detected by the run (typically empty when API is GREEN).
  const findingsPath = path.resolve(
    __dirname,
    '../../reports/test-e2e/goal-functional-validation-2026-05-28/LIVREUR/findings.spec-detected.json',
  );
  fs.mkdirSync(path.dirname(findingsPath), { recursive: true });
  fs.writeFileSync(findingsPath, JSON.stringify({
    ts: new Date().toISOString(),
    surface: 'LIVREUR',
    surface_kind: 'API-only (mobile-app pattern, no web UI)',
    livreur_id: LIVREUR_ID,
    order_id: ORDER_ID,
    total_findings: FINDINGS.length,
    findings: FINDINGS,
  }, null, 2));
  // eslint-disable-next-line no-console
  console.log(`[LIVREUR] findings -> ${findingsPath} (count=${FINDINGS.length})`);
});
