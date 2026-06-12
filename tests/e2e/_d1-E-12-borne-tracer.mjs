// D1 VAGUE E — run 2 : commande borne TRAÇANTE (Tacos composé 8,50 + Coca 1,50 + BORNEAUDIT5 −5,00)
// + capture du REQUEST BODY du POST /frontend/order (le code promo part-il au backend ?)
import fs from 'fs';
import { BASE, OUT, boot, quartet, makeLogger } from './_d1-E-lib.mjs';

const L = makeLogger('E12-borne-tracer');
const { browser, page, consoleBuf, netBuf } = await boot({ kiosk: true });

const orderPosts = [];
page.on('response', async (r) => {
  const u = r.url();
  if (u.includes('frontend/order') && r.request().method() === 'POST' && !u.includes('change-status')) {
    let body = null; try { body = JSON.parse(r.request().postData() || 'null'); } catch {}
    let json = null; try { json = await r.json(); } catch {}
    const rec = { url: u.slice(30, 160), status: r.status(), requestBody: body, response: json?.data || json };
    orderPosts.push(rec);
    L(`POST ${r.status()} ${u.includes('quote') ? 'QUOTE' : 'ORDER'} ${u.slice(30, 110)}`);
  }
});

// boot token → idle → takeaway
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

const cartCount = () => page.evaluate(() => { try { return JSON.parse(localStorage.vuex)?.kioskCart?.items?.length || 0; } catch { return 0; } });

// --- Tacos composé : viande 1 → sauce 1 → SANS MENU → récap/ajout ---
await page.goto(`${BASE}/kiosk/categories?cat=5`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1800);
await page.locator('[data-testid="kiosk-product-add-26"]').click();
await page.waitForTimeout(2000);
const chosen = [];
for (let s = 0; s < 12; s++) {
  if (!(await page.locator('.kiosk-wizard-overlay').isVisible().catch(() => false))) break;
  const stepInfo = await page.evaluate(() => {
    const ov = document.querySelector('.kiosk-wizard-overlay');
    const vis = (el) => el && el.offsetParent !== null;
    const q = ov.querySelector('.kiosk-step-question')?.innerText?.trim() || '';
    const pick = (sel, prefer) => {
      const cards = Array.from(ov.querySelectorAll(sel)).filter(vis);
      if (!cards.length) return null;
      const target = prefer ? cards.find((c) => c.innerText.toUpperCase().includes(prefer)) || cards[0] : cards[0];
      const label = target.innerText.replace(/\s+/g, ' ').trim().slice(0, 50);
      target.click();
      return label;
    };
    let clicked = null;
    if (ov.querySelector('.kiosk-viande-card')) clicked = pick('.kiosk-viande-card.is-selectable, .kiosk-viande-card:not(.disabled)');
    else if (ov.querySelector('.kiosk-menu-card')) clicked = pick('.kiosk-menu-card', 'SANS MENU');
    else if (ov.querySelector('.kiosk-option-card')) clicked = pick('.kiosk-option-card:not(.disabled)');
    return { q, clicked };
  });
  L(`wizard step "${stepInfo.q}" → choisi "${stepInfo.clicked}"`);
  if (stepInfo.clicked) chosen.push(`${stepInfo.q}: ${stepInfo.clicked}`);
  await page.waitForTimeout(700);
  const adv = await page.evaluate(() => {
    const ov = document.querySelector('.kiosk-wizard-overlay');
    const vis = (el) => el && el.offsetParent !== null;
    const next = Array.from(ov.querySelectorAll('button')).filter(vis).find((b) => !b.disabled && /SUIVANT|AJOUTER|VALIDER|PANIER|TERMINER/i.test(b.innerText));
    if (next) { const t = next.innerText.trim().slice(0, 40); next.click(); return t; }
    return null;
  });
  L(`  avancé via "${adv}"`);
  await page.waitForTimeout(1300);
}
L(`Tacos: wizard fermé=${!(await page.locator('.kiosk-wizard-overlay').isVisible().catch(() => false))} cartCount=${await cartCount()} choix=${JSON.stringify(chosen)}`);

// --- Coca simple ---
await page.goto(`${BASE}/kiosk/categories?cat=10`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1800);
await page.locator('[data-testid="kiosk-product-add-52"]').click();
await page.waitForTimeout(1500);
L(`Coca ajouté: cartCount=${await cartCount()}`);

// --- panier ---
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
await quartet(page, consoleBuf, netBuf, 'E12-01-cart-avant-promo');

// --- promo ---
await page.locator('[data-testid="kiosk-cart-promo-input"]').fill('BORNEAUDIT5');
await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
await page.waitForTimeout(2500);
const promoErr = await page.locator('[data-testid="kiosk-cart-promo-error"]').innerText().catch(() => null);
if (promoErr) L(`PROMO-ERROR: ${promoErr}`);
const promoApplied = await page.locator('[data-testid="kiosk-cart-promo-applied"]').innerText().catch(() => null);
L(`PROMO-APPLIED: ${JSON.stringify(promoApplied)}`);
const cartAfter = await lineInfo();
L(`CART APRES PROMO: ${JSON.stringify(cartAfter)}`);
await quartet(page, consoleBuf, netBuf, 'E12-02-cart-apres-promo');

// --- checkout → upsell skip → payment ---
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2500);
const loySkip = page.locator('[data-testid="kiosk-loyalty-skip"], [data-testid="kiosk-loyalty-continue-without"]');
if (await loySkip.first().isVisible().catch(() => false)) { await loySkip.first().click(); await page.waitForTimeout(1500); }
if (page.url().includes('/upsell')) {
  await page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {});
  await page.waitForTimeout(1800);
}
L(`→ ${page.url().replace(BASE, '')}`);
await page.waitForTimeout(1200);
const payInfo = await page.evaluate(() => {
  const q = (s) => document.querySelector(s)?.innerText.replace(/\s+/g, ' ').trim() ?? null;
  return { title: q('[data-testid="kiosk-payment-counter-title"]'), total: q('[data-testid="kiosk-payment-counter-total"]') };
});
L(`PAYMENT PLAN B: ${JSON.stringify(payInfo)}`);
await quartet(page, consoleBuf, netBuf, 'E12-03-payment-counter');

await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click().catch(() => L('WARN confirm introuvable'));
await page.waitForTimeout(4000);
const cashInfo = await page.evaluate(() => {
  const q = (s) => document.querySelector(s)?.innerText.replace(/\s+/g, ' ').trim() ?? null;
  return { url: location.pathname + location.search, number: q('[data-testid="kiosk-cash-order-number"]'), amount: q('[data-testid="kiosk-cash-amount"]') };
});
L(`CASH-INSTRUCTION: ${JSON.stringify(cashInfo)}`);
await quartet(page, consoleBuf, netBuf, 'E12-04-cash-instruction');

fs.writeFileSync(`${OUT}_E12-order.json`, JSON.stringify({ chosen, cartBefore, cartAfter, payInfo, cashInfo, orderPosts }, null, 2));
const createPost = orderPosts.filter((p) => !p.url.includes('quote')).pop();
L(`ORDER FINAL: id=${createPost?.response?.id} queue=${createPost?.response?.queue_number} total=${createPost?.response?.total ?? createPost?.response?.order_amount}`);
L(`REQUEST BODY keys: ${createPost?.requestBody ? Object.keys(createPost.requestBody).join(',') : 'n/a'}`);
L(`REQUEST BODY promo fields: ${JSON.stringify(Object.fromEntries(Object.entries(createPost?.requestBody || {}).filter(([k]) => /promo|coupon|discount|code/i.test(k))))}`);
L.flush();
await browser.close();
