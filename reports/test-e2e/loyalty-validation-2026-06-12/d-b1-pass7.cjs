/* D-B1 pass 7: clean loaded re-captures of dashboard, items, ingredients (Tous) */
const path = require('path');
const fs = require('fs');
const DIR = __dirname;
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8767';
const TOKEN = process.env.FK_TOKEN;

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.route(/\/api\//, (route) => {
    const headers = { ...route.request().headers(), authorization: 'Bearer ' + TOKEN };
    route.continue({ headers });
  });
  const page = await ctx.newPage();
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1000);
  const tb = page.getByRole('textbox');
  await tb.nth(0).fill('admin@lecayenne.fr');
  await tb.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/admin/, { timeout: 30000 });
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2500);
  await page.screenshot({ path: path.join(DIR, 'D-B1-dashboard.png') });
  console.log('dashboard captured');

  await page.goto(BASE + '/admin/items', { waitUntil: 'commit' });
  await page.waitForSelector('table tbody tr td', { timeout: 60000 });
  await page.waitForTimeout(2000);
  await page.screenshot({ path: path.join(DIR, 'D-B1-items.png') });
  console.log('items captured');

  await page.goto(BASE + '/admin/ingredients', { waitUntil: 'commit' });
  await page.waitForSelector('[data-testid="ingredient-list"] table tbody tr', { timeout: 60000 });
  await page.waitForTimeout(800);
  await page.screenshot({ path: path.join(DIR, 'D-B1-ingredients.png') });
  fs.writeFileSync(path.join(DIR, 'D-B1-ingredients.txt'), await page.evaluate(() => document.body.innerText));
  console.log('ingredients captured');
  await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
