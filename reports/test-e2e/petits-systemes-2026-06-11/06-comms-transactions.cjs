/* Steps 5-8 — messages, subscribers(+export), push modal (NO SEND), transactions filter. */
const { BASE, makePage, uiLogin } = require('./lib.cjs');
const { log, snap, ready, sinkSummary } = require('./06-util.cjs');

(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  const exports = [];
  page.on('response', r => { if (/export|download/i.test(r.url())) exports.push(`${r.status()} ${r.url().slice(0, 120)}`); });
  await uiLogin(page);

  // --- MESSAGES ---
  try {
    await page.goto(BASE + '/admin/messages', { waitUntil: 'networkidle', timeout: 45000 });
    await ready(page); await page.waitForTimeout(1000);
    await snap(page, '06-messages-list');
    sink.http.length = 0; sink.console.length = 0;
    const rows = page.locator('tbody tr');
    const n = await rows.count();
    let detail = 'empty-state';
    if (n > 0) {
      const txt = (await rows.first().innerText()).slice(0, 60);
      const act = rows.first().locator('button, a');
      if (await act.count()) { await act.first().click(); await page.waitForTimeout(1500); await snap(page, '06-messages-detail'); }
      detail = `${n} ligne(s), 1ère=« ${txt.replace(/\n/g, ' ')} », détail ouvert`;
    } else {
      const body = await page.evaluate(() => document.body.innerText);
      detail = 'empty-state: ' + (body.match(/Aucun[^\n]*/)?.[0] || 'aucun texte vide trouvé');
    }
    log('messages-list', 'OK', `${detail} ; ${sinkSummary(sink)}`);
  } catch (e) { log('messages-list', 'FAIL', 'script error: ' + String(e).slice(0, 200)); }

  // --- SUBSCRIBERS ---
  try {
    await page.goto(BASE + '/admin/subscribers', { waitUntil: 'networkidle', timeout: 45000 });
    await ready(page); await page.waitForTimeout(1000);
    await snap(page, '06-subscribers-list');
    sink.http.length = 0; sink.console.length = 0; exports.length = 0;
    const exp = page.getByRole('button', { name: /Exporter|Export/i }).first();
    let expDetail = 'pas de bouton export';
    if (await exp.count()) {
      await exp.click(); await page.waitForTimeout(800);
      await snap(page, '06-subscribers-export-open');
      const item = page.locator('a, button').filter({ hasText: /Excel|CSV|XLSX|Imprimer|Print|PDF/i }).first();
      if (await item.count()) {
        const dl = page.waitForEvent('download', { timeout: 8000 }).catch(() => null);
        await item.click();
        const d = await dl;
        await page.waitForTimeout(2000);
        expDetail = `export cliqué (${(await item.innerText()).trim()}) ; download=${d ? 'reçu' : 'non-intercepté'} ; resp export=[${exports.join(' ; ') || 'aucune url /export'}]`;
      } else expDetail = 'dropdown export ouvert mais aucune option visible';
    }
    log('subscribers-export', sink.http.length === 0 ? 'OK' : 'FAIL', `${expDetail} ; ${sinkSummary(sink)}`);
  } catch (e) { log('subscribers-export', 'FAIL', 'script error: ' + String(e).slice(0, 200)); }

  // --- PUSH NOTIFICATIONS (NO SEND) ---
  try {
    await page.goto(BASE + '/admin/push-notifications', { waitUntil: 'networkidle', timeout: 45000 });
    await ready(page); await page.waitForTimeout(1000);
    sink.http.length = 0; sink.console.length = 0;
    await page.getByRole('button', { name: /Ajouter|Envoyer|Nouvelle|Send/i }).first().click();
    await page.waitForTimeout(1200);
    const ti = page.locator('input#title, input[name=title]').first();
    if (await ti.count()) await ti.fill('E2E-Abuse Push (NE PAS ENVOYER)');
    const desc = page.locator('textarea, input#description').first();
    if (await desc.count()) await desc.fill('test abuse - jamais envoyé');
    await snap(page, '06-push-modal-filled');
    const bodyTxt = await page.evaluate(() => document.body.innerText);
    const hasConfirmHint = /confirm|sûr|attention|tous les abonnés|mass/i.test(bodyTxt);
    // CLOSE WITHOUT SENDING
    const close = page.getByRole('button', { name: /Fermer|Annuler|Close|Cancel/i }).last();
    if (await close.count()) await close.click(); else await page.keyboard.press('Escape');
    await page.waitForTimeout(1000);
    await snap(page, '06-push-modal-closed');
    log('push-modal-no-send', 'OK', `modal rempli puis FERMÉ sans envoi ; garde/confirm visible dans le modal=${hasConfirmHint} ; ${sinkSummary(sink)}`);
  } catch (e) { log('push-modal-no-send', 'FAIL', 'script error: ' + String(e).slice(0, 200)); }

  // --- TRANSACTIONS FILTER ---
  try {
    await page.goto(BASE + '/admin/transactions', { waitUntil: 'networkidle', timeout: 45000 });
    await ready(page); await page.waitForTimeout(1000);
    const before = await page.locator('tbody tr').count();
    await snap(page, '06-transactions-before');
    sink.http.length = 0; sink.console.length = 0;
    await page.getByRole('button', { name: /Filtrer|Filter/i }).first().click();
    await page.waitForTimeout(1000);
    await snap(page, '06-transactions-filter-open');
    const dp = page.locator('.dp__input');
    if (await dp.count()) {
      await dp.nth(0).click(); await dp.nth(0).fill('2026-06-11'); await page.keyboard.press('Enter').catch(() => {}); await page.keyboard.press('Escape');
      if (await dp.count() > 1) { await dp.nth(1).click(); await dp.nth(1).fill('2026-06-11'); await page.keyboard.press('Enter').catch(() => {}); await page.keyboard.press('Escape'); }
    }
    const apply = page.getByRole('button', { name: /Rechercher|Appliquer|Search|Filtrer/i }).last();
    if (await apply.count()) await apply.click();
    await page.waitForTimeout(2500);
    const after = await page.locator('tbody tr').count();
    await snap(page, '06-transactions-filtered');
    log('transactions-filter', sink.console.length === 0 && sink.http.length === 0 ? 'OK' : 'FAIL',
      `lignes avant=${before} après filtre date 2026-06-11=${after} ; ${sinkSummary(sink)}`);
  } catch (e) { log('transactions-filter', 'FAIL', 'script error: ' + String(e).slice(0, 200) + ' ; ' + sinkSummary(sink)); }

  await browser.close();
})().catch(e => { log('comms-transactions', 'FAIL', 'FATAL ' + String(e).slice(0, 200)); process.exit(1); });
