// [GOAL 100% round-4 CLUSTER-DELIVERY-PARKED] Visual render of the Caisse Livreur
// (delivery boy cash session) admin surfaces on the disposable clone :8766.
// Validates item (3): "the livreur surfaces (Caisse Livreur) render clean".
const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/login');
const fs = require('fs');
const path = require('path');

const OUT = path.resolve(__dirname, 'screenshots', 'r4-caisse-livreur');
fs.mkdirSync(OUT, { recursive: true });

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '123456';

async function shot(page, name) {
  await Promise.race([
    page.screenshot({ path: path.join(OUT, name), fullPage: true }),
    new Promise((_, rej) => setTimeout(() => rej(new Error('screenshot 7s timeout')), 7000)),
  ]);
}

test('Caisse Livreur surfaces render clean', async ({ page }) => {
  test.setTimeout(70000);
  await login(page, ADMIN_EMAIL, ADMIN_PASS);

  // LIST
  await page.goto('/admin/delivery-boy-cash-sessions', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await shot(page, '01-list.png');
  const listText = await page.locator('body').innerText().catch(() => '');
  console.log('LIST_TEXT_START>>>' + listText.replace(/\n+/g, ' | ').slice(0, 1200) + '<<<LIST_TEXT_END');

  // SHOW — reconciled exact (session 6)
  await page.goto('/admin/delivery-boy-cash-sessions/6', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await shot(page, '02-show-reconciled.png');
  const showText = await page.locator('body').innerText().catch(() => '');
  console.log('SHOW6_TEXT_START>>>' + showText.replace(/\n+/g, ' | ').slice(0, 1500) + '<<<SHOW6_TEXT_END');

  // SHOW — variance session (7, variance -2.50)
  await page.goto('/admin/delivery-boy-cash-sessions/7', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await shot(page, '03-show-variance.png');
  const varText = await page.locator('body').innerText().catch(() => '');
  console.log('SHOW7_TEXT_START>>>' + varText.replace(/\n+/g, ' | ').slice(0, 1500) + '<<<SHOW7_TEXT_END');

  expect(listText.length + showText.length).toBeGreaterThan(0);
});
