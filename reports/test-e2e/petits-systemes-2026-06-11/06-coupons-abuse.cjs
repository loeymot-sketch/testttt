/* Step 9 — ABUSE coupons: dup code, remise négative, dates inversées, cleanup. */
const { BASE, makePage, uiLogin } = require('./lib.cjs');
const { log, snap, deleteRow, ready, sinkSummary } = require('./06-util.cjs');

async function openDrawer(page) {
  await page.getByRole('button', { name: /Ajouter Un Coupon/i }).click();
  await page.waitForTimeout(1200);
}
async function fillCoupon(page, { name, code, discount, start, end }) {
  await page.locator('input#name, input[name=name]').first().fill(name);
  await page.locator('input#code, input[name=code]').first().fill(code);
  await page.locator('input#discount:not([type=radio]), input[name=discount]').first().fill(discount);
  const dp = page.locator('.dp__input');
  await dp.nth(0).click(); await dp.nth(0).fill(start); await page.keyboard.press('Enter').catch(() => {}); await page.keyboard.press('Escape');
  await dp.nth(1).click(); await dp.nth(1).fill(end); await page.keyboard.press('Enter').catch(() => {}); await page.keyboard.press('Escape');
  const min = page.locator('input#minimum_order, input[name=minimum_order]');
  if (await min.count()) await min.first().fill('10');
  const maxd = page.locator('input#maximum_discount, input[name=maximum_discount]');
  if (await maxd.count()) await maxd.first().fill('5');
  await page.waitForTimeout(300);
}
async function save(page, sink) {
  sink.http.length = 0; sink.console.length = 0;
  await page.getByRole('button', { name: /Enregistrer|Sauvegarder|Save/i }).last().click();
  await page.waitForTimeout(3000);
}
async function drawerErrors(page) {
  return page.evaluate(() => {
    const el = document.querySelector('#sidebar, .drawer');
    if (!el) return '';
    return [...el.querySelectorAll('small, .invalid-feedback, .db-field-alert, [class*=error], small.db-field-alert')]
      .map(e => e.innerText.trim()).filter(Boolean).join(' // ').slice(0, 350);
  });
}
async function closeDrawer(page) {
  const c = page.getByRole('button', { name: /Fermer|Annuler|Close/i }).last();
  if (await c.count()) await c.click().catch(() => {});
  await page.waitForTimeout(800);
}

(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);
  await page.goto(BASE + '/admin/coupons', { waitUntil: 'networkidle', timeout: 45000 });
  await ready(page); await page.waitForTimeout(1000);

  // (a) valid create
  try {
    await openDrawer(page);
    await fillCoupon(page, { name: 'E2E Abuse Valide', code: 'E2EABUSE1', discount: '5', start: '2026-06-11', end: '2026-06-30' });
    await save(page, sink);
    await snap(page, '06-coupon-a-valid-after-save');
    const body = await page.evaluate(() => document.body.innerText);
    const created = body.includes('E2EABUSE1');
    log('coupon-abuse-a-create-valide', created ? 'OK' : 'FAIL', `E2EABUSE1 visible=${created} ; ${sinkSummary(sink)}`);
  } catch (e) { log('coupon-abuse-a-create-valide', 'FAIL', 'script error: ' + String(e).slice(0, 180)); }

  // (b) duplicate code
  try {
    await openDrawer(page);
    await fillCoupon(page, { name: 'E2E Abuse Doublon', code: 'E2EABUSE1', discount: '5', start: '2026-06-11', end: '2026-06-30' });
    await save(page, sink);
    await snap(page, '06-coupon-b-duplicate');
    const errs = await drawerErrors(page);
    const got422 = sink.http.some(h => h.startsWith('422'));
    log('coupon-abuse-b-code-duplique', got422 && errs ? 'OK' : 'FAIL',
      `attendu 422 propre ; HTTP=[${sink.http.join(';')}] ; messages=« ${errs || 'AUCUN MESSAGE VISIBLE'} »`);
    await closeDrawer(page);
  } catch (e) { log('coupon-abuse-b-code-duplique', 'FAIL', 'script error: ' + String(e).slice(0, 180)); }

  // (c) negative discount (fill bypasse le filtre keypress)
  try {
    await openDrawer(page);
    await fillCoupon(page, { name: 'E2E Abuse Negatif', code: 'E2EABUSE2', discount: '-5', start: '2026-06-11', end: '2026-06-30' });
    await snap(page, '06-coupon-c-negative-filled');
    await save(page, sink);
    await snap(page, '06-coupon-c-negative');
    const errs = await drawerErrors(page);
    const got422 = sink.http.some(h => h.startsWith('422'));
    const body = await page.evaluate(() => document.body.innerText);
    const wrongCreated = body.includes('E2EABUSE2') && !got422;
    log('coupon-abuse-c-remise-negative', got422 ? 'OK' : (wrongCreated ? 'FAIL-PRODUIT' : 'FAIL'),
      `HTTP=[${sink.http.join(';') || 'aucun'}] ; créé-à-tort=${wrongCreated} ; messages=« ${errs || 'aucun'} »`);
    await closeDrawer(page);
  } catch (e) { log('coupon-abuse-c-remise-negative', 'FAIL', 'script error: ' + String(e).slice(0, 180)); }

  // (d) end before start
  try {
    await openDrawer(page);
    await fillCoupon(page, { name: 'E2E Abuse DatesInv', code: 'E2EABUSE3', discount: '5', start: '2026-06-30', end: '2026-06-11' });
    await save(page, sink);
    await snap(page, '06-coupon-d-dates-inversees');
    const errs = await drawerErrors(page);
    const got422 = sink.http.some(h => h.startsWith('422'));
    const body = await page.evaluate(() => document.body.innerText);
    const wrongCreated = body.includes('E2EABUSE3') && !got422;
    log('coupon-abuse-d-dates-inversees', got422 ? 'OK' : (wrongCreated ? 'FAIL-PRODUIT' : 'FAIL'),
      `HTTP=[${sink.http.join(';') || 'aucun'}] ; créé-à-tort=${wrongCreated} ; messages=« ${errs || 'aucun'} »`);
    await closeDrawer(page);
  } catch (e) { log('coupon-abuse-d-dates-inversees', 'FAIL', 'script error: ' + String(e).slice(0, 180)); }

  // cleanup
  try {
    await page.goto(BASE + '/admin/coupons', { waitUntil: 'networkidle', timeout: 45000 });
    await ready(page); await page.waitForTimeout(1200);
    const body0 = await page.evaluate(() => document.body.innerText);
    const ghosts = ['E2EABUSE2', 'E2EABUSE3'].filter(c => body0.includes(c));
    sink.http.length = 0; sink.console.length = 0;
    const res = await deleteRow(page, 'E2EABUSE1');
    await snap(page, '06-coupon-cleanup');
    log('coupon-abuse-cleanup', res === 'deleted' && ghosts.length === 0 ? 'OK' : 'FAIL',
      `delete E2EABUSE1=${res} ; coupons fantômes (auraient dû être rejetés)=[${ghosts.join(',') || 'aucun'}] ; ${sinkSummary(sink)}`);
  } catch (e) { log('coupon-abuse-cleanup', 'FAIL', 'script error: ' + String(e).slice(0, 180)); }

  await browser.close();
})().catch(e => { log('coupons-abuse', 'FAIL', 'FATAL ' + String(e).slice(0, 200)); process.exit(1); });
