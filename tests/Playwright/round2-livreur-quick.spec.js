// Round 2 LIVREUR — quick capture (no setup)
const { test, expect } = require('@playwright/test');

test('LIVREUR quick capture', async ({ page }) => {
  test.setTimeout(60000);
  page.setDefaultTimeout(15000);

  // login
  await page.goto('http://127.0.0.1:8000/login');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);
  // Inputs may be Vue-rendered without type=email; click by label text
  const inputs = await page.locator('input:not([type=checkbox]):not([type=hidden])').all();
  if (inputs.length >= 2) {
    await inputs[0].fill('admin@lecayenne.fr');
    await inputs[1].fill('123456');
  }
  await page.click('button:has-text("Connexion"), button[type="submit"]');
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(2500);
  await page.screenshot({ path: '/tmp/foodking-round2-livreur/00-after-login.png' });

  // delivery boys list
  await page.goto('http://127.0.0.1:8000/admin/delivery-boys');
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(2500);
  await page.screenshot({ path: '/tmp/foodking-round2-livreur/01-delivery-boys-list.png' });

  // cash sessions list
  await page.goto('http://127.0.0.1:8000/admin/delivery-boy-cash-sessions');
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(2500);
  await page.screenshot({ path: '/tmp/foodking-round2-livreur/02-cash-session-list.png' });

  // cash session detail
  await page.goto('http://127.0.0.1:8000/admin/delivery-boy-cash-sessions/1');
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(2500);
  await page.screenshot({ path: '/tmp/foodking-round2-livreur/03-cash-session-show.png' });

  // try livreur self-service path
  await page.goto('http://127.0.0.1:8000/livreur');
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(1500);
  await page.screenshot({ path: '/tmp/foodking-round2-livreur/05-livreur-self-service.png' });
});
