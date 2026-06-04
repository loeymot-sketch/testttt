// FoodKing E2E — GOAL_MGMT_TESTPLAN Wave B / task DASH-T11
// "V1-hidden modules absent from sidebar"
//
// Asserts that modules intentionally hidden in V1 (resources/js/config/
// v1-hidden-modules.js) do NOT ship as dead/forbidden nav entries in the
// rendered admin sidebar (.db-sidebar-nav).
//
// GROUNDING (read before authoring):
//   - resources/js/config/v1-hidden-modules.js
//       V1_HIDDEN_MENU_MODULES  (32 keys incl. 9 BackendMenu + 23 settings.*)
//       V1_HIDDEN_BACKEND_MENU_URLS = ['items']  (legacy parent-row suppression)
//   - resources/js/components/layouts/backend/BackendMenuComponent.vue
//       * Sidebar links render href = `/admin/<menu.url>` (parent + children)
//         and label = $t('menu.' + menu.language).
//       * HIDDEN_KEY_TO_MENU_URL maps the 9 non-settings hidden keys to their
//         seeder `menu.url` (e.g. creditBalanceReport -> credit-balance-report).
//       * The 23 settings.* hidden keys are a DIFFERENT surface, handled by
//         admin/settings/MenuComponent.vue — NOT BackendMenuComponent. They are
//         intentionally OUT OF SCOPE here. (Proof they must be excluded:
//         `settings.item-attributes` is hidden, yet VIRTUAL_CHILDREN_BY_URL
//         deliberately re-adds `settings/item-attributes/list` as a Catalogue
//         child — a naive settings.* assertion would fire a FALSE finding.)
//       * `items` (V1_HIDDEN_BACKEND_MENU_URLS) suppresses ONLY the legacy parent
//         nav row; the virtual child `items/studio` ("Catalogue") legitimately
//         stays. So we assert the exact `/admin/items` parent link is absent,
//         NOT that "items" never appears.
//
// DUAL GUARD:
//   - A hidden module ACTUALLY in the sidebar = REAL product finding (test FAILs,
//     do not fix product — document).
//   - Vacuous pass on an empty/half-rendered sidebar = FAIL. The sidebar's
//     dashboard/pos + V1_PRIMARY rows are HARDCODED (independent of authMenu),
//     so "non-empty" alone is NOT a valid positive control: if authMenu fails to
//     load, the hidden modules are absent purely because the DB-driven pipeline
//     never ran. We therefore wait for + assert a DB-SOURCED, survives-filtering
//     entry ("Administrateurs") — proving the very pipeline that would surface a
//     leak actually executed.
//
// EXACT-MATCH ONLY (never substring) to avoid known collisions:
//   - `items` (hidden parent) vs `items/studio` (visible child)
//   - `delivery-boys` (hidden) vs `delivery-boy-cash-sessions` (visible V1_PRIMARY)
//   - label "Livreurs" (delivery_boys) vs "Caisse livreur" (delivery_cash_sessions)

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.describe.configure({ timeout: 90_000 });

const ADMIN_EMAIL = 'admin@lecayenne.fr';
const ADMIN_PASSWORD = '123456';
const SCREENSHOT_DIR = path.join(__dirname, '__screenshots__', 'dash-hidden');

if (!fs.existsSync(SCREENSHOT_DIR)) {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

// The 9 V1-hidden modules handled by BackendMenuComponent.vue, with the EXACT
// seeder `menu.url` (HIDDEN_KEY_TO_MENU_URL) + the resolved FR `menu.<language>`
// label. Grounded against MenuTableSeeder.php + languages/fr.json.
const HIDDEN_BACKEND_MODULES = [
  { key: 'customers',           url: 'customers',             label: 'Clients' },
  { key: 'coupons',             url: 'coupons',               label: 'Coupons' },
  { key: 'offers',              url: 'offers',                label: 'Offres' },
  { key: 'creditBalanceReport', url: 'credit-balance-report', label: 'Rapport solde crédit' },
  { key: 'deliveryBoys',        url: 'delivery-boys',         label: 'Livreurs' },
  { key: 'onlineOrders',        url: 'online-orders',         label: 'Commandes en ligne' },
  { key: 'tableOrders',         url: 'table-orders',          label: 'Commandes table' },
  { key: 'waiters',             url: 'waiters',               label: 'Serveurs' },
  { key: 'diningTables',        url: 'dining-tables',         label: 'Tables' },
];

// Legacy parent-row suppressed by V1_HIDDEN_BACKEND_MENU_URLS. Exact href only —
// `items/studio` (Catalogue) is allowed to remain.
const HIDDEN_PARENT_ROW = { url: 'items', label: 'Articles' };

// DB-sourced positive control: comes from authMenu (NOT a hardcoded sidebar
// block), survives V1 + permission filtering for the admin user. Its presence
// proves the DB-driven nav pipeline that would surface any leak actually ran.
const POSITIVE_CONTROL_LABEL = 'Administrateurs';

async function loginAsAdmin(page) {
  await page.goto('/login');
  await page.locator('input[type="text"]').first().fill(ADMIN_EMAIL);
  await page.locator('input[type="password"]').fill(ADMIN_PASSWORD);
  // FR app: button reads "Connexion"; keep /login/ as a fallback for EN locale.
  await page.getByRole('button', { name: /connexion|login/i }).click();
  await page.waitForURL(/\/admin\//, { timeout: 30_000 });
}

test.describe('DASH-T11 — V1-hidden modules absent from admin sidebar', () => {
  test('no hidden module nav entry (href or label) renders in .db-sidebar-nav', async ({ page }) => {
    await loginAsAdmin(page);

    if (!/\/admin\/dashboard(\/|$|\?)/.test(page.url())) {
      await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
    }

    const sidebarNav = page.locator('.db-sidebar-nav');
    await expect(sidebarNav, 'admin sidebar nav must mount').toBeVisible({ timeout: 30_000 });

    // POSITIVE CONTROL (anti-vacuous-pass): wait for a DB-sourced, non-hardcoded
    // entry to render. This proves authMenu loaded and the filtering pipeline ran
    // — so an absent hidden module is genuinely filtered, not merely never fetched.
    const navLinks = sidebarNav.locator('a.db-sidebar-nav-menu');
    await expect
      .poll(
        async () => {
          const texts = await navLinks.allInnerTexts();
          return texts.some((t) => t.trim() === POSITIVE_CONTROL_LABEL);
        },
        { timeout: 30_000, message: `positive control "${POSITIVE_CONTROL_LABEL}" must render (proves DB nav pipeline ran)` },
      )
      .toBe(true);

    // Let the dashboard route chunk finish painting so the visual artifact matches
    // the (already-mounted) sidebar DOM. Does not gate any assertion below.
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
    await page.waitForTimeout(800);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'admin-sidebar.png'), fullPage: false });
    // The .db-sidebar parent (not the inner <nav>) carries the visible rows.
    await page.locator('.db-sidebar').first().screenshot({ path: path.join(SCREENSHOT_DIR, 'sidebar-only.png') }).catch(() => {});

    // Snapshot the rendered sidebar: every nav link's exact href + trimmed label.
    const rendered = await navLinks.evaluateAll((els) =>
      els.map((el) => ({
        href: el.getAttribute('href') || el.getAttribute('to') || '',
        text: (el.textContent || '').trim().replace(/\s+/g, ' '),
      })),
    );

    // Sidebar non-empty (necessary, not sufficient — positive control above is the
    // real anti-vacuous guard).
    expect(rendered.length, 'sidebar must render at least one nav link').toBeGreaterThan(0);

    const hrefs = rendered.map((r) => r.href);
    const labels = rendered.map((r) => r.text);

    const leaks = [];

    // --- The 9 BackendMenu-handled hidden modules: exact href AND exact label absent.
    for (const mod of HIDDEN_BACKEND_MODULES) {
      const expectedHref = `/admin/${mod.url}`;
      const hrefHit = hrefs.includes(expectedHref);
      const labelHit = labels.includes(mod.label);

      if (hrefHit) leaks.push(`href "${expectedHref}" (module: ${mod.key})`);
      if (labelHit) leaks.push(`label "${mod.label}" (module: ${mod.key})`);

      // Hard per-module assertions (exact match — no substring).
      expect(hrefs, `HIDDEN MODULE LEAK: href "${expectedHref}" (${mod.key}) must be absent from sidebar`).not.toContain(expectedHref);
      expect(labels, `HIDDEN MODULE LEAK: label "${mod.label}" (${mod.key}) must be absent from sidebar`).not.toContain(mod.label);
    }

    // --- Legacy `items` parent row suppressed (exact /admin/items only).
    // `items/studio` ("Catalogue") is explicitly allowed to remain.
    const itemsParentHref = `/admin/${HIDDEN_PARENT_ROW.url}`;
    if (hrefs.includes(itemsParentHref)) leaks.push(`legacy parent href "${itemsParentHref}"`);
    expect(hrefs, `LEGACY PARENT LEAK: exact "${itemsParentHref}" row must be suppressed (items/studio child stays)`).not.toContain(itemsParentHref);

    // Diagnostic dump on failure path is the assertion message itself; also log clean state.
    // eslint-disable-next-line no-console
    console.log(`[DASH-T11] sidebar links=${rendered.length} | positive-control="${POSITIVE_CONTROL_LABEL}" present | leaks=${leaks.length ? leaks.join('; ') : 'none'}`);
    // eslint-disable-next-line no-console
    console.log(`[DASH-T11] rendered hrefs: ${JSON.stringify(hrefs)}`);

    expect(leaks, `hidden V1 modules leaked into sidebar: ${leaks.join('; ')}`).toHaveLength(0);
  });
});
