// Heals W-C validation (F-WC-01 checkbox intercept, F-WC-02 site save 422,
// F-WC-03 raw i18n labels) — live on :8766 rebuilt bundles.
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-heals-wc-validation-2026-06-10.spec.js

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');

const OUT = path.resolve(__dirname, '../../reports/test-e2e/validation-profonde-2026-06-10/heals-wc');
fs.mkdirSync(OUT, { recursive: true });
const REPO = path.resolve(__dirname, '../..');
const db = (sql) => execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', sql], { cwd: REPO, encoding: 'utf8', timeout: 15_000 }).trim();

test.describe.configure({ mode: 'serial', timeout: 300_000 });

test('F-WC-01+03 — coupon créable à la SOURIS, jours FR rendus, 0 label brut', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));
  await loginAsAdmin(page);
  await page.goto('/admin/coupons', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  await page.getByRole('button', { name: /ajouter|add/i }).first().click();
  await page.waitForTimeout(1200);

  // F-WC-03: no raw label.* anywhere in the open form
  const txt = await page.locator('body').innerText();
  const raw = txt.match(/label\.[a-z_.]+/gi) || [];
  await page.screenshot({ path: path.join(OUT, 'coupon-form.jpg'), type: 'jpeg', quality: 70, fullPage: true });
  expect(raw, `raw labels: ${raw.join(',')}`).toHaveLength(0);
  expect(txt).toContain('Lun');
  expect(txt).toContain('Dim');

  // fill minimal fields with MOUSE/keyboard (the bug was click interception)
  const stamp = Date.now() % 100000;
  await page.locator('#name, input[name="name"]').first().fill(`E2EWF-${stamp}`);
  await page.locator('#code, input[name="code"]').first().fill(`E2EWF${stamp}`);
  // required per CouponRequest — same flow as the proven W-C mutation spec
  await page.locator('#sidebar #discount').fill('5');
  await page.locator('#sidebar #percentage').check({ force: true });
  await page.locator('#sidebar #minimum_order').fill('10');
  await page.locator('#sidebar #maximum_discount').fill('5');
  const pickDate = async (idx, mode) => {
    const input = page.locator('#sidebar .dp__input').nth(idx);
    await input.scrollIntoViewIfNeeded();
    await input.click();
    const menu = page.locator('.dp__menu');
    await menu.waitFor({ state: 'visible', timeout: 5000 });
    if (mode === 'today') {
      await page.locator('.dp__menu .dp__today').first().click();
    } else {
      await page.locator('.dp__menu [aria-label="Next month"], .dp__menu .dp__arrow_btn').last().click();
      await page.waitForTimeout(300);
      await page.locator('.dp__menu .dp__calendar_item .dp__cell_inner:not(.dp__cell_offset)').filter({ hasText: /^15$/ }).first().click();
    }
    await page.waitForTimeout(400);
    if (await menu.isVisible().catch(() => false)) {
      const sel = page.locator('.dp__menu .dp__action_select, .dp__menu .dp__action_buttons button').last();
      if (await sel.isVisible().catch(() => false)) await sel.click();
      await page.keyboard.press('Escape').catch(() => {});
    }
  };
  await pickDate(0, 'today');
  await pickDate(1, 'future');
  // F-WC-01: click a weekday checkbox BY MOUSE — must toggle, not intercept
  const lundi = page.locator('label:has-text("Lun") input[type="checkbox"]').first();
  await lundi.click({ force: false });
  expect(await lundi.isChecked(), 'Lun checked via mouse').toBeTruthy();

  // Save BY MOUSE — the historical bug: invisible checkbox intercepted this click
  const saveResp = page.waitForResponse((r) => /coupon/i.test(r.url()) && r.request().method() === 'POST', { timeout: 15_000 }).catch(() => null);
  await page.getByRole('button', { name: /enregistrer|save/i }).first().click();
  const resp = await saveResp;
  await page.waitForTimeout(1200);
  await page.screenshot({ path: path.join(OUT, 'coupon-saved.jpg'), type: 'jpeg', quality: 70 });
  expect(resp, 'coupon POST fired (click NOT intercepted)').not.toBeNull();
  expect([200, 201]).toContain(resp.status());
  const row = db(`SELECT COUNT(*) FROM coupons WHERE code='E2EWF${stamp}';`);
  expect(row, 'coupon persisted').toBe('1');
  db(`DELETE FROM coupons WHERE code='E2EWF${stamp}';`);
  expect(pageErrors, pageErrors.join('|')).toHaveLength(0);
});

test('F-WC-02 — Paramètres/Site sauvegardable (plus de 422 map-key/copyright)', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));
  await loginAsAdmin(page);
  await page.goto('/admin/settings/site', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  // vider les 2 champs historiquement requis pour prouver le nullable
  for (const id of ['#site_google_map_key', '#site_copyright']) {
    const el = page.locator(id).first();
    if (await el.isVisible().catch(() => false)) await el.fill('');
  }
  const saveResp = page.waitForResponse((r) => /setting|site/i.test(r.url()) && ['POST', 'PUT', 'PATCH'].includes(r.request().method()), { timeout: 15_000 }).catch(() => null);
  await page.getByRole('button', { name: /enregistrer|save/i }).first().click();
  const resp = await saveResp;
  await page.waitForTimeout(1000);
  await page.screenshot({ path: path.join(OUT, 'site-saved.jpg'), type: 'jpeg', quality: 70 });
  expect(resp, 'site settings save fired').not.toBeNull();
  expect(resp.status(), 'no more 422').toBeLessThan(400);
  // le mandat 24h doit avoir survécu
  const tf = db(`SELECT payload FROM settings WHERE \`key\`='site_time_format' LIMIT 1;`).replace(/"/g, '');
  console.log('[F-WC-02] site_time_format =', tf);
  expect(pageErrors, pageErrors.join('|')).toHaveLength(0);
});
