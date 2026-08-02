// Phase 3 — ticket completeness: what exactly does the cook read on the card?
import { launch, loginAndGetContext, BASE, SHOTS, log } from './lib.mjs';

const ORDER_ID = process.argv[2] || '6039';
const browser = await launch();
try {
  const { ctx } = await loginAndGetContext(browser);
  const kds = await ctx.newPage();
  await kds.goto(`${BASE}/admin/kitchen-display-system`, { waitUntil: 'networkidle' });
  await kds.waitForTimeout(6000);

  const snapshot = await kds.evaluate((oid) => {
    const cards = Array.from(document.querySelectorAll('.kds-card[data-order-id]'));
    const active = cards.map((c) => ({
      id: c.getAttribute('data-order-id'),
      queue: c.querySelector('.kds-card__queue')?.textContent.trim(),
      text: c.innerText.replace(/\n{2,}/g, '\n'),
    }));
    const target = active.find((c) => c.id === String(oid));
    const served = Array.from(document.querySelectorAll('.kds-v2__served-pill')).map((p) => p.textContent.replace(/\s+/g, ' ').trim());
    const overflow = document.querySelector('.kds-overflow-chip')?.textContent.replace(/\s+/g, ' ').trim() || null;
    const gridOrder = active.map((c) => `${c.id}:${c.queue}`);
    return { count: cards.length, gridOrder, target, served, overflow };
  }, ORDER_ID);

  log('ACTIVE CARDS IN GRID (FIFO order):', JSON.stringify(snapshot.gridOrder));
  log('OVERFLOW CHIP:', snapshot.overflow);
  log('SERVED STRIP (' + snapshot.served.length + '):', JSON.stringify(snapshot.served.slice(0, 12)));
  log('=== TARGET CARD ' + ORDER_ID + ' — FULL TEXT ===');
  log(snapshot.target ? snapshot.target.text : 'CARD NOT VISIBLE IN ACTIVE GRID');
  log('=== END ===');

  // Zoom screenshot on the target card if present
  const el = await kds.$(`.kds-card[data-order-id="${ORDER_ID}"]`);
  if (el) await el.screenshot({ path: SHOTS + `p3-card-${ORDER_ID}.png` });
  await kds.screenshot({ path: SHOTS + 'p3-board-full.png', fullPage: true });
} finally {
  await browser.close();
}
