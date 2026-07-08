const { test, expect } = require('@playwright/test');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/web';
const TOKEN = '6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e';
const PHONE = '0697222388';

test('fidelite web real capture', async ({ page }) => {
  const consoleMsgs = [];
  page.on('console', m => consoleMsgs.push(`[${m.type()}] ${m.text()}`));
  page.on('pageerror', e => consoleMsgs.push(`[pageerror] ${e.message}`));

  // seed auth token BEFORE app boots
  await page.addInitScript(([t, p]) => {
    localStorage.setItem('lecayenne.authToken', t);
    localStorage.setItem('lecayenne.authPhone', p);
  }, [TOKEN, PHONE]);

  await page.goto('http://127.0.0.1:8096/', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);

  // header capture (top nav — check for Ikyes)
  await page.screenshot({ path: OUT + '/00-home-header.png' });

  // navigate to fidélité route
  const navBtn = page.locator("button:has-text('Fidélité')").first();
  await navBtn.click();
  await page.waitForTimeout(2500);
  await page.screenshot({ path: OUT + '/01-fidelite-top.png', fullPage: false });
  await page.screenshot({ path: OUT + '/02-fidelite-full.png', fullPage: true });

  // Report DOM facts
  const facts = await page.evaluate(() => {
    const txt = document.body.innerText;
    const svgCount = document.querySelectorAll('svg').length;
    // QR svg heuristic: an svg with many rect/path inside a loyalty container
    const qrSvgs = Array.from(document.querySelectorAll('svg')).filter(s => s.querySelectorAll('rect,path').length > 20);
    const has = (s) => txt.includes(s);
    return {
      innerTextSnippet: txt.slice(0, 4000),
      totalSvg: svgCount,
      qrLikeSvgCount: qrSvgs.length,
      qrRectCounts: qrSvgs.map(s => s.querySelectorAll('rect,path').length),
      residue: {
        Ikyes: has('Ikyes'),
        Benzaid: has('Benzaid'),
        LECAY347: has('LECAY-347'),
        DEMO_V1: has('DÉMO V1'),
        visa4242: has('4242'),
      },
      navHeaderText: (document.querySelector('.lc-nav-btn-account')||{}).innerText || null,
    };
  });
  require('fs').writeFileSync(OUT + '/facts.json', JSON.stringify({facts, consoleMsgs}, null, 2));
  console.log('FACTS', JSON.stringify(facts, null, 2));
});
