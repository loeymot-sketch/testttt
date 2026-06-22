// WAVE6-KIOSK 2026-05-08 — capture 4 critical surfaces (READ-ONLY)
const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsKiosk } = require('./helpers/login');

const OUT = path.resolve(__dirname, '../captures/wave6-kiosk-2026-05-08/round-1');

test.describe('WAVE6 kiosk audit captures', () => {
  test.setTimeout(180_000);

  test('capture 4 critical kiosk surfaces', async ({ page }) => {
    page.on('console', m => console.log(`[browser ${m.type()}]`, m.text().slice(0, 200)));

    // Surface 1: kiosk login
    await page.goto('http://127.0.0.1:8000/kiosk/login', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.screenshot({ path: path.join(OUT, '01-login.png'), fullPage: true });
    console.log('captured login');

    // Authenticate via helper
    try {
      await loginAsKiosk(page);
    } catch (e) {
      console.log('loginAsKiosk failed', e.message);
    }

    // Surface 2: idle screen
    await page.goto('http://127.0.0.1:8000/kiosk/idle', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    await page.screenshot({ path: path.join(OUT, '02-idle.png'), fullPage: true });
    console.log('captured idle');

    // Tap idle screen to enter wizard zone
    await page.evaluate(() => {
      const el = document.querySelector('.kiosk-idle, .v-application, body');
      if (el) el.click();
    });
    await page.waitForTimeout(1500);
    await page.screenshot({ path: path.join(OUT, '03-categories.png'), fullPage: true });
    console.log('captured post-tap');

    // Surface 4: cart & payment surface — just navigate to URL if exists
    await page.goto('http://127.0.0.1:8000/kiosk/menu', { waitUntil: 'networkidle' }).catch(() => {});
    await page.waitForTimeout(1200);
    await page.screenshot({ path: path.join(OUT, '04-menu.png'), fullPage: true });
    console.log('captured menu');
  });
});
