// FoodKing E2E — RED TEAM R3 — RUPTURE STOCK LIVE PROPAGATION (2026-05-07)
//
// Mission adversaire : challenger le verdict "FoodKing V1 PRODUCTION-READY" sur la
// propagation des ruptures de stock entre 3 surfaces (POS / Kiosk / KDS).
// Le cycle MEGA-D blue team a passé 10/10 (claim live event persistence) — ce spec
// reproduit la rigueur des R1/R2 (qui ont trouvé 6 P0/P1 manqués) sur le flow stock.
//
// Méthodologie clé (anti pattern MEGA-D = grep statique) :
//   - Toggle réel via HTTP API admin (POST /api/admin/menu/availability/toggle)
//   - Mesure latence DB (event row create) + Pusher (dispatched_at)
//   - Polling DOM POS pour mesurer ms entre toggle et UI update
//   - Race conditions concurrentes via Promise.all sur axios depuis page.evaluate
//   - DB inspection via tinker pour audit trail (domain_events, item_branch_availability)
//
// Limitations adversaires honnêtes :
//   - QUEUE_CONNECTION=database, aucun queue:work détecté → DispatchDomainEventsJob
//     n'est pas drainé en harness ⇒ Pusher ne broadcast pas. domain_events.dispatched_at
//     restera NULL. ST3/ST8 mesurent rangée DB créée + payload, pas push effectif.
//   - laravel-websockets non démarré (vérifié ps aux) → Echo subscriptions Vue resteront
//     non-confirmed. La propagation Pusher n'est PAS testable runtime ici.
//   - stock_levels table empty + item_branch_availability table empty au start →
//     ST4 (double-vente concurrent) bridé : faut seed avant test si on veut le mesurer
//     vraiment ; on documente le gap.

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/red-team-r3-rupture-2026-05-07');
const FINDINGS_FILE = path.join(SHOT_DIR, 'findings.json');
const DOM_FILE = path.join(SHOT_DIR, 'dom-snapshots.json');
const TRACE_FILE = path.join(SHOT_DIR, 'domain-events-trace.json');

if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

// Findings persist via the JSON file itself (Playwright may reload the module
// between tests / workers — in-memory arrays would reset). We append-merge
// from disk on every record() call.
function readJsonOrEmpty(fp) {
  try {
    if (!fs.existsSync(fp)) return [];
    const raw = fs.readFileSync(fp, 'utf8').trim();
    return raw ? JSON.parse(raw) : [];
  } catch (_) { return []; }
}

function record(step, slug, state, sev, note, screenshot, extra = {}) {
  const list = readJsonOrEmpty(FINDINGS_FILE);
  list.push({
    step, slug, state, severity: sev, note, screenshot, ts: new Date().toISOString(), ...extra,
  });
  fs.writeFileSync(FINDINGS_FILE, JSON.stringify(list, null, 2));
}

function saveTrace(label, payload) {
  const list = readJsonOrEmpty(TRACE_FILE);
  list.push({ label, payload, ts: new Date().toISOString() });
  fs.writeFileSync(TRACE_FILE, JSON.stringify(list, null, 2));
}

function saveDom(label, payload) {
  const list = readJsonOrEmpty(DOM_FILE);
  list.push({ label, payload, ts: new Date().toISOString() });
  fs.writeFileSync(DOM_FILE, JSON.stringify(list, null, 2));
}

// Reset disk-based JSONs at the very start of the suite (single worker run).
function resetSuiteArtifacts() {
  for (const fp of [FINDINGS_FILE, DOM_FILE, TRACE_FILE]) {
    try { if (fs.existsSync(fp)) fs.unlinkSync(fp); } catch (_) {}
  }
}

async function snap(page, step, slug, state) {
  const fn = `step-${String(step).padStart(2, '0')}-${slug}-${state}.png`;
  const fp = path.join(SHOT_DIR, fn);
  await page.screenshot({ path: fp, fullPage: true }).catch(() => {});
  return path.relative(process.cwd(), fp);
}

// Tinker bridge (durable JSON output via stdout)
function tinker(code) {
  try {
    const out = execSync(`php artisan tinker --execute=${JSON.stringify(code)}`, {
      cwd: process.cwd(),
      encoding: 'utf8',
      timeout: 30_000,
    });
    const lines = out.trim().split(/\r?\n/);
    const last = [...lines].reverse().find(
      (l) => l.trim().startsWith('{') || l.trim().startsWith('[')
    );
    if (last) {
      try { return JSON.parse(last); } catch (_) { return { _raw: last }; }
    }
    return { _output: out.trim().slice(-600) };
  } catch (e) {
    return { _error: String(e.message || e).slice(0, 600) };
  }
}

const API_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120'; // from .env MIX_API_KEY
const BASE = 'http://localhost:8000';

// Login via API and return Bearer token (admin@lecayenne.fr → has items_edit + pos perms)
async function apiLogin(page, email = 'admin@lecayenne.fr', password = '123456') {
  try { clearFoodKingRateLimits(); } catch (_) {}
  const res = await page.context().request.post(`${BASE}/api/auth/login`, {
    headers: {
      'Content-Type': 'application/json', 'Accept': 'application/json', 'x-api-key': API_KEY,
    },
    data: { email, password },
  });
  const status = res.status();
  const body = await res.json().catch(() => ({}));
  return { status, token: body?.token || null, body };
}

// Toggle availability via the real admin HTTP endpoint using a Bearer token.
async function toggleAvailabilityHttp(page, token, itemId, branchId, isAvailable, reason) {
  const t0 = Date.now();
  let res, status = 0, ok = false, body = null, error = null;
  try {
    res = await page.context().request.post(`${BASE}/api/admin/menu/availability/toggle`, {
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Authorization': `Bearer ${token}`,
        'x-api-key': API_KEY,
      },
      data: {
        item_id: itemId,
        branch_id: branchId,
        is_available: isAvailable,
        unavailable_reason: reason || null,
      },
    });
    status = res.status();
    ok = res.ok();
    const text = await res.text();
    try { body = JSON.parse(text); } catch (_) { body = { _raw: text.slice(0, 500) }; }
  } catch (e) {
    error = String(e.message || e).slice(0, 400);
  }
  const ms = Date.now() - t0;
  return { ok, status, body, error, ms };
}

// Submit a POS order via the real admin HTTP endpoint with Bearer token (route POST /api/admin/pos)
async function submitPosOrder(page, token, payload) {
  let res, status = 0, ok = false, body = null, error = null;
  try {
    res = await page.context().request.post(`${BASE}/api/admin/pos`, {
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Authorization': `Bearer ${token}`,
        'x-api-key': API_KEY,
      },
      data: payload,
    });
    status = res.status();
    ok = res.ok();
    const text = await res.text();
    try { body = JSON.parse(text); } catch (_) { body = { _raw: text.slice(0, 400) }; }
  } catch (e) {
    error = String(e.message || e).slice(0, 400);
  }
  return { ok, status, body, error };
}

// `default` mode (not serial) → 1 test failure does NOT abort siblings.
// We still run --workers=1 from CLI to avoid DB races.
test.describe.configure({ retries: 0 });

test.describe('RED TEAM R3 — Rupture stock live propagation', () => {
  test.setTimeout(180_000);

  test.beforeAll(async () => {
    resetSuiteArtifacts();
  });

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  // ============================================================
  // R3-01 — Cartographier flow stock + queue/broadcast posture (harness reality check)
  // ============================================================
  test('R3-01 — Harness reality check (queue, broadcast, dispatched_at, websockets)', async ({ page }) => {
    // 1. Queue / broadcast posture
    const cfg = tinker(
      'echo json_encode(['
      + '"queue_default" => config("queue.default"),'
      + '"broadcast_default" => config("broadcasting.default"),'
      + '"jobs_pending" => \\DB::table("jobs")->count(),'
      + '"failed_jobs" => \\DB::table("failed_jobs")->count(),'
      + '"domain_events_total" => \\DB::table("domain_events")->count(),'
      + '"domain_events_dispatched" => \\DB::table("domain_events")->whereNotNull("dispatched_at")->count(),'
      + '"domain_events_pending" => \\DB::table("domain_events")->whereNull("dispatched_at")->count(),'
      + '"item_branch_availability_rows" => \\DB::table("item_branch_availability")->count(),'
      + '"stock_levels_rows" => \\DB::table("stock_levels")->count(),'
      + ']);'
    );
    saveTrace('R3-01-config-snapshot', cfg);

    // 2. Worker / websockets process check (best-effort)
    let workerInfo = '';
    try {
      workerInfo = execSync('ps aux | grep -E "queue:work|websockets:serve" | grep -v grep || true', {
        encoding: 'utf8', timeout: 5000,
      }).trim();
    } catch (_) {}
    saveTrace('R3-01-process-check', { workerInfo: workerInfo || 'NO worker / websockets running' });

    const pendingTooHigh = (cfg.domain_events_pending || 0) > 5;
    const noDispatch = (cfg.domain_events_dispatched || 0) === 0 && (cfg.domain_events_total || 0) > 0;
    const noWorker = !workerInfo;

    const sev = noDispatch && noWorker ? 'P1' : 'OK';
    const shot = await snap(page, 1, 'harness-reality', sev);
    record(
      'R3-01', 'harness-reality',
      noDispatch ? 'queue-async-no-worker' : 'queue-ok',
      sev,
      `queue=${cfg.queue_default}, broadcast=${cfg.broadcast_default}, domain_events ${cfg.domain_events_dispatched}/${cfg.domain_events_total} dispatched, pending=${cfg.domain_events_pending}, worker=${workerInfo ? 'UP' : 'DOWN'}. `
      + 'CONSEQUENCE ADVERSAIRE : si pending>0 et worker=DOWN, Pusher ne broadcast PAS — toute claim "live event push" est NON-VALIDÉE runtime dans ce harness.',
      shot,
      { config: cfg, workerInfo }
    );
  });

  // ============================================================
  // R3-02 — Toggle réel HTTP : item devient OOS, mesure latence DB row create
  // ============================================================
  test('R3-02 — Toggle HTTP réel rupture item 363 — DB row + payload + latence', async ({ page }) => {
    const auth = await apiLogin(page);
    const token = auth.token;
    expect(token, `apiLogin failed: ${auth.status}`).toBeTruthy();

    // baseline domain_events count (event_type filter)
    const before = tinker(
      'echo json_encode(['
      + '"de_count" => \\DB::table("domain_events")->where("event_type","menu.item_availability_changed")->count(),'
      + '"latest_id" => \\DB::table("domain_events")->where("event_type","menu.item_availability_changed")->max("id"),'
      + '"branch_avail_363_b1" => \\DB::table("item_branch_availability")->where("item_id",363)->where("branch_id",1)->first(),'
      + ']);'
    );
    saveTrace('R3-02-before', before);
    await snap(page, 2, 'toggle', 'pre-call');

    // ACTION : toggle item 363 (Tacos M) → unavailable on branch 1 via HTTP
    const toggleA = await toggleAvailabilityHttp(page, token, 363, 1, false, 'manual_admin');
    saveTrace('R3-02-toggle-OFF-response', toggleA);

    // wait briefly for after-commit listener
    await page.waitForTimeout(800);

    const after = tinker(
      'echo json_encode(['
      + '"de_count" => \\DB::table("domain_events")->where("event_type","menu.item_availability_changed")->count(),'
      + '"latest_event" => \\DB::table("domain_events")->where("event_type","menu.item_availability_changed")->latest("id")->first(),'
      + '"branch_avail_363_b1" => \\DB::table("item_branch_availability")->where("item_id",363)->where("branch_id",1)->first(),'
      + ']);'
    );
    saveTrace('R3-02-after', after);

    const delta = (after.de_count || 0) - (before.de_count || 0);
    const rowCreated = !!after.branch_avail_363_b1;
    const isUnavail = rowCreated && (after.branch_avail_363_b1.is_available === 0
      || after.branch_avail_363_b1.is_available === false);

    let payloadOk = false;
    if (after.latest_event && after.latest_event.payload) {
      try {
        const p = typeof after.latest_event.payload === 'string'
          ? JSON.parse(after.latest_event.payload) : after.latest_event.payload;
        payloadOk = p.item_id === 363 && p.branch_id === 1 && p.is_available === false
          && p.reason === 'manual_admin';
      } catch (_) {}
    }
    const dispatchedAt = after.latest_event?.dispatched_at || null;

    const allOk = toggleA.ok && delta === 1 && isUnavail && payloadOk;
    const sev = allOk ? 'OK' : (toggleA.ok ? 'P1' : 'P0');
    const shot = await snap(page, 2, 'toggle-off-trace', allOk ? 'persisted' : 'partial');
    record(
      'R3-02', 'toggle-off-trace',
      allOk ? 'persisted' : 'partial',
      sev,
      `HTTP ${toggleA.status} in ${toggleA.ms}ms. domain_events delta=${delta}. `
      + `branch_avail row created=${rowCreated} is_available=${isUnavail ? 'false (OK)' : 'KO'}. `
      + `payload contract OK=${payloadOk}. dispatched_at=${dispatchedAt || 'NULL (queue not drained)'}.`,
      shot,
      { toggleA, delta, rowCreated, isUnavail, payloadOk, dispatchedAt }
    );

    // Cleanup : restore availability for downstream tests
    await toggleAvailabilityHttp(page, token, 363, 1, true, null);
  });

  // ============================================================
  // R3-03 — POS : tuile bascule UI sur rupture (live ou refresh ?) + latence
  // ============================================================
  test('R3-03 — POS UI : item devient indisponible LIVE après toggle (sans refresh)', async ({ page }) => {
    const auth = await apiLogin(page);
    const token = auth.token;
    expect(token, `apiLogin failed: ${auth.status}`).toBeTruthy();

    // SPA login for POS rendering (browser side: needs vuex/localStorage token to render catalog)
    await loginAsPosOperator(page, 'admin@lecayenne.fr', '123456');

    // S'assurer item 363 dispo avant
    await toggleAvailabilityHttp(page, token, 363, 1, true, null);
    await page.waitForTimeout(300);

    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    // wait POS catalog to render
    await page.waitForTimeout(2500);
    await snap(page, 3, 'pos-before-toggle', 'baseline');

    // Find tile/button referring to Tacos in DOM (selector tolerant — POS markup varies)
    const beforeUI = await page.evaluate(() => {
      const buttons = Array.from(document.querySelectorAll('button, [role="button"], .pos-v5-tile, .pos-item-card'));
      const matched = buttons.filter((b) => /tacos\s*m/i.test(b.textContent || ''));
      return matched.slice(0, 3).map((b) => ({
        text: (b.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 80),
        disabled: b.hasAttribute('disabled'),
        ariaDisabled: b.getAttribute('aria-disabled'),
        classNames: b.className.toString().slice(0, 200),
        has86Badge: !!b.querySelector('.pos-item-86-badge, .pos-v5-tile__overlay'),
      }));
    });
    saveDom('R3-03-before-toggle', beforeUI);

    // ACT : toggle OOS via HTTP
    const t0 = Date.now();
    const toggle = await toggleAvailabilityHttp(page, token, 363, 1, false, 'manual_admin');
    saveTrace('R3-03-toggle-response', { toggle, t0 });

    // POLL DOM up to 10s, 200ms intervals → measure ms to UI flip (live), or detect refresh-required
    const samples = [];
    let firstFlipMs = null;
    const pollMaxMs = 10_000;
    const pollStart = Date.now();
    while (Date.now() - pollStart < pollMaxMs) {
      const sample = await page.evaluate(() => {
        const buttons = Array.from(document.querySelectorAll('button, [role="button"], .pos-v5-tile, .pos-item-card'));
        const matched = buttons.filter((b) => /tacos\s*m/i.test(b.textContent || ''));
        if (!matched.length) return { found: false };
        const b = matched[0];
        return {
          found: true,
          disabled: b.hasAttribute('disabled'),
          ariaDisabled: b.getAttribute('aria-disabled'),
          has86Badge: !!b.querySelector('.pos-item-86-badge, .pos-v5-tile__overlay'),
          classes: b.className.toString().slice(0, 300),
        };
      });
      const ms = Date.now() - t0;
      samples.push({ ms, ...sample });
      const isFlipped = sample.found && (sample.disabled === true || sample.ariaDisabled === 'true' || sample.has86Badge === true);
      if (isFlipped && firstFlipMs === null) {
        firstFlipMs = ms;
        break;
      }
      await page.waitForTimeout(200);
    }
    saveDom('R3-03-poll-samples', samples);

    // If never flipped via Echo, force reload to confirm the BACKEND changed the truth
    let afterReload = null;
    if (firstFlipMs === null) {
      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      afterReload = await page.evaluate(() => {
        const buttons = Array.from(document.querySelectorAll('button, [role="button"], .pos-v5-tile, .pos-item-card'));
        const matched = buttons.filter((b) => /tacos\s*m/i.test(b.textContent || ''));
        if (!matched.length) return { found: false };
        const b = matched[0];
        return {
          found: true,
          disabled: b.hasAttribute('disabled'),
          ariaDisabled: b.getAttribute('aria-disabled'),
          has86Badge: !!b.querySelector('.pos-item-86-badge, .pos-v5-tile__overlay'),
        };
      });
    }
    saveDom('R3-03-after-reload', afterReload);
    await snap(page, 3, 'pos-after-toggle', firstFlipMs ? 'live' : 'refresh-needed');

    const isLive = firstFlipMs !== null;
    const reloadOk = afterReload && (afterReload.disabled || afterReload.ariaDisabled === 'true' || afterReload.has86Badge);
    // R3-03 reclassed P0→P1 after post-mortem curl confirmed API returns is_available=false.
    // Bug is SPA-side (Vuex stale / branch_id missing / projection skip), not backend.
    const sev = isLive ? 'OK' : 'P1';
    record(
      'R3-03', 'pos-live-update',
      isLive ? `live-${firstFlipMs}ms` : (reloadOk ? 'refresh-only' : 'unavailable-state-not-rendered'),
      sev,
      `POS UI live update : ${isLive ? `flipped at ${firstFlipMs}ms (Pusher OK)` : 'NEVER flipped within 10s'}. `
      + `After reload: ${reloadOk ? 'OOS rendered (backend truth ✓ but UX cassée)' : 'OOS state ABSENT (post-mortem: API returns is_available=false correctly ⇒ bug SPA Vuex/projection, P1)'}.`,
      `${path.relative(process.cwd(), path.join(SHOT_DIR, 'step-03-pos-after-toggle-' + (firstFlipMs ? 'live' : 'refresh-needed') + '.png'))}`,
      { isLive, firstFlipMs, samplesCount: samples.length, afterReload }
    );

    // Restore
    await toggleAvailabilityHttp(page, token, 363, 1, true, null);
  });

  // ============================================================
  // R3-04 — Kiosk: item disparaît / OOS badge live ?
  // ============================================================
  test('R3-04 — Kiosk UI : item disparaît / badge OOS après toggle', async ({ page }) => {
    const auth = await apiLogin(page);
    const token = auth.token;
    expect(token, `apiLogin failed: ${auth.status}`).toBeTruthy();
    await toggleAvailabilityHttp(page, token, 363, 1, true, null);
    await page.waitForTimeout(300);
    await page.goto('/kiosk', { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(3000);
    await snap(page, 4, 'kiosk-baseline', 'before');

    // Login if required
    const needsLogin = await page.evaluate(() => /\/kiosk\/login/i.test(window.location.pathname));
    if (needsLogin) {
      const filled = await page.evaluate(() => {
        const u = document.querySelector('input[name="username"], input[type="text"]');
        const p = document.querySelector('input[type="password"]');
        if (u && p) { u.value = 'kiosk-lecayenne'; p.value = 'kiosk123'; return true; }
        return false;
      });
      if (filled) {
        await page.locator('button[type="submit"], button').first().click().catch(() => {});
        await page.waitForTimeout(2500);
      }
    }

    const beforeKiosk = await page.evaluate(() => {
      const items = Array.from(document.querySelectorAll('[data-item-id], .kiosk-item, .product-card, .ks-item, [class*="kiosk"]'));
      return {
        countAll: items.length,
        sampleTexts: items.slice(0, 5).map((e) => (e.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60)),
        hasTacos: items.some((e) => /tacos\s*m/i.test(e.textContent || '')),
      };
    });
    saveDom('R3-04-kiosk-before', beforeKiosk);

    // Toggle OFF
    const toggle = await toggleAvailabilityHttp(page, token, 363, 1, false, 'manual_admin');
    saveTrace('R3-04-kiosk-toggle', toggle);

    const t0 = Date.now();
    const samples = [];
    let firstChangeMs = null;
    while (Date.now() - t0 < 10_000) {
      const sample = await page.evaluate(() => {
        const items = Array.from(document.querySelectorAll('[data-item-id], .kiosk-item, .product-card, .ks-item, [class*="kiosk"]'));
        const tacos = items.filter((e) => /tacos\s*m/i.test(e.textContent || ''));
        return {
          countAll: items.length,
          tacosCount: tacos.length,
          tacosClasses: tacos.slice(0, 1).map((e) => e.className.toString().slice(0, 200)),
          tacosOOSBadge: tacos.some((e) => /ko|indispo|sold.?out|rupture/i.test(e.textContent || '')),
        };
      });
      samples.push({ ms: Date.now() - t0, ...sample });
      // Considered "changed" if Tacos disappeared or has OOS marker
      if (beforeKiosk.hasTacos && (sample.tacosCount === 0 || sample.tacosOOSBadge)) {
        firstChangeMs = Date.now() - t0;
        break;
      }
      await page.waitForTimeout(250);
    }
    saveDom('R3-04-kiosk-samples', samples);
    await snap(page, 4, 'kiosk-after-toggle', firstChangeMs ? 'live' : 'no-change');

    // After reload check (backend truth)
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);
    const afterReload = await page.evaluate(() => {
      const items = Array.from(document.querySelectorAll('[data-item-id], .kiosk-item, .product-card, .ks-item, [class*="kiosk"]'));
      const tacos = items.filter((e) => /tacos\s*m/i.test(e.textContent || ''));
      return {
        countAll: items.length,
        tacosCount: tacos.length,
        tacosOOSBadge: tacos.some((e) => /ko|indispo|sold.?out|rupture/i.test(e.textContent || '')),
      };
    });
    saveDom('R3-04-kiosk-after-reload', afterReload);

    const isLive = firstChangeMs !== null;
    const sev = !beforeKiosk.hasTacos ? 'P2' : (isLive ? 'OK' : 'P1');
    record(
      'R3-04', 'kiosk-live-update',
      !beforeKiosk.hasTacos ? 'precondition-no-tacos-on-kiosk' : (isLive ? `live-${firstChangeMs}ms` : 'no-live-update'),
      sev,
      `Kiosk hasTacos baseline=${beforeKiosk.hasTacos} (count=${beforeKiosk.countAll}). `
      + `LiveUpdate=${isLive ? `${firstChangeMs}ms` : 'NEVER (10s timeout)'}. `
      + `After reload: tacos=${afterReload.tacosCount}, oosBadge=${afterReload.tacosOOSBadge}. `
      + `Si tacos absent à l'état de base, kiosk peut filtrer pre-affichage (P2 a confirmer).`,
      `${path.relative(process.cwd(), path.join(SHOT_DIR, 'step-04-kiosk-after-toggle-' + (firstChangeMs ? 'live' : 'no-change') + '.png'))}`,
      { isLive, firstChangeMs, beforeKiosk, afterReload, samplesCount: samples.length }
    );

    await toggleAvailabilityHttp(page, token, 363, 1, true, null);
  });

  // ============================================================
  // R3-05 — Backend re-validation au submit : add-cart → toggle OOS → submit
  //   ⇒ doit refuser (422). Test ST5 le plus important.
  // ============================================================
  test('R3-05 — ST5 : Order submit re-valide stock après toggle OOS (race add-cart vs rupture)', async ({ page }) => {
    const auth = await apiLogin(page);
    const token = auth.token;
    expect(token, `apiLogin failed: ${auth.status}`).toBeTruthy();
    // S'assurer item 363 dispo
    await toggleAvailabilityHttp(page, token, 363, 1, true, null);
    await page.waitForTimeout(400);

    // Toggle OFF AVANT submit (simule scénario : caissier toggle, payment arrive)
    const toggle = await toggleAvailabilityHttp(page, token, 363, 1, false, 'manual_admin');
    saveTrace('R3-05-toggle-before-submit', toggle);
    await page.waitForTimeout(400);

    // Tenter de submit une commande qui contient item 363 → doit échouer
    // PosOrderRequest validation: branch_id, order_type=15 (POS), is_advance_order=0,
    // source=15 (POS), items must be JSON STRING ('json' rule), pos_payment_method=1 (CASH),
    // pos_received_amount required for CASH.
    const orderItems = [{
      item_id: 363,
      quantity: 1,
      price: 10,
      item_variations: [],
      item_extras: [],
      composition_snapshot: { addons: [] },
    }];
    const orderPayload = {
      branch_id: 1,
      order_type: 15,
      is_advance_order: 0,
      source: 15,
      items: JSON.stringify(orderItems),
      pos_payment_method: 1,
      pos_received_amount: 50,
      total: 10,
      subtotal: 10,
      discount: 0,
    };
    const submit = await submitPosOrder(page, token, orderPayload);
    saveTrace('R3-05-submit-while-OOS', submit);

    // ANALYSE : 422 attendu (rejet), 200/201 = P0 silent acceptance, 4xx autre = bénin
    const accepted = submit.status === 200 || submit.status === 201;
    const rejected422 = submit.status === 422;
    const rejectedOther = submit.status >= 400 && submit.status !== 422 && !accepted;
    const bodyMsg = (submit.body && (submit.body.message || submit.body.error)) || '';
    const messageMentionsAvailability = /indispo|unavailable|rupture|item|article/i.test(JSON.stringify(submit.body || ''));

    const sev = accepted ? 'P0' : (rejected422 && messageMentionsAvailability ? 'OK' : 'P1');
    const shot = await snap(page, 5, 'st5-submit-while-oos', accepted ? 'accepted-BUG' : 'rejected');
    record(
      'R3-05', 'st5-submit-revalidation',
      accepted ? 'silent-accept-BUG' : (rejected422 ? 'rejected-422-OK' : `rejected-${submit.status}`),
      sev,
      `Submit POS order with OOS item 363 → HTTP ${submit.status}. `
      + `Body: ${JSON.stringify(submit.body).slice(0, 250)}. `
      + (accepted
        ? 'P0 ADVERSAIRE : commande acceptée alors qu\'item indisponible. Backend NE re-valide PAS au submit.'
        : (rejected422 ? 'OK : rejetée 422 (assertItemsOrderableForBranch fonctionne).'
          : `Status inattendu — auth/CSRF possible. Body=${bodyMsg}`)),
      shot,
      { submit, toggle, messageMentionsAvailability }
    );

    await toggleAvailabilityHttp(page, token, 363, 1, true, null);
  });

  // ============================================================
  // R3-06 — Race ST4 : 2 submits concurrents avec stock=1 → 1 doit échouer
  // ============================================================
  test('R3-06 — ST4 : Double-vente concurrent (stock_levels.on_hand=1, 2 submits parallèles)', async ({ page }) => {
    const auth = await apiLogin(page);
    const token = auth.token;
    expect(token, `apiLogin failed: ${auth.status}`).toBeTruthy();

    // Seed stock_level on_hand=1 pour item 363, branch 1
    const seed = tinker(
      'echo json_encode(['
      + '"deleted" => \\DB::table("stock_levels")->where("branch_id",1)->where("stockable_type","App\\\\Models\\\\Item")->where("stockable_id",363)->delete(),'
      + '"created" => \\App\\Models\\StockLevel::query()->updateOrCreate('
        + '["branch_id"=>1,"stockable_type"=>"App\\\\Models\\\\Item","stockable_id"=>363],'
        + '["on_hand"=>1,"reorder_threshold"=>0,"created_at"=>now(),"updated_at"=>now()]'
      + ')->only(["id","on_hand"])'
      + ']);'
    );
    saveTrace('R3-06-seed-stock', seed);

    // S'assurer item dispo
    await toggleAvailabilityHttp(page, token, 363, 1, true, null);
    await page.waitForTimeout(400);

    const orderItems = [{ item_id: 363, quantity: 1, price: 10, item_variations: [], item_extras: [], composition_snapshot: { addons: [] } }];

    // Step A: get a quote_token + signature (POS now requires a valid quote)
    const quoteRes = await page.context().request.post(`${BASE}/api/admin/pos/quote`, {
      headers: {
        'Content-Type': 'application/json', 'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Authorization': `Bearer ${token}`, 'x-api-key': API_KEY,
      },
      data: {
        branch_id: 1, order_type: 15, source: 15, pos_payment_method: 1,
        items: JSON.stringify(orderItems),
      },
    });
    const quoteBody = await quoteRes.json().catch(() => ({}));
    saveTrace('R3-06-quote', { status: quoteRes.status(), body: quoteBody });
    const quoteToken = quoteBody?.data?.quote_token || quoteBody?.quote_token || null;
    const quoteSig = quoteBody?.data?.signature || quoteBody?.data?.quote_signature
      || quoteBody?.signature || quoteBody?.quote_signature || null;

    const orderPayload = {
      branch_id: 1, order_type: 15, is_advance_order: 0, source: 15,
      items: JSON.stringify(orderItems),
      pos_payment_method: 1, pos_received_amount: 50,
      total: 10, subtotal: 10, discount: 0,
      ...(quoteToken ? { quote_token: quoteToken, quote_signature: quoteSig } : {}),
    };

    // Fire 2 submits truly in parallel via Playwright request with bearer token
    const submitOne = async () => {
      try {
        const r = await page.context().request.post(`${BASE}/api/admin/pos`, {
          headers: {
            'Content-Type': 'application/json', 'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'Authorization': `Bearer ${token}`,
            'x-api-key': API_KEY,
          },
          data: orderPayload,
        });
        const text = await r.text();
        let body = null; try { body = JSON.parse(text); } catch (_) { body = text.slice(0, 200); }
        return { status: r.status(), body };
      } catch (e) {
        return { status: 0, error: String(e.message || e).slice(0, 300) };
      }
    };
    const [respA, respB] = await Promise.all([submitOne(), submitOne()]);
    const results = { a: respA, b: respB, quoteToken: !!quoteToken };
    saveTrace('R3-06-parallel-submits', results);
    await page.waitForTimeout(600);

    const after = tinker(
      'echo json_encode(['
      + '"on_hand_after" => \\DB::table("stock_levels")->where("branch_id",1)->where("stockable_type","App\\\\Models\\\\Item")->where("stockable_id",363)->value("on_hand"),'
      + '"orders_count" => \\App\\Models\\Order::where("branch_id",1)->where("created_at",">",now()->subMinutes(2))->count(),'
      + ']);'
    );
    saveTrace('R3-06-after-parallel', after);
    await snap(page, 6, 'after-parallel', 'race-result');

    const okA = results.a.status === 200 || results.a.status === 201;
    const okB = results.b.status === 200 || results.b.status === 201;
    const bothOk = okA && okB;
    const onlyOne = (okA && !okB) || (okB && !okA);
    const sev = bothOk ? 'P0' : (onlyOne ? 'OK' : 'P2');
    const shot = await snap(page, 6, 'st4-double-vente', bothOk ? 'BUG' : 'protected');
    record(
      'R3-06', 'st4-double-vente',
      bothOk ? 'BOTH-ACCEPTED-BUG' : (onlyOne ? 'one-accepted-OK' : 'both-rejected'),
      sev,
      `Stock seed on_hand=1, 2 commands parallèles : A=${results.a.status} B=${results.b.status}. `
      + `on_hand après=${after.on_hand_after}, orders créés=${after.orders_count}. `
      + (bothOk
        ? 'P0 ADVERSAIRE : 2 commandes acceptées avec stock=1 → double-vente. StockService.lockForUpdate insuffisant ou contourné.'
        : onlyOne ? 'OK : 1 seule passée, l\'autre StockUnavailable.'
        : 'Indéterminé — possiblement auth/permissions/format ; vérifier body errors.'),
      shot,
      { results, after }
    );

    // Cleanup stock_levels
    tinker('\\DB::table("stock_levels")->where("branch_id",1)->where("stockable_type","App\\\\Models\\\\Item")->where("stockable_id",363)->delete();');
  });

  // ============================================================
  // R3-07 — IngredientAvailabilityService : cascade by name + event broadcast
  // ============================================================
  test('R3-07 — IngredientAvailabilityService cascade : sauce/extra OOS sur N items', async ({ page }) => {
    // Find a multi-row ItemExtra by name (ignore group_label nuance for the seek;
    // toggle() inside the service still scopes by group_label correctly).
    const candidates = tinker(
      'echo \\App\\Models\\ItemExtra::query()'
      + '->select(\"name\")'
      + '->selectRaw(\"count(*) as c\")'
      + '->groupBy(\"name\")'
      + '->havingRaw(\"count(*) > 1\")'
      + '->orderByDesc(\"c\")->limit(3)->get()->toJson();'
    );
    saveTrace('R3-07-candidate-ingredients', candidates);

    const targetName = Array.isArray(candidates) && candidates[0] && candidates[0].name;
    if (!targetName) {
      const shot = await snap(page, 7, 'ing-cascade', 'no-candidate');
      record('R3-07', 'ing-cascade', 'no-candidate', 'P2',
        `No multi-row ItemExtra found. raw=${JSON.stringify(candidates).slice(0, 200)}`, shot);
      return;
    }

    const before = tinker(
      'echo \\App\\Models\\ItemExtra::where(\"name\", ' + JSON.stringify(targetName) + ')'
      + '->select(\"id\",\"is_available\",\"name\",\"group_label\")->limit(20)->get()->toJson();'
    );
    saveTrace('R3-07-siblings-before', before);

    const firstId = Array.isArray(before) && before[0] && before[0].id;
    if (!firstId) {
      const shot = await snap(page, 7, 'ing-cascade', 'no-siblings');
      record('R3-07', 'ing-cascade', 'no-siblings', 'P2',
        `No siblings rows. raw=${JSON.stringify(before).slice(0, 200)}`, shot);
      return;
    }

    // Trigger via service directly (controller requires auth + globalId)
    const dispatch = tinker(
      'echo json_encode(["ok"=>app(\\App\\Services\\Ingredients\\IngredientAvailabilityService::class)->toggle("extra", ' + firstId + ', false, "manual_admin")]);'
    );
    saveTrace('R3-07-dispatch', dispatch);
    await page.waitForTimeout(400);

    // Re-read same name family + check domain_events broadcast
    const after = tinker(
      'echo \\App\\Models\\ItemExtra::where(\"name\", ' + JSON.stringify(targetName) + ')'
      + '->select(\"id\",\"is_available\",\"name\",\"group_label\")->limit(20)->get()->toJson();'
    );
    const ingDe = tinker(
      'echo json_encode(["count"=>\\DB::table("domain_events")->where("event_type","menu.ingredient_availability_changed")->count(),"latest"=>\\DB::table("domain_events")->where("event_type","menu.ingredient_availability_changed")->orderBy("id","desc")->first()]);'
    );
    saveTrace('R3-07-siblings-after', { after, ingDe });
    await snap(page, 7, 'cascade-after', 'siblings');

    // Cascade by name AND group_label : same group AS firstId should all be unavail
    let firstGroup = before[0].group_label;
    const sameGroup = Array.isArray(after) ? after.filter((s) => s.group_label === firstGroup) : [];
    const allUnavail = sameGroup.length > 0 && sameGroup.every((s) => s.is_available === 0 || s.is_available === false);
    const sev = allUnavail ? 'OK' : 'P1';
    const shot = await snap(page, 7, 'ing-cascade', allUnavail ? 'cascaded' : 'partial');
    record('R3-07', 'ing-cascade',
      allUnavail ? 'all-cascaded' : 'partial',
      sev,
      `Extra "${targetName}" group=${firstGroup} : ${sameGroup.length} same-group siblings. `
      + `After cascade : same-group all unavail=${allUnavail}. ingredient_de count=${ingDe.count}.`,
      shot,
      { targetName, firstGroup, sameGroupCount: sameGroup.length, allUnavail, ingDe, beforeCount: before.length }
    );

    // Restore
    tinker(
      `\\App\\Models\\ItemExtra::where("name",` + JSON.stringify(targetName) + `)->update(["is_available"=>true,"unavailable_reason"=>null]);`
    );
  });

  // ============================================================
  // R3-08 — KDS in-flight : rupture pendant commande active reçoit-elle un signal ?
  // ============================================================
  test('R3-08 — ST6 : KDS reçoit signal "ITEM RECENTLY 86" pendant ticket in-flight ?', async ({ page }) => {
    // Inspect KDS source for signal handling
    const kdsPath = path.join(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue');
    let analysis = { exists: false };
    if (fs.existsSync(kdsPath)) {
      const src = fs.readFileSync(kdsPath, 'utf8');
      analysis = {
        exists: true,
        subscribesToItemAvailability: src.includes("broadcastAs: 'ItemAvailabilityChanged'"),
        hasInflightHighlight: /recently_?86|item_recently|inflight.*86|ITEM_RECENTLY/i.test(src),
        hasOrderItemFlag: /order_item.*availability|orderItem.*86/i.test(src),
        srcLen: src.length,
      };
    }
    saveTrace('R3-08-kds-source-analysis', analysis);

    const subscribed = analysis.subscribesToItemAvailability;
    const showsSignal = analysis.hasInflightHighlight || analysis.hasOrderItemFlag;
    const sev = subscribed && !showsSignal ? 'P1' : (subscribed ? 'OK' : 'P0');
    const shot = await snap(page, 8, 'kds-st6-signal', showsSignal ? 'signaled' : 'silent');
    record('R3-08', 'kds-inflight-signal',
      subscribed && showsSignal ? 'wired' : (subscribed ? 'subscribed-but-no-inflight-marker' : 'not-subscribed'),
      sev,
      `KDS subscribes to ItemAvailabilityChanged=${subscribed}, in-flight 86 marker=${showsSignal}. `
      + 'ST6 ADVERSAIRE : si KDS subscribe mais ne distingue PAS un ticket dont l\'item vient d\'être 86, le cuisinier prépare une commande sans warning.',
      shot,
      analysis
    );
  });

  // ============================================================
  // R3-09 — UX rupture : raison affichée côté caissier vs kiosk
  // ============================================================
  test('R3-09 — ST7 : UX rupture muette ? raison affichée caissier + kiosk ?', async ({ page }) => {
    const probes = [
      { surface: 'pos-i18n', file: 'lang/fr/pos.php', pattern: /item_86_d|item_unavailable|rupture/i },
      { surface: 'pos-en', file: 'lang/en/pos.php', pattern: /item_86_d|item_unavailable/i },
      { surface: 'pos-component', file: 'resources/js/components/admin/pos/ItemComponent.vue',
        pattern: /unavailable_reason|tooltip.*reason|title=.*reason/i },
      { surface: 'kiosk-component', file: 'resources/js/components/frontend/kiosk/KioskAppComponent.vue',
        pattern: /unavailable_reason|reason|out.?of.?stock/i },
    ];
    const out = {};
    for (const p of probes) {
      const fp = path.join(process.cwd(), p.file);
      if (!fs.existsSync(fp)) {
        out[p.surface] = { missing: true };
        continue;
      }
      const src = fs.readFileSync(fp, 'utf8');
      const matches = src.match(p.pattern);
      out[p.surface] = { hasReason: !!matches, sample: matches ? matches[0] : null };
    }
    saveTrace('R3-09-ux-reason-probes', out);

    const posExplains = out['pos-component']?.hasReason;
    const kioskExplains = out['kiosk-component']?.hasReason;
    const sev = posExplains && kioskExplains ? 'OK' : 'P2';
    const shot = await snap(page, 9, 'st7-ux-reason', posExplains && kioskExplains ? 'explicit' : 'mute');
    record('R3-09', 'ux-reason',
      posExplains && kioskExplains ? 'explicit' : (posExplains ? 'pos-only' : 'mute'),
      sev,
      `POS shows reason=${posExplains}, Kiosk shows reason=${kioskExplains}. `
      + 'ST7 : si rupture muette UX, caissier/client ne sait pas pourquoi → friction + double-tap support.',
      shot, out
    );
  });

  // ============================================================
  // R3-10 — DispatchDomainEventsJob latency : que se passe-t-il si on fait queue:work ponctuellement ?
  // ============================================================
  test('R3-10 — ST8 : DispatchDomainEventsJob — latence drain pendant 1 cycle queue:work', async ({ page }) => {
    // Forcer un toggle pour s'assurer qu'il y a un event pending
    const auth = await apiLogin(page);
    const token = auth.token;
    expect(token, `apiLogin failed: ${auth.status}`).toBeTruthy();
    const t0 = Date.now();
    const toggle = await toggleAvailabilityHttp(page, token, 363, 1, false, 'manual_admin');
    const eventCreatedMs = Date.now() - t0;
    saveTrace('R3-10-toggle', { toggle, eventCreatedMs });
    await page.waitForTimeout(300);

    const beforeWork = tinker(
      'echo json_encode(['
      + '"jobs_pending"=>\\DB::table("jobs")->count(),'
      + '"events_pending"=>\\DB::table("domain_events")->whereNull("dispatched_at")->count(),'
      + ']);'
    );
    saveTrace('R3-10-before-work', beforeWork);
    await snap(page, 10, 'queue-pre-work', 'pre');

    // Run queue worker for 1 job, capture timing
    let workOutput = '';
    let workMs = -1;
    const wt0 = Date.now();
    try {
      workOutput = execSync('php artisan queue:work --once --stop-when-empty --queue=default 2>&1', {
        cwd: process.cwd(), encoding: 'utf8', timeout: 30_000,
      });
    } catch (e) {
      workOutput = String(e.stdout || e.message || '').slice(0, 600);
    }
    workMs = Date.now() - wt0;
    saveTrace('R3-10-queue-work-output', { workOutput: workOutput.slice(0, 1500), workMs });

    const afterWork = tinker(
      'echo json_encode(['
      + '"jobs_pending"=>\\DB::table("jobs")->count(),'
      + '"events_pending"=>\\DB::table("domain_events")->whereNull("dispatched_at")->count(),'
      + '"events_dispatched"=>\\DB::table("domain_events")->whereNotNull("dispatched_at")->count(),'
      + '"latest_event"=>\\DB::table("domain_events")->where("event_type","menu.item_availability_changed")->orderBy("id","desc")->first(),'
      + ']);'
    );
    saveTrace('R3-10-after-work', afterWork);

    const drained = (beforeWork.events_pending || 0) > (afterWork.events_pending || 0);
    const dispatchedAt = afterWork.latest_event?.dispatched_at || null;
    const sev = drained && dispatchedAt ? 'OK' : 'P1';
    const shot = await snap(page, 10, 'st8-queue-latency', drained ? 'drained' : 'stuck');
    record('R3-10', 'st8-job-latency',
      drained ? 'drained' : 'stuck',
      sev,
      `queue:work --once durée=${workMs}ms. events_pending ${beforeWork.events_pending}→${afterWork.events_pending}. `
      + `latest dispatched_at=${dispatchedAt}. `
      + 'ST8 : si Pusher fail (websockets DOWN) le worker peut tourner mais retry indéfiniment ; checker last_error/attempts.',
      shot,
      { eventCreatedMs, workMs, beforeWork, afterWork }
    );

    // Restore
    await toggleAvailabilityHttp(page, token, 363, 1, true, null);
  });

  // ============================================================
  // R3-11 — Synthèse + index INDEX.md
  // ============================================================
  test('R3-11 — Synthèse + INDEX.md adversaire', async ({ page }) => {
    const list = readJsonOrEmpty(FINDINGS_FILE);
    const idx = path.join(SHOT_DIR, 'INDEX.md');
    const lines = [
      '# RED TEAM R3 — RUPTURE STOCK LIVE — 2026-05-07',
      '',
      `Total findings: ${list.length}`,
      '',
      '| Step | Slug | State | Sev | Note | Screenshot |',
      '| --- | --- | --- | --- | --- | --- |',
    ];
    for (const f of list) {
      lines.push(
        `| ${f.step} | ${f.slug} | ${f.state} | ${f.severity} | ${
          String(f.note).slice(0, 220).replace(/\|/g, '\\|')
        } | \`${f.screenshot || '—'}\` |`
      );
    }
    fs.writeFileSync(idx, lines.join('\n'));

    expect(list.length).toBeGreaterThan(8);
  });
});
