import pkg from '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/node_modules/playwright/index.js';
const { chromium } = pkg;
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r2';
const TOKEN = '6626|mGgnWhPlvWtt4XVfIsFIzzJrvYk9gTch44xh1MhI51218a9c';
const PHONE = '0697285033';
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 420, height: 900 }, deviceScaleFactor: 2 });
const p = await ctx.newPage();
const errs = [];
p.on('console', m => { if (m.type()==='error') errs.push(m.text()); });
p.on('pageerror', e => errs.push('PAGEERR:'+e.message));
await p.goto('http://127.0.0.1:8087/', { waitUntil: 'domcontentloaded' });
await p.evaluate(([t,ph]) => {
  localStorage.setItem('lecayenne.auth', JSON.stringify({ token: t, phone: ph, user_id: 190 }));
  localStorage.setItem('lecayenne.onboarding_seen', 'true');
}, [TOKEN, PHONE]);
await p.goto('http://127.0.0.1:8087/', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2000);
const authed = await p.evaluate(() => !!(window.LC && window.LC.mobileApi && window.LC.mobileApi.isAuthed && window.LC.mobileApi.isAuthed()));
console.error('mobile isAuthed:', authed);
// navigate: Profil tab → Ma carte fidélité
const nav1 = await p.evaluate(() => {
  const el = Array.from(document.querySelectorAll('[aria-label]')).find(x => x.getAttribute('aria-label') === 'Profil' && x.closest('[aria-label="Navigation principale"]'));
  const any = el || Array.from(document.querySelectorAll('*')).find(x => x.children.length===0 && x.textContent.trim()==='Profil');
  if (any) { any.click(); return 'profil-clicked'; }
  return 'profil-not-found';
});
console.error('nav1:', nav1);
await p.waitForTimeout(1500);
const nav2 = await p.evaluate(() => {
  const el = Array.from(document.querySelectorAll('[aria-label]')).find(x => /carte fidélité/i.test(x.getAttribute('aria-label')||''));
  if (el) { el.click(); return 'carte-clicked:'+el.getAttribute('aria-label'); }
  const byText = Array.from(document.querySelectorAll('*')).find(x => /Ma carte fidélité/i.test(x.textContent) && x.querySelector('*')===null);
  if (byText) { (byText.closest('[role="button"]')||byText).click(); return 'carte-text-clicked'; }
  return 'carte-not-found';
});
console.error('nav2:', nav2);
await p.waitForTimeout(4500);
const info = await p.evaluate(() => {
  const el = document.querySelector('[data-testid="loyalty-qr"]');
  const svg = el ? el.querySelector('svg') : null;
  const shapes = svg ? svg.querySelectorAll('rect,path').length : 0;
  const cd = document.querySelector('[data-testid="loyalty-qr-countdown"]');
  const codeEl = document.querySelector('[data-testid="loyalty-code-text"]') || document.querySelector('[data-testid="loyalty-member-number"]');
  const payload = el ? el.getAttribute('data-payload') : null;
  const txt = document.body.innerText;
  return {
    hasQrEl: !!el,
    qrState: el ? el.getAttribute('data-qr-state') : null,
    hasSvg: !!svg,
    svgShapeCount: shapes,
    svgSnippet: svg ? svg.outerHTML.slice(0,150) : null,
    dataPayloadPrefix: payload ? payload.slice(0,4) : null,
    countdownText: cd ? cd.textContent.trim() : null,
    loyaltyCode: codeEl ? codeEl.textContent.trim() : null,
    bodyExpireMatch: (txt.match(/Expire dans[^\n]*/) || txt.match(/\d+\s*min\s*\d+\s*s/) || [null])[0],
  };
});
info.mintTokenPrefix = await p.evaluate(async () => { try { const d = await window.LC.mobileApi.loyaltyQr(); return d && d.token ? d.token.slice(0,4) : 'no'; } catch(e){ return 'ERR:'+(e.message||e); } });
info.consoleErrors = errs;
await p.screenshot({ path: OUT + '/mobile-loyalty-qr.png', fullPage: false });
const qrEl = await p.$('[data-testid="loyalty-qr"] svg');
if (qrEl) await qrEl.screenshot({ path: OUT + '/mobile-qr-closeup.png' });
console.log(JSON.stringify(info, null, 2));
await b.close();
