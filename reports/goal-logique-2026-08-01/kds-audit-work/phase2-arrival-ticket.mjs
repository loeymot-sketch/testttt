// Phase 2 — order arrival latency (caisse → KDS) + ticket completeness for the cook.
import { launch, loginAndGetContext, api, createPosOrder, BASE, SHOTS, log } from './lib.mjs';

const browser = await launch();
try {
  const { ctx, token } = await loginAndGetContext(browser);
  if (!token) { log('NO TOKEN'); process.exit(2); }
  const client = api(token);

  // KDS screen (the cook's screen)
  const kds = await ctx.newPage();
  const kdsRequests = [];
  kds.on('request', (r) => {
    const u = r.url();
    if (u.includes('/kds-order')) kdsRequests.push({ t: Date.now(), u: u.replace(BASE, '').slice(0, 90) });
  });
  await kds.goto(`${BASE}/admin/kitchen-display-system`, { waitUntil: 'networkidle' });
  await kds.waitForTimeout(5000);
  const ws = await kds.evaluate(() => ({
    wsState: window._wsService?.state ?? null,
    echo: !!window.Echo,
    echoState: window.Echo?.connector?.pusher?.connection?.state ?? null,
  }));
  log('WS on KDS page:', JSON.stringify(ws));

  // Create the caisse order — Cayenne MIXTE + 2nd sauce + viande supp + note,
  // Menu Enfant (Burger), Cayenne SANS SAUCE.
  const items = [
    {
      item_id: 22, quantity: 1,
      item_variations: [
        { id: 687, quantity: 1 }, // Viande 1 = Mixte (hachée + poulet)
        { id: 289, quantity: 1 }, // Sauce = Samouraï
        { id: 450, quantity: 1 }, // Pain
      ],
      item_extras: [{ id: 398 }, { id: 428 }], // Viande supplémentaire + Sauce supplémentaire
      instruction: 'Sauces en plus : Ketchup | ZZ-TEST bien cuit',
    },
    {
      item_id: 40, quantity: 1,
      item_variations: [
        { id: 584, quantity: 1 }, // Sauce = Ketchup ("Plat enfant" variations are soft-deleted in this DB)
      ],
      item_extras: [],
    },
    {
      item_id: 22, quantity: 1,
      item_variations: [
        { id: 680, quantity: 1 }, // Viande 1 = Poulet mariné
        { id: 688, quantity: 1 }, // Sauce = SANS SAUCE
        { id: 451, quantity: 1 }, // Galette
      ],
      item_extras: [],
    },
  ];

  const t0 = Date.now();
  const res = await createPosOrder(client, { items, customerName: 'ZZ-TEST-AUDIT' });
  const t201 = Date.now();
  log('POS store:', res.step, res.status, JSON.stringify(res.body?.message ?? res.body?.errors ?? '').slice(0, 400));
  if (res.status !== 201 && res.status !== 200) {
    log('QUOTE/STORE FAILED — full body:', JSON.stringify(res.body).slice(0, 1500));
    process.exit(3);
  }
  const order = res.body.data;
  log(`ORDER CREATED id=${order.id} serial=${order.order_serial_no} queue=${order.queue_number} status=${order.status} total=${order.total_currency_price ?? order.total}`);

  // Watch the cook's screen for the queue number to appear.
  const queueNo = String(order.queue_number || '').trim();
  let seenAt = null;
  for (let i = 0; i < 160; i++) { // 160 × 250ms = 40s max
    const found = await kds.evaluate((q) => document.body.innerText.includes(q), queueNo);
    if (found) { seenAt = Date.now(); break; }
    await kds.waitForTimeout(250);
  }
  if (seenAt) {
    log(`ARRIVAL LATENCY: order visible on KDS ${(seenAt - t201) / 1000}s after HTTP 201 (t201-t0 request time ${(t201 - t0) / 1000}s)`);
  } else {
    log('ORDER NEVER APPEARED on KDS within 40s');
  }
  await kds.screenshot({ path: SHOTS + 'p2-kds-after-arrival.png', fullPage: false });

  // Dump the new card's full text — cook-readability check.
  const cardText = await kds.evaluate((q) => {
    const all = Array.from(document.querySelectorAll('div'));
    // find the smallest div containing the queue number (the card)
    const matches = all.filter((d) => d.textContent.includes(q));
    const smallest = matches.sort((a, b) => a.textContent.length - b.textContent.length)[0];
    let node = smallest;
    // climb to a reasonable card container
    while (node && node.parentElement && node.parentElement.textContent.length < smallest.textContent.length + 3000 && !String(node.className).match(/card|kds/i)) {
      node = node.parentElement;
    }
    return (node || smallest)?.innerText || 'NOT FOUND';
  }, queueNo);
  log('=== CARD TEXT AS THE COOK SEES IT ===');
  log(cardText.slice(0, 2500));
  log('=== END CARD ===');

  log('kds-order requests during test:', kdsRequests.length);
  for (const r of kdsRequests.slice(-8)) log('  ', new Date(r.t).toISOString().slice(11, 23), r.u);

  // Persist ids for later phases
  console.log(`::ORDER_ID::${order.id}::QUEUE::${queueNo}`);
} finally {
  await browser.close();
}
