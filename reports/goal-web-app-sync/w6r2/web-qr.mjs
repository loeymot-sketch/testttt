import pkg from '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/node_modules/playwright/index.js';
const { chromium } = pkg;
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r2';
const TOKEN = '6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e';
const PHONE = '0697222388';
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
const p = await ctx.newPage();
const errs = [];
p.on('console', m => { if (m.type()==='error') errs.push(m.text()); });
p.on('pageerror', e => errs.push('PAGEERR:'+e.message));
await p.goto('http://127.0.0.1:8096/', { waitUntil: 'domcontentloaded' });
await p.evaluate(([t,ph]) => { localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone', ph); }, [TOKEN, PHONE]);
await p.goto('http://127.0.0.1:8096/', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2000);
const authed = await p.evaluate(() => !!(window.LC && window.LC.api && window.LC.api.isAuthed()));
console.error('isAuthed after inject:', authed);
// click Fidélité nav link
const clicked = await p.evaluate(() => {
  const btns = Array.from(document.querySelectorAll('button'));
  const b = btns.find(x => x.textContent.trim() === 'Fidélité');
  if (b) { b.click(); return true; }
  return false;
});
console.error('nav clicked:', clicked);
await p.waitForTimeout(4500);
const info = await p.evaluate(() => {
  const el = document.querySelector('.lc-wallet-code-qr');
  const svg = el ? el.querySelector('svg') : null;
  const rects = svg ? svg.querySelectorAll('rect,path').length : 0;
  const txt = document.body.innerText;
  const expireMatch = txt.match(/Expire dans\s+\d{1,2}:\d{2}/);
  const codeMatch = txt.match(/[0-9A-F]{8}/);
  return {
    hasQrEl: !!el,
    hasSvg: !!svg,
    svgShapeCount: rects,
    ariaLabel: el ? el.getAttribute('aria-label') : null,
    svgSnippet: svg ? svg.outerHTML.slice(0,160) : null,
    countdown: expireMatch ? expireMatch[0] : null,
    loyaltyCodeVisible: codeMatch ? codeMatch[0] : null,
    bodyHasIndispo: /QR indisponible/.test(txt),
  };
});
// capture the mint network token via re-calling api in page
const mintTok = await p.evaluate(async () => {
  try { const d = await window.LC.api.loyaltyQr(); return d && d.token ? d.token.slice(0,4) : 'no-token'; } catch(e){ return 'ERR:'+e.message; }
});
info.mintTokenPrefix = mintTok;
info.consoleErrors = errs;
await p.screenshot({ path: OUT + '/web-loyalty-qr.png', fullPage: false });
const qrEl = await p.$('.lc-wallet-code-qr');
if (qrEl) await qrEl.screenshot({ path: OUT + '/web-qr-closeup.png' });
console.log(JSON.stringify(info, null, 2));
await b.close();
