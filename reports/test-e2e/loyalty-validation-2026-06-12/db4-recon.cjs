/* Recon: login + list visible settings menu tabs */
const path = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10';
const { chromium } = require(path + '/node_modules/playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8767';
const OUT = path + '/reports/test-e2e/loyalty-validation-2026-06-12';
const EXE = '/Users/1millnonstop/Library/Caches/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-mac-arm64/chrome-headless-shell';

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await ctx.newPage();
  const sink = { console: [], http: [] };
  page.on('console', m => { if (m.type() === 'error') sink.console.push(`[error] ${m.text().slice(0, 250)}`); });
  page.on('pageerror', e => sink.console.push(`[pageerror] ${String(e).slice(0, 250)}`));
  page.on('response', r => { if (r.status() >= 400) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });

  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  const tb = page.getByRole('textbox');
  await tb.nth(0).fill('admin@lecayenne.fr');
  await tb.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/admin/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(800);

  const t0 = Date.now();
  await page.goto(BASE + '/admin/settings', { waitUntil: 'networkidle' });
  const loadMs = Date.now() - t0;
  await page.waitForTimeout(1000);
  const tabs = await page.evaluate(() => {
    const nav = document.querySelector('nav.db-card');
    if (!nav) return { error: 'nav.db-card not found', url: location.href };
    return {
      url: location.href,
      links: Array.from(nav.querySelectorAll('a')).map(a => ({ text: a.innerText.trim(), href: a.getAttribute('href') })),
    };
  });
  await page.screenshot({ path: OUT + '/D-B4-settings-00-menu.png', fullPage: false });
  console.log(JSON.stringify({ loadMs, tabs, console: sink.console, http: sink.http }, null, 1));
  // save storage state for reuse
  await ctx.storageState({ path: OUT + '/db4-state.json' });
  await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
