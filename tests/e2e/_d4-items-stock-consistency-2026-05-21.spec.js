/**
 * D4 — /admin/items ↔ /admin/stock/rupture per-branch availability parity
 * (MISSION FIX D4, owner override on Wave Y Round 1 C-013, 2026-05-21).
 *
 * Bug: /admin/items showed "Actif" (green) for Chicken Burger while
 * /admin/stock/rupture showed RUPTURE for the same item on branch 1. The
 * header card "INDISPONIBLES" tile reported 0.
 *
 * Root cause: ItemController::index never injected branch_id when the caller
 * was admin (branch_id=0), so ItemService::simpleList's
 * applyBranchAvailabilityOverlay short-circuited (branchId<1 guard) and the
 * listing reported the GLOBAL `items.is_available` flag only. Per-branch
 * overrides in item_branch_availability were ignored. The header tile
 * filtered the visible page (10 rows) instead of using a global count.
 *
 * Fix:
 *   - app/Http/Controllers/Admin/ItemController.php — admin caller without
 *     branch_id now resolves to the first active branch (mirror of
 *     StockRuptureDashboardController::scopedBranches() admin fallback).
 *     Adds availabilityCounts() to the response meta.
 *   - app/Services/ItemService.php — new availabilityCounts() method
 *     combining global is_available with per-branch overrides.
 *   - resources/js/components/admin/items/ItemListComponent.vue —
 *     RUPTURE pill in status cell when item.is_available === false;
 *     header counter reads pagination.meta.unavailable_count.
 *
 * Spec strategy:
 *   1. Seed: ensure Chicken Burger (id=38) has a row in
 *      item_branch_availability with is_available=false for branch 1.
 *   2. Login admin, visit /admin/stock/rupture, confirm Chicken Burger
 *      is rendered in the unavailable/rupture state.
 *   3. Visit /admin/items, confirm:
 *      - RUPTURE pill renders inside the Chicken Burger row (not "Actif")
 *      - header "INDISPONIBLES" tile > 0
 *      - response meta carries available_count + unavailable_count
 *   4. Capture screenshots of both surfaces for the owner.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = path.resolve(__dirname, '..', 'captures/d4-items-stock-consistency-2026-05-21');
if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

test.use({ viewport: { width: 1440, height: 1100 } });
test.setTimeout(180_000);

function tinker(php) {
    return execSync('php artisan tinker --no-interaction', {
        input: php + "\nexit;\n",
        encoding: 'utf-8',
        cwd: path.resolve(__dirname, '../..'),
    });
}

/**
 * Force Chicken Burger (id=38) to is_available=false on branch 1, mark the
 * reason so the cross-surface assertion can verify "non-empty reason" path.
 * Idempotent (updateOrCreate).
 */
function seedChickenRupture(branchId = 1) {
    const php = `
\\App\\Models\\ItemBranchAvailability::withoutGlobalScope(\\App\\Models\\Scopes\\BranchScope::class)
    ->updateOrCreate(
        ['item_id' => 38, 'branch_id' => ${branchId}],
        ['is_available' => false, 'unavailable_reason' => 'manual', 'unavailable_since' => now()]
    );
echo "seeded OK\\n";
`;
    return tinker(php);
}

test.describe('D4 — items ↔ stock-rupture per-branch availability parity', () => {

    test.beforeAll(() => {
        seedChickenRupture(1);
    });

    test('Chicken Burger RUPTURE on both /admin/items and /admin/stock/rupture', async ({ page }) => {
        await loginAsAdmin(page);

        // --------------------------------------------------------------
        // 1) /admin/stock/rupture — confirm Chicken Burger is RUPTURE
        // --------------------------------------------------------------
        // Use relative path (no BASE) so the SPA router handles navigation
        // through its in-memory router rather than triggering a hard reload
        // that may race with token persistence.
        await page.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });
        // SPA + dashboard catalog-overview API roundtrip (≤2.5s in practice).
        await page.waitForTimeout(3000);
        await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});

        // The dashboard groups items by category. Click "Burgers" to switch
        // the right-side panel to show Chicken Burger.
        const burgersCat = page.locator('text=/^Burgers$/i').first();
        await expect(burgersCat).toBeVisible({ timeout: 10_000 });
        await burgersCat.click();
        await page.waitForTimeout(500);

        const dashboardChickenRow = page
            .locator('text=Chicken Burger')
            .first();
        await expect(dashboardChickenRow).toBeVisible({ timeout: 15_000 });
        // Cross-surface assertion: dashboard payload must mark Chicken Burger
        // as out_of_stock for the cross-page parity claim to hold. The card
        // wrapping the product name + the EN STOCK/RUPTURE pill is a few
        // levels up in the DOM; walk up to the 4th ancestor div to capture
        // the full product tile (matches the visible card boundary).
        const dashboardChickenContainer = page
            .locator('text=Chicken Burger')
            .first()
            .locator('xpath=ancestor::*[self::div or self::li or self::article][4]');
        const dashboardText = (await dashboardChickenContainer.innerText().catch(() => '')) || '';
        expect(dashboardText.toUpperCase(), `dashboard tile text: ${dashboardText}`).toMatch(/RUPTURE|OUT OF STOCK|ÉPUISÉ|EPUISE/);

        await page.screenshot({
            path: path.join(OUT, '01-stock-rupture-dashboard.png'),
            fullPage: true,
        });

        // --------------------------------------------------------------
        // 2) /admin/items — listing must reflect the same RUPTURE state
        // --------------------------------------------------------------
        // Capture the items API response to introspect the meta payload.
        // Set up listener BEFORE navigation. The Vuex `item/lists` action hits
        // `/api/admin/item?...` with paginate=1; filter strictly on that
        // pattern so we don't pick up `/api/admin/item-category` or other
        // sibling endpoints.
        const itemsResponsePromise = page.waitForResponse(
            (r) => /\/api\/admin\/item\?/.test(r.url())
                && /paginate=1/.test(r.url())
                && r.request().method() === 'GET'
                && r.status() === 200,
            { timeout: 30_000 }
        );
        await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
        const initialItemsResponse = await itemsResponsePromise;
        const initialItemsBody = await initialItemsResponse.json();

        // The catalog has more items than fit on page 1 (default per_page=10
        // sorted by id desc). Chicken Burger (id=38) lands on a deeper page,
        // so we filter the listing by name to force it onto the visible page
        // — same workflow an admin would use to find it through the UI.
        const filteredPromise = page.waitForResponse(
            (r) => /\/api\/admin\/item\?/.test(r.url())
                && /name=Chicken/.test(r.url())
                && r.request().method() === 'GET'
                && r.status() === 200,
            { timeout: 20_000 }
        );
        // Open filter pane, fill the name input, submit search.
        // The page contains TWO #name inputs: the create-drawer form and the
        // filter pane — scope to #item-filter to avoid strict-mode collision.
        await page.locator('button:has-text("Filtrer"), button:has-text("Filter")').first().click();
        await page.waitForTimeout(400);
        await page.locator('#item-filter #name').fill('Chicken');
        await page.locator('#item-filter button:has-text("Recherche"), #item-filter button:has-text("Rechercher"), #item-filter button:has-text("Search")').first().click();
        const itemsResponse = await filteredPromise;
        const itemsBody = await itemsResponse.json();

        // ---- Backend meta assertions ----
        // The INITIAL (unfiltered) response carries the GLOBAL availability
        // counts that power the header tile — they must reflect the whole
        // catalogue, NOT just the visible page.
        expect(initialItemsBody.meta).toBeTruthy();
        expect(initialItemsBody.meta.available_count).toBeGreaterThanOrEqual(0);
        expect(initialItemsBody.meta.unavailable_count).toBeGreaterThanOrEqual(1);
        // The filtered response also carries the per-branch overlay so the
        // RUPTURE pill renders correctly when the row appears.
        expect(itemsBody.meta).toBeTruthy();

        // Chicken Burger (id=38) must surface with is_available=false in the payload.
        const chickenInPayload = (itemsBody.data || []).find((it) => Number(it.id) === 38);
        expect(chickenInPayload, 'Chicken Burger #38 must appear in /api/admin/item payload').toBeTruthy();
        expect(chickenInPayload.is_available).toBe(false);

        // ---- UI assertions ----
        // Wait for the row to actually render.
        const chickenRow = page.locator('[data-testid="admin-item-row-38"]');
        await expect(chickenRow).toBeVisible({ timeout: 15_000 });

        // The RUPTURE pill must be rendered INSIDE the Chicken Burger row.
        const ruptureInRow = chickenRow.locator('[data-testid="admin-item-rupture-pill"]');
        await expect(ruptureInRow).toBeVisible({ timeout: 10_000 });
        const ruptureText = (await ruptureInRow.textContent())?.trim();
        expect(ruptureText).toMatch(/RUPTURE/i);

        // The Chicken Burger row must NOT render the green "Actif" pill.
        const actifInRow = chickenRow.locator('text=Actif').first();
        const actifVisible = await actifInRow.isVisible({ timeout: 1_000 }).catch(() => false);
        expect(actifVisible, 'Chicken Burger row must NOT show "Actif" when RUPTURE').toBe(false);

        // ---- Header counter assertion ----
        // The "INDISPONIBLES" tile (catalog-control-plane__metric--alert) must
        // surface a value >= 1 — at minimum Chicken Burger is ruptured.
        const indisponiblesTile = page.locator('.catalog-control-plane__metric--alert');
        await expect(indisponiblesTile).toBeVisible({ timeout: 5_000 });
        const indisponiblesCountText = (await indisponiblesTile.locator('span').first().textContent())?.trim();
        const indisponiblesCount = parseInt(indisponiblesCountText || '0', 10);
        expect(indisponiblesCount, 'INDISPONIBLES tile must reflect global rupture count').toBeGreaterThanOrEqual(1);

        await page.screenshot({
            path: path.join(OUT, '02-admin-items-with-rupture-pill.png'),
            fullPage: true,
        });

        // Also screenshot the row in isolation.
        await chickenRow.screenshot({
            path: path.join(OUT, '03-chicken-burger-row-rupture.png'),
        });
    });
});
