// D2 VAGUE E — état 1 : commande borne TRAÇANTE post-heal
// Tacos composé 8,50 + Coca 1,50 + promo BORNEAUDIT5 (−5,00, doit FACTURER — C-RED-01/E-ADV-1)
// + rachat fidélité VICT1234 165 pts (doit FACTURER + DÉBITER — C-RED-02)
import fs from 'fs';
import { BASE, OUT, boot, quartet, makeLogger } from './_d2-E-lib.mjs';

const L = makeLogger('E10-borne-order');
const { browser, page, consoleBuf, netBuf } = await boot({ kiosk: true });

const orderPosts = [];
page.on('response', async (r) => {
  const u = r.url();
  if (u.includes('frontend/order') && r.request().method() === 'POST' && !u.includes('change-status')) {
    let body = null; try { body = JSON.parse(r.request().postData() || 'null'); } catch {}
    let json = null; try { json = await r.json(); } catch {}
    orderPosts.push({ url: u.slice(30, 160), status: r.status(), requestBody: body, response: json?.data || json });
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

// --- Tacos composé (id 26, cat 5) ---
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

// --- Coca simple (id 52, cat 10) ---
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
    lineTotals: g('[data-testid^="kiosk-cart-item-total-"]'),
    subtotal: document.querySelector('[data-testid="kiosk-cart-subtotal"]')?.innerText.trim() ?? null,
    promoDiscount: document.querySelector('[data-testid="kiosk-cart-promo-discount"]')?.innerText.trim() ?? null,
    loyaltyDiscount: document.querySelector('[data-testid="kiosk-cart-loyalty-discount"]')?.innerText.trim() ?? null,
    total: document.querySelector('[data-testid="kiosk-cart-total"]')?.innerText.trim() ?? null,
  };
});
const cartBefore = await lineInfo();
L(`CART AVANT PROMO: ${JSON.stringify(cartBefore)}`);
await quartet(page, consoleBuf, netBuf, 'E10-01-cart-avant-promo');

// --- promo BORNEAUDIT5 ---
await page.locator('[data-testid="kiosk-cart-promo-input"]').fill('BORNEAUDIT5');
await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
await page.waitForTimeout(2500);
const promoErr = await page.locator('[data-testid="kiosk-cart-promo-error"]').innerText().catch(() => null);
if (promoErr) L(`PROMO-ERROR: ${promoErr}`);
L(`PROMO-APPLIED: ${JSON.stringify(await page.locator('[data-testid="kiosk-cart-promo-applied"]').innerText().catch(() => null))}`);
const cartAfterPromo = await lineInfo();
L(`CART APRES PROMO: ${JSON.stringify(cartAfterPromo)}`);
await quartet(page, consoleBuf, netBuf, 'E10-02-cart-apres-promo');

// --- checkout → écran fidélité : identifier VICT1234 + racheter ---
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2500);
L(`après checkout → ${page.url().replace(BASE, '')}`);
const loyInput = page.locator('.kiosk-loyalty-input').first();
if (await loyInput.isVisible().catch(() => false)) {
  await loyInput.fill('VICT1234');
  await page.waitForTimeout(500);
  // bouton "vérifier" = .kiosk-btn-primary du step input
  await page.locator('.kiosk-loyalty-step .kiosk-btn-primary').first().click();
  await page.waitForTimeout(3000);
  const balanceVisible = await page.locator('.kiosk-loyalty-points-badge').isVisible().catch(() => false);
  L(`LOYALTY balance step visible=${balanceVisible} text=${JSON.stringify(await page.locator('.kiosk-loyalty-points-badge').innerText().catch(() => null))}`);
  await quartet(page, consoleBuf, netBuf, 'E10-03-loyalty-balance');
  // choisir "utiliser mes points"
  const optYes = page.locator('.kiosk-loyalty-option').first();
  if (await optYes.isVisible().catch(() => false)) {
    L(`option redeem: ${JSON.stringify(await optYes.innerText().catch(() => null))}`);
    await optYes.click();
    await page.waitForTimeout(500);
  }
  await page.locator('.kiosk-loyalty-step .kiosk-btn-primary').first().click();
  await page.waitForTimeout(2500);
  L(`après applyLoyalty → ${page.url().replace(BASE, '')}`);
} else {
  L('WARN: écran fidélité non visible après checkout');
}

// upsell skip éventuel
if (page.url().includes('/upsell')) {
  await page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {});
  await page.waitForTimeout(1800);
}
L(`→ ${page.url().replace(BASE, '')}`);

// si retourné au panier après fidélité (selon flux), re-checkout
if (page.url().includes('/cart')) {
  const cartAfterLoy = await lineInfo();
  L(`CART APRES FIDELITE: ${JSON.stringify(cartAfterLoy)}`);
  await quartet(page, consoleBuf, netBuf, 'E10-04-cart-apres-fidelite');
  await page.locator('[data-testid="kiosk-cart-checkout"]').click();
  await page.waitForTimeout(2500);
  // re-skip loyalty éventuel
  const skip = page.locator('.kiosk-loyalty-skip').first();
  if (await skip.isVisible().catch(() => false)) { await skip.click(); await page.waitForTimeout(1500); }
  if (page.url().includes('/upsell')) {
    await page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {});
    await page.waitForTimeout(1800);
  }
  L(`→ ${page.url().replace(BASE, '')}`);
}

await page.waitForTimeout(1200);
const payInfo = await page.evaluate(() => {
  const q = (s) => document.querySelector(s)?.innerText.replace(/\s+/g, ' ').trim() ?? null;
  return { url: location.pathname, title: q('[data-testid="kiosk-payment-counter-title"]'), total: q('[data-testid="kiosk-payment-counter-total"]'), body: document.body.innerText.replace(/\s+/g, ' ').slice(0, 600) };
});
L(`PAYMENT PLAN B: ${JSON.stringify(payInfo)}`);
await quartet(page, consoleBuf, netBuf, 'E10-05-payment-counter');

await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click().catch(() => L('WARN confirm introuvable'));
await page.waitForTimeout(4000);
const cashInfo = await page.evaluate(() => {
  const q = (s) => document.querySelector(s)?.innerText.replace(/\s+/g, ' ').trim() ?? null;
  return { url: location.pathname + location.search, number: q('[data-testid="kiosk-cash-order-number"]'), amount: q('[data-testid="kiosk-cash-amount"]'), body: document.body.innerText.replace(/\s+/g, ' ').slice(0, 500) };
});
L(`CASH-INSTRUCTION: ${JSON.stringify(cashInfo)}`);
await quartet(page, consoleBuf, netBuf, 'E10-06-cash-instruction');

fs.writeFileSync(`${OUT}_E10-order.json`, JSON.stringify({ chosen, cartBefore, cartAfterPromo, payInfo, cashInfo, orderPosts }, null, 2));
const createPost = orderPosts.filter((p) => !p.url.includes('quote')).pop();
L(`ORDER FINAL: id=${createPost?.response?.id} total=${createPost?.response?.total ?? createPost?.response?.order_amount} status=${createPost?.status}`);
L(`REQUEST BODY promo/loyalty fields: ${JSON.stringify(Object.fromEntries(Object.entries(createPost?.requestBody || {}).filter(([k]) => /promo|coupon|discount|code|loyal/i.test(k))))}`);
L.flush();
await browser.close();
