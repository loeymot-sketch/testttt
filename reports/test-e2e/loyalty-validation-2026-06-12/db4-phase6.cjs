const ROOT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10';
const { chromium } = require(ROOT + '/node_modules/playwright');
const BASE = 'http://127.0.0.1:8767';
const OUT = ROOT + '/reports/test-e2e/loyalty-validation-2026-06-12';
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await ctx.newPage();
  const sink = { console: [], http: [] };
  page.on('console', m => { if (m.type() === 'error') sink.console.push(m.text().slice(0, 200)); });
  page.on('pageerror', e => sink.console.push('[pageerror] ' + String(e).slice(0, 200)));
  page.on('response', r => { if (r.status() >= 400) sink.http.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });
  const login = async () => {
    await page.goto(BASE + '/login', { waitUntil: 'networkidle' }).catch(() => {});
    await page.waitForTimeout(1000);
    if (!page.url().includes('/login')) return;
    const tb = page.getByRole('textbox');
    await tb.nth(0).fill('admin@lecayenne.fr');
    await tb.nth(1).fill('123456');
    await page.getByRole('button', { name: /Connexion/i }).click();
    await page.waitForURL(/admin/, { timeout: 20000 });
    await page.waitForLoadState('networkidle');
  };
  await login();
  sink.console = []; sink.http = [];
  for (let i = 0; i < 3; i++) {
    const t0 = Date.now();
    await page.goto(BASE + '/admin/settings/branches/show/1', { waitUntil: 'networkidle', timeout: 30000 }).catch(() => {});
    await page.waitForFunction(() => { const ov = document.querySelector('.velmld-overlay, .velmld-full-screen'); return !ov || !ov.checkVisibility(); }, { timeout: 12000 }).catch(() => {});
    await page.waitForTimeout(1000);
    if (!page.url().includes('/login')) {
      console.log('loadMs', Date.now() - t0);
      break;
    }
    await login(); sink.console = []; sink.http = [];
  }
  await page.screenshot({ path: OUT + '/D-B4-settings-branch-show.png', fullPage: true });
  const txt = await page.evaluate(() => document.body.innerText.split('Devises').pop().slice(0, 1800));
  console.log(JSON.stringify({ finalUrl: page.url(), console: sink.console, http: sink.http, txt }, null, 1));
  await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
