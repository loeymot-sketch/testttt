// VAGUE B dispute — étape 0: créer 2 commandes borne (paiement comptoir) pour la file POS
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger } from './_d1-B-lib.mjs';

const L = mkLogger('b0-borne');
const { browser, page, state } = await boot({ kiosk: true });
const orders = [];
page.on('response', async (r) => {
  if (r.url().includes('frontend/order') && r.request().method() === 'POST' && !r.url().includes('quote') && !r.url().includes('change-status')) {
    try { const j = await r.json(); const d = j?.data || j; orders.push({ status: r.status(), id: d?.id, order_serial_no: d?.order_serial_no, queue: d?.queue_number, total: d?.total ?? d?.order_amount }); L(`ORDER-POST ${r.status()} id=${d?.id} serial=${d?.order_serial_no} queue=${d?.queue_number} total=${d?.total ?? d?.order_amount}`); } catch { L(`ORDER-POST ${r.status()} (non-json)`); }
  }
});

const cartCount = () => page.evaluate(() => { try { return JSON.parse(localStorage.vuex)?.kioskCart?.items?.length || 0; } catch { return 0; } });

async function kioskBoot() {
  await page.goto(BASE + '/kiosk/login', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 8; i++) {
    await page.waitForTimeout(1500);
    const tok = await page.evaluate(() => { try { return !!JSON.parse(localStorage.vuex)?.kioskCart?.kioskToken; } catch { return false; } });
    if (tok) { L(`kioskBoot: token OK (try ${i + 1})`); return true; }
  }
  L('kioskBoot: token NON acquis'); return false;
}

async function addOneSimple() {
  for (const cat of [9, 8, 10, 7, 6, 5]) {
    await page.goto(`${BASE}/kiosk/categories?cat=${cat}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1300);
    const adds = page.locator('[data-testid^="kiosk-product-add-"]');
    const n = await adds.count();
    for (let i = 0; i < n; i++) {
      const before = await cartCount();
      const el = adds.nth(i);
      if (!(await el.isVisible().catch(() => false))) continue;
      const tid = await el.getAttribute('data-testid');
      await el.click().catch(() => {});
      await page.waitForTimeout(1200);
      const overlay = await page.locator('.kiosk-wizard-overlay').isVisible().catch(() => false);
      if (overlay) {
        await page.locator('.kiosk-btn-abandon').first().click().catch(() => {});
        await page.waitForTimeout(300);
        await page.locator('.kiosk-wizard-abandon-yes').click().catch(() => {});
        await page.waitForTimeout(400);
        continue;
      }
      const after = await cartCount();
      if (after > before) { L(`simple ajouté ${tid} (cat=${cat})`); return true; }
    }
  }
  return false;
}

async function placeOrder(idx) {
  await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2200);
  const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
  if (await touch.isVisible().catch(() => false)) { await touch.click({ force: true }); await page.waitForTimeout(900); }
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  if (await takeaway.isVisible().catch(() => false)) { await takeaway.click(); await page.waitForTimeout(1500); }
  const ok = await addOneSimple();
  if (!ok) { L(`order#${idx}: AUCUN produit simple ajouté`); return false; }
  await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  const total = (await page.locator('[data-testid="kiosk-cart-total"]').innerText().catch(() => '?')).replace(/\n/g, ' ');
  L(`order#${idx} cart total="${total}"`);
  if (idx === 1) await snap(page, state, 'b0-01-cart');
  await page.locator('[data-testid="kiosk-cart-checkout"]').click();
  await page.waitForTimeout(2000);
  // loyalty éventuel
  if (page.url().includes('/loyalty')) {
    await page.locator('.kiosk-loyalty-skip').first().click().catch(() => {});
    await page.waitForTimeout(1500);
  }
  if (page.url().includes('/upsell')) {
    await page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {});
    await page.waitForTimeout(1500);
  }
  L(`order#${idx} après checkout → ${page.url().replace(BASE, '')}`);
  await page.waitForTimeout(800);
  if (idx === 1) await snap(page, state, 'b0-02-payment');
  await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click().catch(async () => {
    await page.locator('[data-testid="kiosk-payment-confirm"]').click().catch(() => {});
  });
  await page.waitForTimeout(3500);
  const cashNum = await page.locator('[data-testid="kiosk-cash-order-number"]').innerText().catch(() => 'ABSENT');
  const cashAmt = await page.locator('[data-testid="kiosk-cash-amount"]').innerText().catch(() => 'ABSENT');
  L(`order#${idx} cash-instruction: numéro="${cashNum}" montant="${cashAmt}" url=${page.url().replace(BASE, '')}`);
  if (idx === 1) await snap(page, state, 'b0-03-cash-instruction');
  // retour idle pour la commande suivante
  await page.locator('[data-testid="kiosk-cash-done"], button:has-text("Terminer"), button:has-text("Accueil")').first().click().catch(() => {});
  await page.waitForTimeout(1500);
  return true;
}

await kioskBoot();
await placeOrder(1);
await placeOrder(2);
fs.writeFileSync(OUT + '_b0-borne-orders.json', JSON.stringify(orders, null, 2));
L(`ORDERS: ${JSON.stringify(orders)}`);
L.flush();
await browser.close();
console.log('DONE');
