// D2 VAGUE E — état 1bis : commande borne TRAÇANTE COMPLÈTE
// Tacos composé 8,50 + Coca 1,50 + BORNEAUDIT5 −5,00 + rachat fidélité VICT1234 (165 pts)
import fs from 'fs';
import { BASE, OUT, boot, quartet, makeLogger } from './_d2-E-lib.mjs';

const L = makeLogger('E11-borne-loyalty');
const { browser, page, consoleBuf, netBuf } = await boot({ kiosk: true });

const orderPosts = [];
page.on('response', async (r) => {
  const u = r.url();
  if ((u.includes('frontend/order') || u.includes('loyalty')) && r.request().method() === 'POST') {
    let body = null; try { body = JSON.parse(r.request().postData() || 'null'); } catch {}
    let json = null; try { json = await r.json(); } catch {}
    orderPosts.push({ url: u.slice(30, 160), status: r.status(), requestBody: body, response: json?.data || json });
    L(`POST ${r.status()} ${u.slice(30, 130)}`);
  }
});

await page.goto(BASE + '/kiosk/login', { waitUntil: 'domcontentloaded' });
for (let i = 0; i < 10; i++) {
  await page.waitForTimeout(1500);
  if (await page.evaluate(() => { try { return !!JSON.parse(localStorage.vuex)?.kioskCart?.kioskToken; } catch { return false; } })) break;
}
await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2500);
const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
if (await touch.isVisible().catch(() => false)) { await touch.click({ force: true }); await page.waitForTimeout(1000); }
const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
if (await takeaway.isVisible().catch(() => false)) { await takeaway.click(); await page.waitForTimeout(1800); }

// Tacos composé
await page.goto(`${BASE}/kiosk/categories?cat=5`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1800);
await page.locator('[data-testid="kiosk-product-add-26"]').click();
await page.waitForTimeout(2000);
for (let s = 0; s < 12; s++) {
  if (!(await page.locator('.kiosk-wizard-overlay').isVisible().catch(() => false))) break;
  await page.evaluate(() => {
    const ov = document.querySelector('.kiosk-wizard-overlay');
    const vis = (el) => el && el.offsetParent !== null;
    const pick = (sel, prefer) => {
      const cards = Array.from(ov.querySelectorAll(sel)).filter(vis);
      if (!cards.length) return;
      (prefer ? cards.find((c) => c.innerText.toUpperCase().includes(prefer)) || cards[0] : cards[0]).click();
    };
    if (ov.querySelector('.kiosk-viande-card')) pick('.kiosk-viande-card.is-selectable, .kiosk-viande-card:not(.disabled)');
    else if (ov.querySelector('.kiosk-menu-card')) pick('.kiosk-menu-card', 'SANS MENU');
    else if (ov.querySelector('.kiosk-option-card')) pick('.kiosk-option-card:not(.disabled)');
  });
  await page.waitForTimeout(700);
  await page.evaluate(() => {
    const ov = document.querySelector('.kiosk-wizard-overlay');
    const vis = (el) => el && el.offsetParent !== null;
    const next = Array.from(ov.querySelectorAll('button')).filter(vis).find((b) => !b.disabled && /SUIVANT|AJOUTER|VALIDER|PANIER|TERMINER/i.test(b.innerText));
    if (next) next.click();
  });
  await page.waitForTimeout(1300);
}
// Coca simple
await page.goto(`${BASE}/kiosk/categories?cat=10`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1800);
await page.locator('[data-testid="kiosk-product-add-52"]').click();
await page.waitForTimeout(1500);

// panier + promo
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
await page.locator('[data-testid="kiosk-cart-promo-input"]').fill('BORNEAUDIT5');
await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
await page.waitForTimeout(2500);

// fidélité depuis le panier
await page.locator('[data-testid="kiosk-cart-loyalty-btn"]').click();
await page.waitForTimeout(2000);
L(`→ ${page.url().replace(BASE, '')}`);
await page.locator('.kiosk-loyalty-input').first().fill('VICT1234');
await page.waitForTimeout(400);
await page.locator('.kiosk-loyalty-step .kiosk-btn-primary').first().click();
await page.waitForTimeout(3000);
const balText = await page.locator('.kiosk-loyalty-points-badge').innerText().catch(() => null);
L(`LOYALTY balance: ${JSON.stringify(balText)}`);
const optYes = page.locator('.kiosk-loyalty-option').first();
L(`option redeem: ${JSON.stringify(await optYes.innerText().catch(() => null))}`);
await quartet(page, consoleBuf, netBuf, 'E11-01-loyalty-balance');
await optYes.click().catch(() => L('WARN option redeem introuvable'));
await page.waitForTimeout(500);
await page.locator('.kiosk-loyalty-step .kiosk-btn-primary').first().click();
await page.waitForTimeout(2500);
L(`après applyLoyalty → ${page.url().replace(BASE, '')}`);

// panier final
if (!page.url().includes('/cart')) await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
const cartFinal = await page.evaluate(() => {
  const q = (s) => document.querySelector(s)?.innerText.replace(/\s+/g, ' ').trim() ?? null;
  const g = (s) => Array.from(document.querySelectorAll(s)).map((e) => e.innerText.replace(/\s+/g, ' ').trim());
  return {
    names: g('[data-testid^="kiosk-cart-item-name-"]'),
    lineTotals: g('[data-testid^="kiosk-cart-item-total-"]'),
    subtotal: q('[data-testid="kiosk-cart-subtotal"]'),
    promo: q('[data-testid="kiosk-cart-promo-discount"]'),
    loyalty: q('[data-testid="kiosk-cart-loyalty-discount"]'),
    total: q('[data-testid="kiosk-cart-total"]'),
  };
});
L(`CART FINAL: ${JSON.stringify(cartFinal)}`);
await quartet(page, consoleBuf, netBuf, 'E11-02-cart-final');

// checkout → payment → confirm
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2500);
if (page.url().includes('/upsell')) {
  await page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {});
  await page.waitForTimeout(1800);
}
L(`→ ${page.url().replace(BASE, '')}`);
const payInfo = await page.evaluate(() => {
  const q = (s) => document.querySelector(s)?.innerText.replace(/\s+/g, ' ').trim() ?? null;
  return { total: q('[data-testid="kiosk-payment-counter-total"]'), body: document.body.innerText.replace(/\s+/g, ' ').slice(0, 400) };
});
L(`PAYMENT: ${JSON.stringify(payInfo)}`);
await quartet(page, consoleBuf, netBuf, 'E11-03-payment-counter');
await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click().catch(() => L('WARN confirm introuvable'));
await page.waitForTimeout(4000);
const cashInfo = await page.evaluate(() => {
  const q = (s) => document.querySelector(s)?.innerText.replace(/\s+/g, ' ').trim() ?? null;
  return { url: location.pathname + location.search, number: q('[data-testid="kiosk-cash-order-number"]'), amount: q('[data-testid="kiosk-cash-amount"]') };
});
L(`CASH-INSTRUCTION: ${JSON.stringify(cashInfo)}`);
await quartet(page, consoleBuf, netBuf, 'E11-04-cash-instruction');

fs.writeFileSync(`${OUT}_E11-order.json`, JSON.stringify({ cartFinal, payInfo, cashInfo, orderPosts }, null, 2));
const createPost = orderPosts.filter((p) => p.url.includes('order') && !p.url.includes('quote') && !p.url.includes('loyalty')).pop();
L(`ORDER FINAL: id=${createPost?.response?.id} total=${createPost?.response?.total ?? createPost?.response?.order_amount} status=${createPost?.status}`);
L(`REQUEST BODY fields: ${JSON.stringify(Object.fromEntries(Object.entries(createPost?.requestBody || {}).filter(([k]) => /promo|coupon|discount|code|loyal/i.test(k))))}`);
L.flush();
await browser.close();
