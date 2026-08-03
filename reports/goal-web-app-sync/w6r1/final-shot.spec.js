const { test } = require('@playwright/test');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/web';
const TOKEN = '6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e';
test('final', async ({ page }) => {
  await page.addInitScript((t)=>{ localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone','0697222388'); }, TOKEN);
  await page.goto('http://127.0.0.1:8096/', { waitUntil: 'networkidle' });
  await page.locator("button:has-text('Fidélité')").first().click();
  await page.waitForSelector('.lc-wallet-code-qr svg path', { timeout: 10000 });
  await page.waitForTimeout(800);
  await page.screenshot({ path: OUT+'/10-fidelite-viewport.png' });
  await page.screenshot({ path: OUT+'/11-fidelite-full.png', fullPage: true });
  // click Historique tab if present
  const hist = page.locator("button:has-text('Historique')").first();
  if (await hist.count()) { await hist.click(); await page.waitForTimeout(1200); await page.screenshot({ path: OUT+'/12-historique.png' }); }
  const red = page.locator("button:has-text('Mes réductions')").first();
  if (await red.count()) { await red.click(); await page.waitForTimeout(800); await page.screenshot({ path: OUT+'/13-reductions.png' }); }
});
