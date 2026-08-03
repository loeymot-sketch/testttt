// FoodKing E2E — iter15 Stock Rupture Cascade Visual Proof (2026-05-10)
//
// Owner mandate iter15 : "tu mets un produit en rupture, tu vérifies que ça
// cascade dans toutes les surfaces (POS, Kiosk, KDS) avec capture d'écran."
//
// Coverage :
//   • Mark Frites Seules (item 361 / branch 1) ruptured via AvailabilityService
//   • Visit POS surface : tile shows .pos-item-86-badge overlay (item disabled)
//   • Visit Kiosk surface : item not orderable / rupture badge visible
//   • Cleanup : restore Frites Seules to available state at test end
//
// Architecture note : AvailabilityService::toggle() is the SSoT for
// item_branch_availability. Both Kiosk catalog (MenuProjectionService) and POS
// (PosController) consume the same source, so a single toggle should cascade
// to both surfaces consistently.

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { loginAsPosOperator } = require('./helpers/login');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/iter15-stock-cascade');

const FRITES_ITEM_ID = 361;   // Frites Seules
const BRANCH_ID = 1;          // LeCayenne default branch

function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: process.cwd(),
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

function setItemAvailability(itemId, branchId, available, reason = null) {
  const reasonPhp = reason ? `'${reason.replace(/'/g, "\\'")}'` : 'null';
  return artisan(
    `app(App\\Services\\Menu\\AvailabilityService::class)->toggle(${itemId}, ${branchId}, ${available ? 'true' : 'false'}, ${reasonPhp}); echo 'OK';`
  );
}

async function snap(page, name) {
  await page.screenshot({
    path: path.join(SCREENSHOT_DIR, `${name}.png`),
    fullPage: false,
  });
}

test.describe('iter15 — stock rupture cascade POS + Kiosk', () => {
  test.setTimeout(180_000);

  test.afterAll(async () => {
    // Belt-and-suspenders cleanup : restore Frites Seules to available
    // even if a test failed before the inline restore step.
    try {
      setItemAvailability(FRITES_ITEM_ID, BRANCH_ID, true);
    } catch (_e) { /* best-effort */ }
  });

  test('rupture POS — Frites Seules tile shows pos-item-86-badge overlay', async ({ page }) => {
    // ----- Pre-flight : mark Frites ruptured -----
    const out1 = setItemAvailability(FRITES_ITEM_ID, BRANCH_ID, false, 'iter15-stock-cascade-test');
    expect(out1).toContain('OK');

    try {
      // ----- POS verification -----
      await loginAsPosOperator(page);
      await page.waitForTimeout(2_500); // let SPA mount + catalog render
      await snap(page, '01-pos-after-rupture');

      // Find the Frites Seules tile and assert it has an overlay/badge
      const fritesTile = page.locator('.pos-v5-tile, .pos-item-tile').filter({ hasText: /Frites? Seules?/i }).first();
      await expect(fritesTile).toBeVisible({ timeout: 10_000 });

      const tileState = await fritesTile.evaluate((el) => {
        return {
          classes: el.className,
          hasUnavailable: el.classList.contains('is-unavailable'),
          hasOverlay: el.querySelector('.pos-item-86-badge, .pos-v5-tile__overlay') !== null,
          overlayText: el.querySelector('.pos-item-86-badge, .pos-v5-tile__overlay')?.innerText || null,
        };
      });
      console.log(`[STOCK-POS] Frites Seules tile state = ${JSON.stringify(tileState)}`);
      expect(
        tileState.hasUnavailable || tileState.hasOverlay,
        `Frites Seules tile must show is-unavailable class OR rupture overlay. Got: ${JSON.stringify(tileState)}`
      ).toBeTruthy();

      await snap(page, '02-pos-frites-tile-with-86-badge');
    } finally {
      // Always restore availability — even on test failure
      const out2 = setItemAvailability(FRITES_ITEM_ID, BRANCH_ID, true);
      expect(out2).toContain('OK');
    }
  });

  test('rupture Kiosk — Frites Seules absent ou badge rupture sur catalogue public', async ({ page }) => {
    // ----- Pre-flight : mark Frites ruptured -----
    const out1 = setItemAvailability(FRITES_ITEM_ID, BRANCH_ID, false, 'iter15-stock-cascade-kiosk');
    expect(out1).toContain('OK');

    try {
      // ----- Kiosk verification (public catalog, no login needed) -----
      await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1_500);

      // Click "commander" / start ordering button if present
      const startBtn = page.getByRole('button', { name: /commander|start|commencer|order/i }).first();
      if (await startBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await startBtn.click({ timeout: 5_000 }).catch(() => {});
        await page.waitForTimeout(1_500);
      }
      await snap(page, '03-kiosk-catalog-after-rupture');

      // Look for Frites Seules in kiosk grid - either absent or marked rupture
      const fritesPresence = await page.evaluate(() => {
        const all = Array.from(document.querySelectorAll('button, [class*="kiosk-product"], [class*="kiosk-item"], [data-testid*="kiosk-product"]'));
        const matches = all.filter((el) => /Frites? Seules?/i.test(el.innerText || ''));
        return {
          totalMatches: matches.length,
          firstMatchHTML: matches[0]?.outerHTML.substring(0, 500) || null,
          firstMatchText: matches[0]?.innerText || null,
          firstMatchHasRupture: matches[0]?.matches('.is-unavailable, .kiosk-product--unavailable, [class*="ruptured"], [class*="rupture"]') || false,
          firstMatchPointerEvents: matches[0] ? window.getComputedStyle(matches[0]).pointerEvents : null,
        };
      });
      console.log(`[STOCK-KIOSK] Frites Seules state = ${JSON.stringify(fritesPresence)}`);

      // Acceptance : either Frites is hidden (totalMatches=0) OR has rupture badge/class
      const acceptable =
        fritesPresence.totalMatches === 0 ||
        fritesPresence.firstMatchHasRupture ||
        /rupture|indispo|unavailable|épuisé/i.test(fritesPresence.firstMatchText || '') ||
        fritesPresence.firstMatchPointerEvents === 'none';

      expect(
        acceptable,
        `Frites Seules must be hidden OR show rupture state on Kiosk. Got: ${JSON.stringify(fritesPresence)}`
      ).toBeTruthy();

      await snap(page, '04-kiosk-frites-rupture-cascade-confirmed');
    } finally {
      const out2 = setItemAvailability(FRITES_ITEM_ID, BRANCH_ID, true);
      expect(out2).toContain('OK');
    }
  });

  test('rupture cleared — POS tile clean après restore (no leftover badge)', async ({ page }) => {
    // Ensure clean state first
    setItemAvailability(FRITES_ITEM_ID, BRANCH_ID, true);
    await new Promise((r) => setTimeout(r, 500));

    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const fritesTile = page.locator('.pos-v5-tile, .pos-item-tile').filter({ hasText: /Frites? Seules?/i }).first();
    await expect(fritesTile).toBeVisible({ timeout: 10_000 });

    const cleanState = await fritesTile.evaluate((el) => ({
      hasUnavailable: el.classList.contains('is-unavailable'),
      hasOverlay: el.querySelector('.pos-item-86-badge, .pos-v5-tile__overlay') !== null,
    }));
    console.log(`[STOCK-RESET] Frites Seules clean state = ${JSON.stringify(cleanState)}`);
    expect(cleanState.hasUnavailable).toBeFalsy();
    expect(cleanState.hasOverlay).toBeFalsy();

    await snap(page, '05-pos-frites-tile-clean-after-restore');
  });
});
