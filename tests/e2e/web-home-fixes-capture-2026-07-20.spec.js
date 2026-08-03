const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const SHOT_DIR = path.join(__dirname, '__screenshots__', 'web-home-fixes-2026-07-20');
fs.mkdirSync(SHOT_DIR, { recursive: true });
const shot = (n) => path.join(SHOT_DIR, n);

test.describe.configure({ retries: 0 });
test.setTimeout(120_000);

test('HOME — hero Cayenne corrigé + gallery Facebook photos produit', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/?dev', { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  await page.waitForTimeout(1200);

  // (1) HERO — la photo produit Cayenne doit charger (src = sandwich-cayenne.png)
  const heroImg = page.locator('.lc-hero-art img');
  await expect(heroImg).toBeVisible({ timeout: 10_000 });
  const heroSrc = await heroImg.getAttribute('src');
  const heroLoaded = await heroImg.evaluate((im) => im.complete && im.naturalWidth > 0);
  console.log('[hero] src=', heroSrc, ' chargée=', heroLoaded);
  await page.locator('.lc-hero').scrollIntoViewIfNeeded();
  await page.waitForTimeout(400);
  await page.screenshot({ path: shot('01-hero.png') });

  // (2) GALLERY — lien Facebook + tuiles = images produit (pas emoji)
  const fbBtn = page.getByRole('link', { name: /Le Cayenne sur Facebook/ });
  await fbBtn.scrollIntoViewIfNeeded();
  await page.waitForTimeout(500);
  const fbHref = await fbBtn.getAttribute('href');
  const tiles = page.locator('.lc-gallery-tile');
  const tileCount = await tiles.count();
  const firstTileHref = await tiles.first().getAttribute('href');
  const tileImgs = page.locator('.lc-gallery-tile img');
  const imgCount = await tileImgs.count();
  let loaded = 0;
  for (let i = 0; i < imgCount; i++) { if (await tileImgs.nth(i).evaluate((im) => im.complete && im.naturalWidth > 0).catch(() => false)) loaded++; }
  console.log(`[gallery] fbHref=${fbHref} tiles=${tileCount} tilesLien=${firstTileHref} images=${imgCount} chargées=${loaded}`);
  await page.locator('.lc-gallery').scrollIntoViewIfNeeded();
  await page.waitForTimeout(400);
  await page.screenshot({ path: shot('02-gallery.png') });

  expect(heroSrc, 'hero = sandwich-cayenne').toContain('sandwich-cayenne');
  expect(heroLoaded, 'hero image chargée (pas 404)').toBeTruthy();
  expect(fbHref, 'bouton → Facebook').toContain('facebook.com/LeCayenne');
  expect(firstTileHref, 'tuile → Facebook').toContain('facebook.com/LeCayenne');
  expect(imgCount, '5 tuiles image').toBe(5);
  expect(loaded, 'toutes les images gallery chargées').toBe(5);
});
