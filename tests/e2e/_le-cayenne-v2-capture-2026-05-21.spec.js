/**
 * Le Cayenne V2 Visual Capture — 2026-05-21
 * Robust round 2.
 */
const { test, expect } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = 'tests/captures/le-cayenne-v2-2026-05-21';

test.use({ viewport: { width: 1366, height: 900 } });
test.setTimeout(60000);

test('kiosk idle — logo + branding', async ({ page }) => {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.screenshot({ path: `${OUT}/01-kiosk-idle.png`, fullPage: true });
});

test('kiosk catalog — categories + items', async ({ page }) => {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);

  // Click "À emporter" — main idle CTA card.
  const cta = page.getByText(/À emporter/i).first();
  if (await cta.count()) {
    await cta.click({ timeout: 5000 }).catch(() => {});
  } else {
    await page.locator('body').click({ position: { x: 683, y: 450 } }).catch(() => {});
  }
  await page.waitForTimeout(3000);
  await page.screenshot({ path: `${OUT}/02-kiosk-catalog.png`, fullPage: false });

  // Iterate through visible category tabs and screenshot each.
  const tabs = ['Sandwich Cayenne', 'Galette', 'Sandwich Classique', 'Burgers', 'Tacos', 'Bols Gourmands', 'Frites', 'Suppléments', 'Desserts', 'Boissons', 'Menu enfant'];
  for (let i = 0; i < tabs.length; i++) {
    const name = tabs[i];
    const tab = page.getByText(name, { exact: true }).first();
    if (await tab.count()) {
      await tab.click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(1500);
      const idx = String(i + 3).padStart(2, '0');
      const slug = name.toLowerCase().replace(/[^a-z]+/g, '-').replace(/^-|-$/g, '');
      await page.screenshot({ path: `${OUT}/${idx}-kiosk-cat-${slug}.png` });
    }
  }
});

test('kiosk wizard — Tacos sauces step', async ({ page }) => {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  const cta = page.getByText(/À emporter/i).first();
  if (await cta.count()) await cta.click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(3000);

  // Go to Tacos category.
  const tacosTab = page.getByText('Tacos', { exact: true }).first();
  if (await tacosTab.count()) {
    await tacosTab.click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(1500);
  }

  // Click first Tacos item.
  const item = page.locator('.kiosk-product, .product-card, .item-card, [data-product]').first();
  if (await item.count()) {
    await item.click({ timeout: 3000 }).catch(() => {});
  } else {
    await page.getByText(/Tacos/i).nth(2).click({ timeout: 3000 }).catch(() => {});
  }
  await page.waitForTimeout(3000);
  await page.screenshot({ path: `${OUT}/15-kiosk-wizard-step1.png`, fullPage: true });

  // Walk through wizard steps capturing each.
  for (let i = 0; i < 7; i++) {
    // Pick any visible option (first card in step grid).
    const opt = page.locator('.wizard-option, .kiosk-step-option, .option-card, [data-step-option]').first();
    if (await opt.count()) await opt.click({ timeout: 2000 }).catch(() => {});
    await page.waitForTimeout(700);

    // Click next/continue.
    const next = page.getByRole('button', { name: /suivant|continuer|next|valider/i }).first();
    if (await next.count()) await next.click({ timeout: 2000 }).catch(() => {});
    await page.waitForTimeout(1500);

    const idx = String(16 + i).padStart(2, '0');
    await page.screenshot({ path: `${OUT}/${idx}-kiosk-wizard-step${i + 2}.png`, fullPage: false });
  }
});

test('admin login page', async ({ page }) => {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  await page.screenshot({ path: `${OUT}/30-admin-login.png`, fullPage: true });
});

test('admin POS', async ({ page }) => {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await page.fill('input[name="email"], input[type="email"]', 'admin@lecayenne.fr').catch(() => {});
  await page.fill('input[name="password"], input[type="password"]', '123456').catch(() => {});
  const submit = page.getByRole('button', { name: /connexion|login|se connecter/i }).first();
  if (await submit.count()) await submit.click({ timeout: 3000 }).catch(() => {});
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  await page.waitForTimeout(2000);

  await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  await page.screenshot({ path: `${OUT}/31-admin-pos.png`, fullPage: true });

  // POS items grid + first category click.
  const firstTab = page.locator('.pos-category-tab, [data-category-tab]').first();
  if (await firstTab.count()) {
    await firstTab.click({ timeout: 2000 }).catch(() => {});
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${OUT}/32-admin-pos-cat.png` });
  }
});
