const { chromium } = require('playwright');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/mobile/';
const TARGETS = [
  { slug: 'tacos-m',    name: 'Tacos M',    file: 'tacosM' },
  { slug: 'cayenne',    name: 'Cayenne',    file: 'cayenne' },
  { slug: 'chicken-burger', name: 'Chicken Burger', file: 'burger' },
  { slug: 'bol-riz',    name: 'Bol Riz',    file: 'bolRiz' },
  { slug: 'bol-frites', name: 'Bol Frites', file: 'bolFrites' },
];
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  const errs = [];
  p.on('console', m => { if (m.type()==='error') errs.push(m.text().slice(0,160)); });
  p.on('pageerror', e => errs.push('PAGEERR '+e.message.slice(0,160)));
  await p.goto('http://127.0.0.1:8087/index.html', { waitUntil: 'networkidle' });
  await p.waitForTimeout(700);
  await p.evaluate(() => {
    window.LC.storage.setAuth({ token: 'e2e-visual', phone: '0600000000', user_id: 1 });
    window.LC.storage.markOnboardingSeen();
  });

  const results = {};
  for (const t of TARGETS) {
    // fresh menu each time
    await p.evaluate(() => window.LC.storage.setCart([]));
    await p.reload({ waitUntil: 'networkidle' });
    await p.waitForTimeout(700);
    // go to menu via tab
    const tab = p.locator('[role=tab][aria-label="Menu"]').first();
    await tab.click(); await p.waitForTimeout(700);
    // open the item card
    const card = p.locator(`[aria-label^="Voir ${t.name}"]`).first();
    const cnt = await card.count();
    if (!cnt) { results[t.slug] = { error: 'card-not-found' }; continue; }
    await card.scrollIntoViewIfNeeded();
    await card.click();
    await p.waitForTimeout(1000);
    // extract structured view of the wizard
    const info = await p.evaluate(() => {
      const txt = document.body.innerText;
      // section headings
      const heads = [...document.querySelectorAll('h1,h2,h3,h4')].map(h=>h.textContent.trim()).filter(Boolean);
      return { txt, heads };
    });
    results[t.slug] = { heads: info.heads, txt: info.txt };
    await p.screenshot({ path: OUT+t.file+'-full.png', fullPage: true });
    await p.screenshot({ path: OUT+t.file+'-view.png' });
  }
  require('fs').writeFileSync(OUT+'_results.json', JSON.stringify({ results, errs }, null, 1));
  for (const [k,v] of Object.entries(results)) {
    console.log('\n########## '+k+' ##########');
    if (v.error) { console.log('ERROR', v.error); continue; }
    console.log('HEADS:', JSON.stringify(v.heads));
    console.log(v.txt.replace(/\n{2,}/g,'\n').slice(0,1600));
  }
  console.log('\nERRS', errs.slice(0,12));
  await b.close();
})();
