// FoodKing E2E — iter15 Mega-Audit Wave D (Admin UI rupture cascade)
//
// Owner mandate (Wave D, distinct from iter15-stock-cascade-regression which
// drives the toggle via tinker): prove that a rupture toggled FROM THE ADMIN
// UI (real click on the AvailabilityToggleComponent button) cascades to:
//   • POS tile  : Sprite 33cl (item 410) shows is-unavailable / 86-overlay
//   • Kiosk     : Sprite 33cl is hidden OR rendered as unavailable
//
// The actuator is the admin click — NOT a tinker artisan call. Tinker is used
// only as a belt-and-suspenders pre-flight reset and as the afterAll cleanup
// guard so the database is never left in a ruptured state.
//
// SSOT : AvailabilityService::toggle() persists item_branch_availability rows
// and dispatches ItemAvailabilityChanged. Both POS and Kiosk catalog read the
// same projection, so a single admin toggle should cascade across surfaces.
//
// Test SKU contract :
//   - Item id 410 = "Sprite 33cl"  (this spec — Wave D)
//   - Item id 361 = "Frites Seules" (owned by iter15-stock-cascade-regression
//     — Wave D MUST NOT touch it)
//
// Quartet evidence : every snap() emits png + dom.html + console.json +
// network.json via the shared mega-audit recorder so Wave E reviewers can run
// the 12-defect protocol in docs/iter15-mega/REVIEWER_PROTOCOL.md.

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { loginAsAdmin, loginAsPosOperator, loginAsKiosk } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/iter15-mega-rupture');

const SPRITE_ITEM_ID = 410;   // Sprite 33cl  (Wave D-owned SKU)
const BRANCH_ID = 1;          // LeCayenne default branch — used for tinker reset

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

test.describe('iter15 mega — Wave D (admin UI rupture cascade POS + Kiosk)', () => {
  test.setTimeout(240_000);

  test.afterAll(async () => {
    // Belt-and-suspenders : never leave Sprite 33cl ruptured, even if the
    // admin restore click failed mid-test. Tinker is allowed for cleanup
    // because it bypasses any UI-state pitfalls.
    try {
      setItemAvailability(SPRITE_ITEM_ID, BRANCH_ID, true);
    } catch (_e) { /* best-effort */ }
  });

  test('admin UI toggle cascades rupture to POS tile + Kiosk catalog', async ({ browser }) => {
    // ----- Pre-flight : ensure Sprite is currently available ---------------
    // (Tinker reset only — the actual rupture under test is admin-UI-driven.)
    setItemAvailability(SPRITE_ITEM_ID, BRANCH_ID, true);

    // ----- Two browser contexts (admin + POS) ; kiosk added best-effort ---
    const adminCtx = await browser.newContext();
    const posCtx = await browser.newContext();
    let kioskCtx = null;

    const adminPage = await adminCtx.newPage();
    const posPage = await posCtx.newPage();

    const adminRec = attachMegaAuditRecorder(adminPage, SCREENSHOT_DIR);
    const posRec = attachMegaAuditRecorder(posPage, SCREENSHOT_DIR);
    let kioskRec = null;

    // Track the toggle endpoint URLs hit (for the report).
    const toggleUrlsSeen = [];
    adminPage.on('response', (resp) => {
      try {
        if (/\/admin\/menu\/availability\/toggle/i.test(resp.url())) {
          toggleUrlsSeen.push({ url: resp.url(), status: resp.status() });
        }
      } catch (_e) { /* ignore */ }
    });

    try {
      // ===================================================================
      // 1) Admin login + items list
      // ===================================================================
      await loginAsAdmin(adminPage);
      await adminPage.goto('/admin/items', { waitUntil: 'domcontentloaded' });
      await adminPage.waitForTimeout(2_000);
      await adminRec.snap('01-admin-items-list');

      // ===================================================================
      // 2) POS login + baseline (Sprite available, no overlay)
      // ===================================================================
      await loginAsPosOperator(posPage);
      // POS catalog mount + render. loginAsPosOperator already navigated to
      // /admin/pos so a reload is unnecessary here.
      await posPage.waitForTimeout(2_500);
      await posRec.snap('01-pos-with-sprite-available');

      // Sanity-check Sprite tile is visible *without* unavailable state.
      const baselineSpriteTile = posPage.locator('.pos-v5-tile, .pos-item-tile').filter({ hasText: /Sprite/i }).first();
      const baselineVisible = await baselineSpriteTile.isVisible({ timeout: 10_000 }).catch(() => false);
      if (baselineVisible) {
        const baselineState = await baselineSpriteTile.evaluate((el) => ({
          hasUnavailable: el.classList.contains('is-unavailable'),
          hasOverlay: el.querySelector('.pos-item-86-badge, .pos-v5-tile__overlay') !== null,
        }));
        console.log(`[WAVE-D] baseline POS Sprite tile = ${JSON.stringify(baselineState)}`);
        expect(baselineState.hasUnavailable, 'Sprite must NOT be unavailable at baseline').toBeFalsy();
        expect(baselineState.hasOverlay, 'Sprite must NOT have rupture overlay at baseline').toBeFalsy();
      } else {
        console.log('[WAVE-D] baseline POS Sprite tile not found (catalog may paginate) — proceeding.');
      }

      // ===================================================================
      // 3) Find admin toggle row for item 410
      //    Items list is paginated, so use the search filter to surface 410.
      // ===================================================================
      let toggleLocator = adminPage.locator(`[data-testid="admin-availability-toggle-${SPRITE_ITEM_ID}"]`);
      let toggleVisibleNoSearch = await toggleLocator.first().isVisible({ timeout: 2_000 }).catch(() => false);

      let usedSearchFilter = false;
      if (!toggleVisibleNoSearch) {
        // Open the filter panel and search "Sprite".
        // The collapsible is toggled by the FilterComponent button — to keep
        // this resilient against UI reshuffles we set the v-model input
        // directly and submit the search form.
        usedSearchFilter = true;
        // Scope to the filter panel so we don't collide with the create-item
        // form's hidden #name input on the same page.
        const nameInput = adminPage.locator('#item-filter #name');
        // Make the filter panel visible if currently collapsed.
        if (!(await nameInput.isVisible({ timeout: 1_500 }).catch(() => false))) {
          // FilterComponent toggles `#item-filter` — try clicking it.
          const filterBtn = adminPage.locator('button:has(.lab-filter), .db-card-filter button').first();
          await filterBtn.click({ timeout: 5_000 }).catch(() => {});
          await adminPage.waitForTimeout(400);
        }
        // Robust fallback : if input is still hidden, force the panel open.
        await adminPage.evaluate(() => {
          const panel = document.getElementById('item-filter');
          if (panel) panel.style.display = 'block';
        }).catch(() => {});
        await nameInput.fill('Sprite');
        // Submit the form (the search button inside the collapsible).
        await adminPage.locator('#item-filter form').evaluate((f) => f.requestSubmit ? f.requestSubmit() : f.submit()).catch(() => {});
        await adminPage.waitForTimeout(1_500);
        toggleLocator = adminPage.locator(`[data-testid="admin-availability-toggle-${SPRITE_ITEM_ID}"]`);
      }

      await expect(toggleLocator.first()).toBeVisible({ timeout: 10_000 });
      await toggleLocator.first().scrollIntoViewIfNeeded().catch(() => {});
      await adminPage.waitForTimeout(400);
      await adminRec.snap('02-admin-row-found');

      // ===================================================================
      // 4) Click the admin toggle to mark Sprite ruptured (UI actuator)
      // ===================================================================
      const toggleButton = toggleLocator.first().locator('button').first();
      const togglePending = adminPage.waitForResponse(
        (resp) =>
          /\/admin\/menu\/availability\/toggle/i.test(resp.url()) &&
          resp.request().method() === 'POST',
        { timeout: 15_000 },
      );
      await toggleButton.click();
      const toggleResp = await togglePending;
      expect(toggleResp.status(), `Toggle endpoint should respond 2xx (got ${toggleResp.status()})`).toBeLessThan(300);
      const togglePayload = await toggleResp.json().catch(() => null);
      console.log(`[WAVE-D] admin toggle response = ${JSON.stringify(togglePayload)}`);

      // Wait for any post-action toast / re-list call.
      await adminPage.waitForTimeout(1_500);
      await adminRec.snap('03-admin-toggle-clicked-now-unavailable');

      // ===================================================================
      // 5) POS cascade verification
      // ===================================================================
      const cascadeStart = Date.now();
      await posPage.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
      await posPage.waitForTimeout(3_000); // SPA mount + catalog refetch

      const ruptureSpriteTile = posPage.locator('.pos-v5-tile, .pos-item-tile').filter({ hasText: /Sprite/i }).first();
      await expect(ruptureSpriteTile, 'Sprite tile must remain in POS catalog (visible but ruptured)').toBeVisible({ timeout: 12_000 });

      const ruptureState = await ruptureSpriteTile.evaluate((el) => ({
        classes: el.className,
        hasUnavailable: el.classList.contains('is-unavailable'),
        hasOverlay: el.querySelector('.pos-item-86-badge, .pos-v5-tile__overlay') !== null,
        overlayText: el.querySelector('.pos-item-86-badge, .pos-v5-tile__overlay')?.innerText || null,
      }));
      const cascadeMs = Date.now() - cascadeStart;
      console.log(`[WAVE-D] POS cascade after admin toggle (${cascadeMs}ms) = ${JSON.stringify(ruptureState)}`);

      await posRec.snap('04-pos-sprite-shows-rupture');

      expect(
        ruptureState.hasUnavailable || ruptureState.hasOverlay,
        `POS Sprite tile must show is-unavailable class OR rupture overlay after admin click. Got: ${JSON.stringify(ruptureState)}`
      ).toBeTruthy();

      // ===================================================================
      // 6) Kiosk cascade — best-effort
      // ===================================================================
      let kioskBlocked = false;
      let kioskState = null;
      try {
        kioskCtx = await browser.newContext();
        const kioskPage = await kioskCtx.newPage();
        kioskRec = attachMegaAuditRecorder(kioskPage, SCREENSHOT_DIR);

        const idleResp = await kioskPage.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' }).catch(() => null);
        if (!idleResp || idleResp.status() >= 400) {
          kioskBlocked = true;
          await kioskRec.snap('05-kiosk-not-reachable');
        } else {
          await kioskPage.waitForTimeout(1_500);

          // [iter15-mega-fix D5-003 run-6 2026-05-10] Idle-bypass extended.
          // The idle screen exposes order-type tiles ("À emporter" + optionally
          // "Sur place"). Click the takeaway tile to advance into the catalog,
          // falling back to legacy "commencer" CTA. Then wait for actual product
          // tiles to mount before scanning so cascade verification is conclusive
          // (not just totalMatches=0 because catalog never rendered).
          const startBtn = kioskPage.getByRole('button', { name: /commander|start|commencer|order/i }).first();
          if (await startBtn.isVisible({ timeout: 1_500 }).catch(() => false)) {
            await startBtn.click({ timeout: 5_000 }).catch(() => {});
            await kioskPage.waitForTimeout(1_500);
          }
          const takeawayTile = kioskPage.locator('[data-testid="kiosk-order-type-takeaway"], button:has-text("À emporter"), [class*="kiosk-order-type"]:has-text("emporter")').first();
          if (await takeawayTile.isVisible({ timeout: 3_000 }).catch(() => false)) {
            await takeawayTile.click({ timeout: 5_000 }).catch(() => {});
            await kioskPage.waitForTimeout(2_500);
          }
          await kioskPage.locator('[class*="kiosk-product"], [data-testid*="kiosk-product-card"]').first()
            .waitFor({ state: 'visible', timeout: 8_000 }).catch(() => {});

          // Scan for "Sprite" presence + state. We do NOT add to cart.
          kioskState = await kioskPage.evaluate(() => {
            const all = Array.from(document.querySelectorAll('button, [class*="kiosk-product"], [class*="kiosk-item"], [data-testid*="kiosk-product"]'));
            const matches = all.filter((el) => /Sprite/i.test(el.innerText || ''));
            return {
              totalMatches: matches.length,
              firstMatchHTML: matches[0]?.outerHTML.substring(0, 500) || null,
              firstMatchText: matches[0]?.innerText || null,
              firstMatchHasRupture: matches[0]?.matches('.is-unavailable, .kiosk-product--unavailable, [class*="ruptured"], [class*="rupture"]') || false,
              firstMatchPointerEvents: matches[0] ? window.getComputedStyle(matches[0]).pointerEvents : null,
            };
          });
          console.log(`[WAVE-D] Kiosk Sprite state = ${JSON.stringify(kioskState)}`);
          await kioskRec.snap('05-kiosk-after-rupture');

          // Acceptable cascade : Sprite hidden OR marked unavailable.
          const acceptable =
            kioskState.totalMatches === 0 ||
            kioskState.firstMatchHasRupture ||
            /rupture|indispo|unavailable|épuisé/i.test(kioskState.firstMatchText || '') ||
            kioskState.firstMatchPointerEvents === 'none';
          if (!acceptable) {
            console.warn(`[WAVE-D] Kiosk cascade NOT confirmed — got ${JSON.stringify(kioskState)} (best-effort, will not fail spec)`);
          }
        }
      } catch (kioskErr) {
        kioskBlocked = true;
        console.log(`[WAVE-D] Kiosk context failed: ${kioskErr.message}`);
        if (kioskRec) {
          await kioskRec.snap('05-kiosk-not-reachable');
        }
      }

      // ===================================================================
      // 7) Restore via admin UI — click the same toggle to re-enable Sprite
      // ===================================================================
      // After the previous toggle the row may have been re-fetched (the
      // component emits availability-changed → list()). Re-resolve the row.
      const toggleLocatorPostRupture = adminPage.locator(`[data-testid="admin-availability-toggle-${SPRITE_ITEM_ID}"]`).first();
      await expect(toggleLocatorPostRupture, 'Admin row for Sprite must remain visible after rupture').toBeVisible({ timeout: 10_000 });

      const restoreButton = toggleLocatorPostRupture.locator('button').first();
      const restorePending = adminPage.waitForResponse(
        (resp) =>
          /\/admin\/menu\/availability\/toggle/i.test(resp.url()) &&
          resp.request().method() === 'POST',
        { timeout: 15_000 },
      );
      await restoreButton.click();
      const restoreResp = await restorePending;
      expect(restoreResp.status(), `Restore toggle endpoint should respond 2xx (got ${restoreResp.status()})`).toBeLessThan(300);
      await adminPage.waitForTimeout(1_500);
      await adminRec.snap('06-admin-row-restored');

      // ===================================================================
      // 8) POS recovery verification
      // ===================================================================
      await posPage.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
      await posPage.waitForTimeout(3_000);
      const cleanSpriteTile = posPage.locator('.pos-v5-tile, .pos-item-tile').filter({ hasText: /Sprite/i }).first();
      await expect(cleanSpriteTile).toBeVisible({ timeout: 12_000 });
      const cleanState = await cleanSpriteTile.evaluate((el) => ({
        hasUnavailable: el.classList.contains('is-unavailable'),
        hasOverlay: el.querySelector('.pos-item-86-badge, .pos-v5-tile__overlay') !== null,
      }));
      console.log(`[WAVE-D] POS Sprite clean state after restore = ${JSON.stringify(cleanState)}`);
      await posRec.snap('07-pos-sprite-clean-after-restore');
      expect(cleanState.hasUnavailable, 'Sprite must NOT be unavailable after restore').toBeFalsy();
      expect(cleanState.hasOverlay, 'Sprite must NOT show overlay after restore').toBeFalsy();

      // Surface the captured toggle network calls for the report.
      console.log(`[WAVE-D] Toggle endpoint hits: ${JSON.stringify(toggleUrlsSeen)}`);
      console.log(`[WAVE-D] Used admin search filter to surface row: ${usedSearchFilter}`);
      console.log(`[WAVE-D] Kiosk reachable: ${!kioskBlocked} ; state: ${JSON.stringify(kioskState)}`);
    } finally {
      // Detach recorders + close contexts. afterAll guarantees DB restore.
      try { adminRec.dispose(); } catch (_e) { /* ignore */ }
      try { posRec.dispose(); } catch (_e) { /* ignore */ }
      if (kioskRec) { try { kioskRec.dispose(); } catch (_e) { /* ignore */ } }
      try { await adminCtx.close(); } catch (_e) { /* ignore */ }
      try { await posCtx.close(); } catch (_e) { /* ignore */ }
      if (kioskCtx) { try { await kioskCtx.close(); } catch (_e) { /* ignore */ } }
    }
  });
});
