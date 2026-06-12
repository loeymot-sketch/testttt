/* Steps 2-3 — ingredients (probe: page is a read-only availability list, no create UI) + dining-tables CRUD. */
const { BASE, makePage, uiLogin } = require('./lib.cjs');
const { log, snap, deleteRow, ready, sinkSummary } = require('./06-util.cjs');

(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);

  // --- INGREDIENTS ---
  try {
    await page.goto(BASE + '/admin/ingredients', { waitUntil: 'networkidle', timeout: 45000 });
    await ready(page); await page.waitForTimeout(1000);
    await snap(page, '06-ingredients-list');
    const btnTexts = await page.evaluate(() =>
      [...document.querySelectorAll('main button, .db-card button')].map(b => b.innerText.trim()).filter(Boolean).slice(0, 20));
    const hasCreate = btnTexts.some(t => /ajouter|add|cr[ée]er/i.test(t));
    // open first usage/detail drawer if any row button exists
    sink.http.length = 0; sink.console.length = 0;
    const rowBtn = page.locator('tbody tr').first().locator('button');
    if (await rowBtn.count()) {
      await rowBtn.first().click().catch(() => {});
      await page.waitForTimeout(1500);
      await snap(page, '06-ingredients-drawer');
      await page.keyboard.press('Escape');
    }
    log('ingredients-probe', 'OK', `create UI présent=${hasCreate} (page = liste dispo/usage, pas de CRUD ingrédient) ; boutons=[${btnTexts.slice(0,6).join(', ')}] ; ${sinkSummary(sink)}`);
  } catch (e) {
    log('ingredients-probe', 'FAIL', 'script error: ' + String(e).slice(0, 200));
  }

  // --- DINING TABLES ---
  try {
    await page.goto(BASE + '/admin/dining-tables', { waitUntil: 'networkidle', timeout: 45000 });
    await ready(page); await page.waitForTimeout(800);
    await snap(page, '06-dining-tables-before');
    await page.getByRole('button', { name: /Ajouter/i }).first().click();
    await page.waitForTimeout(1200);
    await page.locator('input#name').first().fill('E2E-T99');
    await page.locator('input#size').first().fill('4');
    await snap(page, '06-dining-tables-filled');
    sink.http.length = 0; sink.console.length = 0;
    await page.getByRole('button', { name: /Enregistrer|Sauvegarder|Save/i }).last().click();
    await page.waitForTimeout(2500);
    await snap(page, '06-dining-tables-after-save');
    const body = await page.evaluate(() => document.body.innerText);
    const created = body.includes('E2E-T99');
    log('dining-tables-create', created ? 'OK' : 'FAIL', `ligne visible=${created} ; ${sinkSummary(sink)}`);

    if (created) {
      sink.http.length = 0; sink.console.length = 0;
      const res = await deleteRow(page, 'E2E-T99');
      await snap(page, '06-dining-tables-after-delete');
      log('dining-tables-delete', res === 'deleted' ? 'OK' : 'FAIL', `${res} ; ${sinkSummary(sink)}`);
    } else {
      log('dining-tables-delete', 'FAIL', 'skipped: create failed');
    }
  } catch (e) {
    await snap(page, '06-dining-tables-error');
    log('dining-tables-create', 'FAIL', 'script error: ' + String(e).slice(0, 200) + ' ; ' + sinkSummary(sink));
  }
  await browser.close();
})().catch(e => { log('ingredients-tables', 'FAIL', 'FATAL ' + String(e).slice(0, 200)); process.exit(1); });
