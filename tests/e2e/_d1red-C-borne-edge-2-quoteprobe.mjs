// ADVERSARIAL probe — le quote backend applique-t-il kiosk_promo_code / loyalty_code ?
import { boot, kioskBoot, BASE, logLine } from './_d1-C-lib.mjs';

const L = (m) => logLine('_d1red-quoteprobe-log.txt', m);
const { browser, page } = await boot();
await kioskBoot(page);
await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);

const probe = await page.evaluate(async () => {
  const tok = JSON.parse(localStorage.vuex).kioskCart.kioskToken;
  const items = JSON.stringify([{ item_id: 52, quantity: 2, item_variations: [], item_extras: [], item_addons: [], instruction: '' }]);
  const call = async (extra) => {
    const r = await fetch('/api/frontend/order/quote', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: 'Bearer ' + tok, 'x-api-key': window.foodkingConfig?.apiKey || '' },
      body: JSON.stringify({ order_type: 10, is_advance_order: 0, source: 15, payment_method: 0, items, ...extra }),
    });
    const j = await r.json().catch(() => null);
    const d = j?.data || j;
    return { status: r.status, subtotal: d?.subtotal, discount: d?.discount, total_ttc: d?.total_ttc, keys: d ? Object.keys(d).slice(0, 12) : null, raw: JSON.stringify(j).slice(0, 400) };
  };
  const out = {};
  out.noPromo = await call({});
  out.withPromo = await call({ kiosk_promo_code: 'BORNEAUDIT5' });
  out.withLoyalty = await call({ loyalty_code: 'VICT1234' });
  // preview (panier) pour comparer
  const p = await fetch('/api/frontend/pricing/preview', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: 'Bearer ' + tok, 'x-api-key': window.foodkingConfig?.apiKey || '' },
    body: JSON.stringify({ items: [{ item_id: 52, quantity: 2 }], kiosk_promo_code: 'BORNEAUDIT5' }),
  });
  const pj = await p.json().catch(() => null);
  out.preview = { status: p.status, raw: JSON.stringify(pj).slice(0, 400) };
  return out;
});
L('QUOTE sans promo:    ' + JSON.stringify(probe.noPromo));
L('QUOTE avec promo:    ' + JSON.stringify(probe.withPromo));
L('QUOTE avec loyalty:  ' + JSON.stringify(probe.withLoyalty));
L('PREVIEW avec promo:  ' + JSON.stringify(probe.preview));
await browser.close();
