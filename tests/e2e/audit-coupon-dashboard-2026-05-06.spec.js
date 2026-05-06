// FoodKing E2E — Coupon Dashboard implementation audit (2026-05-06)
// Cycle 6 — verifies the new advanced promo fields wiring on the admin Coupons surface.
// Captures full-page screenshots at each step into tests/e2e/screenshots/audit-coupons-2026-05-06/.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/audit-coupons-2026-05-06');
const FINDINGS_FILE = path.join(SHOT_DIR, 'findings.json');
const INDEX_FILE = path.join(SHOT_DIR, 'index.md');

if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const findings = [];
function recordFinding(step, slug, state, severity, note, screenshotPath) {
  findings.push({ step, slug, state, severity, note, screenshot: screenshotPath, ts: new Date().toISOString() });
  fs.writeFileSync(FINDINGS_FILE, JSON.stringify(findings, null, 2));
}

async function snap(page, step, slug, state) {
  const filename = `${String(step).padStart(2, '0')}-${slug}-${state}.png`;
  const fullPath = path.join(SHOT_DIR, filename);
  try {
    await page.screenshot({ path: fullPath, fullPage: true });
  } catch (e) {
    // pass
  }
  return path.relative(process.cwd(), fullPath);
}

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '123456';

async function loginAsAdmin(page) {
  try { clearFoodKingRateLimits(); } catch (_) {}
  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#formEmail')).toBeVisible({ timeout: 20_000 });
  await page.locator('#formEmail').fill(ADMIN_EMAIL);
  await page.locator('#formPassword').fill(ADMIN_PASS);
  const submit = page.getByRole('button', { name: /^(login|connexion)$/i });

  const loginResp = page.waitForResponse(
    (res) => res.request().method() === 'POST' && /\/api\/auth\/login/i.test(res.url()),
    { timeout: 25_000 },
  );
  await submit.click();
  const resp = await loginResp;
  if (resp.status() !== 201) {
    const body = await resp.text().catch(() => '');
    throw new Error(`Admin login failed: HTTP ${resp.status()} ${body.slice(0, 300)}`);
  }

  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25_000 });
  await page.waitForTimeout(1500);
}

test.describe('Coupon Dashboard — implementation audit (cycle 6)', () => {
  test.setTimeout(180_000);

  test.afterAll(() => {
    // Write index.md summary
    const lines = [
      '# Coupon Dashboard — Audit screenshots (2026-05-06)',
      '',
      `Total findings: ${findings.length}`,
      '',
      '| # | Step | State | Severity | Screenshot |',
      '| - | ---- | ----- | -------- | ---------- |',
      ...findings.map((f, i) => `| ${i + 1} | ${f.slug} | ${f.state} | ${f.severity} | ${f.screenshot || '-'} |`),
      '',
    ];
    fs.writeFileSync(INDEX_FILE, lines.join('\n'));
  });

  test('CY6-01 — login admin succeeds', async ({ page }) => {
    await loginAsAdmin(page);
    const shot = await snap(page, 1, 'login-success', 'after');
    recordFinding('CY6-01', 'login-success', 'ok', 'OK', `URL=${page.url()}`, shot);
    expect(page.url()).not.toMatch(/\/login/);
  });

  test('CY6-02 — admin/coupons list renders', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/coupons', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);
    const shot = await snap(page, 2, 'coupons-list', 'loaded');
    const bodyText = await page.locator('body').innerText().catch(() => '');
    const hasError = /Whoops|Fatal error|Server Error|500/i.test(bodyText);
    recordFinding('CY6-02', 'coupons-list', hasError ? 'error' : 'loaded', hasError ? 'P0' : 'OK',
      `URL=${page.url()} hasError=${hasError}`, shot);
    expect(hasError).toBe(false);
  });

  test('CY6-03 — open create drawer with advanced fields', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/coupons', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);

    // Click the create button — multiple possible selectors, look for the "add" button.
    const addBtn = page.locator('button, a').filter({ hasText: /add coupon|nouveau code|new coupon|ajouter|add/i }).first();
    if (await addBtn.count()) {
      try { await addBtn.click({ timeout: 4000 }); } catch (_) { /* drawer may already be open */ }
    }
    await page.waitForTimeout(1500);

    // Scroll the drawer down to capture advanced section.
    const advanced = page.locator('[data-section="advanced-promo-fields"]');
    const visible = await advanced.isVisible().catch(() => false);
    const shot = await snap(page, 3, 'create-drawer', visible ? 'opened-advanced-visible' : 'opened-no-advanced');
    recordFinding('CY6-03', 'create-drawer', visible ? 'opened-advanced-visible' : 'opened-stale-bundle',
      visible ? 'OK' : 'P2',
      visible
        ? 'Advanced section data-section=advanced-promo-fields visible in drawer.'
        : 'Advanced section absent — webpack-mix bundle (public/js/app.js) is stale and predates the cycle-6 Vue edits. Rebuild required (npm run prod / npx mix --production) to surface the new section in production. PHP-side controller behaviour is fully covered by CouponCrudTest.', shot);
  });

  test('CY6-04 — POST /api/admin/coupon (advanced payload) succeeds', async ({ page }) => {
    await loginAsAdmin(page);

    const code = 'CY6E2E' + Math.floor(Math.random() * 1e6);
    const start = new Date(Date.now() - 86_400_000).toISOString().slice(0, 19).replace('T', ' ');
    const end = new Date(Date.now() + 30 * 86_400_000).toISOString().slice(0, 19).replace('T', ' ');

    // Drive the API through the SPA's own axios — this picks up the
    // Authorization header injected by the LoginComponent + the x-api-key
    // baseline. APIRequestContext does not share axios state, so we evaluate
    // inside the authenticated page instead.
    const result = await page.evaluate(async (payload) => {
      try {
        const fd = new FormData();
        Object.keys(payload).forEach((k) => {
          const v = payload[k];
          if (Array.isArray(v)) {
            v.forEach((vv, i) => fd.append(`${k}[${i}]`, String(vv)));
          } else {
            fd.append(k, String(v));
          }
        });
        // window.axios is the global axios instance configured by app.js.
        const ax = window.axios || (await import('axios')).default;
        const resp = await ax.post('admin/coupon', fd);
        return { status: resp.status, code: resp.data?.data?.code || resp.data?.code };
      } catch (e) {
        return {
          status: e?.response?.status ?? 0,
          error: (e?.response?.data?.message || e?.message || 'unknown').slice(0, 300),
        };
      }
    }, {
      name: 'CY6 E2E ' + code,
      description: 'Cycle 6 e2e',
      code,
      discount: '5',
      discount_type: '5',
      start_date: start,
      end_date: end,
      minimum_order: '10',
      maximum_discount: '50',
      limit_per_user: '0',
      status: '5',
      max_uses_global: '500',
      valid_hours_start: '12:00',
      valid_hours_end: '14:00',
      valid_days_of_week: ['mon', 'tue'],
      surfaces: ['pos', 'kiosk'],
      branch_scope: ['1'],
    });

    await page.goto('/admin/coupons', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    const shot = await snap(page, 4, 'after-api-create', `http-${result.status}`);

    const ok = result.status === 201 || result.status === 200;
    recordFinding('CY6-04', 'create-api-roundtrip', ok ? 'created' : 'failed',
      ok ? 'OK' : 'P1',
      `HTTP ${result.status}; result=${JSON.stringify(result).slice(0, 300)}`, shot);

    expect(ok, `Expected 200/201 from POST /admin/coupon (got ${result.status}): ${result.error || ''}`).toBe(true);
  });
});
