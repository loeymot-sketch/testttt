// DISPUTE round-2 VAGUE F (design re-jugement post-heals) — lib jetable
// Quartet par état: PNG + DOM (#app outerHTML, slice FIN 120KB) + console + network (>=400)
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

export const BASE = 'http://127.0.0.1:8768';
export const OUT = new URL('../../reports/test-e2e/dispute-2026-06-12/round-2/F-design-vision/', import.meta.url).pathname;
fs.mkdirSync(OUT, { recursive: true });

export async function boot(viewport) {
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

export async function quartet(page, consoleLog, netLog, name) {
  await page.screenshot({ path: path.join(OUT, name + '.png') }).catch((e) => console.log('shot-fail', name, e.message));
  let dom = '';
  try {
    // CORRECTIF round-1 ADV-F-P1-4: capturer le RENDU (#app), jamais documentElement (head seul)
    dom = await page.evaluate(() => document.querySelector('#app')?.outerHTML || document.body.outerHTML);
  } catch (e) { dom = 'DOM-CAPTURE-FAIL: ' + e.message; }
  if (dom.length > 120 * 1024) dom = '<!-- TRONQUÉ PAR LA FIN (slice -120KB) -->\n' + dom.slice(-120 * 1024);
  fs.writeFileSync(path.join(OUT, name + '.dom.html'), dom);
  fs.writeFileSync(path.join(OUT, name + '.console.txt'), consoleLog.join('\n') || '(vide)');
  fs.writeFileSync(path.join(OUT, name + '.network.txt'), netLog.join('\n') || '(vide — aucun >=400)');
  console.log('QUARTET', name, 'dom=' + dom.length + 'B');
}
