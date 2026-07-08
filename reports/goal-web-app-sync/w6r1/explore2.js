const { chromium } = require('playwright');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/mobile/';
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  const errs = [];
  p.on('console', m => { if (m.type()==='error') errs.push(m.text()); });
  p.on('pageerror', e => errs.push('PAGEERROR '+e.message));
  await p.goto('http://127.0.0.1:8087/index.html', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  const clickText = async (t) => {
    const el = p.locator(`text=${t}`).first();
    if (await el.count()) { await el.click(); await p.waitForTimeout(700); return true; }
    return false;
  };
  // skip onboarding
  await clickText('Passer');
  await p.waitForTimeout(800);
  const snap = async (tag) => {
    const info = await p.evaluate(() => ({
      inner: document.body.innerText.slice(0,1200),
      testids: [...document.querySelectorAll('[data-testid]')].map(e=>e.getAttribute('data-testid')).slice(0,60)
    }));
    console.log('=== '+tag+' ===');
    console.log(info.inner);
    console.log('TESTIDS:', JSON.stringify(info.testids));
  };
  await snap('after-skip');
  // Try to reach menu
  await clickText('Commencer ma commande');
  await clickText('Commander');
  await p.waitForTimeout(800);
  await snap('menu?');
  console.log('ERRS', errs.slice(0,8));
  await b.close();
})();
