// D1 VAGUE E — étape 1 : commande borne traçante (Tacos composé + Coca simple + promo BORNEAUDIT5)
import fs from 'fs';
import { BASE, OUT, boot, quartet, makeLogger } from './_d1-E-lib.mjs';

const L = makeLogger('E10-borne');
const { browser, page, consoleBuf, netBuf } = await boot({ kiosk: true });

const orderPosts = [];
page.on('response', async (r) => {
  const u = r.url();
  if (u.includes('frontend/order') && r.request().method() === 'POST' && !u.includes('quote') && !u.includes('change-status')) {
    try {
      const j = await r.json();
      const d = j?.data || j;
      orderPosts.push({ status: r.status(), url: u.slice(30, 140), data: d });
      L(`ORDER-POST ${r.status()} id=${d?.id} queue=${d?.queue_number ?? d?.order_serial_no} total=${d?.total ?? d?.order_amount}`);
    } catch { orderPosts.push({ status: r.status(), url: u.slice(30, 140), data: null }); }
  }
});

// --- boot kiosk token ---
await page.goto(BASE + '/kiosk/login', { waitUntil: 'domcontentloaded' });
let tok = false;
for (let i = 0; i < 10 && !tok; i++) {
  await page.waitForTimeout(1500);
  tok = await page.evaluate(() => { try { return !!JSON.parse(localStorage.vuex)?.kioskCart?.kioskToken; } catch { return false; } });
}
L(`kiosk token: ${tok}`);

// --- idle → takeaway ---
await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2500);
const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
if (await touch.isVisible().catch(() => false)) { await touch.click({ force: true }); await page.waitForTimeout(1000); }
const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
if (await takeaway.isVisible().catch(() => false)) { await takeaway.click(); await page.waitForTimeout(1800); }
L(`après order-type → ${page.url().replace(BASE, '')}`);

const cartCount = () => page.evaluate(() => { try { return JSON.parse(localStorage.vuex)?.kioskCart?.items?.length || 0; } catch { return 0; } });

// --- composé : Tacos (item 26, cat 5, wizard catégorie profil 38) ---
async function completeWizard() {
  for (let s = 0; s < 16; s++) {
    const overlay = page.locator('.kiosk-wizard-overlay');
    if (!(await overlay.isVisible().catch(() => false))) return true;
    const opt = page.locator('.kiosk-option-card:not(.disabled), .kiosk-viande-card:not(.disabled), .kiosk-step-menu-option');
    const skip = page.locator('.kiosk-btn-continue, button:has-text("Sans sauce"), button:has-text("Passer")');
    if (await opt.first().isVisible().catch(() => false)) { await opt.first().click().catch(() => {}); await page.waitForTimeout(400); }
    else if (await skip.first().isVisible().catch(() => false)) { await skip.first().click().catch(() => {}); await page.waitForTimeout(400); }
    const next = page.locator('.kiosk-btn-next');
    if (await next.first().isVisible().catch(() => false)) {
      const dis = await next.first().isDisabled().catch(() => false);
      if (!dis) { await next.first().click().catch(() => {}); await page.waitForTimeout(900); } else { await page.waitForTimeout(400); }
    } else { await page.waitForTimeout(400); }
  }
  return !(await page.locator('.kiosk-wizard-overlay').isVisible().catch(() => false));
}

await page.goto(`${BASE}/kiosk/categories?cat=5`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1800);
let before = await cartCount();
const tacosAdd = page.locator('[data-testid="kiosk-product-add-26"]');
if (await tacosAdd.isVisible().catch(() => false)) { await tacosAdd.click(); }
else { L('WARN testid add-26 absent → premier add cat 5'); await page.locator('[data-testid^="kiosk-product-add-"]').first().click().catch(() => {}); }
await page.waitForTimeout(1500);
if (await page.locator('.kiosk-wizard-overlay').isVisible().catch(() => false)) {
  await page.screenshot({ path: `${OUT}E10-01-wizard-tacos-step1.png` });
  const ok = await completeWizard();
  L(`wizard Tacos complété=${ok} cart ${before}→${await cartCount()}`);
} else { L(`WARN pas de wizard sur Tacos — cart ${before}→${await cartCount()}`); }

// --- simple : Coca-Cola 33cl (item 52, cat 10) ---
await page.goto(`${BASE}/kiosk/categories?cat=10`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1800);
before = await cartCount();
const cocaAdd = page.locator('[data-testid="kiosk-product-add-52"]');
if (await cocaAdd.isVisible().catch(() => false)) { await cocaAdd.click(); }
else { L('WARN testid add-52 absent → premier add cat 10'); await page.locator('[data-testid^="kiosk-product-add-"]').first().click().catch(() => {}); }
await page.waitForTimeout(1500);
if (await page.locator('.kiosk-wizard-overlay').isVisible().catch(() => false)) { L('WARN wizard inattendu sur Coca'); await completeWizard(); }
L(`Coca ajouté: cart ${before}→${await cartCount()}`);

// --- panier AVANT promo ---
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
const lineInfo = async () => page.evaluate(() => {
  const g = (s) => Array.from(document.querySelectorAll(s)).map((e) => e.innerText.replace(/\s+/g, ' ').trim());
  return {
    names: g('[data-testid^="kiosk-cart-item-name-"]'),
    options: g('[data-testid^="kiosk-cart-item-options-"]'),
    lineTotals: g('[data-testid^="kiosk-cart-item-total-"]'),
    subtotal: document.querySelector('[data-testid="kiosk-cart-subtotal"]')?.innerText.trim() ?? null,
    promoDiscount: document.querySelector('[data-testid="kiosk-cart-promo-discount"]')?.innerText.trim() ?? null,
    total: document.querySelector('[data-testid="kiosk-cart-total"]')?.innerText.trim() ?? null,
  };
});
const cartBefore = await lineInfo();
L(`CART AVANT PROMO: ${JSON.stringify(cartBefore)}`);
await quartet(page, consoleBuf, netBuf, 'E10-02-cart-avant-promo');

// --- promo BORNEAUDIT5 ---
const promoInput = page.locator('[data-testid="kiosk-cart-promo-input"]');
if (await promoInput.isVisible().catch(() => false)) {
  await promoInput.fill('BORNEAUDIT5');
  await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
  await page.waitForTimeout(2500);
  const err = await page.locator('[data-testid="kiosk-cart-promo-error"]').innerText().catch(() => null);
  if (err) L(`PROMO-ERROR: ${err}`);
  const applied = await page.locator('[data-testid="kiosk-cart-promo-applied"]').innerText().catch(() => null);
  L(`PROMO-APPLIED bloc: ${JSON.stringify(applied)}`);
} else { L('ANOMALIE: input promo INVISIBLE sur le panier borne'); }
const cartAfter = await lineInfo();
L(`CART APRES PROMO: ${JSON.stringify(cartAfter)}`);
await quartet(page, consoleBuf, netBuf, 'E10-03-cart-apres-promo');

// --- checkout → loyalty skip → upsell skip ---
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2500);
L(`après checkout → ${page.url().replace(BASE, '')}`);
const loySkip = page.locator('[data-testid="kiosk-loyalty-skip"], [data-testid="kiosk-loyalty-continue-without"]');
if (await loySkip.first().isVisible().catch(() => false)) { await loySkip.first().click(); await page.waitForTimeout(1500); L('loyalty skipped'); }
if (page.url().includes('/upsell')) {
  const skip = page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]');
  await skip.first().click().catch(() => {});
  await page.waitForTimeout(1800);
  L(`upsell passé → ${page.url().replace(BASE, '')}`);
}

// --- payment Plan B (counter route) ---
await page.waitForTimeout(1200);
const payInfo = await page.evaluate(() => {
  const q = (s) => document.querySelector(s)?.innerText.replace(/\s+/g, ' ').trim() ?? null;
  return {
    counterTitle: q('[data-testid="kiosk-payment-counter-title"]'),
    counterSub: q('[data-testid="kiosk-payment-counter-sub"]'),
    counterTotal: q('[data-testid="kiosk-payment-counter-total"]'),
    methodCards: document.querySelectorAll('[data-testid^="kiosk-payment-method-"]').length,
  };
});
L(`PAYMENT: ${JSON.stringify(payInfo)}`);
await quartet(page, consoleBuf, netBuf, 'E10-04-payment-counter');

await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click().catch(() => L('WARN confirm introuvable'));
await page.waitForTimeout(4000);
L(`après confirm → ${page.url().replace(BASE, '')}`);

// --- cash instruction (nº file + montant) ---
const cashInfo = await page.evaluate(() => {
  const q = (s) => document.querySelector(s)?.innerText.replace(/\s+/g, ' ').trim() ?? null;
  return {
    title: q('[data-testid="kiosk-cash-title"]'),
    number: q('[data-testid="kiosk-cash-order-number"]'),
    amount: q('[data-testid="kiosk-cash-amount"]'),
  };
});
L(`CASH-INSTRUCTION: ${JSON.stringify(cashInfo)}`);
await quartet(page, consoleBuf, netBuf, 'E10-05-cash-instruction');

const last = orderPosts[orderPosts.length - 1];
fs.writeFileSync(`${OUT}_E10-order.json`, JSON.stringify({ cartBefore, cartAfter, payInfo, cashInfo, orderPosts }, null, 2));
L(`ORDER FINAL: ${JSON.stringify({ id: last?.data?.id, queue: last?.data?.queue_number, total: last?.data?.total ?? last?.data?.order_amount })}`);
L.flush();
await browser.close();
