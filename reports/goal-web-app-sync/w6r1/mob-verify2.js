const { chromium } = require('playwright');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/mobile';
const log = (...a) => console.log(...a);

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push('PAGEERROR: ' + e.message));

  // Pre-seed auth so we land authed (use a known fixture client token to see a real balance if any)
  await page.addInitScript(() => {
    localStorage.setItem('lecayenne.auth', JSON.stringify({ token: '6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e', phone: '0697222388', user_id: 189 }));
    localStorage.setItem('lecayenne.onboarding_seen', 'true');
  });
  await page.goto('http://127.0.0.1:8087/', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(3500);

  // Directly drive app router if exposed; else click profile card -> loyalty
  // Navigate to loyalty via home loyalty card
  await page.evaluate(() => {
    const els = [...document.querySelectorAll('[aria-label]')];
    const card = els.find(e => /Carte fidélité/i.test(e.getAttribute('aria-label')||''));
    if (card) card.click();
  });
  await page.waitForTimeout(2500);
  await page.screenshot({ path: `${OUT}/v2-01-loyalty.png`, fullPage: true });

  // Now click the "Réductions" tab
  const tabClicked = await page.evaluate(() => {
    const els = [...document.querySelectorAll('button,[role="tab"],[role="button"],div,span')];
    const el = els.find(e => (e.textContent||'').trim() === 'Réductions');
    if (el) { el.click(); return true; }
    return false;
  });
  await page.waitForTimeout(2000);
  await page.screenshot({ path: `${OUT}/v2-02-reductions.png`, fullPage: true });
  const red = await page.evaluate(() => {
    const b = document.body.innerText;
    return {
      tabClicked: true,
      redeemValue: document.querySelector('[data-testid="redeem-points-value"]')?.textContent || null,
      // detect old mock 8-reward catalogue names
      mockNames: ['Frites offertes','Boisson offerte','Café offert','Menu enfant offert','Dessert offert','Burger offert','-15%','Réduction 5€ mock'].filter(n=>b.includes(n)),
      mentionsContinuous: b.includes('pts = 1') || b.includes('1 € de réduction') || b.includes('tranche de'),
      snippet: b.slice(0,700)
    };
  });
  log('REDUCTIONS_TAB =', JSON.stringify(red, null, 2), 'clicked=', tabClicked);

  // Historique tab
  await page.evaluate(() => {
    const els = [...document.querySelectorAll('button,[role="tab"],[role="button"],div,span')];
    const el = els.find(e => (e.textContent||'').trim() === 'Historique');
    if (el) el.click();
  });
  await page.waitForTimeout(2000);
  await page.screenshot({ path: `${OUT}/v2-03-historique.png`, fullPage: true });

  // Go back and open Profile screen (bottom nav profile)
  await page.evaluate(() => {
    // back button top-left
    const back = [...document.querySelectorAll('button,[role="button"]')].find(e => /retour|back|‹|←/i.test((e.getAttribute('aria-label')||'')));
    if (back) back.click();
  });
  await page.waitForTimeout(1500);
  await page.evaluate(() => {
    const nav = [...document.querySelectorAll('button,[role="button"],[role="tab"],a')];
    const prof = nav.find(e => /profil|compte/i.test((e.getAttribute('aria-label')||'')) || (e.textContent||'').trim().toLowerCase() === 'profil' || (e.textContent||'').trim().toLowerCase() === 'compte');
    if (prof) prof.click();
  });
  await page.waitForTimeout(2000);
  await page.screenshot({ path: `${OUT}/v2-04-profile.png`, fullPage: true });
  const prof = await page.evaluate(() => {
    const b = document.body.innerText;
    return {
      mentionsIkyes: b.includes('Ikyes'),
      mentionsRealPhone: b.includes('0697222388') || b.includes('06 97 22 23 88') || /06\s?97/.test(b),
      hardcodedNum: b.includes('6 42 79 98 84') || b.includes('12 34 56 78'),
      snippet: b.slice(0,700)
    };
  });
  log('PROFILE_SCREEN =', JSON.stringify(prof, null, 2));
  log('ERRORS =', JSON.stringify(errors.slice(0,10)));
  await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
