/* Step 4 — employees + chefs create/delete. */
const { BASE, makePage, uiLogin } = require('./lib.cjs');
const { log, snap, deleteRow, ready, sinkSummary } = require('./06-util.cjs');

async function fillCommon(page, name, email, phone) {
  await page.locator('input#name').first().fill(name);
  await page.locator('input#email').first().fill(email);
  await page.locator('input#phone').first().fill(phone);
  await page.locator('input#password').first().fill('Abuse123!');
  await page.locator('input#password_confirmation').first().fill('Abuse123!');
  const cur = page.locator('input#current_branch');
  if (await cur.count()) await cur.check().catch(() => {});
}

(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);

  // --- EMPLOYEES ---
  try {
    await page.goto(BASE + '/admin/employees', { waitUntil: 'networkidle', timeout: 45000 });
    await ready(page); await page.waitForTimeout(800);
    await page.getByRole('button', { name: /Ajouter/i }).first().click();
    await page.waitForTimeout(1200);
    await fillCommon(page, 'E2E-Abuse Employe', 'e2e-abuse@lecayenne.fr', '0699887766');
    // role select (vue-select)
    const sel = page.locator('#role_id');
    if (await sel.count()) {
      await sel.click();
      await page.waitForTimeout(600);
      await page.keyboard.press('ArrowDown');
      await page.keyboard.press('Enter');
      await page.waitForTimeout(300);
    }
    await snap(page, '06-employees-filled');
    sink.http.length = 0; sink.console.length = 0;
    await page.getByRole('button', { name: /Enregistrer|Sauvegarder|Save/i }).last().click();
    await page.waitForTimeout(3000);
    await snap(page, '06-employees-after-save');
    let body = await page.evaluate(() => document.body.innerText);
    let created = body.includes('E2E-Abuse Employe') || body.includes('e2e-abuse@lecayenne.fr');
    log('employees-create', created ? 'OK' : 'FAIL', `visible=${created} ; ${sinkSummary(sink)}`);
    if (created) {
      await page.goto(BASE + '/admin/employees', { waitUntil: 'networkidle' });
      await ready(page); await page.waitForTimeout(1000);
      sink.http.length = 0; sink.console.length = 0;
      const res = await deleteRow(page, 'E2E-Abuse Employe');
      await snap(page, '06-employees-after-delete');
      log('employees-delete', res === 'deleted' ? 'OK' : 'FAIL', `${res} ; ${sinkSummary(sink)}`);
    } else log('employees-delete', 'FAIL', 'skipped: create failed');
  } catch (e) {
    await snap(page, '06-employees-error');
    log('employees-create', 'FAIL', 'script error: ' + String(e).slice(0, 200) + ' ; ' + sinkSummary(sink));
  }

  // --- CHEFS ---
  try {
    await page.goto(BASE + '/admin/chefs', { waitUntil: 'networkidle', timeout: 45000 });
    await ready(page); await page.waitForTimeout(800);
    await page.getByRole('button', { name: /Ajouter/i }).first().click();
    await page.waitForTimeout(1200);
    await fillCommon(page, 'E2E-Abuse Chef', 'e2e-abuse-chef@lecayenne.fr', '0699887767');
    await snap(page, '06-chefs-filled');
    sink.http.length = 0; sink.console.length = 0;
    await page.getByRole('button', { name: /Enregistrer|Sauvegarder|Save/i }).last().click();
    await page.waitForTimeout(3000);
    await snap(page, '06-chefs-after-save');
    let body = await page.evaluate(() => document.body.innerText);
    let created = body.includes('E2E-Abuse Chef');
    log('chefs-create', created ? 'OK' : 'FAIL', `visible=${created} ; ${sinkSummary(sink)}`);
    if (created) {
      await page.goto(BASE + '/admin/chefs', { waitUntil: 'networkidle' });
      await ready(page); await page.waitForTimeout(1000);
      sink.http.length = 0; sink.console.length = 0;
      const res = await deleteRow(page, 'E2E-Abuse Chef');
      await snap(page, '06-chefs-after-delete');
      log('chefs-delete', res === 'deleted' ? 'OK' : 'FAIL', `${res} ; ${sinkSummary(sink)}`);
    } else log('chefs-delete', 'FAIL', 'skipped: create failed');
  } catch (e) {
    await snap(page, '06-chefs-error');
    log('chefs-create', 'FAIL', 'script error: ' + String(e).slice(0, 200) + ' ; ' + sinkSummary(sink));
  }
  await browser.close();
})().catch(e => { log('employees-chefs', 'FAIL', 'FATAL ' + String(e).slice(0, 200)); process.exit(1); });
