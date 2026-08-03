const { chromium } = require('playwright');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/mobile';
const PHONE_INPUT = '06 98 11 22 33';   // FR number we type (digits 0698112233)
const log = (...a) => console.log(...a);

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', e => errors.push('PAGEERROR: ' + e.message));

  await page.goto('http://127.0.0.1:8087/', { waitUntil: 'networkidle', timeout: 30000 });
  // wait for babel/react to render root
  await page.waitForTimeout(3500);
  await page.screenshot({ path: `${OUT}/v-01-boot.png` });
  log('BOOT bodyText len:', (await page.textContent('body')).length);

  // ---- Navigate onboarding -> login. The app boots at splash/onboarding.
  // Try to reach the login/phone screen. Look for "Recevoir le code" or phone input.
  async function findScreen() {
    return await page.evaluate(() => {
      const b = document.body.innerText;
      const has = s => b.includes(s);
      return {
        screen: document.querySelector('[data-screen-label]')?.getAttribute('data-screen-label') || null,
        hasPhoneInput: !!document.querySelector('input[inputmode="tel"], input[autocomplete="tel-national"]'),
        hasRecevoir: has('Recevoir le code'),
        hasOtpBoxes: document.querySelectorAll('input[autocomplete="one-time-code"], input[aria-label*="code de validation"]').length,
        otpPhone: document.querySelector('[data-testid="otp-phone"]')?.textContent || null,
        snippet: b.slice(0, 200)
      };
    });
  }
  log('SCREEN@boot', JSON.stringify(await findScreen()));

  // Click through onboarding: press any "Suivant"/"Commencer"/CTA up to 6 times until phone input appears
  for (let i = 0; i < 8; i++) {
    const st = await findScreen();
    if (st.hasPhoneInput || st.hasRecevoir) break;
    // click the primary CTA / skip
    const clicked = await page.evaluate(() => {
      const btns = [...document.querySelectorAll('button, [role="button"], a')];
      // Prefer texts that advance
      const wants = ['Commencer','Suivant','Continuer','C\'est parti','Je me lance','Se connecter','Connexion','Passer','Ignorer','Créer'];
      for (const w of wants) {
        const el = btns.find(b => (b.textContent||'').trim().includes(w));
        if (el) { el.click(); return w; }
      }
      // else click last button (usually the CTA)
      if (btns.length) { btns[btns.length-1].click(); return 'LAST:'+(btns[btns.length-1].textContent||'').trim().slice(0,30); }
      return null;
    });
    log('onb click', i, clicked);
    await page.waitForTimeout(800);
  }
  await page.screenshot({ path: `${OUT}/v-02-login.png` });
  log('SCREEN@login', JSON.stringify(await findScreen()));

  // ---- Type phone number
  const typed = await page.evaluate((val) => {
    const inp = document.querySelector('input[inputmode="tel"], input[autocomplete="tel-national"]');
    if (!inp) return null;
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(inp, val);
    inp.dispatchEvent(new Event('input', { bubbles: true }));
    return inp.value;
  }, PHONE_INPUT);
  log('typed phone ->', typed);
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${OUT}/v-03-phone-filled.png` });

  // Click "Recevoir le code"
  const sent = await page.evaluate(() => {
    const btns = [...document.querySelectorAll('button')];
    const el = btns.find(b => (b.textContent||'').includes('Recevoir le code') || (b.textContent||'').includes('Envoi du code'));
    if (el && !el.disabled) { el.click(); return true; }
    return el ? 'DISABLED' : false;
  });
  log('sendCode ->', sent);
  await page.waitForTimeout(2500);
  await page.screenshot({ path: `${OUT}/v-04-otp.png` });
  const otpState = await findScreen();
  log('SCREEN@otp', JSON.stringify(otpState));
  // Capture the exact otp-phone rendered
  const otpPhoneText = await page.evaluate(() => document.querySelector('[data-testid="otp-phone"]')?.textContent || null);
  log('OTP_PHONE_DISPLAYED =', JSON.stringify(otpPhoneText));

  // ---- Enter code 1234
  await page.evaluate(() => {
    const boxes = [...document.querySelectorAll('input[aria-label*="code de validation"], input[autocomplete="one-time-code"], input[maxlength="1"]')].slice(0,4);
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    ['1','2','3','4'].forEach((d,i) => { if (boxes[i]) { setter.call(boxes[i], d); boxes[i].dispatchEvent(new Event('input',{bubbles:true})); } });
  });
  await page.waitForTimeout(600);
  // Some OTP auto-submit on 4th digit; else click a validate button
  await page.evaluate(() => {
    const btns = [...document.querySelectorAll('button')];
    const el = btns.find(b => /valider|confirmer|vérifier|continuer/i.test(b.textContent||''));
    if (el && !el.disabled) el.click();
  });
  await page.waitForTimeout(3000);
  await page.screenshot({ path: `${OUT}/v-05-after-login.png` });
  log('SCREEN@afterlogin', JSON.stringify(await findScreen()));

  // ---- Check localStorage lecayenne.auth
  const auth = await page.evaluate(() => {
    const raw = localStorage.getItem('lecayenne.auth');
    return { raw, parsed: raw ? JSON.parse(raw) : null };
  });
  log('AUTH_STORAGE =', JSON.stringify(auth));

  // ---- Navigate to loyalty screen. Go home then loyalty.
  // Try to click the loyalty/fidélité card or nav.
  await page.evaluate(() => {
    // if app exposes go(), we can't. Click bottom nav 'profile' then loyalty card
    const els = [...document.querySelectorAll('button,[role="button"],a,[tabindex]')];
    const prof = els.find(e => /profil|compte|fidél/i.test(e.getAttribute('aria-label')||'') || /profil/i.test(e.textContent||''));
    if (prof) prof.click();
  });
  await page.waitForTimeout(1500);
  // click loyalty card "VOIR MON QR"
  await page.evaluate(() => {
    const els = [...document.querySelectorAll('button,[role="button"],a,[tabindex]')];
    const q = els.find(e => /VOIR MON QR|voir détails|fidél/i.test((e.getAttribute('aria-label')||'')+(e.textContent||'')));
    if (q) q.click();
  });
  await page.waitForTimeout(3000);
  await page.screenshot({ path: `${OUT}/v-06-loyalty.png`, fullPage: true });

  const loyalty = await page.evaluate(() => {
    const qr = document.querySelector('[data-testid="loyalty-qr"]');
    const svgHost = document.querySelector('[data-testid="loyalty-qr-svg"], [data-testid="loyalty-qr"] svg, svg');
    const svgTag = document.querySelector('[data-testid="loyalty-qr"] svg') || document.querySelector('.lc-device svg');
    const countdown = document.querySelector('[data-testid="loyalty-qr-countdown"]')?.textContent || null;
    const codeText = document.querySelector('[data-testid="loyalty-code-text"]')?.textContent || null;
    const member = document.querySelector('[data-testid="loyalty-member-number"]')?.textContent || null;
    const err = document.querySelector('[data-testid="loyalty-qr-error"]')?.textContent || null;
    const payload = qr?.getAttribute('data-payload') || null;
    // count svg rects (QR modules) anywhere in the loyalty region
    const svgs = [...document.querySelectorAll('svg')];
    const svgRectCounts = svgs.map(s => s.querySelectorAll('rect,path').length);
    const bodyText = document.body.innerText;
    return {
      hasQrEl: !!qr,
      payloadStartsLqr: payload ? payload.startsWith('lqr.') : null,
      payloadSample: payload ? payload.slice(0,20) : null,
      countdown, codeText, member, err,
      svgCount: svgs.length,
      svgRectCounts,
      mentionsReduction: bodyText.includes('pts = 1') || bodyText.includes('réduction'),
      mentionsIkyes: bodyText.includes('Ikyes'),
      loyaltyBodySnippet: bodyText.slice(0, 600)
    };
  });
  log('LOYALTY =', JSON.stringify(loyalty, null, 2));

  // ---- Reductions tab
  await page.evaluate(() => {
    const els = [...document.querySelectorAll('button,[role="tab"],[role="button"]')];
    const el = els.find(e => /réduction|reduction|points/i.test(e.textContent||''));
    if (el) el.click();
  });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/v-07-reductions.png`, fullPage: true });
  const reductions = await page.evaluate(() => {
    const b = document.body.innerText;
    return {
      hasRedeemValue: !!document.querySelector('[data-testid="redeem-points-value"]'),
      redeemValue: document.querySelector('[data-testid="redeem-points-value"]')?.textContent || null,
      mentionsPtsEuro: b.includes('pts = 1') || b.includes('1 € de réduction'),
      // mock catalogue detection: 8 rewards names
      mockRewardsHits: ['Frites offertes','Boisson offerte','Menu offert','-10%','Dessert offert','Burger offert'].filter(n=>b.includes(n)),
      snippet: b.slice(0,500)
    };
  });
  log('REDUCTIONS =', JSON.stringify(reductions, null, 2));

  // ---- Profile phone check
  await page.evaluate(() => {
    const els = [...document.querySelectorAll('button,[role="button"],a,[tabindex]')];
    const prof = els.find(e => /profil|compte/i.test((e.getAttribute('aria-label')||'')+(e.textContent||'')));
    if (prof) prof.click();
  });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/v-08-profile.png`, fullPage: true });
  const profile = await page.evaluate(() => {
    const b = document.body.innerText;
    return { mentionsIkyes: b.includes('Ikyes'), snippet: b.slice(0,500) };
  });
  log('PROFILE =', JSON.stringify(profile, null, 2));

  log('CONSOLE_ERRORS =', JSON.stringify(errors.slice(0,20)));
  await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
