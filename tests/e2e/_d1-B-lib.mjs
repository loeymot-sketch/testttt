// VAGUE B dispute round-1 — helper quartet (jetable, READ-ONLY source)
import { chromium } from 'playwright';
import fs from 'fs';

export const BASE = 'http://127.0.0.1:8768';
export const OUT = new URL('../../reports/test-e2e/dispute-2026-06-12/round-1/B-caisse-gestion/', import.meta.url).pathname;
fs.mkdirSync(OUT, { recursive: true });

export function mkLogger(tag) {
  const lines = [];
  const L = (m) => { const s = `[${new Date().toISOString().slice(11, 19)}] ${m}`; lines.push(s); console.log(s); };
  L.flush = () => fs.writeFileSync(`${OUT}_${tag}-log.txt`, lines.join('\n'));
  return L;
}

export async function boot({ kiosk = false } = {}) {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const ctx = await browser.newContext({
    viewport: kiosk ? { width: 1080, height: 1920 } : { width: 1440, height: 900 },
    locale: 'fr-FR',
    ...(kiosk ? { hasTouch: true } : {}),
  });
  const page = await ctx.newPage();
  const state = { consoleBuf: [], netBuf: [] };
  page.on('console', (m) => {
    if (m.type() === 'error' || m.type() === 'warning') state.consoleBuf.push(`${m.type().toUpperCase()} ${page.url().replace(BASE, '')} :: ${m.text().slice(0, 300)}`);
  });
  page.on('pageerror', (e) => state.consoleBuf.push(`PAGEERROR ${page.url().replace(BASE, '')} :: ${String(e).slice(0, 300)}`));
  page.on('response', (r) => { if (r.status() >= 400) state.netBuf.push(`HTTP ${r.status()} ${r.request().method()} ${r.url().slice(0, 180)}`); });
  return { browser, ctx, page, state };
}

// Quartet: PNG + DOM (80KB) + console (erreurs+warnings cumulés) + network (>=400 cumulés)
export async function snap(page, state, name) {
  const base = OUT + name;
  await page.screenshot({ path: base + '.png' }).catch((e) => console.log('SNAP-FAIL png ' + name + ' ' + e.message));
  const html = await page.content().catch(() => '(content fail)');
  fs.writeFileSync(base + '.dom.html', html.slice(0, 80 * 1024));
  fs.writeFileSync(base + '.console.txt', state.consoleBuf.join('\n') || '(aucune erreur/warning console à cet instant)');
  fs.writeFileSync(base + '.network.txt', state.netBuf.join('\n') || '(aucune réponse >=400 à cet instant)');
  console.log('SNAP ' + name);
}

export async function login(page, L, email = 'bm.t2admin@lecayenne.fr') {
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('input[autocomplete="email"]', { timeout: 15000 });
  await page.fill('input[autocomplete="email"]', email);
  await page.fill('input[type="password"]', '123456');
  await page.click('button[type="submit"]');
  await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 20000 });
  await page.waitForTimeout(2500);
  L(`login(${email}) → ${page.url().replace(BASE, '')}`);
}

export async function gotoPos(page, L) {
  for (let a = 1; a <= 3; a++) {
    await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    if (page.url().includes('/login')) { await login(page, L); continue; }
    const ok = await page.waitForSelector('.pos-v5-tile', { timeout: 20000 }).then(() => true).catch(() => false);
    if (ok) return;
    await login(page, L);
  }
  throw new Error('gotoPos failed after 3 attempts');
}

export async function bodyText(page) {
  return page.evaluate(() => document.body.innerText);
}
