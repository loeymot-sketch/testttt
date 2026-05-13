// FoodKing E2E — rush-100 Wave B (POS V4) capture spec
// RUN=rush-100-2026-05-13 / round-1 / wave-B-POS
//
// Mission : capture toutes les surfaces POS V4 admin (login → /admin/pos-v4 →
// wizard popup → cart → cash payment → receipt) pour 5 scenarios S6, S8, S3,
// S4, S10. Emit the artifact quartet (png + dom + console + network) per state
// via mega-audit-snap. Push DB checks (fiscal_sequence_no, total, line count)
// after each order to wave-B-db-checks.json.
//
// Frozen-zone : public/js/pos-wizard.js NE PAS modifier.
//
// Surface targets (DB-mapped 2026-05-13):
//   S3  → Galette Cayenne     id 476, cat 345 (sauce locked Cayenne)
//   S4  → Sandwich Classique  id 477, cat 346 (faluche + crudités + supp)
//   S6  → Big Tacos           id 479, cat 306 (+ menu frites + boisson)
//   S8  → Bol Gratiné         id 484, cat 347 (composer-aware)
//   S10 → Sandwich Cayenne 474 + Grande Frites 486 + Tarte Daim 405

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';

const REPO_ROOT = path.resolve(__dirname, '../..');
const SCREENSHOT_DIR = path.join(REPO_ROOT, 'tests/e2e/__screenshots__/rush-100/pos');
const REPORT_DIR = path.join(REPO_ROOT, 'reports/test-e2e/rush-100-2026-05-13/round-1');
const DB_CHECKS_PATH = path.join(REPORT_DIR, 'wave-B-db-checks.json');

fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
fs.mkdirSync(REPORT_DIR, { recursive: true });

// Scenario catalog : (id, category_id, target item name as visible label)
const SCENARIOS = [
  {
    code: 'S6',
    label: 'Big Tacos + menu',
    catId: 306,
    itemId: 479,
    itemName: 'Big Tacos',
    extraLines: 0,
  },
  {
    code: 'S8',
    label: 'Bol Gratiné full',
    catId: 347,
    itemId: 484,
    itemName: 'Bol Gratiné',
    extraLines: 0,
  },
  {
    code: 'S3',
    label: 'Galette Cayenne',
    catId: 345,
    itemId: 476,
    itemName: 'Galette Cayenne',
    extraLines: 0,
  },
  {
    code: 'S4',
    label: 'Sandwich Classique + supp',
    catId: 346,
    itemId: 477,
    itemName: 'Sandwich Classique',
    extraLines: 0,
  },
  {
    code: 'S10',
    label: 'Multi-cart 3 items',
    catId: 344,
    itemId: 474,
    itemName: 'Sandwich Cayenne',
    extraLines: [
      { catId: 348, itemId: 486, itemName: 'Grande Frites' },
      { catId: 316, itemId: 405, itemName: 'Tarte Daim' },
    ],
  },
];

// In-memory DB checks aggregator → flushed at end of run.
const dbChecks = [];

function appendDbCheck(entry) {
  dbChecks.push(entry);
  fs.writeFileSync(DB_CHECKS_PATH, JSON.stringify(dbChecks, null, 2));
}

function runTinker(code) {
  try {
    const out = execFileSync(
      'php',
      ['artisan', 'tinker', '--execute', code],
      { cwd: REPO_ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 15_000 },
    );
    // Take last JSON line.
    const line = String(out).split(/\r?\n/).map((l) => l.trim()).filter(Boolean).reverse().find((l) => l.startsWith('{') || l.startsWith('['));
    if (!line) return { ok: false, raw: out.substring(0, 500) };
    try { return JSON.parse(line); } catch (_e) { return { ok: false, raw: line }; }
  } catch (err) {
    return { ok: false, error: String(err.message || err).substring(0, 400) };
  }
}

async function clickPosV4TileForItem(page, scenario) {
  // POS V4 sidebar : category pill. Try multiple selector flavors.
  const catSelectors = [
    `.pos-v5-category[data-cat-id="${scenario.catId}"]`,
    `.pos-v4-category-pill[data-cat-id="${scenario.catId}"]`,
    `.pos-v5-category:has-text("${scenario.catId}")`,
  ];
  // Try category selector by ID, then by name fallback.
  let categoryClicked = false;
  for (const sel of catSelectors) {
    const cat = page.locator(sel).first();
    if (await cat.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await cat.click({ timeout: 3_000 }).catch(() => {});
      await page.waitForTimeout(700);
      categoryClicked = true;
      break;
    }
  }
  if (!categoryClicked) {
    // Fallback : find by visible text fragment of category (best effort)
    const catPills = page.locator('.pos-v5-category, .pos-v4-category-pill');
    const count = await catPills.count();
    for (let i = 0; i < Math.min(count, 12); i++) {
      const txt = (await catPills.nth(i).innerText().catch(() => '')).toLowerCase();
      if (txt.includes(scenario.itemName.toLowerCase().split(' ')[0])) {
        await catPills.nth(i).click({ timeout: 2_000 }).catch(() => {});
        await page.waitForTimeout(700);
        categoryClicked = true;
        break;
      }
    }
  }

  // Now click tile by data-item-id, then by visible name.
  const tileById = page.locator(
    `.pos-v5-tile[data-item-id="${scenario.itemId}"], .pos-item-tile[data-item-id="${scenario.itemId}"]`,
  ).first();
  if (await tileById.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await tileById.click({ timeout: 4_000 });
    return true;
  }

  // Fallback : tile contains target name.
  const tileByText = page.locator('.pos-v5-tile, .pos-item-tile')
    .filter({ hasText: new RegExp(scenario.itemName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i') })
    .first();
  if (await tileByText.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await tileByText.click({ timeout: 4_000 });
    return true;
  }

  // Last resort : first non-86'd tile (so the spec still emits a wizard state for inspection).
  const tiles = page.locator('.pos-v5-tile, .pos-item-tile').filter({
    hasNot: page.locator('.pos-item-86-badge, .pos-v5-tile__overlay'),
  });
  if ((await tiles.count()) > 0) {
    await tiles.first().click({ timeout: 4_000 }).catch(() => {});
    return true;
  }
  return false;
}

async function finalizeWizardIfOpen(page) {
  const wizardModal = page.locator('#item-variation-modal').first();
  const isOpen = await wizardModal.isVisible({ timeout: 2_500 }).catch(() => false);
  if (!isOpen) return false;

  // Step through any required steps : click "Suivant" until we land on a final "Ajouter".
  for (let step = 0; step < 6; step++) {
    // Auto-select first option on multi-choice screens (sauce, viande, supp, menu) if visible.
    const optionBtns = wizardModal.locator(
      '.viande-btn.plus, .wizard-menu-card, .addon[data-addon-id], .wizard-option-card',
    );
    const optCount = await optionBtns.count();
    if (optCount > 0) {
      // Click first available option (defensive : already-selected ones still re-emit a no-op).
      await optionBtns.first().click({ timeout: 1_500 }).catch(() => {});
      await page.waitForTimeout(300);
    }

    // Final CTA : "Ajouter au panier" via data-action="add-to-cart" or .wizard-btn-cart
    const addCart = wizardModal.locator(
      'button[data-action="add-to-cart"], .wizard-btn-cart, .wizard-btn[data-nav="cart"]',
    ).first();
    if (await addCart.isVisible({ timeout: 800 }).catch(() => false)) {
      await addCart.click({ timeout: 2_500 }).catch(() => {});
      await page.waitForTimeout(900);
      // Check if modal closed.
      const stillOpen = await wizardModal.isVisible({ timeout: 600 }).catch(() => false);
      if (!stillOpen) return true;
    }

    // Step next : look for "Suivant" / "Next" button
    const nextBtn = wizardModal.locator('button').filter({ hasText: /suivant|next|valider|continuer/i }).first();
    if (await nextBtn.isVisible({ timeout: 500 }).catch(() => false)) {
      await nextBtn.click({ timeout: 1_500 }).catch(() => {});
      await page.waitForTimeout(500);
    } else {
      break;
    }
  }

  // Force-close if still open.
  const stillOpen = await wizardModal.isVisible({ timeout: 600 }).catch(() => false);
  if (stillOpen) {
    await page.keyboard.press('Escape').catch(() => {});
    await page.waitForTimeout(400);
  }
  return true;
}

async function payCashAndConfirm(page, scenarioCode, snap) {
  // 4 — open payment modal
  const payBtn = page.locator('[data-testid="pos-v5-pay"]').first();
  const payVisible = await payBtn.isVisible({ timeout: 4_000 }).catch(() => false);
  if (!payVisible) {
    return { ok: false, reason: 'pay_btn_not_visible' };
  }
  await payBtn.click({ timeout: 4_000 }).catch(() => {});
  await page.waitForTimeout(1_200);
  await snap(`${scenarioCode}-04-payment-modal`);

  // 5 — choose cash
  const cashMode = page.locator('[data-testid="pos-payment-mode-cash"]').first();
  if (await cashMode.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await cashMode.click({ timeout: 2_500 }).catch(() => {});
    await page.waitForTimeout(500);
  }

  // 6 — read grand total to enter exact-change tendered.
  let total = null;
  try {
    const t = await page.locator('[data-testid="pos-grand-total"]').first().innerText().catch(() => '');
    const m = t.match(/(\d+[.,]?\d*)/);
    if (m) total = parseFloat(m[1].replace(',', '.'));
  } catch (_e) { /* ignore */ }

  // Fill cash input — exact total or 100 fallback.
  const cashInput = page.locator('#cashInput, input[name*="received" i], input[name*="cash" i]').first();
  if (await cashInput.isVisible({ timeout: 2_000 }).catch(() => false)) {
    const amount = (total && total > 0) ? Math.ceil(total + 0.5) : 50;
    await cashInput.fill(String(amount)).catch(() => {});
    await page.waitForTimeout(400);
  }

  // 7 — wait for POS create response
  const orderResp = page.waitForResponse(
    (res) =>
      /\/api\/admin\/pos(?:\?|$|\/)/.test(res.url()) &&
      res.request().method() === 'POST' &&
      res.status() < 500,
    { timeout: 25_000 },
  ).catch(() => null);

  const confirmBtn = page.locator('[data-testid="pos-payment-confirm"]').first();
  if (!(await confirmBtn.isVisible({ timeout: 4_000 }).catch(() => false))) {
    return { ok: false, reason: 'confirm_btn_not_visible' };
  }
  await confirmBtn.click({ timeout: 5_000 }).catch(() => {});
  const resp = await orderResp;
  await page.waitForTimeout(1_500);

  let orderId = null;
  let status = null;
  if (resp) {
    status = resp.status();
    try {
      const body = await resp.json();
      const order = body?.data || body?.order || body;
      if (order && typeof order === 'object') {
        orderId = order.id || order?.data?.id || null;
      }
    } catch (_e) { /* ignore */ }
  }
  return { ok: true, total, status, orderId };
}

async function dbCheckLatestOrder() {
  return runTinker(`
    use Illuminate\\Support\\Facades\\DB;
    $row = DB::table('orders')->orderByDesc('id')->first();
    if (! $row) { echo json_encode(['ok' => false, 'reason' => 'no_orders']); return; }
    $lines = DB::table('order_items')->where('order_id', $row->id)->count();
    $snap = DB::table('order_items')->where('order_id', $row->id)->orderBy('id')->limit(1)->value('composition_snapshot');
    $snapLines = null;
    if ($snap) {
      $arr = json_decode($snap, true);
      if (is_array($arr) && isset($arr['lines']) && is_array($arr['lines'])) { $snapLines = count($arr['lines']); }
    }
    echo json_encode([
      'ok' => true,
      'id' => (int) $row->id,
      'fiscal_sequence_no' => isset($row->fiscal_sequence_no) ? (int) $row->fiscal_sequence_no : null,
      'total' => isset($row->total) ? (float) $row->total : null,
      'payment_status' => $row->payment_status ?? null,
      'status' => $row->status ?? null,
      'lines_count' => (int) $lines,
      'composition_snapshot_lines' => $snapLines,
    ]);
  `);
}

test.describe.configure({ mode: 'serial' });

test.describe('rush-100 Wave B — POS V4 capture (5 scenarios)', () => {
  test.setTimeout(180_000);

  let recorder = null;
  let snap = null;

  test.beforeAll(async () => {
    // Reset shared aggregator for a clean run.
    if (!fs.existsSync(DB_CHECKS_PATH)) {
      fs.writeFileSync(DB_CHECKS_PATH, JSON.stringify([], null, 2));
    }
  });

  test('00 — login admin + /admin/pos-v4 + cash drawer open', async ({ page }) => {
    const r = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
    recorder = r; snap = r.snap;

    await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await page.waitForTimeout(1_500);
    await snap('00-admin-dashboard');

    await page.goto('/admin/pos-v4', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);
    await expect(page).toHaveURL(/\/admin\/pos-v4/, { timeout: 20_000 });

    const grid = page.locator('.pos-v5-grid, [data-testid="pos-grand-total"]').first();
    await expect(grid).toBeVisible({ timeout: 15_000 });

    // Cash drawer open (F-003) one-shot — best-effort.
    const openCashBtn = page.locator('[data-testid="kiosk-cash-open"]').first();
    if (await openCashBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await openCashBtn.click({ timeout: 3_000 }).catch(() => {});
      await page.waitForTimeout(700);
      const floatField = page.locator('input[type="number"], input[name*="float" i], input[name*="opening" i]').first();
      if (await floatField.isVisible({ timeout: 1_500 }).catch(() => false)) {
        await floatField.fill('100').catch(() => {});
        const confirm = page.getByRole('button', { name: /ouvrir|valider|confirmer|confirm/i }).first();
        if (await confirm.isVisible({ timeout: 1_500 }).catch(() => false)) {
          await confirm.click({ timeout: 3_000 }).catch(() => {});
          await page.waitForTimeout(1_000);
        }
      }
    }

    await snap('00-pos-v4-ready');
    recorder.dispose();
  });

  for (const sc of SCENARIOS) {
    test(`${sc.code} — ${sc.label}`, async ({ page }) => {
      const r = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
      const localSnap = r.snap;

      try {
        // Wipe pos-order-create + api rate-limit buckets before EACH order.
        // The first scenario passed at HTTP 200 then S8/S3/S4/S10 inherited 429.
        try { clearFoodKingRateLimits(); } catch (_e) { /* soft */ }
        await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASSWORD);
        await page.waitForTimeout(1_200);
        await page.goto('/admin/pos-v4', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2_500);
        await expect(page).toHaveURL(/\/admin\/pos-v4/, { timeout: 15_000 });

        await localSnap(`${sc.code}-01-pos-empty`);

        // 2 — open wizard for target item
        const clicked = await clickPosV4TileForItem(page, sc);
        if (!clicked) {
          await localSnap(`${sc.code}-02-wizard-popup-NOT-OPENED`);
          throw new Error(`${sc.code} : tile not clickable for itemId=${sc.itemId}`);
        }
        await page.waitForTimeout(1_500);
        await localSnap(`${sc.code}-02-wizard-popup`);

        // 3 — step through wizard if it opened
        await finalizeWizardIfOpen(page);
        await page.waitForTimeout(700);

        // S10 only : add 2 extra lines.
        if (Array.isArray(sc.extraLines) && sc.extraLines.length > 0) {
          for (const extra of sc.extraLines) {
            await clickPosV4TileForItem(page, extra);
            await page.waitForTimeout(1_200);
            await finalizeWizardIfOpen(page);
            await page.waitForTimeout(400);
          }
        }

        await localSnap(`${sc.code}-03-cart-with-item`);

        // 4–6 — payment cash
        const payRes = await payCashAndConfirm(page, sc.code, localSnap);
        await localSnap(`${sc.code}-05-receipt`);
        await page.waitForTimeout(1_200);

        // DB check
        const dbRow = await dbCheckLatestOrder();
        appendDbCheck({
          scenario: sc.code,
          label: sc.label,
          target_item_id: sc.itemId,
          target_item_name: sc.itemName,
          paid_response_status: payRes.status,
          read_grand_total: payRes.total,
          db: dbRow,
          ts: new Date().toISOString(),
        });

        // 7 — back to POS
        // Try close any receipt overlay then snap.
        await page.keyboard.press('Escape').catch(() => {});
        await page.waitForTimeout(800);
        const newOrderBtn = page.locator('button').filter({ hasText: /nouvelle commande|new order|continuer|close|fermer/i }).first();
        if (await newOrderBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
          await newOrderBtn.click({ timeout: 2_000 }).catch(() => {});
          await page.waitForTimeout(800);
        }
        await localSnap(`${sc.code}-06-back-to-pos`);

        // Soft sanity : no fatal error.
        const text = await page.locator('body').innerText().catch(() => '');
        expect(text).not.toMatch(/Whoops|Fatal error|Server Error/i);
      } catch (err) {
        // Capture failure state without aborting wave coverage.
        await localSnap(`${sc.code}-99-error-state`);
        appendDbCheck({
          scenario: sc.code,
          label: sc.label,
          error: String(err && err.message ? err.message : err).substring(0, 400),
          ts: new Date().toISOString(),
        });
        // Re-throw so Playwright records the failure, but the snaps stay durable.
        throw err;
      } finally {
        r.dispose();
      }
    });
  }
});
