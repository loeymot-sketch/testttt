// DISPUTE round-1 — Vague C (borne edge) — lib jetable, READ-ONLY sur le code source.
// Quartet d'artefacts par état: PNG + DOM(80KB) + console(err+warn) + network(>=400).
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

export const BASE = 'http://127.0.0.1:8768';
export const OUT = new URL('../../reports/test-e2e/dispute-2026-06-12/round-1/C-borne-edge/', import.meta.url).pathname;
fs.mkdirSync(OUT, { recursive: true });

export function logLine(file, msg) {
  const line = `[${new Date().toISOString().slice(11, 19)}] ${msg}`;
  console.log(line);
  fs.appendFileSync(path.join(OUT, file), line + '\n');
}

export async function boot(opts = {}) {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({
    viewport: { width: 1080, height: 1920 },
    locale: 'fr-FR',
    ...opts,
  });
  const page = await context.newPage();
  const sink = { console: [], network: [], orders: [] };
  page.on('console', (m) => {
    const t = m.type();
    if (t === 'error' || t === 'warning') sink.console.push({ ts: Date.now(), level: t, text: m.text().slice(0, 400) });
  });
  page.on('pageerror', (e) => sink.console.push({ ts: Date.now(), level: 'pageerror', text: String(e).slice(0, 400) }));
  page.on('response', async (r) => {
    if (r.status() >= 400) sink.network.push({ ts: Date.now(), status: r.status(), method: r.request().method(), url: r.url().slice(0, 200) });
    // Capture les POST /frontend/order (création de commande) pour intégrité numérique
    if (r.url().includes('frontend/order') && r.request().method() === 'POST'
        && !r.url().includes('quote') && !r.url().includes('change-status') && !r.url().includes('cancel')) {
      try {
        const j = await r.json();
        const d = j?.data || j;
        sink.orders.push({ ts: Date.now(), status: r.status(), id: d?.id, queue: d?.queue_number, total: d?.total ?? d?.order_amount, discount: d?.discount, subtotal: d?.subtotal });
      } catch { sink.orders.push({ ts: Date.now(), status: r.status(), nonJson: true }); }
    }
  });
  return { browser, context, page, sink };
}

let lastSnapTs = 0;
export async function snap(page, sink, name) {
  const png = path.join(OUT, name + '.png');
  await page.screenshot({ path: png }).catch((e) => console.log('SHOT FAIL', name, e.message));
  let dom = '';
  try { dom = await page.content(); } catch (e) { dom = 'DOM CAPTURE FAIL: ' + e.message; }
  fs.writeFileSync(path.join(OUT, name + '.dom.html'), dom.slice(0, 80 * 1024));
  const since = lastSnapTs;
  fs.writeFileSync(path.join(OUT, name + '.console.json'), JSON.stringify(sink.console.filter(c => c.ts > since), null, 1));
  fs.writeFileSync(path.join(OUT, name + '.network.json'), JSON.stringify(sink.network.filter(c => c.ts > since), null, 1));
  lastSnapTs = Date.now();
  console.log('SNAP', name);
  return png;
}

export async function kioskBoot(page) {
  await page.goto(BASE + '/kiosk/login', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 10; i++) {
    await page.waitForTimeout(1400);
    const tok = await page.evaluate(() => { try { return !!JSON.parse(localStorage.vuex)?.kioskCart?.kioskToken; } catch { return false; } });
    if (tok) return true;
  }
  return false;
}

export async function startFlow(page, orderType = 'takeaway') {
  await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2200);
  const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
  if (await touch.isVisible().catch(() => false)) { await touch.click({ force: true }); await page.waitForTimeout(900); }
  const ot = page.locator(`[data-testid="kiosk-order-type-${orderType}"]`);
  if (await ot.isVisible().catch(() => false)) { await ot.click(); await page.waitForTimeout(1500); }
}

export async function cartState(page) {
  return page.evaluate(() => {
    try {
      const c = JSON.parse(localStorage.vuex)?.kioskCart;
      return { n: c?.items?.length || 0, items: (c?.items || []).map(i => ({ name: i.name, qty: i.quantity, total: i.total ?? i.itemTotal })) };
    } catch { return { n: 0, items: [] }; }
  });
}

// Complète le wizard frozen (KioskWizardComponent) avec choix par défaut.
// Couvre tous les steps: viande/sauce/pain (option-card), menu (menu-card),
// taille, frites-style, generic, garnitures (pré-incluses), suppléments (skip).
const WIZ_CANDIDATES = [
  // boisson/frites-sauce internes au step menu (visibles seulement après choix menu)
  '.kiosk-boisson-card:not(.selected)',
  '.kiosk-viande-card:not(.disabled):not(.selected)',
  '.kiosk-option-card:not(.disabled):not(.selected)',
  '.kiosk-menu-card:not(.selected)',
  '.kiosk-taille-card:not(.disabled):not(.selected)',
  '.kiosk-frites-style-card--nature',
  '.kiosk-frites-style-card',
  '.kiosk-generic-choice:not(.disabled):not(.selected)',
];
export async function completeWizard(page, trace = null) {
  for (let s = 0; s < 24; s++) {
    const overlay = page.locator('.kiosk-wizard-overlay');
    if (!(await overlay.isVisible().catch(() => false))) return true;
    const title = (await page.locator('.kiosk-step-title').first().innerText({ timeout: 800 }).catch(() => '?'))?.trim();
    const next = page.locator('.kiosk-btn-next').first();
    const nextVisible = await next.isVisible().catch(() => false);
    const nextDisabled = nextVisible ? await next.isDisabled().catch(() => false) : true;
    if (nextVisible && !nextDisabled) {
      if (trace) trace.push(`step "${title}" → NEXT`);
      await next.click().catch(() => {});
      await page.waitForTimeout(800);
      continue;
    }
    // Next absent/disabled → satisfaire le 1er GROUPE sans sélection (multi-sections: menu+boisson+sauce frites)
    const picked = await page.evaluate(() => {
      const cardSel = '.kiosk-menu-card,.kiosk-boisson-card,.kiosk-option-card,.kiosk-viande-card,.kiosk-taille-card,.kiosk-generic-choice,.kiosk-frites-style-card,.kiosk-pain-card';
      const visible = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
      const cards = [...document.querySelectorAll(cardSel)].filter(visible).filter(c => !c.classList.contains('disabled'));
      const groups = new Map();
      for (const c of cards) {
        const g = c.closest('[role="radiogroup"],[role="group"]') || c.parentElement;
        if (!groups.has(g)) groups.set(g, []);
        groups.get(g).push(c);
      }
      for (const [, cs] of groups) {
        if (!cs.some(c => c.classList.contains('selected') || c.classList.contains('active') || c.getAttribute('aria-checked') === 'true')) {
          cs[0].click();
          return (cs[0].getAttribute('aria-label') || cs[0].className).slice(0, 90);
        }
      }
      return null;
    }).catch(() => null);
    if (picked) {
      if (trace) trace.push(`step "${title}" → pick-group [${picked}]`);
      await page.waitForTimeout(500);
      continue;
    }
    const cont = page.locator('.kiosk-btn-continue').first();
    if (await cont.isVisible().catch(() => false)) { await cont.click().catch(() => {}); await page.waitForTimeout(450); continue; }
    if (trace) trace.push(`step "${title}" → STUCK (tous groupes satisfaits mais Next disabled)`);
    await page.waitForTimeout(500);
  }
  return !(await page.locator('.kiosk-wizard-overlay').isVisible().catch(() => false));
}

// Ajoute un produit par id depuis sa catégorie; retourne true si panier a grossi.
export async function addProduct(page, catId, itemId) {
  await page.goto(`${BASE}/kiosk/categories?cat=${catId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1400);
  const before = (await cartState(page)).n;
  const btn = page.locator(`[data-testid="kiosk-product-add-${itemId}"]`);
  await btn.scrollIntoViewIfNeeded().catch(() => {});
  if (!(await btn.isVisible().catch(() => false))) return { ok: false, reason: 'add-btn-invisible' };
  await btn.click().catch(() => {});
  await page.waitForTimeout(1200);
  if (await page.locator('.kiosk-wizard-overlay').isVisible().catch(() => false)) {
    const trace = [];
    const done = await completeWizard(page, trace);
    if (!done) return { ok: false, reason: 'wizard-stuck', trace };
  }
  await page.waitForTimeout(600);
  const after = (await cartState(page)).n;
  return { ok: after > before, before, after };
}

// Lit les montants du panier (texte affiché)
export async function readCartTotals(page) {
  const g = async (sel) => (await page.locator(sel).innerText({ timeout: 1200 }).catch(() => null))?.trim() ?? null;
  return {
    subtotal: await g('[data-testid="kiosk-cart-subtotal"]'),
    loyalty: await g('[data-testid="kiosk-cart-loyalty-discount"]'),
    promo: await g('[data-testid="kiosk-cart-promo-discount"]'),
    total: await g('[data-testid="kiosk-cart-total"]'),
  };
}
