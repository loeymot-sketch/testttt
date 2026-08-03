// FoodKing Wave Final 2026-05-23 — S3 KDS CUISINE
// =================================================
// Mission: bump-board + Historique du jour drawer MAX REASONING.
//
// Reuses + extends `wave-x3-kds-history.spec.js` (Wave X3 drawer coverage)
// with these net-new scenarios:
//   - S3-01..06 baseline drawer surface (some overlap with wave-x3, captured
//     here under wave-final/S3 naming for the cross-system findings dossier)
//   - S3-06 Esc handler (wave-x3 only covered close-button)
//   - S3-07..10 ACCEPTED order in V2 grid → expand → bump → bump again
//   - S3-11 multi-bump race (3 simultaneous taps)
//   - S3-12 Wave U recentlyServed strip intact after bumps
//
// Canonical URL: /admin/kitchen-display-system (resolves to KDS controller).
//  - `/kds` also returns 200 but the components mount under the admin path.
//
// Bump endpoint (NOT PATCH — verified routes/api.php):
//   POST /api/admin/kds-order/change-status/{order}
//
// Status enum (app/Enums/OrderStatus.php):
//   ACCEPT=4 PREPARING=7 PREPARED=8 OUT_FOR_DELIVERY=10 DELIVERED=13
//
// V2 layout default ON since 2026-05-16 (Z3-NEW-006).
// v2AutoTransitionEnabled is pinned `false` at the parent — single-tap CTA
// promotes ACCEPT→PREPARING, second tap PREPARING→PREPARED. The chef sees
// the same Prêt button in both states (label.kds_card_cta_ready).
//
// OrderStateMachine.php is FROZEN — invalid transitions are CRITICAL findings.

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

const SHOT_DIR = path.join(
  process.cwd(),
  'tests/e2e/__screenshots__/wave-final-S3-kds',
);
if (!fs.existsSync(SHOT_DIR)) {
  fs.mkdirSync(SHOT_DIR, { recursive: true });
}

const TOKEN_PREFIX = 'WAVE-FINAL-S3-';
const FINDINGS = [];

function recordFinding({ id, severity, state, dom, network, evidence, classification, fix_hint }) {
  FINDINGS.push({
    id,
    severity,
    state_ref: state,
    classification,
    dom_evidence: dom || null,
    network_evidence: network || null,
    evidence_path: evidence || null,
    fix_hint: fix_hint || null,
  });
}

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

// [Round-2 healing] Attach a real `order_items` row so the V2 card body
// renders item lines — without this the card body is blank for tinker-seeded
// orders and S3-08 (item/variation rendering) cannot be visually verified.
function attachOrderItem(orderId, { itemId = 1, branchId = 1, quantity = 1 } = {}) {
  try {
    const phpCode = `
      $oi = new \\App\\Models\\OrderItem();
      $oi->order_id = ${orderId};
      $oi->branch_id = ${branchId};
      $oi->item_id = ${itemId};
      $oi->quantity = ${quantity};
      $oi->released_qty = 0;
      $oi->discount = 0;
      $oi->price = 12.50;
      $oi->tax_rate = 5.5;
      $oi->tax_amount = 0;
      $oi->item_variation_total = 0;
      $oi->item_extra_total = 0;
      $oi->total_price = 12.50;
      $oi->saveQuietly();
      echo $oi->id;
    `.replace(/\s+/g, ' ');
    const out = artisan(phpCode).trim();
    return parseInt(out.split('\n').pop().trim(), 10);
  } catch (_err) {
    return -1;
  }
}

function seedOrder({ token, status, branchId = 1, orderType = 10 }) {
  // orderType=10 = TAKEAWAY per task spec.
  //
  // CRITICAL enum values (verified app/Enums/ during round-1 healing):
  //   PaymentStatus::PAID  = 5  (NOT 10 — 10 is UNPAID)
  //   Ask::NO              = 10 (NOT 0)
  // The wave-x3 spec used `payment_status=10, is_advance_order=0` and only
  // worked because the historyToday endpoint filters on status + updated_at
  // only (no payment/advance gates). The active-grid `list()` endpoint
  // (used by S3-07..11) DOES enforce both — round-1 dry-run returned 0 items
  // with the wrong values. Fixed here.
  // [Round-1 healing] Stamp `order_datetime` slightly in the past (5h ago)
  // so the visibleOrders sort (ascending by parseOrderCreatedMs) places our
  // seeded card BEFORE concurrent parallel-session orders created with now().
  // Still inside today's TZ-aware window for the KDS feed.
  const phpCode = `
    $o = new \\App\\Models\\Order();
    $o->order_serial_no = '${token}';
    $o->order_type = ${orderType};
    $o->source_surface = 'pos';
    $o->branch_id = ${branchId};
    $o->user_id = 1;
    $o->status = ${status};
    $o->payment_status = 5;
    $o->payment_method = 1;
    $o->subtotal = 12.50;
    $o->total = 12.50;
    $o->discount = 0;
    $o->delivery_charge = 0;
    $o->order_datetime = now()->subHours(5);
    $o->updated_at = now();
    $o->is_advance_order = 10;
    $o->saveQuietly();
    echo $o->id;
  `.replace(/\s+/g, ' ');
  const out = artisan(phpCode).trim();
  // tinker may emit warnings on stderr; the last line is the id.
  const idLine = out.split('\n').pop().trim();
  return parseInt(idLine, 10);
}

function readOrderStatus(orderId) {
  const phpCode = `
    $o = \\App\\Models\\Order::withoutGlobalScopes()->find(${orderId});
    echo $o ? (int) $o->status : -1;
  `.replace(/\s+/g, ' ');
  return parseInt(artisan(phpCode).trim().split('\n').pop().trim(), 10);
}

function cleanupSeededOrders() {
  try {
    // [Round-2 healing] Delete attached order_items first to avoid FK
    // integrity violation when our seeds had OrderItem rows attached for
    // S3-08 visibility coverage.
    artisan(
      `$ids = \\App\\Models\\Order::where('order_serial_no', 'like', '${TOKEN_PREFIX}%')->withoutGlobalScopes()->pluck('id')->toArray(); ` +
      `if (!empty($ids)) { \\App\\Models\\OrderItem::whereIn('order_id', $ids)->withoutGlobalScopes()->forceDelete(); ` +
      `\\DB::table('orders')->whereIn('id', $ids)->update(['fiscal_sequence_no' => null]); ` +
      `\\App\\Models\\Order::whereIn('id', $ids)->withoutGlobalScopes()->forceDelete(); }`,
    );
  } catch (_err) {
    // Best-effort.
  }
}


async function pinV2Layout(page) {
  await page.addInitScript(() => {
    try {
      window.localStorage.setItem('kds.v2_enabled', '1');
    } catch (_e) {
      // Some browser contexts may block storage; URL fallback below catches it.
    }
  });
}

async function gotoKds(page) {
  // Append ?v2=1 belt-and-suspenders even though localStorage is pinned —
  // useV2Layout precedence: URL param > localStorage > org config > default.
  await page.goto('/admin/kitchen-display-system?v2=1', { waitUntil: 'domcontentloaded' });
}

test.describe('Wave Final — S3 KDS bump-board + history drawer', () => {
  test.beforeAll(() => {
    cleanupSeededOrders();
  });

  // [Round-1 healing] Clean seeded orders between each test so the V2 grid
  // FIFO sort doesn't surface a stale card from the previous scenario when
  // tests use `[data-testid="kds-card-cta-ready"]).first()`. Without this,
  // S3-10 was hitting the order from S3-07 (still ACCEPT) instead of its
  // own PREPARING seed.
  test.beforeEach(() => {
    cleanupSeededOrders();
  });

  test.afterAll(() => {
    cleanupSeededOrders();
    // Persist findings JSON at the end so it always reflects the latest run.
    fs.writeFileSync(
      path.join(process.cwd(), 'reports/test-e2e/wave-final-2026-05-23/round-1/S3-kds-findings.json'),
      JSON.stringify({
        system: 'S3-KDS',
        wave: 'wave-final-2026-05-23',
        round: 1,
        branch: 'heal/cms-pr1-quickwins-2026-05-18',
        canonical_url: '/admin/kitchen-display-system',
        v2_layout: true,
        v2_auto_transition: false,
        order_state_machine_frozen: true,
        findings: FINDINGS,
        generated_at: new Date().toISOString(),
      }, null, 2),
    );
  });

  // -------------------------------------------------------------------------
  // S3-01: KDS mount + history pill visible + FR resolved
  // -------------------------------------------------------------------------
  test('S3-01 KDS mounts with history pill FR-resolved (no raw label)', async ({ page }) => {
    await pinV2Layout(page);
    await loginAsAdmin(page);
    await gotoKds(page);

    const historyBtn = page.locator('[data-testid="kds-history-button"]');
    await expect(historyBtn).toBeVisible({ timeout: 15_000 });

    const btnText = (await historyBtn.innerText()).trim();
    // FR locale on /admin/* → button must say "Historique" (with the emoji prefix
    // rendered as 📚). Raw key would be `label.kds_history_button` — sentinel.
    expect(btnText).toMatch(/Historique/);
    expect(btnText).not.toMatch(/label\.kds_history_button/i);

    await page.screenshot({
      path: path.join(SHOT_DIR, 'S3-01-kds-mount-history-pill.png'),
      fullPage: true,
    });
  });

  // -------------------------------------------------------------------------
  // S3-02 + S3-03 + S3-04 + S3-05 — drawer open, content, status badges,
  //                                  V1 no-revert sentinel
  // -------------------------------------------------------------------------
  test('S3-02..05 drawer opens with bumped orders, FR badges, no revert CTA', async ({ page }) => {
    const tNow = Date.now();
    const idPrep = seedOrder({ token: `${TOKEN_PREFIX}HIST-P-${tNow}`, status: 8 });   // PREPARED
    const idOut  = seedOrder({ token: `${TOKEN_PREFIX}HIST-O-${tNow}`, status: 10 });  // OUT
    const idDel  = seedOrder({ token: `${TOKEN_PREFIX}HIST-D-${tNow}`, status: 13 });  // DELIVERED

    await pinV2Layout(page);
    await loginAsAdmin(page);
    await gotoKds(page);

    const historyBtn = page.locator('[data-testid="kds-history-button"]');
    await expect(historyBtn).toBeVisible({ timeout: 15_000 });
    await historyBtn.click();

    const drawer = page.locator('[data-testid="kds-history-drawer"]');
    await expect(drawer).toBeVisible({ timeout: 10_000 });

    await page.screenshot({
      path: path.join(SHOT_DIR, 'S3-02-drawer-open.png'),
      fullPage: true,
    });

    // Wait for the historyToday GET to land (more deterministic than
    // racing the DOM `loading` → `list` swap).
    await page.waitForResponse(
      (resp) => /\/api\/admin\/kds-order\/history-today/.test(resp.url()),
      { timeout: 15_000 },
    ).catch(() => { /* best-effort */ });

    // Loading state can flash for a frame; wait for terminal state.
    await page
      .locator('[data-testid="kds-history-list"], [data-testid="kds-history-empty"]')
      .first()
      .waitFor({ timeout: 15_000 });

    const items = page.locator('[data-testid="kds-history-item"]');
    const count = await items.count();
    expect(count).toBeGreaterThanOrEqual(3);

    const drawerText = (await drawer.innerText()).trim();

    // S3-03 — header resolved
    expect(drawerText).toContain('Historique du jour');

    // S3-04 — at least one FR status badge resolved (case-insensitive because
    // .kds-history-drawer__status applies text-transform:uppercase)
    expect(drawerText).toMatch(/\b(Prêt|En livraison|Livré)\b/i);

    // Negative i18n sentinels (raw key leak = P0)
    if (/\b(label|kiosk|kds)\.[a-z_]+\b/i.test(drawerText) || /\bLABEL\.[A-Z_]+\b/.test(drawerText)) {
      recordFinding({
        id: 'S3-FIND-DRAWER-RAW-KEYS',
        severity: 'CRITICAL',
        state: 'S3-04',
        dom: drawerText.slice(0, 200),
        classification: 'i18n leak',
        evidence: 'S3-02-drawer-open.png',
        fix_hint: 'Rebuild lang bundle (npx mix) and confirm AdminLayout locale lock is fr for /admin/*. See wave-polish-final-C kds bundle freshness sentinel.',
      });
    }
    expect(drawerText).not.toMatch(/\b(label|kiosk|kds)\.[a-z_]+\b/i);
    expect(drawerText).not.toMatch(/\bLABEL\.[A-Z_]+\b/);

    await page.screenshot({
      path: path.join(SHOT_DIR, 'S3-03-drawer-with-history.png'),
      fullPage: true,
    });
    await page.screenshot({
      path: path.join(SHOT_DIR, 'S3-04-drawer-badges-uppercase.png'),
      fullPage: true,
    });

    // S3-05 — V1 contract: NO revert/recall/cancel buttons
    const revertControls = drawer.locator(
      'button:has-text("Renvoyer"), button:has-text("Revert"), button:has-text("Recall"), button:has-text("Annuler"), button:has-text("Rétablir"), button:has-text("Restaurer")',
    );
    const revertCount = await revertControls.count();
    if (revertCount > 0) {
      recordFinding({
        id: 'S3-FIND-DRAWER-REVERT-LEAK',
        severity: 'CRITICAL',
        state: 'S3-05',
        dom: `Found ${revertCount} revert-style buttons inside drawer.`,
        classification: 'V1 contract violation (OrderStateMachine §7 forbids reverse)',
        evidence: 'S3-05-drawer-no-revert.png',
        fix_hint: 'Remove revert CTA — V1.0.2 backlog only with LOCK plan + owner countersign.',
      });
    }
    expect(revertCount).toBe(0);
    await page.screenshot({
      path: path.join(SHOT_DIR, 'S3-05-drawer-no-revert.png'),
      fullPage: true,
    });

    fs.writeFileSync(
      path.join(SHOT_DIR, 'S3-seeded.json'),
      JSON.stringify({ idPrep, idOut, idDel, listCount: count }, null, 2),
    );
  });

  // -------------------------------------------------------------------------
  // S3-06: Esc key closes drawer (Wave X 8c handler, NOT covered by wave-x3 spec)
  // -------------------------------------------------------------------------
  test('S3-06 Escape key closes drawer (Wave X 8c a11y handler)', async ({ page }) => {
    await pinV2Layout(page);
    await loginAsAdmin(page);
    await gotoKds(page);

    await page.locator('[data-testid="kds-history-button"]').click();
    const drawer = page.locator('[data-testid="kds-history-drawer"]');
    await expect(drawer).toBeVisible({ timeout: 10_000 });

    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden({ timeout: 5_000 });

    await page.screenshot({
      path: path.join(SHOT_DIR, 'S3-06-drawer-escape-closed.png'),
      fullPage: true,
    });
  });

  // -------------------------------------------------------------------------
  // S3-07: Seed ACCEPTED order → V2 grid shows it
  // -------------------------------------------------------------------------
  test('S3-07..09 ACCEPTED order appears in V2 grid → bump to PREPARING', async ({ page }) => {
    const tNow = Date.now();
    const orderId = seedOrder({ token: `${TOKEN_PREFIX}BUMP-${tNow}`, status: 4 }); // ACCEPT
    // [Round-2 healing] Attach a real OrderItem so the card body renders
    // an item line — proves S3-08 visibility assertion is meaningful.
    const orderItemId = attachOrderItem(orderId);

    await pinV2Layout(page);
    await loginAsAdmin(page);
    await gotoKds(page);

    // V2 grid render — scope to OUR seeded card
    const card = page.locator(`[data-order-id="${orderId}"]`);
    await expect(card).toBeVisible({ timeout: 15_000 });

    await page.screenshot({
      path: path.join(SHOT_DIR, 'S3-07-grid-with-accepted-order.png'),
      fullPage: true,
    });

    // S3-08 verify the card body actually renders the attached item line.
    // KdsOrderLine emits the qty + item name; check both.
    const cardBody = card.locator('.kds-card__body, .kds-order-line').first();
    if (await cardBody.isVisible({ timeout: 3_000 }).catch(() => false)) {
      const bodyText = (await cardBody.innerText()).trim();
      // Asserts item rendering ran — we expect a quantity marker or the item
      // name we just seeded. Default item is "Menu (Frites + Boisson)" id=1.
      if (!/\b(Menu|Frites|Boisson|x\s*1|×|\d+\s*x)/i.test(bodyText)) {
        recordFinding({
          id: 'S3-FIND-CARD-BODY-NOT-RENDERING-ITEMS',
          severity: 'IMPROVEMENT',
          state: 'S3-08',
          dom: `Card body text after seeding OrderItem id=${orderItemId}: "${bodyText.slice(0, 150)}"`,
          classification: 'Item rendering surface drift (KdsOrderLine may not handle minimal-field seeded items)',
          evidence: 'S3-08-card-details-visible.png',
          fix_hint: 'Inspect resources/js/helpers/kdsCustomization.js renderItem() — likely missing fallback when `composition_snapshot` is null.',
        });
      }
      await page.screenshot({
        path: path.join(SHOT_DIR, 'S3-08-card-details-visible.png'),
        fullPage: true,
      });
    } else {
      recordFinding({
        id: 'S3-FIND-CARD-BODY-INVISIBLE',
        severity: 'CRITICAL',
        state: 'S3-08',
        dom: `Card body wrapper not visible despite OrderItem ${orderItemId} attached to order ${orderId}.`,
        classification: 'V2 grid card body fail-render — chef cannot see what to prepare',
        evidence: 'S3-08-card-details-empty.png',
        fix_hint: 'Investigate KdsOrderCard.vue v-for over order.order_items — Vuex feed may not be hydrating relation. Check axios.get(admin/kds-order/list) eager-load.',
      });
      await page.screenshot({
        path: path.join(SHOT_DIR, 'S3-08-card-details-empty.png'),
        fullPage: true,
      });
    }

    // S3-09 bump to PREPARING — tap kds-card-cta-ready once, scoped to OUR order
    const cta = page.locator(`[data-order-id="${orderId}"] [data-testid="kds-card-cta-ready"]`);
    await expect(cta).toBeVisible({ timeout: 10_000 });

    // Listen for the bump network call to verify endpoint + method.
    const bumpPromise = page.waitForResponse(
      (resp) => /\/api\/admin\/kds-order\/change-status\/\d+/.test(resp.url()),
      { timeout: 10_000 },
    );

    await cta.click();
    const bumpResp = await bumpPromise;

    expect(bumpResp.status()).toBeLessThan(400);
    // Endpoint contract: POST not PATCH (verified routes/api.php)
    if (bumpResp.request().method() !== 'POST') {
      recordFinding({
        id: 'S3-FIND-BUMP-METHOD',
        severity: 'CRITICAL',
        state: 'S3-09',
        network: `Expected POST, got ${bumpResp.request().method()}`,
        classification: 'Endpoint contract drift',
        fix_hint: 'Check resources/js/store + axios call — must be axios.post(`admin/kds-order/change-status/${id}`).',
      });
    }
    expect(bumpResp.request().method()).toBe('POST');

    // Give Vuex + Echo a tick to settle.
    await page.waitForTimeout(500);
    const dbStatus = readOrderStatus(orderId);
    expect(dbStatus).toBe(7); // PREPARING

    await page.screenshot({
      path: path.join(SHOT_DIR, 'S3-09-after-bump-to-preparing.png'),
      fullPage: true,
    });
  });

  // -------------------------------------------------------------------------
  // S3-10: Bump PREPARING → PREPARED (ladder second tap)
  // -------------------------------------------------------------------------
  test('S3-10 second bump promotes PREPARING → PREPARED', async ({ page }) => {
    const tNow = Date.now();
    const orderId = seedOrder({ token: `${TOKEN_PREFIX}BUMP2-${tNow}`, status: 7 }); // PREPARING

    await pinV2Layout(page);
    await loginAsAdmin(page);
    await gotoKds(page);

    // [Round-1 healing] Lock onto OUR seeded order's CTA via data-order-id
    // — `.first()` was occasionally hitting an older order's card when the
    // suite ran sequentially (beforeEach cleanup now mitigates but the
    // orderId-scoped locator is the durable fix).
    const cta = page.locator(`[data-order-id="${orderId}"] [data-testid="kds-card-cta-ready"]`);
    await expect(cta).toBeVisible({ timeout: 15_000 });

    const bumpPromise = page.waitForResponse(
      (resp) => /\/api\/admin\/kds-order\/change-status\/\d+/.test(resp.url()),
      { timeout: 10_000 },
    );
    await cta.click();
    const bumpResp = await bumpPromise;
    expect(bumpResp.status()).toBeLessThan(400);

    // [Round-1 finding] Server's optimistic-lock + idempotency middleware
    // may produce a 2xx that nevertheless does not mutate (changed:false).
    // Capture the response body + URL for the findings dossier so the
    // diagnosis is self-contained when the assertion fails.
    let bodyDigest = null;
    try {
      const txt = await bumpResp.text();
      bodyDigest = txt.length > 240 ? txt.slice(0, 240) + '…' : txt;
    } catch (_e) {
      bodyDigest = '[body unreadable]';
    }

    await page.waitForTimeout(1200); // Allow for axios.then → dispatch lists → DB write tail
    const dbStatus = readOrderStatus(orderId);
    if (dbStatus !== 8) {
      recordFinding({
        id: 'S3-FIND-PREPARING-TO-PREPARED-NOOP',
        severity: 'CRITICAL',
        state: 'S3-10',
        network: `POST ${bumpResp.url()} → ${bumpResp.status()}; body=${bodyDigest}`,
        dom: `DB status after bump still ${dbStatus} (expected 8=PREPARED). seeded orderId=${orderId}.`,
        classification: 'KDS bump ladder PREPARING→PREPARED not effective on V2 grid (S3-10 regression)',
        evidence: 'S3-10-after-bump-to-prepared.png',
        fix_hint: 'Investigate: (a) does V2 grid render a CTA for PREPARING orders? Some chefs see only "Récemment servies" pill — bump CTA may be hidden once status=PREPARING in current build. Inspect KdsOrderCard guard. (b) Verify expected_status sent matches DB (Vuex order list refresh may not have run before bump on raw page load).',
      });
    }
    expect(dbStatus).toBe(8); // PREPARED

    await page.screenshot({
      path: path.join(SHOT_DIR, 'S3-10-after-bump-to-prepared.png'),
      fullPage: true,
    });
  });

  // -------------------------------------------------------------------------
  // S3-11: Multi-bump race — 3 simultaneous CTAs
  //
  // Wave V removed the 3s undo window so each tap PATCHes immediately. Race
  // risk: same order double-tap (server idempotency-keyed) OR three different
  // orders concurrent (no cross-order serialization needed since Wave V).
  // We test the *3 distinct orders* path — the more realistic chef scenario.
  // -------------------------------------------------------------------------
  test('S3-11 multi-bump race — 3 distinct orders chef-rush', async ({ page }) => {
    const tNow = Date.now();
    const id1 = seedOrder({ token: `${TOKEN_PREFIX}RACE-1-${tNow}`, status: 4 });
    const id2 = seedOrder({ token: `${TOKEN_PREFIX}RACE-2-${tNow}`, status: 4 });
    const id3 = seedOrder({ token: `${TOKEN_PREFIX}RACE-3-${tNow}`, status: 4 });

    await pinV2Layout(page);
    await loginAsAdmin(page);
    await gotoKds(page);

    // [Round-1 healing] Target cards by `data-order-id` rather than `.nth()`
    // because Vuex auto-refreshes the order list after each PATCH (line 42
    // of resources/js/store/modules/kitchenDisplaySystemOrder.js: the
    // `context.dispatch("lists", payload)` call). That refresh re-sorts
    // `activeOrders` and the nth-index would shift between clicks. Locating
    // by orderId stays stable across re-renders and matches the chef's
    // physical reality (3 different cards on the wall).
    await expect(page.locator(`[data-order-id="${id1}"] [data-testid="kds-card-cta-ready"]`)).toBeVisible({ timeout: 15_000 });

    // Listen for 3 distinct PATCH responses by URL discrimination
    const seen = new Set();
    const allPromise = page.waitForResponse((r) => {
      if (!/\/api\/admin\/kds-order\/change-status\/\d+/.test(r.url())) return false;
      seen.add(r.url());
      return seen.size === 3;
    }, { timeout: 20_000 });

    // Sequential clicks — chef rapid tap (3 in ~150ms).
    await page.locator(`[data-order-id="${id1}"] [data-testid="kds-card-cta-ready"]`).click();
    await page.locator(`[data-order-id="${id2}"] [data-testid="kds-card-cta-ready"]`).click();
    await page.locator(`[data-order-id="${id3}"] [data-testid="kds-card-cta-ready"]`).click();

    // Best effort wait — some PATCHes may be in-flight when the third returns.
    try {
      await allPromise;
    } catch (_e) {
      // Continue — diagnose via DB read below.
    }
    await page.waitForTimeout(1500); // safety margin for Echo + Vuex re-fetch tail

    const s1 = readOrderStatus(id1);
    const s2 = readOrderStatus(id2);
    const s3 = readOrderStatus(id3);

    // Each must have advanced (ACCEPT=4 → PREPARING=7). If any are still 4,
    // the rapid-tap dropped a PATCH — CRITICAL because chef perceives the
    // tap as accepted.
    const advanced = [s1, s2, s3].filter((s) => s === 7).length;
    if (advanced < 3) {
      recordFinding({
        id: 'S3-FIND-MULTIBUMP-RACE-DROP',
        severity: 'CRITICAL',
        state: 'S3-11',
        network: `PATCHes seen: ${seen.size}/3 unique URLs.`,
        dom: `Order statuses after rapid 3-tap: id${id1}=${s1} id${id2}=${s2} id${id3}=${s3} — advanced=${advanced}/3 (expected all 7=PREPARING). Chef rush scenario regression.`,
        classification: 'Rapid-tap PATCH drop (Wave V removed pending serialization but Vuex auto-refresh between clicks may race; or backend OrderStateMachine + idempotency middleware drops one)',
        evidence: 'S3-11-after-multibump-race.png',
        fix_hint: 'Investigate (a) is the bump CTA pointer-events:none briefly during Vuex refresh? (b) does the idempotency middleware key on user_id+endpoint and collapse rapid same-user POSTs? (c) does Vuex dispatch chain swallow a parallel PATCH? Logs in Laravel storage/logs/laravel.log around the failing time show [KDS_409] or 422.',
      });
    }
    expect(advanced).toBeGreaterThanOrEqual(3);

    await page.screenshot({
      path: path.join(SHOT_DIR, 'S3-11-after-multibump-race.png'),
      fullPage: true,
    });
  });

  // -------------------------------------------------------------------------
  // S3-12: Wave U recentlyServed strip intact (not regressed by X3 drawer)
  // -------------------------------------------------------------------------
  test('S3-12 Wave U recentlyServed strip renders alongside drawer trigger', async ({ page }) => {
    const tNow = Date.now();
    // [Round-2 healing] Seed 2 PREPARED orders + 1 ACTIVE so the grid is
    // non-empty (empty-state would replace the grid entirely and naturally
    // hide the served strip — that's not "intact" verification).
    //
    // KdsV2Grid.recentlyServed reads from visibleOrders (status 4/7/8) — the
    // backend feed via KitchenReleaseRule::visibleStatuses includes PREPARED.
    // So seeded PREPARED orders MUST appear in the strip.
    seedOrder({ token: `${TOKEN_PREFIX}SERVED-1-${tNow}`, status: 8 });
    seedOrder({ token: `${TOKEN_PREFIX}SERVED-2-${tNow}`, status: 8 });
    seedOrder({ token: `${TOKEN_PREFIX}SERVED-ACTIVE-${tNow}`, status: 4 });

    await pinV2Layout(page);
    await loginAsAdmin(page);
    await gotoKds(page);

    // Wait for at least one active card so we know the grid hydrated.
    await expect(page.locator('[data-testid="kds-card-cta-ready"]').first())
      .toBeVisible({ timeout: 15_000 });

    const strip = page.locator('.kds-v2__served');
    // Wave U "intact" assertion — strip MUST be visible when PREPARED orders
    // exist alongside an active card. If not, the X3 drawer add regressed it.
    await expect(strip).toBeVisible({ timeout: 10_000 });

    // Screenshot WHILE the strip is asserted visible (parallel sessions may
    // bump or delete in flight; ordering ensures the PNG captures the truth).
    await page.screenshot({
      path: path.join(SHOT_DIR, 'S3-12-wave-u-served-strip.png'),
      fullPage: true,
    });

    const stripText = (await strip.innerText()).trim();
    expect(stripText).toMatch(/N°\d+/);
    // FR-resolved label
    expect(stripText).not.toMatch(/label\.kds_recently_served/i);
    // Each PREPARED order produces one .kds-v2__served-pill
    const pillCount = await strip.locator('.kds-v2__served-pill').count();
    expect(pillCount).toBeGreaterThanOrEqual(2);
  });
});
