/* Phase 4 — full sweep with forced Authorization header. */
const fs = require('fs');
const path = require('path');
const { BASE, makePage, uiLogin } = require('./lib.cjs');
const OUT = __dirname;
const ONLY = process.argv[2] ? process.argv[2].split(',') : null;
const ROUTES = [
  ['coupons', '/admin/coupons'],
  ['offers', '/admin/offers'],
  ['messages', '/admin/messages'],
  ['subscribers', '/admin/subscribers'],
  ['push-notifications', '/admin/push-notifications'],
  ['transactions', '/admin/transactions'],
  ['sales-report', '/admin/sales-report'],
  ['items-report', '/admin/items-report'],
  ['ingredients', '/admin/ingredients'],
  ['delivery-boys', '/admin/delivery-boys'],
  ['delivery-boy-cash-sessions', '/admin/delivery-boy-cash-sessions'],
  ['dining-tables', '/admin/dining-tables'],
  ['employees', '/admin/employees'],
  ['administrators', '/admin/administrators'],
  ['chefs', '/admin/chefs'],
  ['customers', '/admin/customers'],
  ['loyalty-setup', '/admin/settings/loyalty-setup'],
  ['historique', '/admin/historique'],
].filter(([n]) => !ONLY || ONLY.includes(n));

(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);
  console.log('LOGIN OK (forced-auth context)');
  const file = path.join(OUT, 'sweep4-report.json');
  const report = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file)) : {};
  for (const [name, route] of ROUTES) {
    sink.console.length = 0; sink.http.length = 0;
    try {
      await page.goto(BASE + route, { waitUntil: 'networkidle', timeout: 45000 });
    } catch (e) { sink.console.push('[nav] ' + String(e).slice(0, 150)); }
    await page.waitForFunction(
      () => document.querySelectorAll('.db-card, .db-card-title, table, .db-table').length > 0,
      null, { timeout: 20000 }
    ).catch(() => { sink.console.push('[readiness] no .db-card/table within 20s'); });
    await page.waitForTimeout(1500);
    await page.screenshot({ path: path.join(OUT, `04-${name}.png`) });
    const bodyText = await page.evaluate(() => document.body.innerText.slice(0, 6000));
    const rawLabels = (bodyText.match(/\b[a-z_]+\.[a-z_]{3,}\.[a-z_]{3,}\b|Label\.[A-Za-z]+/g) || []).slice(0, 10);
    report[name] = {
      url: page.url().replace(BASE, ''),
      console: sink.console.slice(0, 10),
      http4xx5xx: [...new Set(sink.http)].slice(0, 10),
      rawLabels,
      textSample: bodyText.slice(0, 2200),
    };
    fs.writeFileSync(file, JSON.stringify(report, null, 2));
    console.log(`-- ${name}: console=${report[name].console.length} httpErr=${report[name].http4xx5xx.length} url=${report[name].url}`);
  }
  await browser.close();
  console.log('SWEEP4 DONE');
})().catch(e => { console.error('FATAL', e); process.exit(1); });
