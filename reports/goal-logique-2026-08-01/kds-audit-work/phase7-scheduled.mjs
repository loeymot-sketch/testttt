// Phase 7 — commandes PROGRAMMÉES : bandeau, absence du board, garde de bump, entrée en fenêtre.
import { launch, loginAndGetContext, api, idem, BASE, SHOTS, log } from './lib.mjs';
import { execSync } from 'node:child_process';

const browser = await launch();
try {
  const { ctx, token } = await loginAndGetContext(browser);
  const client = api(token);

  // scheduled_at = now + 2h (Paris local, format Y-m-d H:i:s)
  const target = new Date(Date.now() + 2 * 3600 * 1000);
  const pad = (n) => String(n).padStart(2, '0');
  const sched = `${target.getFullYear()}-${pad(target.getMonth() + 1)}-${pad(target.getDate())} ${pad(target.getHours())}:${pad(target.getMinutes())}:00`;

  const payload = {
    customer_id: 2, branch_id: 1, order_type: 15, is_advance_order: 0, source: 15, pos_payment_method: 1,
    scheduled_at: sched, pos_customer_name: 'ZZ-TEST-SCHED',
    items: JSON.stringify([{ item_id: 22, quantity: 1, item_variations: [{ id: 680, quantity: 1 }, { id: 287, quantity: 1 }, { id: 450, quantity: 1 }], item_extras: [] }]),
  };
  const q = await client.post('/admin/pos/quote', payload);
  if (q.status !== 200) { log('quote fail', q.status, JSON.stringify(q.body).slice(0, 300)); process.exit(3); }
  const qd = q.body.data;
  const store = await client.post('/admin/pos', { ...payload, subtotal: qd.subtotal, total: qd.total_ttc, pos_received_amount: qd.total_ttc, quote_token: qd.quote_token, quote_signature: qd.signature }, idem());
  const o = store.body?.data;
  log('SCHEDULED order:', store.status, o ? `id=${o.id} queue=${o.queue_number} status=${o.status} scheduled_at=${sched}` : JSON.stringify(store.body).slice(0, 300));
  if (!o) process.exit(3);

  const kds = await ctx.newPage();
  await kds.goto(`${BASE}/admin/kitchen-display-system`, { waitUntil: 'networkidle' });
  await kds.waitForTimeout(6000);

  const view = await kds.evaluate((id) => ({
    inGrid: !!document.querySelector(`.kds-card[data-order-id="${id}"]`),
    bannerText: document.querySelector('[class*="scheduled"], [data-testid*="scheduled"]')?.innerText.replace(/\s+/g, ' ').trim() || null,
    bodyHasSched: /programm/i.test(document.body.innerText) ? document.body.innerText.match(/.{0,140}programm.{0,140}/i)?.[0] : null,
  }), o.id);
  log('On board (should be FALSE):', view.inGrid);
  log('Scheduled banner:', JSON.stringify(view.bannerText));
  log('Body mention:', JSON.stringify(view.bodyHasSched));
  await kds.screenshot({ path: SHOTS + 'p7-scheduled-banner.png' });

  // Bump attempt while out of window → expect 422
  const bump = await client.post(`/admin/kds-order/change-status/${o.id}`, { status: 8, expected_status: o.status }, idem());
  log('bump out-of-window:', bump.status, JSON.stringify(bump.body?.message ?? '').slice(0, 160));

  // Time-travel: move scheduled_at to now+10min (inside the 20-min lead) — simulate time passing.
  execSync(`mysql -u root foodking_e2e -e "UPDATE orders SET scheduled_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id=${o.id} AND pos_customer_name='ZZ-TEST-SCHED';"`);
  log('DB: scheduled_at moved to now+10min (time-travel simulation)');

  // KDS poll cadence when WS connected = 15s → wait up to 40s for the card to enter the grid.
  let enteredAt = null; const tMove = Date.now();
  for (let i = 0; i < 160; i++) {
    const inGrid = await kds.evaluate((id) => !!document.querySelector(`.kds-card[data-order-id="${id}"]`), o.id);
    if (inGrid) { enteredAt = Date.now(); break; }
    await kds.waitForTimeout(250);
  }
  log(enteredAt ? `Card ENTERED the grid ${(enteredAt - tMove) / 1000}s after entering window (poll pickup)` : 'Card did NOT enter grid within 40s');
  const bannerAfter = await kds.evaluate(() => document.querySelector('[class*="scheduled"], [data-testid*="scheduled"]')?.innerText.replace(/\s+/g, ' ').trim() || null);
  log('Banner after window entry:', JSON.stringify(bannerAfter));
  await kds.screenshot({ path: SHOTS + 'p7-scheduled-entered.png' });
  console.log(`::ORDER_ID::${o.id}`);
} finally {
  await browser.close();
}
