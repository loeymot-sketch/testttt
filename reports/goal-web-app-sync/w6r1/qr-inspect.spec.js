const { test } = require('@playwright/test');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/web';
const TOKEN = '6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e';
test('qr inspect', async ({ page }) => {
  const netFail = [];
  page.on('requestfailed', r => netFail.push(r.url()+' :: '+(r.failure()||{}).errorText));
  const errs = [];
  page.on('console', m => { if(m.type()==='error') errs.push(m.text()); });
  page.on('pageerror', e => errs.push('PAGEERR '+e.message));
  await page.addInitScript((t)=>{ localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone','0697222388'); }, TOKEN);
  await page.goto('http://127.0.0.1:8096/', { waitUntil: 'networkidle' });
  await page.locator("button:has-text('Fidélité')").first().click();
  await page.waitForTimeout(3000);
  const info = await page.evaluate(() => {
    // find element near the identifier text / QR
    const all = Array.from(document.querySelectorAll('*'));
    const qrHost = all.find(e => /qr/i.test(e.className||'') && e.className.includes && true);
    // search containers with class containing 'qr'
    const qrEls = Array.from(document.querySelectorAll('[class*="qr" i],[class*="QR"]'));
    const dump = qrEls.map(e => ({cls:e.className, tag:e.tagName, childHTMLlen:(e.innerHTML||'').length, htmlHead:(e.innerHTML||'').slice(0,300)}));
    return {
      qrElementsFound: qrEls.length,
      qrDump: dump,
      qrcodeLibType: typeof window.qrcode,
      svgTotal: document.querySelectorAll('svg').length,
      canvasTotal: document.querySelectorAll('canvas').length,
      imgTotal: document.querySelectorAll('img').length,
    };
  });
  require('fs').writeFileSync(OUT+'/qr-info.json', JSON.stringify({info, errs, netFail}, null, 2));
  console.log(JSON.stringify({info, errs, netFail}, null, 2));
  // element screenshot of QR area if found
  const host = page.locator('[class*="qr" i]').first();
  if (await host.count()) { await host.screenshot({ path: OUT+'/03-qr-element.png' }).catch(()=>{}); }
});
