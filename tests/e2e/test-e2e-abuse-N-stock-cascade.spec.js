/**
 * ABUSE-E2E — Wave N: Auto-86 stock cascade + empty states (⭐ silent loss)
 * Spec: tests/e2e/test-e2e-abuse-N-stock-cascade.spec.js
 * Dir:  tests/e2e/__screenshots__/test-e2e-N/
 *
 * Mission (AUDIT_PLAN.md "Wave N", reports/test-e2e/abuse-e2e-2026-06-01/AUDIT_PLAN.md:168-171):
 *   URLs: /admin/stock/rupture, POST /admin/menu/availability/toggle (incl. stock_quantity=0).
 *   States: rupture dashboard · toggle OOS · cascade (kiosk hidden / POS grayed ≤2s WS) ·
 *           empty states (search no-match, empty category) · re-enable reverses · toggle-fail.
 *   KEY ASSERTION: item hidden on ALL surfaces ≤2s of toggle; empty states never crash;
 *                  re-enable reverses.
 *
 * ── MULTI-CONTEXT SETUP ──────────────────────────────────────────────────────
 *   browser.newContext() ×3 so each surface keeps its own auth/session cookie:
 *     - adminCtx  → /admin/stock/rupture (admin@lecayenne.fr, branch_id=0) — TOGGLES the item.
 *     - posCtx    → /admin/pos          (pos@lecayenne.fr,   branch_id=1)  — OBSERVES the tile.
 *     - kioskCtx  → /kiosk/categories   (kiosk-lecayenne machine token)    — OBSERVES the card.
 *   The toggle happens in the admin context; the SAME item id is then asserted
 *   gone/épuisé in the POS + kiosk contexts (real cross-surface cascade), then
 *   re-enabled and asserted reversed.
 *
 * ── REAL SELECTORS / ROUTES (grep 2026-06-02, file:line) ─────────────────────
 *   ADMIN dashboard (resources/js/components/admin/stock/StockRuptureDashboardComponent.vue):
 *     - root             [data-testid="stock-management-v2"]                          (:29)
 *     - rail buckets     [data-testid="stock-mgmt-bucket-${bucket.key}"]              (:90)
 *     - search box       [data-testid="stock-mgmt-search"]                            (:123)
 *     - empty pane       [data-testid="stock-mgmt-empty"]                             (:146)
 *     - product card     [data-testid="stock-mgmt-product-item-${id}"]  (key=`item-${id}` :335)
 *     - toggle button    [data-testid="stock-mgmt-toggle-item-${id}"]  role=switch aria-checked (:189,:199)
 *                        label = in_stock / out_of_stock ; .in-stock / .out-of-stock class (:194-195)
 *   ROUTE  POST /api/admin/menu/availability/toggle  → AvailabilityController@toggle
 *     - route        routes/api.php:275  (name admin.menu.availability.toggle)
 *     - throttle     throttle:menu-availability (routes/api.php:274)
 *     - body         { item_id, branch_id?, is_available, unavailable_reason? }
 *                    (AvailabilityToggleRequest; controller AvailabilityController.php:45-98)
 *     - admin (branch_id=0) toggle FANS OUT to every scoped branch incl. branch 1
 *                    (AvailabilityController.php:60, resolveScopedBranchIds :239-249)
 *     - emits ItemAvailabilityChanged on private-branch.{id} + outbox
 *                    (AvailabilityService.php:386 / :30 doc)
 *   KIOSK menu (resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue):
 *     - categories root  [data-testid="kiosk-categories-root"]                         (:2)
 *     - products pane    [data-testid="kiosk-categories-products"]                     (:129)
 *     - sidebar cat      [data-testid="kiosk-categories-sidebar-item-${cat.id}"]       (:107)
 *     - product card     [data-testid="kiosk-product-card-${product.id}"]              (:157)
 *       OOS → class kiosk-product-card--filtered-out + aria-disabled="true"            (:152,:155)
 *       (KioskCategoriesComponent.isProductUnavailable :450-457 keys off is_available=false)
 *     - empty category   [data-testid="kiosk-categories-empty"]                        (:78)
 *     NB: MenuProjectionService.php:154 KEEPS the OOS item in the payload with
 *         is_available=false (it does NOT drop it) → kiosk renders it filtered-out,
 *         not removed. Assertion accepts BOTH "gone" and "filtered-out/aria-disabled".
 *   POS grid (resources/js/components/admin/pos/ItemComponent.vue):
 *     - grid root        .pos-v5-grid                                                  (:10)
 *     - tile             button[data-pos-item-id="${id}"]                              (:20)
 *       OOS → :disabled + aria-disabled="true" (isCatalogTileUnavailable)             (:15-16)
 *           + overlay span.pos-item-86-badge.pos-v5-tile__overlay = $t('pos.item_86_d')(:31)
 *           + tile class 'is-unavailable' (tileClassList → :720)
 *     - live cascade UI  [data-testid="pos-availability-banner"] / "pos-availability-live"
 *                        (PosComponent.vue:59 / :49) — WS-driven 86 banner (observed, soft)
 *
 * ── CASCADE ASSERTION + WAIT ─────────────────────────────────────────────────
 *   waitForCascade() polls (re-reading the surface DOM, reload fallback after the
 *   WS/poll grace window) up to CASCADE_TIMEOUT_MS for the predicate to flip.
 *   The plan's "≤2s WS" is the IDEAL; this env has no Reverb/Soketi (mega-audit-snap
 *   filters the ws://...:6001 failed warning) so the product degrades to its 60s poll
 *   fallback — we therefore allow a generous timeout AND force a reload as the worst
 *   case, but the assertion itself is HARD (expect, no silent catch). Observed latency
 *   is recorded into defects[]/console for the reviewer. If the item never flips on a
 *   surface within the window the test FAILS (real cascade defect, not swallowed).
 *
 * ── CLEANUP (state restoration — mandatory, AUDIT_PLAN serial waves share DB) ──
 *   afterAll re-enables EVERY item this spec disabled, via a direct API toggle in
 *   the admin context (is_available=true), so a mid-test failure cannot leave an
 *   item ÉPUISÉ and poison the waves running behind us. Idempotent + best-effort.
 *
 * ── ASSERTION ARCHITECTURE (mirrors sibling abuse specs) ─────────────────────
 *   - SNAP BEFORE ASSERT — the 4-file quartet lands on disk regardless of outcome.
 *   - THROW only for spec-integrity (login fail, shell never mounted, no item found).
 *   - The cascade + reversal checks are HARD expects (NO silent catch).
 *   - Product defects (i18n leak, console error, 4xx) recorded loudly into defects[].
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsAdmin, loginAsPosOperator } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';
const SHOT_DIR = path.resolve(__dirname, '__screenshots__/test-e2e-N');

// Cascade grace window. Plan ideal = ≤2s (WS). This env has no broadcast server,
// so we allow the product's poll/reload fallback before failing the HARD assert.
const CASCADE_TIMEOUT_MS = 30_000;
const CASCADE_POLL_MS = 1_000;

/**
 * Toggle an item's branch availability via the real admin API, reusing the
 * admin page's in-browser axios (CSRF + X-API-KEY + bearer already wired).
 * Returns { ok, status, body }. Used both for the deliberate OOS toggle AND
 * for guaranteed cleanup re-enable in afterAll.
 *
 * POST /api/admin/menu/availability/toggle — routes/api.php:275.
 */
async function apiToggle(adminPage, itemId, isAvailable, reason = 'out_of_stock_manual') {
  return adminPage.evaluate(async ({ itemId, isAvailable, reason }) => {
    try {
      const resp = await window.axios.post('admin/menu/availability/toggle', {
        item_id: itemId,
        is_available: isAvailable,
        unavailable_reason: isAvailable ? null : reason,
      });
      return { ok: true, status: resp.status, body: resp.data };
    } catch (err) {
      return {
        ok: false,
        status: err?.response?.status ?? 0,
        body: err?.response?.data ?? { message: String(err?.message || err) },
      };
    }
  }, { itemId, isAvailable, reason });
}

/**
 * Poll a surface predicate up to CASCADE_TIMEOUT_MS, re-reading the live DOM
 * each tick and forcing a reload after the first half of the window (covers
 * the no-WS poll-fallback env). Returns { flipped, elapsedMs }. NO throw here —
 * the caller turns the boolean into a HARD expect so the failure is loud.
 *
 * @param {import('@playwright/test').Page} page
 * @param {() => Promise<boolean>} predicate resolves true once the cascade landed
 * @param {() => Promise<void>} [reload] re-navigate / re-fetch the surface
 */
async function waitForCascade(page, predicate, reload) {
  const start = Date.now();
  // A surface with no broadcast server (this env) only flips after a cold reload
  // re-fetches the menu projection — its natural in-page poll is ~60s and can't
  // land inside CASCADE_TIMEOUT_MS. So we REPEAT the forced reload on a short
  // interval (not once at mid-window): the surface flips on the first reload that
  // (a) the backend has reflected the toggle AND (b) the cold-boot has completed,
  // instead of being gated to the half-way mark. The assertion stays HARD.
  const RELOAD_EVERY_MS = 6_000;
  let lastReloadAt = 0; // first reload fires ~RELOAD_EVERY_MS in, after a fast-WS chance
  // immediate check (catches a fast WS push before any reload)
  if (await predicate().catch(() => false)) {
    return { flipped: true, elapsedMs: Date.now() - start };
  }
  while (Date.now() - start < CASCADE_TIMEOUT_MS) {
    await page.waitForTimeout(CASCADE_POLL_MS);
    if (await predicate().catch(() => false)) {
      return { flipped: true, elapsedMs: Date.now() - start };
    }
    // Re-fetch the surface on a short cadence to exercise the poll/reload fallback.
    if (reload && Date.now() - lastReloadAt >= RELOAD_EVERY_MS) {
      lastReloadAt = Date.now();
      await reload().catch(() => {});
      // Re-check immediately after the reload settles (don't wait a full poll tick).
      if (await predicate().catch(() => false)) {
        return { flipped: true, elapsedMs: Date.now() - start };
      }
    }
  }
  return { flipped: false, elapsedMs: Date.now() - start };
}

test.describe('ABUSE-N — Auto-86 stock cascade + empty states', () => {
  // Tracks every item this spec disables so afterAll can re-enable it even on failure.
  const disabledItemIds = new Set();
  let adminCtx; let posCtx; let kioskCtx;
  let adminPage; let posPage; let kioskPage;

  test.afterAll(async () => {
    // ── STATE RESTORATION (mandatory) ──────────────────────────────────────
    // Re-enable anything left OOS so serial waves behind us are not poisoned.
    if (adminPage && !adminPage.isClosed()) {
      for (const id of disabledItemIds) {
        // best-effort; log but never throw out of cleanup
        // eslint-disable-next-line no-await-in-loop
        const res = await apiToggle(adminPage, id, true).catch((e) => ({ ok: false, body: String(e) }));
        // eslint-disable-next-line no-console
        console.log(`[ABUSE-N cleanup] re-enable item ${id} →`, JSON.stringify(res.body || res));
      }
    }
    for (const ctx of [adminCtx, posCtx, kioskCtx]) {
      if (ctx) await ctx.close().catch(() => {});
    }
  });

  test('toggle OOS cascades to POS + kiosk ≤window, empty states hold, re-enable reverses', async ({ browser }) => {
    test.setTimeout(180_000);
    clearFoodKingRateLimits();

    const defects = [];
    const recordDefect = (msg) => {
      defects.push(msg);
      // eslint-disable-next-line no-console
      console.error(`[ABUSE-N DEFECT] ${msg}`);
    };

    // ── CONTEXT 1: ADMIN (toggler) ─────────────────────────────────────────
    adminCtx = await browser.newContext();
    adminPage = await adminCtx.newPage();
    const adminRec = attachMegaAuditRecorder(adminPage, SHOT_DIR);

    await loginAsAdmin(adminPage);
    await adminPage.goto(`${BASE}/admin/stock/rupture`, { waitUntil: 'domcontentloaded' });
    const dash = adminPage.locator('[data-testid="stock-management-v2"]');
    await expect(dash, 'stock-rupture dashboard shell must mount').toBeVisible({ timeout: 25_000 });
    // Let the catalog-overview fetch settle so product cards render.
    await adminPage.locator('[data-testid^="stock-mgmt-product-item-"]').first()
      .waitFor({ state: 'visible', timeout: 25_000 })
      .catch(() => recordDefect('no stock-mgmt product cards rendered on dashboard'));
    await adminRec.snap('01-rupture-dashboard');

    // Pick a REAL, currently in-stock item from the live DOM (no hardcoded id).
    // The toggle button is role=switch with aria-checked reflecting is_available.
    const inStockToggle = adminPage
      .locator('[data-testid^="stock-mgmt-toggle-item-"][aria-checked="true"]')
      .first();
    await expect(inStockToggle, 'need at least one in-stock item to toggle OOS').toBeVisible({ timeout: 20_000 });
    const toggleTestId = await inStockToggle.getAttribute('data-testid'); // stock-mgmt-toggle-item-<ID>
    const itemId = Number(String(toggleTestId).replace('stock-mgmt-toggle-item-', ''));
    expect(Number.isFinite(itemId) && itemId > 0, `parsed item id from ${toggleTestId}`).toBeTruthy();
    const itemName = (await adminPage
      .locator(`[data-testid="stock-mgmt-product-item-${itemId}"] .name`)
      .innerText().catch(() => '')).trim();
    // eslint-disable-next-line no-console
    console.log(`[ABUSE-N] target item id=${itemId} name="${itemName}"`);

    // ── CONTEXT 2: POS (observer) — open BEFORE toggle so we see it in-stock first.
    posCtx = await browser.newContext();
    posPage = await posCtx.newPage();
    const posRec = attachMegaAuditRecorder(posPage, SHOT_DIR);
    await loginAsPosOperator(posPage);
    await expect(posPage.locator('.pos-v5-grid').first(), 'POS grid must mount')
      .toBeVisible({ timeout: 25_000 });
    const posTile = () => posPage.locator(`.pos-v5-grid [data-pos-item-id="${itemId}"]`).first();
    // The POS first-page filters to a featured-category allowlist; bring every
    // item into view via the dedicated "Toutes" toggle. Stable selector =
    // [data-testid="pos-category-toggle-all"] (PosComponent.vue:461 — label flips
    // 'Toutes'→'Réduire' when expanded, so a name-regex is fragile). Idempotent
    // helper: only expands when the tile is still missing.
    const posShowAll = async () => {
      if (await posTile().count() > 0) return;
      const allBtn = posPage.locator('[data-testid="pos-category-toggle-all"]').first();
      if (await allBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
        const label = (await allBtn.innerText().catch(() => '')) || '';
        // Click only if still collapsed ("Toutes"); 'Réduire' means already expanded.
        if (/toutes/i.test(label)) {
          await allBtn.click().catch(() => {});
          await posPage.waitForTimeout(800);
        }
      }
    };
    await posShowAll();
    const posTilePresentBefore = await posTile().count() > 0;
    await posRec.snap('02-pos-grid-before');

    // ── CONTEXT 3: KIOSK (observer) — drive to the categories/menu surface.
    kioskCtx = await browser.newContext();
    kioskPage = await kioskCtx.newPage();
    const kioskRec = attachMegaAuditRecorder(kioskPage, SHOT_DIR);
    await kioskPage.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
    // Choose order type → categories (mirror abuse-A navigation, lines 125-136).
    // The SPA boots through KioskAppComponent: a `.kiosk-init-overlay` covers the
    // screen while branchLoading + the silent kiosk auto-login (requireKioskAuth
    // → kioskCart/kioskLogin) settle. Clicking takeaway before that overlay clears
    // gets the click intercepted (item never navigates → categories never mounts).
    await expect(kioskPage.locator('[data-testid="kiosk-idle-root"]'), 'kiosk idle must mount')
      .toBeVisible({ timeout: 20_000 });
    await kioskPage.locator('.kiosk-init-overlay')
      .waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {});
    const takeaway = kioskPage.locator('[data-testid="kiosk-order-type-takeaway"]');
    if (await takeaway.isVisible({ timeout: 8_000 }).catch(() => false)) {
      await takeaway.click();
    } else {
      await kioskPage.goto(`${BASE}/kiosk/categories`, { waitUntil: 'domcontentloaded' }).catch(() => {});
    }
    await kioskPage.waitForURL(/\/kiosk\/categories/, { timeout: 20_000 }).catch(() => {});
    await expect(kioskPage.locator('[data-testid="kiosk-categories-root"]'), 'kiosk menu must mount')
      .toBeVisible({ timeout: 20_000 });
    const kioskCard = () => kioskPage.locator(`[data-testid="kiosk-product-card-${itemId}"]`).first();
    // The kiosk groups by category; the target item may live under another
    // sidebar bucket. Walk every category once until the card appears. The
    // products pane re-renders async after a sidebar click, so we wait for the
    // grid to repopulate (≥1 card) before probing — a bare 400ms tick raced the
    // re-render and missed the card (round-2 → "kiosk cascade unverified").
    const anyCard = kioskPage.locator('[data-testid^="kiosk-product-card-"]');
    const ensureKioskCardVisible = async () => {
      // Let the initially-selected category's grid finish its first render.
      await anyCard.first().waitFor({ state: 'attached', timeout: 8_000 }).catch(() => {});
      if (await kioskCard().count() > 0) return true;
      const cats = kioskPage.locator('[data-testid^="kiosk-categories-sidebar-item-"]');
      const n = await cats.count().catch(() => 0);
      for (let i = 0; i < n; i += 1) {
        // eslint-disable-next-line no-await-in-loop
        await cats.nth(i).click().catch(() => {});
        // Wait for the products pane to re-render under the newly-picked category
        // (≥1 card attached) rather than a fixed sleep that races the transition.
        // eslint-disable-next-line no-await-in-loop
        await anyCard.first().waitFor({ state: 'attached', timeout: 5_000 }).catch(() => {});
        // eslint-disable-next-line no-await-in-loop
        await kioskPage.waitForTimeout(250);
        // eslint-disable-next-line no-await-in-loop
        if (await kioskCard().count() > 0) return true;
      }
      return false;
    };
    const kioskCardPresentBefore = await ensureKioskCardVisible();
    await kioskRec.snap('03-kiosk-menu-before');

    // ════════════════════════════════════════════════════════════════════════
    // STATE: TOGGLE OOS  (admin, the real mutation — assert HTTP 2xx)
    // ════════════════════════════════════════════════════════════════════════
    const togglePost = adminPage.waitForResponse(
      (res) => res.request().method() === 'POST'
        && /\/api\/admin\/menu\/availability\/toggle/i.test(res.url()),
      { timeout: 20_000 },
    ).catch(() => null);
    await inStockToggle.click();
    const toggleResp = await togglePost;
    if (!toggleResp) {
      throw new Error('toggle POST /api/admin/menu/availability/toggle was never observed');
    }
    expect(
      toggleResp.status(),
      `toggle OOS must succeed (got ${toggleResp.status()})`,
    ).toBeLessThan(300);
    disabledItemIds.add(itemId);
    // Dashboard self-state: the same toggle should now read out_of_stock.
    await expect(
      adminPage.locator(`[data-testid="stock-mgmt-toggle-item-${itemId}"]`),
      'dashboard toggle should reflect OOS (aria-checked=false)',
    ).toHaveAttribute('aria-checked', 'false', { timeout: 10_000 });
    await adminRec.snap('04-rupture-dashboard-after-toggle-oos');

    // ════════════════════════════════════════════════════════════════════════
    // CASCADE → POS  (HARD assert: tile gone OR épuisé/disabled within window)
    // ════════════════════════════════════════════════════════════════════════
    const posReload = async () => {
      await posPage.reload({ waitUntil: 'domcontentloaded' });
      await posPage.locator('.pos-v5-grid').first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
      await posShowAll();
    };
    const posOosPredicate = async () => {
      const tile = posTile();
      if (await tile.count() === 0) return true; // removed entirely = also "hidden"
      const disabled = await tile.isDisabled().catch(() => false);
      const ariaDisabled = (await tile.getAttribute('aria-disabled').catch(() => null)) === 'true';
      const cls = (await tile.getAttribute('class').catch(() => '')) || '';
      const overlay = await tile.locator('.pos-item-86-badge, .pos-v5-tile__overlay').count().catch(() => 0);
      return disabled || ariaDisabled || /is-unavailable/.test(cls) || overlay > 0;
    };
    const posCascade = await waitForCascade(posPage, posOosPredicate, posReload);
    await posRec.snap('05-pos-grid-after-oos');
    if (posTilePresentBefore) {
      expect(
        posCascade.flipped,
        `POS did not reflect item ${itemId} OOS within ${CASCADE_TIMEOUT_MS}ms (cascade latency=${posCascade.elapsedMs}ms)`,
      ).toBeTruthy();
      // eslint-disable-next-line no-console
      console.log(`[ABUSE-N] POS cascade latency ≈ ${posCascade.elapsedMs}ms (id=${itemId})`);
    } else {
      recordDefect(`POS tile for item ${itemId} not present before toggle — POS cascade unverified (first-page filter / category)`);
    }

    // ════════════════════════════════════════════════════════════════════════
    // CASCADE → KIOSK (HARD assert: card gone OR filtered-out/aria-disabled)
    // ════════════════════════════════════════════════════════════════════════
    const kioskReload = async () => {
      await kioskPage.reload({ waitUntil: 'domcontentloaded' });
      // SPA re-boots on a hard reload → let the init overlay + auto-login clear,
      // then wait for the catalogue shell before re-walking categories.
      await kioskPage.locator('.kiosk-init-overlay').waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {});
      await kioskPage.locator('[data-testid="kiosk-categories-root"]').waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
      await ensureKioskCardVisible();
    };
    const kioskOosPredicate = async () => {
      const card = kioskCard();
      if (await card.count() === 0) return true; // removed = hidden
      const ariaDisabled = (await card.getAttribute('aria-disabled').catch(() => null)) === 'true';
      const cls = (await card.getAttribute('class').catch(() => '')) || '';
      return ariaDisabled || /kiosk-product-card--filtered-out/.test(cls);
    };
    const kioskCascade = await waitForCascade(kioskPage, kioskOosPredicate, kioskReload);
    await kioskRec.snap('06-kiosk-menu-after-oos');
    if (kioskCardPresentBefore) {
      expect(
        kioskCascade.flipped,
        `Kiosk did not hide/disable item ${itemId} within ${CASCADE_TIMEOUT_MS}ms (cascade latency=${kioskCascade.elapsedMs}ms)`,
      ).toBeTruthy();
      // eslint-disable-next-line no-console
      console.log(`[ABUSE-N] Kiosk cascade latency ≈ ${kioskCascade.elapsedMs}ms (id=${itemId})`);
    } else {
      recordDefect(`Kiosk card for item ${itemId} not located before toggle — kiosk cascade unverified`);
    }

    // ════════════════════════════════════════════════════════════════════════
    // EMPTY STATES — never crash
    // ════════════════════════════════════════════════════════════════════════
    // (a) Dashboard search no-match → stock-mgmt-empty fallback (no blank/crash).
    const search = adminPage.locator('[data-testid="stock-mgmt-search"]');
    await search.fill('zzz-no-such-product-xyz-12345');
    const emptyPane = adminPage.locator('[data-testid="stock-mgmt-empty"]');
    await expect(emptyPane, 'dashboard empty-search fallback must render (no crash)')
      .toBeVisible({ timeout: 8_000 });
    const emptyText = (await emptyPane.innerText().catch(() => '')).trim();
    if (!emptyText || /^(admin\.|label\.|stock_mgmt)/i.test(emptyText)) {
      recordDefect(`empty-state shows raw/blank label: "${emptyText}"`);
    }
    await adminRec.snap('07-dashboard-empty-search');
    await search.fill(''); // restore

    // (b) Kiosk empty category — if any category bucket is genuinely empty the
    //     [data-testid="kiosk-categories-empty"] block must show, never a crash.
    const kioskEmpty = kioskPage.locator('[data-testid="kiosk-categories-empty"]');
    if (await kioskEmpty.isVisible({ timeout: 1_500 }).catch(() => false)) {
      await kioskRec.snap('08-kiosk-empty-category');
    }

    // ════════════════════════════════════════════════════════════════════════
    // RE-ENABLE → reverses cascade  (HARD assert reversal on both surfaces)
    // ════════════════════════════════════════════════════════════════════════
    const reTogglePost = adminPage.waitForResponse(
      (res) => res.request().method() === 'POST'
        && /\/api\/admin\/menu\/availability\/toggle/i.test(res.url()),
      { timeout: 20_000 },
    ).catch(() => null);
    await adminPage.locator(`[data-testid="stock-mgmt-toggle-item-${itemId}"]`).click();
    const reResp = await reTogglePost;
    expect(reResp && reResp.status() < 300, 're-enable toggle must succeed').toBeTruthy();
    await expect(
      adminPage.locator(`[data-testid="stock-mgmt-toggle-item-${itemId}"]`),
      'dashboard toggle should be back IN STOCK (aria-checked=true)',
    ).toHaveAttribute('aria-checked', 'true', { timeout: 10_000 });
    disabledItemIds.delete(itemId);
    await adminRec.snap('09-rupture-dashboard-re-enabled');

    // POS reversal: tile present AND no longer disabled/épuisé.
    if (posTilePresentBefore) {
      const posBackPredicate = async () => {
        const tile = posTile();
        if (await tile.count() === 0) return false;
        const disabled = await tile.isDisabled().catch(() => true);
        const cls = (await tile.getAttribute('class').catch(() => '')) || '';
        return !disabled && !/is-unavailable/.test(cls);
      };
      const posBack = await waitForCascade(posPage, posBackPredicate, posReload);
      await posRec.snap('10-pos-grid-re-enabled');
      expect(
        posBack.flipped,
        `POS did not RE-ENABLE item ${itemId} within ${CASCADE_TIMEOUT_MS}ms (reversal latency=${posBack.elapsedMs}ms)`,
      ).toBeTruthy();
    }

    // Kiosk reversal: card available again (not filtered-out / not aria-disabled).
    if (kioskCardPresentBefore) {
      const kioskBackPredicate = async () => {
        const card = kioskCard();
        if (await card.count() === 0) return false;
        const ariaDisabled = (await card.getAttribute('aria-disabled').catch(() => null)) === 'true';
        const cls = (await card.getAttribute('class').catch(() => '')) || '';
        return !ariaDisabled && !/kiosk-product-card--filtered-out/.test(cls);
      };
      const kioskBack = await waitForCascade(kioskPage, kioskBackPredicate, kioskReload);
      await kioskRec.snap('11-kiosk-menu-re-enabled');
      expect(
        kioskBack.flipped,
        `Kiosk did not RE-ENABLE item ${itemId} within ${CASCADE_TIMEOUT_MS}ms (reversal latency=${kioskBack.elapsedMs}ms)`,
      ).toBeTruthy();
    }

    // ── Surface defects loudly for the adversarial reviewer (non-fatal here). ──
    if (defects.length) {
      // eslint-disable-next-line no-console
      console.error(`[ABUSE-N] ${defects.length} non-fatal defect(s):\n - ${defects.join('\n - ')}`);
    }

    adminRec.dispose();
    posRec.dispose();
    kioskRec.dispose();
  });
});
