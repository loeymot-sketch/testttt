// DISPUTE round-2 VAGUE A — lib jetable (caisse vente & encaissement, post-heal)
// Quartet par état: PNG + DOM (#app outerHTML, slice(-120KB) si trop grand) + console + network (>=400)
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

export const BASE = 'http://127.0.0.1:8768';
export const OUT = new URL('../../reports/test-e2e/dispute-2026-06-12/round-2/A-caisse-vente/', import.meta.url).pathname;
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

// Quartet capture — name sans extension. DOM = #app outerHTML (corrigé R2), fin du doc si >120KB.
export async function quartet(page, consoleLog, netLog, name) {
  await page.screenshot({ path: path.join(OUT, name + '.png') }).catch((e) => console.log('shot-fail', name, e.message));
  let dom = '';
  try {
    dom = await page.evaluate(() => document.querySelector('#app')?.outerHTML || document.body.outerHTML);
  } catch (e) { dom = 'DOM-CAPTURE-FAIL: ' + e.message; }
  const LIM = 120 * 1024;
  if (dom.length > LIM) dom = dom.slice(-LIM);
  fs.writeFileSync(path.join(OUT, name + '.dom.html'), dom);
  fs.writeFileSync(path.join(OUT, name + '.console.txt'), consoleLog.join('\n') || '(vide)');
  fs.writeFileSync(path.join(OUT, name + '.network.txt'), netLog.join('\n') || '(vide — aucun >=400)');
  console.log('QUARTET', name, 'dom=' + dom.length + 'B');
}

export const jsClick = (page, sel) => page.evaluate((s) => { const el = document.querySelector(s); if (el) { el.click(); return true; } return false; }, sel);

export async function completeWizardIfAny(page, tag, picks = null) {
  for (let round = 0; round < 4; round++) {
    await page.waitForTimeout(900);
    const wiz = await page.evaluate(() => !!document.querySelector('.pos-wizard'));
    if (!wiz) return;
    const sections = await page.evaluate(() => Array.from(document.querySelectorAll('.wizard-section')).map((s, i) => ({ i, head: s.innerText.split('\n')[0].slice(0, 50), mandatory: /obligatoire/i.test(s.innerText.slice(0, 200)), selected: s.querySelectorAll('.wizard-option.selected, .wizard-option[class*="active"]').length })));
    console.log(`WIZ[${tag}] round${round}:`, JSON.stringify(sections));
    // sélectionne option nommée (picks) ou la 1re de chaque section obligatoire non remplie
    await page.evaluate((wanted) => {
      document.querySelectorAll('.wizard-section').forEach((s) => {
        if (/obligatoire/i.test(s.innerText.slice(0, 200)) && !s.querySelector('.wizard-option.selected, .wizard-option[class*="active"]')) {
          let target = null;
          if (wanted && wanted.length) {
            const opts = Array.from(s.querySelectorAll('.wizard-option'));
            target = opts.find((o) => wanted.some((w) => o.innerText.includes(w)));
          }
          (target || s.querySelector('.wizard-option'))?.click();
        }
      });
    }, picks);
    await page.waitForTimeout(500);
    await page.evaluate(() => document.querySelector('.wizard-btn-cart')?.click());
  }
}

export async function addItem(page, cat, tile, picks = null) {
  if (cat) {
    await page.locator(`button.pos-v5-category[aria-label="${cat}"]`).first().click().catch(() => {});
    await page.waitForTimeout(1200);
  }
  const before = await page.locator('.pos-v5-cart-item').count();
  await page.locator(`.pos-v5-tile:has-text("${tile}")`).first().click();
  await completeWizardIfAny(page, tile, picks);
  await page.waitForTimeout(800);
  const after = await page.locator('.pos-v5-cart-item').count();
  console.log(`ADD ${tile}: cart ${before} -> ${after}`);
}

export async function cartState(page) {
  return page.evaluate(() => {
    const items = Array.from(document.querySelectorAll('.pos-v5-cart-item')).map((el) => el.innerText.replace(/\s+/g, ' ').trim().slice(0, 220));
    const totals = Array.from(document.querySelectorAll('.pos-v5-total-row, [class*="total-row"]')).map((el) => el.innerText.replace(/\s+/g, ' ').trim().slice(0, 120));
    const payBtn = document.querySelector('[data-testid="pos-v5-pay"]');
    return { items, totals, payLabel: payBtn ? payBtn.innerText.replace(/\s+/g, ' ').trim() : null };
  });
}

export async function receiptText(page) {
  return page.evaluate(() => document.querySelector('#print-receipt-client')?.innerText || 'ABSENT');
}

// Confirme et capture le POST /api/admin/pos — retourne {status, json}
export async function confirmAndCapture(page, timeout = 25000) {
  const respP = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/api/admin/pos'), { timeout }).catch(() => null);
  await page.locator('[data-testid="pos-payment-confirm"]').click().catch(() => {});
  const resp = await respP;
  if (!resp) return { status: null, json: null };
  let json = null;
  try { json = await resp.json(); } catch { }
  return { status: resp.status(), json, url: resp.url() };
}
