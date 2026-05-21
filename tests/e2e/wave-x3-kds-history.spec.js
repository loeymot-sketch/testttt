// FoodKing Wave X3 — KDS Historique du jour (read-only V1) E2E
// =============================================================
// Mission: verify the new day-history drawer added by Wave X3.
// Owner mandate verbatim:
//   "Sur KDS, je veux un bouton 'historique' ou 'archives'. Chef peut voir
//    toutes les commandes du jour (prêt + livré). Vérifier le contenu si
//    erreur. Trié comme grille principale."
//
// Read-only V1: revert (PREPARED → PREPARING) deferred to V1.0.2 because
// OrderStateMachine §7 forbids reverse transitions. No revert control is
// rendered in V1 — sentinel asserts this.
//
// Status mapping (per app/Enums/OrderStatus.php):
//   PREPARED         = 8
//   OUT_FOR_DELIVERY = 10
//   DELIVERED        = 13
//
// Seed strategy: artisan tinker creates Orders directly in the target states
// today so the history endpoint sees them. This avoids driving the full
// POS→KDS bump flow (covered by Wave T) and keeps this spec scoped to the
// drawer surface itself.

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

const SHOT_DIR = path.join(
  process.cwd(),
  'reports/test-e2e/wave-x-2026-05-21/x3-kds-history/screenshots',
);
if (!fs.existsSync(SHOT_DIR)) {
  fs.mkdirSync(SHOT_DIR, { recursive: true });
}

const TOKEN_PREFIX = 'WAVE-X3-HIST-';

function artisan(code) {
  return execFileSync(
    'php',
    ['artisan', 'tinker', '--execute', code],
    {
      cwd: path.resolve(__dirname, '../..'),
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 60_000,
    },
  );
}

function seedHistoricalOrder({ token, status, branchId = 1 }) {
  // Status enum integers: PREPARED=8, OUT=10, DELIVERED=13.
  // `payment_status=PAID(10)` ensures the order is "released to kitchen"
  // semantics; `is_advance_order=0`, `branch_id=1` (Le Cayenne primary),
  // `order_datetime=now` so the row falls into today's Paris window for
  // both ordering queries. `updated_at=now()` is what the history endpoint
  // bounds against.
  // [Wave X round-1 heal] PHP variables use single `$` in tinker --execute
  // (no `\\` escape needed in JS template; the JS string passes verbatim).
  // user_id = 1 (admin) required: NOT NULL column. Matches the Wave S-4 /
  // Wave T R1 F1 seed pattern (system user).
  const phpCode = `
    $o = new \\App\\Models\\Order();
    $o->order_serial_no = '${token}';
    $o->order_type = 5;
    $o->source_surface = 'pos';
    $o->branch_id = ${branchId};
    $o->user_id = 1;
    $o->status = ${status};
    $o->payment_status = 10;
    $o->payment_method = 1;
    $o->subtotal = 12.50;
    $o->total = 12.50;
    $o->discount = 0;
    $o->delivery_charge = 0;
    $o->order_datetime = now();
    $o->updated_at = now();
    $o->is_advance_order = 0;
    $o->saveQuietly();
    echo $o->id . '|' . $o->order_serial_no;
  `.replace(/\s+/g, ' ');
  const out = artisan(phpCode).trim();
  const [id, serial] = out.split('|');
  return { id: parseInt(id, 10), serial };
}

function cleanupSeededOrders() {
  try {
    artisan(
      `\\App\\Models\\Order::where('order_serial_no', 'like', '${TOKEN_PREFIX}%')->withoutGlobalScopes()->forceDelete();`,
    );
  } catch (_err) {
    // Best-effort.
  }
}

test.describe('Wave X3 — KDS Historique du jour drawer (read-only V1)', () => {
  test.beforeAll(() => {
    cleanupSeededOrders();
  });

  test.afterAll(() => {
    cleanupSeededOrders();
  });

  test('drawer trigger button is visible on /admin/kitchen-display-system', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });

    const trigger = page.locator('[data-testid="kds-history-button"]');
    await expect(trigger).toBeVisible({ timeout: 15_000 });

    await page.screenshot({
      path: path.join(SHOT_DIR, 'X3-01-kds-with-history-button.png'),
      fullPage: true,
    });
  });

  test('clicking trigger opens drawer with today\'s history list', async ({ page }) => {
    // Seed 2 historical orders BEFORE loading the KDS page.
    const orderA = seedHistoricalOrder({ token: `${TOKEN_PREFIX}1-${Date.now()}`, status: 8 });   // PREPARED
    const orderB = seedHistoricalOrder({ token: `${TOKEN_PREFIX}2-${Date.now()}`, status: 13 });  // DELIVERED

    await loginAsAdmin(page);
    await page.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });

    const trigger = page.locator('[data-testid="kds-history-button"]');
    await expect(trigger).toBeVisible({ timeout: 15_000 });
    await trigger.click();

    const drawer = page.locator('[data-testid="kds-history-drawer"]');
    await expect(drawer).toBeVisible({ timeout: 10_000 });

    // Loading state may flash briefly; wait for either list or empty.
    await page
      .locator('[data-testid="kds-history-list"], [data-testid="kds-history-empty"]')
      .first()
      .waitFor({ timeout: 15_000 });

    // The list MUST contain at least the two we seeded (other today-state rows
    // from seeded fixtures are tolerated).
    const items = page.locator('[data-testid="kds-history-item"]');
    const count = await items.count();
    expect(count).toBeGreaterThanOrEqual(2);

    // [Wave X B-003 round-1 heal] Anchored exact-text assertions, NOT a
    // substring regex. The previous regex `/(prêt|ready|prepared|jaha|قيد)/i`
    // was vacuously satisfied by the raw key `LABEL.KDS_STATE_PREPARED` —
    // matching the English substring "prepared" inside the leaked key.
    // FR is the locked locale for /admin/* (cf. i18n.js isAdminPath →
    // detectLocale returns 'fr'), so we expect "Historique du jour" header
    // and at least one anchored badge token: "Prêt" / "En livraison" / "Livré".
    const drawerText = await drawer.innerText();

    // 1. Header must be resolved (not the raw key).
    expect(drawerText).toContain('Historique du jour');
    expect(drawerText).not.toMatch(/label\.kds_history_title/i);

    // 2. At least one status badge must be a resolved FR token, exact match.
    //    Match-word boundary prevents accidental substring satisfaction.
    //    Case-insensitive: row badges apply CSS `text-transform: uppercase`,
    //    so `innerText` returns "LIVRÉ" / "PRÊT" / "EN LIVRAISON".
    expect(drawerText).toMatch(/\b(Prêt|En livraison|Livré)\b/i);

    // 3. Negative sentinel: any raw i18n key (label.* / LABEL.* / kiosk.* /
    //    kds.*) leaking into the rendered drawer is a P0 failure.
    expect(drawerText).not.toMatch(/\b(label|kiosk|kds)\.[a-z_]+\b/i);
    expect(drawerText).not.toMatch(/\bLABEL\.[A-Z_]+\b/);

    await page.screenshot({
      path: path.join(SHOT_DIR, 'X3-02-drawer-open-with-history.png'),
      fullPage: true,
    });

    // Note seeded IDs in meta for debugging.
    fs.writeFileSync(
      path.join(SHOT_DIR, 'X3-seeded.json'),
      JSON.stringify({ orderA, orderB, totalItems: count }, null, 2),
    );
  });

  test('close button dismisses the drawer', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });

    const trigger = page.locator('[data-testid="kds-history-button"]');
    await expect(trigger).toBeVisible({ timeout: 15_000 });
    await trigger.click();

    const drawer = page.locator('[data-testid="kds-history-drawer"]');
    await expect(drawer).toBeVisible({ timeout: 10_000 });

    await page.locator('[data-testid="kds-history-close"]').click();
    await expect(drawer).toBeHidden({ timeout: 5_000 });

    await page.screenshot({
      path: path.join(SHOT_DIR, 'X3-03-drawer-closed.png'),
      fullPage: true,
    });
  });

  test('V1 contract: no revert/recall button in drawer (deferred V1.0.2)', async ({ page }) => {
    // Seed at least one PREPARED order so the drawer renders rows.
    seedHistoricalOrder({ token: `${TOKEN_PREFIX}revert-${Date.now()}`, status: 8 });

    await loginAsAdmin(page);
    await page.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });

    const trigger = page.locator('[data-testid="kds-history-button"]');
    await expect(trigger).toBeVisible({ timeout: 15_000 });
    await trigger.click();

    const drawer = page.locator('[data-testid="kds-history-drawer"]');
    await expect(drawer).toBeVisible({ timeout: 10_000 });

    await page
      .locator('[data-testid="kds-history-list"], [data-testid="kds-history-empty"]')
      .first()
      .waitFor({ timeout: 15_000 });

    // V1 must NOT expose any revert/recall/restore action inside the drawer.
    // OrderStateMachine §7 forbids reverse transitions — LOCK plan + owner
    // countersign required for V1.0.2.
    const revertControls = drawer.locator(
      'button:has-text("Renvoyer"), button:has-text("Revert"), button:has-text("Recall"), button:has-text("Annuler"), button:has-text("Rétablir")',
    );
    await expect(revertControls).toHaveCount(0);

    await page.screenshot({
      path: path.join(SHOT_DIR, 'X3-04-drawer-no-revert-button.png'),
      fullPage: true,
    });
  });
});
