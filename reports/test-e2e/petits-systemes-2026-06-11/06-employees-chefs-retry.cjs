/* Step 4 retry — password >=12 + vrai pick du rôle (vue-next-select). */
const { BASE, makePage, uiLogin } = require('./lib.cjs');
const { log, snap, deleteRow, ready, sinkSummary } = require('./06-util.cjs');
const PASS = 'AbuseE2E-2026-Pass!';

(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);

  // --- EMPLOYEES ---
  try {
    await page.goto(BASE + '/admin/employees', { waitUntil: 'networkidle', timeout: 45000 });
    await ready(page); await page.waitForTimeout(800);
    await page.getByRole('button', { name: /Ajouter/i }).first().click();
    await page.waitForTimeout(1200);
    await page.locator('input#name').first().fill('E2E-Abuse Employe');
    await page.locator('input#email').first().fill('e2e-abuse@lecayenne.fr');
    await page.locator('input#phone').first().fill('0699887766');
    await page.locator('input#password').first().fill(PASS);
    await page.locator('input#password_confirmation').first().fill(PASS);
    const cur = page.locator('input#current_branch');
    if (await cur.count()) await cur.check().catch(() => {});
    await page.locator('#role_id').click();
    await page.waitForTimeout(800);
    const opt = page.locator('#role_id li, .vue-dropdown li, li.vue-dropdown-item').first();
    if (await opt.count()) { await opt.click(); } else {
      await page.keyboard.press('ArrowDown'); await page.keyboard.press('Enter');
    }
    await page.waitForTimeout(400);
    await snap(page, '06-employees-retry-filled');
    sink.http.length = 0; sink.console.length = 0;
    await page.getByRole('button', { name: /Enregistrer|Sauvegarder|Save/i }).last().click();
    await page.waitForTimeout(3000);
    await snap(page, '06-employees-retry-after-save');
    let body = await page.evaluate(() => document.body.innerText);
    let created = body.includes('E2E-Abuse Employe') || body.includes('e2e-abuse@lecayenne.fr');
    log('employees-create-retry', created ? 'OK' : 'FAIL', `visible=${created} ; ${sinkSummary(sink)}`);
    if (created) {
      await page.goto(BASE + '/admin/employees', { waitUntil: 'networkidle' });
      await ready(page); await page.waitForTimeout(1000);
      sink.http.length = 0; sink.console.length = 0;
      const res = await deleteRow(page, 'E2E-Abuse Employe');
      await snap(page, '06-employees-after-delete');
      log('employees-delete', res === 'deleted' ? 'OK' : 'FAIL', `${res} ; ${sinkSummary(sink)}`);
    } else log('employees-delete', 'FAIL', 'skipped: create failed (retry)');
  } catch (e) {
    await snap(page, '06-employees-retry-error');
    log('employees-create-retry', 'FAIL', 'script error: ' + String(e).slice(0, 200) + ' ; ' + sinkSummary(sink));
  }

  // --- CHEFS ---
  try {
    await page.goto(BASE + '/admin/chefs', { waitUntil: 'networkidle', timeout: 45000 });
    await ready(page); await page.waitForTimeout(800);
    await page.getByRole('button', { name: /Ajouter/i }).first().click();
    await page.waitForTimeout(1200);
    await page.locator('input#name').first().fill('E2E-Abuse Chef');
    await page.locator('input#email').first().fill('e2e-abuse-chef@lecayenne.fr');
    await page.locator('input#phone').first().fill('0699887767');
    await page.locator('input#password').first().fill(PASS);
    await page.locator('input#password_confirmation').first().fill(PASS);
    const cur2 = page.locator('input#current_branch');
    if (await cur2.count()) await cur2.check().catch(() => {});
    await snap(page, '06-chefs-retry-filled');
    sink.http.length = 0; sink.console.length = 0;
    await page.getByRole('button', { name: /Enregistrer|Sauvegarder|Save/i }).last().click();
    await page.waitForTimeout(3000);
    await snap(page, '06-chefs-retry-after-save');
    let body = await page.evaluate(() => document.body.innerText);
    let created = body.includes('E2E-Abuse Chef');
    log('chefs-create-retry', created ? 'OK' : 'FAIL', `visible=${created} ; ${sinkSummary(sink)}`);
    if (created) {
      await page.goto(BASE + '/admin/chefs', { waitUntil: 'networkidle' });
      await ready(page); await page.waitForTimeout(1000);
      sink.http.length = 0; sink.console.length = 0;
      const res = await deleteRow(page, 'E2E-Abuse Chef');
      await snap(page, '06-chefs-after-delete');
      log('chefs-delete', res === 'deleted' ? 'OK' : 'FAIL', `${res} ; ${sinkSummary(sink)}`);
    } else log('chefs-delete', 'FAIL', 'skipped: create failed (retry)');
  } catch (e) {
    await snap(page, '06-chefs-retry-error');
    log('chefs-create-retry', 'FAIL', 'script error: ' + String(e).slice(0, 200) + ' ; ' + sinkSummary(sink));
  }
  await browser.close();
})().catch(e => { log('employees-chefs-retry', 'FAIL', 'FATAL ' + String(e).slice(0, 200)); process.exit(1); });
