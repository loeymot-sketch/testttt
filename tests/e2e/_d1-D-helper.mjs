// DISPUTE R1 vague D — helper quartet (PNG + DOM 80KB + console + network)
import fs from 'fs';
import path from 'path';

export const BASE = 'http://127.0.0.1:8768';
export const OUT = 'reports/test-e2e/dispute-2026-06-12/round-1/D-borne-robustesse';

export function attachRecorder(page, label = '') {
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
    let dom = '(page.content() indisponible)';
    try { dom = await page.content(); } catch (e) { dom = '(page.content() error: ' + String(e) + ')'; }
    fs.writeFileSync(base + '.dom.html', dom.slice(0, 80 * 1024));
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
    return { items: (c.items || []).map(i => ({ name: i.name || i.item_name, qty: i.quantity, price: i.total_price ?? i.price })), orderType: c.orderType ?? c.order_type };
  } catch { return null; }
});

// Entre dans le flux depuis idle (touch + à emporter)
export async function enterFromIdle(page) {
  await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2200);
  const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
  if (await touch.isVisible().catch(() => false)) { await touch.click({ force: true }); await page.waitForTimeout(900); }
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  if (await takeaway.isVisible().catch(() => false)) { await takeaway.click(); await page.waitForTimeout(1500); }
  console.log(`enterFromIdle → ${page.url().replace(BASE, '')}`);
}

// Ajoute 1 produit simple (clic + sur carte sans wizard). Retourne testid ou null.
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

// Ouvre un wizard (produit composé) et S'ARRÊTE dedans (overlay visible).
export async function openWizard(page) {
  for (const cat of [1, 2, 3, 4, 5, 6]) {
    await page.goto(`${BASE}/kiosk/categories?cat=${cat}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1300);
    const adds = page.locator('[data-testid^="kiosk-product-add-"]');
    const n = await adds.count();
    for (let i = 0; i < n; i++) {
      const el = adds.nth(i);
      if (!(await el.isVisible().catch(() => false))) continue;
      const tid = await el.getAttribute('data-testid');
      await el.click().catch(() => {});
      await page.waitForTimeout(1300);
      if (await page.locator('.kiosk-wizard-overlay').isVisible().catch(() => false)) {
        console.log(`openWizard: overlay ouvert via ${tid} (cat=${cat})`);
        return tid;
      }
    }
  }
  return null;
}
