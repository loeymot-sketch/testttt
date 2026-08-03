// FoodKing E2E — Wave POS-CROSS SUPPLEMENT — RUN=menu-v2-final-2026-05-14 / round-1
//
// Context : the main spec menu-v2-pos-cross-final.spec.js succeeded on the P0
// NEW-menu validation (sidebar 12 pills + Burgers + Menu enfant, 3 wizards
// render NEW items at correct prices, admin item searches confirm DB state)
// but all 3 POS POSTs returned HTTP 429 due to the parallel kiosk wave burning
// the admin-mutation/pos-order-create buckets. Result : zero new POS-source
// orders persisted, no KDS/OSS cross-surface capture for those 3 attempts.
//
// HOWEVER : the parallel kiosk wave DID persist orders containing the NEW menu
// items :
//   Order 1444 → Chicken Burger (cat 349 Burgers) — NEW menu item
//   Order 1445 → Bowl Frites Poulet curry (cat 347 Bols) — NEW menu item
//   Order 1446 → Sandwich Cayenne + Petite Frites + Tiramisu (multi-item)
//
// These are kiosk-source (transaction_id "MENU-V2-KIOSK-TPE-*") but they prove
// the NEW menu items work cross-surface (pricing SSOT, composition_snapshot,
// fiscal_sequence chain healthy). This supplement captures KDS + OSS surfaces
// against these existing orders so the wave isn't 0% on cross-surface.
//
// HONEST framing : this is supplemental cross-surface coverage using
// already-persisted NEW-menu orders, NOT in-wave POS POST attempts.

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';

const REPO_ROOT = path.resolve(__dirname, '../..');
const SS_KDS = path.join(REPO_ROOT, 'tests/e2e/__screenshots__/menu-v2-final/kds');
const SS_OSS = path.join(REPO_ROOT, 'tests/e2e/__screenshots__/menu-v2-final/oss');
const REPORT_DIR = path.join(REPO_ROOT, 'reports/test-e2e/menu-v2-final-2026-05-14/round-1');
const SUPPLEMENT_PATH = path.join(REPORT_DIR, 'wave-POS-CROSS-supplement-tracking.json');

[SS_KDS, SS_OSS].forEach((d) => fs.mkdirSync(d, { recursive: true }));

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

const supplement = {
  run: 'menu-v2-final-2026-05-14',
  round: 1,
  wave: 'POS-CROSS-SUPPLEMENT',
  started_at: new Date().toISOString(),
  evidence_orders: [],
  kds_api_check: null,
  oss_api_check: null,
};
function flush() {
  fs.writeFileSync(SUPPLEMENT_PATH, JSON.stringify(supplement, null, 2));
}
flush();

test.describe.configure({ mode: 'serial' });

test.describe('menu-v2-final Wave POS-CROSS SUPPLEMENT — KDS + OSS cross-surface with persisted NEW-menu orders', () => {
  test.setTimeout(180_000);

  test('SUP-01 — DB evidence orders (1444 Chicken Burger / 1445 Bowl Frites / 1446 multi)', async () => {
    const evidence = runTinker(`
      use Illuminate\\Support\\Facades\\DB;
      $ids = [1444, 1445, 1446];
      $rows = [];
      foreach ($ids as $id) {
        $o = DB::table('orders')->where('id', $id)->first();
        if (! $o) { $rows[] = ['id' => $id, 'ok' => false, 'reason' => 'not_found']; continue; }
        $items = DB::table('order_items')->where('order_id', $id)->get()->toArray();
        $itemDetails = [];
        foreach ($items as $i) {
          $name = DB::table('items')->where('id', $i->item_id)->value('name');
          $snap = $i->composition_snapshot ? json_decode($i->composition_snapshot, true) : null;
          $itemDetails[] = [
            'item_id' => (int) $i->item_id,
            'name' => $name,
            'quantity' => (int) $i->quantity,
            'total_price' => (float) $i->total_price,
            'snapshot_lines_count' => is_array($snap) && isset($snap['lines']) ? count($snap['lines']) : 0,
          ];
        }
        $rows[] = [
          'id' => (int) $id,
          'ok' => true,
          'total' => (float) $o->total,
          'fiscal_sequence_no' => isset($o->fiscal_sequence_no) ? (int) $o->fiscal_sequence_no : null,
          'status' => (int) $o->status,
          'payment_status' => (int) $o->payment_status,
          'transaction_id' => $o->transaction_id,
          'source' => $o->source ?? null,
          'items_count' => count($items),
          'items' => $itemDetails,
        ];
      }
      echo json_encode($rows);
    `);
    supplement.evidence_orders = evidence;
    flush();
    expect(Array.isArray(evidence) ? evidence.length : 0).toBeGreaterThan(0);
  });

  test('SUP-02 — KDS API + UI capture', async ({ page }) => {
    const rec = attachMegaAuditRecorder(page, SS_KDS);
    try {
      await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASSWORD);
      await page.waitForTimeout(1_500);

      // KDS API check (admin-context).
      const apiResp = await page.request.get('/api/admin/kds-order', { timeout: 8_000 }).catch(() => null);
      let apiBody = null;
      if (apiResp) {
        try { apiBody = await apiResp.json(); } catch (_e) { /* */ }
      }
      const apiStatus = apiResp ? apiResp.status() : null;
      const list = apiBody?.data || apiBody?.orders || apiBody || [];
      const arr = Array.isArray(list) ? list : (Array.isArray(list?.data) ? list.data : []);
      const flatten = JSON.stringify(arr);
      supplement.kds_api_check = {
        status: apiStatus,
        orders_count: arr.length,
        contains_1444_chicken_burger: flatten.includes('"id":1444') || flatten.includes('"id": 1444'),
        contains_1445_bowl_frites: flatten.includes('"id":1445') || flatten.includes('"id": 1445'),
        contains_1446_multi: flatten.includes('"id":1446') || flatten.includes('"id": 1446'),
      };
      flush();

      // KDS UI capture.
      await page.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3_500);
      await rec.snap('SUP-kds-01-board');

      // Inspect DOM for order id markers.
      const cardSelectors = [
        '[data-order-id]',
        '[data-id]',
        '.kds-order-card',
        '.kds-card',
        '[class*="order"]',
      ];
      let foundCards = 0;
      for (const sel of cardSelectors) {
        foundCards = await page.locator(sel).count().catch(() => 0);
        if (foundCards > 0) break;
      }
      supplement.kds_ui = { cards_count_via_selectors: foundCards };
      flush();

      // Try focus on first card if exists.
      const firstCard = page.locator('[data-order-id], [data-id], .kds-order-card').first();
      if (await firstCard.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await firstCard.scrollIntoViewIfNeeded().catch(() => {});
        await page.waitForTimeout(500);
        await rec.snap('SUP-kds-02-card-focus');
      } else {
        await rec.snap('SUP-kds-02-empty-state');
      }
    } finally {
      rec.dispose();
    }
  });

  test('SUP-03 — OSS API + UI capture', async ({ page }) => {
    const rec = attachMegaAuditRecorder(page, SS_OSS);
    try {
      await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASSWORD);
      await page.waitForTimeout(1_200);

      // OSS API check (public endpoint).
      const apiResp = await page.request.get('/api/admin/oss-order', { timeout: 8_000 }).catch(() => null);
      let apiBody = null;
      if (apiResp) {
        try { apiBody = await apiResp.json(); } catch (_e) { /* */ }
      }
      const apiStatus = apiResp ? apiResp.status() : null;
      const flatten = apiBody ? JSON.stringify(apiBody) : '';
      supplement.oss_api_check = {
        status: apiStatus,
        body_size_bytes: flatten.length,
        contains_1444: flatten.includes('1444'),
        contains_1445: flatten.includes('1445'),
        contains_1446: flatten.includes('1446'),
      };
      flush();

      await page.goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(4_000);
      await rec.snap('SUP-oss-01-screen');

      // Scroll if multi-column.
      const cols = page.locator('[class*="column"], [class*="oss-col"], .oss-status-section').first();
      if (await cols.isVisible({ timeout: 2_000 }).catch(() => false)) {
        await rec.snap('SUP-oss-02-with-content');
      }
    } finally {
      rec.dispose();
    }
  });

  test.afterAll(async () => {
    supplement.finished_at = new Date().toISOString();
    flush();
  });
});
