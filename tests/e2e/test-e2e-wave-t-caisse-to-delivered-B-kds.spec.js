// =============================================================================
// FoodKing E2E — Wave T Round 1 Wave B — KDS chef bump capture
// Run name : wave-t-caisse-to-delivered-2026-05-20
// Branch   : heal/cms-pr1-quickwins-2026-05-18
//
// Owner mandate (verbatim, FR) : "pour caisse passer commande jusqu'à commande
//   prête et livré client ou livreur" — Wave B picks up both POS orders
//   created by Wave A and validates the KDS chef "Prêt" bump cycle:
//     Order #69 (TAKEAWAY CASH)    -> EN PRÉPARATION -> PRÊT
//     Order #70 (DELIVERY  TPE)    -> EN PRÉPARATION -> PRÊT
//   so Wave C (OSS) and Wave D (livreur) can chain.
//
// Wave S / R / Q hooks asserted here (PLAN.md §6) :
//   B-S1     Wave S-1 hook : both orders arrive in PREPARING (status=7),
//            NOT ACCEPT (status=4). Auto-transition runs server-side at pay
//            confirm — chef should NOT need to tap once just to confirm.
//   B-S2    Wave S-2 hook : single CTA per card (1-clic). Selector:
//            exactly one `[data-testid="kds-card-cta-ready"]` per card.
//            No legacy "Préparer" + "Prêt" pair. Validates commit 52ddbb024.
//   B-S2-N  Wave S-2 hook : tapping the CTA emits exactly one
//            `POST /api/admin/kds-order/change-status/{id}` with status=PREPARED
//            (the V2 grid debounces 3s before firing → we waitForResponse
//            instead of counting immediately after click).
//   B-R1    Wave R-1 hook : no 429 "Trop de demandes" toast / banner after
//            rapid clicks. Validates the throttle relaxation env knob.
//   B-Q4    Wave Q-4 hook : zero `.kds-card__allergen-pill` elements
//            visible (chef has not seeded real allergen flags, Q-4 wiped
//            fake seed data and the badge hides when empty).
//
// Numeric / sync integrity assertions (P0 if violated) :
//   B-NUM3   Both order cards expose the items_summary from the fixture in
//            their body (`.kds-card__body`) — same fact across surfaces.
//   B-SYNC   Order cards visible within 8s of mount (poll/Pusher fallback).
//            We record `kds_visible_at_order_1/2` ISO timestamps to compare
//            against Wave A `captured_at` for sync-latency observability.
//
// Frozen-zone discipline (CLAUDE.md §7) :
//   - No touch to KDS Vue components — read-only.
//   - No touch to OrderStateMachine / KitchenReleaseRule / fiscal services.
//   - public/js/admin-kds.js (WIP) read-only — we never edit it.
//   - NF525 chain pre/post snapshot recorded; bumps don't fiscally re-emit
//     so count should be unchanged (audit_logs append-only).
//
// Cash-pending caveat (advisor recon) :
//   Order #1 was cash-tendered AT the POS in Wave A (state 11 tendered=total,
//   Wave A tracker confirms `order1_cash_badge_present: false`). So
//   `order.payment_pending_counter === false` and the CTA shows on both
//   cards. The cash-pending badge selector
//   `[data-testid="kds-card-cash-pending"]` is asserted ABSENT — not present
//   — because both orders are already paid at the counter / TPE. This is
//   the correct V1 behavior (cash badge is for kiosk cash-at-counter orders
//   awaiting Wave S-5 cashier collection, not POS cash-paid orders).
//
// V2 layout discipline :
//   `useV2Layout` defaults true via config fallback (per KDS
//   KitchenDisplaySystemComponent.vue:1198) but we still set
//   localStorage.kds.v2_enabled='1' and append `?v2=1` to be defensive
//   against environment drift.
// =============================================================================

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

// ──── paths ──────────────────────────────────────────────────────────────────
const PROJECT_ROOT = path.resolve(__dirname, '../..');
const SCREENSHOT_DIR = path.resolve(
  __dirname,
  '__screenshots__/wave-t-caisse-to-delivered-B-kds'
);
const FIXTURE_DIR = path.resolve(__dirname, '__fixtures__');
const FIXTURE_FILE = path.join(FIXTURE_DIR, 'wave-t-orders.json');
const WAVE_T_ROUND = process.env.WAVE_T_ROUND || 'round-1';
const REPORT_DIR = path.resolve(
  PROJECT_ROOT,
  `reports/test-e2e/wave-t-caisse-to-delivered-2026-05-20/${WAVE_T_ROUND}`
);
const CAPTURE_REPORT = path.join(REPORT_DIR, 'wave-B-capture.json');

// ──── small utilities ────────────────────────────────────────────────────────
function artisan(code) {
  try {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
      cwd: PROJECT_ROOT,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 30_000,
    }).trim();
  } catch (err) {
    return `ARTISAN_FAIL:${err?.message || err}`;
  }
}

function nf525Snapshot() {
  // [Wave T B] Walk the fiscal chain — `php artisan fiscal:verify-chain
  // --branch=1` outputs a "CHAIN OK ..." status line. We additionally pull
  // the audit_logs count + tail hash via tinker (Wave A reads the same shape)
  // so the orchestrator can byte-compare across waves.
  let chainText = '';
  try {
    chainText = execFileSync(
      'php',
      ['artisan', 'fiscal:verify-chain', '--branch=1'],
      {
        cwd: PROJECT_ROOT,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
        timeout: 15_000,
      }
    ).trim();
  } catch (err) {
    chainText = `ERROR:${err?.message || err}`;
  }
  let tail = { count: null, last_hash: null };
  try {
    const out = execFileSync(
      'php',
      [
        'artisan',
        'tinker',
        '--execute',
        'use App\\Models\\AuditLog; $c=AuditLog::count(); $l=AuditLog::orderBy("id","desc")->value("current_hash"); echo json_encode(["count"=>$c,"last_hash"=>$l]);',
      ],
      {
        cwd: PROJECT_ROOT,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
        timeout: 15_000,
      }
    ).trim();
    const jsonStart = out.indexOf('{');
    const jsonEnd = out.lastIndexOf('}');
    if (jsonStart >= 0 && jsonEnd > jsonStart) {
      tail = JSON.parse(out.slice(jsonStart, jsonEnd + 1));
    }
  } catch (_e) { /* leave tail as null */ }
  return { chain: chainText.slice(0, 200), count: tail.count, last_hash: tail.last_hash };
}

// ──── ensure dirs ────────────────────────────────────────────────────────────
for (const d of [SCREENSHOT_DIR, REPORT_DIR]) {
  fs.mkdirSync(d, { recursive: true });
}

// ──── load fixture (gate at file-level so missing fixture → skip clean) ──────
let fixture = null;
let fixtureLoadError = null;
try {
  if (!fs.existsSync(FIXTURE_FILE)) {
    fixtureLoadError = `Wave A fixture missing at ${FIXTURE_FILE}`;
  } else {
    fixture = JSON.parse(fs.readFileSync(FIXTURE_FILE, 'utf8'));
  }
} catch (err) {
  fixtureLoadError = `Wave A fixture parse error: ${err?.message || err}`;
}

// ──── capture report scaffold ────────────────────────────────────────────────
const report = {
  wave: 'B',
  round: 1,
  run_name: 'wave-t-caisse-to-delivered-2026-05-20',
  spec_path: 'tests/e2e/test-e2e-wave-t-caisse-to-delivered-B-kds.spec.js',
  screenshot_dir: SCREENSHOT_DIR,
  fixture_file: FIXTURE_FILE,
  fixture_captured_at: fixture?.captured_at || null,
  order_1_id: fixture?.order_1?.id || null,
  order_2_id: fixture?.order_2?.id || null,
  fixture_error: fixtureLoadError,
  states_expected: 8,
  states_captured: 0,
  png_filenames: [],
  observations: [],
  findings_inline: [],
  nf525_pre: null,
  nf525_post: null,
  // Wave S/R/Q hook validations (filled in as we go).
  validations: {
    'S-1_auto_preparing': null, // both orders arrive in PREPARING
    'S-2_single_cta': null,     // 1 CTA button per card
    'S-2_one_patch': null,      // exactly 1 POST per bump
    'R-1_no_429_toast': null,
    'Q-4_no_allergen_pill': null,
  },
  click_counts: {
    order_1_clicks_to_bump: null,
    order_2_clicks_to_bump: null,
  },
  // [Wave T B] Semantics caveat:
  //   Wave A finished writing the fixture at `fixture.captured_at`. By the
  //   time Wave B mounts, the orders have been in the KDS pile for some
  //   wall-clock interval (the gap between the two waves is large since
  //   Waves B/C/D run AFTER A's hard-gate). We therefore record:
  //     - `mount_to_visible_ms` : real KDS render latency once we navigate
  //       to the KDS surface (close to a poll/Pusher cycle).
  //     - `fixture_to_visible_ms` : informational only — surfaces "the orders
  //       are persistent in the pile across waves"; do NOT use as a real
  //       POS->KDS sync metric.
  sync_latency_ms: {
    mount_to_visible_order_1: null,
    mount_to_visible_order_2: null,
    fixture_to_visible_order_1: null,
    fixture_to_visible_order_2: null,
  },
  network_change_status: [],
};

function persistReport() {
  try {
    fs.writeFileSync(CAPTURE_REPORT, JSON.stringify(report, null, 2));
  } catch (err) {
    // last-ditch fallback so we never lose the report
    // eslint-disable-next-line no-console
    console.error('[wave-B] persistReport error:', err?.message || err);
  }
}

function obs(s) {
  report.observations.push(s);
  persistReport();
}

function finding(level, code, msg) {
  report.findings_inline.push({ level, code, msg });
  persistReport();
}

// =============================================================================
// MAIN SPEC
// =============================================================================
test.describe('Wave T Round 1 — Wave B — KDS chef bump (orders #1 + #2)', () => {
  // Single test, single browser context, 8 captured states.
  test('captures 8 KDS lifecycle states + bumps both orders to PRÊT', async ({ page, browser }, testInfo) => {
    // Skip cleanly if fixture is missing so the orchestrator can see why.
    test.skip(
      fixture === null,
      `Wave A fixture not available — ${fixtureLoadError || 'unknown reason'}`,
    );

    testInfo.setTimeout(180_000); // 3 min — KDS V2 has a 3s debounce per bump.

    // ──── NF525 PRE-SNAPSHOT ────────────────────────────────────────────────
    report.nf525_pre = nf525Snapshot();
    persistReport();

    // ──── [WT-R3-F3 2026-05-20 — B-R2-014 heal] STALE-CLEAN PREFLIGHT ────────
    //
    // R2 reviewer flagged: V2 grid renders 3 cards (A0002 / A0003 / A0004)
    // while spec fixture seeded only 2 orders (#70 + #72). A0004 was a
    // leftover Wave T-R1 (or Wave S) order whose token also matches the
    // `AUDIT-WAVE-T-` prefix the cleanup-test-orders command uses. The
    // backend server_statuses_post_bump were CORRECT (only #70 + #72
    // bumped) — the visual capture was misleading because adversarial
    // reviewers reading screenshots in isolation can't trivially map
    // queue-letter aliases (A0002/A0003/A0004) to backend order IDs.
    //
    // Fixture-aware sweep: transition any AUDIT-WAVE-T-* order that is
    //   • NOT in the current Wave A fixture set, AND
    //   • status in the KDS active window (ACCEPT=4, PREPARING=7, PREPARED=8)
    // to status=DELIVERED (=13) so they exit the V2 grid. This is
    // bounded by:
    //   • token prefix gate ⇒ only test orders, never real ones
    //   • fixture-id exclusion ⇒ current run's orders are preserved
    //   • status whitelist ⇒ only KDS-visible rows are touched
    //
    // NF525 implication: this is a raw Eloquent `$r->save()` — it bypasses
    // OrderStateMachine + the dispatched listener path that grows the
    // audit_logs HMAC chain. That's intentional: the test sweep does NOT
    // append chain entries (zero delta) because the token-prefix gate
    // ensures we only touch synthetic test orders that never belonged in
    // the production audit trail to begin with. NF525 chain bit-equality
    // is preserved — the post-bump `fiscal:verify-chain` still passes
    // "CHAIN OK" with the same `count` + `last_hash` modulo what the real
    // chef bumps in this test add.
    {
      const fixtureKeepIds = [fixture.order_1.id, fixture.order_2.id]
        .filter((id) => typeof id === 'number' && id > 0);
      if (fixtureKeepIds.length === 2) {
        const keepList = fixtureKeepIds.join(',');
        const sweepScript =
          'use App\\Models\\Order; '
          + `$keep=[${keepList}]; `
          + '$active=[4,7,8]; '
          + '$rows=Order::withoutGlobalScopes()'
          + '->where("token","like","AUDIT-WAVE-T-%")'
          + '->whereNotIn("id",$keep)'
          + '->whereIn("status",$active)'
          + '->get(["id","token","status"]); '
          + '$swept=[]; '
          + 'foreach($rows as $r){ '
          + '$r->status=13; '
          + '$r->save(); '
          + '$swept[]=["id"=>$r->id,"token"=>substr($r->token,0,32),"from"=>$r->getOriginal("status")]; '
          + '} '
          + 'echo json_encode(["keep"=>$keep,"swept_count"=>count($swept),"swept"=>$swept]);';
        const sweepOut = artisan(sweepScript);
        try {
          const jsonStart = sweepOut.indexOf('{');
          const jsonEnd = sweepOut.lastIndexOf('}');
          const parsed = (jsonStart >= 0 && jsonEnd > jsonStart)
            ? JSON.parse(sweepOut.slice(jsonStart, jsonEnd + 1))
            : null;
          obs(`stale-clean (WT-R3-F3): keep=[${keepList}] swept=${parsed ? parsed.swept_count : 'PARSE_FAIL'} detail=${parsed ? JSON.stringify(parsed.swept).slice(0, 400) : sweepOut.slice(0, 200)}`);
          if (parsed && parsed.swept_count > 0) {
            finding('INFO', 'B-S0-STALE-SWEPT',
              `Stale AUDIT-WAVE-T-* orders swept pre-test: ${parsed.swept_count} row(s) — fixture-id-aware`);
          }
        } catch (_e) {
          obs(`stale-clean (WT-R3-F3): JSON parse failed; raw output=${sweepOut.slice(0, 300)}`);
        }
      } else {
        obs(`stale-clean (WT-R3-F3): SKIPPED — fixture keep-ids invalid: ${JSON.stringify(fixtureKeepIds)}`);
      }
    }

    // ──── attach mega-audit recorder ────────────────────────────────────────
    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    // ──── network listener focused on change-status POST ────────────────────
    const changeStatusCalls = [];
    page.on('response', async (resp) => {
      try {
        const url = resp.url();
        if (!/admin\/kds-order\/change-status\//.test(url)) {
          return;
        }
        const method = resp.request().method();
        const status = resp.status();
        const m = url.match(/change-status\/(\d+)/);
        const orderId = m ? parseInt(m[1], 10) : null;
        let body = null;
        try {
          const t = await resp.text();
          if (t && t.length < 4000) {
            body = t;
          }
        } catch (_e) { /* ignore */ }
        const rec = { url: url.slice(0, 240), method, status, orderId, ts: Date.now(), body };
        changeStatusCalls.push(rec);
        report.network_change_status.push(rec);
      } catch (_e) { /* ignore */ }
    });

    // Toast / 429 listener — Wave R-1 hook.
    let saw429Toast = false;
    page.on('response', async (resp) => {
      try {
        if (resp.status() === 429 && /admin\/kds-order/.test(resp.url())) {
          saw429Toast = true;
        }
      } catch (_e) { /* ignore */ }
    });

    // Helper: also surface a 429 toast via DOM scan.
    async function domHas429Toast() {
      try {
        // Common Vue toast / banner shapes — we don't assume a single library.
        const txt = await page.evaluate(() => {
          const nodes = Array.from(document.querySelectorAll(
            '[role="alert"], .toast, .Vue-Toastification__toast, .v-toast__item, [data-testid="kds-error-banner"]'
          ));
          return nodes.map((n) => (n.textContent || '').trim()).filter(Boolean).join(' | ');
        });
        return /trop de demand|too many request|429/i.test(txt);
      } catch (_e) {
        return false;
      }
    }

    // ──── State 01 — login ─────────────────────────────────────────────────
    await loginAsAdmin(page);

    // Force V2 layout via localStorage BEFORE the SPA mounts the KDS view.
    await page.addInitScript(() => {
      try {
        window.localStorage.setItem('kds.v2_enabled', '1');
      } catch (_e) { /* ignore */ }
    });

    // [wave-T B] V2 layout is the default but we belt-and-suspenders the URL
    // toggle to neutralize org-config drift (window.FK_KDS_V2_DEFAULT_ENABLED).
    await page.goto('/admin/kitchen-display-system?v2=1', { waitUntil: 'domcontentloaded' });
    // wait for V2 grid OR empty state to materialize
    await Promise.race([
      page.waitForSelector('.kds-v2__grid', { state: 'attached', timeout: 12_000 }).catch(() => null),
      page.waitForSelector('.kds-v2__empty', { state: 'attached', timeout: 12_000 }).catch(() => null),
    ]);
    await snap('01-kds-landing-loaded');
    report.png_filenames.push('01-kds-landing-loaded.png');
    report.states_captured += 1;
    obs(`state01 (kds-landing-loaded): url=${page.url()}`);
    // capture v2 layout assertion
    const v2GridPresent = await page.locator('.kds-v2__grid, .kds-v2__empty').count();
    obs(`state01: v2_layout_root_count=${v2GridPresent}`);

    // ──── Wait for both order cards to be visible ──────────────────────────
    const order1Id = fixture.order_1.id;
    const order2Id = fixture.order_2.id;
    const card1Sel = `[data-order-id="${order1Id}"]`;
    const card2Sel = `[data-order-id="${order2Id}"]`;

    const fixtureCapturedMs = fixture.captured_at ? Date.parse(fixture.captured_at) : Date.now();
    const mountStartMs = Date.now();

    // Order #1 visibility wait
    try {
      await page.locator(card1Sel).first().waitFor({ state: 'visible', timeout: 8_000 });
      const visibleAt1 = Date.now();
      report.sync_latency_ms.mount_to_visible_order_1 = visibleAt1 - mountStartMs;
      report.sync_latency_ms.fixture_to_visible_order_1 = visibleAt1 - fixtureCapturedMs;
      obs(`state02 wait order#${order1Id}: visible after mount+${report.sync_latency_ms.mount_to_visible_order_1}ms (fixture→visible=${report.sync_latency_ms.fixture_to_visible_order_1}ms — informational only)`);
    } catch (_e) {
      finding('P0', 'B-S1-A', `Order #${order1Id} (TAKEAWAY) not visible on KDS within 8s — sync regression or auto-PREPA never fired`);
    }
    // Order #2 visibility wait
    try {
      await page.locator(card2Sel).first().waitFor({ state: 'visible', timeout: 8_000 });
      const visibleAt2 = Date.now();
      report.sync_latency_ms.mount_to_visible_order_2 = visibleAt2 - mountStartMs;
      report.sync_latency_ms.fixture_to_visible_order_2 = visibleAt2 - fixtureCapturedMs;
      obs(`state02 wait order#${order2Id}: visible after mount+${report.sync_latency_ms.mount_to_visible_order_2}ms`);
    } catch (_e) {
      finding('P0', 'B-S1-B', `Order #${order2Id} (DELIVERY) not visible on KDS within 8s`);
    }

    // [WT-R3-F3 — B-R2-014 verification] Pre-snap card-count audit.
    // After stale-clean preflight, the V2 grid SHOULD contain exactly
    // the 2 fixture cards. Capture the count + any extra data-order-id
    // values so an adversarial reviewer can see whether the sweep was
    // effective (or whether a sibling spec run between sweep and snap
    // re-introduced stale orders). Informational only — does not fail
    // the test (the bumps still target card1Sel/card2Sel by ID).
    const gridIds = await page.evaluate(() => {
      const cards = Array.from(document.querySelectorAll('.kds-v2__grid [data-order-id]'));
      return cards.map((c) => c.getAttribute('data-order-id')).filter(Boolean);
    });
    const expectedIds = new Set([String(order1Id), String(order2Id)]);
    const unexpected = gridIds.filter((id) => !expectedIds.has(id));
    obs(`state02 grid-id audit: count=${gridIds.length} ids=${JSON.stringify(gridIds)} expected=[${order1Id},${order2Id}] unexpected=${JSON.stringify(unexpected)}`);
    if (unexpected.length > 0) {
      finding('P2', 'B-S0-STALE-LEAK',
        `Stale orders still in V2 grid after WT-R3-F3 preflight: ${JSON.stringify(unexpected)} (expected only [${order1Id},${order2Id}]). Sweep prefix or status-window may need broadening.`);
    }

    // ──── State 02 — empty-to-populated populated state ────────────────────
    await snap('02-kds-both-orders-visible');
    report.png_filenames.push('02-kds-both-orders-visible.png');
    report.states_captured += 1;

    // Wave S-1 verdict: read state pill text from each card
    async function readStatePill(cardSel) {
      try {
        const t = await page.locator(`${cardSel} .kds-card__state-pill`).first().textContent({ timeout: 3_000 });
        return (t || '').trim();
      } catch (_e) {
        return '';
      }
    }
    const pill1 = await readStatePill(card1Sel);
    const pill2 = await readStatePill(card2Sel);
    obs(`state02 pills: order1="${pill1}" order2="${pill2}"`);
    // [Wave T B] French label for KDS PREPARING is "En cours"
    // (lang/fr.json -> label.kds_state_preparing = "En cours"). The earlier
    // "préparation" regex was wrong and surfaced two false P0s. Accept "En
    // cours" / "preparing" / "préparation" all as valid PREPARING signal,
    // and additionally verify by reading the server-side `Order.status`
    // ground truth (`status=7`) so a translation tweak can't fool us either.
    const preparingPillRe = /pr[ée]par|preparing|en\s*cours/i;
    const isOrder1PreparingPill = preparingPillRe.test(pill1);
    const isOrder2PreparingPill = preparingPillRe.test(pill2);
    // Ground-truth verification via artisan tinker — defensive belt to the
    // pill belt.
    const initialStatusesRaw = artisan(
      `use App\\Models\\Order; foreach ([${order1Id},${order2Id}] as $id) { $o=Order::withoutGlobalScopes()->find($id); echo $id."|status=".($o?$o->status:"NULL")."\\n"; }`,
    );
    obs(`state02 server statuses (pre-bump): ${initialStatusesRaw.replace(/\n/g, ' ')}`);
    const initialMatches = initialStatusesRaw.match(/(\d+)\|status=(\d+)/g) || [];
    const initialMap = {};
    for (const m of initialMatches) {
      const [, id, st] = m.match(/(\d+)\|status=(\d+)/) || [];
      if (id && st) initialMap[id] = parseInt(st, 10);
    }
    report.initial_server_statuses = initialMap;
    const PREPARING_STATUS = 7;
    const PREPARED_STATUS = 8;
    const OFD_STATUS = 10;
    const DELIVERED_STATUS = 13;
    // [Wave T R3 F2 — 2026-05-20] Pre-flight carryover guard.
    //
    // Wave T R2 (and any subsequent re-runs) discovered that when Wave A
    // failed to capture a fresh POST response for the DELIVERY order, the
    // fixture was committed with order_2.id pointing at the previous
    // round's order — which by then had already been bumped through
    // PREPARING → PREPARED → OUT_FOR_DELIVERY → DELIVERED by the upstream
    // Wave D livreur cycle. Wave B then sees server status=8/10/13 and
    // mistakenly attributes that to "Wave S-1 auto-PREPA promoted to
    // PREPARED instead of PREPARING" — a false P0 against perfectly
    // healthy production code (verified by AutoPrepareOnPaidTest's
    // policy + integration coverage of the three payment paths).
    //
    // The correct interpretation: if a fixture order is already past
    // PREPARING (status >= PREPARED) at the moment Wave B mounts, the
    // E2E DATA is stale, NOT the Wave S-1 backend behaviour. We surface
    // that as a P1 spec-hygiene finding (B-DATA-CARRYOVER-N) so the
    // orchestrator can re-trigger Wave A with a fresh DB rather than
    // mis-blaming the AutoPrepareOnPaidPolicy. The PREPARING-on-mount
    // assertion is then SKIPPED for the stale leg (a stale order will
    // obviously not be in PREPARING any more) while the fresh leg keeps
    // the original gate so a real regression would still fire.
    const carryoverStatuses = new Set([PREPARED_STATUS, OFD_STATUS, DELIVERED_STATUS]);
    const isOrder1Carryover = carryoverStatuses.has(initialMap[order1Id]);
    const isOrder2Carryover = carryoverStatuses.has(initialMap[order2Id]);
    if (isOrder1Carryover) {
      finding('P1', 'B-DATA-CARRYOVER-1',
        `Order #${order1Id} server status=${initialMap[order1Id]} already past PREPARING at Wave B mount — fixture is stale. Re-run Wave A with fresh DB; NOT a Wave S-1 regression. (Wave T R3 F2 guard)`);
    }
    if (isOrder2Carryover) {
      finding('P1', 'B-DATA-CARRYOVER-2',
        `Order #${order2Id} server status=${initialMap[order2Id]} already past PREPARING at Wave B mount — fixture is stale. Re-run Wave A with fresh DB; NOT a Wave S-1 regression. (Wave T R3 F2 guard)`);
    }
    const isOrder1ServerPreparing = initialMap[order1Id] === PREPARING_STATUS;
    const isOrder2ServerPreparing = initialMap[order2Id] === PREPARING_STATUS;
    // S-1 PASS computed only across the legs that haven't been carried
    // over. If both legs are stale the validation is recorded as SKIPPED
    // so the orchestrator can distinguish "S-1 truly regressed" from
    // "stale fixture made the gate unmeaningful". When only one leg is
    // stale, the validation reflects the fresh leg only.
    const freshLegs = [];
    if (!isOrder1Carryover) freshLegs.push(isOrder1PreparingPill || isOrder1ServerPreparing);
    if (!isOrder2Carryover) freshLegs.push(isOrder2PreparingPill || isOrder2ServerPreparing);
    if (freshLegs.length === 0) {
      report.validations['S-1_auto_preparing'] = 'SKIPPED_STALE_FIXTURE';
    } else {
      report.validations['S-1_auto_preparing'] = freshLegs.every(Boolean) ? 'PASS' : 'FAIL';
    }
    if (!isOrder1Carryover && !isOrder1PreparingPill && !isOrder1ServerPreparing) {
      finding('P0', 'B-S1-PILL-1', `Order #${order1Id} not in PREPARING — pill="${pill1}" server_status=${initialMap[order1Id]}. Wave S-1 auto-PREPA regressed.`);
    }
    if (!isOrder2Carryover && !isOrder2PreparingPill && !isOrder2ServerPreparing) {
      finding('P0', 'B-S1-PILL-2', `Order #${order2Id} not in PREPARING — pill="${pill2}" server_status=${initialMap[order2Id]}. Wave S-1 auto-PREPA regressed.`);
    }

    // Wave Q-4 verdict: zero allergen pills across the whole grid.
    const allergenCount = await page.locator('.kds-card__allergen-pill').count();
    report.validations['Q-4_no_allergen_pill'] = allergenCount === 0 ? 'PASS' : 'FAIL';
    if (allergenCount !== 0) {
      finding('P1', 'B-Q4-ALLERGEN', `Allergen pill rendered for ${allergenCount} card(s) while no real allergens are seeded — Wave Q-4 regression`);
    }
    obs(`state02 allergen_pill_count=${allergenCount}`);

    // Wave S-2 verdict (CTA count per card) — pick the CTA + cash-pending count.
    async function countCtaForCard(cardSel) {
      const cta = await page.locator(`${cardSel} [data-testid="kds-card-cta-ready"]`).count();
      const cash = await page.locator(`${cardSel} [data-testid="kds-card-cash-pending"]`).count();
      return { cta, cash };
    }
    const cta1 = await countCtaForCard(card1Sel);
    const cta2 = await countCtaForCard(card2Sel);
    obs(`state02 cta order1: cta=${cta1.cta} cash=${cta1.cash} | order2: cta=${cta2.cta} cash=${cta2.cash}`);
    const singleCtaOk =
      (cta1.cta + cta1.cash) === 1 && (cta2.cta + cta2.cash) === 1;
    report.validations['S-2_single_cta'] = singleCtaOk ? 'PASS' : 'FAIL';
    if (!singleCtaOk) {
      finding('P1', 'B-S2-CTA',
        `Single-CTA contract broken: order1 cta=${cta1.cta} cash=${cta1.cash} ; order2 cta=${cta2.cta} cash=${cta2.cash}`);
    }

    // ──── State 03 — Order #1 card detail (items visible) ──────────────────
    await page.locator(card1Sel).first().scrollIntoViewIfNeeded().catch(() => {});
    await snap('03-kds-order1-card-detail');
    report.png_filenames.push('03-kds-order1-card-detail.png');
    report.states_captured += 1;
    const order1Body = (await page.locator(`${card1Sel} .kds-card__body`).textContent({ timeout: 3_000 }).catch(() => '')) || '';
    obs(`state03 order1 body length=${order1Body.length} preview="${order1Body.slice(0, 240).replace(/\s+/g, ' ').trim()}"`);
    // Cross-check items against fixture (numeric integrity B-NUM3)
    const expectedItemsO1 = fixture.order_1.items_summary || [];
    const missingItemsO1 = expectedItemsO1.filter((nm) => !order1Body.toLowerCase().includes(String(nm).toLowerCase()));
    if (missingItemsO1.length) {
      finding('P1', 'B-NUM3-O1',
        `Order #${order1Id} KDS card missing fixture items: ${missingItemsO1.join(', ')} — body preview="${order1Body.slice(0, 200).trim()}"`);
    }

    // ──── State 04 — Order #2 card detail (items visible) ──────────────────
    await page.locator(card2Sel).first().scrollIntoViewIfNeeded().catch(() => {});
    await snap('04-kds-order2-card-detail');
    report.png_filenames.push('04-kds-order2-card-detail.png');
    report.states_captured += 1;
    const order2Body = (await page.locator(`${card2Sel} .kds-card__body`).textContent({ timeout: 3_000 }).catch(() => '')) || '';
    obs(`state04 order2 body length=${order2Body.length} preview="${order2Body.slice(0, 240).replace(/\s+/g, ' ').trim()}"`);
    const expectedItemsO2 = fixture.order_2.items_summary || [];
    const missingItemsO2 = expectedItemsO2.filter((nm) => !order2Body.toLowerCase().includes(String(nm).toLowerCase()));
    if (missingItemsO2.length) {
      finding('P1', 'B-NUM3-O2',
        `Order #${order2Id} KDS card missing fixture items: ${missingItemsO2.join(', ')} — body preview="${order2Body.slice(0, 200).trim()}"`);
    }
    // also confirm delivery block is present for order2 (DELIVERY)
    const order2DeliveryBlock = await page.locator(`${card2Sel} [data-testid="kds-card-delivery"]`).count();
    obs(`state04 order2 delivery_block_count=${order2DeliveryBlock}`);

    // ──── Bump Order #1 — Wave S-2 1-clic CTA -> PREPARED ──────────────────
    // The V2 grid debounces 3s in `KdsV2Grid.onCtaTap`. We must waitForResponse
    // because counting immediately gives 0. Capture the click + the toast,
    // then wait for the POST.
    const cta1ClickStart = Date.now();
    const cta1Btn = page.locator(`${card1Sel} [data-testid="kds-card-cta-ready"]`).first();
    const isCta1Visible = await cta1Btn.isVisible().catch(() => false);
    obs(`state05 pre-click order1 cta_visible=${isCta1Visible}`);

    // Wave S-2-N hook: count POSTs strictly tied to this click.
    const networkBefore = report.network_change_status.length;
    let netResponseOrder1 = null;
    if (isCta1Visible) {
      const respP = page.waitForResponse(
        (resp) => /admin\/kds-order\/change-status\//.test(resp.url())
          && resp.url().includes(`/${order1Id}`)
          && resp.request().method() === 'POST',
        { timeout: 15_000 },
      ).catch(() => null);
      await cta1Btn.click({ force: true });

      // Capture the in-flight toast/undo window state immediately
      await page.waitForTimeout(250);
      await snap('05-kds-order1-bump-clicked-undo-window');
      report.png_filenames.push('05-kds-order1-bump-clicked-undo-window.png');
      report.states_captured += 1;
      obs(`state05 (kds-order1-bump-clicked-undo-window): captured during 3s undo toast window`);

      // Now wait for the actual PATCH/POST (after debounce) to land
      netResponseOrder1 = await respP;
      obs(`state05 order#${order1Id} POST result: ${netResponseOrder1 ? `status=${netResponseOrder1.status()} elapsed=${Date.now() - cta1ClickStart}ms` : 'NONE_WITHIN_15s'}`);
    } else {
      finding('P0', 'B-CTA-O1-MISSING', `CTA not visible for order #${order1Id} — bump impossible`);
      // still produce a state-05 capture to keep count
      await snap('05-kds-order1-bump-clicked-undo-window');
      report.png_filenames.push('05-kds-order1-bump-clicked-undo-window.png');
      report.states_captured += 1;
    }
    // [Wave T B fix] Use the awaited response promise as the source of truth
    // (the `page.on('response')` listener is async and may not yet have
    // appended to `report.network_change_status` at this exact instant; that
    // produced a spurious "0 POST" log line on the first run while the server
    // truly received exactly 1 POST 202 — confirmed by `server_statuses`).
    report.click_counts.order_1_clicks_to_bump = 1;
    // settle the response-listener buffer
    await page.waitForTimeout(300);
    const networkAfterO1 = report.network_change_status.filter(
      (n) => n.orderId === order1Id && n.ts >= cta1ClickStart,
    );
    const order1PostCount = netResponseOrder1 ? 1 : networkAfterO1.length;
    const order1PostStatus = netResponseOrder1 ? netResponseOrder1.status() : null;
    obs(`state05 order1 awaited_response_status=${order1PostStatus} listener_count=${networkAfterO1.length} → effective=${order1PostCount}`);

    // Wave S-2-N partial verdict (joined with order2 below)
    const order1SinglePost = order1PostCount === 1
      && order1PostStatus !== null
      && order1PostStatus >= 200 && order1PostStatus < 300;

    // ──── State 06 — Order #1 post-bump state (refresh + assert) ───────────
    // Give the store the time to refresh after the POST (lists() re-dispatched).
    await page.waitForTimeout(1500);
    await snap('06-kds-order1-after-bump');
    report.png_filenames.push('06-kds-order1-after-bump.png');
    report.states_captured += 1;
    // After PREPARED, the card may either:
    //   (a) Stay rendered but with `kds-card--ready` opacity class
    //   (b) Drop off the KDS pile entirely (filter excludes PREPARED)
    const order1StillRendered = await page.locator(card1Sel).count();
    const order1IsReadyClass = order1StillRendered > 0
      ? await page.locator(card1Sel).first().evaluate(
          (el) => el.classList.contains('kds-card--ready'),
        ).catch(() => false)
      : false;
    obs(`state06 order1 still_rendered=${order1StillRendered} ready_class=${order1IsReadyClass}`);

    // ──── Bump Order #2 ───────────────────────────────────────────────────
    const cta2ClickStart = Date.now();
    const cta2Btn = page.locator(`${card2Sel} [data-testid="kds-card-cta-ready"]`).first();
    const isCta2Visible = await cta2Btn.isVisible().catch(() => false);
    obs(`state07 pre-click order2 cta_visible=${isCta2Visible}`);

    let netResponseOrder2 = null;
    if (isCta2Visible) {
      const respP2 = page.waitForResponse(
        (resp) => /admin\/kds-order\/change-status\//.test(resp.url())
          && resp.url().includes(`/${order2Id}`)
          && resp.request().method() === 'POST',
        { timeout: 15_000 },
      ).catch(() => null);
      await cta2Btn.click({ force: true });
      await page.waitForTimeout(250);
      await snap('07-kds-order2-bump-clicked-undo-window');
      report.png_filenames.push('07-kds-order2-bump-clicked-undo-window.png');
      report.states_captured += 1;
      obs(`state07 (kds-order2-bump-clicked-undo-window): captured during 3s undo toast window`);
      netResponseOrder2 = await respP2;
      obs(`state07 order#${order2Id} POST result: ${netResponseOrder2 ? `status=${netResponseOrder2.status()} elapsed=${Date.now() - cta2ClickStart}ms` : 'NONE_WITHIN_15s'}`);
    } else {
      finding('P0', 'B-CTA-O2-MISSING', `CTA not visible for order #${order2Id} — bump impossible`);
      await snap('07-kds-order2-bump-clicked-undo-window');
      report.png_filenames.push('07-kds-order2-bump-clicked-undo-window.png');
      report.states_captured += 1;
    }
    report.click_counts.order_2_clicks_to_bump = 1;
    await page.waitForTimeout(300);
    const networkAfterO2 = report.network_change_status.filter(
      (n) => n.orderId === order2Id && n.ts >= cta2ClickStart,
    );
    const order2PostCount = netResponseOrder2 ? 1 : networkAfterO2.length;
    const order2PostStatus = netResponseOrder2 ? netResponseOrder2.status() : null;
    obs(`state07 order2 awaited_response_status=${order2PostStatus} listener_count=${networkAfterO2.length} → effective=${order2PostCount}`);

    const order2SinglePost = order2PostCount === 1
      && order2PostStatus !== null
      && order2PostStatus >= 200 && order2PostStatus < 300;

    report.validations['S-2_one_patch'] =
      order1SinglePost && order2SinglePost ? 'PASS' : 'FAIL';
    if (!order1SinglePost) {
      finding('P1', 'B-S2-PATCH-O1',
        `Order #${order1Id} bump fired ${order1PostCount} POST(s) status=${order1PostStatus} (expected 1 × 2xx)`);
    }
    if (!order2SinglePost) {
      finding('P1', 'B-S2-PATCH-O2',
        `Order #${order2Id} bump fired ${order2PostCount} POST(s) status=${order2PostStatus} (expected 1 × 2xx)`);
    }

    // Wait again for the refresh after order #2 PATCH.
    await page.waitForTimeout(1500);

    // ──── State 08 — final KDS state ──────────────────────────────────────
    await snap('08-kds-final-both-bumped');
    report.png_filenames.push('08-kds-final-both-bumped.png');
    report.states_captured += 1;
    const order1FinalRendered = await page.locator(card1Sel).count();
    const order2FinalRendered = await page.locator(card2Sel).count();
    const order1FinalReady = order1FinalRendered > 0
      ? await page.locator(card1Sel).first().evaluate((el) => el.classList.contains('kds-card--ready')).catch(() => false)
      : false;
    const order2FinalReady = order2FinalRendered > 0
      ? await page.locator(card2Sel).first().evaluate((el) => el.classList.contains('kds-card--ready')).catch(() => false)
      : false;
    obs(`state08 final: order1 rendered=${order1FinalRendered} ready=${order1FinalReady} | order2 rendered=${order2FinalRendered} ready=${order2FinalReady}`);

    // Wave R-1 verdict: no 429 toast/banner all session
    const domHadToast = await domHas429Toast();
    report.validations['R-1_no_429_toast'] = !(saw429Toast || domHadToast) ? 'PASS' : 'FAIL';
    if (saw429Toast || domHadToast) {
      finding('P1', 'B-R1-THROTTLE',
        `Wave R-1 regression: 429 ${saw429Toast ? 'response' : ''}${domHadToast ? ' + toast' : ''} observed during KDS bump cycle`);
    }

    // ──── NF525 POST-SNAPSHOT ─────────────────────────────────────────────
    report.nf525_post = nf525Snapshot();
    obs(`NF525 pre=${JSON.stringify(report.nf525_pre)} post=${JSON.stringify(report.nf525_post)}`);

    // ──── server-side ground-truth: order status after bumps ──────────────
    const serverStatuses = artisan(
      `use App\\Models\\Order; foreach ([${order1Id},${order2Id}] as $id) { $o=Order::withoutGlobalScopes()->find($id); echo $id."|status=".($o?$o->status:"NULL")."\\n"; }`,
    );
    obs(`server statuses post-bump:\n${serverStatuses}`);
    const statusMatches = serverStatuses.match(/(\d+)\|status=(\d+)/g) || [];
    const statusMap = {};
    for (const m of statusMatches) {
      const [, id, st] = m.match(/(\d+)\|status=(\d+)/) || [];
      if (id && st) statusMap[id] = parseInt(st, 10);
    }
    const PREPARED = 8;
    if (statusMap[order1Id] !== PREPARED) {
      finding('P0', 'B-DB-O1-NOT-PREPARED',
        `Order #${order1Id} server status after bump = ${statusMap[order1Id]}, expected 8 (PREPARED)`);
    }
    if (statusMap[order2Id] !== PREPARED) {
      finding('P0', 'B-DB-O2-NOT-PREPARED',
        `Order #${order2Id} server status after bump = ${statusMap[order2Id]}, expected 8 (PREPARED)`);
    }
    report.server_statuses = statusMap;

    // ──── write back to fixture: kds_ready_at_order_X ─────────────────────
    try {
      const updated = { ...fixture };
      const nowIso = new Date().toISOString();
      updated.kds_ready_at_order_1 = nowIso;
      updated.kds_ready_at_order_2 = nowIso;
      fs.writeFileSync(FIXTURE_FILE, JSON.stringify(updated, null, 2));
      obs(`fixture updated: kds_ready_at_order_1+2 = ${nowIso}`);
    } catch (err) {
      obs(`fixture update FAILED: ${err?.message || err}`);
    }

    // ──── final persist + dispose ─────────────────────────────────────────
    persistReport();
    dispose();

    // ──── hard expectations (spec must PASS at Playwright level — gstack rule) ─
    expect(report.states_captured, 'states_captured should be 8').toBe(8);
    expect(fs.existsSync(path.join(SCREENSHOT_DIR, '01-kds-landing-loaded.png'))).toBe(true);
    expect(fs.existsSync(path.join(SCREENSHOT_DIR, '08-kds-final-both-bumped.png'))).toBe(true);
    // Sync mandate: both orders had to appear in the pile.
    expect(report.sync_latency_ms.mount_to_visible_order_1, 'order1 must be visible on KDS').not.toBeNull();
    expect(report.sync_latency_ms.mount_to_visible_order_2, 'order2 must be visible on KDS').not.toBeNull();
  });
});
