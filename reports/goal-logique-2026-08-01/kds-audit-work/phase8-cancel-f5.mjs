// Phase 8 — annulation caissier PENDANT préparation (multi-écran, sans F5) + reprise F5.
import { launch, loginAndGetContext, api, createPosOrder, idem, BASE, SHOTS, log } from './lib.mjs';

const browser = await launch();
try {
  const { ctx, token } = await loginAndGetContext(browser);
  const client = api(token);

  const res = await createPosOrder(client, {
    items: [{ item_id: 22, quantity: 2, item_variations: [{ id: 687, quantity: 1 }, { id: 281, quantity: 1 }, { id: 450, quantity: 1 }], item_extras: [] }],
    customerName: 'ZZ-TEST-CXL',
  });
  const o = res.body?.data;
  log('order for cancel test:', res.status, o ? `id=${o.id} queue=${o.queue_number} status=${o.status} paid` : JSON.stringify(res.body).slice(0, 200));
  if (!o) process.exit(3);

  const kds = await ctx.newPage();
  await kds.goto(`${BASE}/admin/kitchen-display-system`, { waitUntil: 'networkidle' });
  await kds.waitForTimeout(5000);
  const there = await kds.evaluate((id) => !!document.querySelector(`.kds-card[data-order-id="${id}"]`), o.id);
  log('cook is preparing it (on board):', there);

  // Cashier cancels while the cook prepares — POS change-status → CANCELED (16).
  const tCxl = Date.now();
  const cxl = await client.post(`/admin/pos-order/change-status/${o.id}`, { status: 16, reason: 'ZZ-TEST annulation pendant préparation' }, idem());
  log('POS cancel:', cxl.status, JSON.stringify(cxl.body?.message ?? '').slice(0, 200));

  let goneAt = null;
  for (let i = 0; i < 120; i++) {
    const still = await kds.evaluate((id) => !!document.querySelector(`.kds-card[data-order-id="${id}"]`), o.id);
    if (!still) { goneAt = Date.now(); break; }
    await kds.waitForTimeout(250);
  }
  log(goneAt ? `Card REMOVED from cook's screen ${(goneAt - tCxl) / 1000}s after cancel (no F5)` : 'Card STILL on board 30s after cancel — cook keeps cooking a canceled order!');
  await kds.screenshot({ path: SHOTS + 'p8-after-cancel.png' });

  // Any explicit "annulée" cue for the cook? (toast/banner)
  const cueTxt = await kds.evaluate(() => {
    const t = document.body.innerText;
    const m = t.match(/.{0,120}(annul|cancel).{0,120}/i);
    return m ? m[0].replace(/\s+/g, ' ') : null;
  });
  log('cancel cue on screen:', JSON.stringify(cueTxt));

  // F5 resilience: create another order, bump it PREPARING (already), then reload the page.
  const res2 = await createPosOrder(client, {
    items: [{ item_id: 38, quantity: 1, item_variations: [{ id: 170, quantity: 1 }], item_extras: [] }],
    customerName: 'ZZ-TEST-F5',
  });
  const o2 = res2.body?.data;
  log('order for F5 test:', res2.status, o2?.id, o2?.queue_number);
  await kds.waitForTimeout(3000);
  const preF5 = await kds.evaluate(() => Array.from(document.querySelectorAll('.kds-card[data-order-id]')).map((c) => `${c.getAttribute('data-order-id')}:${c.querySelector('.kds-card__state-pill')?.textContent.trim()}`));
  await kds.reload({ waitUntil: 'networkidle' });
  await kds.waitForTimeout(5000);
  const postF5 = await kds.evaluate(() => Array.from(document.querySelectorAll('.kds-card[data-order-id]')).map((c) => `${c.getAttribute('data-order-id')}:${c.querySelector('.kds-card__state-pill')?.textContent.trim()}`));
  log('grid before F5:', JSON.stringify(preF5));
  log('grid after  F5:', JSON.stringify(postF5));
  log('identical:', JSON.stringify(preF5) === JSON.stringify(postF5));
  console.log(`::CXL::${o.id}::F5::${o2?.id}`);
} finally {
  await browser.close();
}
