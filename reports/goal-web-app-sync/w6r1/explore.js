const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  const errs = [];
  p.on('console', m => { if (m.type()==='error') errs.push(m.text()); });
  p.on('pageerror', e => errs.push('PAGEERROR '+e.message));
  await p.goto('http://127.0.0.1:8087/index.html', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1500);
  // Try to skip onboarding: click any CTA to go to menu
  const dump = async (tag) => {
    const txt = await p.evaluate(() => {
      const btns = [...document.querySelectorAll('button, [role=button], a')].slice(0,40).map(e => (e.getAttribute('data-testid')||'')+'|'+(e.textContent||'').trim().slice(0,40));
      return { title: document.title, h: document.body.innerText.slice(0,600), btns };
    });
    console.log('=== '+tag+' ===');
    console.log(JSON.stringify(txt, null, 1).slice(0, 2500));
  };
  await dump('boot');
  console.log('ERRS', errs.slice(0,10));
  await b.close();
})();
