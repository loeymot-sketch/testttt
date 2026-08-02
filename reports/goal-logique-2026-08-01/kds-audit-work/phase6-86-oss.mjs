// Phase 6 — 86 (rupture) visibility on KDS, order-with-86-item, OSS with TAKEAWAY order.
import { launch, loginAndGetContext, api, createPosOrder, idem, BASE, SHOTS, log } from './lib.mjs';

const browser = await launch();
try {
  const { ctx, token } = await loginAndGetContext(browser);
  const client = api(token);

  // 1) TAKEAWAY order with Cayenne so we have an in-flight card + OSS-eligible order.
  const mk = () => createPosOrder(client, {
    items: [{ item_id: 22, quantity: 1, item_variations: [{ id: 687, quantity: 1 }, { id: 289, quantity: 1 }, { id: 450, quantity: 1 }], item_extras: [] }],
    customerName: 'ZZ-TEST-86',
  });
  // TAKEAWAY variant: override order_type via a custom call
  const payload = {
    customer_id: 2, branch_id: 1, order_type: 10, is_advance_order: 0, source: 15, pos_payment_method: 1,
    items: JSON.stringify([{ item_id: 22, quantity: 1, item_variations: [{ id: 687, quantity: 1 }, { id: 289, quantity: 1 }, { id: 450, quantity: 1 }], item_extras: [] }]),
  };
  const q = await client.post('/admin/pos/quote', payload);
  log('quote TAKEAWAY:', q.status);
  let takeawayOrder = null;
  if (q.status === 200) {
    const qd = q.body.data;
    const store = await client.post('/admin/pos', { ...payload, subtotal: qd.subtotal, total: qd.total_ttc, pos_received_amount: qd.total_ttc, quote_token: qd.quote_token, quote_signature: qd.signature }, idem());
    takeawayOrder = store.body?.data || null;
    log('TAKEAWAY store:', store.status, takeawayOrder ? `id=${takeawayOrder.id} queue=${takeawayOrder.queue_number} status=${takeawayOrder.status} type=${takeawayOrder.order_type}` : JSON.stringify(store.body).slice(0, 300));
  }
  if (!takeawayOrder) { log('no takeaway order — fallback POS'); const r = await mk(); takeawayOrder = r.body?.data; log('fallback:', r.status, takeawayOrder?.id); }
  const oid = takeawayOrder.id;

  // KDS screen with the in-flight card
  const kds = await ctx.newPage();
  await kds.goto(`${BASE}/admin/kitchen-display-system`, { waitUntil: 'networkidle' });
  await kds.waitForTimeout(5000);
  const onBoard = await kds.evaluate((id) => !!document.querySelector(`.kds-card[data-order-id="${id}"]`), oid);
  log('TAKEAWAY order on KDS board:', onBoard);

  // 2) 86 the Cayenne while in-flight — cook must see the OOS badge WITHOUT F5.
  const t86 = Date.now();
  const tog = await client.post('/admin/menu/availability/toggle', { item_id: 22, branch_id: 1, is_available: false, unavailable_reason: 'ZZ-TEST rupture audit' });
  log('86 toggle:', tog.status, JSON.stringify(tog.body?.message ?? tog.body ?? '').slice(0, 160));
  let badgeAt = null;
  for (let i = 0; i < 80; i++) {
    const has = await kds.evaluate((id) => {
      const c = document.querySelector(`.kds-card[data-order-id="${id}"]`);
      return c ? c.innerText.includes('OOS') || !!c.querySelector('[data-testid*="oos"], .kds-oos-warning-badge, .kds-card [class*="oos"]') : false;
    }, oid);
    if (has) { badgeAt = Date.now(); break; }
    await kds.waitForTimeout(250);
  }
  log(badgeAt ? `OOS badge appeared on in-flight card ${(badgeAt - t86) / 1000}s after 86 (no F5)` : 'OOS badge NEVER appeared within 20s');
  const cardTxt = await kds.evaluate((id) => document.querySelector(`.kds-card[data-order-id="${id}"]`)?.innerText.replace(/\n{2,}/g, '\n'), oid);
  log('CARD with 86 in-flight:\n' + (cardTxt || 'gone'));
  const el = await kds.$(`.kds-card[data-order-id="${oid}"]`);
  if (el) await el.screenshot({ path: SHOTS + 'p6-card-oos.png' });

  // 3) New order containing the 86'd item — does the caisse path still accept it?
  const q2 = await client.post('/admin/pos/quote', payload);
  log('quote WITH 86 item:', q2.status, JSON.stringify(q2.body?.message ?? q2.body?.errors ?? '').slice(0, 220));

  // 4) bump the takeaway → OSS must show Prêt
  const bump = await client.post(`/admin/kds-order/change-status/${oid}`, { status: 8, expected_status: takeawayOrder.status }, idem());
  log('bump takeaway:', bump.status, JSON.stringify(bump.body?.message ?? '').slice(0, 120));
  const oss = await client.get('/admin/oss-order');
  const rows = oss.body?.data || [];
  const mine = rows.find((o) => o.id === oid);
  log('OSS after bump:', oss.status, 'rows:', rows.length, '| our TAKEAWAY:', mine ? `id=${mine.id} status=${mine.status} queue=${mine.queue_number}` : 'ABSENT');

  // 5) restore availability
  const tog2 = await client.post('/admin/menu/availability/toggle', { item_id: 22, branch_id: 1, is_available: true });
  log('restore item 22:', tog2.status);
  console.log(`::ORDER_ID::${oid}`);
} finally {
  await browser.close();
}
