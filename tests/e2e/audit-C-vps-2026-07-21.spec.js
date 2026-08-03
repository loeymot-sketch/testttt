// FoodKing — Wave C VPS deploy-validation audit (2026-07-21)
// GStack + adversarial capture-first spec against the LIVE VPS.
// Run: PLAYWRIGHT_BASE_URL=https://vps-418872ac.vps.ovh.net \
//        PLAYWRIGHT_NO_WEB_SERVER=1 \
//        npx playwright test tests/e2e/audit-C-vps-2026-07-21.spec.js --project=chromium
//
// CAPTURE-FIRST: every step is defensive (try/catch) and ALWAYS screenshots.
// Nothing aborts the run — the auditor reads the PNGs + obs JSON afterwards.
// Login is attempted for real in-browser; if it fails the surface is captured
// as login-blocked (redirect to /login) — never an invented pass.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.use({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 900 } });
test.describe.configure({ mode: 'serial', timeout: 180_000 });

const SHOT_DIR = path.join(__dirname, '__screenshots__', 'audit-C-vps');
if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const ADMIN_EMAILS = ['admin@lecayenne.fr', 'admin@example.com'];
const POS_EMAIL = 'pos@lecayenne.fr';
const PASSWORD = '123456';

// Anchored raw-i18n-key shape: a whole token that is entirely a key path.
const RAW_KEY_RE = /^[a-z]+(\.[a-z_]+){1,4}$/;

const obs = {}; // per-scenario observations, dumped to obs.json at the end

function recordSinks(page, key) {
  const bucket = { console_errors: [], page_errors: [], bad_responses: [] };
  obs[key] = obs[key] || {};
  obs[key].sinks = bucket;
  page.on('console', (m) => {
    if (m.type() === 'error') bucket.console_errors.push(m.text().slice(0, 300));
  });
  page.on('pageerror', (e) => bucket.page_errors.push((e.message || String(e)).slice(0, 300)));
  page.on('response', (r) => {
    const s = r.status();
    if (s >= 400) bucket.bad_responses.push(`${s} ${r.request().method()} ${r.url().slice(0, 160)}`);
  });
  return bucket;
}

async function scanText(locator) {
  try {
    const text = (await locator.innerText()) || '';
    const naN = [...new Set(text.match(/\b(NaN|undefined|null)\b/g) || [])];
    const tokens = text.split(/\s+/).filter(Boolean);
    const rawKeys = [...new Set(tokens.filter((t) => RAW_KEY_RE.test(t)))];
    return { rawKeys, naN, len: text.length };
  } catch (_e) {
    return { rawKeys: [], naN: [], len: 0, error: 'scan-failed' };
  }
}

async function shot(page, name) {
  try {
    await page.screenshot({ path: path.join(SHOT_DIR, `${name}.png`), fullPage: false });
  } catch (_e) { /* ignore */ }
}

// In-browser login. Returns { ok, status, email, note }. No local-artisan side effects.
async function tryLogin(page, emails, password) {
  const list = Array.isArray(emails) ? emails : [emails];
  let last = { ok: false, status: null, email: null, note: 'no attempt' };
  for (const email of list) {
    for (let attempt = 1; attempt <= 2; attempt++) {
      try {
        await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 40_000 });
        const emailInput = page
          .locator('#formEmail, input[type="email"], input[name="email"], input[type="text"]')
          .first();
        await emailInput.waitFor({ state: 'visible', timeout: 15_000 });
        await emailInput.fill(email);
        const pwInput = page
          .locator('#formPassword, input[type="password"], input[name="password"]')
          .first();
        await pwInput.fill(password);

        let submit = page.getByRole('button', { name: /^(login|connexion|se connecter)$/i }).first();
        if (!(await submit.isVisible({ timeout: 2_000 }).catch(() => false))) {
          submit = page.locator('button[type="submit"], form button').first();
        }

        const respP = page
          .waitForResponse(
            (r) => r.request().method() === 'POST' && /\/api\/auth\/login/i.test(r.url()),
            { timeout: 25_000 },
          )
          .catch(() => null);
        await submit.click().catch(() => {});
        const resp = await respP;
        const status = resp ? resp.status() : null;

        // Success = URL leaves /login within a few seconds.
        const left = await page
          .waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 8_000 })
          .then(() => true)
          .catch(() => false);
        if (left) return { ok: true, status, email, note: `attempt ${attempt}` };
        last = { ok: false, status, email, note: `stayed on /login (HTTP ${status})` };
      } catch (e) {
        last = { ok: false, status: null, email, note: (e.message || String(e)).slice(0, 160) };
      }
      await page.waitForTimeout(1_200 * attempt);
    }
  }
  return last;
}

// ── Scenario 1 — Kiosk idle (public, no login) ────────────────────────────
test('S1 kiosk/idle (public)', async ({ page }) => {
  recordSinks(page, 's1_kiosk_idle');
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded', timeout: 40_000 }).catch(() => {});
  await page.waitForTimeout(3_500); // idle attract animation + hydration
  const root = page.locator('[data-testid="kiosk-idle-root"], .kiosk-idle, body').first();
  obs.s1_kiosk_idle.root_visible = await root.isVisible({ timeout: 15_000 }).catch(() => false);
  obs.s1_kiosk_idle.url = page.url();
  obs.s1_kiosk_idle.text_scan = await scanText(page.locator('body'));
  await shot(page, 's1-kiosk-idle');
});

// ── Login page render + real login attempt (evidence either way) ───────────
test('S0 login page + admin login attempt', async ({ page }) => {
  recordSinks(page, 's0_login');
  await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 40_000 }).catch(() => {});
  await page.waitForTimeout(2_500);
  obs.s0_login.form_visible = await page
    .locator('#formEmail, input[type="email"], input[name="email"], input[type="text"]')
    .first()
    .isVisible({ timeout: 15_000 })
    .catch(() => false);
  obs.s0_login.text_scan = await scanText(page.locator('body'));
  await shot(page, 's0-login-page');

  const res = await tryLogin(page, ADMIN_EMAILS, PASSWORD);
  obs.s0_login.admin_login = res;
  await page.waitForTimeout(1_000);
  // Capture whatever is on screen after the attempt (error toast or landing).
  obs.s0_login.post_attempt_url = page.url();
  obs.s0_login.post_attempt_text = await scanText(page.locator('body'));
  await shot(page, 's0-login-after-attempt');
});

// ── Scenario 2 + 3 — Admin catalog-hub (2 tabs) + Stock photo button ───────
test('S2+S3 admin catalog-hub tabs + stock photo', async ({ page }) => {
  recordSinks(page, 's2_catalog_hub');
  const res = await tryLogin(page, ADMIN_EMAILS, PASSWORD);
  obs.s2_catalog_hub.login = res;

  await page
    .goto('/admin/catalog-hub', { waitUntil: 'domcontentloaded', timeout: 40_000 })
    .catch(() => {});
  await page.waitForTimeout(3_000);
  obs.s2_catalog_hub.landed_url = page.url();

  const hub = page.locator('[data-testid="catalog-hub"]');
  obs.s2_catalog_hub.hub_visible = await hub.isVisible({ timeout: 10_000 }).catch(() => false);

  if (!obs.s2_catalog_hub.hub_visible) {
    // Login-blocked → bounced to /login. Capture the redirect state.
    obs.s2_catalog_hub.coverage = res.ok ? 'hub-not-rendered' : 'login-blocked (infra)';
    obs.s2_catalog_hub.text_scan = await scanText(page.locator('body'));
    await shot(page, 's2-catalog-hub-blocked');
    return;
  }

  // Tab "Catalogue"
  const tabCat = page.locator('[data-testid="catalog-hub-tab-catalogue"]');
  const tabStock = page.locator('[data-testid="catalog-hub-tab-stock"]');
  obs.s2_catalog_hub.tab_catalogue_present = await tabCat.isVisible().catch(() => false);
  obs.s2_catalog_hub.tab_stock_present = await tabStock.isVisible().catch(() => false);
  obs.s2_catalog_hub.tab_catalogue_label = (await tabCat.innerText().catch(() => '')).trim();
  obs.s2_catalog_hub.tab_stock_label = (await tabStock.innerText().catch(() => '')).trim();

  await tabCat.click().catch(() => {});
  await page.waitForTimeout(2_000);
  obs.s2_catalog_hub.panel_catalogue_visible = await page
    .locator('[data-testid="catalog-hub-panel-catalogue"] [data-testid="catalog-studio-page"]')
    .isVisible({ timeout: 10_000 })
    .catch(() => false);
  obs.s2_catalog_hub.catalogue_scan = await scanText(page.locator('[data-testid="catalog-hub"]'));
  await shot(page, 's2-catalog-hub-tab-catalogue');

  // Tab "Stock & disponibilité"
  await tabStock.click().catch(() => {});
  await page.waitForTimeout(2_500);
  obs.s2_catalog_hub.panel_stock_visible = await page
    .locator('[data-testid="catalog-hub-panel-stock"] [data-testid="stock-management-v2"]')
    .isVisible({ timeout: 10_000 })
    .catch(() => false);
  obs.s2_catalog_hub.stock_scan = await scanText(page.locator('[data-testid="catalog-hub"]'));
  await shot(page, 's3-catalog-hub-tab-stock');

  // Scenario 3 — photo button per product row → open inline uploader
  const photoBtn = page.locator('[data-testid^="stock-mgmt-photo-btn-"]');
  const photoCount = await photoBtn.count().catch(() => 0);
  obs.s2_catalog_hub.photo_button_count = photoCount;
  if (photoCount > 0) {
    await photoBtn.first().scrollIntoViewIfNeeded().catch(() => {});
    await photoBtn.first().click().catch(() => {});
    await page.waitForTimeout(1_500);
    const panel = page.locator('[data-testid^="stock-mgmt-photo-panel-"]').first();
    obs.s2_catalog_hub.photo_panel_opened = await panel.isVisible({ timeout: 6_000 }).catch(() => false);
    await shot(page, 's3-stock-photo-uploader');
  } else {
    obs.s2_catalog_hub.photo_panel_opened = false;
  }
  obs.s2_catalog_hub.coverage = 'captured';
});

// ── Scenario 4 — Caisse POS wizard (composer-aware) ────────────────────────
test('S4 POS caisse wizard (composer-aware)', async ({ page }) => {
  recordSinks(page, 's4_pos');
  const res = await tryLogin(page, [POS_EMAIL, ...ADMIN_EMAILS], PASSWORD);
  obs.s4_pos.login = res;

  await page.goto('/admin/pos', { waitUntil: 'domcontentloaded', timeout: 40_000 }).catch(() => {});
  await page.waitForTimeout(3_500);
  obs.s4_pos.landed_url = page.url();

  const grid = page.locator('.pos-v5-grid, .pos-grid, .pos-v5-tile').first();
  obs.s4_pos.grid_visible = await grid.isVisible({ timeout: 12_000 }).catch(() => false);
  if (!obs.s4_pos.grid_visible) {
    obs.s4_pos.coverage = res.ok ? 'pos-not-rendered' : 'login-blocked (infra)';
    obs.s4_pos.text_scan = await scanText(page.locator('body'));
    await shot(page, 's4-pos-blocked');
    return;
  }
  await shot(page, 's4-pos-grid');

  // Dismiss a cash-drawer dialog if it blocks the grid (best-effort, non-fatal).
  const cashClose = page
    .locator('[data-testid="cash-session-close"], [role="dialog"] button:has-text("Fermer"), [role="dialog"] button:has-text("Plus tard")')
    .first();
  if (await cashClose.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await cashClose.click().catch(() => {});
    await page.waitForTimeout(800);
  }

  // Prefer a "Cayenne" tile (composer-aware product); else first non-86 tile.
  let tile = page.locator('.pos-v5-tile', { hasText: /Cayenne/i }).first();
  if (!(await tile.isVisible({ timeout: 3_000 }).catch(() => false))) {
    tile = page
      .locator('.pos-v5-tile')
      .filter({ hasNot: page.locator('.pos-item-86-badge, .pos-v5-tile__overlay') })
      .first();
  }
  obs.s4_pos.tile_found = await tile.isVisible({ timeout: 5_000 }).catch(() => false);
  obs.s4_pos.tile_name = (await tile.innerText().catch(() => '')).trim().slice(0, 60);

  // Snapshot page-error count right before opening the wizard (composer-aware activation).
  const errBefore = obs.s4_pos.sinks.page_errors.length;
  await tile.click().catch(() => {});
  await page.waitForTimeout(2_500);

  const wizard = page.locator('.pos-wizard, [data-testid="pos-wizard"], .wizard-modal').first();
  obs.s4_pos.wizard_opened = await wizard.isVisible({ timeout: 8_000 }).catch(() => false);
  obs.s4_pos.wizard_step_count = await page.locator('.pos-wizard .wizard-step').count().catch(() => 0);
  obs.s4_pos.wizard_scan = await scanText(wizard);
  obs.s4_pos.page_errors_during_wizard = obs.s4_pos.sinks.page_errors.slice(errBefore);
  await shot(page, 's4-pos-wizard');
  obs.s4_pos.coverage = 'captured';
});

// ── Scenario 5 — KDS + OSS ─────────────────────────────────────────────────
test('S5 KDS + OSS', async ({ page }) => {
  recordSinks(page, 's5_kds_oss');
  const res = await tryLogin(page, ADMIN_EMAILS, PASSWORD);
  obs.s5_kds_oss.login = res;

  // KDS
  await page.goto('/kds', { waitUntil: 'domcontentloaded', timeout: 40_000 }).catch(() => {});
  await page.waitForTimeout(3_000);
  obs.s5_kds_oss.kds_url = page.url();
  obs.s5_kds_oss.kds_blocked = /\/login(?:$|\?)/.test(page.url());
  obs.s5_kds_oss.kds_scan = await scanText(page.locator('body'));
  await shot(page, 's5-kds');

  // OSS
  await page
    .goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded', timeout: 40_000 })
    .catch(() => {});
  await page.waitForTimeout(3_000);
  obs.s5_kds_oss.oss_url = page.url();
  obs.s5_kds_oss.oss_blocked = /\/login(?:$|\?)/.test(page.url());
  obs.s5_kds_oss.oss_scan = await scanText(page.locator('body'));
  await shot(page, 's5-oss');
  obs.s5_kds_oss.coverage = res.ok ? 'captured' : 'login-blocked (infra)';
});

test.afterAll(async () => {
  try {
    fs.writeFileSync(path.join(SHOT_DIR, 'obs.json'), JSON.stringify(obs, null, 2));
  } catch (_e) { /* ignore */ }
});
