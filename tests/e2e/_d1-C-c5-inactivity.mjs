// DISPUTE round-1 Vague C — C5 : inactivité. idleMs=10s/confirmMs=5s via localStorage persisté
// (bornes min kioskSettings.js:49-50). 3 surfaces : catalogue (contrôle), /kiosk/payment, cash-instruction.
import { boot, snap, kioskBoot, startFlow, addProduct, BASE, logLine, OUT } from './_d1-C-lib.mjs';

const L = (m) => logLine('_c5-log.txt', m);
const { browser, page, sink } = await boot();
await kioskBoot(page);

// accélère les timers AVANT le boot SPA (clefs persistées store/index.js:312-313)
await page.evaluate(() => {
  const v = JSON.parse(localStorage.vuex || '{}');
  v.kioskSettings = { ...(v.kioskSettings || {}), idleMs: 10000, confirmMs: 5000 };
  localStorage.vuex = JSON.stringify(v);
});

// ---- A. contrôle : catalogue (timer actif attendu : warning à 5s, reset à 10s)
await startFlow(page);
await addProduct(page, 10, 52); // 1 Coca au panier pour vérifier le sort du panier
L(`A catalogue url=${page.url().replace(BASE, '')} — attente 7s sans toucher`);
await page.waitForTimeout(7000);
await snap(page, sink, 'c5-A1-catalog-7s-overlay-attendu');
const overlayA = await page.locator('.kiosk-inactivity-overlay, [data-testid="kiosk-inactivity-overlay"]').isVisible().catch(() => false);
const stillHereTxt = await page.evaluate(() => document.body.innerText.match(/(Toujours là|toujours là|présent)[^\n]*/)?.[0] || null);
L(`A overlay visible=${overlayA} texte="${stillHereTxt}"`);
await page.waitForTimeout(6000);
await snap(page, sink, 'c5-A2-catalog-13s-reset-attendu');
L(`A après 13s url=${page.url().replace(BASE, '')}`);
const cartAfterA = await page.evaluate(() => { try { return JSON.parse(localStorage.vuex)?.kioskCart?.items?.length || 0; } catch { return -1; } });
L(`A panier après reset: ${cartAfterA} items (attendu 0 si RESET)`);

// ---- B. /kiosk/payment : timer DÉSACTIVÉ par design (KioskAppComponent.vue:881) → vérifier empiriquement
await startFlow(page);
await addProduct(page, 10, 52);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2200);
if (page.url().includes('/loyalty')) {
  const skip = page.locator('[data-testid="kiosk-loyalty-skip"], .kiosk-loyalty-skip').first();
  if (await skip.isVisible().catch(() => false)) { await skip.click(); await page.waitForTimeout(1400); }
}
if (page.url().includes('/upsell')) {
  const skip = page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]');
  await skip.first().click().catch(() => {});
  await page.waitForTimeout(1600);
}
L(`B payment url=${page.url().replace(BASE, '')} — attente 25s sans toucher (idle 10s dépassé ×2.5)`);
await page.waitForTimeout(25000);
await snap(page, sink, 'c5-B1-payment-25s');
const overlayB = await page.locator('.kiosk-inactivity-overlay, [data-testid="kiosk-inactivity-overlay"]').isVisible().catch(() => false);
L(`B après 25s : url=${page.url().replace(BASE, '')} overlay=${overlayB} (code: timer désactivé sur kiosk.payment)`);

// ---- C. cash-instruction : countdown 45s auto-redirect (hard-codé navTarget timeout:45)
await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click();
await page.waitForTimeout(4200);
L(`C cash url=${page.url().replace(BASE, '')}`);
await snap(page, sink, 'c5-C1-cash-start');
const countdown0 = await page.locator('.kiosk-cash__countdown').innerText({ timeout: 2000 }).catch(() => 'ABSENT');
L(`C countdown initial: "${countdown0}"`);
const t0 = Date.now();
let redirected = false;
try {
  await page.waitForURL('**/kiosk/idle**', { timeout: 60000 });
  redirected = true;
} catch {}
const dt = Math.round((Date.now() - t0) / 1000);
L(`C auto-redirect idle: ${redirected} après ~${dt}s (attendu ~45s)`);
await snap(page, sink, 'c5-C2-apres-redirect');
const cartAfterC = await page.evaluate(() => { try { return JSON.parse(localStorage.vuex)?.kioskCart?.items?.length || 0; } catch { return -1; } });
L(`C panier après retour idle: ${cartAfterC} items`);

await browser.close();
L('C5 done');
