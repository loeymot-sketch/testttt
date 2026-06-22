// FoodKing E2E — Wave POS-CROSS — RUN=menu-v2-final-2026-05-14 / round-1
//
// Mission : Validation cross-surface POS V4 + KDS + OSS + Admin post heal-light
// V2 (commit 62959bfc9 — 12 categories canonical, new items: Burgers cat 349
// with Big Chicken 8.90€, Bowl Frites 4 viandes 8.90€, Big Cayenne 9.50€).
//
// Goals (advisor-reconciled 2026-05-14) :
//   - Observe sidebar category count + verify cat 349 (Burgers) + 350 (Menu
//     enfant) pills render with non-empty aria-label + title (NOT fail-hard on
//     12 — admin sees ~20 due to PosCategoryController bypass)
//   - 3 NEW-menu POS scenarios with rate-limit-safe 32s spacing :
//       S-POS-NEW-01 : Big Cayenne id=488 (cat 344 Sandwich Cayenne) — 9.50€
//       S-POS-NEW-02 : Big Chicken id=490 (cat 349 Burgers) — 8.90€  ← Burgers proof
//       S-POS-NEW-03 : Bowl Frites Poulet curry id=493 (cat 347 Bols) — 8.90€
//   - Cross-surface : for each persisted POS order, capture KDS + OSS UI +
//     check API presence (kds-order, oss-order) with realistic latency
//     measurement (clock starts at confirmBtn.click → kds_seen timestamp)
//   - Admin items list (search "Bowl Frites" → 4 items expected) + items
//     visible for Big Chicken / Big Cayenne / Chicken Burger
//   - Admin item-categories list at /admin/settings/item-categories/list
//
// Frozen-zone : public/js/pos-wizard.js + public/js/pos-app.js — READ ONLY
//
// Output :
//   spec        : tests/e2e/menu-v2-pos-cross-final.spec.js
//   screenshots : tests/e2e/__screenshots__/menu-v2-final/{pos,kds,oss,admin}/
//   db tracking : reports/test-e2e/menu-v2-final-2026-05-14/round-1/wave-POS-CROSS-db-tracking.json
//   report      : reports/test-e2e/menu-v2-final-2026-05-14/round-1/wave-POS-CROSS-report.md

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
const SS_BASE = path.join(REPO_ROOT, 'tests/e2e/__screenshots__/menu-v2-final');
const SS_POS = path.join(SS_BASE, 'pos');
const SS_KDS = path.join(SS_BASE, 'kds');
const SS_OSS = path.join(SS_BASE, 'oss');
const SS_ADMIN = path.join(SS_BASE, 'admin');
const REPORT_DIR = path.join(REPO_ROOT, 'reports/test-e2e/menu-v2-final-2026-05-14/round-1');
const DB_TRACKING_PATH = path.join(REPORT_DIR, 'wave-POS-CROSS-db-tracking.json');

[SS_POS, SS_KDS, SS_OSS, SS_ADMIN, REPORT_DIR].forEach((d) =>
  fs.mkdirSync(d, { recursive: true }),
);

// Scenario catalog — DB-verified 2026-05-14 from heal-light V2 commit 62959bfc9
const SCENARIOS = [
  {
    code: 'S-POS-NEW-01',
    label: 'Big Cayenne (NEW sandwich variant)',
    catId: 344,
    catName: 'Sandwich Cayenne',
    itemId: 488,
    itemName: 'Big Cayenne',
    expectedPrice: 9.5,
  },
  {
    code: 'S-POS-NEW-02',
    label: 'Big Chicken — Burgers cat proof',
    catId: 349,
    catName: 'Burgers',
    itemId: 490,
    itemName: 'Big Chicken',
    expectedPrice: 8.9,
  },
  {
    code: 'S-POS-NEW-03',
    label: 'Bowl Frites Poulet curry (NEW bowl/frites base)',
    catId: 347,
    catName: 'Bols Gourmands',
    itemId: 493,
    itemName: 'Bowl Frites Poulet curry',
    expectedPrice: 8.9,
  },
];

// In-memory aggregator (flushed to disk on every append).
const dbTracking = {
  run: 'menu-v2-final-2026-05-14',
  round: 1,
  wave: 'POS-CROSS',
  started_at: new Date().toISOString(),
  baseline: null,
  sidebar: null,
  admin_searches: {},
  scenarios: [],
};
function flushTracking() {
  fs.writeFileSync(DB_TRACKING_PATH, JSON.stringify(dbTracking, null, 2));
}
flushTracking();

function runTinker(code) {
  try {
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], {
      cwd: REPO_ROOT,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 20_000,
    });
    const lines = String(out).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
    const jsonLine = [...lines].reverse().find((l) => l.startsWith('{') || l.startsWith('['));
    if (!jsonLine) return { ok: false, raw: out.substring(0, 400) };
    try {
      return JSON.parse(jsonLine);
    } catch (_e) {
      return { ok: false, raw: jsonLine.substring(0, 400) };
    }
  } catch (err) {
    return { ok: false, error: String(err?.message || err).substring(0, 400) };
  }
}

function dbBaseline() {
  return runTinker(`
    use Illuminate\\Support\\Facades\\DB;
    echo json_encode([
      'latest_fiscal_seq' => (int) DB::table('orders')->max('fiscal_sequence_no'),
      'latest_order_id'   => (int) DB::table('orders')->max('id'),
      'now'               => now()->toIso8601String(),
    ]);
  `);
}

function dbCheckLatestOrder() {
  return runTinker(`
    use Illuminate\\Support\\Facades\\DB;
    $row = DB::table('orders')->orderByDesc('id')->first();
    if (! $row) { echo json_encode(['ok' => false, 'reason' => 'no_orders']); return; }
    $lines = DB::table('order_items')->where('order_id', $row->id)->count();
    $snap = DB::table('order_items')->where('order_id', $row->id)->orderBy('id')->limit(1)->value('composition_snapshot');
    $snapLines = null; $snapHead = null;
    if ($snap) {
      $arr = json_decode($snap, true);
      if (is_array($arr) && isset($arr['lines']) && is_array($arr['lines'])) {
        $snapLines = count($arr['lines']);
        $snapHead = array_map(fn(\$l) => array_intersect_key(\$l, array_flip(['label','quantity','unit_price','total'])), array_slice(\$arr['lines'], 0, 4));
      }
    }
    echo json_encode([
      'ok' => true,
      'id' => (int) $row->id,
      'fiscal_sequence_no' => isset($row->fiscal_sequence_no) ? (int) $row->fiscal_sequence_no : null,
      'total' => isset($row->total) ? (float) $row->total : null,
      'payment_status' => $row->payment_status ?? null,
      'status' => $row->status ?? null,
      'token' => $row->token ?? null,
      'order_serial_no' => $row->order_serial_no ?? null,
      'created_at' => $row->created_at ?? null,
      'lines_count' => (int) $lines,
      'composition_snapshot_lines_count' => $snapLines,
      'composition_snapshot_head' => $snapHead,
    ]);
  `);
}

function dbItemSearch(needle) {
  const escaped = needle.replace(/['"\\]/g, '');
  return runTinker(`
    use Illuminate\\Support\\Facades\\DB;
    $rows = DB::table('items')
      ->where('name', 'like', '%${escaped}%')
      ->where('status', 5)
      ->select('id','name','price','item_category_id')
      ->orderBy('id')->get()->toArray();
    echo json_encode(array_map(fn(\$r) => (array) \$r, $rows));
  `);
}

async function clickPosV4TileForItem(page, scenario) {
  // PosComponent.vue category pills expose `aria-label` + `title` but NO
  // `data-cat-id` attr (verified live 2026-05-14). Match by aria-label only.
  const catPills = page.locator('.pos-v5-category, .pos-v4-category-pill');
  const total = await catPills.count();
  let clicked = false;
  for (let i = 0; i < total; i++) {
    const pill = catPills.nth(i);
    const aria = await pill.getAttribute('aria-label').catch(() => null);
    if (aria && aria.trim() === scenario.catName) {
      await pill.click({ timeout: 3_000 }).catch(() => {});
      await page.waitForTimeout(900);
      clicked = true;
      break;
    }
  }
  if (!clicked) {
    // Defensive: title match (template emits title === aria-label).
    const byTitle = page.locator(`.pos-v5-category[title="${scenario.catName}"], .pos-v4-category-pill[title="${scenario.catName}"]`).first();
    if (await byTitle.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await byTitle.click({ timeout: 3_000 }).catch(() => {});
      await page.waitForTimeout(900);
      clicked = true;
    }
  }

  // Click tile by data-pos-item-id (ItemComponent.vue verified 2026-05-14).
  // No last-resort fallback that would mask the NEW-menu validation signal
  // (advisor #4): if the tile isn't visible we fail the scenario by name match
  // exactly, otherwise emit ok:false.
  const tile = page.locator(`.pos-v5-tile[data-pos-item-id="${scenario.itemId}"], .pos-item-tile[data-pos-item-id="${scenario.itemId}"]`).first();
  if (await tile.isVisible({ timeout: 4_000 }).catch(() => false)) {
    await tile.click({ timeout: 4_000 });
    return { ok: true, categoryClicked: clicked };
  }
  // Strict exact-name fallback (still scoped to .pos-v5-tile, no global text match).
  const byName = page.locator('.pos-v5-tile, .pos-item-tile')
    .filter({ has: page.locator('.pos-v5-tile__name', { hasText: new RegExp(`^\\s*${scenario.itemName.replace(/[.*+?^${}()|[\]\\]/g, '\\\\$&')}\\s*$`, 'i') }) })
    .first();
  if (await byName.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await byName.click({ timeout: 3_000 });
    return { ok: true, categoryClicked: clicked, hitByName: true };
  }
  return { ok: false, categoryClicked: clicked, reason: 'tile_not_visible' };
}

async function finalizeWizardIfOpen(page) {
  const wizardModal = page.locator('#item-variation-modal').first();
  const isOpen = await wizardModal.isVisible({ timeout: 2_500 }).catch(() => false);
  if (!isOpen) return false;

  for (let step = 0; step < 8; step++) {
    // Auto-select first available option on each step (sauce, viande, supp, menu).
    const optionBtns = wizardModal.locator(
      '.viande-btn.plus, .wizard-menu-card, .addon[data-addon-id], .wizard-option-card',
    );
    const optCount = await optionBtns.count();
    if (optCount > 0) {
      await optionBtns.first().click({ timeout: 1_500 }).catch(() => {});
      await page.waitForTimeout(280);
    }

    const addCart = wizardModal.locator(
      'button[data-action="add-to-cart"], .wizard-btn-cart, .wizard-btn[data-nav="cart"]',
    ).first();
    if (await addCart.isVisible({ timeout: 800 }).catch(() => false)) {
      await addCart.click({ timeout: 2_500 }).catch(() => {});
      await page.waitForTimeout(900);
      const stillOpen = await wizardModal.isVisible({ timeout: 500 }).catch(() => false);
      if (!stillOpen) return true;
    }

    const nextBtn = wizardModal.locator('button').filter({ hasText: /suivant|next|valider|continuer/i }).first();
    if (await nextBtn.isVisible({ timeout: 500 }).catch(() => false)) {
      await nextBtn.click({ timeout: 1_500 }).catch(() => {});
      await page.waitForTimeout(450);
    } else {
      break;
    }
  }

  const stillOpen = await wizardModal.isVisible({ timeout: 600 }).catch(() => false);
  if (stillOpen) {
    await page.keyboard.press('Escape').catch(() => {});
    await page.waitForTimeout(400);
  }
  return true;
}

async function payCashAndConfirm(page, snap, scenarioCode) {
  const payBtn = page.locator('[data-testid="pos-v5-pay"]').first();
  const payVisible = await payBtn.isVisible({ timeout: 4_000 }).catch(() => false);
  if (!payVisible) return { ok: false, reason: 'pay_btn_not_visible' };
  await payBtn.click({ timeout: 4_000 }).catch(() => {});
  await page.waitForTimeout(1_200);
  await snap(`${scenarioCode}-04-payment-modal`);

  const cashMode = page.locator('[data-testid="pos-payment-mode-cash"]').first();
  if (await cashMode.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await cashMode.click({ timeout: 2_500 }).catch(() => {});
    await page.waitForTimeout(450);
  }

  let total = null;
  try {
    const t = await page.locator('[data-testid="pos-grand-total"]').first().innerText().catch(() => '');
    const m = t.match(/(\d+[.,]?\d*)/);
    if (m) total = parseFloat(m[1].replace(',', '.'));
  } catch (_e) { /* ignore */ }

  const cashInput = page.locator('#cashInput, input[name*="received" i], input[name*="cash" i]').first();
  if (await cashInput.isVisible({ timeout: 2_000 }).catch(() => false)) {
    const amount = total && total > 0 ? Math.ceil(total + 0.5) : 50;
    await cashInput.fill(String(amount)).catch(() => {});
    await page.waitForTimeout(350);
  }

  const orderRespPromise = page.waitForResponse(
    (res) => {
      const url = res.url();
      const m = url.match(/\/api\/admin\/pos(\?[^/]*)?$/);
      if (!m) return false;
      return res.request().method() === 'POST';
    },
    { timeout: 25_000 },
  ).catch(() => null);

  const confirmBtn = page.locator('[data-testid="pos-payment-confirm"]').first();
  if (!(await confirmBtn.isVisible({ timeout: 4_000 }).catch(() => false))) {
    return { ok: false, reason: 'confirm_btn_not_visible', total };
  }
  const tConfirmClick = Date.now();
  await confirmBtn.click({ timeout: 5_000 }).catch(() => {});
  const resp = await orderRespPromise;
  await page.waitForTimeout(1_000);

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
  return { ok: true, total, status, orderId, tConfirmClick };
}

async function pollKdsForOrder(page, orderId, deadlineMs = 5_000) {
  // Poll the admin KDS API every 500ms until the orderId appears in payload or
  // deadline expires. Returns { found: bool, latency_ms: int|null, last_status }
  const tStart = Date.now();
  let lastStatus = null;
  while (Date.now() - tStart < deadlineMs) {
    try {
      const resp = await page.request.get('/api/admin/kds-order', { timeout: 3_000 }).catch(() => null);
      if (resp) {
        lastStatus = resp.status();
        if (resp.ok()) {
          const body = await resp.json().catch(() => ({}));
          const list = body?.data || body?.orders || body;
          const arr = Array.isArray(list) ? list : (Array.isArray(list?.data) ? list.data : []);
          if (arr.some((o) => Number(o?.id) === Number(orderId))) {
            return { found: true, latency_ms: Date.now() - tStart, last_status: lastStatus };
          }
        }
      }
    } catch (_e) { /* keep polling */ }
    await page.waitForTimeout(500);
  }
  return { found: false, latency_ms: null, last_status: lastStatus, polled_for_ms: Date.now() - tStart };
}

async function pollOssForOrder(page, orderSerialNo, deadlineMs = 3_000) {
  const tStart = Date.now();
  let lastStatus = null;
  while (Date.now() - tStart < deadlineMs) {
    try {
      const resp = await page.request.get('/api/admin/oss-order', { timeout: 3_000 }).catch(() => null);
      if (resp) {
        lastStatus = resp.status();
        if (resp.ok()) {
          const body = await resp.json().catch(() => ({}));
          const flatten = JSON.stringify(body);
          if (orderSerialNo && flatten.includes(String(orderSerialNo))) {
            return { found: true, latency_ms: Date.now() - tStart, last_status: lastStatus };
          }
        }
      }
    } catch (_e) { /* keep polling */ }
    await page.waitForTimeout(400);
  }
  return { found: false, latency_ms: null, last_status: lastStatus, polled_for_ms: Date.now() - tStart };
}

async function captureSidebarObservation(page) {
  // PosComponent.vue pills: aria-label + title carry category name (no data-cat-id).
  // Mission P0 verification = "Burgers" + "Menu enfant" aria-label present.
  const pills = page.locator('.pos-v5-category, .pos-v4-category-pill');
  const total = await pills.count();
  const out = {
    total_pills_count: total,
    pills: [],
    has_burgers_pill: false,
    has_menu_enfant_pill: false,
    aria_labels: [],
  };
  for (let i = 0; i < total; i++) {
    const p = pills.nth(i);
    const aria = await p.getAttribute('aria-label').catch(() => null);
    const title = await p.getAttribute('title').catch(() => null);
    const label = (await p.innerText().catch(() => '')).trim().slice(0, 80);
    out.pills.push({ index: i, aria_label: aria, title, label });
    if (aria) out.aria_labels.push(aria);
    if (aria === 'Burgers' && title === 'Burgers') {
      out.has_burgers_pill = true;
      out.burgers_pill = { index: i, aria_label: aria, title, label, aria_label_ok: aria.length > 0, title_ok: !!title && title.length > 0 };
    }
    if (aria === 'Menu enfant' && title === 'Menu enfant') {
      out.has_menu_enfant_pill = true;
      out.menu_enfant_pill = { index: i, aria_label: aria, title, label, aria_label_ok: aria.length > 0, title_ok: !!title && title.length > 0 };
    }
  }
  return out;
}

test.describe.configure({ mode: 'serial' });

test.describe('menu-v2-final Wave POS-CROSS — POS V4 NEW menu + KDS/OSS cross-surface', () => {
  test.setTimeout(420_000); // 7 min cap per test (rate-limit waits eat ~96s + cross-surface)

  test.beforeAll(async () => {
    dbTracking.baseline = dbBaseline();
    flushTracking();
  });

  test('00 — login admin + dashboard + POS V4 ready + sidebar observation', async ({ page }) => {
    const recAdmin = attachMegaAuditRecorder(page, SS_ADMIN);
    const recPos = attachMegaAuditRecorder(page, SS_POS);
    try {
      await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASSWORD);
      await page.waitForTimeout(1_500);
      await recAdmin.snap('00-admin-dashboard');

      await page.goto('/admin/pos-v4', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3_500);
      await expect(page).toHaveURL(/\/admin\/pos-v4/, { timeout: 20_000 });

      const grid = page.locator('.pos-v5-grid, [data-testid="pos-grand-total"]').first();
      await expect(grid).toBeVisible({ timeout: 15_000 });

      // Optional cash drawer open — best-effort.
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
      await page.waitForTimeout(1_200);
      await recPos.snap('00-pos-v4-ready');

      // SIDEBAR OBSERVATION (advisor #2 — observe-don't-assert).
      const sidebar = await captureSidebarObservation(page);
      dbTracking.sidebar = sidebar;
      flushTracking();

      // Hard assertion ONLY on the two NEW cat pills (P0 NEW-menu signal).
      // PosComponent template emits aria-label + title === category.name.
      // [WB-R1-02 verified] both attributes required for screen-reader parity.
      expect(sidebar.has_burgers_pill, 'Burgers pill (aria-label="Burgers", title="Burgers") must render in POS V4 sidebar').toBe(true);
      expect(sidebar.has_menu_enfant_pill, 'Menu enfant pill (aria-label="Menu enfant", title="Menu enfant") must render in POS V4 sidebar').toBe(true);

      await recPos.snap('00b-pos-v4-sidebar-12cat-observation');
    } finally {
      recAdmin.dispose();
      recPos.dispose();
    }
  });

  for (let scIdx = 0; scIdx < SCENARIOS.length; scIdx++) {
    const sc = SCENARIOS[scIdx];
    test(`${sc.code} — ${sc.label}`, async ({ page }) => {
      const recPos = attachMegaAuditRecorder(page, SS_POS);
      const recKds = attachMegaAuditRecorder(page, SS_KDS);
      const recOss = attachMegaAuditRecorder(page, SS_OSS);

      const scenarioEntry = {
        code: sc.code,
        label: sc.label,
        target_item_id: sc.itemId,
        target_item_name: sc.itemName,
        target_cat_id: sc.catId,
        target_cat_name: sc.catName,
        expected_price: sc.expectedPrice,
        started_at: new Date().toISOString(),
      };
      dbTracking.scenarios.push(scenarioEntry);

      try {
        // Rate-limit hygiene — clear + 32s wait between scenarios (sliding-window
        // safety per advisor #3). First scenario only does the clear (no wait).
        try { clearFoodKingRateLimits(); } catch (_e) { /* soft */ }
        if (scIdx > 0) {
          // 32s sliding-window safety guard for admin-mutation 30/min limiter.
          await page.waitForTimeout(32_000);
          try { clearFoodKingRateLimits(); } catch (_e) { /* soft */ }
        }

        await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASSWORD);
        await page.waitForTimeout(1_200);
        await page.goto('/admin/pos-v4', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2_500);
        await expect(page).toHaveURL(/\/admin\/pos-v4/, { timeout: 15_000 });

        await recPos.snap(`${sc.code}-01-pos-empty`);

        // Open wizard for the target NEW item.
        const click = await clickPosV4TileForItem(page, sc);
        scenarioEntry.tile_click = click;
        flushTracking();
        if (!click.ok) {
          await recPos.snap(`${sc.code}-02-wizard-popup-NOT-OPENED`);
          throw new Error(`${sc.code}: tile not visible for itemId=${sc.itemId} (cat=${sc.catId}) — NEW item missing in POS V4 grid`);
        }
        await page.waitForTimeout(1_500);
        await recPos.snap(`${sc.code}-02-wizard-popup`);

        await finalizeWizardIfOpen(page);
        await page.waitForTimeout(700);
        await recPos.snap(`${sc.code}-03-cart-with-item`);

        const payRes = await payCashAndConfirm(page, recPos.snap, sc.code);
        await recPos.snap(`${sc.code}-05-receipt`);
        scenarioEntry.pay_response = {
          status: payRes.status,
          orderId: payRes.orderId,
          read_total: payRes.total,
          reason: payRes.reason || null,
        };
        flushTracking();

        // Cross-surface latency capture: clock starts BEFORE confirmBtn click,
        // so we measure click→propagation, which is the only honest signal.
        const tConfirmClick = payRes.tConfirmClick;
        await page.waitForTimeout(800);

        // DB check — latest order (best signal of fiscal state).
        const dbRow = await dbCheckLatestOrder();
        scenarioEntry.db = dbRow;
        flushTracking();

        // Resolve real order id from DB if API didn't give it.
        const resolvedOrderId = payRes.orderId || dbRow?.id || null;
        const resolvedSerial = dbRow?.order_serial_no || dbRow?.token || null;

        if (resolvedOrderId && payRes.status && payRes.status < 400) {
          // KDS poll.
          const kds = await pollKdsForOrder(page, resolvedOrderId, 5_000);
          scenarioEntry.kds_poll = {
            ...kds,
            api_latency_ms: kds.latency_ms,
            click_to_api_latency_ms: tConfirmClick ? (Date.now() - tConfirmClick) : null,
          };
          flushTracking();

          // KDS UI capture.
          await page.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });
          await page.waitForTimeout(3_000);
          await recKds.snap(`${sc.code}-kds-01-board`);

          // Try to spot the order card.
          const card = page.locator(`[data-order-id="${resolvedOrderId}"], [data-id="${resolvedOrderId}"]`).first();
          const cardVisible = await card.isVisible({ timeout: 4_000 }).catch(() => false);
          scenarioEntry.kds_ui_card_visible = cardVisible;
          if (cardVisible) {
            await card.scrollIntoViewIfNeeded().catch(() => {});
            await page.waitForTimeout(500);
            await recKds.snap(`${sc.code}-kds-02-card-focus`);
          }

          // OSS poll + UI.
          const oss = await pollOssForOrder(page, resolvedSerial, 3_000);
          scenarioEntry.oss_poll = oss;
          flushTracking();

          await page.goto('/order-status-screen', { waitUntil: 'domcontentloaded' });
          await page.waitForTimeout(3_500);
          await recOss.snap(`${sc.code}-oss-01-screen`);
          const ossText = await page.locator('body').innerText().catch(() => '');
          scenarioEntry.oss_ui_serial_found = resolvedSerial ? ossText.includes(String(resolvedSerial)) : null;
          flushTracking();
        } else {
          scenarioEntry.kds_poll = { skipped: true, reason: 'no_resolved_order_id_or_http_error' };
          scenarioEntry.oss_poll = { skipped: true, reason: 'no_resolved_order_id_or_http_error' };
          flushTracking();
        }

        // Sanity check: no fatal error in body.
        const text = await page.locator('body').innerText().catch(() => '');
        expect(text, `${sc.code} body should not contain Whoops/Fatal`).not.toMatch(/Whoops|Fatal error|Server Error/i);

        scenarioEntry.finished_at = new Date().toISOString();
        flushTracking();
      } catch (err) {
        scenarioEntry.error = String(err?.message || err).substring(0, 600);
        flushTracking();
        await recPos.snap(`${sc.code}-99-error-state`);
        throw err;
      } finally {
        recPos.dispose();
        recKds.dispose();
        recOss.dispose();
      }
    });
  }

  test('ZZ — admin items search + categories list', async ({ page }) => {
    const recAdmin = attachMegaAuditRecorder(page, SS_ADMIN);
    try {
      // Cool down before admin searches (the prior 3 scenarios may have drained
      // pos-order-create + admin-mutation buckets).
      try { clearFoodKingRateLimits(); } catch (_e) { /* soft */ }
      await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASSWORD);
      await page.waitForTimeout(1_200);

      // STATE — /admin/items (Burger search).
      await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3_000);
      await recAdmin.snap('ZZ-01-admin-items-default');

      const searchInput = page.locator('input[type="search"], input[placeholder*="Search" i], input[placeholder*="Recherche" i], input[name*="search" i]').first();
      const burgerSearch = await dbItemSearch('Burger');
      dbTracking.admin_searches.burger = burgerSearch;
      flushTracking();

      if (await searchInput.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await searchInput.fill('Burger').catch(() => {});
        await page.waitForTimeout(1_800);
        await recAdmin.snap('ZZ-02-admin-items-search-Burger');
        await searchInput.fill('').catch(() => {});
        await page.waitForTimeout(900);
        await searchInput.fill('Bowl Frites').catch(() => {});
        await page.waitForTimeout(1_800);
        await recAdmin.snap('ZZ-03-admin-items-search-BowlFrites');
        await searchInput.fill('').catch(() => {});
        await page.waitForTimeout(900);
        await searchInput.fill('Big Cayenne').catch(() => {});
        await page.waitForTimeout(1_800);
        await recAdmin.snap('ZZ-04-admin-items-search-BigCayenne');
      }

      dbTracking.admin_searches.bowl_frites = await dbItemSearch('Bowl Frites');
      dbTracking.admin_searches.big_cayenne = await dbItemSearch('Big Cayenne');
      dbTracking.admin_searches.big_chicken = await dbItemSearch('Big Chicken');
      flushTracking();

      // STATE — admin item-categories list (canonical SPA route).
      await page.goto('/admin/settings/item-categories/list', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3_500);
      await recAdmin.snap('ZZ-05-admin-categories-list');

      // DB check — categories canonical state.
      const catsState = runTinker(`
        use Illuminate\\Support\\Facades\\DB;
        $rows = DB::table('item_categories')->where('status', 5)->orderBy('sort')->select('id','name','sort','status','wizard_template','has_menu')->get()->toArray();
        echo json_encode([
          'active_count' => count($rows),
          'has_349_burgers' => DB::table('item_categories')->where('id', 349)->where('status', 5)->exists(),
          'has_350_menu_enfant' => DB::table('item_categories')->where('id', 350)->where('status', 5)->exists(),
          'sample' => array_slice(array_map(fn(\$r) => (array) \$r, $rows), 0, 22),
        ]);
      `);
      dbTracking.categories_state = catsState;
      flushTracking();

      expect(catsState.has_349_burgers, 'DB cat 349 Burgers must be active').toBe(true);
      expect(catsState.has_350_menu_enfant, 'DB cat 350 Menu enfant must be active').toBe(true);

      // Bowl Frites: expect 4 (P0 from mission).
      const bf = dbTracking.admin_searches.bowl_frites;
      expect(Array.isArray(bf) ? bf.length : 0, 'DB items LIKE "Bowl Frites" must return 4 rows (heal-light V2 invariant)').toBe(4);
    } finally {
      recAdmin.dispose();
    }
  });

  test.afterAll(async () => {
    dbTracking.finished_at = new Date().toISOString();
    dbTracking.final_baseline = dbBaseline();
    flushTracking();
  });
});
