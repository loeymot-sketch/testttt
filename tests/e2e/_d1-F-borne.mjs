// D1 VAGUE F — captures fraîches borne: idle / catalogue / panier (1080×1920)
import { BASE, boot, quartet } from './_d1-F-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot({ width: 1080, height: 1920 });

// boot kiosk token
await page.goto(BASE + '/kiosk/login', { waitUntil: 'domcontentloaded' });
let tok = false;
for (let i = 0; i < 10 && !tok; i++) {
  await page.waitForTimeout(1500);
  tok = await page.evaluate(() => { try { return !!JSON.parse(localStorage.vuex)?.kioskCart?.kioskToken; } catch { return false; } });
}
console.log('kiosk token:', tok);

// F-01 idle
await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3500);
await quartet(page, consoleLog, netLog, 'F-01-borne-idle');

// idle → takeaway
const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
if (await touch.isVisible().catch(() => false)) { await touch.click({ force: true }); await page.waitForTimeout(1200); }
const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
if (await takeaway.isVisible().catch(() => false)) { await takeaway.click(); await page.waitForTimeout(2000); }
console.log('après order-type →', page.url().replace(BASE, ''));

// F-02 catalogue Sandwichs (cat=1 — grille JAMAIS capturée hier)
await page.goto(BASE + '/kiosk/categories?cat=1', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2500);
const catInfo = await page.evaluate(() => {
  const g = (s) => Array.from(document.querySelectorAll(s)).map((e) => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 80));
  return {
    title: document.querySelector('h1,h2,[class*="category-title"]')?.innerText?.trim()?.slice(0, 60) ?? null,
    products: g('[data-testid^="kiosk-product-add-"]').length,
    names: g('[class*="product-name"], [data-testid^="kiosk-product-name-"]').slice(0, 12),
    prices: g('[class*="price"]').slice(0, 14),
  };
});
console.log('CATALOGUE:', JSON.stringify(catInfo));
await quartet(page, consoleLog, netLog, 'F-02-borne-catalogue-sandwichs');

// add Coca-Cola 33cl (item 52, cat 10) — produit simple
await page.goto(BASE + '/kiosk/categories?cat=10', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
const cocaAdd = page.locator('[data-testid="kiosk-product-add-52"]');
if (await cocaAdd.isVisible().catch(() => false)) await cocaAdd.click();
else await page.locator('[data-testid^="kiosk-product-add-"]').first().click().catch(() => {});
await page.waitForTimeout(1500);
// add un 2e produit boisson pour une ligne de plus
await page.locator('[data-testid^="kiosk-product-add-"]').nth(1).click().catch(() => {});
await page.waitForTimeout(1500);

// F-03 panier
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2200);
const cartInfo = await page.evaluate(() => {
  const q = (s) => document.querySelector(s)?.innerText?.replace(/\s+/g, ' ')?.trim() ?? null;
  const g = (s) => Array.from(document.querySelectorAll(s)).map((e) => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 120));
  return {
    names: g('[data-testid^="kiosk-cart-item-name-"]'),
    lineTotals: g('[data-testid^="kiosk-cart-item-total-"]'),
    subtotal: q('[data-testid="kiosk-cart-subtotal"]'),
    total: q('[data-testid="kiosk-cart-total"]'),
    checkoutLabel: q('[data-testid="kiosk-cart-checkout"]'),
  };
});
console.log('CART:', JSON.stringify(cartInfo));
await quartet(page, consoleLog, netLog, 'F-03-borne-panier');

await browser.close();
console.log('F-borne DONE');
