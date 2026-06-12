// D1 VAGUE E — cross-surface integrity helpers (jetable, READ-ONLY source)
// Quartet par état: PNG + DOM (80KB) + console (err+warn) + network (>=400)
import { chromium } from 'playwright';
import fs from 'fs';

export const BASE = 'http://127.0.0.1:8768';
export const OUT = new URL('../../reports/test-e2e/dispute-2026-06-12/round-1/E-cross-surface/', import.meta.url).pathname;
fs.mkdirSync(OUT, { recursive: true });

export function makeLogger(tag) {
  const lines = [];
  const L = (m) => { const s = `[${new Date().toISOString().slice(11, 19)}] ${m}`; lines.push(s); console.log(s); };
  L.flush = () => fs.writeFileSync(`${OUT}_log-${tag}.txt`, lines.join('\n') + '\n');
  return L;
}

export async function boot({ kiosk = false } = {}) {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({
    viewport: kiosk ? { width: 1080, height: 1920 } : { width: 1440, height: 900 },
    locale: 'fr-FR',
  });
  const page = await context.newPage();
  const consoleBuf = [];
  const netBuf = [];
  page.on('console', (m) => {
    if (m.type() === 'error' || m.type() === 'warning') consoleBuf.push(`${m.type().toUpperCase()} ${page.url().replace(BASE, '')} :: ${m.text().slice(0, 400)}`);
  });
  page.on('pageerror', (e) => consoleBuf.push(`PAGEERROR ${page.url().replace(BASE, '')} :: ${String(e).slice(0, 400)}`));
  page.on('response', (r) => { if (r.status() >= 400) netBuf.push(`${r.status()} ${r.request().method()} ${r.url().slice(0, 200)}`); });
  return { browser, context, page, consoleBuf, netBuf };
}

// Quartet: <name>.png + <name>.dom.html + <name>.console.txt + <name>.network.txt
export async function quartet(page, consoleBuf, netBuf, name) {
  await page.screenshot({ path: `${OUT}${name}.png` }).catch((e) => console.log(`SHOT-FAIL ${name}: ${e.message?.slice(0, 100)}`));
  const dom = await page.content().catch(() => '(content unavailable)');
  fs.writeFileSync(`${OUT}${name}.dom.html`, dom.slice(0, 80 * 1024));
  fs.writeFileSync(`${OUT}${name}.console.txt`, (consoleBuf.length ? consoleBuf.join('\n') : '(aucune erreur/warning console depuis le boot)') + '\n');
  fs.writeFileSync(`${OUT}${name}.network.txt`, (netBuf.length ? netBuf.join('\n') : '(aucune réponse >=400 depuis le boot)') + '\n');
  console.log(`QUARTET ${name}`);
}

export async function login(page, email = 'admin@lecayenne.fr') {
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('input[autocomplete="email"]', { timeout: 15000 });
  await page.fill('input[autocomplete="email"]', email);
  await page.fill('input[type="password"]', '123456');
  await page.click('button[type="submit"]');
  await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 20000 });
  await page.waitForTimeout(2500);
}

// goto admin route with 401/login resilience (parallel agents may revoke tokens)
export async function gotoAdmin(page, path, readySel = null) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);
    if (page.url().includes('/login')) { await login(page); continue; }
    if (!readySel) return true;
    const ok = await page.waitForSelector(readySel, { timeout: 15000 }).then(() => true).catch(() => false);
    if (ok) return true;
    await login(page);
  }
  return false;
}

export const bodyText = (page) => page.evaluate(() => document.body.innerText);
