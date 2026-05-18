// Phase C — Visual Mandate Capture (CLAUDE.md §6)
// Date : 2026-05-17
// Mission : Capture + analyze 7 production surfaces post-Phase C commit 72b078682.
// READ-ONLY — no UI changes; confirms no unintended regression.

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const OUT_DIR = path.join(__dirname, '..', 'captures', 'phase-c-visual-mandate-2026-05-17');
const CONSOLE_LOG = path.join(OUT_DIR, 'console-errors.json');

// Ensure output dir + console log seed exist
if (!fs.existsSync(OUT_DIR)) fs.mkdirSync(OUT_DIR, { recursive: true });
const allConsoleErrors = {};

test.describe.configure({ mode: 'serial' });
test.use({ viewport: { width: 1440, height: 900 } });

/**
 * Login via LoginComponent ids (#formEmail / #formPassword)
 * Falls back to name="email" if needed. Best-effort — capture even if login fails.
 */
async function loginAdmin(page) {
  await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 30000 });
  // LoginComponent uses #formEmail / #formPassword (id attrs)
  const emailField = page.locator('#formEmail');
  const passwordField = page.locator('#formPassword');
  await emailField.waitFor({ state: 'visible', timeout: 15000 });
  await emailField.fill('admin@lecayenne.fr');
  await passwordField.fill('123456');
  const submit = page.getByRole('button', { name: /^(login|connexion)$/i });
  await submit.click();
  // Wait for SPA route change away from /login
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25000 }).catch(() => {});
  await page.waitForTimeout(1500);
}

async function snap(page, name) {
  await page.screenshot({ path: path.join(OUT_DIR, `${name}.png`), fullPage: true });
}

function attachConsoleCollector(page, surfaceName) {
  const errs = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      errs.push({ type: 'console.error', text: msg.text() });
    }
  });
  page.on('pageerror', (err) => {
    errs.push({ type: 'pageerror', text: err.message });
  });
  allConsoleErrors[surfaceName] = errs;
  return errs;
}

test.afterAll(async () => {
  fs.writeFileSync(CONSOLE_LOG, JSON.stringify(allConsoleErrors, null, 2));
});

// ---------- Surface 1 : Admin Login (public) ----------
test('S1 — admin login screen', async ({ page }) => {
  const errs = attachConsoleCollector(page, 'S1-login');
  await page.goto('/login', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(600);
  await snap(page, '01-login');
  console.log('[S1-login] console errors:', errs.length);
});

// ---------- Surface 2 : POS V4 Caisse (admin-auth) ----------
test('S2 — POS V4 caisse', async ({ page }) => {
  const errs = attachConsoleCollector(page, 'S2-pos');
  await loginAdmin(page);
  await page.goto('/admin/pos', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(2500); // POS bundle is heavy
  await snap(page, '02-admin-pos');
  console.log('[S2-pos] console errors:', errs.length, 'URL:', page.url());
});

// ---------- Surface 3 : Admin Items Catalogue ----------
test('S3 — admin items catalogue', async ({ page }) => {
  const errs = attachConsoleCollector(page, 'S3-items');
  await loginAdmin(page);
  await page.goto('/admin/items', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(2000);
  await snap(page, '03-admin-items');
  console.log('[S3-items] console errors:', errs.length, 'URL:', page.url());
});

// ---------- Surface 4 : Stock Rupture Dashboard ----------
test('S4 — stock rupture dashboard', async ({ page }) => {
  const errs = attachConsoleCollector(page, 'S4-stock');
  await loginAdmin(page);
  await page.goto('/admin/stock-rupture-dashboard', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(1800);
  await snap(page, '04-admin-stock-rupture-dashboard');
  console.log('[S4-stock] console errors:', errs.length, 'URL:', page.url());
});

// ---------- Surface 5 : KDS (chef-auth, route /admin/kitchen-display-system) ----------
test('S5 — KDS kitchen display', async ({ page }) => {
  const errs = attachConsoleCollector(page, 'S5-kds');
  // Per CLAUDE.md §6: surface URL listed as /kds — capture both if redirected
  await loginAdmin(page);
  await page.goto('/admin/kitchen-display-system', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(2000);
  await snap(page, '05-kds');
  console.log('[S5-kds] console errors:', errs.length, 'URL:', page.url());
});

// ---------- Surface 6 : OSS Wall (public) ----------
test('S6 — OSS order status screen', async ({ page }) => {
  const errs = attachConsoleCollector(page, 'S6-oss');
  await page.goto('/order-status-screen', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(2000);
  await snap(page, '06-oss');
  console.log('[S6-oss] console errors:', errs.length, 'URL:', page.url());
});

// ---------- Surface 7 : Kiosk Idle (public) ----------
test('S7 — kiosk idle borne', async ({ page }) => {
  const errs = attachConsoleCollector(page, 'S7-kiosk');
  await page.goto('/kiosk/idle', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(2000);
  await snap(page, '07-kiosk-idle');
  console.log('[S7-kiosk] console errors:', errs.length, 'URL:', page.url());
});
