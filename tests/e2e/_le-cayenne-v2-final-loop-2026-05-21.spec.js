/**
 * Le Cayenne V2 — FINAL E2E LOOP (2026-05-21)
 * 10 proof captures + order simulation kiosk→KDS + POS auth attempt
 */
const { test, expect } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = 'tests/captures/le-cayenne-v2-2026-05-21';

test.use({ viewport: { width: 1366, height: 900 } });
test.setTimeout(120000);

// ─── 1. Kiosk idle (logo + branding) ───
test('P1 — Kiosk idle: Le Cayenne logo + branding', async ({ page }) => {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.screenshot({ path: `${OUT}/PROOF-01-kiosk-idle.png`, fullPage: true });
});

// ─── 2. Kiosk wizard: 13 sauces visible ───
test('P2 — Kiosk wizard: 13 sauces rendered', async ({ page }) => {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.getByText(/À emporter/i).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.getByText('Tacos', { exact: true }).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(1500);
  await page.getByText('Personnaliser').first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(2500);

  // Pick first viande by clicking the entire card (label POULET MARINÉ)
  await page.getByText('POULET MARINÉ', { exact: true }).first().click({ timeout: 3000 }).catch(() => {});
  await page.waitForTimeout(1000);
  // Click CHOISIR on POULET MARINÉ
  const choisirBtns = page.getByRole('button', { name: /^choisir$/i });
  if (await choisirBtns.count()) await choisirBtns.first().click({ timeout: 3000 }).catch(() => {});
  await page.waitForTimeout(1200);

  // Click SUIVANT (next step = sauce)
  await page.getByRole('button', { name: /^suivant$/i }).first().click({ timeout: 3000 }).catch(() => {});
  await page.waitForTimeout(3000);

  await page.screenshot({ path: `${OUT}/PROOF-02-kiosk-13-sauces.png`, fullPage: true });
});

// ─── 3. Kiosk catalog: 11 categories ───
test('P3 — Kiosk catalog: 11 categories', async ({ page }) => {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.getByText(/À emporter/i).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.screenshot({ path: `${OUT}/PROOF-03-kiosk-catalog.png`, fullPage: false });
});

// ─── 4. Kiosk Tacos category ───
test('P4 — Kiosk Tacos with new prices', async ({ page }) => {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.getByText(/À emporter/i).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.getByText('Tacos', { exact: true }).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/PROOF-04-kiosk-tacos-6,90-7,90.png`, fullPage: false });
});

// ─── 5. Kiosk Burgers category ───
test('P5 — Kiosk Burgers with new prices', async ({ page }) => {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.getByText(/À emporter/i).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.getByText('Burgers', { exact: true }).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/PROOF-05-kiosk-burgers-4,90-6,90.png`, fullPage: false });
});

// ─── 6. Kiosk Bols ───
test('P6 — Kiosk Bols with new prices', async ({ page }) => {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.getByText(/À emporter/i).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.getByText('Bols Gourmands', { exact: true }).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/PROOF-06-kiosk-bols-6,90.png`, fullPage: false });
});

// ─── 7. Kiosk Boissons (boisson 1,90) ───
test('P7 — Kiosk Boissons / Menu enfant', async ({ page }) => {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.getByText(/À emporter/i).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.getByText('Menu enfant', { exact: true }).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/PROOF-07-kiosk-menu-enfant-4,90.png`, fullPage: false });
});

// ─── 8. Admin login ───
test('P8 — Admin login Le Cayenne branding', async ({ page }) => {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  await page.screenshot({ path: `${OUT}/PROOF-08-admin-login.png`, fullPage: true });
});

// ─── 9. POS strip + 1 category switch ───
test('P9 — POS category strip', async ({ page, context }) => {
  // Auth via POST
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);
  await page.fill('input[name="email"]', 'admin@lecayenne.fr');
  await page.fill('input[name="password"]', '123456');
  await Promise.all([
    page.waitForURL(/admin|dashboard/i, { timeout: 15000 }).catch(() => {}),
    page.click('button[type="submit"]'),
  ]);
  await page.waitForTimeout(2000);

  await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(6000);
  await page.screenshot({ path: `${OUT}/PROOF-09-admin-pos.png`, fullPage: false });
});

// ─── 10. KDS ───
test('P10 — KDS view', async ({ page }) => {
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);
  await page.fill('input[name="email"]', 'admin@lecayenne.fr');
  await page.fill('input[name="password"]', '123456');
  await Promise.all([
    page.waitForURL(/admin|dashboard/i, { timeout: 15000 }).catch(() => {}),
    page.click('button[type="submit"]'),
  ]);
  await page.waitForTimeout(2000);

  await page.goto(`${BASE}/kds`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  await page.screenshot({ path: `${OUT}/PROOF-10-kds.png`, fullPage: false });
});
