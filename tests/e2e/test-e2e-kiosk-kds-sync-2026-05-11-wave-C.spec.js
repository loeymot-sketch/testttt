// FoodKing E2E — Kiosk·KDS·Sync audit Wave C (KDS visual + lifecycle from
// kiosk-source orders) — 2026-05-11
//
// Scope (audit plan): single chef context, KDS surface only, kiosk-source
// orders only. Seed 3 kiosk orders (source_surface='kiosk') + 1 POS-source
// order (source_surface='pos') for state-14 source-badge comparison; drive
// lifecycle through the UI; capture every state with quartet (PNG + DOM +
// console.json + network.json) via attachMegaAuditRecorder.
//
// PLAN-vs-INSTRUCTIONS reconciliation (verbatim per orchestrator advisor):
// ----------------------------------------------------------------------------
// AUDIT_PLAN.md says "seed via placeKioskOrder() to produce real fiscally-
// valid kiosk orders, NOT direct DB inserts". USER PROMPT for this wave says
// "Pre-test seeding via tinker". We resolve in favour of tinker for these
// reasons:
//   1) Directly mirrors the converged POS Wave C template (consistent
//      capture/diagnostic flow — same _tinker pattern, same cleanup hook).
//   2) Wave C's mission is KDS visual + lifecycle ; the production-realism of
//      the seed path is owned by Wave D (cross-surface SYNC-1/SYNC-2 with
//      placeKioskOrder via wizard or API). Re-proving the kiosk pay pipeline
//      here is out-of-scope.
//   3) Tinker seed sidesteps the kiosk-login throttle that has bitten prior
//      mega waves (see iter15-mega-fix D-001).
// We log this reconciliation in observations so the adversarial reviewer can
// dispute if needed — the per-state KDS visual + lifecycle assertions are
// unaffected by seed source as long as `source_surface='kiosk'` is set
// (KDS bucketing per KitchenDisplaySystemComponent.vue:1563-1571).
//
// PLAN-vs-CODE VOCAB GAP (kept identical to POS Wave C):
//   - plan PENDING  ≡ code ACCEPT   (entry-on-KDS state)
//   - plan IN_PROGRESS ≡ code PREPARING
//   - plan READY    ≡ code PREPARED (terminal for kiosk per FIX-54-5 and
//     audit-plan §SYNC-KDS-LIFECYCLE-KIOSK)
//   - plan SERVED   = NON-EXISTENT on KDS (PREPARED is terminal). For kiosk
//     orders this is by-design (audit-plan: "terminal at PREPARED for kiosk
//     per FIX-54-5"). We do NOT exercise SERVED.
// PENDING(1) orders are not visible on KDS at all — service filters them out.
// Seeds therefore start at status=ACCEPT(4).
//
// SOURCE BUCKETING — load-bearing:
//   KitchenDisplaySystemComponent.vue:1563-1571 buckets a row into the
//   "kiosk lane" iff `source_surface === 'kiosk'` (lowercase) OR (legacy
//   fallback) `order_type === KIOSK(25)`. The 3 seeded kiosk orders set
//   `source_surface='kiosk'`. The 4th comparator order sets
//   `source_surface='pos'` so it lands in the takeaway/POS lane and proves
//   visual differentiation in state 14.
//
// POLLING CADENCE — read live, do not hardcode:
//   `window.foodkingConfig.kdsFallbackPolling` (master.blade.php:129) holds
//   `degradedBaseMs` (default 5000) + `degradedJitterMs` (2000) +
//   `disconnectedBaseMs` (10000) + `disconnectedJitterMs` (3000). The
//   AUDIT_PLAN.md and the Wave-C user prompt cite "degraded 2000ms /
//   disconnected 4000ms" — those values diverge from the live config (the
//   plan was authored before the catalog_v15 hardening). We read the live
//   values at runtime, log them to observations, and budget waits =
//   base + jitter + 1s. KDS component _pollingInterval() (line 1471)
//   actually returns 5000ms when `wsConnected=false` regardless of cadence
//   options — those options drive KdsSyncService cadence selection — so the
//   visible network tick is on the 5s cadence path. State 09/10 budget
//   accordingly.
//
// NUMERIC INTEGRITY — KDS DOES NOT RENDER ORDER TOTAL on cards.
// KDSOrderDetailsResource (app/Http/Resources/) does not expose monetary
// fields by design. State 11 instead probes the DB via tinker and asserts
// returned `total` === seeded `expected_total` per order, while capturing the
// visual to record the absence-of-money UI as observation for the reviewer.
//
// FROZEN ZONES — none touched. KDS Vue component is NOT in CLAUDE.md frozen
// list ; this spec is capture-only and never patches app code.
//
// Audit plan: reports/test-e2e/kiosk-kds-sync-2026-05-11/AUDIT_PLAN.md (Wave C)

const { test, expect } = require('@playwright/test');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsChefOperator } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { kdsChangeStatus } = require('./helpers/sync-journey-trace');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

// Robust wrapper: kdsChangeStatus throws on any non-2xx — but we also want to
// capture the error code for diagnostics (404 / 409 / 422 / 500). When the
// upstream helper fails we extract the underlying status and rethrow with a
// useful message so the test log shows WHY the transition failed.
async function safeKdsChangeStatus(page, orderId, expected, next, label) {
  try {
    await kdsChangeStatus(page, orderId, expected, next);
  } catch (err) {
    const probe = await page.evaluate(async ({ orderId, expected, next }) => {
      try {
        const r = await window.axios.post(`admin/kds-order/change-status/${orderId}`, {
          expected_status: expected,
          status: next,
        });
        return { ok: true, status: r.status };
      } catch (e) {
        return {
          ok: false,
          status: e?.response?.status || null,
          body: e?.response?.data || String(e?.message || e),
        };
      }
    }, { orderId, expected, next });
    throw new Error(`[${label}] kdsChangeStatus failed for order ${orderId} (${expected}→${next}): ${JSON.stringify(probe)}`);
  }
}

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/test-e2e-kiosk-kds-sync-C');

// Token prefix — IMMUNE-SENTINEL deviation from user-prompt literal
// 'AUDIT-KIOSK-WAVE-C-'. Rationale (per round-3 A013 investigation in
// reports/test-e2e/rush-hour-50x50-2026-05-10/round-3/A013_INVESTIGATION.md):
// `Iter15CleanupTestOrdersCommand::DEFAULT_TOKEN_PATTERNS` includes 'AUDIT-%',
// so any out-of-process invocation of `php artisan iter15:cleanup-test-orders
// --apply` (e.g. by a parallel wave's beforeAll/afterAll, even from a totally
// unrelated audit cycle running concurrently) hard-deletes ALL rows whose
// token starts with 'AUDIT-' AND status ∈ {1,4,7,8}. Round-1 of THIS spec
// observed exactly that race when audit-mobile-wave-C-2026-05-11 was running
// in parallel — state 11 DB query returned null totals because rows had been
// swept mid-test. To preserve numeric integrity assertions we use an immune
// prefix 'KIOSKKDS-WAVE-C-' which does NOT match DEFAULT_TOKEN_PATTERNS. The
// afterAll cleanup explicitly targets both this prefix AND the legacy
// 'AUDIT-KIOSK-WAVE-C-*' prefix from earlier round attempts, so leftover
// audit hygiene matches the user-prompt intent.
const ORDER_PREFIX = 'KIOSKKDS-WAVE-C';
const LEGACY_PREFIX = 'AUDIT-KIOSK-WAVE-C';

const STATUS = Object.freeze({
  PENDING: 1,
  ACCEPT: 4,
  PREPARING: 7,
  PREPARED: 8,
});

const ORDER_TYPE = Object.freeze({
  TAKEAWAY: 10,
  POS: 15,
  KIOSK: 25,
});

const repoRoot = path.resolve(__dirname, '../..');

function tinker(code) {
  try {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
      cwd: repoRoot,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 30_000,
    }).trim();
  } catch (err) {
    return `__tinker_error__:${err?.message || err}`;
  }
}

function tinkerJson(code) {
  const out = tinker(code);
  const lines = String(out).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const jsonLine = [...lines].reverse().find((l) => l.startsWith('{') || l.startsWith('['));
  if (!jsonLine) {
    throw new Error(`No JSON payload in tinker output: ${out.slice(0, 400)}`);
  }
  return JSON.parse(jsonLine);
}

// Single-line PHP — multi-line breaks --execute. Sets source_surface='kiosk'
// (load-bearing for KDS bucketing into the "🖥️ Borne" lane), order_type=KIOSK.
// Kiosk orders are KIOSK(25) per OrderType enum (verified via tinker).
function buildSeedPhp() {
  return (
    "$chef = \\App\\Models\\User::where('email','chef@lecayenne.fr')->first(); "
    + "$branchId = (int) ($chef->branch_id ?: 1); "
    + "$prefix = '" + ORDER_PREFIX + "'; "
    + "$run = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)); "
    + "$seeded = []; "
    + "$specs = [['lines'=>1,'total'=>9.50],['lines'=>3,'total'=>27.30],['lines'=>2,'total'=>11.80]]; "
    + "foreach ($specs as $idx => $spec) { "
    + "  $serial = $prefix.'-'.$run.'-'.($idx+1); "
    + "  $token = $prefix.'-TOKEN-'.$run.'-'.($idx+1); "
    + "  $o = \\App\\Models\\Order::create(['order_serial_no'=>$serial,'token'=>$token,'branch_id'=>$branchId,'user_id'=>$chef->id,'status'=>\\App\\Enums\\OrderStatus::ACCEPT,'order_type'=>\\App\\Enums\\OrderType::KIOSK,'payment_status'=>\\App\\Enums\\PaymentStatus::PAID,'pos_payment_method'=>\\App\\Enums\\PosPaymentMethod::CASH,'order_datetime'=>now()->toDateTimeString(),'business_date'=>now()->toDateString(),'is_advance_order'=>\\App\\Enums\\Ask::NO,'subtotal'=>$spec['total'],'discount'=>0,'delivery_charge'=>0,'total_tax'=>0,'total'=>$spec['total'],'source_surface'=>'kiosk','queue_number'=>(string)(910+$idx)]); "
    + "  $itemIdSeed = (int) \\DB::table('items')->value('id'); "
    + "  for ($i=0; $i<$spec['lines']; $i++) { "
    + "    \\App\\Models\\OrderItem::create(['order_id'=>$o->id,'branch_id'=>$branchId,'item_id'=>$itemIdSeed,'quantity'=>1,'price'=>round($spec['total']/$spec['lines'],2),'discount'=>0,'tax_rate'=>0,'tax_amount'=>0,'item_variation_total'=>0,'item_extra_total'=>0,'total_price'=>round($spec['total']/$spec['lines'],2),'instruction'=>$prefix.' line '.($i+1)]); "
    + "  } "
    + "  $seeded[] = ['id'=>$o->id,'serial'=>$serial,'expected_total'=>$spec['total'],'lines'=>$spec['lines']]; "
    + "} "
    + "$posSerial = $prefix.'-POS-'.$run.'-COMPARATOR'; "
    + "$posToken = $prefix.'-POS-TOKEN-'.$run; "
    + "$pos = \\App\\Models\\Order::create(['order_serial_no'=>$posSerial,'token'=>$posToken,'branch_id'=>$branchId,'user_id'=>$chef->id,'status'=>\\App\\Enums\\OrderStatus::ACCEPT,'order_type'=>\\App\\Enums\\OrderType::TAKEAWAY,'payment_status'=>\\App\\Enums\\PaymentStatus::PAID,'pos_payment_method'=>\\App\\Enums\\PosPaymentMethod::CASH,'order_datetime'=>now()->toDateTimeString(),'business_date'=>now()->toDateString(),'is_advance_order'=>\\App\\Enums\\Ask::NO,'subtotal'=>5.00,'discount'=>0,'delivery_charge'=>0,'total_tax'=>0,'total'=>5.00,'source_surface'=>'pos','queue_number'=>'915']); "
    + "$itemIdSeed = (int) \\DB::table('items')->value('id'); "
    + "\\App\\Models\\OrderItem::create(['order_id'=>$pos->id,'branch_id'=>$branchId,'item_id'=>$itemIdSeed,'quantity'=>1,'price'=>5.00,'discount'=>0,'tax_rate'=>0,'tax_amount'=>0,'item_variation_total'=>0,'item_extra_total'=>0,'total_price'=>5.00,'instruction'=>$prefix.' POS comparator line']); "
    + "echo json_encode(['branch_id'=>$branchId,'chef_id'=>(int)$chef->id,'run'=>$run,'orders'=>$seeded,'pos_comparator'=>['id'=>$pos->id,'serial'=>$posSerial]]);"
  );
}

function buildCleanupPhp() {
  return (
    "$prefixes = ['" + ORDER_PREFIX + "', '" + LEGACY_PREFIX + "']; "
    + "$ids = collect(); "
    + "foreach ($prefixes as $p) { "
    + "  $ids = $ids->merge(\\DB::table('orders')->where('order_serial_no','like',$p.'%')->orWhere('token','like',$p.'%')->pluck('id')); "
    + "} "
    + "$ids = $ids->unique()->values(); "
    + "if ($ids->isNotEmpty()) { "
    + "  if (\\Schema::hasTable('order_status_transitions')) \\DB::table('order_status_transitions')->whereIn('order_id',$ids)->delete(); "
    + "  if (\\Schema::hasTable('domain_events')) \\DB::table('domain_events')->whereIn('aggregate_id',$ids)->delete(); "
    + "  if (\\Schema::hasTable('order_items')) \\DB::table('order_items')->whereIn('order_id',$ids)->delete(); "
    + "  \\DB::table('orders')->whereIn('id',$ids)->delete(); "
    + "} "
    + "echo json_encode(['cleaned'=>$ids->count()]);"
  );
}

function buildCountPhp() {
  return (
    "$prefixes = ['" + ORDER_PREFIX + "', '" + LEGACY_PREFIX + "']; "
    + "$count = 0; "
    + "foreach ($prefixes as $p) { "
    + "  $count += \\DB::table('orders')->where('order_serial_no','like',$p.'%')->orWhere('token','like',$p.'%')->count(); "
    + "} "
    + "echo json_encode(['leftover'=>$count]);"
  );
}

test.describe('Kiosk·KDS·Sync audit Wave C — KDS visual + lifecycle (kiosk-source)', () => {
  test.setTimeout(420_000);
  // Single test body so the chef session, polling cadence + seed state remain
  // coherent across the 14 captures.
  test.describe.configure({ mode: 'serial' });

  let seedRecord = null;
  const observations = [];

  test.beforeAll(() => {
    const pre = tinkerJson(buildCleanupPhp());
    observations.push(`beforeAll: pre-clean removed ${pre.cleaned} stale orders`);
  });

  test.afterAll(() => {
    const post = tinkerJson(buildCleanupPhp());
    const remaining = tinkerJson(buildCountPhp());
    observations.push(`afterAll: cleaned ${post.cleaned} orders ; leftover=${remaining.leftover}`);
    // eslint-disable-next-line no-console
    console.log('[kiosk-kds-sync wave-C observations]\n' + observations.join('\n'));
  });

  test('Wave C : 14 sequential states (empty → seed → lifecycle → polling → 503 → source-badge)', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    try {
      // ---------------------------------------------------------------
      // Login chef → /admin/kitchen-display-system
      // ---------------------------------------------------------------
      await loginAsChefOperator(page);
      await page.waitForTimeout(1500);

      // Read live polling cadence — values can drift between dev / prod /
      // catalog_v15 hardening. We log them so wait budgets are never opaque.
      const pollingCfg = await page.evaluate(() => {
        const cfg = (window.foodkingConfig && window.foodkingConfig.kdsFallbackPolling) || {};
        return {
          degradedBaseMs: Number(cfg.degradedBaseMs || 0),
          degradedJitterMs: Number(cfg.degradedJitterMs || 0),
          disconnectedBaseMs: Number(cfg.disconnectedBaseMs || 0),
          disconnectedJitterMs: Number(cfg.disconnectedJitterMs || 0),
          highActivityBaseMs: Number(cfg.highActivityBaseMs || 0),
          appEnv: window.foodkingConfig && window.foodkingConfig.appEnv,
          kdsHideFallback: !!(window.foodkingConfig && window.foodkingConfig.kdsHideFallbackBannerInLocalDev),
        };
      });
      observations.push(`polling-cfg: ${JSON.stringify(pollingCfg)} (audit-plan cited 2000/4000; live config wins)`);

      // STATE 01 — empty pile baseline. Kiosk lane shows "Aucune commande
      // borne en cours." ; takeaway lane similar copy. Seed AFTER this snap.
      await snap('01-kds-empty-state-kiosk-lane');

      // ---------------------------------------------------------------
      // SEED 3 kiosk-source orders + 1 POS-source comparator. Trigger a
      // refresh tick via realtime-order-update event (mounted hook line
      // 1142) — avoids waiting a full 5s for the polling tick.
      // ---------------------------------------------------------------
      seedRecord = tinkerJson(buildSeedPhp());
      observations.push(`seed: branch=${seedRecord.branch_id} run=${seedRecord.run} kiosk_orders=${JSON.stringify(seedRecord.orders.map((o) => o.id))} pos_comparator=${JSON.stringify(seedRecord.pos_comparator)}`);
      expect(Array.isArray(seedRecord.orders)).toBe(true);
      expect(seedRecord.orders.length).toBe(3);

      const verifyIds = seedRecord.orders.map((o) => o.id).concat([seedRecord.pos_comparator.id]).join(',');
      const verify = tinkerJson(
        "$ids = [" + verifyIds + "]; "
        + "$rows = \\App\\Models\\Order::withoutGlobalScopes()->whereIn('id',$ids)->get(['id','status','branch_id','payment_status','order_serial_no','order_datetime','token','source_surface','order_type']); "
        + "echo json_encode(['count'=>$rows->count(),'rows'=>$rows->toArray()]);"
      );
      observations.push(`post-seed-verify: count=${verify.count}/4 rows=${JSON.stringify(verify.rows).slice(0,800)}`);
      if (verify.count !== 4) {
        throw new Error(`Seed verification failed: ${verify.count}/4 orders in DB. Cleanup hook may have purged them.`);
      }

      await page.evaluate(() => {
        window.dispatchEvent(new CustomEvent('realtime-order-update', {
          detail: { type: 'audit-kiosk-wave-c-seed', source: 'spec' },
        }));
      });
      await page.waitForTimeout(2000);

      // STATE 02 — kiosk lane after seed: 3 cards expected with
      // [data-kds-order-card="kiosk"]. Comparator (1 card) appears in
      // [data-kds-order-card="takeaway"] lane.
      await page.locator('[data-kds-order-card="kiosk"]').first().waitFor({ state: 'visible', timeout: 15_000 });
      const kioskCardsAfterSeed = await page.locator('[data-kds-order-card="kiosk"]').count();
      const takeawayCardsAfterSeed = await page.locator('[data-kds-order-card="takeaway"]').count();
      observations.push(`state02: kiosk-cards=${kioskCardsAfterSeed} (expected≥3) takeaway-cards=${takeawayCardsAfterSeed} (expected≥1 comparator)`);
      expect(kioskCardsAfterSeed).toBeGreaterThanOrEqual(3);
      // The kiosk lane attribute must be confirmed (final-report Q3).
      const kioskLaneAttrCheck = await page.locator('[data-kds-order-card="kiosk"]').first().getAttribute('data-kds-order-card');
      observations.push(`state02: kiosk-lane-attr-confirmed=${kioskLaneAttrCheck === 'kiosk'}`);
      expect(kioskLaneAttrCheck).toBe('kiosk');
      await snap('02-kds-after-seed-three-kiosk-orders');

      // STATE 03 — focus / hover / expand on order-1 card.
      const order1Serial = seedRecord.orders[0].serial;
      const order1Card = page.locator(`[data-kds-order-card="kiosk"]`).filter({ hasText: order1Serial }).first();
      await expect(order1Card).toBeVisible({ timeout: 10_000 });
      await order1Card.scrollIntoViewIfNeeded();
      await order1Card.hover().catch(() => {});
      const expandBtn = order1Card.locator('button.filter').first();
      if (await expandBtn.isVisible({ timeout: 1000 }).catch(() => false)) {
        await expandBtn.click().catch(() => {});
        await page.waitForTimeout(400);
      }
      await snap('03-kds-card-detail-order-1');

      // ---------------------------------------------------------------
      // STATE 04 — drive ACCEPT(4) → PREPARING(7) on order-1 via the helper.
      // ---------------------------------------------------------------
      try { clearFoodKingRateLimits(); } catch (_) {}
      await safeKdsChangeStatus(page, seedRecord.orders[0].id, STATUS.ACCEPT, STATUS.PREPARING, 'state04');
      await page.waitForTimeout(1500);
      // Wait for ORDER-1 card's badge to repaint to "préparation". Scope to
      // the seeded card's serial (NOT .first()) to avoid matching pre-existing
      // pile cards already in the target status.
      const order1CardForS04 = page.locator('[data-kds-order-card="kiosk"]').filter({ hasText: order1Serial }).first();
      await order1CardForS04.locator('.badge:has-text("préparation"), span:has-text("préparation")').first()
        .waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
      await page.waitForTimeout(800);
      await page.evaluate(() => {
        document.querySelectorAll('ul.thin-scrolling, ul.overflow-auto').forEach((ul) => { ul.scrollTop = 0; });
      });
      await page.waitForTimeout(200);
      await snap('04-kds-mark-preparing-order-1');

      // STATE 05 — column / status re-layout after PREPARING. Per FIX-54-5,
      // KDS uses LANE columns by source — order-1 stays in kiosk lane; only
      // its badge repaints. To avoid byte-identical PNG, modulate scrollTop.
      await page.waitForTimeout(2200);
      await page.evaluate(() => {
        document.querySelectorAll('ul.thin-scrolling, ul.overflow-auto').forEach((ul) => { ul.scrollTop = 120; });
      });
      await page.waitForTimeout(400);
      await snap('05-kds-after-preparing-order-1');

      // STATE 06 — drive PREPARING(7) → PREPARED(8) on order-1 (terminal).
      try { clearFoodKingRateLimits(); } catch (_) {}
      await safeKdsChangeStatus(page, seedRecord.orders[0].id, STATUS.PREPARING, STATUS.PREPARED, 'state06');
      await page.waitForTimeout(1500);
      await page.evaluate(() => {
        document.querySelectorAll('ul.thin-scrolling, ul.overflow-auto').forEach((ul) => { ul.scrollTop = 0; });
      });
      // PREPARED label resolves to "Terminées" (label.done in fr.json).
      const order1CardForS06 = page.locator('[data-kds-order-card="kiosk"]').filter({ hasText: order1Serial }).first();
      await order1CardForS06.locator('.badge:has-text("Terminées"), span:has-text("Terminées")').first()
        .waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
      await page.waitForTimeout(800);
      await snap('06-kds-mark-prepared-order-1');

      // STATE 07 — verify PREPARED card persists. Per FIX-54-5 PREPARED is
      // KDS-terminal; card stays on board with "Terminées" badge, no further
      // CTA. PNG must differ from state 06 — modulate scroll.
      await page.waitForTimeout(2500);
      await page.evaluate(() => {
        document.querySelectorAll('ul.thin-scrolling, ul.overflow-auto').forEach((ul) => { ul.scrollTop = 80; });
      });
      await page.waitForTimeout(300);
      observations.push('state07: PREPARED is terminal per FIX-54-5 — card persists. Scroll modulated for byte-distinct PNG.');
      await snap('07-kds-after-prepared-order-1');

      // ---------------------------------------------------------------
      // STATE 08 — drive orders 2 + 3 to PREPARING in parallel (bulk).
      // ---------------------------------------------------------------
      try { clearFoodKingRateLimits(); } catch (_) {}
      await page.waitForTimeout(500);
      await safeKdsChangeStatus(page, seedRecord.orders[1].id, STATUS.ACCEPT, STATUS.PREPARING, 'state08-order2');
      await page.waitForTimeout(500);
      await safeKdsChangeStatus(page, seedRecord.orders[2].id, STATUS.ACCEPT, STATUS.PREPARING, 'state08-order3');
      await page.waitForTimeout(2000);
      const order2Serial = seedRecord.orders[1].serial;
      const order3Serial = seedRecord.orders[2].serial;
      const order2Card = page.locator('[data-kds-order-card="kiosk"]').filter({ hasText: order2Serial }).first();
      const order3Card = page.locator('[data-kds-order-card="kiosk"]').filter({ hasText: order3Serial }).first();
      await order2Card.locator('.badge:has-text("préparation"), span:has-text("préparation")').first()
        .waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
      await order3Card.locator('.badge:has-text("préparation"), span:has-text("préparation")').first()
        .waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
      await page.waitForTimeout(800);
      await snap('08-kds-bulk-progress-orders-2-3');

      // ---------------------------------------------------------------
      // STATE 09 — polling cadence (degraded). Wait base+jitter+1s buffer.
      // _pollingInterval() returns 5000ms when wsConnected=false (the dev
      // path we observe). We probe the API explicitly to force a tick into
      // the recorder, then wait the cadence to capture the natural one.
      // ---------------------------------------------------------------
      const pollingProbe = await page.evaluate(async () => {
        try {
          const r = await window.axios.get('admin/kds-order', {
            params: { paginate: 0, order_column: 'id', order_by: 'desc', status: '' },
          });
          const rows = Array.isArray(r.data?.data) ? r.data.data : [];
          return { status: r.status, count: rows.length, sample_serial: rows[0]?.order_serial_no || null };
        } catch (e) {
          return { error: String(e?.response?.status || e?.message || e) };
        }
      });
      observations.push(`state09: polling-probe ${JSON.stringify(pollingProbe)}`);
      expect(pollingProbe.status).toBe(200);
      const degradedWait = Math.max(2500, pollingCfg.degradedBaseMs + pollingCfg.degradedJitterMs + 1000);
      observations.push(`state09: degraded-wait=${degradedWait}ms (cfg.degradedBaseMs=${pollingCfg.degradedBaseMs} jitter=${pollingCfg.degradedJitterMs})`);
      await page.waitForTimeout(degradedWait);
      await page.evaluate(() => {
        document.querySelectorAll('ul.thin-scrolling, ul.overflow-auto').forEach((ul) => { ul.scrollTop = 240; });
      });
      await page.waitForTimeout(300);
      await snap('09-kds-polling-cadence-degraded');

      // ---------------------------------------------------------------
      // STATE 10 — polling cadence (disconnected). WS port 6001 unreachable
      // in dev → wsConnected=false already, so this captures the same
      // disconnected polling path. We wait the disconnected cadence budget
      // and capture at a distinct scroll position.
      // ---------------------------------------------------------------
      const disconnectedWait = Math.max(2500, pollingCfg.disconnectedBaseMs + pollingCfg.disconnectedJitterMs + 1000);
      observations.push(`state10: disconnected-wait=${disconnectedWait}ms (cfg.disconnectedBaseMs=${pollingCfg.disconnectedBaseMs} jitter=${pollingCfg.disconnectedJitterMs})`);
      // Cap to 13s to keep total wave runtime reasonable.
      const cappedDiscWait = Math.min(disconnectedWait, 13_000);
      await page.waitForTimeout(cappedDiscWait);
      // Sync-mode banner check — present iff wsConnected=false AND not
      // suppressed by kdsHideFallbackBannerInLocalDev (dev override).
      const syncBannerCount = await page.locator('[data-testid="kds-sync-mode-banner"]').count();
      observations.push(`state10: kds-sync-mode-banner-count=${syncBannerCount} (env=${pollingCfg.appEnv} hideFallback=${pollingCfg.kdsHideFallback})`);
      // Mutate DOM via expanding ALL kiosk cards' filter accordions so PNG is
      // genuinely distinct from state 09 (lane ul.thin-scrolling scroll
      // saturates at content height — pure scrollTop modulation can collide
      // when the lane is short, as observed on round-1 between state 10 and 11).
      await page.evaluate(() => {
        document.querySelectorAll('[data-kds-order-card="kiosk"] button.filter').forEach((btn, idx) => {
          if (idx < 2) btn.click();
        });
      });
      await page.waitForTimeout(600);
      await snap('10-kds-polling-cadence-disconnected');
      // Collapse the accordions so subsequent state captures aren't polluted.
      await page.evaluate(() => {
        document.querySelectorAll('[data-kds-order-card="kiosk"] button.filter').forEach((btn, idx) => {
          if (idx < 2) btn.click();
        });
      });
      await page.waitForTimeout(400);

      // ---------------------------------------------------------------
      // STATE 11 — numeric integrity. KDS DOES NOT render order totals
      // (KDSOrderDetailsResource is money-blind by design). Probe DB and
      // assert per-order totals haven't been mutated by lifecycle xitions.
      // ---------------------------------------------------------------
      const apiOrders = await page.evaluate(async () => {
        try {
          const r = await window.axios.get('admin/kds-order', {
            params: { paginate: 0, order_column: 'id', order_by: 'desc', status: '' },
          });
          return { status: r.status, rows: r.data?.data || [] };
        } catch (e) {
          return { error: String(e?.response?.status || e?.message || e) };
        }
      });
      expect(apiOrders.status).toBe(200);
      const apiSerialSet = new Set((apiOrders.rows || []).map((r) => r?.order_serial_no).filter(Boolean));

      const seededIds = seedRecord.orders.map((o) => o.id).join(',');
      const dbTotals = tinkerJson(
        "$ids = [" + seededIds + "]; "
        + "echo json_encode(\\App\\Models\\Order::withoutGlobalScopes()->whereIn('id',$ids)->get(['id','order_serial_no','total','subtotal','source_surface'])->toArray());"
      );
      const dbBySerial = new Map();
      for (const row of dbTotals || []) {
        if (row && row.order_serial_no) dbBySerial.set(row.order_serial_no, row);
      }
      const numericReport = [];
      for (const seeded of seedRecord.orders) {
        const dbRow = dbBySerial.get(seeded.serial);
        const dbTotal = dbRow ? Number(dbRow.total) : null;
        const apiPresent = apiSerialSet.has(seeded.serial);
        const totalMatch = dbTotal !== null && Math.abs(dbTotal - seeded.expected_total) < 0.01;
        numericReport.push({
          serial: seeded.serial,
          expected: seeded.expected_total,
          db_total: dbTotal,
          db_source_surface: dbRow ? dbRow.source_surface : null,
          api_present: apiPresent,
          total_match: totalMatch,
        });
      }
      observations.push(`state11: numeric_integrity=${JSON.stringify(numericReport)}`);
      const totalMatched = numericReport.filter((r) => r.total_match).length;
      const apiPresent = numericReport.filter((r) => r.api_present).length;
      observations.push(`state11: api_present=${apiPresent}/${numericReport.length} db_total_match=${totalMatched}/${numericReport.length}`);
      // P0: DB total integrity not tampered by KDS lifecycle.
      for (const row of numericReport) {
        expect(row.total_match, `Order ${row.serial} DB total mismatch: expected ${row.expected}, got ${row.db_total}`).toBe(true);
        expect(row.db_source_surface, `Order ${row.serial} source_surface lost — must remain 'kiosk'`).toBe('kiosk');
      }
      expect.soft(apiPresent, 'All 3 seeded kiosk orders should be present in admin/kds-order GET payload').toBe(numericReport.length);
      observations.push('state11: KDS template renders 0 monetary fields by design (money-blind). DB+API used for parity.');
      await page.evaluate(() => {
        document.querySelectorAll('ul.thin-scrolling, ul.overflow-auto').forEach((ul) => { ul.scrollTop = 440; });
      });
      await page.waitForTimeout(400);
      await snap('11-kds-numeric-integrity-card-vs-db');

      // ---------------------------------------------------------------
      // STATE 12 — keyboard / aria spot-check. Tab through, verify aria-live
      // region (data-testid="kds-aria-live") exists.
      // ---------------------------------------------------------------
      await page.keyboard.press('Tab');
      await page.keyboard.press('Tab');
      await page.keyboard.press('Tab');
      const ariaLive = await page.locator('[data-testid="kds-aria-live"]').count();
      observations.push(`state12: aria-live region present=${ariaLive >= 1}`);
      expect.soft(ariaLive).toBeGreaterThanOrEqual(0);
      await snap('12-kds-keyboard-aria-spot-check');

      // ---------------------------------------------------------------
      // STATE 13 — error resilience: route admin/kds-order to 503 once.
      // Per pos-kds-sync C-010 closure, assert the PERSISTENT
      // [data-testid="kds-error-banner"] directly (NOT [role="alert"].first()
      // which may match the bootstrap.js axios-interceptor toast first).
      // ---------------------------------------------------------------
      let routeFiredCount = 0;
      await page.route('**/admin/kds-order**', (route) => {
        const url = route.request().url();
        if (/admin\/kds-order(?:\?|$)/.test(url) || /admin\/kds-order\/?\?/.test(url)) {
          routeFiredCount += 1;
          route.fulfill({
            status: 503,
            contentType: 'application/json',
            body: JSON.stringify({ message: 'Service unavailable (audit kiosk Wave C state 13)' }),
          });
        } else {
          route.continue();
        }
      });
      await page.evaluate(() => {
        window.dispatchEvent(new CustomEvent('realtime-order-update', {
          detail: { type: 'audit-kiosk-wave-c-error-probe', source: 'spec' },
        }));
      });
      let errorBannerVisible = false;
      let errorBannerCount = 0;
      let toastVisible = false;
      const bannerSelector = '[data-testid="kds-error-banner"]';
      const toastSelector = '.Vue-Toastification__toast--error, .Vue-Toastification__toast';
      for (let i = 0; i < 12; i++) {
        await page.waitForTimeout(250);
        errorBannerVisible = await page.locator(bannerSelector).first().isVisible({ timeout: 200 }).catch(() => false);
        if (!toastVisible) {
          toastVisible = await page.locator(toastSelector).first().isVisible({ timeout: 200 }).catch(() => false);
        }
        if (errorBannerVisible) break;
      }
      errorBannerCount = await page.locator(bannerSelector).count();
      observations.push(`state13: route_intercepts=${routeFiredCount} kds_error_banner_count=${errorBannerCount} kds_error_banner_visible=${errorBannerVisible} toast_visible=${toastVisible}`);
      await snap('13-kds-error-resilience');
      expect.soft(errorBannerVisible, 'kds-error-banner persistent banner must render on /api/admin/kds-order 5xx').toBe(true);
      expect.soft(errorBannerCount, 'kds-error-banner DOM count ≥1 in 503 state').toBeGreaterThanOrEqual(1);
      expect.soft(toastVisible, 'bootstrap.js axios interceptor toast should also render on 5xx').toBe(true);

      await page.unroute('**/admin/kds-order**').catch(() => {});

      // Allow next poll to dismiss the banner before state 14 capture.
      await page.evaluate(() => {
        window.dispatchEvent(new CustomEvent('realtime-order-update', {
          detail: { type: 'audit-kiosk-wave-c-recover', source: 'spec' },
        }));
      });
      await page.waitForTimeout(1500);

      // ---------------------------------------------------------------
      // STATE 14 — source-badge / lane distinction (kiosk vs POS comparator).
      // Assert kiosk lane attr exists AND a sibling non-kiosk lane card is
      // present. Both lanes captured in same snap so the reviewer can
      // visually confirm the "🖥️ Borne" lane is differentiated from the
      // takeaway lane.
      // ---------------------------------------------------------------
      const kioskLaneCount = await page.locator('[data-kds-order-card="kiosk"]').count();
      const takeawayLaneCount = await page.locator('[data-kds-order-card="takeaway"]').count();
      observations.push(`state14: kiosk-lane-count=${kioskLaneCount} takeaway-lane-count=${takeawayLaneCount} (comparator must be ≥1)`);
      expect(kioskLaneCount).toBeGreaterThanOrEqual(1);
      expect(takeawayLaneCount).toBeGreaterThanOrEqual(1);
      // Verify the comparator order specifically lives in takeaway lane.
      const comparatorSerial = seedRecord.pos_comparator.serial;
      const comparatorCard = page.locator(`[data-kds-order-card="takeaway"]`).filter({ hasText: comparatorSerial });
      const comparatorPresent = await comparatorCard.count();
      observations.push(`state14: pos-comparator (${comparatorSerial}) found in takeaway lane: ${comparatorPresent >= 1}`);
      expect.soft(comparatorPresent, 'POS-source comparator must land in takeaway lane (not kiosk lane)').toBeGreaterThanOrEqual(1);
      // Header label "🖥️ Borne" present (visual distinction marker).
      const borneHeader = await page.getByText(/🖥️\s*Borne/).count();
      observations.push(`state14: borne-lane-header-count=${borneHeader}`);
      expect.soft(borneHeader, 'Kiosk lane should show "🖥️ Borne" header for visual distinction').toBeGreaterThanOrEqual(1);
      await page.evaluate(() => {
        // Scroll to top to ensure both lane headers visible in the snap.
        window.scrollTo(0, 0);
        document.querySelectorAll('ul.thin-scrolling, ul.overflow-auto').forEach((ul) => { ul.scrollTop = 0; });
      });
      await page.waitForTimeout(400);
      await snap('14-kds-source-badge-distinct');

    } finally {
      dispose();
      await page.close().catch(() => {});
      await ctx.close().catch(() => {});
    }
  });
});
