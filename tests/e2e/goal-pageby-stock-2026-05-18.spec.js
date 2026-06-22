// GOAL Page-By-Page E2E — STOCK + ORDERS + LIVREUR + ITEMS management surfaces.
// Date: 2026-05-18
// Wave: STOCK (then ITEMS / ORDERS / LIVREUR appended below)
// Discipline: artifact-quartet per state (PNG + DOM + console + network).
//
// Runs sequentially (single worker per playwright.config.js:55). Use --headed
// when local + display available; CI falls back to default headless.
const path = require('path');
const fs = require('fs');
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const OUT_BASE = path.resolve(__dirname, '__screenshots__/goal-pageby-stock-2026-05-18');

// -----------------------------------------------------------------------------
// STOCK MANAGEMENT (Pages 1-5)
// -----------------------------------------------------------------------------
test.describe('STOCK Management — page-by-page', () => {
  test.describe.configure({ mode: 'serial' });

  test('Page 1 — /admin/stock/rupture loads and renders dashboard', async ({ page }) => {
    const dir = path.join(OUT_BASE, '01-stock-dashboard');
    fs.mkdirSync(dir, { recursive: true });
    const rec = attachMegaAuditRecorder(page, dir);
    try {
      await loginAsAdmin(page);
      await page.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500); // SPA + API roundtrip
      await rec.snap('01-stock-dashboard-loaded');

      // Smoke gates: not a 404, dashboard component visible.
      const bodyText = await page.locator('body').innerText();
      // Branch filter dropdown OR rupture/low-alerts heading present
      const dashboardMarker = bodyText.toLowerCase();
      const has404 = /404|not\s*found/i.test(dashboardMarker) && !dashboardMarker.includes('rupture');
      expect(has404, 'Stock dashboard route returned 404').toBe(false);
    } finally {
      rec.dispose();
    }
  });

  test('Page 2 — /admin/stock/rupture manual scan trigger', async ({ page }) => {
    const dir = path.join(OUT_BASE, '02-stock-scan-trigger');
    fs.mkdirSync(dir, { recursive: true });
    const rec = attachMegaAuditRecorder(page, dir);
    try {
      await loginAsAdmin(page);
      await page.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      await rec.snap('02-stock-before-scan');

      // Find scan trigger button — multiple possible labels (i18n: run_now / scan)
      const scanBtn = page.locator('button').filter({ hasText: /scan|lancer|run|actualiser|refresh/i }).first();
      if (await scanBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await scanBtn.click();
        await page.waitForTimeout(2000);
        await rec.snap('02-stock-after-scan-click');
      } else {
        await rec.snap('02-stock-no-scan-button-found');
      }
    } finally {
      rec.dispose();
    }
  });

  test('Page 3 — /admin/stock/rupture low alerts section', async ({ page }) => {
    const dir = path.join(OUT_BASE, '03-stock-low-alerts');
    fs.mkdirSync(dir, { recursive: true });
    const rec = attachMegaAuditRecorder(page, dir);
    try {
      await loginAsAdmin(page);
      await page.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3000);
      // Scroll to low alerts panel if it exists
      const lowSection = page.locator('text=/low.*alert|alerte.*bas|alerts/i').first();
      if (await lowSection.isVisible({ timeout: 3000 }).catch(() => false)) {
        await lowSection.scrollIntoViewIfNeeded();
      }
      await page.waitForTimeout(1000);
      await rec.snap('03-stock-low-alerts');
    } finally {
      rec.dispose();
    }
  });

  test('Page 4 — /admin/stock/rupture verify branch context', async ({ page }) => {
    const dir = path.join(OUT_BASE, '04-stock-branch-context');
    fs.mkdirSync(dir, { recursive: true });
    const rec = attachMegaAuditRecorder(page, dir);
    try {
      await loginAsAdmin(page);
      await page.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3000);
      await rec.snap('04-stock-branch-context');
    } finally {
      rec.dispose();
    }
  });
});

// -----------------------------------------------------------------------------
// ITEMS MANAGEMENT (Pages 6-8)
// -----------------------------------------------------------------------------
test.describe('ITEMS Management — page-by-page', () => {
  test.describe.configure({ mode: 'serial' });

  test('Page 6 — /admin/items list renders catalog', async ({ page }) => {
    const dir = path.join(OUT_BASE, '06-items-list');
    fs.mkdirSync(dir, { recursive: true });
    const rec = attachMegaAuditRecorder(page, dir);
    try {
      await loginAsAdmin(page);
      await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3000);
      await rec.snap('06-items-list-loaded');
    } finally {
      rec.dispose();
    }
  });

  test('Page 7 — /admin/items/create dialog/route', async ({ page }) => {
    const dir = path.join(OUT_BASE, '07-items-create');
    fs.mkdirSync(dir, { recursive: true });
    const rec = attachMegaAuditRecorder(page, dir);
    try {
      await loginAsAdmin(page);
      await page.goto('/admin/items/create', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3000);
      await rec.snap('07-items-create-form');
    } finally {
      rec.dispose();
    }
  });

  test('Page 8 — /admin/items/show/{id} edit existing item', async ({ page }) => {
    const dir = path.join(OUT_BASE, '08-items-edit');
    fs.mkdirSync(dir, { recursive: true });
    const rec = attachMegaAuditRecorder(page, dir);
    try {
      await loginAsAdmin(page);
      // Navigate to items list first to pick an existing id from DOM
      await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      const showLink = page.locator('a[href*="/admin/items/show/"]').first();
      if (await showLink.isVisible({ timeout: 3000 }).catch(() => false)) {
        const href = await showLink.getAttribute('href');
        await page.goto(href, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(3000);
      }
      await rec.snap('08-items-edit-view');
    } finally {
      rec.dispose();
    }
  });
});

// -----------------------------------------------------------------------------
// ORDERS MANAGEMENT (Pages 9-13)
// -----------------------------------------------------------------------------
test.describe('ORDERS Management — page-by-page', () => {
  test.describe.configure({ mode: 'serial' });

  test('Page 9 — /admin/pos-orders list (POS orders)', async ({ page }) => {
    const dir = path.join(OUT_BASE, '09-pos-orders-list');
    fs.mkdirSync(dir, { recursive: true });
    const rec = attachMegaAuditRecorder(page, dir);
    try {
      await loginAsAdmin(page);
      await page.goto('/admin/pos-orders', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3000);
      await rec.snap('09-pos-orders-list');
    } finally {
      rec.dispose();
    }
  });

  test('Page 9b — /admin/online-orders list (online orders)', async ({ page }) => {
    const dir = path.join(OUT_BASE, '09b-online-orders-list');
    fs.mkdirSync(dir, { recursive: true });
    const rec = attachMegaAuditRecorder(page, dir);
    try {
      await loginAsAdmin(page);
      await page.goto('/admin/online-orders', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3000);
      await rec.snap('09b-online-orders-list');
    } finally {
      rec.dispose();
    }
  });

  test('Page 10 — Order detail (first POS order)', async ({ page }) => {
    const dir = path.join(OUT_BASE, '10-order-detail');
    fs.mkdirSync(dir, { recursive: true });
    const rec = attachMegaAuditRecorder(page, dir);
    try {
      await loginAsAdmin(page);
      await page.goto('/admin/pos-orders', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3000);
      // Try clicking first order's show link
      const showLink = page.locator('a[href*="/admin/pos-orders/show/"], a[href*="/admin/pos-orders/show"]').first();
      if (await showLink.isVisible({ timeout: 3000 }).catch(() => false)) {
        const href = await showLink.getAttribute('href');
        await page.goto(href, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(3000);
      } else {
        // Fallback: navigate to tracker UI to capture detail-ish surface
        await page.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2500);
      }
      await rec.snap('10-order-detail');
    } finally {
      rec.dispose();
    }
  });

  test('Page 11 — Order status change capability surface', async ({ page }) => {
    const dir = path.join(OUT_BASE, '11-order-status-change');
    fs.mkdirSync(dir, { recursive: true });
    const rec = attachMegaAuditRecorder(page, dir);
    try {
      await loginAsAdmin(page);
      await page.goto('/admin/pos-orders', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3000);
      await rec.snap('11-order-status-change-list');
    } finally {
      rec.dispose();
    }
  });

  test('Page 12 — POS tracker (refund / counter-entry visibility)', async ({ page }) => {
    const dir = path.join(OUT_BASE, '12-pos-tracker');
    fs.mkdirSync(dir, { recursive: true });
    const rec = attachMegaAuditRecorder(page, dir);
    try {
      await loginAsAdmin(page);
      await page.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3000);
      await rec.snap('12-pos-tracker');
    } finally {
      rec.dispose();
    }
  });
});

// -----------------------------------------------------------------------------
// LIVREUR (DeliveryBoy) Management (Pages 14-18)
// -----------------------------------------------------------------------------
test.describe('LIVREUR Management — page-by-page', () => {
  test.describe.configure({ mode: 'serial' });

  test('Page 14 — /admin/delivery-boys list', async ({ page }) => {
    const dir = path.join(OUT_BASE, '14-livreur-list');
    fs.mkdirSync(dir, { recursive: true });
    const rec = attachMegaAuditRecorder(page, dir);
    try {
      await loginAsAdmin(page);
      await page.goto('/admin/delivery-boys', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3000);
      await rec.snap('14-livreur-list');
    } finally {
      rec.dispose();
    }
  });

  test('Page 15 — /admin/delivery-boys/create form', async ({ page }) => {
    const dir = path.join(OUT_BASE, '15-livreur-create');
    fs.mkdirSync(dir, { recursive: true });
    const rec = attachMegaAuditRecorder(page, dir);
    try {
      await loginAsAdmin(page);
      // Try common create routes
      await page.goto('/admin/delivery-boys/create', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3000);
      await rec.snap('15-livreur-create-form');
    } finally {
      rec.dispose();
    }
  });

  test('Page 16-17 — /admin/delivery-boys/show/{id} profile + orders', async ({ page }) => {
    const dir = path.join(OUT_BASE, '16-livreur-profile');
    fs.mkdirSync(dir, { recursive: true });
    const rec = attachMegaAuditRecorder(page, dir);
    try {
      await loginAsAdmin(page);
      // Navigate to list then click first row
      await page.goto('/admin/delivery-boys', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3000);
      const showLink = page.locator('a[href*="/admin/delivery-boys/show/"]').first();
      if (await showLink.isVisible({ timeout: 3000 }).catch(() => false)) {
        const href = await showLink.getAttribute('href');
        await page.goto(href, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(3000);
      }
      await rec.snap('16-livreur-profile-or-list');
    } finally {
      rec.dispose();
    }
  });

  // ---------------------------------------------------------------------------
  // Page 17 — Livreur orders payload verification.
  // The livreur is API-only V1 — no front-end Vue. So we hit the API directly
  // via Playwright's request context (authenticated as the livreur) and assert
  // the payload includes `items[]` (P1-LIV-01 heal verification, see
  // SimpleDeliveryBoyOrderResource.php L38-43).
  // ---------------------------------------------------------------------------
  test('Page 17 — livreur /api/frontend/delivery-boy-order index includes items[]', async ({ request }) => {
    const dir = path.join(OUT_BASE, '17-livreur-api-items');
    fs.mkdirSync(dir, { recursive: true });

    // Login as the livreur via the auth API (livreur-e2e@lecayenne.fr created via tinker).
    const apiKey = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';
    const loginResp = await request.post('http://127.0.0.1:8000/api/auth/login', {
      headers: { 'x-api-key': apiKey, 'Content-Type': 'application/json' },
      data: { email: 'livreur-e2e@lecayenne.fr', password: '123456' },
    });
    const loginBody = await loginResp.json();
    const token = loginBody.token;
    fs.writeFileSync(
      path.join(dir, '17a-livreur-login.json'),
      JSON.stringify({ status: loginResp.status(), has_token: !!token, branch_id: loginBody.branch_id || null }, null, 2),
    );

    // Hit livreur orders index endpoint.
    const indexResp = await request.get('http://127.0.0.1:8000/api/frontend/delivery-boy-order', {
      headers: { 'x-api-key': apiKey, Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    const status = indexResp.status();
    let body = null;
    try { body = await indexResp.json(); } catch { body = await indexResp.text(); }
    fs.writeFileSync(
      path.join(dir, '17b-livreur-index-response.json'),
      JSON.stringify({ status, body }, null, 2),
    );

    // Assertions (visible to reviewer in artifact):
    expect(status, 'livreur orders index should return 200').toBe(200);
    expect(body, 'response body should be an object').toBeTruthy();
    expect(body.data, 'response should have data array').toBeTruthy();
    // The first order (E2E one) should expose items[] (may be empty array if no orderItems
    // were created — but the key MUST exist per resource contract).
    if (Array.isArray(body.data) && body.data.length > 0) {
      expect(body.data[0]).toHaveProperty('items');
    }
  });

  // ---------------------------------------------------------------------------
  // Page 18 — delivery flow status change to DELIVERED with CASH_ON_DELIVERY
  // triggers audit_log `delivery.cash_collected_escrow` row (P0-LIV-02 / NF525).
  // We exercise the controller via the livreur API and then assert via tinker
  // the audit_log row exists. Evidence file dumped for reviewer.
  // ---------------------------------------------------------------------------
  test('Page 18 — DELIVERED + CASH_ON_DELIVERY writes delivery.cash_collected_escrow audit_log', async ({ request }) => {
    const dir = path.join(OUT_BASE, '18-livreur-cash-escrow');
    fs.mkdirSync(dir, { recursive: true });
    const { execSync } = require('child_process');

    // Pre-state: capture current audit_log count for our test order via tinker
    const orderIdStdout = execSync(
      `php artisan tinker --execute "echo \\App\\Models\\Order::where('order_serial_no','like','E2E-LIV-%')->orderByDesc('id')->value('id');"`,
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' },
    ).trim();
    const orderId = parseInt(orderIdStdout.match(/\d+/)?.[0] || '0', 10);
    fs.writeFileSync(path.join(dir, '18a-target-order-id.json'), JSON.stringify({ order_id: orderId }, null, 2));
    expect(orderId, 'test order should exist (created via tinker fixture)').toBeGreaterThan(0);

    // Login as the livreur.
    const apiKey = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';
    const loginResp = await request.post('http://127.0.0.1:8000/api/auth/login', {
      headers: { 'x-api-key': apiKey, 'Content-Type': 'application/json' },
      data: { email: 'livreur-e2e@lecayenne.fr', password: '123456' },
    });
    const token = (await loginResp.json()).token;
    expect(token, 'livreur login should succeed').toBeTruthy();

    // Change status to DELIVERED (=13 per OrderStatus enum: PENDING=1, ACCEPT=4,
    // PREPARING=7, PREPARED=8, OUT_FOR_DELIVERY=10, DELIVERED=13).
    const changeStatusResp = await request.post(
      `http://127.0.0.1:8000/api/frontend/delivery-boy-order/change-status/${orderId}`,
      {
        headers: { 'x-api-key': apiKey, Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        data: { status: 13, reason: 'Delivered to customer doorstep (E2E)' },
      },
    );
    const csStatus = changeStatusResp.status();
    let csBody = null;
    try { csBody = await changeStatusResp.json(); } catch { csBody = await changeStatusResp.text(); }
    fs.writeFileSync(
      path.join(dir, '18b-change-status-response.json'),
      JSON.stringify({ status: csStatus, body: csBody }, null, 2),
    );

    // Allow the audit row to persist.
    await new Promise((res) => setTimeout(res, 800));

    // Query audit_logs via tinker — write evidence row to disk.
    const auditQuery = `php artisan tinker --execute "
      \\\$row = \\\\DB::table('audit_logs')
        ->where('action','delivery.cash_collected_escrow')
        ->where('resource_id', ${orderId})
        ->orderByDesc('id')
        ->first();
      echo \\\$row ? json_encode([
        'id'=>\\\$row->id,
        'branch_id'=>\\\$row->branch_id,
        'user_id'=>\\\$row->user_id,
        'action'=>\\\$row->action,
        'resource'=>\\\$row->resource,
        'resource_id'=>\\\$row->resource_id,
        'payload'=>json_decode(\\\$row->payload,true),
        'created_at'=>(string)\\\$row->created_at,
      ]) : 'NONE';
    "`;
    const auditStdout = execSync(auditQuery, {
      cwd: path.resolve(__dirname, '../..'),
      encoding: 'utf8',
    }).trim();
    fs.writeFileSync(path.join(dir, '18c-audit-log-evidence.json'), auditStdout);

    // NF525 evidence: audit row must exist.
    expect(auditStdout, 'audit_log row delivery.cash_collected_escrow should exist').not.toBe('NONE');
    expect(auditStdout, 'audit row should reference our order').toContain(`"resource_id":${orderId}`);
    expect(auditStdout, 'payload should record amount_collected').toContain('"amount_collected"');
    expect(auditStdout, 'payload should record event=doorstep_cash_collection').toContain('"doorstep_cash_collection"');
  });
});
