/**
 * GOAL Functional Validation — POS Caisse Worker Journey
 * Date: 2026-05-28
 * Branch: heal/cms-pr1-quickwins-2026-05-18 HEAD 2bb6f1113
 *
 * Mission: Observe-only E2E audit of POS caisse as caissier real worker.
 * NO FROZEN-ZONE EDIT. AUDIT ONLY.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT_DIR = path.resolve(
  __dirname,
  '../../reports/test-e2e/goal-functional-validation-2026-05-28/POS/screenshots'
);
const FIND_PATH = path.resolve(
  __dirname,
  '../../reports/test-e2e/goal-functional-validation-2026-05-28/POS/findings-runtime.json'
);

if (!fs.existsSync(OUT_DIR)) {
  fs.mkdirSync(OUT_DIR, { recursive: true });
}

// Persisted findings — read-modify-write to survive worker isolation
function record(id, severity, title, file_line, evidence) {
  let data = { findings: [], generated_at: '' };
  try { data = JSON.parse(fs.readFileSync(FIND_PATH, 'utf8')); } catch (_) {}
  data.findings.push({ id, severity, title, file_line, evidence, ts: new Date().toISOString() });
  data.generated_at = new Date().toISOString();
  fs.writeFileSync(FIND_PATH, JSON.stringify(data, null, 2));
}

// Initialize file
fs.writeFileSync(FIND_PATH, JSON.stringify({ findings: [], generated_at: new Date().toISOString() }, null, 2));

test.use({ viewport: { width: 1366, height: 900 } });
test.setTimeout(120000);

async function loginAdmin(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  // SPA Vue boot — wait for the email input to render
  const emailInput = page.locator('input[type="email"], input[placeholder*="mail" i], input[id*="email" i]').first();
  await emailInput.waitFor({ timeout: 15000 }).catch(() => {});
  await emailInput.fill('admin@lecayenne.fr').catch(() => {});

  const pwInput = page.locator('input[type="password"]').first();
  await pwInput.fill('123456').catch(() => {});

  // Submit button — French "Connexion"
  const submitBtn = page.locator('button:has-text("Connexion"), button[type="submit"]').first();
  await submitBtn.click({ timeout: 4000 }).catch(() => {});

  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(1500);
}

test.describe.serial('POS Caisse Worker — full functional E2E', () => {
  test('POS-01 — Login admin + verify dashboard reach', async ({ page }) => {
    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500); // SPA boot
    await page.screenshot({ path: `${OUT_DIR}/01-login-page.png`, fullPage: true });

    // Probe DOM for input attributes
    const inputs = await page.locator('input').evaluateAll((els) =>
      els.map((e) => ({ type: e.type, name: e.name, id: e.id, placeholder: e.placeholder }))
    );
    record('POS-01-DOM-INPUTS', 'INFO', 'login page input inventory', '/login SPA', { inputs });

    await loginAdmin(page);

    const url = page.url();
    await page.screenshot({ path: `${OUT_DIR}/02-after-login.png`, fullPage: true });

    if (!url.includes('/admin')) {
      record(
        'POS-01-LOGIN-FAIL',
        'P0',
        `Login admin failed — post-submit URL=${url}`,
        '/login',
        { url, screenshot: '02-after-login.png' }
      );
    } else {
      record(
        'POS-01-LOGIN-OK',
        'INFO',
        `Login admin OK — landed at ${url}`,
        '/login',
        { url }
      );
    }
  });

  test('POS-02 — Navigate /admin/pos (POS Caisse load)', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5000);
    await page.screenshot({ path: `${OUT_DIR}/03-pos-loaded.png`, fullPage: true });

    const buttonCount = await page.locator('button').count();
    const itemCards = await page.locator('[data-pos-item], .pos-item, .item-card, [class*="product"]').count();
    const hasWizardRoot = (await page.locator('#pos-wizard, [class*="wizard"]').count()) > 0;
    const bodyText = await page.locator('body').innerText().catch(() => '');
    const sampleText = bodyText.substring(0, 500);

    record(
      'POS-02-OBS',
      'INFO',
      `POS loaded — buttons=${buttonCount} item_cards=${itemCards} wizard_root=${hasWizardRoot}`,
      'public/js/pos-wizard.js (frozen)',
      { buttonCount, itemCards, hasWizardRoot, sampleText, url: page.url() }
    );

    if (itemCards === 0) {
      record(
        'POS-02-NO-ITEMS',
        'P1',
        'POS shows zero item cards on first page',
        'public/js/pos-wizard.js category render',
        { hint: 'DB has only 11 supplément items. No Sandwich Cayenne/Burger/Tacos/Bols visible.' }
      );
    }
  });

  test('POS-03 — Cash drawer overview', async ({ page }) => {
    await loginAdmin(page);
    const resp = await page.goto(`${BASE}/admin/cash-drawer-overview`, { waitUntil: 'domcontentloaded' }).catch(() => null);
    const status = resp ? resp.status() : 0;
    await page.waitForTimeout(2500);
    await page.screenshot({ path: `${OUT_DIR}/04-cash-drawer-overview.png`, fullPage: true });

    const html = await page.content();
    const hasOpenSessionText =
      /ouverte|opened|en cours|session active/i.test(html);

    record(
      'POS-03-CASH-OVERVIEW',
      'INFO',
      `cash-drawer-overview status=${status} has_open_text=${hasOpenSessionText}`,
      'CashDrawerSessionController',
      { url: page.url(), status, hasOpenSessionText }
    );
  });

  test('POS-04 — Wizard popup observation (FROZEN)', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5000);
    await page.screenshot({ path: `${OUT_DIR}/05-pos-before-add.png`, fullPage: true });

    // Various selector candidates for first product card
    const candidates = page.locator(
      '[data-pos-item], .pos-item, .item-card, [class*="product-card"], button[data-item-id], [data-action="add-item"], .menu-item, [data-id]'
    ).first();
    const hasCandidate = (await candidates.count()) > 0;

    if (hasCandidate) {
      await candidates.click({ timeout: 4000 }).catch(() => {});
      await page.waitForTimeout(2500);
      await page.screenshot({ path: `${OUT_DIR}/06-pos-after-item-click.png`, fullPage: true });

      const wizardOpen = (await page.locator('.pos-wizard-popup, #pos-wizard-popup, [class*="wizard-modal"], [role="dialog"], .modal').count()) > 0;
      record(
        'POS-04-WIZARD',
        'INFO',
        `Wizard popup opened after click=${wizardOpen}`,
        'public/js/pos-wizard.js',
        { wizardOpen }
      );
    } else {
      record(
        'POS-04-NO-CARD',
        'P1',
        'No product card found in POS DOM — wizard cannot be triggered for observation',
        'public/js/pos-wizard.js DOM render',
        { hint: 'DB-deficit: 11 items in DB, no Sandwich/Burger/Tacos/Bols — supplements should render but POS may filter to featured cats only' }
      );
    }
  });

  test('POS-05 — Payment button discovery', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5000);

    const payButton = page.locator('button:has-text("Encaisser"), button:has-text("Payer"), button:has-text("Paiement"), button[data-action="checkout"], button:has-text("Espèces"), button:has-text("Carte")').first();
    const hasPayButton = (await payButton.count()) > 0;
    await page.screenshot({ path: `${OUT_DIR}/07-pos-pre-payment.png`, fullPage: true });

    record(
      'POS-05-PAY-BUTTON',
      'INFO',
      `Payment-related button discoverable=${hasPayButton}`,
      'public/js/pos-wizard.js payment UI',
      { hasPayButton }
    );
  });

  test('POS-06 — i18n raw-label sweep on /admin/pos', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5000);

    const text = await page.locator('body').innerText().catch(() => '');
    const rawPatterns = [
      /\bpos\.[a-z_.]+/gi,
      /\bkiosk\.[a-z_.]+/gi,
      /\[object Object\]/g,
      /\b0undefined\b/g,
      /\bLabel\.[A-Z][a-z]+/g,
      /\bNaN€?/g,
    ];
    const matches = [];
    for (const pat of rawPatterns) {
      const m = text.match(pat);
      if (m) matches.push({ pattern: pat.toString(), count: m.length, sample: m.slice(0, 3) });
    }

    if (matches.length > 0) {
      record(
        'POS-06-RAW-LABELS',
        'P1',
        `Raw i18n labels detected — ${matches.length} pattern hits`,
        '/admin/pos body',
        { matches }
      );
    } else {
      record('POS-06-I18N-CLEAN', 'INFO', 'No raw label patterns on POS body', '/admin/pos', { textLen: text.length });
    }
    await page.screenshot({ path: `${OUT_DIR}/08-pos-i18n-sweep.png`, fullPage: true });
  });

  test('POS-07 — Z report routes discovery', async ({ page }) => {
    await loginAdmin(page);
    const routes = ['/admin/z-reports', '/admin/cloture', '/admin/reports/daily', '/admin/reports/z-report'];
    for (const route of routes) {
      const resp = await page.goto(`${BASE}${route}`, { waitUntil: 'domcontentloaded' }).catch(() => null);
      const status = resp ? resp.status() : 0;
      const ct = resp ? resp.headers()['content-type'] : '';
      await page.waitForTimeout(1500);
      const safe = route.replace(/[^a-z0-9]/gi, '-');
      await page.screenshot({ path: `${OUT_DIR}/09-z-route${safe}.png`, fullPage: true });

      record(
        'POS-07-Z-ROUTE',
        'INFO',
        `Z route ${route} status=${status} ct=${ct}`,
        route,
        { route, status, content_type: ct }
      );
    }
  });
});
