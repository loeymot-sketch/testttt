/* Coupon CRUD complete via UI. */
const fs = require('fs');
const path = require('path');
const { BASE, makePage, uiLogin } = require('./lib.cjs');
const OUT = __dirname;
const ready = (page) => page.waitForFunction(() => document.querySelectorAll('.db-card, table').length > 0, null, { timeout: 20000 }).catch(() => {});

(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);
  await page.goto(BASE + '/admin/coupons', { waitUntil: 'networkidle', timeout: 45000 });
  await ready(page);
  await page.getByRole('button', { name: /Ajouter Un Coupon/i }).click();
  await page.waitForTimeout(1200);
  await page.locator('input#name, input[name=name]').first().fill('E2E-AUDIT Coupon');
  await page.locator('input#code, input[name=code]').first().fill('E2EAUDIT11');
  await page.locator('input#discount:not([type=radio]), input[name=discount]').first().fill('5');
  const dp = page.locator('.dp__input, input[type=date]');
  await dp.nth(0).click(); await dp.nth(0).fill('2026-06-11'); await page.keyboard.press('Enter').catch(() => {});
  await page.keyboard.press('Escape');
  await dp.nth(1).click(); await dp.nth(1).fill('2026-06-30'); await page.keyboard.press('Enter').catch(() => {});
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);
  // minimum order + maximum discount
  const min = page.locator('input#minimum_order, input[name=minimum_order]');
  if (await min.count()) await min.first().fill('10'); else {
    // fallback: find by label text proximity
    await page.locator('label:has-text("MONTANT MINIMUM") + input, label:has-text("Montant minimum") ~ input').first().fill('10').catch(async () => {
      const inputs = page.locator('.drawer input[type=text], [class*=sidebar] input[type=text]');
      console.log('fallback inputs count', await inputs.count());
    });
  }
  const maxd = page.locator('input#maximum_discount, input[name=maximum_discount]');
  if (await maxd.count()) await maxd.first().fill('5');
  await page.screenshot({ path: path.join(OUT, '05b-coupon-filled.png') });
  sink.http.length = 0;
  await page.getByRole('button', { name: /Enregistrer|Sauvegarder|Save/i }).last().click();
  await page.waitForTimeout(3000);
  await page.screenshot({ path: path.join(OUT, '05b-coupon-after-save.png') });
  console.log('HTTP:', [...new Set(sink.http)].join(' | ') || 'none>=400');
  let body = await page.evaluate(() => document.body.innerText);
  console.log('created visible:', body.includes('E2EAUDIT11'));

  // DELETE
  await page.goto(BASE + '/admin/coupons', { waitUntil: 'networkidle', timeout: 45000 });
  await ready(page);
  await page.waitForTimeout(1500);
  const row = page.locator('tbody tr', { hasText: 'E2EAUDIT11' }).first();
  if (await row.count()) {
    const btns = row.locator('button, a');
    await btns.last().click();
    await page.waitForTimeout(1200);
    await page.screenshot({ path: path.join(OUT, '05b-coupon-delete-confirm.png') });
    const confirmBtn = page.getByRole('button', { name: /^(Oui|Confirmer|Supprimer|Yes|OK)/i }).last();
    if (await confirmBtn.count()) await confirmBtn.click().catch(() => {});
    await page.waitForTimeout(2500);
    await page.screenshot({ path: path.join(OUT, '05b-coupon-after-delete.png') });
    body = await page.evaluate(() => document.body.innerText);
    console.log('still visible after delete:', body.includes('E2EAUDIT11'));
  } else {
    console.log('row not found for delete');
  }
  await browser.close();
  console.log('COUPON CRUD DONE');
})().catch(e => { console.error('FATAL', e); process.exit(1); });
