// Baseline anti-régression Vague 0 — GOAL UIUX caisse+borne 2026-06-11 (W0)
// Usage: node tests/e2e/_baseline-capture-2026-06-11.mjs
import { chromium } from 'playwright';
import fs from 'fs';

const BASE = 'http://127.0.0.1:8768';
const OUT = 'tests/captures/baseline-25cb5dac1';
fs.mkdirSync(OUT, { recursive: true });

const results = [];
async function login(page) {
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[autocomplete="email"]', 'admin@lecayenne.fr');
  await page.fill('input[type="password"]', '123456');
  await page.click('button[type="submit"]');
  await page.waitForTimeout(3500);
}
async function snap(page, url, name, { wait = 1800, sel = null, auth = false } = {}) {
  try {
    await page.goto(BASE + url, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(800);
    if (auth && page.url().includes('/login')) {
      results.push(`RELOGIN before ${name}`);
      await login(page);
      await page.goto(BASE + url, { waitUntil: 'domcontentloaded', timeout: 30000 });
    }
    if (sel) await page.waitForSelector(sel, { timeout: 12000 }).catch(() => {});
    await page.waitForTimeout(wait);
    await page.screenshot({ path: `${OUT}/${name}.png` });
    results.push(`OK   ${name}  ${url}  → final=${page.url().replace(BASE, '')}`);
  } catch (e) {
    results.push(`FAIL ${name}  ${url}  ${e.message.split('\n')[0]}`);
  }
}

const browser = await chromium.launch();

// ---------- ADMIN / CAISSE (1440x900) ----------
const admin = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
const ap = await admin.newPage();
ap.on('console', m => { if (m.type() === 'error') results.push(`CONSOLE-ERR ${ap.url().replace(BASE, '')} :: ${m.text().slice(0, 160)}`); });
await login(ap);
results.push(`LOGIN admin → ${ap.url().replace(BASE, '')}`);

await snap(ap, '/admin/pos-orders', 'pos-orders', { wait: 2500, auth: true });
await snap(ap, '/admin/pos-orders/show/4511', 'pos-orders-show', { wait: 2500, auth: true });
await snap(ap, '/admin/pos-orders-tracker', 'pos-orders-tracker', { wait: 2500, auth: true });
await snap(ap, '/admin/encaissement', 'encaissement', { wait: 2500, auth: true });
await snap(ap, '/admin/cash-overview', 'cash-overview', { wait: 2500, auth: true });
await snap(ap, '/admin/cash-sessions-report', 'cash-sessions-report', { wait: 2500, auth: true });
await snap(ap, '/admin/historique', 'historique', { wait: 2500, auth: true });
await snap(ap, '/admin/pos', 'pos-main', { wait: 3000, auth: true });
await snap(ap, '/admin/pos/floorplan', 'pos-floorplan', { auth: true });
await admin.close();

// ---------- KIOSK / BORNE (1080x1920) ----------
const kiosk = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR' });
const kp = await kiosk.newPage();
await snap(kp, '/kiosk/login', 'kiosk-login');
await snap(kp, '/kiosk/idle', 'kiosk-idle', { wait: 3000 });
await snap(kp, '/kiosk/categories', 'kiosk-categories', { wait: 2500 });
await snap(kp, '/kiosk/products/1', 'kiosk-products-sandwich', { wait: 2500 });
await snap(kp, '/kiosk/products/21', 'kiosk-products-tacos', { wait: 2500 });
await snap(kp, '/kiosk/cart', 'kiosk-cart-empty');
await snap(kp, '/kiosk/loyalty', 'kiosk-loyalty');
await snap(kp, '/kiosk/upsell', 'kiosk-upsell');
await snap(kp, '/kiosk/payment', 'kiosk-payment');
await snap(kp, '/kiosk/cash-instruction', 'kiosk-cash-instruction');
await snap(kp, '/kiosk/confirmation', 'kiosk-confirmation');
await snap(kp, '/kiosk/admin', 'kiosk-admin');
await snap(kp, '/kiosk/error/network', 'kiosk-error-network');
await snap(kp, '/kiosk/error/menu-unavailable', 'kiosk-error-menu-unavailable');
await snap(kp, '/kiosk/error/product-removed', 'kiosk-error-product-removed');
await snap(kp, '/kiosk/error/payment-refused', 'kiosk-error-payment-refused');
await kiosk.close();

await browser.close();
fs.writeFileSync(`${OUT}/_manifest.txt`, results.join('\n'));
console.log(results.join('\n'));
