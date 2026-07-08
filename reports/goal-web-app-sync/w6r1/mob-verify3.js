const { chromium } = require('playwright');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/mobile';
const log = (...a) => console.log(...a);
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  await page.addInitScript(() => {
    localStorage.setItem('lecayenne.auth', JSON.stringify({ token: '6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e', phone: '0697222388', user_id: 189 }));
    localStorage.setItem('lecayenne.onboarding_seen', 'true');
  });
  await page.goto('http://127.0.0.1:8087/', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(3500);
  // Home -> profile nav
  await page.evaluate(() => { const n=[...document.querySelectorAll('*')].find(e=>(e.textContent||'').trim()==='PROFIL' && e.children.length<=2); if(n) n.click(); });
  await page.waitForTimeout(1200);
  // click VOIR MON QR
  await page.evaluate(() => { const n=[...document.querySelectorAll('*')].find(e=>/VOIR MON QR/.test(e.textContent||'') && e.children.length<=3); if(n) n.click(); });
  await page.waitForTimeout(2500);
  // click Réductions tab (leaf element)
  const clicked = await page.evaluate(() => { const els=[...document.querySelectorAll('*')].filter(e=>(e.textContent||'').trim()==='Réductions' && e.children.length===0); if(els[0]){els[0].click(); return true;} return false; });
  await page.waitForTimeout(2000);
  await page.screenshot({ path: `${OUT}/v3-reductions.png`, fullPage: true });
  const red = await page.evaluate(() => {
    const b=document.body.innerText;
    return {
      clicked: true,
      redeemValue: document.querySelector('[data-testid="redeem-points-value"]')?.textContent||null,
      hasWizardRedeem: !!document.querySelector('[aria-label*="points"]'),
      mockNames: ['Frites offertes','Boisson offerte','Café offert','Dessert offert','Burger offert','Menu offert'].filter(n=>b.includes(n)),
      continuousModel: /pts?\s*=\s*1\s*€|tranche de|1 € de réduction|minimum \d+ pts/i.test(b),
      snippet: b.slice(0,800)
    };
  });
  log('REDUCTIONS =', JSON.stringify(red,null,2), 'tabClicked=', clicked);
  await browser.close();
})().catch(e=>{console.error('FATAL',e);process.exit(1);});
