// D2 VAGUE F — borne post-heals: idle light / grille sans SKU / panier / paiement (retour) / cash-instruction (CTA+reset) — 1080×1920
import { BASE, boot, quartet } from './_d2-F-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot({ width: 1080, height: 1920 });

// boot kiosk token
await page.goto(BASE + '/kiosk/login', { waitUntil: 'domcontentloaded' });
let tok = false;
for (let i = 0; i < 10 && !tok; i++) {
  await page.waitForTimeout(1500);
  tok = await page.evaluate(() => { try { return !!JSON.parse(localStorage.vuex)?.kioskCart?.kioskToken; } catch { return false; } });
}
console.log('kiosk token:', tok);

// ===== F2-01 idle — HEAL ADV-F-P1-3 (light mode) =====
await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3500);
const idleProbe = await page.evaluate(() => {
  const root = document.querySelector('[class*="kiosk-idle"]');
  const fb = document.querySelector('.kiosk-idle-fallback');
  const ov = document.querySelector('.kiosk-idle-overlay');
  const cs = (el) => el ? getComputedStyle(el) : null;
  return {
    rootClasses: root ? root.className.slice(0, 200) : null,
    hasVideoClass: !!document.querySelector('.kiosk-idle--has-video'),
    fallbackBgImage: fb ? cs(fb).backgroundImage.slice(0, 220) : null,
    overlayBg: ov ? cs(ov).background.slice(0, 150) : null,
    titleColor: (() => { const t = document.querySelector('.kiosk-idle-content h1, .kiosk-idle-content [class*="title"]'); return t ? cs(t).color : null; })(),
    bodyText: document.body.innerText.replace(/\s+/g, ' ').slice(0, 300),
  };
});
console.log('IDLE-PROBE:', JSON.stringify(idleProbe, null, 1));
await quartet(page, consoleLog, netLog, 'F2-01-borne-idle');

// idle → takeaway
const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
if (await touch.isVisible().catch(() => false)) { await touch.click({ force: true }); await page.waitForTimeout(1200); }
const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
if (await takeaway.isVisible().catch(() => false)) { await takeaway.click(); await page.waitForTimeout(2000); }
console.log('après order-type →', page.url().replace(BASE, ''));

// ===== F2-02 catalogue Sandwich Cayenne — HEAL ADV-F-P1-2 (3 SKU techniques sortis) =====
await page.goto(BASE + '/kiosk/categories?cat=1', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2500);
const catInfo = await page.evaluate(() => {
  const cards = Array.from(document.querySelectorAll('[data-testid^="kiosk-product-add-"], [class*="product-card"]'));
  const names = Array.from(document.querySelectorAll('[class*="product-name"], [data-testid^="kiosk-product-name-"]')).map((e) => e.innerText.replace(/\s+/g, ' ').trim());
  const sidebar = Array.from(document.querySelectorAll('[class*="category"] [class*="name"], [class*="sidebar"] [class*="label"]')).map((e) => e.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean).slice(0, 20);
  const brokenImgs = Array.from(document.querySelectorAll('img')).filter((i) => i.complete && i.naturalWidth === 0).map((i) => i.src.slice(-60));
  return {
    productCount: cards.length,
    names: names.slice(0, 16),
    skuTechniques: names.filter((n) => /frites seules|boisson seule|menu \(frites/i.test(n)),
    upsellItemDesc: document.body.innerText.includes('Upsell item'),
    sidebar,
    brokenImgs: brokenImgs.slice(0, 6),
  };
});
console.log('CATALOGUE:', JSON.stringify(catInfo, null, 1));
await quartet(page, consoleLog, netLog, 'F2-02-borne-catalogue-sandwichs');

// add Coca-Cola 33cl (item 52, cat 10) — produit simple, 2 boissons
await page.goto(BASE + '/kiosk/categories?cat=10', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
const cocaAdd = page.locator('[data-testid="kiosk-product-add-52"]');
if (await cocaAdd.isVisible().catch(() => false)) await cocaAdd.click();
else await page.locator('[data-testid^="kiosk-product-add-"]').first().click().catch(() => {});
await page.waitForTimeout(1800);
await page.locator('[data-testid^="kiosk-product-add-"]').nth(1).click().catch(() => {});
await page.waitForTimeout(1800);

// ===== F2-03 panier =====
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2200);
const cartInfo = await page.evaluate(() => {
  const q = (s) => document.querySelector(s)?.innerText?.replace(/\s+/g, ' ')?.trim() ?? null;
  const g = (s) => Array.from(document.querySelectorAll(s)).map((e) => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 120));
  // cibles tactiles GAP-14
  const small = Array.from(document.querySelectorAll('button')).map((b) => { const r = b.getBoundingClientRect(); return { t: (b.getAttribute('aria-label') || b.innerText || b.className).replace(/\s+/g, ' ').slice(0, 40), w: Math.round(r.width), h: Math.round(r.height) }; }).filter((x) => x.w > 0 && (x.w < 48 || x.h < 48));
  return {
    names: g('[data-testid^="kiosk-cart-item-name-"]'),
    total: q('[data-testid="kiosk-cart-total"]'),
    checkoutLabel: q('[data-testid="kiosk-cart-checkout"]'),
    targetsUnder48: small.slice(0, 10),
  };
});
console.log('CART:', JSON.stringify(cartInfo, null, 1));
await quartet(page, consoleLog, netLog, 'F2-03-borne-panier');

// ===== checkout → upsell (skip) → paiement Plan B =====
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(3000);
if (page.url().includes('/upsell')) {
  const skip = page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]');
  await skip.first().click().catch(() => {});
  await page.waitForTimeout(2500);
}
console.log('après checkout →', page.url().replace(BASE, ''));

// ===== F2-03b paiement Plan B — HEAL D-003/ADV-F-P1-1 (bouton retour) + GAP-03 =====
const payProbe = await page.evaluate(() => {
  const back = document.querySelector('[data-testid="kiosk-payment-counter-back"]');
  const confirm = document.querySelector('[data-testid="kiosk-payment-counter-confirm"]');
  const total = document.querySelector('[data-testid="kiosk-payment-counter-total"]');
  const rect = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top), h: Math.round(r.height), w: Math.round(r.width) }; };
  const cs = (el) => el ? getComputedStyle(el) : null;
  return {
    url: location.pathname,
    backPresent: !!back, backText: back?.innerText?.trim() ?? null, backRect: rect(back),
    confirmText: confirm?.innerText?.replace(/\s+/g, ' ')?.trim() ?? null, confirmRect: rect(confirm),
    confirmTextAlign: confirm ? cs(confirm).textAlign : null,
    totalText: total?.innerText?.replace(/\s+/g, ' ')?.trim() ?? null,
    totalBg: total ? cs(total).backgroundColor : null,
    confirmBg: confirm ? cs(confirm).backgroundColor : null,
    viewportH: innerHeight,
  };
});
console.log('PAYMENT:', JSON.stringify(payProbe, null, 1));
await quartet(page, consoleLog, netLog, 'F2-03b-borne-paiement-planB');

// ===== confirmer → cash-instruction — HEAL C-ADV-06 (copy unifiée) + cta_back_home + reset panier =====
await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click();
await page.waitForTimeout(4500);
console.log('après confirm →', page.url().replace(BASE, ''));
const cashProbe = await page.evaluate(() => {
  const txt = document.body.innerText.replace(/\s+/g, ' ');
  return {
    orderNo: (txt.match(/[A-Z]\d{4}/) || [null])[0],
    helpCopy: (txt.match(/Réglez[^.]*\./) || txt.match(/caisse[^.]*\./i) || [null])[0],
    hasUniquement: /uniquement/i.test(txt),
    ctaButtons: Array.from(document.querySelectorAll('button, a')).map((b) => b.innerText.replace(/\s+/g, ' ').trim()).filter((t) => t && t.length < 50),
  };
});
console.log('CASH-INSTRUCTION:', JSON.stringify(cashProbe, null, 1));
await quartet(page, consoleLog, netLog, 'F2-03c-borne-cash-instruction');

// panier vidé ? (D-003)
const cartCount = await page.evaluate(() => { try { return (JSON.parse(localStorage.vuex)?.kioskCart?.lists || []).length; } catch { return 'ERR'; } });
console.log('PANIER POST-COMMANDE (store lists):', cartCount);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
const emptyCart = await page.evaluate(() => ({ url: location.pathname, body: document.body.innerText.replace(/\s+/g, ' ').slice(0, 250) }));
console.log('CART-APRÈS:', JSON.stringify(emptyCart));
await quartet(page, consoleLog, netLog, 'F2-03d-borne-panier-apres-commande');

await browser.close();
console.log('F2-borne DONE');
