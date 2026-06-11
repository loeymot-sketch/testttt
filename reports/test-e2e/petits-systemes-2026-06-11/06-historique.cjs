/* Step 10 — historique : filtre date du jour + détail + cohérence totaux. */
const { BASE, makePage, uiLogin } = require('./lib.cjs');
const { log, snap, ready, sinkSummary } = require('./06-util.cjs');
(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);
  await page.goto(BASE + '/admin/historique', { waitUntil: 'networkidle', timeout: 45000 });
  await ready(page); await page.waitForTimeout(1200);
  await snap(page, '06-historique-list');

  // filtre date du jour si panneau Filtrer présent
  try {
    sink.http.length = 0; sink.console.length = 0;
    const filt = page.getByRole('button', { name: /Filtrer|Filter/i }).first();
    let filtered = 'pas de bouton Filtrer';
    if (await filt.count()) {
      await filt.click(); await page.waitForTimeout(900);
      const dp = page.locator('.dp__input').first();
      if (await dp.count()) {
        await dp.click(); await page.waitForTimeout(700);
        const today = page.locator('.dp__calendar_item .dp__cell_inner.dp__today').first();
        if (await today.count()) {
          await today.click(); await page.waitForTimeout(300);
          // si range, 2e clic
          await today.click().catch(() => {});
          await page.waitForTimeout(500);
          await page.keyboard.press('Escape').catch(() => {});
        }
      }
      const apply = page.getByRole('button', { name: /Rechercher|Appliquer|Search/i }).first();
      if (await apply.count()) await apply.click();
      await page.waitForTimeout(2500);
      filtered = 'filtre appliqué';
    }
    await snap(page, '06-historique-filtered');
    const rows = await page.locator('tbody tr').count();
    log('historique-filtre-jour', sink.console.length === 0 && sink.http.length === 0 ? 'OK' : 'FAIL',
      `${filtered} ; lignes=${rows} ; ${sinkSummary(sink)}`);
  } catch (e) { log('historique-filtre-jour', 'FAIL', 'script error: ' + String(e).slice(0, 180)); }

  // détail + cohérence totaux
  try {
    sink.http.length = 0; sink.console.length = 0;
    const row = page.locator('tbody tr').first();
    if (!(await row.count())) { log('historique-detail-totaux', 'FAIL', 'aucune ligne après filtre'); }
    else {
      await row.locator('button, a').first().click();
      await page.waitForTimeout(2200);
      await snap(page, '06-historique-detail');
      const txt = await page.evaluate(() => document.body.innerText);
      const num = (re) => { const m = txt.match(re); return m ? parseFloat(m[1].replace(/\s/g, '').replace(',', '.')) : null; };
      const sub = num(/Sous[- ]total\s*:?\s*([\d\s]+[.,]\d{2})/i);
      const disc = num(/Remise[^:\n]*:?\s*-?\s*([\d\s]+[.,]\d{2})/i) || 0;
      const tot = num(/Total\s*:?\s*([\d\s]+[.,]\d{2})/i);
      let verdict = 'FAIL', detail;
      if (sub !== null && tot !== null) {
        const calc = Math.round((sub - disc) * 100) / 100;
        const ok = Math.abs(calc - tot) < 0.01;
        verdict = ok ? 'OK' : 'FAIL-PRODUIT';
        detail = `sous-total=${sub} remise=${disc} total affiché=${tot} calc=${calc} cohérent=${ok}`;
      } else detail = `montants non extraits (sub=${sub}, tot=${tot}) — voir capture`;
      log('historique-detail-totaux', verdict, `${detail} ; ${sinkSummary(sink)}`);
    }
  } catch (e) { log('historique-detail-totaux', 'FAIL', 'script error: ' + String(e).slice(0, 180)); }
  await browser.close();
})().catch(e => { log('historique', 'FAIL', 'FATAL ' + String(e).slice(0, 200)); process.exit(1); });
