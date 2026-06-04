// FoodKing E2E — VISUAL confirmation of 5 owner fixes (2026-06-04)
// ============================================================================
// Captures + visually verifies the 5 owner-requested fixes shipped in commits
// e6d77b575 / 60215b592 / 9e13e6c9d / bc900631b:
//   1. POS all categories in a single horizontal strip (P1)  -> 01-pos-categories.png
//   2. POS product tiles show ingredient/description line (P2) -> 02-pos-descriptions.png
//   3. "Menu (Frites + Boisson)" tile shows real combo photo (P5) -> 03-menu-photo.png
//   4. Order detail walk-in -> "Passager", no fake email/phone (P4) -> 04-passager.png
//   5. KDS history drawer shows FULL composition + time placed (P6) -> 05-kds-history.png
//
// Login: admin@lecayenne.fr / 123456 (branch_id=0 -> sees all categories, all
// branches' orders, can reach /kds + order detail + the nudged branch-1 order).
//
// NOTE (#5 test-data nudge, disclosed): today (2026-06-04) had ZERO orders with
// updated_at in the historyToday() window, so the drawer would render its empty
// state. Order #4118 (real walk-in, branch 1, queue A0008, instruction
// "MENU (FRITES + BOISSON)") had its `updated_at` bumped into today's window via
// a raw DB::table query-builder update (NO Eloquent save -> no observers /
// broadcast / audit hooks; updated_at is NOT part of the NF525 HMAC chain). No
// source / fiscal change. This only exercises the drawer with real composition.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

test.describe.configure({ timeout: 90_000 });

const SHOT_DIR = path.resolve(__dirname, '__screenshots__/owner-fixes');
const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '123456';
// Real walk-in order #4118 (instruction "MENU (FRITES + BOISSON)"), nudged into
// today's KDS-history window by the orchestrator before this spec runs.
const WALKIN_ORDER_ID = process.env.E2E_WALKIN_ORDER_ID || '4118';

function shot(page, name) {
  return page.screenshot({ path: path.join(SHOT_DIR, name), fullPage: false });
}

function fail(visibleText) {
  // DUAL GUARD: blank/error page OR raw i18n key literal = hard FAIL.
  expect(visibleText, 'page must not be a Laravel/server error page').not.toMatch(
    /Whoops|Fatal error|Server Error|Page Not Found|404|419|Sorry, the page/i,
  );
  // Raw i18n keys that would mean the translation never resolved.
  expect(visibleText, 'no raw i18n key may leak').not.toMatch(
    /kds_history_placed_at|kds_history_completed_at|label\.kds_history|kiosk\.[a-z]|undefinedundefined/,
  );
}

test.describe('Owner fixes 2026-06-04 — visual confirmation', () => {
  test.beforeAll(() => {
    fs.mkdirSync(SHOT_DIR, { recursive: true });
  });

  // ------------------------------------------------------------------
  // #1 + #2 + #3 share the POS surface — capture them in one logged-in pass.
  // ------------------------------------------------------------------
  test('POS: all categories (P1) + tile descriptions (P2) + menu combo photo (P5)', async ({ page }) => {
    await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 25_000 });
    // Wait for the POS V5 grid + category strip to mount.
    await page.waitForTimeout(3_000);

    const bodyText = await page.locator('body').innerText();
    fail(bodyText);

    // ---- #1 CATEGORIES -------------------------------------------------
    // Category chips/buttons in the horizontal strip. PosComponent renders a
    // scrollable strip of category buttons (each with a photo). Count the
    // distinct category labels rendered.
    const catData = await page.evaluate(() => {
      // The strip is a horizontal list of category buttons. Collect all
      // candidate category elements by common class fragments + role.
      const sel = [
        '.pos-v5-cat', '.pos-v5-category', '.pos-category', '[data-category-id]',
        '.pos-v5-cats button', '.pos-v5-cat-strip button', '.pos-cat-strip button',
      ];
      let nodes = [];
      for (const s of sel) {
        const found = Array.from(document.querySelectorAll(s));
        if (found.length > nodes.length) nodes = found;
      }
      // Fallback: any horizontal-scroll container whose children look like cat chips.
      const labels = nodes
        .map((n) => (n.innerText || n.textContent || '').trim())
        .filter((t) => t.length > 0 && t.length < 40);
      const withImg = nodes.filter((n) => n.querySelector('img') || /background-image/.test(n.getAttribute('style') || '')).length;
      // Detect the legacy "Toutes / +" toggle button (the thing that should be GONE).
      const allText = document.body.innerText || '';
      const hasToggle = /\+\s*Toutes|Toutes\s*\/\s*\+|afficher toutes les catégories|voir tout/i.test(allText);
      return { count: nodes.length, labels: labels.slice(0, 20), withImg, hasToggle };
    });

    await shot(page, '01-pos-categories.png');
    // Persist the raw detection for the report.
    fs.writeFileSync(path.join(SHOT_DIR, '_cat-detect.json'), JSON.stringify(catData, null, 2));

    // ---- #2 DESCRIPTIONS ----------------------------------------------
    // Ensure the product grid has actually rendered tiles before detecting
    // descriptions (on cold "Toutes" load the grid may hydrate slightly late).
    await page.locator('.pos-v5-tile').first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
    await page.waitForTimeout(1_000);
    const descData = await page.evaluate(() => {
      const tiles = Array.from(document.querySelectorAll('.pos-v5-tile, .pos-item-tile'));
      const descs = Array.from(document.querySelectorAll('.pos-v5-tile__desc'));
      const descTexts = descs.map((d) => (d.innerText || '').trim()).filter(Boolean);
      // Tiles missing a description (e.g. the 3 upsell placeholders) — list their names.
      const noDesc = tiles
        .filter((t) => !t.querySelector('.pos-v5-tile__desc'))
        .map((t) => (t.querySelector('.pos-v5-tile__name')?.innerText || '').trim())
        .filter(Boolean);
      return {
        tileCount: tiles.length,
        descCount: descs.length,
        sampleDescs: descTexts.slice(0, 6),
        tilesWithoutDesc: noDesc.slice(0, 12),
      };
    });
    await shot(page, '02-pos-descriptions.png');
    fs.writeFileSync(path.join(SHOT_DIR, '_desc-detect.json'), JSON.stringify(descData, null, 2));

    // ---- #3 MENU COMBO PHOTO ------------------------------------------
    // Search for the Menu (Frites + Boisson) item via the POS search box.
    const searchBox = page.locator(
      'input[placeholder*="Rechercher un article" i], input[placeholder*="Rechercher" i], input[type="search"]',
    ).first();
    if (await searchBox.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await searchBox.fill('Menu');
      await page.waitForTimeout(1_500);
    }
    const menuPhoto = await page.evaluate(() => {
      const tiles = Array.from(document.querySelectorAll('.pos-v5-tile, .pos-item-tile'));
      const match = tiles.find((t) => /menu\s*\(?\s*frites/i.test((t.querySelector('.pos-v5-tile__name')?.innerText || '')));
      if (!match) return { found: false };
      const img = match.querySelector('img');
      const name = (match.querySelector('.pos-v5-tile__name')?.innerText || '').trim();
      const src = img ? (img.currentSrc || img.src || '') : '';
      const natW = img ? img.naturalWidth : 0;
      const natH = img ? img.naturalHeight : 0;
      return {
        found: true,
        name,
        src,
        naturalWidth: natW,
        naturalHeight: natH,
        isMenuPhoto: /menu-frites-boisson/i.test(src),
        isDefaultPlaceholder: /item-default|placeholder|no-image|default\.svg/i.test(src),
        rendered: natW > 0 && natH > 0,
      };
    });
    await shot(page, '03-menu-photo.png');
    fs.writeFileSync(path.join(SHOT_DIR, '_menu-detect.json'), JSON.stringify(menuPhoto, null, 2));

    // ASSERTIONS (#1/#2/#3) — keep soft enough to capture, hard enough to flag a real miss.
    expect(catData.count, `category strip should show MORE than old 8 (got ${catData.count})`).toBeGreaterThan(8);
    expect(descData.descCount, 'at least some POS tiles must render a description line').toBeGreaterThan(0);
    expect(menuPhoto.found, 'Menu (Frites + Boisson) tile must be found via search').toBeTruthy();
  });

  // ------------------------------------------------------------------
  // #4 — Walk-in order detail shows "Passager", no fake email/phone.
  // ------------------------------------------------------------------
  test('Order detail (P4): walk-in shows "Passager" with no fake contact', async ({ page }) => {
    await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto(`/admin/pos-orders/show/${WALKIN_ORDER_ID}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);

    const bodyText = await page.locator('body').innerText();
    fail(bodyText);

    const passagerData = await page.evaluate(() => {
      const txt = document.body.innerText || '';
      return {
        hasPassager: /Passager/i.test(txt),
        hasFakeEmail: /walkingcustomer@example\.com/i.test(txt),
        hasFakePhone: /\+?330?600000001|0600000001/.test(txt),
      };
    });
    await shot(page, '04-passager.png');
    fs.writeFileSync(path.join(SHOT_DIR, '_passager-detect.json'), JSON.stringify(passagerData, null, 2));

    expect(passagerData.hasPassager, 'customer block must read "Passager"').toBeTruthy();
    expect(passagerData.hasFakeEmail, 'fake walk-in email must NOT appear').toBeFalsy();
    expect(passagerData.hasFakePhone, 'fake walk-in phone must NOT appear').toBeFalsy();
  });

  // ------------------------------------------------------------------
  // #5 — KDS history drawer: full composition + time placed.
  // ------------------------------------------------------------------
  test('KDS history (P6): drawer shows full composition + "Passée à"/"Terminée à"', async ({ page }) => {
    await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/(kds|admin\/kitchen-display-system)/, { timeout: 25_000 });
    await page.waitForTimeout(3_000);

    // Open the history drawer.
    const histBtn = page.locator('[data-testid="kds-history-button"]').first();
    await expect(histBtn, 'KDS history button must exist').toBeVisible({ timeout: 15_000 });
    await histBtn.click();
    await page.waitForTimeout(2_500);

    const drawer = page.locator('[data-testid="kds-history-drawer"]').first();
    await expect(drawer, 'history drawer must open').toBeVisible({ timeout: 10_000 });

    const bodyText = await page.locator('body').innerText();
    fail(bodyText);

    const kdsData = await page.evaluate(() => {
      const items = Array.from(document.querySelectorAll('[data-testid="kds-history-item"]'));
      const drawerEl = document.querySelector('[data-testid="kds-history-drawer"]');
      const drawerText = drawerEl ? (drawerEl.innerText || '') : '';
      const emptyEl = document.querySelector('[data-testid="kds-history-empty"]');
      const isEmpty = emptyEl && emptyEl.offsetParent !== null;
      // Composition lines come from <KdsOrderLine> inside .kds-history-drawer__items.
      const compLines = Array.from(document.querySelectorAll('.kds-history-drawer__items .kds-line, .kds-history-drawer__item-block')).length;
      const firstItemText = items.length ? (items[0].innerText || '').trim() : '';
      return {
        itemCount: items.length,
        isEmpty: !!isEmpty,
        // Labels are uppercased by CSS (text-transform) -> match case-insensitively.
        hasPlacedLabel: /Pass[ée]e?\s*à/i.test(drawerText),
        hasCompletedLabel: /Termin[ée]e?\s*à/i.test(drawerText),
        // The nudged order #4118 carries instruction "MENU (FRITES + BOISSON)".
        hasComposition: /MENU\s*\(?\s*FRITES|FRITES\s*\+\s*BOISSON|Frites|Boisson/i.test(drawerText),
        hasTimeValue: /\b\d{1,2}[:hH]\d{2}\b/.test(drawerText),
        firstItemPreview: firstItemText.slice(0, 280),
      };
    });
    await shot(page, '05-kds-history.png');
    fs.writeFileSync(path.join(SHOT_DIR, '_kds-detect.json'), JSON.stringify(kdsData, null, 2));

    expect(kdsData.isEmpty, 'history drawer should NOT be empty (order #4118 nudged into window)').toBeFalsy();
    expect(kdsData.itemCount, 'at least one history order must render').toBeGreaterThan(0);
    expect(kdsData.hasPlacedLabel, '"Passée à" label must render (not raw key)').toBeTruthy();
    expect(kdsData.hasCompletedLabel, '"Terminée à" label must render (not raw key)').toBeTruthy();
    expect(kdsData.hasComposition, 'full composition (instruction line) must render, not bare name').toBeTruthy();
  });
});
