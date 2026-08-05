// =============================================================================
// REVALIDATION E2E LIVE 2026-07-23 — R3 nav mobile web (Pixel 7)
// Burger → tiroir PLEIN ÉCRAN, 4 liens tapables, 0 débord horizontal.
// READ-ONLY probe. Run: PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/revalidation-mobile-2026-07-23.spec.js --project=chromium
// =============================================================================
const { test, expect, devices } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const BASE = 'https://site-lecayenne.vercel.app';
const SHOT = path.join(__dirname, '__screenshots__', 'revalidation-2026-07-23');
fs.mkdirSync(SHOT, { recursive: true });
const shot = (n) => path.join(SHOT, n);

const px7 = devices['Pixel 7'] || { ...devices['Pixel 5'], viewport: { width: 412, height: 915 } };
test.use({ ...px7 });
test.describe.configure({ retries: 0 });
test.setTimeout(150_000);

test('R3 — nav mobile Pixel 7 : burger → tiroir plein écran, 4 liens, 0 débord', async ({ page }) => {
  const consoleErrors = []; const netProblems = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 240)); });
  page.on('response', (r) => { if (r.status() >= 400) netProblems.push({ status: r.status(), url: r.url().slice(0, 160) }); });
  page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.message).slice(0, 200)));

  await page.goto(BASE + '/?dev', { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  await page.waitForTimeout(1_200);
  await page.screenshot({ path: shot('R3-01-home-mobile.png'), fullPage: false });

  const obs = { device: 'Pixel 7', viewport: page.viewportSize() };

  // débord horizontal AVANT ouverture
  obs.overflowClosed = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    horizontal: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
  }));

  // burger visible + tap
  const burger = page.locator('.lc-nav-burger');
  await expect(burger, 'burger visible sur mobile').toBeVisible({ timeout: 10_000 });
  await burger.tap().catch(async () => { await burger.click(); });
  const drawer = page.locator('#lc-mobile-menu');
  await expect(drawer, 'tiroir ouvert').toBeVisible({ timeout: 6_000 });
  await page.waitForTimeout(600);
  await page.screenshot({ path: shot('R3-02-tiroir-ouvert.png'), fullPage: false });

  // plein écran ?
  const box = await drawer.boundingBox();
  const vp = page.viewportSize();
  obs.drawerBox = box; obs.vp = vp;
  obs.coverageW = box ? +(box.width / vp.width).toFixed(3) : 0;
  obs.coverageH = box ? +(box.height / vp.height).toFixed(3) : 0;

  // 4 liens tapables
  const links = drawer.locator('.lc-mobile-link');
  obs.linkCount = await links.count();
  obs.links = [];
  for (let i = 0; i < obs.linkCount; i++) {
    const b = await links.nth(i).boundingBox();
    const txt = (await links.nth(i).innerText().catch(() => '')).replace(/\s+/g, ' ').trim();
    obs.links.push({ txt, h: b ? Math.round(b.height) : 0, w: b ? Math.round(b.width) : 0, visible: !!b });
  }

  // débord horizontal AVEC tiroir ouvert
  obs.overflowOpen = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    horizontal: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
  }));

  // tap réel sur « Menu » → grille menu s'affiche (lien fonctionnel)
  const menuLink = drawer.locator('.lc-mobile-link', { hasText: 'Menu' }).first();
  await menuLink.tap().catch(async () => { await menuLink.click(); });
  obs.menuGridAfterTap = await page.locator('.lc-menu-grid').first().isVisible({ timeout: 12_000 }).catch(() => false);
  obs.drawerClosedAfterTap = !(await drawer.isVisible({ timeout: 1_000 }).catch(() => false));
  await page.waitForTimeout(600);
  await page.screenshot({ path: shot('R3-03-apres-tap-menu.png'), fullPage: false });

  obs.consoleErrors = consoleErrors; obs.netProblems = netProblems;
  fs.writeFileSync(path.join(SHOT, 'obs-R3.json'), JSON.stringify(obs, null, 2));
  console.log('[R3]', JSON.stringify(obs));

  expect(obs.linkCount, '4 liens dans le tiroir').toBe(4);
  for (const l of obs.links) expect(l.h, `lien "${l.txt}" tapable (≥40px)`).toBeGreaterThanOrEqual(40);
  expect(obs.coverageW, 'tiroir pleine largeur (≥95%)').toBeGreaterThanOrEqual(0.95);
  expect(obs.coverageH, 'tiroir pleine hauteur (≥85%)').toBeGreaterThanOrEqual(0.85);
  expect(obs.overflowClosed.horizontal, '0 débord horizontal (fermé)').toBeFalsy();
  expect(obs.overflowOpen.horizontal, '0 débord horizontal (ouvert)').toBeFalsy();
  expect(obs.menuGridAfterTap, 'tap Menu → grille menu').toBeTruthy();
});
