// DISPUTE round-1 VAGUE F (design vision) — lib jetable
// Quartet par état: PNG + DOM (80KB) + console (err+warn) + network (>=400)
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

export const BASE = 'http://127.0.0.1:8768';
export const OUT = new URL('../../reports/test-e2e/dispute-2026-06-12/round-1/F-design-vision/', import.meta.url).pathname;
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
  try { dom = await page.content(); } catch (e) { dom = 'DOM-CAPTURE-FAIL: ' + e.message; }
  fs.writeFileSync(path.join(OUT, name + '.dom.html'), dom.slice(0, 80 * 1024));
  fs.writeFileSync(path.join(OUT, name + '.console.txt'), consoleLog.join('\n') || '(vide)');
  fs.writeFileSync(path.join(OUT, name + '.network.txt'), netLog.join('\n') || '(vide — aucun >=400)');
  console.log('QUARTET', name);
}
