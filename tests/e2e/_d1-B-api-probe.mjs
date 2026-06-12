// VAGUE B dispute — sondes API lecture seule (attribution client borne, file pending)
import fs from 'fs';
import { BASE, OUT, boot, mkLogger, login } from './_d1-B-lib.mjs';

const L = mkLogger('b5-probe');
const { browser, page } = await boot();
await login(page, L);
await page.goto(BASE + '/admin/dashboard', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2500);

const probe = await page.evaluate(async () => {
  const out = {};
  const get = async (u) => { try { const r = await window.axios.get(u); return r.data; } catch (e) { return { err: e?.response?.status }; } };
  out.hist4329 = await get('admin/order-history?order_serial_no=1006264329&paginate=1&per_page=5');
  out.hist4519 = await get('admin/order-history?order_serial_no=1206264519&paginate=1&per_page=5');
  const pend = await get('admin/pos/counter-collect/pending');
  const list = pend?.data || [];
  out.pendingCount = list.length;
  out.pendingFirst3 = list.slice(0, 3).map((o) => ({ id: o.id, serial: o.order_serial_no, queue: o.queue_number, date: o.order_datetime, total: o.total }));
  out.pendingLast3 = list.slice(-3).map((o) => ({ id: o.id, serial: o.order_serial_no, queue: o.queue_number, date: o.order_datetime, total: o.total }));
  return out;
});
const cli = (h) => (h?.data || []).map((o) => ({ serial: o.order_serial_no, client: o.customer_name || o.user_name || o.client || (o.user && o.user.name), source: o.source_surface || o.origin, total: o.total }));
L(`hist 4329: ${JSON.stringify(cli(probe.hist4329))}`);
L(`hist 4519: ${JSON.stringify(cli(probe.hist4519))}`);
L(`pending count=${probe.pendingCount}`);
L(`pending first3=${JSON.stringify(probe.pendingFirst3)}`);
L(`pending last3=${JSON.stringify(probe.pendingLast3)}`);
fs.writeFileSync(OUT + '_b5-api-probe.json', JSON.stringify(probe, null, 2));
L.flush();
await browser.close();
console.log('DONE');
