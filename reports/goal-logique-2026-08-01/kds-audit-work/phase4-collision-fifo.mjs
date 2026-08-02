// Phase 4 — CHI collision (Chicken Burger vs Menu Enfant Chicken Burger) + FIFO + names toggle.
import { launch, loginAndGetContext, api, createPosOrder, BASE, SHOTS, log } from './lib.mjs';

const browser = await launch();
try {
  const { ctx, token } = await loginAndGetContext(browser);
  const client = api(token);

  const res = await createPosOrder(client, {
    items: [
      { item_id: 38, quantity: 1, item_variations: [{ id: 168, quantity: 1 }], item_extras: [] },  // Chicken Burger, Ketchup
      { item_id: 106, quantity: 1, item_variations: [{ id: 674, quantity: 1 }], item_extras: [] }, // Menu Enfant Chicken Burger, Ketchup
    ],
    customerName: 'ZZ-TEST-CHI',
  });
  log('store:', res.status, res.body?.data?.id, res.body?.data?.queue_number, JSON.stringify(res.body?.message ?? '').slice(0, 200));
  if (!res.body?.data?.id) process.exit(3);
  const oid = res.body.data.id;

  const kds = await ctx.newPage();
  await kds.goto(`${BASE}/admin/kitchen-display-system`, { waitUntil: 'networkidle' });
  await kds.waitForTimeout(6000);

  const snap = await kds.evaluate((id) => {
    const cards = Array.from(document.querySelectorAll('.kds-card[data-order-id]'));
    return {
      gridOrder: cards.map((c) => `${c.getAttribute('data-order-id')}:${c.querySelector('.kds-card__queue')?.textContent.trim()}`),
      target: cards.find((c) => c.getAttribute('data-order-id') === String(id))?.innerText.replace(/\n{2,}/g, '\n') || 'NOT FOUND',
    };
  }, oid);
  log('GRID FIFO ORDER:', JSON.stringify(snap.gridOrder));
  log('=== CARD (symbols mode) ===\n' + snap.target + '\n=== END ===');

  const el1 = await kds.$(`.kds-card[data-order-id="${oid}"]`);
  if (el1) await el1.screenshot({ path: SHOTS + `p4-card-${oid}-symbols.png` });

  // Toggle "Afficher les noms" and re-dump
  const toggled = await kds.evaluate(() => {
    const btns = Array.from(document.querySelectorAll('button'));
    const b = btns.find((x) => /afficher les noms/i.test(x.textContent));
    if (b) { b.click(); return true; }
    return false;
  });
  await kds.waitForTimeout(1200);
  if (toggled) {
    const after = await kds.evaluate((id) => {
      const c = Array.from(document.querySelectorAll('.kds-card[data-order-id]')).find((x) => x.getAttribute('data-order-id') === String(id));
      return c?.innerText.replace(/\n{2,}/g, '\n') || 'NOT FOUND';
    }, oid);
    log('=== CARD (names mode) ===\n' + after + '\n=== END ===');
    const el2 = await kds.$(`.kds-card[data-order-id="${oid}"]`);
    if (el2) await el2.screenshot({ path: SHOTS + `p4-card-${oid}-names.png` });
  } else {
    log('names toggle NOT FOUND');
  }
  console.log(`::ORDER_ID::${oid}`);
} finally {
  await browser.close();
}
