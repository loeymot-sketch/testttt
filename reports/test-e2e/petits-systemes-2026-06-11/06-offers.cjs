/* Step 1 — offers CRUD via UI. */
const path = require('path');
const { BASE, makePage, uiLogin } = require('./lib.cjs');
const { OUT, log, snap, deleteRow, ready, sinkSummary } = require('./06-util.cjs');
const NAME = 'E2E-Abuse Offre';

(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);
  await page.goto(BASE + '/admin/offers', { waitUntil: 'networkidle', timeout: 45000 });
  await ready(page);
  await snap(page, '06-offers-before');

  // CREATE
  try {
    await page.getByRole('button', { name: /Ajouter/i }).first().click();
    await page.waitForTimeout(1200);
    await page.locator('input#name').first().fill(NAME);
    await page.locator('input#amount').first().fill('10');
    const dp = page.locator('.dp__input');
    await dp.nth(0).click(); await dp.nth(0).fill('2026-06-11 08:00'); await page.keyboard.press('Enter').catch(() => {}); await page.keyboard.press('Escape');
    await dp.nth(1).click(); await dp.nth(1).fill('2026-06-30 23:00'); await page.keyboard.press('Enter').catch(() => {}); await page.keyboard.press('Escape');
    await page.locator('input#image').setInputFiles(path.join(OUT, '06-fixture.png'));
    await page.waitForTimeout(400);
    await snap(page, '06-offers-filled');
    sink.http.length = 0; sink.console.length = 0;
    await page.getByRole('button', { name: /Enregistrer|Sauvegarder|Save/i }).last().click();
    await page.waitForTimeout(3000);
    await snap(page, '06-offers-after-save');
    const body = await page.evaluate(() => document.body.innerText);
    const created = body.includes(NAME);
    log('offers-create', created ? 'OK' : 'FAIL', `ligne visible=${created} ; ${sinkSummary(sink)}`);
  } catch (e) {
    await snap(page, '06-offers-create-error');
    log('offers-create', 'FAIL', `script error: ${String(e).slice(0, 200)} ; ${sinkSummary(sink)}`);
  }

  // DELETE
  try {
    await page.goto(BASE + '/admin/offers', { waitUntil: 'networkidle', timeout: 45000 });
    await ready(page); await page.waitForTimeout(1200);
    sink.http.length = 0; sink.console.length = 0;
    const res = await deleteRow(page, NAME);
    await snap(page, '06-offers-after-delete');
    log('offers-delete', res === 'deleted' ? 'OK' : 'FAIL', `${res} ; ${sinkSummary(sink)}`);
  } catch (e) {
    log('offers-delete', 'FAIL', `script error: ${String(e).slice(0, 200)}`);
  }
  await browser.close();
})().catch(e => { log('offers', 'FAIL', 'FATAL ' + String(e).slice(0, 200)); process.exit(1); });
