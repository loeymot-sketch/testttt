// JETABLE — visual e2e mobile paiement/commande (isolated chromium)
const { chromium } = require('playwright');
const fs = require('fs');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/mobile';
const URL = 'http://127.0.0.1:8087/index.html';
const out = { errors: [] };

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  page.on('console', m => { if (m.type() === 'error') out.errors.push(m.text()); });
  page.on('pageerror', e => out.errors.push('PAGEERROR: ' + e.message));

  await page.goto(URL, { waitUntil: 'networkidle' });

  // Seed auth + a main-dish cart via the app's own storage API, then reload to hydrate React state.
  await page.evaluate(() => {
    const s = window.LC.storage;
    // REAL Sanctum kiosk:order token (fixture client 189) so placeCounterOrder hits API for real
    s.setAuth({ token: '6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e', phone: '0697222388', user_id: 189 });
    // Faithful line: real mobile menu item (slug 'cayenne' → backend id 22)
    s.setCart([{ id: 101, slug: 'cayenne', name: 'Cayenne', cat: 'sandwichs', price: 7.4, unitPrice: 7.4, qty: 1, lineTotal: 7.4, sups: [], painId: 'pain-classique', painLabel: 'Pain' }]);
  });
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForTimeout(500);

  out.flag = await page.evaluate(() => window.LC.config.onlineCardEnabled);

  // Go to Menu tab (sticky "Voir le panier" bar lives on the menu screen).
  await page.evaluate(() => {
    const t = [...document.querySelectorAll('button,a,div')].find(x => (x.textContent||'').trim() === 'Menu' || /menu/i.test(x.getAttribute('aria-label')||''));
    if (t) t.click();
  });
  await page.waitForTimeout(500);
  await page.screenshot({ path: OUT + '/v02b-menu.png' });

  // Click "Voir le panier"
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(x => /Voir le panier/i.test(x.textContent||''));
    if (b) b.click();
  });
  await page.waitForTimeout(500);

  let onCart = await page.evaluate(() => /Pour accompagner|Valider ma commande/i.test(document.body.innerText));
  out.onCart = onCart;

  // Inspect upsell contents
  out.upsell = await page.evaluate(() => {
    const body = document.body.innerText;
    const idx = body.indexOf('Pour accompagner');
    const seg = idx >= 0 ? body.slice(idx, idx + 400) : null;
    // Count upsell cards: those inside the horizontal scroller after the section title.
    const cards = [...document.querySelectorAll('div')].filter(d => {
      const t = d.textContent || '';
      return /€/.test(t) && d.querySelector('button') && d.offsetWidth > 100 && d.offsetWidth < 160;
    }).map(d => (d.querySelector('div')?.textContent || '').trim()).filter(Boolean);
    return { present: idx >= 0, seg, cardCount: cards.length, cards };
  });
  await page.screenshot({ path: OUT + '/v05-cart-upsell.png', fullPage: true });

  // Click "Valider ma commande"
  const valider = page.locator('button', { hasText: /Valider ma commande/i });
  if (await valider.count()) {
    await valider.first().click();
    await page.waitForTimeout(600);
  }
  await page.screenshot({ path: OUT + '/v06-modal-pay.png' });

  // Inspect the pay modal DOM: which options present?
  out.payModal = await page.evaluate(() => {
    const modal = document.querySelector('[data-modal-kind="pay"]');
    if (!modal) return { present: false };
    return {
      present: true,
      hasCounter: !!modal.querySelector('[data-testid="pay-counter"]'),
      hasCardOnline: !!modal.querySelector('[data-testid="pay-card-online"]'),
      hasSoonNote: !!modal.querySelector('[data-testid="pay-online-soon"]'),
      text: modal.innerText.replace(/\n+/g, ' | '),
    };
  });

  // Pick counter -> confirm
  const counter = page.locator('[data-testid="pay-counter"]');
  if (await counter.count()) {
    await counter.click();
    await page.waitForTimeout(1500); // allow placeCounterOrder network + gain modal timeout
  }
  await page.screenshot({ path: OUT + '/v07-confirm.png', fullPage: true });

  out.confirm = await page.evaluate(() => {
    const body = document.body.innerText;
    return {
      snippet: body.slice(0, 500),
      hasQueue: /[A-Z]?\d{3,4}/.test(body),
      hasOffline: /Hors ligne|démonstration/i.test(body),
      isConfirmScreen: /commande|confirm|préparation|retrait|merci/i.test(body),
    };
  });

  await browser.close();
  fs.writeFileSync(OUT + '/v-findings.json', JSON.stringify(out, null, 2));
  console.log(JSON.stringify(out, null, 2));
})().catch(e => { console.error('FATAL', e); process.exit(1); });
