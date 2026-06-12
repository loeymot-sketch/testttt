const { BASE, makePage, uiLogin } = require('./lib.cjs');
const { log, snap, ready, sinkSummary } = require('./06-util.cjs');
(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);
  await page.goto(BASE + '/admin/historique', { waitUntil: 'networkidle', timeout: 45000 });
  await ready(page); await page.waitForTimeout(1500);
  const row = page.locator('tbody tr').first();
  const links = row.locator('a');
  const nLinks = await links.count();
  let clicked = false;
  for (let i = 0; i < nLinks; i++) {
    const href = await links.nth(i).getAttribute('href');
    if (href && /order|historique|show|\d+/.test(href)) { await links.nth(i).click(); clicked = true; break; }
  }
  if (!clicked) {
    // fallback : bouton "Voir" / oeil dans la dernière cellule
    const btn = row.locator('td').last().locator('button, a').first();
    await btn.click();
  }
  await page.waitForTimeout(2500);
  await snap(page, '06-historique-detail2');
  const txt = await page.evaluate(() => document.body.innerText);
  const num = (re) => { const m = txt.match(re); return m ? parseFloat(m[1].replace(/\s/g, '').replace(',', '.')) : null; };
  const sub = num(/Sous[- ]Total\s*\n?\s*([\d\s]+[.,]\d{2})/i);
  const disc = num(/Remise\s*\n?\s*-?\s*([\d\s]+[.,]\d{2})/i) || 0;
  const tot = num(/\nTotal\s*\n?\s*([\d\s]+[.,]\d{2})/i);
  const ncmd = (txt.match(/N° Commande\s*:?\s*(#?\S+)/i) || [])[1] || '?';
  if (sub !== null && tot !== null) {
    const calc = Math.round((sub - disc) * 100) / 100;
    const ok = Math.abs(calc - tot) < 0.01;
    log('historique-detail-totaux-retry', ok ? 'OK' : 'FAIL-PRODUIT',
      `commande ${ncmd} : sous-total=${sub} − remise=${disc} = ${calc} vs total affiché=${tot} → cohérent=${ok} ; ${sinkSummary(sink)}`);
  } else {
    log('historique-detail-totaux-retry', 'FAIL', `montants non extraits (sub=${sub}, disc=${disc}, tot=${tot}) commande=${ncmd} ; ${sinkSummary(sink)}`);
  }
  await browser.close();
})().catch(e => { log('historique-detail-totaux-retry', 'FAIL', 'FATAL ' + String(e).slice(0, 200)); process.exit(1); });
