const { chromium } = require('playwright');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/mobile/';
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  const errs = [];
  p.on('console', m => { if (m.type()==='error') errs.push(m.text().slice(0,160)); });
  p.on('pageerror', e => errs.push('PAGEERR '+e.message.slice(0,160)));
  await p.goto('http://127.0.0.1:8087/index.html', { waitUntil: 'networkidle' });
  await p.waitForTimeout(800);
  // seed auth + onboarding
  await p.evaluate(() => {
    window.LC.storage.setAuth({ token: 'e2e-visual', phone: '0600000000', user_id: 1 });
    window.LC.storage.markOnboardingSeen();
  });
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(1000);
  const dump = async (tag) => {
    const t = await p.evaluate(() => document.body.innerText.replace(/\n{2,}/g,'\n').slice(0,900));
    console.log('=== '+tag+' ===\n'+t);
  };
  await dump('boot');
  // go to menu: click Menu tab
  const menuTab = p.locator('text=Menu').first();
  if (await menuTab.count()) { await menuTab.click(); await p.waitForTimeout(900); }
  await dump('menu');
  // open Tacos M
  const card = p.locator('[aria-label^="Voir Tacos M"]').first();
  console.log('TacosM card count', await card.count());
  if (await card.count()) { await card.click(); await p.waitForTimeout(1000); }
  await dump('tacosM-open');
  await p.screenshot({ path: OUT+'probe-tacosM.png', fullPage: true });
  console.log('ERRS', errs.slice(0,10));
  await b.close();
})();
