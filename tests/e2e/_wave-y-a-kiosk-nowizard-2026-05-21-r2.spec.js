/**
 * Wave Y / Agent A (GStack capture) — Kiosk NON-WIZARD E2E audit
 * 2026-05-21
 *
 * Mission: visual + console + network capture of every kiosk client-facing
 * surface EXCEPT the wizard customization flow. Owner mandate: "Visual first"
 * — truncation, overlap, palette drift, off-screen elements = P1+; numeric
 * total mismatch = P0; silent 4xx without UI alert = P0.
 *
 * Output:
 *   reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/captures/wave-A/*.png
 *   reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/captures/wave-A/_logs/*.json
 *
 * Frozen-zones honored: no clicks on "Personnaliser", no edits in
 * KioskWizardComponent / KioskAppComponent / KioskUpsellComponent / Payment.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT_DIR = path.resolve(
  __dirname,
  '..',
  '..',
  'reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/round-2/captures/wave-A'
);
const LOG_DIR = path.join(OUT_DIR, '_logs');

fs.mkdirSync(OUT_DIR, { recursive: true });
fs.mkdirSync(LOG_DIR, { recursive: true });

// 1366x900 — kiosk portrait-ish landscape, matches existing capture cycles.
test.use({ viewport: { width: 1366, height: 900 } });
test.setTimeout(120_000);

// ---------------------------------------------------------------------------
// Capture harness — attach listeners BEFORE goto.
// ---------------------------------------------------------------------------
function attachListeners(page, label) {
  const consoleErrors = [];
  const consoleWarnings = [];
  const networkErrors = [];
  const requests = [];

  page.on('console', msg => {
    const type = msg.type();
    const text = msg.text();
    if (type === 'error') consoleErrors.push({ url: page.url(), text });
    else if (type === 'warning') consoleWarnings.push({ url: page.url(), text });
  });
  page.on('pageerror', err => {
    consoleErrors.push({ url: page.url(), text: 'PAGEERROR: ' + (err.message || String(err)) });
  });
  page.on('response', async res => {
    const status = res.status();
    const url = res.url();
    if (status >= 400) {
      // Filter noise: favicon, hot-reload, analytics endpoints we don't own.
      if (/favicon|hot-update|analytics\.google|googletagmanager/i.test(url)) return;
      let bodySnippet = null;
      try { bodySnippet = (await res.text()).slice(0, 240); } catch (_) {}
      networkErrors.push({ url, status, bodySnippet });
    }
  });
  page.on('request', req => {
    // Track relevant kiosk API requests for numeric-integrity audit.
    const u = req.url();
    if (/\/api\/(kiosk|frontend|cart|order|menu|item)/i.test(u)) {
      requests.push({ method: req.method(), url: u });
    }
  });

  return {
    consoleErrors,
    consoleWarnings,
    networkErrors,
    requests,
    dump() {
      const file = path.join(LOG_DIR, `${label}.json`);
      fs.writeFileSync(
        file,
        JSON.stringify(
          { label, consoleErrors, consoleWarnings, networkErrors, requests },
          null,
          2
        )
      );
      return file;
    },
  };
}

async function safeScreenshot(page, name) {
  const file = path.join(OUT_DIR, name);
  try {
    await page.screenshot({ path: file, fullPage: true });
  } catch (e) {
    // fall back to viewport screenshot if fullPage fails (rare)
    await page.screenshot({ path: file }).catch(() => {});
  }
  return file;
}

async function ensureIdle(page) {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2200);
}

async function clickTakeaway(page) {
  // The idle screen shows order-type choice cards after first tap. Try both
  // direct text and testid paths.
  const touchBtn = page.locator('[data-testid="kiosk-idle-touch-btn"]');
  if (await touchBtn.count()) {
    await touchBtn.first().click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(700);
  }
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  if (await takeaway.count()) {
    await takeaway.first().click({ timeout: 3000 }).catch(() => {});
  } else {
    const text = page.getByText(/À emporter|Emporter|Takeaway/i).first();
    if (await text.count()) await text.click({ timeout: 3000 }).catch(() => {});
  }
  await page.waitForTimeout(2800);
}

// ---------------------------------------------------------------------------
// TESTS
// ---------------------------------------------------------------------------

test('A1 — kiosk idle screen', async ({ page }) => {
  const logs = attachListeners(page, 'A1-idle');
  await ensureIdle(page);
  await safeScreenshot(page, 'A1-kiosk-idle.png');

  // Capture also after touching the idle CTA — order-type chooser may appear.
  const touchBtn = page.locator('[data-testid="kiosk-idle-touch-btn"]');
  if (await touchBtn.count()) {
    await touchBtn.first().click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(1200);
    await safeScreenshot(page, 'A1b-kiosk-idle-order-type-chooser.png');
  }
  logs.dump();
});

test('A2 — kiosk catalog all 11 categories', async ({ page }) => {
  const logs = attachListeners(page, 'A2-catalog');
  await ensureIdle(page);
  await clickTakeaway(page);

  await safeScreenshot(page, 'A2-00-catalog-landing.png');

  const tabs = [
    'Sandwich Cayenne',
    'Galette',
    'Sandwich Classique',
    'Burgers',
    'Tacos',
    'Bols Gourmands',
    'Frites',
    'Suppléments',
    'Desserts',
    'Boissons',
    'Menu enfant',
  ];

  for (let i = 0; i < tabs.length; i++) {
    const name = tabs[i];
    // Sidebar item text is rendered exactly as the category name.
    const tab = page.getByText(name, { exact: true }).first();
    if (await tab.count()) {
      await tab.click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(1400);
    }
    const idx = String(i + 1).padStart(2, '0');
    const slug = name.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z]+/g, '-').replace(/^-|-$/g, '');
    await safeScreenshot(page, `A2-${idx}-cat-${slug}.png`);
  }
  logs.dump();
});

test('A3 — cart after non-wizard "+ add" (Supplement)', async ({ page }) => {
  const logs = attachListeners(page, 'A3-cart');
  await ensureIdle(page);
  await clickTakeaway(page);

  // Navigate to Suppléments — guaranteed non-wizard by component logic.
  const tab = page.getByText('Suppléments', { exact: true }).first();
  if (await tab.count()) {
    await tab.click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(1400);
  }
  await safeScreenshot(page, 'A3-00-supplements-list.png');

  // Click the first "+" button (kiosk-product-add-*) — bypasses wizard.
  const addBtn = page.locator('[data-testid^="kiosk-product-add-"]').first();
  if (await addBtn.count()) {
    await addBtn.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(1500);
  }
  await safeScreenshot(page, 'A3-01-supplements-after-add.png');

  // Capture cart total from the bottom bar / bottom sheet directly on catalog.
  // Then navigate explicitly to /kiosk/cart for the full cart surface.
  await page.goto(`${BASE}/kiosk/cart`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  await safeScreenshot(page, 'A3-02-cart-page.png');

  // Numeric integrity probe — record cart total from DOM.
  const cartTotalText = await page
    .locator('[data-testid="kiosk-cart-total"]')
    .first()
    .innerText()
    .catch(() => null);
  fs.writeFileSync(
    path.join(LOG_DIR, 'A3-cart-total.json'),
    JSON.stringify({ cartTotalText }, null, 2)
  );

  // Try adding a second item (a different supplement) to verify quantity bump.
  await page.goto(`${BASE}/kiosk/categories`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  const tab2 = page.getByText('Suppléments', { exact: true }).first();
  if (await tab2.count()) await tab2.click({ timeout: 3000 }).catch(() => {});
  await page.waitForTimeout(1200);
  const adds = page.locator('[data-testid^="kiosk-product-add-"]');
  const count = await adds.count();
  if (count >= 2) {
    await adds.nth(1).click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(1500);
  }
  await safeScreenshot(page, 'A3-03-after-second-add.png');

  logs.dump();
});

test('A4 — cart → payment selection screen', async ({ page }) => {
  const logs = attachListeners(page, 'A4-payment');
  await ensureIdle(page);
  await clickTakeaway(page);

  // Seed cart with one supplement.
  const tab = page.getByText('Suppléments', { exact: true }).first();
  if (await tab.count()) await tab.click({ timeout: 3000 }).catch(() => {});
  await page.waitForTimeout(1200);
  const addBtn = page.locator('[data-testid^="kiosk-product-add-"]').first();
  if (await addBtn.count()) await addBtn.click({ timeout: 4000 }).catch(() => {});
  await page.waitForTimeout(1500);

  // Go to cart.
  await page.goto(`${BASE}/kiosk/cart`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  await safeScreenshot(page, 'A4-00-cart-before-pay.png');

  // Click "Payer" / "Continuer vers paiement" CTA. The cart bottom bar.
  const payCta = page.locator(
    '[data-testid="kiosk-cart-checkout"], [data-testid="kiosk-cart-checkout-cta"], button:has-text("Payer"), button:has-text("Commander"), button:has-text("Continuer")'
  ).first();
  if (await payCta.count()) {
    await payCta.click({ timeout: 4000 }).catch(() => {});
  } else {
    // Fallback: direct navigation
    await page.goto(`${BASE}/kiosk/payment`, { waitUntil: 'domcontentloaded' });
  }
  await page.waitForTimeout(2500);
  await safeScreenshot(page, 'A4-01-payment-screen.png');

  // Capture cart total from payment screen.
  const payTotalText = await page
    .locator('[data-testid="kiosk-payment-total"]')
    .first()
    .innerText()
    .catch(() => null);
  fs.writeFileSync(
    path.join(LOG_DIR, 'A4-payment-total.json'),
    JSON.stringify({ payTotalText, url: page.url() }, null, 2)
  );

  // Select cash method (capture state).
  const cash = page.locator('[data-testid="kiosk-payment-method-cash"]');
  if (await cash.count()) {
    await cash.click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(1000);
    await safeScreenshot(page, 'A4-02-payment-cash-selected.png');
  }

  logs.dump();
});

test('A5 — cash instruction screen', async ({ page }) => {
  const logs = attachListeners(page, 'A5-cash');
  // Direct navigation — KioskCashInstructionComponent renders standalone.
  await page.goto(`${BASE}/kiosk/cash-instruction`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await safeScreenshot(page, 'A5-cash-instruction.png');
  logs.dump();
});

test('A6 — confirmation screen', async ({ page }) => {
  const logs = attachListeners(page, 'A6-confirmation');
  await page.goto(`${BASE}/kiosk/confirmation`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await safeScreenshot(page, 'A6-confirmation.png');
  logs.dump();
});

test('A7 — return-to-idle (post confirmation home button)', async ({ page }) => {
  const logs = attachListeners(page, 'A7-return-idle');
  await page.goto(`${BASE}/kiosk/confirmation`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1800);

  const home = page.locator('[data-testid="kiosk-confirmation-cta-home"]');
  if (await home.count()) {
    await home.click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(2200);
  } else {
    // Fallback — just go idle directly.
    await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1800);
  }
  await safeScreenshot(page, 'A7-after-return-to-idle.png');
  logs.dump();
});

test('A8 — kiosk error pages (4 routes)', async ({ page }) => {
  const errs = [
    { slug: 'network', path: '/kiosk/error/network' },
    { slug: 'menu-unavailable', path: '/kiosk/error/menu-unavailable' },
    { slug: 'product-removed', path: '/kiosk/error/product-removed?name=TestProduit&item_id=999' },
    { slug: 'payment-refused', path: '/kiosk/error/payment-refused?code=DECLINED' },
  ];
  for (let i = 0; i < errs.length; i++) {
    const e = errs[i];
    const logs = attachListeners(page, `A8-error-${e.slug}`);
    await page.goto(`${BASE}${e.path}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2200);
    await safeScreenshot(page, `A8-${String(i + 1).padStart(2, '0')}-error-${e.slug}.png`);
    logs.dump();
  }
});
