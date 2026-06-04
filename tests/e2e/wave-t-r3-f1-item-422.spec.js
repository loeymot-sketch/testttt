// =============================================================================
// Wave T Round 3 — F1 P0 cluster heal verification (silent 422 on POS landing)
// -----------------------------------------------------------------------------
// What this spec proves (surface contract, post-heal):
//   1. POS landing (/admin/pos-v4) for admin@lecayenne.fr produces ZERO
//      `/api/admin/item` 422 responses during the first 10 s after navigation.
//      Sentinel for WT-A-R2-002. Before the heal, the bootstrap fired
//      `itemList()` before `defaultAccess/show` resolved a branch_id, and the
//      controller's CV1-POS-AVAILABILITY-LIVE-001 guard returned 422 silently.
//      The fix short-circuits the doomed dispatch at the Vuex layer
//      (resources/js/store/modules/item.js — when surface=pos AND no usable
//      branch_id, resolve with empty payload instead of firing the request).
//   2. The POS tile catalog still renders correctly (≥1 product tile visible),
//      proving the branch-aware refetch wired through `defaultAccess/show`
//      still fires once the branch is resolved.
//   3. No silent-failure toast is shown on landing (catalog_unavailable would
//      only appear if a 422 were actually emitted on a critical path — which
//      the heal prevents). This is the positive-path sentinel.
//   4. NEGATIVE-PATH: a forced 422 on /api/admin/item DOES surface a toast.
//      This validates the bootstrap.js critical-path 422 toast layer added
//      as defense-in-depth (if any future regression re-introduces a silent
//      422 on the catalog SSOT, the operator sees it).
//
// Scope-minimal: only POS landing surface. Caisse-to-delivered flow is
// covered by wave-T-A/B/C/D. We do NOT exercise checkout here.
// =============================================================================

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/wave-t-r3-f1-item-422');
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

test.describe('Wave T R3 F1 — POS landing /api/admin/item no silent 422', () => {
  test.setTimeout(120_000);

  test('POS landing produces zero /api/admin/item 422 + catalog renders', async ({ page }) => {
    const itemRequests = [];
    page.on('response', (resp) => {
      const url = resp.url();
      if (url.includes('/api/admin/item') && !url.includes('/api/admin/item-')) {
        itemRequests.push({
          url,
          status: resp.status(),
        });
      }
    });

    await loginAsAdmin(page);
    await page.goto('/admin/pos-v4', { waitUntil: 'domcontentloaded' });

    // Let bootstrap → defaultAccess/show → branch-aware refetch sequence finish.
    // PosComponent dispatches itemList() twice: once on mount (guarded out for
    // admin@branch_id=0 by the new short-circuit), then again post
    // defaultAccess/show with the resolved branch_id. Give it 10 s for the
    // full chain to settle on a cold cache.
    await page.waitForTimeout(10_000);

    // Capture landing screenshot for visual evidence.
    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, '01-pos-landing-no-422.png'),
      fullPage: false,
    });

    // ASSERTION 1: zero 422 on /api/admin/item during landing window.
    const fourTwentyTwos = itemRequests.filter((r) => r.status === 422);
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, '01-pos-landing-no-422.network.json'),
      JSON.stringify(itemRequests, null, 2)
    );
    expect(
      fourTwentyTwos,
      `Expected zero /api/admin/item 422 responses on POS landing, got ${fourTwentyTwos.length}: ${JSON.stringify(fourTwentyTwos)}`
    ).toHaveLength(0);

    // ASSERTION 2: the catalog grid rendered with at least one tile (proves
    // the branch-aware refetch fired and populated `item/lists`).
    // Selector tolerant to V5 and legacy classes.
    const tileSelector = '[data-pos-item-id], .pos-v5-tile, .fk-pos-tile, .pos-tile';
    const tileCount = await page.locator(tileSelector).count();
    expect(
      tileCount,
      `Expected ≥1 POS catalog tile to be rendered after defaultAccess refetch, got ${tileCount}`
    ).toBeGreaterThan(0);

    // ASSERTION 3: no critical-path 422 toast leaked (would mean the
    // short-circuit failed and the interceptor caught a real 422).
    const toastLocator = page.locator('.Vue-Toastification__toast--error, [role="alert"]');
    const toastCount = await toastLocator.count();
    if (toastCount > 0) {
      const toastTexts = await toastLocator.allTextContents();
      expect(
        toastTexts.some((t) => /catalog|catalogue|fiscal|indisponible|unavailable/i.test(t)),
        `Expected no critical-path 4xx toast on landing, found: ${JSON.stringify(toastTexts)}`
      ).toBe(false);
    }
  });

  test('Forced 422 on /api/admin/item SURFACES a toast (defense-in-depth)', async ({ page, context }) => {
    // NEGATIVE-PATH sentinel: validates bootstrap.js critical-path 422 toast.
    // We intercept the FIRST /api/admin/item call after landing and force it
    // to 422 with a JSON body. The interceptor (resources/js/bootstrap.js
    // _CRITICAL_4XX_PATTERNS) must surface a toast with the
    // `error.catalog_unavailable` message (FR: "Catalogue produits
    // indisponible — actualisez la page.").
    let intercepted = false;
    await context.route('**/api/admin/item?**', (route) => {
      if (intercepted) return route.continue();
      intercepted = true;
      route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          status: false,
          message: 'POS catalog requires branch_id',
        }),
      });
    });

    await loginAsAdmin(page);
    await page.goto('/admin/pos-v4', { waitUntil: 'domcontentloaded' });

    // The branch-aware refetch fires AFTER defaultAccess/show resolves; that
    // path includes `branch_id` so the short-circuit doesn't kick in and the
    // route fulfill above will deliver the synthetic 422. The interceptor's
    // 3 s debounce gap means the toast appears in the first 6 s.
    const toast = page.locator('.Vue-Toastification__toast--error');
    await expect(
      toast.first(),
      'Expected critical-path 422 toast to appear after forced 422 on /api/admin/item'
    ).toBeVisible({ timeout: 15_000 });

    const toastText = await toast.first().textContent();
    expect(
      toastText,
      `Expected catalog_unavailable message, got: "${toastText}"`
    ).toMatch(/catalogue|catalog/i);

    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, '02-forced-422-toast.png'),
      fullPage: false,
    });
  });
});
