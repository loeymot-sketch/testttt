// DISPUTE round-1 VAGUE A — lib jetable (caisse vente & encaissement)
// Quartet par état: PNG + DOM (80KB) + console (err+warn) + network (>=400)
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

export const BASE = 'http://127.0.0.1:8768';
export const OUT = new URL('../../reports/test-e2e/dispute-2026-06-12/round-1/A-caisse-vente/', import.meta.url).pathname;
fs.mkdirSync(OUT, { recursive: true });

export async function boot(viewport = { width: 1440, height: 900 }) {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ viewport, locale: 'fr-FR' });
  const page = await context.newPage();
  const consoleLog = [];
  const netLog = [];
  page.on('console', (m) => {
    if (m.type() === 'error' || m.type() === 'warning')
      consoleLog.push(`[${new Date().toISOString().slice(11, 19)}] ${m.type().toUpperCase()} ${m.text().slice(0, 400)}`);
  });
  page.on('pageerror', (e) => consoleLog.push(`[${new Date().toISOString().slice(11, 19)}] PAGEERROR ${String(e).slice(0, 400)}`));
  page.on('response', (r) => {
    if (r.status() >= 400) netLog.push(`[${new Date().toISOString().slice(11, 19)}] HTTP ${r.status()} ${r.request().method()} ${r.url().slice(0, 200)}`);
  });
  return { browser, context, page, consoleLog, netLog };
}

export const LOGIN_EMAIL = process.env.A_LOGIN || 'pos@lecayenne.fr';

export async function login(page) {
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('input[autocomplete="email"]', { timeout: 15000 });
  await page.fill('input[autocomplete="email"]', LOGIN_EMAIL);
  await page.fill('input[type="password"]', '123456');
  await page.click('button[type="submit"]');
  await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 20000 });
  await page.waitForTimeout(2500);
}

export async function gotoPos(page) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    if (page.url().includes('/login')) { await login(page); continue; }
    const ok = await page.waitForSelector('.pos-v5-tile', { timeout: 20000 }).then(() => true).catch(() => false);
    if (ok) return;
    await login(page);
  }
  throw new Error('gotoPos failed after 3 attempts');
}

// Quartet capture — name sans extension
export async function quartet(page, consoleLog, netLog, name) {
  await page.screenshot({ path: path.join(OUT, name + '.png') }).catch((e) => console.log('shot-fail', name, e.message));
  let dom = '';
  try { dom = await page.content(); } catch (e) { dom = 'DOM-CAPTURE-FAIL: ' + e.message; }
  fs.writeFileSync(path.join(OUT, name + '.dom.html'), dom.slice(0, 80 * 1024));
  fs.writeFileSync(path.join(OUT, name + '.console.txt'), consoleLog.join('\n') || '(vide)');
  fs.writeFileSync(path.join(OUT, name + '.network.txt'), netLog.join('\n') || '(vide — aucun >=400)');
  console.log('QUARTET', name);
}

export const jsClick = (page, sel) => page.evaluate((s) => { const el = document.querySelector(s); if (el) { el.click(); return true; } return false; }, sel);

// Re-auth IN-PAGE sans navigation (token churn des agents parallèles sur compte partagé) :
// POST auth/login via l'axios de l'app + commit authLogin (modules non-namespacés) — le cart Vuex reste intact.
export async function freshAuth(page, email = LOGIN_EMAIL) {
  return page.evaluate(async (em) => {
    try {
      const store = document.querySelector('#app')?.__vue_app__?.config?.globalProperties?.$store;
      if (!store) return 'NO-STORE';
      const res = await window.axios.post('auth/login', { email: em, password: '123456' });
      store.commit('authLogin', res.data);
      return 'OK:' + (res.data?.user?.email || '?');
    } catch (e) { return 'FAIL:' + (e?.response?.status || e.message); }
  }, email);
}

// Ajoute un produit simple par nom de tuile (catégorie déjà visible ou via aria-label)
export async function addSimple(page, category, tileName, times = 1) {
  if (category) {
    await page.locator(`button.pos-v5-category[aria-label="${category}"]`).first().click().catch(() => {});
    await page.waitForTimeout(1200);
  }
  for (let i = 0; i < times; i++) {
    await page.locator(`.pos-v5-tile:has-text("${tileName}")`).first().click();
    // si wizard popup s'ouvre (composé), valider direct via bouton panier
    for (let w = 0; w < 8; w++) {
      await page.waitForTimeout(400);
      if (await page.evaluate(() => !!document.querySelector('.wizard-btn-cart'))) {
        await jsClick(page, '.wizard-btn-cart');
        break;
      }
      // simple: la ligne apparaît sans wizard
      if (await page.locator('.pos-v5-cart-item').count() > 0 && w >= 2) break;
    }
    await page.waitForTimeout(800);
  }
}

export async function cartState(page) {
  return page.evaluate(() => {
    const items = Array.from(document.querySelectorAll('.pos-v5-cart-item')).map((el) => el.innerText.replace(/\s+/g, ' ').trim().slice(0, 200));
    const totals = Array.from(document.querySelectorAll('.pos-v5-total-row, [class*="total-row"]')).map((el) => el.innerText.replace(/\s+/g, ' ').trim().slice(0, 120));
    const payBtn = document.querySelector('[data-testid="pos-v5-pay"]');
    return { items, totals, payLabel: payBtn ? payBtn.innerText.replace(/\s+/g, ' ').trim() : null };
  });
}
