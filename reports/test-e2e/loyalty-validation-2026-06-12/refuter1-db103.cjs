/* REFUTER-1 D-B1-03: dashboard datepickers EN locale check on :8767 */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8767';
const OUT = __dirname;
const EXE = '/Users/1millnonstop/Library/Caches/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-mac-arm64/chrome-headless-shell';

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: fs.existsSync(EXE) ? EXE : undefined });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'fr-FR' });
  const page = await ctx.newPage();

  // login
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  const tb = page.getByRole('textbox');
  await tb.nth(0).fill('admin@lecayenne.fr');
  await tb.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/admin/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');

  await page.goto(BASE + '/admin/dashboard', { waitUntil: 'networkidle' });
  await page.waitForTimeout(2500);

  // collect all datepicker inputs values
  const inputs = await page.$$eval('input[id^="dp-input-"]', els =>
    els.map(e => ({ id: e.id, value: e.value, placeholder: e.placeholder }))
  );
  console.log('DATEPICKER INPUTS:', JSON.stringify(inputs, null, 2));

  // open the orderStatistics datepicker calendar
  const target = page.locator('#dp-input-orderStatisticsDate');
  await target.scrollIntoViewIfNeeded();
  await target.click();
  await page.waitForTimeout(1200);

  // extract calendar header month label + weekday names
  const cal = await page.evaluate(() => {
    const month = document.querySelector('[data-dp-element="overlay-month"], .dp__month_year_select');
    const monthBtns = Array.from(document.querySelectorAll('.dp__month_year_select')).map(e => e.textContent.trim());
    const weekdays = Array.from(document.querySelectorAll('.dp__calendar_header_item')).map(e => e.textContent.trim());
    const presets = Array.from(document.querySelectorAll('.dp--preset-range, .dp__preset_range')).map(e => e.textContent.trim());
    return { monthBtns, weekdays, presets };
  });
  console.log('CALENDAR:', JSON.stringify(cal, null, 2));

  await page.screenshot({ path: OUT + '/refuter1-DB103-orderstats-calendar.png', fullPage: false });

  await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
