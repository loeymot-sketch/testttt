// FoodKing E2E — GOAL_MGMT_TESTPLAN Wave B / task HIST-11
// Historique unified table — visual integrity + origin filter behaviour.
//
// Surface under test: /admin/historique (HistoriqueListComponent.vue), ONE
// unified table listing every order regardless of origin (Borne / Caisse /
// En ligne / Livraison). The page is the V1 Le Cayenne "single source of
// truth" order log, so its origin column must render HUMAN labels, never raw
// `source_surface` values (kiosk / pos / web) and never raw i18n keys
// (label.kiosk …).
//
// DUAL GUARD (per HIST-11 mandate):
//   - A blank/shell table that "passes" because it has zero rows AND zero
//     empty-state message = FAIL. We assert the component actually MOUNTED
//     (title + header) BEFORE branching rows-vs-empty.
//   - A raw label / raw i18n key / NaN / undefined visible in any cell =
//     REAL product finding → the test FAILS loudly with the offending text.
//   - A wrong selector that finds nothing = test RED to be fixed, not a pass.
//
// Origin-filter reality (grounded in HistoriqueListComponent.vue): the origin
// control is a vue-select that ONLY sets props.origin. The query fires in
// search() → list(), bound to the orange "Rechercher" button. So exercising
// the filter REQUIRES: open filter panel → pick "En ligne" → click Search →
// assert the listing endpoint responds 200 JSON (not the SPA HTML catchall).

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const ADMIN_EMAIL = 'admin@lecayenne.fr';
const ADMIN_PASSWORD = '123456';
const SCREENSHOT_DIR = path.join(__dirname, '__screenshots__', 'hist-unified');

// Human-readable origin labels (resources/js/languages/fr.json):
//   label.kiosk="Borne", label.caisse="Caisse",
//   label.online="En ligne", label.delivery="Livraison".
const ALLOWED_ORIGIN_LABELS = ['Borne', 'Caisse', 'En ligne', 'Livraison'];
// Raw source_surface values that must NEVER leak into the origin column.
const RAW_SURFACE_VALUES = ['kiosk', 'pos', 'web', 'app', 'mobile', 'online', 'delivery'];
// Raw i18n key shape, e.g. "label.kiosk", "menu.historique".
const RAW_KEY_RE = /^[a-z]+(\.[a-z_]+){1,4}$/;
const EMPTY_STATE_TEXT = 'Aucune donnée disponible.';
const LISTING_ENDPOINT_RE = /\/api\/admin\/order-history(\?|$)/;

if (!fs.existsSync(SCREENSHOT_DIR)) {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

async function loginAsAdmin(page) {
  await page.goto('/login');
  // Login form fields — the deployed app renders in French ("Email" /
  // "Mot de passe" / submit "Connexion"). Fill by visible text input + the
  // password input, then submit via the locale-tolerant button matcher.
  await page.locator('input[type="text"], input[type="email"]').first().fill(ADMIN_EMAIL);
  await page.locator('input[type="password"]').fill(ADMIN_PASSWORD);
  // Submit button is "Connexion" (FR) / "Login" (EN) — match either, and
  // fall back to a form submit button if the accessible name differs.
  const submit = page.getByRole('button', { name: /^(connexion|login|se connecter)$/i });
  if ((await submit.count()) > 0) {
    await submit.first().click();
  } else {
    await page.locator('button[type="submit"], form button').last().click();
  }
  await page.waitForURL(/\/admin\//, { timeout: 25_000 });
}

// The Origine filter is a vue3-select-component. Grounded against the live DOM
// (probe 2026-06-03): the control's `id="searchOrigin"` does NOT survive to a
// queryable `#searchOrigin` element — instead it renders
//   <label for="searchOrigin">Origine</label>
//   <div class="vue-select ..."><div class="vue-select-header">…</div>
//     <ul class="vue-dropdown" role="listbox">
//       <li class="vue-dropdown-item" role="option"><span>Borne</span></li> … </ul></div>
// The <li role="option"> nodes are ALWAYS in the DOM (not portaled, not v-if'd),
// so we anchor on the stable <label for="searchOrigin"> and scope the option
// click INSIDE that field group to avoid colliding with the row badges or the
// Statut/Paiement selects.
function originFieldGroup(page) {
  // The per-field wrapper is the DIRECT parent of <label for="searchOrigin">
  // (a col wrapper that holds exactly that label + its vue-select). Using the
  // label's xpath parent avoids matching the outer card-level div.col-12 that
  // contains all three selects (Origine / Statut / Paiement).
  return page.locator('label[for="searchOrigin"]').locator('xpath=..');
}

async function pickOrigin(page, optionLabel) {
  const group = originFieldGroup(page);
  const control = group.locator('.vue-select');
  await expect(control, 'origin vue-select control must render in the filter panel').toBeVisible({ timeout: 10_000 });
  // Open the dropdown via its header (the readonly input / arrow).
  await group.locator('.vue-select-header').click();
  await page.waitForTimeout(200);

  const option = group.locator('li.vue-dropdown-item[role="option"]', { hasText: optionLabel });
  if ((await option.count()) === 0) {
    return false;
  }
  await option.first().click();
  return true;
}

test.describe('HIST-11 — Historique unified table visual integrity + origin filter', () => {
  // Cap the per-test budget well below the repo-wide 600s default so a real
  // failure (or server contention) surfaces in bounded time instead of a
  // multi-minute blackout.
  test.describe.configure({ timeout: 90_000 });

  test('table renders with human origin labels (no raw values/keys/NaN) and the origin filter responds', async ({ page }) => {
    /** @type {string[]} */
    const consoleErrors = [];
    page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });

    await loginAsAdmin(page);
    await page.goto('/admin/historique', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
    await page.waitForTimeout(1_500);

    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '01-historique-default.png'), fullPage: true });

    // ---- DUAL GUARD step 1: prove the component MOUNTED (not a blank shell) ----
    await expect(
      page.getByRole('heading', { name: 'Historique' }),
      'page title "Historique" must render — proves the component mounted',
    ).toBeVisible({ timeout: 10_000 });
    const originHeader = page.locator('th.db-table-head-th', { hasText: 'Origine' });
    await expect(originHeader, 'unified table header "Origine" must render').toBeVisible();

    // Columns aligned: a fixed set of head cells must be present.
    const headerCount = await page.locator('th.db-table-head-th').count();
    expect(headerCount, 'unified table must render its column headers').toBeGreaterThanOrEqual(8);

    // Filters visible: open the collapsible filter panel and confirm controls.
    await page.locator('button.table-filter-btn').first().click();
    await page.waitForTimeout(300);
    await expect(
      page.locator('label[for="searchOrigin"]'),
      'origin filter ("Origine") label visible after opening panel',
    ).toBeVisible({ timeout: 10_000 });
    await expect(
      originFieldGroup(page).locator('.vue-select'),
      'origin vue-select control visible after opening panel',
    ).toBeVisible();
    await expect(page.locator('#order_id'), 'order_id search field visible').toBeVisible();
    await expect(
      page.getByRole('button', { name: 'Rechercher' }),
      'Search button visible',
    ).toBeVisible();

    // ---- DUAL GUARD step 2: rows present OR honest empty-state ----
    const rowCount = await page.locator('tbody.db-table-body tr.db-table-body-tr').count();
    const badges = page.locator('.hist-origin-badge');
    const badgeCount = await badges.count();

    if (badgeCount > 0) {
      // We have real data rows. Every origin badge must be a human label.
      const badgeTexts = (await badges.allInnerTexts()).map((t) => t.trim()).filter(Boolean);
      expect(badgeTexts.length, 'origin badges must have non-empty text').toBeGreaterThan(0);

      for (const label of badgeTexts) {
        // Positive allowlist — the assertion that actually catches a raw leak.
        expect(
          ALLOWED_ORIGIN_LABELS,
          `origin badge "${label}" is NOT a human label — raw value or untranslated key leaked into the Origine column`,
        ).toContain(label);
        // Defence in depth: explicitly reject raw surface values + raw i18n keys.
        expect(RAW_SURFACE_VALUES, `raw source_surface "${label}" leaked into the Origine column`).not.toContain(label.toLowerCase());
        expect(RAW_KEY_RE.test(label), `raw i18n key "${label}" leaked into the Origine column`).toBeFalsy();
      }
    } else {
      // No rows → must be the HONEST empty state, never a silent blank table.
      await expect(
        page.getByText(EMPTY_STATE_TEXT, { exact: false }),
        'with zero rows the honest empty-state message must show (blank table = FAIL)',
      ).toBeVisible();
      expect(rowCount, 'empty-state should have at most the single placeholder row').toBeLessThanOrEqual(1);
    }

    // ---- No NaN / undefined / raw key anywhere in the visible table ----
    const tableText = (await page.locator('.db-table-responsive').innerText()).replace(/\s+/g, ' ');
    expect(tableText, 'table must not render "NaN"').not.toMatch(/\bNaN\b/);
    expect(tableText, 'table must not render literal "undefined"').not.toMatch(/\bundefined\b/);
    expect(tableText, 'table must not render "null"').not.toMatch(/\bnull\b/);
    expect(tableText, 'table must not render "0undefined"-style price corruption').not.toMatch(/0undefined/);
    // No raw i18n key token (word.word.word) sitting in the visible cells.
    const rawKeyTokens = (tableText.match(/\b[a-z]+(?:\.[a-z_]+){1,4}\b/g) || [])
      .filter((tok) => !/\.(png|svg|jpg|jpeg|ico|webp|fr|com|net)$/i.test(tok));
    expect(rawKeyTokens, `raw i18n key tokens visible in table: ${rawKeyTokens.join(', ')}`).toHaveLength(0);

    // ---- Exercise the origin filter: pick "En ligne" → Search → assert response ----
    const picked = await pickOrigin(page, 'En ligne');
    expect(picked, 'must be able to select the "En ligne" origin option in the vue-select').toBeTruthy();

    const responsePromise = page.waitForResponse(
      (r) => LISTING_ENDPOINT_RE.test(r.url()),
      { timeout: 15_000 },
    );
    await page.getByRole('button', { name: 'Rechercher' }).click();
    const resp = await responsePromise;

    // The listing endpoint must answer 200 JSON — NOT the SPA HTML catchall
    // (the silent-200-HTML failure mode that once hid a bug for 25 days).
    expect(resp.status(), 'origin-filtered listing request must return HTTP 200').toBe(200);
    expect(
      resp.headers()['content-type'] || '',
      'filtered listing must return JSON, not the SPA HTML catchall',
    ).toMatch(/application\/json/i);

    await page.waitForTimeout(1_000);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '02-historique-filter-enligne.png'), fullPage: true });

    // Table must still be coherent after the filter (rows OR honest empty-state),
    // and any remaining origin badges still human labels. (We do NOT assert all
    // badges == "En ligne": a web-surface delivery badges as Livraison.)
    const postRowCount = await page.locator('tbody.db-table-body tr.db-table-body-tr').count();
    const postBadges = page.locator('.hist-origin-badge');
    const postBadgeCount = await postBadges.count();
    if (postBadgeCount > 0) {
      for (const label of (await postBadges.allInnerTexts()).map((t) => t.trim()).filter(Boolean)) {
        expect(ALLOWED_ORIGIN_LABELS, `post-filter origin badge "${label}" is not a human label`).toContain(label);
      }
    } else {
      await expect(
        page.getByText(EMPTY_STATE_TEXT, { exact: false }),
        'post-filter zero-rows must show the honest empty state, not a blank table',
      ).toBeVisible();
    }
    expect(postRowCount, 'filtered table must not crash into a negative/garbage row count').toBeGreaterThanOrEqual(0);

    // No fatal console errors over the whole flow (ignore infra noise).
    const fatals = consoleErrors.filter(
      (e) => !/(favicon|net::ERR|Service Worker|404 .*\.(png|svg|ico|jpg|woff)|Failed to load resource:|Pusher|Echo|WebSocket)/i.test(e),
    );
    expect(fatals, `unexpected console errors:\n${fatals.slice(0, 5).join('\n')}`).toHaveLength(0);
  });
});
