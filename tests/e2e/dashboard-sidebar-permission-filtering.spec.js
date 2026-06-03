// FoodKing E2E — GOAL_MGMT_TESTPLAN Wave B · DASH-T12
// Sidebar permission-filtering / RBAC.
//
// Invariant under test: the admin backend sidebar is permission-filtered.
// A lower-privilege user (POS Operator, pos@lecayenne.fr) must see a STRICT
// SUBSET of the sidebar vs. a full admin (admin@lecayenne.fr): sensitive
// sections present for admin (e.g. Transactions / Employés / Clients) must be
// ABSENT for the POS operator, while the POS operator still sees its own
// allowed items (positive control — sidebar non-empty).
//
// GROUNDING (verified 2026-06-03):
//   - database/seeders/UserTableSeeder.php:80-92 → pos@lecayenne.fr / 123456,
//     role POS Operator, branch_id=1.
//   - database/seeders/RolePermissionTableSeeder.php:96-136 → POS Operator
//     permissions = dashboard, pos, pos-orders, pos-discount-up-to-10,
//     pos.redeem-loyalty, kitchen-display-system, order-status-screen.
//     Admin gets Permission::all() (line 18-19).
//   - resources/js/.../layouts/backend/BackendMenuComponent.vue → sidebar links
//     render as `a.db-sidebar-nav-menu` / `router-link.db-sidebar-nav-menu`
//     (label in inner <span>), section headers as `.db-sidebar-nav-title`.
//     menuPathAllowed() gates each row by Spatie permission url.
//   - Login button label = "Connexion" ($t('button.login'), fr.json), inputs
//     are type="text" (email) + type="password".
//
// NOTE on the component's fail-open semantics (BackendMenuComponent.vue:235-248):
//   userHasPermissionUrl() returns true when authPermission is empty. We MUST
//   wait for the store to hydrate (a known POS-allowed label visible) before
//   snapshotting, otherwise both roles would momentarily show everything and we
//   would emit a false "no filtering" finding.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.describe.configure({ timeout: 90_000 });

const PASSWORD = '123456';
const ADMIN_EMAIL = 'admin@lecayenne.fr';
const LOWER_EMAIL = 'pos@lecayenne.fr'; // POS Operator (Caissier Le Cayenne)

const SCREENSHOT_DIR = path.join(__dirname, '__screenshots__', 'dash-perm');
if (!fs.existsSync(SCREENSHOT_DIR)) {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

// A label every authenticated backend user (incl. POS Operator) can see —
// used as the "store hydrated + sidebar populated" gate before capture.
const HYDRATION_LABEL = /Commandes caisse|POS|Tableau de bord/i;

async function login(page, email) {
  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[type="text"]').first().fill(email);
  await page.locator('input[type="password"]').first().fill(PASSWORD);
  // FR login button label = "Connexion".
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/\/admin\//, { timeout: 30_000 });
}

/**
 * Normalize every role to the SAME route (/admin/dashboard) for apples-to-apples
 * comparison: POS lands on /admin/pos, admin on /admin/dashboard, and the
 * sidebar is hidden on KDS/OSS shells. POS Operator holds the `dashboard`
 * permission so it must not be bounced.
 */
async function gotoDashboard(page) {
  if (!/\/admin\/dashboard(\/|$|\?)/.test(page.url())) {
    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
  }
  // Wait for the SPA sidebar to mount + the store to hydrate permissions.
  await page.locator('aside.db-sidebar').first().waitFor({ state: 'attached', timeout: 30_000 });
  // Gate on the actual sidebar nav rows we capture (state:attached — they may be
  // in a collapsed drawer i.e. not "visible", but querySelectorAll still reads them).
  // A text-regex gate is brittle: /POS/ also matches the hidden 'pos@…fr' email <p>.
  await page
    .locator('aside.db-sidebar .db-sidebar-nav-menu, aside.db-sidebar .db-sidebar-nav-title')
    .first()
    .waitFor({ state: 'attached', timeout: 30_000 });
  await page.waitForTimeout(1_500); // settle any post-hydration re-render
}

/**
 * Capture the visible sidebar label set: both nav links (.db-sidebar-nav-menu)
 * and section header buttons (.db-sidebar-nav-title). De-duplicated, trimmed,
 * non-empty.
 */
async function captureSidebarLabels(page) {
  return page.evaluate(() => {
    const out = new Set();
    document
      .querySelectorAll('aside.db-sidebar .db-sidebar-nav-menu, aside.db-sidebar .db-sidebar-nav-title')
      .forEach((el) => {
        const t = (el.textContent || '').replace(/\s+/g, ' ').trim();
        if (t) out.add(t);
      });
    return Array.from(out);
  });
}

test.describe('DASH-T12 — admin sidebar is permission-filtered (RBAC)', () => {
  test('POS operator sidebar is a strict subset of admin; sensitive sections hidden', async ({ page }) => {
    // Desktop viewport so the sidebar renders expanded (not the mobile drawer).
    await page.setViewportSize({ width: 1440, height: 900 });
    // ---- (1) ADMIN ----
    await login(page, ADMIN_EMAIL);
    await gotoDashboard(page);
    const adminLabels = await captureSidebarLabels(page);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'admin-sidebar.png'), fullPage: true });

    // ---- (2) LOWER ROLE (POS Operator) ----
    await page.context().clearCookies();
    await page.evaluate(() => { try { localStorage.clear(); sessionStorage.clear(); } catch (_e) {} });

    await login(page, LOWER_EMAIL);
    // Guard against silently landing on a non-admin shell (would be a creds/login failure, not a defect).
    expect(page.url(), `POS operator login did not reach an /admin/ surface — check creds`).toMatch(/\/admin\//);
    await gotoDashboard(page);
    const lowerLabels = await captureSidebarLabels(page);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'pos-operator-sidebar.png'), fullPage: true });

    // Diagnostics (printed on every run for calibration / evidence).
    console.log('ADMIN sidebar labels (' + adminLabels.length + '):', JSON.stringify(adminLabels));
    console.log('POS   sidebar labels (' + lowerLabels.length + '):', JSON.stringify(lowerLabels));
    const adminSet = new Set(adminLabels);
    const onlyAdmin = adminLabels.filter((l) => !lowerLabels.includes(l));
    const onlyLower = lowerLabels.filter((l) => !adminSet.has(l));
    console.log('ONLY in admin (hidden for POS):', JSON.stringify(onlyAdmin));
    console.log('ONLY in POS  (not in admin)   :', JSON.stringify(onlyLower));

    // ---- POSITIVE CONTROL: neither sidebar is vacuously empty ----
    expect(adminLabels.length, 'admin sidebar must render items (else login/hydration failed, not a defect)').toBeGreaterThan(0);
    expect(lowerLabels.length, 'POS operator sidebar must render its allowed items (positive control — not vacuous)').toBeGreaterThan(0);

    // POS operator must see at least one of its own allowed surfaces.
    const posAllowedSeen = lowerLabels.some((l) => /POS|Commandes caisse|Tableau de bord/i.test(l));
    expect(posAllowedSeen, `POS operator must see its allowed items. Got: ${JSON.stringify(lowerLabels)}`).toBeTruthy();

    // ---- STRICT SUBSET: POS ⊊ admin ----
    // Every POS label must also appear in admin (admin holds Permission::all()).
    const posLabelsNotInAdmin = lowerLabels.filter((l) => !adminSet.has(l));
    expect(
      posLabelsNotInAdmin,
      `POS operator shows labels absent from admin (unexpected — admin has all perms): ${JSON.stringify(posLabelsNotInAdmin)}`,
    ).toHaveLength(0);

    // Strictly fewer items for the lower role — proves filtering, not identity.
    // If equal: RBAC filtering is NOT enforced (DUAL GUARD → real finding).
    expect(
      lowerLabels.length,
      `RBAC filtering not enforced: POS operator sidebar (${lowerLabels.length}) is NOT a strict subset of admin (${adminLabels.length}). ` +
        `Admin=${JSON.stringify(adminLabels)} POS=${JSON.stringify(lowerLabels)}`,
    ).toBeLessThan(adminLabels.length);

    // ---- HARD: specific sensitive sections present-for-admin, absent-for-POS ----
    // Calibrated against the seeder: POS Operator lacks transactions, employees,
    // customers permissions; admin has them. These FR labels come from fr.json
    // (menu.transactions="Transactions", menu.employees="Employés",
    //  menu.customers="Clients").
    const SENSITIVE = ['Transactions', 'Employés', 'Clients'];
    for (const label of SENSITIVE) {
      const inAdmin = adminLabels.some((l) => l === label || l.includes(label));
      const inLower = lowerLabels.some((l) => l === label || l.includes(label));
      // Only assert the hide if admin actually exposes it (guards label drift).
      if (inAdmin) {
        expect(
          inLower,
          `RBAC leak: sensitive section "${label}" is visible to POS operator but should be admin-only. POS=${JSON.stringify(lowerLabels)}`,
        ).toBeFalsy();
      }
    }
    // At least ONE sensitive section must have been present for admin, else the
    // hard check above is vacuous.
    const sensitivePresentForAdmin = SENSITIVE.filter((label) =>
      adminLabels.some((l) => l === label || l.includes(label)),
    );
    expect(
      sensitivePresentForAdmin.length,
      `No sensitive section found in admin sidebar to anchor the hide-assertion. Admin=${JSON.stringify(adminLabels)}`,
    ).toBeGreaterThan(0);
  });
});
