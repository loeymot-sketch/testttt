// =============================================================================
// test-e2e-goal-4chantiers-wave-C.spec.js
// GOAL goal-4chantiers-2026-08-16 — Wave C
// Public tracking page /suivi/:trackingToken — ALL states + admin-cookie
// regression (owner's TOP-PRIORITY feature in this audit).
//
// Files under audit:
//   resources/js/components/frontend/tracking/OrderTrackingPageComponent.vue
//   resources/js/components/DefaultComponent.vue (theme === 'tracking' branch)
//   resources/js/router/modules/orderTrackingRoutes.js
//   app/Services/OrderTrackingService.php
//   app/Http/Controllers/Frontend/OrderController.php (track/trackQr)
//
// TWO contexts, BOTH required:
//   C1 anonymous      — fresh context, no cookies.
//   C2 admin-session   — fresh context, loginAsAdmin() FIRST, THEN navigate the
//                         SAME page/context to /suivi/<token>. This is the exact
//                         regression the implementer flagged: without the
//                         dedicated 'tracking' router theme, this public page
//                         would inherit the FULL admin sidebar/navbar when a
//                         staff session cookie is present in the browser.
//
// THE single most important assertion (P0 if violated): in EVERY C2 capture,
// structurally ZERO admin/kiosk chrome DOM nodes are present
// (.db-header / .db-sidebar / .db-main / .kiosk-locked-shell), not just
// visually hidden.
//
// Fixtures: seeded directly via Eloquent (tinker, real dev DB, branch_id=1,
// NOT Order::factory()) — 4 real orders (in-progress, almost-ready, ready,
// cancelled) + 1 dedicated "ahead" filler for the almost-ready fixture.
// Cleaned up (forceDelete) in afterAll.
//
// Run:
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 \
//   npx playwright test tests/e2e/test-e2e-goal-4chantiers-wave-C.spec.js \
//   --project=chromium --workers=1 --retries=0 --reporter=list
// =============================================================================

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { loginAsAdmin, cleanupOrphanTestOrders } = require('./helpers/login');

const repoRoot = path.resolve(__dirname, '../..');
const SCREENSHOT_DIR = path.join(__dirname, '__screenshots__', 'test-e2e-goal-4chantiers-wave-C');
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

// -----------------------------------------------------------------------------
// tinker helpers (fixtures + cleanup + rate-limit hygiene) — mirror the
// established pattern used across other iter15/goal specs
// (_teste2e-parite-sync-2026-07-18.spec.js, _kds-reopen-2026-08-13.spec.js).
// -----------------------------------------------------------------------------
function tinker(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: repoRoot, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 60_000,
  });
}
function tinkerJson(code) {
  const out = tinker(code);
  const lines = String(out).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const jsonLine = [...lines].reverse().find((l) => l.startsWith('{') || l.startsWith('['));
  if (!jsonLine) throw new Error(`tinker: no JSON in output:\n${out.slice(0, 1200)}`);
  return JSON.parse(jsonLine);
}

function readApiKey() {
  if (process.env.MIX_API_KEY) return process.env.MIX_API_KEY;
  const env = fs.readFileSync(path.join(repoRoot, '.env'), 'utf8');
  const m = env.match(/^MIX_API_KEY=(.+)$/m);
  if (m) return m[1].trim().replace(/^["']|["']$/g, '');
  throw new Error('MIX_API_KEY introuvable (.env / env).');
}
const API_KEY = readApiKey();

// [WAVE-C RATE-LIMIT] `order/track`, `order/track-qr`, `order/wait-estimate`
// all use the UNNAMED `throttle:30,1` Laravel middleware. Its default request
// signature is sha1($route->getDomain().'|'.$request->ip()) — this means ALL
// THREE routes share ONE 30-req/min bucket PER IP, not one bucket per route.
// On this shared dev server, other parallel GStack waves hitting these public
// frontend endpoints from the same 127.0.0.1 can exhaust the bucket before
// Wave C's own captures run (confirmed empirically pre-flight: a single fresh
// curl to order/track returned x-ratelimit-remaining: 0). Clearing this exact
// key before every navigation keeps Wave C's captures deterministic regardless
// of what other waves are doing concurrently. NOT part of the app's own
// clearFoodKingRateLimits() helper (that one targets NAMED limiters only —
// api/admin-mutation/pos-quote/etc. — this unnamed-throttle key is distinct).
function clearTrackingRateLimit() {
  tinker(`
    $limiter = app(\\Illuminate\\Cache\\RateLimiter::class);
    foreach (['127.0.0.1','::1','localhost'] as $ip) { $limiter->clear(sha1('|'.$ip)); }
    echo 'ok';
  `);
}

// -----------------------------------------------------------------------------
// Fixture seeding — real dev DB, branch_id=1, plain Order::create() (fires the
// tracking_token static::creating hook — Order.php ~L189). Mirrors the
// _kds-reopen-2026-08-13.spec.js precedent (plain create(), not saveQuietly —
// these are status ACCEPT/PREPARING/PREPARED/CANCELED, never PENDING, so they
// cannot land in the web-orders/pending panel Wave B is auditing — verified
// via routes/api.php:1025 comment "web-orders/pending exige status = PENDING").
//
// [round-2 2026-08-16, post fix C-001 commit 8dfdd2dd3] FRESH-WINDOW fixture
// design — replaces the round-1 "anchor on the stale DB backlog" trick.
// OrderTrackingService::forOrder()'s position_ahead query NOW carries the
// same staleness bound as WaitEstimateService (order_datetime >= now -
// QUEUE_WINDOW_MINUTES=120), which is exactly the fix this wave demanded.
// Consequence: anchoring a fixture's own order_datetime to "one day before
// the DB backlog" (round-1 approach) now falls itself OUTSIDE the 120-min
// window and can no longer see any "ahead" candidates — that is the fix
// working correctly, not a regression. All fixture timestamps below are
// therefore FRESH (relative to `now()` at seed time, all inside the 120-min
// window), spread out so they are individually orderable by `order_datetime`:
//
//   almostFiller (t-100) < almost (t-90)  <<  posFiller1 (t-60) <
//   posFiller2 (t-50) < posFiller3 (t-40)  <<  pos / ready / cancelled (t-0)
//
// - almost's own ahead-query only sees candidates with order_datetime <
//   t-90 AND >= t-120 → exactly almostFiller (t-100) → position_ahead=1
//   (<=2 → almost_ready=true). None of the posFiller* (t-60..t-40, i.e.
//   AFTER t-90) leak into almost's count.
// - pos's own ahead-query sees every candidate with order_datetime < t-0 and
//   >= t-120 → almostFiller + almost + posFiller1 + posFiller2 + posFiller3
//   = 5 (> 2 → position_ahead deterministic, small, sane — NOT the round-1
//   stale-ghost "465").
function seedFixtures() {
  return tinkerJson(`
    $admin = \\App\\Models\\User::where('email','admin@lecayenne.fr')->first();
    $branchId = 1;
    $now = now();

    $mk = function(array $attrs) use ($admin, $branchId) {
        $o = \\App\\Models\\Order::withoutGlobalScopes()->create(array_merge([
            'branch_id' => $branchId, 'user_id' => $admin->id,
            'order_type' => \\App\\Enums\\OrderType::TAKEAWAY, 'source_surface' => 'web',
            'payment_method' => 1, 'pos_payment_method' => null,
            'subtotal' => 12.5, 'total' => 12.5, 'total_tax' => 1.14,
            'discount' => 0, 'delivery_charge' => 0,
            'is_advance_order' => \\App\\Enums\\Ask::NO,
        ], $attrs));
        $o->token = 'WAVEC-'.$o->status.'-'.uniqid();
        $o->order_serial_no = $o->token;
        $o->queue_number = (string) (9700 + random_int(0, 250));
        $o->saveQuietly();
        return $o;
    };

    $almostFiller = $mk(['status' => 7, 'payment_status' => 5, 'order_datetime' => $now->copy()->subMinutes(100)]);
    $almost       = $mk(['status' => 7, 'payment_status' => 5, 'order_datetime' => $now->copy()->subMinutes(90)]);
    $posFiller1   = $mk(['status' => 4, 'payment_status' => 5, 'order_datetime' => $now->copy()->subMinutes(60)]);
    $posFiller2   = $mk(['status' => 4, 'payment_status' => 5, 'order_datetime' => $now->copy()->subMinutes(50)]);
    $posFiller3   = $mk(['status' => 4, 'payment_status' => 5, 'order_datetime' => $now->copy()->subMinutes(40)]);
    $pos          = $mk(['status' => 4, 'payment_status' => 5, 'order_datetime' => $now]);
    $ready        = $mk(['status' => 8, 'payment_status' => 5, 'order_datetime' => $now]);
    $cancelled    = $mk(['status' => 16, 'payment_status' => 10, 'order_datetime' => $now]);

    echo json_encode([
      'pos' => ['id'=>$pos->id, 'token'=>$pos->tracking_token],
      'almost' => ['id'=>$almost->id, 'token'=>$almost->tracking_token],
      'almostFiller' => ['id'=>$almostFiller->id],
      'posFiller1' => ['id'=>$posFiller1->id],
      'posFiller2' => ['id'=>$posFiller2->id],
      'posFiller3' => ['id'=>$posFiller3->id],
      'ready' => ['id'=>$ready->id, 'token'=>$ready->tracking_token],
      'cancelled' => ['id'=>$cancelled->id, 'token'=>$cancelled->tracking_token],
    ]);
  `);
}

function cleanupFixtures(ids) {
  if (!ids || ids.length === 0) return;
  tinker(`
    \\App\\Models\\Order::withoutGlobalScopes()->whereIn('id', [${ids.join(',')}])->forceDelete();
    echo 'CLEAN';
  `);
}

// Ground-truth backend numbers for the numeric-consistency assertion — hits
// the SAME public endpoint the SPA calls, out-of-band (page.request, not
// axios), mirroring rawApi() from _teste2e-parite-sync-2026-07-18.spec.js.
async function fetchTrackJson(request, token) {
  const r = await request.get(`/api/frontend/order/track/${token}`, {
    headers: { 'x-api-key': API_KEY, Accept: 'application/json' },
  });
  return { status: r.status(), data: await r.json().catch(() => null) };
}

// -----------------------------------------------------------------------------
// Fixture-driven state table.
// -----------------------------------------------------------------------------
function randomAlnum(len) {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  let s = '';
  for (let i = 0; i < len; i++) s += chars[Math.floor(Math.random() * chars.length)];
  return s;
}
const NOT_FOUND_TOKEN = randomAlnum(48); // syntactically valid (48 alnum), never seeded
const MALFORMED_TOKEN = 'ABCDEFGHIJ'; // 10 chars — route regex [A-Za-z0-9]{48} never matches

let fixtures = null;
let fixtureIds = [];

function buildStates() {
  return [
    { id: '01-in-progress', tokenGetter: () => fixtures.pos.token, testid: 'ot-in-progress', expectAlmost: false },
    { id: '02-almost-ready', tokenGetter: () => fixtures.almost.token, testid: 'ot-in-progress', expectAlmost: true },
    { id: '03-ready', tokenGetter: () => fixtures.ready.token, testid: 'ot-ready' },
    { id: '04-cancelled', tokenGetter: () => fixtures.cancelled.token, testid: 'ot-cancelled' },
    { id: '05-not-found', tokenGetter: () => NOT_FOUND_TOKEN, testid: 'ot-not-found' },
    { id: '06-malformed-token', tokenGetter: () => MALFORMED_TOKEN, testid: 'ot-not-found' },
  ];
}

// Admin/kiosk chrome selector — the P0 structural assertion. Root DOM nodes
// only (DefaultComponent.vue): .db-header (BackendNavbarComponent),
// .db-sidebar (BackendMenuComponent), .db-main (backend shell wrapper,
// theme==='backend' && logged), .kiosk-locked-shell (kiosk wrapper).
const ADMIN_CHROME_SELECTOR = '.db-header, .db-sidebar, .db-main, .kiosk-locked-shell';

const RAW_I18N_KEY_RE = /\b[a-z][a-z_]*(?:\.[a-z][a-z_]*){1,}\b/; // e.g. "label.foo_bar", "kiosk.ready"
const BAD_TOKEN_RE = /\bundefined\b|\bNaN\b/;

async function gotoAndWaitState(page, token, testid) {
  clearTrackingRateLimit();
  await page.goto(`/suivi/${token}`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  await expect(page.locator('[data-testid="order-tracking-page"]')).toBeVisible({ timeout: 15_000 });
  await expect(page.locator(`[data-testid="${testid}"]`)).toBeVisible({ timeout: 20_000 });
}

// -----------------------------------------------------------------------------
test.describe.configure({ mode: 'serial' });

test.describe('Wave C — /suivi/:trackingToken (public tracking page)', () => {
  test.beforeAll(async () => {
    cleanupOrphanTestOrders(['WAVEC-']);
    clearTrackingRateLimit();
    fixtures = seedFixtures();
    fixtureIds = [
      fixtures.pos.id, fixtures.almost.id, fixtures.almostFiller.id,
      fixtures.posFiller1.id, fixtures.posFiller2.id, fixtures.posFiller3.id,
      fixtures.ready.id, fixtures.cancelled.id,
    ];
    console.log('[wave-C] seeded fixtures:', JSON.stringify(fixtures));
  });

  test.afterAll(async () => {
    cleanupFixtures(fixtureIds);
  });

  // ---------------------------------------------------------------------
  // Ground truth: pull the raw backend numbers for the two active fixtures
  // to prove the "fewer orders ahead ⇒ never a wider wait range" invariant,
  // and to positively document the position_ahead / wait_low-high mismatch
  // this uncovers (see final report).
  // ---------------------------------------------------------------------
  test('ground-truth numeric consistency — position_ahead vs wait range', async ({ request }) => {
    clearTrackingRateLimit();
    const posJson = await fetchTrackJson(request, fixtures.pos.token);
    clearTrackingRateLimit();
    const almostJson = await fetchTrackJson(request, fixtures.almost.token);

    expect(posJson.status, `pos track HTTP: ${JSON.stringify(posJson)}`).toBe(200);
    expect(almostJson.status, `almost track HTTP: ${JSON.stringify(almostJson)}`).toBe(200);

    const pos = posJson.data;
    const almost = almostJson.data;
    console.log('[wave-C] ground truth pos:', JSON.stringify(pos));
    console.log('[wave-C] ground truth almost:', JSON.stringify(almost));

    expect(pos.found).toBe(true);
    expect(almost.found).toBe(true);
    expect(typeof pos.position_ahead).toBe('number');
    expect(typeof almost.position_ahead).toBe('number');

    // The fixture design guarantees this ordering deterministically.
    expect(pos.position_ahead).toBeGreaterThan(2);
    expect(almost.position_ahead).toBeLessThanOrEqual(2);
    expect(almost.almost_ready).toBe(true);
    expect(pos.almost_ready).toBe(false);

    // Acceptance criterion: fewer orders ahead (almost) must NEVER show a
    // WIDER wait range than more orders ahead (pos).
    if (pos.wait_high !== null && almost.wait_high !== null) {
      expect(almost.wait_high, 'almost-ready wait_high must not exceed pos wait_high').toBeLessThanOrEqual(pos.wait_high);
      expect(almost.wait_low, 'almost-ready wait_low must not exceed pos wait_low').toBeLessThanOrEqual(pos.wait_low);
    }

    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, '00-ground-truth.json'),
      JSON.stringify({ pos, almost }, null, 2),
    );
  });

  // ---------------------------------------------------------------------
  // C1 — anonymous context.
  // ---------------------------------------------------------------------
  test('C1 anonymous — all 6 states render cleanly, no admin chrome (baseline)', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    for (const state of buildStates()) {
      const token = state.tokenGetter();
      await gotoAndWaitState(page, token, state.testid);

      const bodyText = await page.locator('.ot-shell').innerText(); // scoped past PHP Debugbar dev-tooling noise
      expect(RAW_I18N_KEY_RE.test(bodyText), `raw i18n key leaked in ${state.id}-anon: ${bodyText.slice(0, 300)}`).toBe(false);
      expect(BAD_TOKEN_RE.test(bodyText), `undefined/NaN leaked in ${state.id}-anon`).toBe(false);

      if (state.expectAlmost === true) {
        await expect(page.locator('[data-testid="ot-almost-ready"]')).toBeVisible();
      } else if (state.expectAlmost === false) {
        await expect(page.locator('[data-testid="ot-almost-ready"]')).toHaveCount(0);
        await expect(page.locator('.ot-meta-value').first()).toBeVisible();
      }

      if (state.id === '05-not-found' || state.id === '06-malformed-token') {
        // Clean non-technical card — no stack trace / raw JSON / blank screen.
        // Scoped to .ot-shell (the page's OWN root), not body — the dev-only
        // PHP Debugbar toolbar is injected on every page in this environment
        // and its own UI legitimately contains the word "Exceptions" (a tab
        // label), which is unrelated app chrome, not the page under test.
        const shellText = await page.locator('.ot-shell').innerText();
        expect(shellText, `${state.id} shell must not leak a stack trace`).not.toMatch(/Stack trace|Fatal error|ErrorException/);
        expect(shellText.trim().length, 'not-found/malformed shell must not be blank').toBeGreaterThan(10);
      }

      await snap(`${state.id}-anon`);

      const consoleJson = JSON.parse(fs.readFileSync(path.join(SCREENSHOT_DIR, `${state.id}-anon.console.json`), 'utf8'));
      const pageErrors = consoleJson.filter((c) => c.level === 'pageerror');
      expect(pageErrors, `unhandled JS exception in ${state.id}-anon: ${JSON.stringify(pageErrors)}`).toHaveLength(0);
    }

    dispose();
    await context.close();
  });

  // ---------------------------------------------------------------------
  // C2 — admin-session context (THE regression test).
  // ---------------------------------------------------------------------
  test('C2 admin-cookie — all 6 states, ZERO admin/kiosk chrome (regression gate)', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();

    await loginAsAdmin(page); // establishes staff session cookie on /admin/*

    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    for (const state of buildStates()) {
      const token = state.tokenGetter();
      await gotoAndWaitState(page, token, state.testid);

      // ── THE single most important assertion of this wave (P0 if violated) ──
      // Structural DOM query, not visual — no admin/kiosk chrome nodes may
      // exist at all while a staff session cookie is present in this context.
      const chromeCount = await page.locator(ADMIN_CHROME_SELECTOR).count();
      expect(chromeCount, `admin/kiosk chrome present in ${state.id}-admin-cookie (staff cookie regression!)`).toBe(0);

      // Sanity: confirm the tracking page's own root DID mount (proves the
      // above zero-count isn't a false negative from a totally blank page).
      await expect(page.locator('[data-testid="order-tracking-page"]')).toBeVisible();

      const bodyText = await page.locator('.ot-shell').innerText(); // scoped past PHP Debugbar dev-tooling noise
      expect(RAW_I18N_KEY_RE.test(bodyText), `raw i18n key leaked in ${state.id}-admin-cookie: ${bodyText.slice(0, 300)}`).toBe(false);
      expect(BAD_TOKEN_RE.test(bodyText), `undefined/NaN leaked in ${state.id}-admin-cookie`).toBe(false);

      if (state.expectAlmost === true) {
        await expect(page.locator('[data-testid="ot-almost-ready"]')).toBeVisible();
      } else if (state.expectAlmost === false) {
        await expect(page.locator('[data-testid="ot-almost-ready"]')).toHaveCount(0);
      }

      await snap(`${state.id}-admin-cookie`);

      const consoleJson = JSON.parse(fs.readFileSync(path.join(SCREENSHOT_DIR, `${state.id}-admin-cookie.console.json`), 'utf8'));
      const pageErrors = consoleJson.filter((c) => c.level === 'pageerror');
      expect(pageErrors, `unhandled JS exception in ${state.id}-admin-cookie: ${JSON.stringify(pageErrors)}`).toHaveLength(0);

      // [test-e2e fix C-010 round-3 2026-08-16] Le regard C-007 (admin bootstrap
      // fuyant sur cette page publique quand un cookie staff est présent) a
      // échappé DEUX fois au CI de cette spec — network.json ne capture que les
      // requêtes en échec/lentes/mutation (un GET 200 rapide y est invisible par
      // construction), seul un grep MANUEL du console.json (violations CSP
      // report-only pour ces mêmes appels) l'a trahi à chaque fois, à la main,
      // par l'audit adversarial. Cette assertion automatise ce grep pour de bon.
      const ADMIN_BOOTSTRAP_LEAK_RE = /default-access|setting\/branch|\/api\/auth\/authcheck/;
      const leaks = consoleJson.filter((c) => ADMIN_BOOTSTRAP_LEAK_RE.test(c.text || ''));
      expect(leaks, `fuite bootstrap admin détectée en ${state.id}-admin-cookie (régression C-007): ${JSON.stringify(leaks)}`).toHaveLength(0);
    }

    dispose();
    await context.close();
  });
});
