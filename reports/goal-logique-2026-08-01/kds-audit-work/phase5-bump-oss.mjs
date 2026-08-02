// Phase 5 — bump «Prêt» on KDS UI, double-bump protection, OSS propagation, multi-screen sync.
import { launch, loginAndGetContext, api, idem, BASE, SHOTS, log } from './lib.mjs';

const ORDER_ID = parseInt(process.argv[2] || '6039', 10);
const browser = await launch();
try {
  const { ctx, token } = await loginAndGetContext(browser);
  const client = api(token);

  // Screen A = cook screen 1 ; Screen B = cook screen 2 (multi-écran)
  const kdsA = await ctx.newPage();
  await kdsA.goto(`${BASE}/admin/kitchen-display-system`, { waitUntil: 'networkidle' });
  const kdsB = await ctx.newPage();
  await kdsB.goto(`${BASE}/admin/kitchen-display-system`, { waitUntil: 'networkidle' });
  await kdsA.waitForTimeout(5000);

  const before = await kdsA.evaluate((id) => {
    const c = document.querySelector(`.kds-card[data-order-id="${id}"]`);
    return c ? { queue: c.querySelector('.kds-card__queue')?.textContent.trim(), hasPret: !!Array.from(c.querySelectorAll('button')).find((b) => /prêt/i.test(b.textContent)) } : null;
  }, ORDER_ID);
  log('Card before bump on screen A:', JSON.stringify(before));
  if (!before) { log('ORDER NOT ON BOARD — abort'); process.exit(3); }

  // UI bump on screen A
  const tBump = Date.now();
  await kdsA.evaluate((id) => {
    const c = document.querySelector(`.kds-card[data-order-id="${id}"]`);
    const b = Array.from(c.querySelectorAll('button')).find((x) => /prêt/i.test(x.textContent));
    b.click();
  }, ORDER_ID);

  // Wait for card to leave the active grid on screen A
  let leftA = null;
  for (let i = 0; i < 80; i++) {
    const still = await kdsA.evaluate((id) => !!document.querySelector(`.kds-card[data-order-id="${id}"]`), ORDER_ID);
    if (!still) { leftA = Date.now(); break; }
    await kdsA.waitForTimeout(250);
  }
  log(leftA ? `Screen A: card left active grid ${(leftA - tBump) / 1000}s after click` : 'Screen A: card STILL in grid after 20s');

  // Screen B (other station) — does it drop the card without F5?
  let leftB = null;
  for (let i = 0; i < 80; i++) {
    const still = await kdsB.evaluate((id) => !!document.querySelector(`.kds-card[data-order-id="${id}"]`), ORDER_ID);
    if (!still) { leftB = Date.now(); break; }
    await kdsB.waitForTimeout(250);
  }
  log(leftB ? `Screen B: card left grid ${(leftB - tBump) / 1000}s after A's click (no F5)` : 'Screen B: card STILL there after 20s (needs F5!)');

  // Served strip shows it?
  const servedA = await kdsA.evaluate(() => Array.from(document.querySelectorAll('.kds-v2__served-pill')).map((p) => p.textContent.replace(/\s+/g, ' ').trim()));
  log('Served strip on A:', JSON.stringify(servedA));
  await kdsA.screenshot({ path: SHOTS + 'p5-after-bump.png' });

  // DOUBLE-BUMP at API level: replay PREPARING→PREPARED after it is already PREPARED.
  const db1 = await client.post(`/admin/kds-order/change-status/${ORDER_ID}`, { status: 8, expected_status: 7 }, idem());
  log('API replay bump (expected_status=7 while already 8):', db1.status, JSON.stringify(db1.body?.message ?? '').slice(0, 140));
  // Same-status idempotent call (8→8, expected 8):
  const db2 = await client.post(`/admin/kds-order/change-status/${ORDER_ID}`, { status: 8, expected_status: 8 }, idem());
  log('API bump 8→8 (expected 8):', db2.status, JSON.stringify(db2.body?.message ?? '').slice(0, 140));

  // OSS: is the order announced "Prêt"?
  const oss = await client.get('/admin/oss-order');
  const ossRows = oss.body?.data || [];
  const mine = ossRows.find((o) => o.id === ORDER_ID);
  log('OSS list status:', oss.status, '| rows:', ossRows.length, '| our order:', mine ? `id=${mine.id} status=${mine.status} queue=${mine.queue_number}` : 'ABSENT');
  const sample = ossRows.slice(0, 8).map((o) => `${o.id}:${o.queue_number}:s${o.status}`);
  log('OSS sample:', JSON.stringify(sample));
} finally {
  await browser.close();
}
