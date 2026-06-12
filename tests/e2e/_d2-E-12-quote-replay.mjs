// D2 VAGUE E — replay du quote EXACT de 4520 pour isoler le skip fidélité
import { BASE, OUT, boot, makeLogger } from './_d2-E-lib.mjs';
import fs from 'fs';

const L = makeLogger('E12-quote-replay');
const { browser, page } = await boot({ kiosk: true });
await page.goto(BASE + '/kiosk/login', { waitUntil: 'domcontentloaded' });
for (let i = 0; i < 10; i++) {
  await page.waitForTimeout(1500);
  if (await page.evaluate(() => { try { return !!JSON.parse(localStorage.vuex)?.kioskCart?.kioskToken; } catch { return false; } })) break;
}

const items = JSON.stringify([
  { item_id: 26, instruction: 'Viandes : Poulet mariné ×1', quantity: 1, item_variations: [{ id: 43, name: 'Poulet mariné', variation_name: 'Viande 1' }, { id: 311, name: 'Algérienne', variation_name: 'Sauce (1ère Gratuite)' }], item_extras: [] },
  { item_id: 52, instruction: '', quantity: 1, item_variations: [], item_extras: [] },
]);

const variants = [
  { tag: 'A-exact-4520', body: { loyalty_code: 'VICT1234', loyalty_redeem_discount: 1.65, kiosk_promo_code: 'BORNEAUDIT5', items } },
  { tag: 'B-sans-promo', body: { loyalty_code: 'VICT1234', loyalty_redeem_discount: 1.65, items } },
  { tag: 'C-redeem-1.00', body: { loyalty_code: 'VICT1234', loyalty_redeem_discount: 1.0, kiosk_promo_code: 'BORNEAUDIT5', items } },
];

const out = [];
for (const v of variants) {
  const res = await page.evaluate(async (payload) => {
    try {
      const body = { order_type: 10, is_advance_order: 10, source: 5, payment_method: 0, ...payload };
      const r = await window.axios.post('/api/frontend/order/quote', body).catch((e) => e?.response);
      const j = r?.data;
      return { status: r?.status, subtotal: j?.data?.subtotal ?? j?.subtotal, discount: j?.data?.discount ?? j?.discount, total: j?.data?.total_ttc ?? j?.total_ttc, raw: JSON.stringify(j).slice(0, 300) };
    } catch (e) { return { error: String(e).slice(0, 200) }; }
  }, v.body);
  L(`${v.tag}: ${JSON.stringify(res)}`);
  out.push({ ...v, res });
  await page.waitForTimeout(1200);
}
fs.writeFileSync(`${OUT}_E12-quote-replay.json`, JSON.stringify(out, null, 2));
L.flush();
await browser.close();
