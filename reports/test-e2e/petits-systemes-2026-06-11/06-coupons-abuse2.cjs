/* Step 9 retry — ABUSE coupons via calendar clicks + reload entre tentatives. */
const { BASE, makePage, uiLogin } = require('./lib.cjs');
const { log, snap, deleteRow, ready, sinkSummary } = require('./06-util.cjs');

async function gotoCoupons(page) {
  await page.goto(BASE + '/admin/coupons', { waitUntil: 'networkidle', timeout: 45000 });
  await ready(page); await page.waitForTimeout(1000);
}
async function pickDate(page, idx, dayText) {
  const dp = page.locator('.dp__input');
  await dp.nth(idx).click();
  await page.waitForTimeout(700);
  const cell = page.locator(`.dp__calendar_item .dp__cell_inner:not(.dp__cell_offset)`, { hasText: new RegExp(`^${dayText}$`) }).first();
  await cell.click();
  await page.waitForTimeout(600);
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(300);
}
async function fillCoupon(page, { name, code, discount, startDay, endDay }) {
  await page.getByRole('button', { name: /Ajouter Un Coupon/i }).click();
  await page.waitForTimeout(1200);
  await page.locator('input#name, input[name=name]').first().fill(name);
  await page.locator('input#code, input[name=code]').first().fill(code);
  await page.locator('input#discount:not([type=radio]), input[name=discount]').first().fill(discount);
  await pickDate(page, 0, startDay);
  await pickDate(page, 1, endDay);
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
const drawerErrors = (page) => page.evaluate(() => {
  const el = document.querySelector('#sidebar, .drawer') || document.body;
  return [...el.querySelectorAll('small, [class*=alert], [class*=error], .text-red-500, .text-danger')]
    .map(e => e.innerText.trim()).filter(t => t && t.length < 140).join(' // ').slice(0, 350);
});

(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);

  // (a) valid
  try {
    await gotoCoupons(page);
    await fillCoupon(page, { name: 'E2E Abuse Valide', code: 'E2EABUSE1', discount: '5', startDay: '11', endDay: '30' });
    await snap(page, '06-coupon-a2-filled');
    await save(page, sink);
    await snap(page, '06-coupon-a2-after-save');
    const body = await page.evaluate(() => document.body.innerText);
    const created = body.includes('E2EABUSE1');
    log('coupon-abuse-a-create-valide-retry', created ? 'OK' : 'FAIL', `E2EABUSE1 visible=${created} ; ${sinkSummary(sink)}`);
  } catch (e) { log('coupon-abuse-a-create-valide-retry', 'FAIL', 'script error: ' + String(e).slice(0, 180)); }

  // (b) duplicate code
  try {
    await gotoCoupons(page);
    await fillCoupon(page, { name: 'E2E Abuse Doublon', code: 'E2EABUSE1', discount: '5', startDay: '11', endDay: '30' });
    await save(page, sink);
    await snap(page, '06-coupon-b2-duplicate');
    const errs = await drawerErrors(page);
    const got422 = sink.http.some(h => h.startsWith('422'));
    log('coupon-abuse-b-code-duplique', got422 ? 'OK' : 'FAIL',
      `HTTP=[${sink.http.join(';') || 'aucun'}] ; messages visibles=« ${errs || 'AUCUN'} »`);
  } catch (e) { log('coupon-abuse-b-code-duplique', 'FAIL', 'script error: ' + String(e).slice(0, 180)); }

  // (c) negative discount
  try {
    await gotoCoupons(page);
    await fillCoupon(page, { name: 'E2E Abuse Negatif', code: 'E2EABUSE2', discount: '-5', startDay: '11', endDay: '30' });
    await snap(page, '06-coupon-c2-negative-filled');
    await save(page, sink);
    await snap(page, '06-coupon-c2-negative');
    const errs = await drawerErrors(page);
    const got422 = sink.http.some(h => h.startsWith('422'));
    const body = await page.evaluate(() => document.body.innerText);
    const wrongCreated = !got422 && body.includes('E2EABUSE2');
    log('coupon-abuse-c-remise-negative', got422 ? 'OK' : (wrongCreated ? 'FAIL-PRODUIT' : 'FAIL'),
      `HTTP=[${sink.http.join(';') || 'aucun'}] ; créé-à-tort=${wrongCreated} ; messages=« ${errs || 'aucun'} »`);
  } catch (e) { log('coupon-abuse-c-remise-negative', 'FAIL', 'script error: ' + String(e).slice(0, 180)); }

  // (d) end before start (start=30, end=11)
  try {
    await gotoCoupons(page);
    await fillCoupon(page, { name: 'E2E Abuse DatesInv', code: 'E2EABUSE3', discount: '5', startDay: '30', endDay: '11' });
    await snap(page, '06-coupon-d2-filled');
    await save(page, sink);
    await snap(page, '06-coupon-d2-dates-inversees');
    const errs = await drawerErrors(page);
    const got422 = sink.http.some(h => h.startsWith('422'));
    const body = await page.evaluate(() => document.body.innerText);
    const wrongCreated = !got422 && body.includes('E2EABUSE3');
    log('coupon-abuse-d-dates-inversees', got422 ? 'OK' : (wrongCreated ? 'FAIL-PRODUIT' : 'FAIL'),
      `HTTP=[${sink.http.join(';') || 'aucun'}] ; créé-à-tort=${wrongCreated} ; messages=« ${errs || 'aucun'} »`);
  } catch (e) { log('coupon-abuse-d-dates-inversees', 'FAIL', 'script error: ' + String(e).slice(0, 180)); }

  // cleanup
  try {
    await gotoCoupons(page);
    const body0 = await page.evaluate(() => document.body.innerText);
    const ghosts = ['E2EABUSE2', 'E2EABUSE3'].filter(c => body0.includes(c));
    sink.http.length = 0; sink.console.length = 0;
    const res = await deleteRow(page, 'E2EABUSE1');
    await snap(page, '06-coupon-cleanup2');
    log('coupon-abuse-cleanup', res === 'deleted' && ghosts.length === 0 ? 'OK' : 'FAIL',
      `delete E2EABUSE1=${res} ; fantômes=[${ghosts.join(',') || 'aucun'}] ; ${sinkSummary(sink)}`);
  } catch (e) { log('coupon-abuse-cleanup', 'FAIL', 'script error: ' + String(e).slice(0, 180)); }

  await browser.close();
})().catch(e => { log('coupons-abuse2', 'FAIL', 'FATAL ' + String(e).slice(0, 200)); process.exit(1); });
