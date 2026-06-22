/**
 * Supervisor Wave D — T2-Idempotency-Race
 *
 * Validates IdempotencyKeyMiddleware (frozen-zone §7) behavior under:
 *   A1 — Same key + same payload, double-fire concurrent → 1×201 + 1×replay
 *   A2 — Same key + different payload → second call 409 IDEMPOTENCY_KEY_CONFLICT
 *   A3 — Cross-user same key → both succeed (scope includes user_id/branch_id)
 *   A4 — Cross-branch same key (theoretical V1 — admin branch=0 vs pos branch=1)
 *   A5 — Missing X-Idempotency-Key on required endpoint → 422 MISSING_*
 *   A6 — Concurrent same-branch double-status-change → 1 success + 1 replay
 *
 * Endpoint exercised: POST /api/admin/pos-order/change-status/{order}
 *   - Idempotency-required (config/idempotency.php required_routes)
 *   - Payload { status: <int> } → small, deterministic body hash
 *   - 200 success on valid transition → fills cache → enables replay tests
 *
 * Scope of the middleware key (verified by reading
 * IdempotencyKeyMiddleware.php:76-82):
 *   `idempotency:v1:{branch_id}:{user_id}:sha256(client_key)`
 *
 * Output: reports/test-e2e/supervisor-wave-d-2026-05-28/IDEMPRACE/findings.json
 */

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAdmin, createApiContext, DEFAULT_BASE_URL } = require('./helpers/admin-auth');
const { generateIdempotencyKey } = require('./helpers/idempotency-key');

const OUT_DIR = path.join(
  process.cwd(),
  'reports/test-e2e/supervisor-wave-d-2026-05-28/IDEMPRACE',
);
if (!fs.existsSync(OUT_DIR)) fs.mkdirSync(OUT_DIR, { recursive: true });
const FINDINGS_FILE = path.join(OUT_DIR, 'findings.json');

const findings = {
  generated_at: new Date().toISOString(),
  branch: process.env.GIT_BRANCH || 'feature/mobile-app-le-cayenne-2026-05-10',
  base_url: DEFAULT_BASE_URL,
  middleware_under_test: 'IdempotencyKeyMiddleware (frozen-zone §7)',
  scope_formula: 'idempotency:v1:{branch_id}:{user_id}:sha256(key)',
  endpoint: 'POST /api/admin/pos-order/change-status/{order}',
  scenarios: {},
  verdict: 'PENDING',
  blockers: [],
};

function record(scenario, payload) {
  findings.scenarios[scenario] = {
    ...payload,
    recorded_at: new Date().toISOString(),
  };
  fs.writeFileSync(FINDINGS_FILE, JSON.stringify(findings, null, 2));
}

// status enum constants mirror app/Enums/OrderStatus.php
const STATUS = {
  ACCEPT: 4,
  PREPARING: 7,
  PREPARED: 8,
};

// Pick a fresh status-ACCEPT (4) order without burning the same id twice.
// Tinker-style query via a helper artisan endpoint would be ideal, but we
// avoid touching the app surface; instead we hardcode a pool gathered at
// session start (see findings.json `pre_flight.candidate_orders`).
const ORDER_POOL = [256, 235, 234, 233, 232, 231, 224, 203, 202, 201];
let poolIdx = 0;
function nextOrder() {
  if (poolIdx >= ORDER_POOL.length) {
    throw new Error('order pool exhausted — increase ORDER_POOL or refresh status=4 seeds');
  }
  return ORDER_POOL[poolIdx++];
}

test.describe('Supervisor Wave D — IDEMPRACE', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(60_000);

  let adminToken;
  let adminContext;
  let posToken;
  let posContext;

  test.beforeAll(async () => {
    const adminLogin = await loginAdmin({ email: 'admin@lecayenne.fr', password: '123456' });
    adminToken = adminLogin.token;
    adminContext = adminLogin.apiContext;
    findings.admin_branch_id = adminLogin.branchId;

    const posLogin = await loginAdmin({ email: 'pos@lecayenne.fr', password: '123456' });
    posToken = posLogin.token;
    posContext = posLogin.apiContext;
    findings.pos_branch_id = posLogin.branchId;
    fs.writeFileSync(FINDINGS_FILE, JSON.stringify(findings, null, 2));
  });

  test.afterAll(async () => {
    if (adminContext) await adminContext.dispose();
    if (posContext) await posContext.dispose();
    findings.completed_at = new Date().toISOString();
    fs.writeFileSync(FINDINGS_FILE, JSON.stringify(findings, null, 2));
  });

  // --------------------------------------------------------------------------
  // A1 — Same key + same payload double-fire (sequential — replay path)
  // Race-mode: two parallel requests with same key+payload. One must persist
  // the response, the other must serve from the replay cache OR receive 425.
  // --------------------------------------------------------------------------
  test('A1 — same key + same payload double-fire (concurrent)', async () => {
    const orderId = nextOrder();
    const key = generateIdempotencyKey('IDEMPRACE-A1');
    const body = { status: STATUS.PREPARING };

    const post = () =>
      adminContext.post(`/api/admin/pos-order/change-status/${orderId}`, {
        headers: { 'X-Idempotency-Key': key },
        data: body,
      });

    const [r1, r2] = await Promise.all([post(), post()]);
    const s1 = r1.status();
    const s2 = r2.status();
    const h1 = r1.headers();
    const h2 = r2.headers();
    const b1 = await r1.text().catch(() => '');
    const b2 = await r2.text().catch(() => '');

    const successes = [s1, s2].filter((s) => s >= 200 && s < 300).length;
    const replayHeaders = [h1, h2].filter((h) => h['idempotency-replayed'] === 'true').length;
    const inFlights = [s1, s2].filter((s) => s === 425).length;

    // Verdict logic:
    //   - Best:   1 success (no replay header) + 1 success (replay header) → BULLETPROOF
    //   - Accept: 1 success (no replay header) + 1×425 (race lost, retryable) → ACCEPTABLE
    //   - Fail:   2×201 with no replay header (double-execute) → BREACH
    let pass = false;
    let interpretation = 'unknown';
    if (successes === 2 && replayHeaders >= 1) {
      pass = true;
      interpretation = 'replay-cache served second request — bulletproof';
    } else if (successes === 1 && inFlights === 1) {
      pass = true;
      interpretation = 'race-loser returned 425 IN_FLIGHT — acceptable, retryable';
    } else if (successes === 2 && replayHeaders === 0) {
      pass = false;
      interpretation = 'BREACH: both succeeded without replay marker — double-execute possible';
    } else {
      pass = false;
      interpretation = `unexpected statuses s1=${s1} s2=${s2}`;
    }

    record('A1', {
      order_id: orderId,
      key,
      r1: { status: s1, replayed: h1['idempotency-replayed'] === 'true', body_snippet: b1.slice(0, 200) },
      r2: { status: s2, replayed: h2['idempotency-replayed'] === 'true', body_snippet: b2.slice(0, 200) },
      pass,
      interpretation,
    });
    expect(pass, `A1 verdict failed — ${interpretation}`).toBe(true);
  });

  // --------------------------------------------------------------------------
  // A2 — Same key + DIFFERENT payload → 409 IDEMPOTENCY_KEY_CONFLICT
  // --------------------------------------------------------------------------
  test('A2 — same key + different payload returns 409 conflict', async () => {
    const orderId = nextOrder();
    const key = generateIdempotencyKey('IDEMPRACE-A2');
    const body1 = { status: STATUS.PREPARING };
    const body2 = { status: STATUS.PREPARED };

    const r1 = await adminContext.post(`/api/admin/pos-order/change-status/${orderId}`, {
      headers: { 'X-Idempotency-Key': key },
      data: body1,
    });
    const s1 = r1.status();
    const b1 = await r1.text().catch(() => '');

    const r2 = await adminContext.post(`/api/admin/pos-order/change-status/${orderId}`, {
      headers: { 'X-Idempotency-Key': key },
      data: body2,
    });
    const s2 = r2.status();
    const b2 = await r2.text().catch(() => '');
    let parsed2 = null;
    try { parsed2 = JSON.parse(b2); } catch (_) {}

    const pass =
      s1 >= 200 && s1 < 300 &&
      s2 === 409 &&
      (parsed2?.code === 'IDEMPOTENCY_KEY_CONFLICT');

    record('A2', {
      order_id: orderId,
      key,
      r1: { status: s1, body_snippet: b1.slice(0, 200) },
      r2: { status: s2, code: parsed2?.code, body_snippet: b2.slice(0, 250) },
      pass,
      interpretation: pass
        ? 'middleware rejected payload-hash mismatch — bulletproof'
        : `expected 1=2xx + 2=409 IDEMPOTENCY_KEY_CONFLICT, got s1=${s1} s2=${s2} code=${parsed2?.code}`,
    });
    expect(pass).toBe(true);
  });

  // --------------------------------------------------------------------------
  // A3 — Cross-user same key, distinct scope, both succeed
  // admin (branch=0 Admin role) vs pos@ (branch=1 POS Operator)
  // --------------------------------------------------------------------------
  test('A3 — cross-user same key produces distinct scopes', async () => {
    const orderId = nextOrder();
    const key = generateIdempotencyKey('IDEMPRACE-A3');
    const body = { status: STATUS.PREPARING };

    const r1 = await adminContext.post(`/api/admin/pos-order/change-status/${orderId}`, {
      headers: { 'X-Idempotency-Key': key },
      data: body,
    });
    const s1 = r1.status();
    const h1 = r1.headers();
    const b1 = await r1.text().catch(() => '');

    // pos user retries with same key — should NOT hit admin's cache slot.
    // Note: order is now in PREPARING after r1, so r2 may succeed (no-op state-machine)
    // OR may 422 from controller. EITHER WAY, the idempotency-replay header MUST
    // be absent because the scope key differs by user_id.
    const r2 = await posContext.post(`/api/admin/pos-order/change-status/${orderId}`, {
      headers: { 'X-Idempotency-Key': key },
      data: body,
    });
    const s2 = r2.status();
    const h2 = r2.headers();
    const b2 = await r2.text().catch(() => '');

    const r2WasReplay = h2['idempotency-replayed'] === 'true';
    // Pass = admin success + pos request was NOT served from admin's replay cache.
    // pos may legitimately get 422 (already in PREPARING) but must NEVER receive
    // a replayed admin response.
    const pass = s1 >= 200 && s1 < 300 && !r2WasReplay;

    record('A3', {
      order_id: orderId,
      key,
      r1_admin: { status: s1, replayed: h1['idempotency-replayed'] === 'true', body_snippet: b1.slice(0, 150) },
      r2_pos: { status: s2, replayed: r2WasReplay, body_snippet: b2.slice(0, 200) },
      pass,
      interpretation: pass
        ? 'pos user did NOT receive admin replay — scope key includes user_id'
        : 'BREACH: pos user received admin replay → user_id missing from scope key',
    });
    expect(pass).toBe(true);
  });

  // --------------------------------------------------------------------------
  // A4 — Cross-branch same key
  // V1 single-branch — admin branch=0 vs pos branch=1 already differentiates.
  // This test is REDUNDANT with A3 but explicitly asserts the branch_id
  // component of the scope key.
  // --------------------------------------------------------------------------
  test('A4 — cross-branch same key produces distinct scopes (V1 admin=0 vs pos=1)', async () => {
    const orderId = nextOrder();
    const key = generateIdempotencyKey('IDEMPRACE-A4');
    const body = { status: STATUS.PREPARING };

    const r1 = await adminContext.post(`/api/admin/pos-order/change-status/${orderId}`, {
      headers: { 'X-Idempotency-Key': key },
      data: body,
    });
    const s1 = r1.status();
    const b1 = await r1.text().catch(() => '');

    const r2 = await posContext.post(`/api/admin/pos-order/change-status/${orderId}`, {
      headers: { 'X-Idempotency-Key': key },
      data: body,
    });
    const s2 = r2.status();
    const h2 = r2.headers();
    const b2 = await r2.text().catch(() => '');
    const r2WasReplay = h2['idempotency-replayed'] === 'true';
    const pass = s1 >= 200 && s1 < 300 && !r2WasReplay;

    record('A4', {
      order_id: orderId,
      key,
      admin_branch_id: findings.admin_branch_id,
      pos_branch_id: findings.pos_branch_id,
      r1_admin: { status: s1, body_snippet: b1.slice(0, 150) },
      r2_pos: { status: s2, replayed: r2WasReplay, body_snippet: b2.slice(0, 200) },
      pass,
      interpretation: pass
        ? 'cross-branch key isolation confirmed — V1 single-branch theoretical path OK'
        : 'BREACH: cross-branch replay leak',
    });
    expect(pass).toBe(true);
  });

  // --------------------------------------------------------------------------
  // A5 — Missing X-Idempotency-Key on required endpoint → 422
  // --------------------------------------------------------------------------
  test('A5 — missing idempotency key returns 422 MISSING_IDEMPOTENCY_KEY', async () => {
    const orderId = nextOrder();
    const body = { status: STATUS.PREPARING };

    const r = await adminContext.post(`/api/admin/pos-order/change-status/${orderId}`, {
      data: body, // no X-Idempotency-Key header
    });
    const s = r.status();
    const b = await r.text().catch(() => '');
    let parsed = null;
    try { parsed = JSON.parse(b); } catch (_) {}

    // MissingIdempotencyKeyException → Laravel render → 422 default
    // Body shape may vary (handler render path), so we look for hints in either
    // `code`, `message`, or `error` containing "idempotency".
    const looksLikeIdempotencyMissing =
      s === 422 &&
      (
        (parsed?.code && /IDEMPOTENCY/i.test(parsed.code)) ||
        (typeof parsed?.message === 'string' && /idempot/i.test(parsed.message)) ||
        (typeof parsed?.error === 'string' && /idempot/i.test(parsed.error)) ||
        /idempot/i.test(b)
      );

    record('A5', {
      order_id: orderId,
      r: { status: s, code: parsed?.code, message: parsed?.message, body_snippet: b.slice(0, 300) },
      pass: looksLikeIdempotencyMissing,
      interpretation: looksLikeIdempotencyMissing
        ? 'missing key on required route → 422 with idempotency hint — enforcement OK'
        : `expected 422 with idempotency-related code/message; got s=${s} body="${b.slice(0, 200)}"`,
    });
    expect(looksLikeIdempotencyMissing).toBe(true);
  });

  // --------------------------------------------------------------------------
  // A6 — Concurrent same-branch double-status-change to the SAME order
  // Same user (admin), same key, fired truly in parallel via Promise.all.
  // Validates Cache::lock (Redis SET NX EX) + DB FOR UPDATE triple defense.
  // --------------------------------------------------------------------------
  test('A6 — concurrent double-fire on same order yields exactly one execution', async () => {
    const orderId = nextOrder();
    const key = generateIdempotencyKey('IDEMPRACE-A6');
    const body = { status: STATUS.PREPARING };

    const burst = await Promise.all([
      adminContext.post(`/api/admin/pos-order/change-status/${orderId}`, {
        headers: { 'X-Idempotency-Key': key },
        data: body,
      }),
      adminContext.post(`/api/admin/pos-order/change-status/${orderId}`, {
        headers: { 'X-Idempotency-Key': key },
        data: body,
      }),
      adminContext.post(`/api/admin/pos-order/change-status/${orderId}`, {
        headers: { 'X-Idempotency-Key': key },
        data: body,
      }),
    ]);

    const summaries = await Promise.all(
      burst.map(async (r) => {
        const txt = await r.text().catch(() => '');
        return {
          status: r.status(),
          replayed: r.headers()['idempotency-replayed'] === 'true',
          body_snippet: txt.slice(0, 120),
        };
      }),
    );

    const succ = summaries.filter((s) => s.status >= 200 && s.status < 300).length;
    const repl = summaries.filter((s) => s.replayed).length;
    const inFlight = summaries.filter((s) => s.status === 425).length;
    const nonReplayedSuccess = summaries.filter(
      (s) => s.status >= 200 && s.status < 300 && !s.replayed,
    ).length;

    // Exactly ONE non-replayed success allowed. Others must be either replay
    // (Idempotency-Replayed: true) or 425 IDEMPOTENCY_IN_FLIGHT.
    const pass = nonReplayedSuccess === 1 && (repl + inFlight) === summaries.length - 1;

    record('A6', {
      order_id: orderId,
      key,
      summaries,
      counts: { success: succ, replayed: repl, in_flight: inFlight, non_replayed_success: nonReplayedSuccess },
      pass,
      interpretation: pass
        ? '1 real execution + 2 protected by cache/lock — triple defense holds'
        : `BREACH: got ${nonReplayedSuccess} non-replayed successes (expected exactly 1)`,
    });
    expect(pass).toBe(true);
  });

  // --------------------------------------------------------------------------
  // FINAL — write verdict
  // --------------------------------------------------------------------------
  test('FINAL — emit verdict', async () => {
    const allPass = Object.values(findings.scenarios).every((s) => s.pass === true);
    findings.verdict = allPass ? 'IDEMPOTENCY_BULLETPROOF' : 'NEEDS_HEAL';
    if (!allPass) {
      findings.blockers = Object.entries(findings.scenarios)
        .filter(([, v]) => v.pass !== true)
        .map(([k, v]) => ({ scenario: k, interpretation: v.interpretation }));
    }
    fs.writeFileSync(FINDINGS_FILE, JSON.stringify(findings, null, 2));
    expect(findings.verdict).toBe('IDEMPOTENCY_BULLETPROOF');
  });
});
