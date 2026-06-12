const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8767';
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-validation-2026-06-12';
const EXE = '/Users/1millnonstop/Library/Caches/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-mac-arm64/chrome-headless-shell';
(async () => {
  const fs = require('fs');
  const browser = await chromium.launch({ headless: true, executablePath: fs.existsSync(EXE) ? EXE : undefined });
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 1000 } });
  const page = await ctx.newPage();
  const errs = [], http = [];
  page.on('console', m => { if (m.type()==='error') errs.push(m.text().slice(0,120)); });
  page.on('response', r => { if (r.status()>=400) http.push(`${r.status()} ${r.url().replace(BASE,'')}`); });
  try {
    await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    const tb = page.getByRole('textbox');
    await tb.nth(0).fill('admin@lecayenne.fr');
    await tb.nth(1).fill('123456');
    await page.getByRole('button', { name: /Connexion/i }).click();
    await page.waitForURL(/admin/, { timeout: 20000 });
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000);
    await page.goto(BASE + '/admin/settings/loyalty-setup', { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);
    await page.screenshot({ path: OUT + '/F4-setup-rates.png', fullPage: true });
    const inputs = await page.evaluate(() => {
      const out = [];
      document.querySelectorAll('input[type=number],input[type=text],select').forEach(el => {
        const grp = el.closest('.form-group,.mb-3,.col-md-6,.col-md-4,.row,div');
        const label = (grp?.querySelector('label')?.innerText || el.name || el.placeholder || '').trim().slice(0,70);
        out.push({ label, value: el.value });
      });
      return out;
    });
    console.log('URL=' + page.url());
    console.log('INPUTS=' + JSON.stringify(inputs));
    console.log('HTTP_ERR=' + JSON.stringify(http.slice(0,8)));
    const body = await page.evaluate(() => document.querySelector('main, .main-content, #app')?.innerText.replace(/\n{2,}/g,'\n').slice(0,800));
    console.log('MAIN_TEXT=\n' + body);
  } catch (e) { console.log('ERR=' + e.message); await page.screenshot({ path: OUT + '/F4-setup-rates.png', fullPage: true }).catch(()=>{}); }
  finally { await browser.close(); }
})();
