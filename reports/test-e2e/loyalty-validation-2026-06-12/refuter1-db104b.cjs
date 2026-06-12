const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8767';
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-validation-2026-06-12';
const EXE = '/Users/1millnonstop/Library/Caches/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-mac-arm64/chrome-headless-shell';

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: fs.existsSync(EXE) ? EXE : undefined });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  page.on('console', m => { if (m.type() === 'error') console.log('[console.error]', m.text().slice(0,200)); });
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);
  const tb = page.getByRole('textbox');
  await tb.nth(0).fill('admin@lecayenne.fr');
  await tb.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/admin/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  console.log('after-login URL:', page.url());

  await page.goto(BASE + '/admin/items', { waitUntil: 'networkidle' });
  try {
    await page.waitForSelector('.catalog-control-plane__metric', { timeout: 15000 });
  } catch (e) {
    console.log('WAIT_FAIL catalog-control-plane__metric:', e.message.split('\n')[0]);
    console.log('current URL:', page.url());
    const h = await page.evaluate(() => document.body.innerText.slice(0, 600));
    console.log('BODY TEXT:', h);
    await page.screenshot({ path: OUT + '/refuter1-db104-debug.png' });
    await browser.close();
    return;
  }
  await page.waitForTimeout(1500);
  const data = await page.evaluate(() => {
    const metrics = [...document.querySelectorAll('.catalog-control-plane__metric')];
    return metrics.map(m => {
      const small = m.querySelector('small');
      const cs = small ? getComputedStyle(small) : null;
      return {
        text: small ? small.textContent.trim() : null,
        smallClientW: small ? small.clientWidth : null,
        smallScrollW: small ? small.scrollWidth : null,
        truncated: small ? small.scrollWidth > small.clientWidth + 1 : null,
        btnClientW: m.clientWidth,
        smallWhiteSpace: cs ? cs.whiteSpace : null,
        smallOverflow: cs ? cs.overflow : null,
        btnOverflow: getComputedStyle(m).overflow,
        title: m.getAttribute('title'),
      };
    });
  });
  console.log(JSON.stringify({ url: page.url(), viewport: '1280x900', metrics: data }, null, 1));
  const plane = page.locator('.catalog-control-plane');
  await plane.screenshot({ path: OUT + '/refuter1-db104-plane-1280.png' });
  await browser.close();
})().catch(e => { console.error('SCRIPT_FAIL', e.message); process.exit(1); });
