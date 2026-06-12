// META-DISPUTE V-3 — promo borne affichée vs persistée (attaque C-01/C-07) — jetable
import { chromium } from 'playwright';
import fs from 'fs';

const BASE = 'http://127.0.0.1:8768';
const OUT = new URL('../../reports/test-e2e/dispute-2026-06-12/round-1/meta/', import.meta.url).pathname;
fs.mkdirSync(OUT, { recursive: true });
const lines = [];
const L = (m) => { const s = `[${new Date().toISOString().slice(11, 19)}] ${m}`; lines.push(s); console.log(s); };

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const context = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR' });
const page = await context.newPage();

const orderPosts = [];
page.on('response', async (r) => {
  const u = r.url();
  if (u.includes('frontend/order') && r.request().method() === 'POST') {
    try {
      const j = await r.json();
      const d = j?.data || j;
      orderPosts.push({ status: r.status(), url: u.slice(26, 110), id: d?.id, queue: d?.queue_number, subtotal: d?.subtotal, discount: d?.discount, total: d?.total });
      L(`POST ${r.status()} ${u.includes('quote') ? 'QUOTE' : 'ORDER'} id=${d?.id ?? '-'} subtotal=${d?.subtotal} discount=${d?.discount} total=${d?.total}`);
    } catch { /* noop */ }
  }
});

// boot kiosk token
await page.goto(BASE + '/kiosk/login', { waitUntil: 'domcontentloaded' });
let tok = false;
for (let i = 0; i < 10 && !tok; i++) {
  await page.waitForTimeout(1500);
  tok = await page.evaluate(() => { try { return !!JSON.parse(localStorage.vuex)?.kioskCart?.kioskToken; } catch { return false; } });
}
L(`kiosk token: ${tok}`);

// idle → takeaway
await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2500);
const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
if (await touch.isVisible().catch(() => false)) { await touch.click({ force: true }); await page.waitForTimeout(1000); }
const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
if (await takeaway.isVisible().catch(() => false)) { await takeaway.click(); await page.waitForTimeout(1800); }
L(`après order-type → ${page.url().replace(BASE, '')}`);

// produit simple Coca 52 (cat 10)
await page.goto(`${BASE}/kiosk/categories?cat=10`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1800);
const cocaAdd = page.locator('[data-testid="kiosk-product-add-52"]');
if (await cocaAdd.isVisible().catch(() => false)) { await cocaAdd.click(); }
else { await page.locator('[data-testid^="kiosk-product-add-"]').first().click().catch(() => {}); }
await page.waitForTimeout(1500);

// panier + promo
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
const cartInfo = async () => page.evaluate(() => {
  const q = (s) => document.querySelector(s)?.innerText.replace(/\s+/g, ' ').trim() ?? null;
  return {
    subtotal: q('[data-testid="kiosk-cart-subtotal"]'),
    promoDiscount: q('[data-testid="kiosk-cart-promo-discount"]'),
    total: q('[data-testid="kiosk-cart-total"]'),
    applied: q('[data-testid="kiosk-cart-promo-applied"]'),
    error: q('[data-testid="kiosk-cart-promo-error"]'),
  };
});
L(`CART avant promo: ${JSON.stringify(await cartInfo())}`);
const promoInput = page.locator('[data-testid="kiosk-cart-promo-input"]');
if (await promoInput.isVisible().catch(() => false)) {
  await promoInput.fill('BORNEAUDIT5');
  await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
  await page.waitForTimeout(2500);
}
const after = await cartInfo();
L(`CART après promo: ${JSON.stringify(after)}`);
await page.screenshot({ path: `${OUT}meta-V3-cart-promo.png` });

// checkout → skips → payment
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2500);
const loySkip = page.locator('[data-testid="kiosk-loyalty-skip"], [data-testid="kiosk-loyalty-continue-without"]');
if (await loySkip.first().isVisible().catch(() => false)) { await loySkip.first().click(); await page.waitForTimeout(1500); }
if (page.url().includes('/upsell')) {
  await page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {});
  await page.waitForTimeout(1800);
}
await page.waitForTimeout(1200);
const payTotal = await page.evaluate(() => document.querySelector('[data-testid="kiosk-payment-counter-total"]')?.innerText.replace(/\s+/g, ' ').trim() ?? null);
L(`PAYMENT total affiché: ${JSON.stringify(payTotal)}`);
await page.screenshot({ path: `${OUT}meta-V3-payment.png` });

await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click().catch(() => L('WARN confirm introuvable'));
await page.waitForTimeout(4500);
const cash = await page.evaluate(() => {
  const q = (s) => document.querySelector(s)?.innerText.replace(/\s+/g, ' ').trim() ?? null;
  return { number: q('[data-testid="kiosk-cash-order-number"]'), amount: q('[data-testid="kiosk-cash-amount"]') };
});
L(`CASH-INSTRUCTION: ${JSON.stringify(cash)} url=${page.url().replace(BASE, '')}`);
await page.screenshot({ path: `${OUT}meta-V3-cash-instruction.png` });

fs.writeFileSync(`${OUT}_V3-promo.json`, JSON.stringify({ cartAfterPromo: after, payTotal, cash, orderPosts }, null, 2));
fs.writeFileSync(`${OUT}_V3-log.txt`, lines.join('\n') + '\n');
await browser.close();
