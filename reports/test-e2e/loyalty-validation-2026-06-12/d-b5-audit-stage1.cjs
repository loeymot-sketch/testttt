/* D-B5-divers stage 1: load audit of 9 admin pages. Read-only navigation. */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8767';
const OUT = __dirname;
const EXE = '/Users/1millnonstop/Library/Caches/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-mac-arm64/chrome-headless-shell';

const PAGES = [
  ['observability', '/admin/observability'],
  ['observability-outbox', '/admin/observability/outbox'],
  ['profile-edit', '/admin/profile/edit-profile'],
  ['profile-password', '/admin/profile/change-password'],
  ['coupons', '/admin/coupons'],
  ['offers', '/admin/offers'],
  ['messages', '/admin/messages'],
  ['subscribers', '/admin/subscribers'],
  ['push-notifications', '/admin/push-notifications'],
];

function log(s) { fs.appendFileSync(path.join(OUT, 'D-B5-divers.md'), s + '\n'); console.log(s); }

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: fs.existsSync(EXE) ? EXE : undefined });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const sink = { console: [], http: [] };
  page.on('console', m => { if (m.type() === 'error') sink.console.push(`[console] ${m.text().slice(0, 300)}`); });
  page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 300)}`));
  page.on('response', r => { if (r.status() >= 400) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });

  // login
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  const tb = page.getByRole('textbox');
  await tb.nth(0).fill('admin@lecayenne.fr');
  await tb.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/admin/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(800);
  log(`\n## STAGE 1 — load audit ${new Date().toISOString()}`);
  log(`login OK, landed on ${page.url()}`);
  sink.console.length = 0; sink.http.length = 0;

  for (const [slug, route] of PAGES) {
    sink.console.length = 0; sink.http.length = 0;
    const t0 = Date.now();
    try {
      await page.goto(BASE + route, { waitUntil: 'networkidle', timeout: 30000 });
    } catch (e) {
      log(`\n### ${route}\nNAV-ERROR: ${String(e).slice(0, 200)}`);
      continue;
    }
    const tLoad = Date.now() - t0;
    await page.waitForTimeout(1000);
    const finalUrl = page.url();
    const shot = path.join(OUT, `D-B5-divers-${slug}.png`);
    await page.screenshot({ path: shot, fullPage: true });
    const body = await page.evaluate(() => document.body.innerText);
    // i18n raw label scan
    const rawLabels = (body.match(/\b[a-z]+\.[a-z_]+\.[a-z_]+\b|Label\.[A-Za-z]+|\b0undefined\b|undefined|NaN\b/g) || []).slice(0, 12);
    const ampm = (body.match(/\b\d{1,2}:\d{2}\s?(AM|PM)\b/gi) || []).slice(0, 6);
    const dotMoney = (body.match(/[€$]\s?\d+\.\d{2}|\d+\.\d{2}\s?[€$]/g) || []).slice(0, 6);
    log(`\n### ${route} -> ${finalUrl.replace(BASE, '')}`);
    log(`- load: ${tLoad}ms`);
    log(`- console errors: ${sink.console.length ? '\n  ' + [...new Set(sink.console)].join('\n  ') : 'none'}`);
    log(`- http>=400: ${sink.http.length ? '\n  ' + [...new Set(sink.http)].join('\n  ') : 'none'}`);
    log(`- raw-label/undefined hits: ${rawLabels.length ? JSON.stringify(rawLabels) : 'none'}`);
    log(`- AM/PM hits: ${ampm.length ? JSON.stringify(ampm) : 'none'}`);
    log(`- dot-decimal money: ${dotMoney.length ? JSON.stringify(dotMoney) : 'none'}`);
    log(`- body first 700 chars:\n\`\`\`\n${body.replace(/\n{2,}/g, '\n').slice(0, 700)}\n\`\`\``);
  }
  await browser.close();
  log('\nSTAGE1 DONE');
})().catch(e => { log('FATAL ' + String(e).slice(0, 400)); process.exit(1); });
