const ROOT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10';
const { chromium } = require(ROOT + '/node_modules/playwright');
const BASE = 'http://127.0.0.1:8767';
const OUT = ROOT + '/reports/test-e2e/loyalty-validation-2026-06-12';

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await ctx.newPage();
  const reqs = [];
  const consoleAll = [];
  page.on('console', m => consoleAll.push(`[${m.type()}] ${m.text().slice(0, 200)}`));
  page.on('pageerror', e => consoleAll.push(`[pageerror] ${String(e).slice(0, 200)}`));
  page.on('requestfailed', r => reqs.push({ url: r.url().replace(BASE, ''), status: 'FAILED ' + (r.failure() || {}).errorText }));
  page.on('response', r => { if (r.url().includes('/api/')) reqs.push({ url: r.url().replace(BASE, '').slice(0, 90), status: r.status() }); });

  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  const tb = page.getByRole('textbox');
  await tb.nth(0).fill('admin@lecayenne.fr');
  await tb.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/admin/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  reqs.length = 0; consoleAll.length = 0;

  await page.goto(BASE + '/admin/settings/loyalty-setup', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(4000); // generous settle
  const vals = await page.evaluate(() => ({
    per_euro: document.querySelector('#loyalty_points_per_euro')?.value,
    per_discount: document.querySelector('#loyalty_points_for_1_euro_discount')?.value,
    min: document.querySelector('#loyalty_min_redeem_points')?.value,
    preview: document.querySelector('.bg-blue-50')?.innerText?.replace(/\n/g, ' | '),
  }));
  await page.screenshot({ path: OUT + '/D-B4-settings-loyalty-trace.png' });
  console.log(JSON.stringify({ vals, apiRequests: reqs, console: consoleAll.slice(0, 20) }, null, 1));
  await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
