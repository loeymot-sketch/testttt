// DISPUTE R2 vague D — helper quartet (PNG + #app outerHTML 120KB-fin + console + network>=400)
import fs from 'fs';
import path from 'path';

export const BASE = 'http://127.0.0.1:8768';
export const OUT = 'reports/test-e2e/dispute-2026-06-12/round-2/D-borne-robustesse';

export function attachRecorder(page) {
  const consoleLog = [];
  const network = [];
  page.on('console', m => {
    const t = m.type();
    if (t === 'error' || t === 'warning') consoleLog.push(`[${t}] ${page.url()} :: ${m.text().slice(0, 400)}`);
  });
  page.on('pageerror', e => consoleLog.push(`[pageerror] ${page.url()} :: ${String(e).slice(0, 400)}`));
  page.on('response', r => { if (r.status() >= 400) network.push(`${r.status()} ${r.request().method()} ${r.url().slice(0, 200)}`); });
  page.on('requestfailed', r => network.push(`FAILED ${r.method()} ${r.url().slice(0, 200)} :: ${r.failure()?.errorText || '?'}`));

  async function snap(tag) {
    fs.mkdirSync(OUT, { recursive: true });
    const base = path.join(OUT, tag);
    try { await page.screenshot({ path: base + '.png', timeout: 8000 }); }
    catch (e) { fs.writeFileSync(base + '.png.ERR.txt', String(e)); }
    let dom = '(dom indisponible)';
    try {
      dom = await page.evaluate(() => document.querySelector('#app')?.outerHTML || document.body.outerHTML);
    } catch (e) { dom = '(dom error: ' + String(e) + ')'; }
    if (dom.length > 120 * 1024) dom = dom.slice(-120 * 1024);
    fs.writeFileSync(base + '.dom.html', dom);
    fs.writeFileSync(base + '.console.txt', consoleLog.join('\n') || '(aucune erreur/warning console)');
    fs.writeFileSync(base + '.network.txt', network.join('\n') || '(aucune requete >=400 / failed)');
    console.log(`SNAP ${tag} url=${page.url().replace(BASE, '')}`);
  }
  return { snap, consoleLog, network };
}

export async function kioskBoot(page) {
  await page.goto(BASE + '/kiosk/login', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 10; i++) {
    await page.waitForTimeout(1500);
    const tok = await page.evaluate(() => { try { return !!JSON.parse(localStorage.vuex)?.kioskCart?.kioskToken; } catch { return false; } });
    if (tok) { console.log(`kioskBoot: token OK (try ${i + 1})`); return true; }
  }
  console.log('kioskBoot: token NON acquis');
  return false;
}

export const cartCount = (page) => page.evaluate(() => {
  try { return JSON.parse(localStorage.vuex)?.kioskCart?.items?.length || 0; } catch { return 0; }
});

export const cartState = (page) => page.evaluate(() => {
  try {
    const c = JSON.parse(localStorage.vuex)?.kioskCart || {};
    return {
      items: (c.items || []).map(i => ({ name: i.name || i.item_name, qty: i.quantity, price: i.total_price ?? i.price })),
      promoCode: c.promoCode ?? c.promo_code ?? null,
      promoDiscount: c.promoDiscount ?? c.promo_discount ?? null,
      loyaltyDiscount: c.loyaltyDiscount ?? null,
      idemKey: c.idempotencyKey ? String(c.idempotencyKey).slice(0, 12) + '…' : null,
    };
  } catch { return null; }
});

export async function enterFromIdle(page) {
  await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2200);
  const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
  if (await touch.isVisible().catch(() => false)) { await touch.click({ force: true }); await page.waitForTimeout(900); }
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  if (await takeaway.isVisible().catch(() => false)) { await takeaway.click(); await page.waitForTimeout(1500); }
  console.log(`enterFromIdle → ${page.url().replace(BASE, '')}`);
}

export async function addSimpleProduct(page) {
  for (const cat of [9, 8, 7, 10, 6, 5]) {
    await page.goto(`${BASE}/kiosk/categories?cat=${cat}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1300);
    const adds = page.locator('[data-testid^="kiosk-product-add-"]');
    const n = await adds.count();
    for (let i = 0; i < n; i++) {
      const before = await cartCount(page);
      const el = adds.nth(i);
      if (!(await el.isVisible().catch(() => false))) continue;
      const tid = await el.getAttribute('data-testid');
      await el.click().catch(() => {});
      await page.waitForTimeout(1200);
      const overlay = await page.locator('.kiosk-wizard-overlay').isVisible().catch(() => false);
      if (overlay) {
        await page.locator('.kiosk-btn-abandon').first().click().catch(() => {});
        await page.waitForTimeout(350);
        await page.locator('.kiosk-wizard-abandon-yes').click().catch(() => {});
        await page.waitForTimeout(350);
        continue;
      }
      const after = await cartCount(page);
      if (after > before) { console.log(`addSimpleProduct: ${tid} (cat=${cat}) cart ${before}→${after}`); return tid; }
    }
  }
  return null;
}

// Déroule cart → (upsell) → (loyalty) → payment. Retourne l'URL atteinte.
export async function gotoPayment(page) {
  await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1400);
  await page.locator('[data-testid="kiosk-cart-checkout"]').click().catch(() => {});
  await page.waitForTimeout(2200);
  for (let hop = 0; hop < 3; hop++) {
    if (page.url().includes('/upsell')) {
      await page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {});
      await page.waitForTimeout(1600);
    } else if (page.url().includes('/loyalty')) {
      await page.locator('.kiosk-loyalty-skip').first().click().catch(() => {});
      await page.waitForTimeout(1600);
    } else break;
  }
  console.log(`gotoPayment → ${page.url().replace(BASE, '')}`);
  return page.url();
}
