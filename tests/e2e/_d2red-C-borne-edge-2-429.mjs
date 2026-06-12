// ADVERSARIAL R2 — heal C-ADV-01 : forcer le throttle 30/min de /frontend/promo/validate
// puis lire l'inline UI sous le champ promo (attendu FR « Trop de tentatives… », plus de « Too Many Attempts. »)
import { boot, kioskBoot, startFlow, addProduct, BASE } from './_d1-C-lib.mjs';
import { log2, snap2 } from './_d2-C-lib.mjs';

const L = (m) => log2('_d2red-429-log.txt', m);
const { browser, page, sink } = await boot();
await kioskBoot(page);
await startFlow(page);
const r = await addProduct(page, 10, 52); L(`add Coca: ${JSON.stringify(r)}`);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1200);

// Burst in-page avec les VRAIS headers de l'app (axios defaults + bearer du store)
const burst = await page.evaluate(async () => {
  const v = JSON.parse(localStorage.vuex || '{}');
  const tok = v?.kioskCart?.kioskToken || null;
  const common = (window.axios && window.axios.defaults.headers.common) || {};
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json', ...common };
  if (tok && !headers.Authorization) headers.Authorization = 'Bearer ' + tok;
  let last = null, n429 = 0;
  for (let i = 0; i < 34; i++) {
    const res = await fetch('/api/frontend/promo/validate', { method: 'POST', headers, body: JSON.stringify({ code: 'FAUXBURST' + i, cart_total: 10 }) });
    last = res.status;
    if (res.status === 429) { n429++; if (n429 >= 2) break; }
  }
  return { last, n429, hadToken: !!tok };
});
L(`burst: ${JSON.stringify(burst)}`);

// Essai UI immédiat → inline
await page.locator('[data-testid="kiosk-cart-promo-input"]').fill('BORNEAUDIT5');
await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
await page.waitForTimeout(1800);
const inline = await page.locator('[data-testid="kiosk-cart-promo-error"]').innerText({ timeout: 2000 }).catch(() => 'ABSENT');
L(`inline UI post-429: "${inline}"`);
const saw429 = sink.network.filter(e => e.status === 429 && String(e.url).includes('promo/validate')).length;
L(`network 429 promo/validate (XHR app): ${saw429}`);
await snap2(page, sink, 'd2r-d2-promo-429-inline');
await browser.close();
