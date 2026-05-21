/**
 * Wave Y-D — POS Vanilla JS wizard popup (AUDIT-ONLY).
 *
 * Trimmed scope. Goal: capture POS catalog + wizard popup if reachable.
 */
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = 'reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/captures/wave-D';

if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

test.use({ viewport: { width: 1366, height: 900 } });
test.setTimeout(60_000);

async function shoot(page, idx, label) {
  const base = path.join(OUT, `${String(idx).padStart(3, '0')}-${label}`);
  await page.screenshot({ path: `${base}-full.png`, fullPage: true }).catch(() => {});
  await page.screenshot({ path: `${base}-viewport.png`, fullPage: false }).catch(() => {});
}

test('P1 POS — login + capture catalog + wizard if available', async ({ page }) => {
  const consoleLog = path.join(OUT, '_console-P1b-pos.log');
  fs.writeFileSync(consoleLog, '');
  page.on('console', (m) => {
    try { fs.appendFileSync(consoleLog, `[${m.type()}] ${m.text()}\n`); } catch (e) {}
  });
  page.on('pageerror', (e) => {
    try { fs.appendFileSync(consoleLog, `[pageerror] ${e.message}\n`); } catch (e2) {}
  });

  // Login
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 20000 });
  await page.waitForTimeout(1500);
  await page.fill('input[name="email"]', 'admin@lecayenne.fr').catch(() => {});
  await page.fill('input[name="password"]', '123456').catch(() => {});
  await Promise.all([
    page.waitForURL(/admin|dashboard|home/i, { timeout: 15000 }).catch(() => {}),
    page.click('button[type="submit"]').catch(() => {}),
  ]);
  await page.waitForTimeout(2500);
  await shoot(page, 500, 'P1b-00-after-login');

  // Try POS V4 first (the one with vanilla wizard popup)
  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(4500);
  await shoot(page, 501, 'P1b-01-pos-v4');

  // Probe DOM
  const probe = await page.evaluate(() => {
    return {
      url: location.href,
      title: document.title,
      hasWizardPopup: !!document.querySelector('.pos-wizard, #pos-wizard, .pos-wizard-modal, .wizard-popup'),
      hasItemCards: document.querySelectorAll('.pos-item-card, .product-card, [data-item-id]').length,
      hasCategoryStrip: document.querySelectorAll('.pos-category, .category-tab, [data-category-id]').length,
      bodyClasses: document.body.className,
      bodyText: (document.body.innerText || '').slice(0, 500),
    };
  });
  console.log('P1b PROBE:', JSON.stringify(probe));

  // Try /admin/pos as fallback
  if (!/pos/.test(probe.url)) {
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(4500);
    await shoot(page, 502, 'P1b-02-pos-fallback');
  }

  // Try clicking a Sandwich Cayenne item to trigger wizard popup
  const item = page.getByText(/Sandwich Cayenne/i).first();
  if (await item.isVisible().catch(() => false)) {
    await item.click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(2500);
    await shoot(page, 503, 'P1b-03-after-item-click');
  }

  // Final state capture
  await shoot(page, 510, 'P1b-99-final');
});
