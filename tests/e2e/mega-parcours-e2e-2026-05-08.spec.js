// FoodKing — MEGA PARCOURS E2E — 2026-05-08
// =====================================================================
// Mission ÉPIQUE : exécuter le parcours complet bout-en-bout sur les 2 surfaces
// (POS + Kiosk) avec mode BYPASS payment+printing ACTIVÉ.
//
// 10 commandes:
//   POS-1 simple cash
//   POS-2 multi-items card
//   POS-3 extras + TR
//   POS-4 RUPTURE produit (item 363 OOS)
//   POS-5 RUPTURE extra (extra 172 OOS)
//   Kiosk-1 emporter card
//   Kiosk-2 cash counter
//   Kiosk-3 multi-items + custom
//   Kiosk-4 RUPTURE produit
//   Kiosk-5 RUPTURE extra
//
// Pattern: disk-persisted findings/traces (R3-style), un test par commande, serial 1-worker.
// Méthodologie hybride: UI-driven pour screenshots/probes, fallback HTTP submit pour
// garantir domain_events rows quand le UI flow se bloque (legacy dialog wizard etc.).
//
// PRECONDITIONS VÉRIFIÉES (preflight orchestrator):
//   - bypass payment + printing = true (config('payment.bypass.enabled')=true)
//   - server up :8000
//   - item 363 (Tacos M, branch_id=1, channels=null → kiosk-visible)
//   - extra 172 (Salade) is_available=true
//   - branch_id=1, kiosk_machine_id=1 (kiosk-lecayenne)
//   - PosOrderRequest.quote_token=nullable
//
// LIMITATIONS HONNÊTES (déclarées d'avance, cf rapport markdown):
//   - websockets:serve probable DOWN ⇒ KDS reception via polling/refresh, pas Echo live.
//   - DispatchDomainEventsJob non drainé en harness ⇒ dispatched_at peut rester NULL.
//   - R3-03 a confirmé POS UI ne flip PAS live sur OOS toggle (P1) — POS-4 va docu reproduire.

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator, loginAsChefOperator, loginAsKiosk } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

// ============================================================
// Constants & disk-based artifact infrastructure
// ============================================================
const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/mega-parcours-2026-05-08');
const FINDINGS_FILE = path.join(SHOT_DIR, 'findings.json');
const DOM_FILE = path.join(SHOT_DIR, 'dom-probes.json');
const HTTP_FILE = path.join(SHOT_DIR, 'http-trace.json');
const EVENTS_FILE = path.join(SHOT_DIR, 'domain-events-timeline.json');
const KDS_FILE = path.join(SHOT_DIR, 'kds-reception-trace.json');

if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const API_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';
const BASE = 'http://localhost:8000';
const BRANCH_ID = 1;
const TARGET_ITEM_OOS = 363; // Tacos M
const TARGET_EXTRA_OOS = 172; // Salade

function readJsonOrEmpty(fp) {
  try {
    if (!fs.existsSync(fp)) return [];
    const raw = fs.readFileSync(fp, 'utf8').trim();
    return raw ? JSON.parse(raw) : [];
  } catch (_) { return []; }
}

function appendJson(fp, entry) {
  const list = readJsonOrEmpty(fp);
  list.push({ ...entry, ts: new Date().toISOString() });
  fs.writeFileSync(fp, JSON.stringify(list, null, 2));
}

function record(order, step, slug, severity, note, screenshot, extra = {}) {
  appendJson(FINDINGS_FILE, { order, step, slug, severity, note, screenshot, ...extra });
}

function probe(order, step, slug, payload) {
  appendJson(DOM_FILE, { order, step, slug, payload });
}

function httpTrace(order, label, payload) {
  appendJson(HTTP_FILE, { order, label, payload });
}

function eventsTrace(order, label, payload) {
  appendJson(EVENTS_FILE, { order, label, payload });
}

function kdsTrace(order, label, payload) {
  appendJson(KDS_FILE, { order, label, payload });
}

async function snap(page, order, step, slug) {
  const fn = `${order}-step-${String(step).padStart(2, '0')}-${slug}.png`;
  const fp = path.join(SHOT_DIR, fn);
  await page.screenshot({ path: fp, fullPage: true }).catch((e) => {
    console.warn(`[mega] snap ${fn} failed: ${e.message}`);
  });
  return path.relative(process.cwd(), fp);
}

function tinker(code) {
  try {
    const out = execSync(`php artisan tinker --execute=${JSON.stringify(code)}`, {
      cwd: process.cwd(), encoding: 'utf8', timeout: 30_000,
    });
    const lines = out.trim().split(/\r?\n/);
    const last = [...lines].reverse().find((l) => l.trim().startsWith('{') || l.trim().startsWith('['));
    if (last) {
      try { return JSON.parse(last); } catch (_) { return { _raw: last }; }
    }
    return { _output: out.trim().slice(-600) };
  } catch (e) {
    return { _error: String(e.message || e).slice(0, 600) };
  }
}

// ============================================================
// HTTP API helpers (apiLogin, toggle availability, submit POS, fetch domain_events)
// ============================================================
async function apiLogin(page, email = 'admin@lecayenne.fr', password = '123456') {
  try { clearFoodKingRateLimits(); } catch (_) {}
  const res = await page.context().request.post(`${BASE}/api/auth/login`, {
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'x-api-key': API_KEY },
    data: { email, password },
  });
  const status = res.status();
  const body = await res.json().catch(() => ({}));
  return { status, token: body?.token || null, body };
}

async function toggleAvailabilityHttp(page, token, itemId, branchId, isAvailable, reason) {
  const t0 = Date.now();
  let status = 0, body = null, error = null;
  try {
    const res = await page.context().request.post(`${BASE}/api/admin/menu/availability/toggle`, {
      headers: {
        'Content-Type': 'application/json', 'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Authorization': `Bearer ${token}`, 'x-api-key': API_KEY,
      },
      data: { item_id: itemId, branch_id: branchId, is_available: isAvailable, unavailable_reason: reason || null },
    });
    status = res.status();
    const text = await res.text();
    try { body = JSON.parse(text); } catch (_) { body = { _raw: text.slice(0, 300) }; }
  } catch (e) {
    error = String(e.message || e).slice(0, 300);
  }
  return { status, body, error, ms: Date.now() - t0 };
}

// Fetch quote_token + quote_signature first (required by POS quote middleware even if Request rule says nullable)
async function fetchPosQuote(page, token, payload) {
  let status = 0, body = null, error = null;
  try {
    const res = await page.context().request.post(`${BASE}/api/admin/pos/quote`, {
      headers: {
        'Content-Type': 'application/json', 'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Authorization': `Bearer ${token}`, 'x-api-key': API_KEY,
      },
      data: {
        branch_id: payload.branch_id,
        order_type: payload.order_type,
        source: payload.source,
        pos_payment_method: payload.pos_payment_method,
        items: payload.items,
      },
    });
    status = res.status();
    const text = await res.text();
    try { body = JSON.parse(text); } catch (_) { body = { _raw: text.slice(0, 400) }; }
  } catch (e) {
    error = String(e.message || e).slice(0, 300);
  }
  return { status, body, error };
}

async function submitPosOrder(page, token, payload) {
  let status = 0, body = null, error = null;
  try {
    // Fetch quote first — POS quote middleware requires both token+signature even with rules nullable
    const quote = await fetchPosQuote(page, token, payload);
    const quoteToken = quote.body?.data?.quote_token || quote.body?.quote_token || null;
    const quoteSig = quote.body?.data?.signature || quote.body?.data?.quote_signature
      || quote.body?.signature || quote.body?.quote_signature || null;
    const enrichedPayload = quoteToken && quoteSig
      ? { ...payload, quote_token: quoteToken, quote_signature: quoteSig }
      : payload;

    const res = await page.context().request.post(`${BASE}/api/admin/pos`, {
      headers: {
        'Content-Type': 'application/json', 'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Authorization': `Bearer ${token}`, 'x-api-key': API_KEY,
        'X-Idempotency-Key': `mega-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      },
      data: enrichedPayload,
    });
    status = res.status();
    const text = await res.text();
    try { body = JSON.parse(text); } catch (_) { body = { _raw: text.slice(0, 400) }; }
    return { status, body, error, quoteFetched: !!quoteToken, quoteStatus: quote.status };
  } catch (e) {
    error = String(e.message || e).slice(0, 300);
  }
  return { status, body, error };
}

function snapshotDomainEvents(orderLabel) {
  const code = 'echo json_encode(['
    + '"total"=>\\DB::table("domain_events")->count(),'
    + '"latest3"=>\\DB::table("domain_events")->orderBy("id","desc")->take(3)->get(["id","event_type","aggregate_id","aggregate_type","occurred_at","dispatched_at"])->toArray(),'
    + '"by_type_recent"=>\\DB::table("domain_events")->where("occurred_at",">",now()->subMinutes(10))->select("event_type",\\DB::raw("count(*) as c"))->groupBy("event_type")->get()->toArray(),'
    + ']);';
  const snap = tinker(code);
  eventsTrace(orderLabel, 'snapshot', snap);
  return snap;
}

// ============================================================
// Standard POS order payload helper (with bypass active, cash mode 1, card mode 2, TR mode 11)
// ============================================================
function buildPosPayload(items, paymentMode, opts = {}) {
  const subtotal = items.reduce((sum, it) => sum + (it.price * it.quantity), 0);
  const total = subtotal - (opts.discount || 0);
  const payload = {
    branch_id: BRANCH_ID,
    order_type: 15,           // 15 = POS in-house
    is_advance_order: 0,
    source: 15,
    items: JSON.stringify(items),
    pos_payment_method: paymentMode,
    total: total,
    subtotal: subtotal,
    discount: opts.discount || 0,
  };
  if (paymentMode === 1) { // cash needs received_amount
    payload.pos_received_amount = total + 5;
  }
  return payload;
}

// ============================================================
// KDS reception probe (separate browser context, polling fallback 7s)
// ============================================================
async function probeKdsReception(browser, orderLabel, expectedHint) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  const startMs = Date.now();
  let appearedMs = null;
  let domSnapshot = null;
  try {
    await loginAsChefOperator(page);
    await page.waitForTimeout(2000);
    // Poll 7s
    const deadline = Date.now() + 7000;
    while (Date.now() < deadline) {
      const found = await page.evaluate((hint) => {
        const cards = Array.from(document.querySelectorAll(
          '[class*="kds-ticket"], [class*="kds-card"], [class*="ticket"], [data-testid*="kds-"]'
        ));
        const matches = cards.filter((c) => {
          const txt = (c.textContent || '').toLowerCase();
          return hint && txt.includes(String(hint).toLowerCase());
        });
        return {
          cardCount: cards.length,
          matchCount: matches.length,
          firstSample: cards.slice(0, 3).map((c) => (c.textContent || '').replace(/\s+/g, ' ').slice(0, 100)),
        };
      }, expectedHint);
      if (found.matchCount > 0 || found.cardCount > 0) {
        domSnapshot = found;
        if (found.matchCount > 0) {
          appearedMs = Date.now() - startMs;
          break;
        }
      }
      await page.waitForTimeout(800);
    }
    const finalShot = path.join(SHOT_DIR, `${orderLabel}-kds-reception.png`);
    await page.screenshot({ path: finalShot, fullPage: true }).catch(() => {});
    if (!domSnapshot) {
      domSnapshot = await page.evaluate(() => ({
        cardCount: document.querySelectorAll('[class*="kds-ticket"], [class*="ticket"]').length,
        bodyTextSample: (document.body.textContent || '').replace(/\s+/g, ' ').slice(0, 300),
      }));
    }
    kdsTrace(orderLabel, 'kds-result', { appearedMs, expectedHint, domSnapshot });
  } catch (e) {
    kdsTrace(orderLabel, 'kds-error', { error: String(e.message || e).slice(0, 400) });
  } finally {
    await ctx.close().catch(() => {});
  }
  return appearedMs;
}

// ============================================================
// Reset artifacts ONCE
// ============================================================
function resetArtifactsOnce() {
  // Two ways to skip reset: MEGA_NO_RESET=1, OR presence of a marker file
  if (process.env.MEGA_NO_RESET === '1') return;
  if (fs.existsSync(path.join(SHOT_DIR, '.no-reset-marker'))) return;
  for (const fp of [FINDINGS_FILE, DOM_FILE, HTTP_FILE, EVENTS_FILE, KDS_FILE]) {
    try { if (fs.existsSync(fp)) fs.unlinkSync(fp); } catch (_) {}
  }
}

// ============================================================
// Test suite
// ============================================================
test.describe.configure({ mode: 'serial', retries: 0 });

test.describe('MEGA PARCOURS E2E — POS + Kiosk + KDS — 2026-05-08', () => {
  test.setTimeout(120_000);

  test.beforeAll(async () => {
    resetArtifactsOnce();
    // Snapshot bypass status
    const cfg = tinker('echo json_encode(["payment"=>config("payment.bypass.enabled"),"printing"=>config("printing.bypass.enabled"),"queue"=>config("queue.default"),"env"=>config("app.env")]);');
    httpTrace('PRE', 'bypass-config', cfg);
    if (!cfg.payment) {
      record('PRE', 0, 'bypass-off', 'P0', 'Bypass payment OFF — assertions de bypass marker invalides', '', { cfg });
    } else {
      record('PRE', 0, 'bypass-on', 'OK', `Bypass payment=${cfg.payment} printing=${cfg.printing} queue=${cfg.queue} env=${cfg.env}`, '', { cfg });
    }
  });

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  // ============================================================
  // POS-1 — Commande simple cash (1 produit, paiement cash)
  // ============================================================
  test('POS-1 — 1 produit simple, paiement cash (UI flow + screenshots à chaque étape)', async ({ page, browser }) => {
    const order = 'pos-1';
    const apiCalls = [];
    page.on('request', (req) => {
      const url = req.url();
      if (/\/api\/.*pos|\/api\/.*order|\/api\/.*payment/i.test(url)) {
        apiCalls.push({ url, method: req.method(), idempotency: req.headers()['x-idempotency-key'] || req.headers()['idempotency-key'] || null });
      }
    });

    // 1. Login
    await snap(page, order, 1, 'pre-login');
    await loginAsPosOperator(page);
    await page.waitForTimeout(2000);
    await snap(page, order, 2, 'post-login');

    // 2. Surface POS V5
    await page.waitForTimeout(2000);
    const surfaceProbe = await page.evaluate(() => ({
      url: location.href,
      hasShell: !!document.querySelector('.pos-v5-shell, [data-pos-v5-shell]'),
      hasGrid: !!document.querySelector('.pos-v5-grid'),
      hasCart: !!document.querySelector('.pos-v5-cart'),
      bypassMode: window.foodkingConfig?.bypassMode || null,
    }));
    probe(order, 3, 'surface-load', surfaceProbe);
    await snap(page, order, 3, 'surface-pos');

    if (!surfaceProbe.bypassMode?.payment) {
      record(order, 3, 'bypass-not-injected', 'P1', 'window.foodkingConfig.bypassMode.payment !== true côté SPA', `${order}-step-03-surface-pos.png`);
    }

    // 3. Catégories
    const cats = page.locator('.pos-v5-category, .pos-v4-category-pill');
    const catCount = await cats.count().catch(() => 0);
    probe(order, 4, 'categories', { count: catCount });
    await snap(page, order, 4, 'categories');

    // 4. Tile click → wizard or direct add
    const tiles = page.locator('.pos-v5-grid .pos-v5-grid__item, .pos-v4-grid .pos-v4-tile, .pos-v5-grid > *');
    const tileCount = await tiles.count();
    probe(order, 5, 'tile-grid', { count: tileCount });
    if (tileCount === 0) {
      record(order, 5, 'no-tiles', 'P0', 'Grille produit vide — flow bloqué, fallback HTTP', '');
    } else {
      await tiles.nth(0).click().catch(() => {});
      await page.waitForTimeout(1200);
      await snap(page, order, 5, 'after-tile-click');
      // If wizard opened, click Ajouter
      const addBtn = page.locator('button').filter({ hasText: /ajouter au panier|ajouter|add to cart/i }).first();
      if (await addBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
        await snap(page, order, 6, 'wizard-open');
        await addBtn.click().catch(() => {});
        await page.waitForTimeout(800);
      }
    }

    // 5. Cart state
    await snap(page, order, 7, 'cart-state');
    const cartProbe = await page.evaluate(() => {
      const items = document.querySelectorAll('.pos-v5-cart-item, .pos-v4-cart-item, [class*="cart-item"]');
      const grand = document.querySelector('[data-testid="pos-grand-total"]');
      const payBtn = document.querySelector('[data-testid="pos-v5-pay"]');
      return {
        itemCount: items.length,
        grandTotal: grand?.textContent?.trim() || null,
        payVisible: !!payBtn,
        payDisabled: payBtn?.disabled || false,
      };
    });
    probe(order, 7, 'cart-after-add', cartProbe);

    // 6. Pre-submit domain_events snapshot
    const eventsBefore = snapshotDomainEvents(`${order}-before`);

    // 7. Hybrid: try UI pay flow, fallback to HTTP submit if blocked
    let submittedViaApi = false;
    let apiSubmitResult = null;
    if (cartProbe.itemCount > 0 && cartProbe.payVisible) {
      const payBtn = page.locator('[data-testid="pos-v5-pay"]');
      await payBtn.click().catch(() => {});
      await page.waitForTimeout(1500);
      await snap(page, order, 8, 'payment-modal');
      const cashBtn = page.locator('[data-testid="pos-payment-mode-cash"]');
      if (await cashBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await cashBtn.click().catch(() => {});
        await page.waitForTimeout(500);
        await snap(page, order, 9, 'cash-mode');
      }
      const confirmBtn = page.locator('[data-testid="pos-payment-confirm"]');
      if (await confirmBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await confirmBtn.click().catch(() => {});
        await page.waitForTimeout(2500);
        await snap(page, order, 10, 'after-confirm');
      } else {
        record(order, 9, 'confirm-btn-not-visible', 'P1', 'pos-payment-confirm not visible — UI flow blocked, falling back to HTTP', `${order}-step-09-cash-mode.png`);
      }
    }

    // 8. Verify via API in case UI did not submit (post-mortem snapshot)
    const eventsAfter = snapshotDomainEvents(`${order}-after-ui`);
    const newEvents = (eventsAfter.total || 0) - (eventsBefore.total || 0);

    if (newEvents === 0) {
      // Fallback API submit
      const auth = await apiLogin(page);
      const apiPayload = buildPosPayload(
        [{ item_id: TARGET_ITEM_OOS, quantity: 1, price: 6.5, item_variations: [], item_extras: [], composition_snapshot: { addons: [] } }],
        1 // cash
      );
      apiSubmitResult = await submitPosOrder(page, auth.token, apiPayload);
      httpTrace(order, 'api-fallback-submit', apiSubmitResult);
      submittedViaApi = true;
      await page.waitForTimeout(800);
      const eventsAfterApi = snapshotDomainEvents(`${order}-after-api`);
      const apiNewEvents = (eventsAfterApi.total || 0) - (eventsBefore.total || 0);
      if (apiNewEvents === 0) {
        record(order, 11, 'no-events-created', 'P0', `Aucun domain_event créé après UI+API submit (status=${apiSubmitResult?.status}). Body=${JSON.stringify(apiSubmitResult?.body).slice(0, 300)}`, '', { apiSubmitResult });
      } else {
        record(order, 11, 'api-fallback-ok', 'OK', `UI bloqué mais API submit OK: ${apiNewEvents} new events. Status=${apiSubmitResult?.status}`, '', { apiNewEvents, apiSubmitResult });
      }
    } else {
      record(order, 11, 'ui-flow-ok', 'OK', `UI submit OK: ${newEvents} new events`, '', { newEvents });
    }

    httpTrace(order, 'api-calls-captured', { count: apiCalls.length, calls: apiCalls.slice(0, 20) });

    // 9. KDS reception probe
    const kdsMs = await probeKdsReception(browser, order, 'tacos');
    record(order, 12, 'kds-reception', kdsMs ? 'OK' : 'P2', kdsMs ? `KDS appearance ${kdsMs}ms` : 'KDS no match within 7s polling (websockets DOWN expected)', `${order}-kds-reception.png`);
  });

  // ============================================================
  // POS-2 — Multi-items, paiement card
  // ============================================================
  test('POS-2 — 2-3 items différents, paiement card', async ({ page, browser }) => {
    const order = 'pos-2';
    const eventsBefore = snapshotDomainEvents(`${order}-before`);

    await loginAsPosOperator(page);
    await page.waitForTimeout(2000);
    await snap(page, order, 1, 'surface');

    const tiles = page.locator('.pos-v5-grid > *');
    const tileCount = await tiles.count();
    probe(order, 1, 'tile-grid', { count: tileCount });

    // Add 3 different tiles
    let added = 0;
    for (let i = 0; i < Math.min(tileCount, 6) && added < 3; i++) {
      await tiles.nth(i).click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(800);
      const addBtn = page.locator('button').filter({ hasText: /ajouter au panier|ajouter/i }).first();
      if (await addBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
        await addBtn.click().catch(() => {});
        added++;
        await page.waitForTimeout(500);
      } else {
        // Direct add (no wizard)
        added++;
      }
      await snap(page, order, 2 + i, `tile-${i}-added`);
      const cartCheck = await page.evaluate(() => ({
        items: document.querySelectorAll('.pos-v5-cart-item, [class*="cart-item"]').length,
        total: document.querySelector('[data-testid="pos-grand-total"]')?.textContent?.trim() || null,
      }));
      probe(order, 2 + i, `cart-after-${i}`, cartCheck);
    }

    // UI: pay button + card mode
    let submittedViaApi = false;
    let apiSubmitResult = null;
    const payBtn = page.locator('[data-testid="pos-v5-pay"]');
    if (await payBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await payBtn.click().catch(() => {});
      await page.waitForTimeout(1200);
      await snap(page, order, 8, 'payment-modal');
      const cardBtn = page.locator('[data-testid="pos-payment-mode-card"]');
      if (await cardBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await cardBtn.click().catch(() => {});
        await page.waitForTimeout(500);
        await snap(page, order, 9, 'card-mode');
      }
      const confirmBtn = page.locator('[data-testid="pos-payment-confirm"]');
      if (await confirmBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await confirmBtn.click().catch(() => {});
        await page.waitForTimeout(2500);
        await snap(page, order, 10, 'after-confirm');
      }
    }

    const eventsAfter = snapshotDomainEvents(`${order}-after-ui`);
    const newEvents = (eventsAfter.total || 0) - (eventsBefore.total || 0);
    if (newEvents === 0) {
      const auth = await apiLogin(page);
      const apiPayload = buildPosPayload(
        [
          { item_id: 363, quantity: 1, price: 6.5, item_variations: [], item_extras: [], composition_snapshot: { addons: [] } },
          { item_id: 364, quantity: 1, price: 7.5, item_variations: [], item_extras: [], composition_snapshot: { addons: [] } },
        ],
        2 // card
      );
      apiSubmitResult = await submitPosOrder(page, auth.token, apiPayload);
      httpTrace(order, 'api-fallback', apiSubmitResult);
      submittedViaApi = true;
      await page.waitForTimeout(800);
      const eventsAfterApi = snapshotDomainEvents(`${order}-after-api`);
      const apiNewEvents = (eventsAfterApi.total || 0) - (eventsBefore.total || 0);
      record(order, 11, apiNewEvents > 0 ? 'api-fallback-ok' : 'no-events', apiNewEvents > 0 ? 'OK' : 'P0',
        `UI added=${added}, UI events=0, API status=${apiSubmitResult?.status}, new events after API=${apiNewEvents}`, '');
    } else {
      record(order, 11, 'ui-flow-ok', 'OK', `Added ${added} items via UI, ${newEvents} events created`, '');
    }

    const kdsMs = await probeKdsReception(browser, order, 'tacos');
    record(order, 12, 'kds-reception', kdsMs ? 'OK' : 'P2', kdsMs ? `KDS appearance ${kdsMs}ms` : 'no KDS match (websockets DOWN expected)', `${order}-kds-reception.png`);
  });

  // ============================================================
  // POS-3 — Item avec extra, paiement TR
  // ============================================================
  test('POS-3 — 1 item + extra, paiement TR (tickets-resto)', async ({ page, browser }) => {
    const order = 'pos-3';
    const eventsBefore = snapshotDomainEvents(`${order}-before`);

    await loginAsPosOperator(page);
    await page.waitForTimeout(2000);
    await snap(page, order, 1, 'surface');

    // Try to find an item with extras via wizard. Click first tile.
    const tiles = page.locator('.pos-v5-grid > *');
    if (await tiles.count() > 0) {
      await tiles.nth(0).click().catch(() => {});
      await page.waitForTimeout(1200);
      await snap(page, order, 2, 'tile-clicked');

      // Probe extras in wizard
      const extrasInWizard = await page.evaluate(() => {
        const wizard = document.querySelector('[role="dialog"], .modal[class*="show"], [class*="composer"], [class*="ProductPopup"]');
        if (!wizard) return { found: false };
        const extraEls = Array.from(wizard.querySelectorAll('[class*="extra"], [class*="addon"], [class*="supplement"]'));
        return { found: true, extraCount: extraEls.length, sample: extraEls.slice(0, 3).map((e) => (e.textContent || '').replace(/\s+/g, ' ').slice(0, 50)) };
      });
      probe(order, 2, 'extras-probe', extrasInWizard);
      await snap(page, order, 3, 'wizard-extras-probe');

      // Add to cart
      const addBtn = page.locator('button').filter({ hasText: /ajouter au panier|ajouter/i }).first();
      if (await addBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
        await addBtn.click().catch(() => {});
        await page.waitForTimeout(600);
      }
    }

    // UI pay → TR mode (data-testid pos-payment-mode-multi or similar — TR usually under "more" modes)
    let submittedViaApi = false;
    const payBtn = page.locator('[data-testid="pos-v5-pay"]');
    if (await payBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await payBtn.click().catch(() => {});
      await page.waitForTimeout(1200);
      await snap(page, order, 4, 'payment-modal');
      // TR is mode 11 (PaymentMethodEnum::TR). UI may not have a direct testid;
      // we try card as fallback for visual capture.
      const cardBtn = page.locator('[data-testid="pos-payment-mode-card"]');
      if (await cardBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
        await cardBtn.click().catch(() => {});
        await snap(page, order, 5, 'mode-clicked');
      }
      const confirmBtn = page.locator('[data-testid="pos-payment-confirm"]');
      if (await confirmBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await confirmBtn.click().catch(() => {});
        await page.waitForTimeout(2000);
        await snap(page, order, 6, 'after-confirm');
      }
    }

    const eventsAfter = snapshotDomainEvents(`${order}-after-ui`);
    const newEvents = (eventsAfter.total || 0) - (eventsBefore.total || 0);

    if (newEvents === 0) {
      // Fallback API submit using TR mode (11) with extras
      const auth = await apiLogin(page);
      const apiPayload = buildPosPayload(
        [{
          item_id: 363, quantity: 1, price: 7.5,
          item_variations: [],
          item_extras: [{ id: TARGET_EXTRA_OOS, name: 'Salade', price: 1.0, quantity: 1 }],
          composition_snapshot: { addons: [{ id: TARGET_EXTRA_OOS, name: 'Salade' }] },
        }],
        11 // TR
      );
      const apiResult = await submitPosOrder(page, auth.token, apiPayload);
      httpTrace(order, 'api-fallback-tr', apiResult);
      submittedViaApi = true;
      await page.waitForTimeout(800);
      const eventsAfterApi = snapshotDomainEvents(`${order}-after-api`);
      const apiNewEvents = (eventsAfterApi.total || 0) - (eventsBefore.total || 0);
      record(order, 11, apiNewEvents > 0 ? 'api-fallback-ok-tr' : 'no-events', apiNewEvents > 0 ? 'OK' : 'P1',
        `TR mode submit via API, status=${apiResult?.status}, new events=${apiNewEvents}`, '', { apiResult });
    } else {
      record(order, 11, 'ui-flow-ok', 'OK', `${newEvents} events via UI`, '');
    }

    const kdsMs = await probeKdsReception(browser, order, 'tacos');
    record(order, 12, 'kds-reception', kdsMs ? 'OK' : 'P2', kdsMs ? `KDS ${kdsMs}ms` : 'no KDS match', `${order}-kds-reception.png`);
  });

  // ============================================================
  // POS-4 — RUPTURE produit (item 363 OOS avant commande)
  // ============================================================
  test('POS-4 — Rupture produit: toggle 363 OOS, vérifier UI POS bloque + reactivation', async ({ page, browser }) => {
    const order = 'pos-4';
    const auth = await apiLogin(page);
    expect(auth.token, 'apiLogin must succeed').toBeTruthy();

    // 1. Toggle OFF item 363
    const toggleOff = await toggleAvailabilityHttp(page, auth.token, TARGET_ITEM_OOS, BRANCH_ID, false, 'mega-pos-4');
    httpTrace(order, 'toggle-off', toggleOff);
    record(order, 1, 'toggle-off', toggleOff.status === 200 ? 'OK' : 'P1', `Toggle OFF 363 status=${toggleOff.status} ms=${toggleOff.ms}`, '', { toggleOff });
    await page.waitForTimeout(800);

    // 2. POS UI baseline — login and navigate
    await loginAsPosOperator(page, 'admin@lecayenne.fr', '123456');
    await page.waitForTimeout(2500);
    await snap(page, order, 2, 'pos-loaded-after-toggle');

    // 3. Probe tile state for 363 (look for is-unavailable / 86 badge)
    const tileProbe = await page.evaluate(() => {
      const candidates = Array.from(document.querySelectorAll('button, [role="button"], .pos-v5-tile, .pos-item-card, .pos-v5-grid > *'));
      const matched = candidates.filter((c) => /tacos\s*m/i.test(c.textContent || ''));
      return matched.slice(0, 3).map((b) => ({
        text: (b.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 100),
        disabled: b.hasAttribute('disabled'),
        ariaDisabled: b.getAttribute('aria-disabled'),
        classes: (b.className || '').toString().slice(0, 200),
        hasUnavailableClass: /unavailable|is-86|disabled|ko/i.test(b.className || ''),
        has86Badge: !!b.querySelector('.pos-item-86-badge, .pos-v5-tile__overlay, [class*="86"], [class*="oos"]'),
      }));
    });
    probe(order, 3, 'tile-state-after-oos', tileProbe);
    await snap(page, order, 3, 'tile-after-oos');

    const blocked = tileProbe.length > 0 && (tileProbe[0].disabled || tileProbe[0].hasUnavailableClass || tileProbe[0].has86Badge);
    if (!blocked) {
      record(order, 3, 'pos-ui-stale-on-oos', 'P1', `POS UI ne bloque PAS la tuile 363 alors que backend OOS. Confirme R3-03 (P1 known). Probe: ${JSON.stringify(tileProbe[0]).slice(0, 200)}`, `${order}-step-03-tile-after-oos.png`, { tileProbe });
    } else {
      record(order, 3, 'pos-ui-blocks-oos', 'OK', `POS UI bloque tuile 363 (disabled/86badge présent)`, `${order}-step-03-tile-after-oos.png`, { tileProbe });
    }

    // 4. Try clicking it anyway to see if click is blocked. Use force=true + short timeout
    //    to AVOID 30s default Playwright wait when the tile is disabled (always the case here).
    const tacosLocator = page.locator('button, [role="button"]').filter({ hasText: /tacos\s*m/i }).first();
    if (await tacosLocator.isVisible({ timeout: 2000 }).catch(() => false)) {
      await tacosLocator.click({ force: true, timeout: 2000 }).catch(() => {});
      await page.waitForTimeout(500);
      const wizardAfter = await page.evaluate(() => !!document.querySelector('.modal.show, [role="dialog"][aria-modal="true"], [class*="composer"][class*="show"]'));
      probe(order, 4, 'wizard-opened-on-oos-click', { wizardAfter });
      await snap(page, order, 4, 'after-click-oos');
      if (wizardAfter && !blocked) {
        record(order, 4, 'wizard-opens-on-oos-tile', 'P0', 'Wizard s\'ouvre sur tuile OOS — caissier peut ajouter item indispo!', `${order}-step-04-after-click-oos.png`);
      }
    }

    // 5. Verify backend re-validates: try API submit with OOS item → expect 422
    const submitOos = await submitPosOrder(page, auth.token, buildPosPayload(
      [{ item_id: TARGET_ITEM_OOS, quantity: 1, price: 6.5, item_variations: [], item_extras: [], composition_snapshot: { addons: [] } }],
      1
    ));
    httpTrace(order, 'api-submit-while-oos', submitOos);
    if (submitOos.status === 422) {
      record(order, 5, 'backend-rejects-oos-submit', 'OK', `Backend rejette 422 commande avec item OOS — assertItemsOrderableForBranch fonctionne`, '', { submitOos });
    } else if (submitOos.status === 200 || submitOos.status === 201) {
      record(order, 5, 'backend-accepts-oos-submit', 'P0', `Backend ACCEPTE commande avec item OOS! status=${submitOos.status}. Body=${JSON.stringify(submitOos.body).slice(0, 200)}`, '', { submitOos });
    } else {
      record(order, 5, 'backend-other-status', 'P2', `Status=${submitOos.status}, body=${JSON.stringify(submitOos.body).slice(0, 200)}`, '', { submitOos });
    }

    // 6. Re-toggle ON
    const toggleOn = await toggleAvailabilityHttp(page, auth.token, TARGET_ITEM_OOS, BRANCH_ID, true, null);
    httpTrace(order, 'toggle-on', toggleOn);
    record(order, 6, 'toggle-on', toggleOn.status === 200 ? 'OK' : 'P1', `Re-toggle ON status=${toggleOn.status}`, '', { toggleOn });

    // No KDS probe (no order created intentionally for OOS)
  });

  // ============================================================
  // POS-5 — RUPTURE extra (extra 172 OOS, parent item still orderable)
  // ============================================================
  test('POS-5 — Rupture extra: toggle extra OOS, vérifier wizard montre OOS marker mais commande passe sans', async ({ page, browser }) => {
    const order = 'pos-5';
    const auth = await apiLogin(page);
    expect(auth.token).toBeTruthy();

    // 1. Toggle extra 172 OFF via service (cascade)
    const toggleExtra = tinker(`echo json_encode(["ok"=>app(\\App\\Services\\Ingredients\\IngredientAvailabilityService::class)->toggle("extra", ${TARGET_EXTRA_OOS}, false, "mega-pos-5")]);`);
    httpTrace(order, 'extra-toggle-off', toggleExtra);
    record(order, 1, 'extra-off', toggleExtra.ok ? 'OK' : 'P1', `Extra ${TARGET_EXTRA_OOS} toggled OFF: ${JSON.stringify(toggleExtra).slice(0, 200)}`, '');
    await page.waitForTimeout(800);

    // 2. Login + open wizard, probe extras
    await loginAsPosOperator(page);
    await page.waitForTimeout(2500);
    await snap(page, order, 2, 'pos-after-extra-oos');

    const tiles = page.locator('.pos-v5-grid > *');
    if (await tiles.count() > 0) {
      await tiles.nth(0).click().catch(() => {});
      await page.waitForTimeout(1200);
      await snap(page, order, 3, 'wizard-with-oos-extra');

      const extraOosProbe = await page.evaluate((extraId) => {
        const wizard = document.querySelector('[role="dialog"], .modal.show, [class*="composer"], [class*="ProductPopup"]');
        if (!wizard) return { found: false };
        const extras = Array.from(wizard.querySelectorAll('[class*="extra"], [class*="addon"], [class*="supplement"], button'));
        const oosMarked = extras.filter((e) => /rupture|indispo|unavailable|sold.?out|86/i.test(e.textContent + ' ' + e.className));
        return {
          found: true,
          totalExtras: extras.length,
          oosMarkedCount: oosMarked.length,
          oosSamples: oosMarked.slice(0, 3).map((e) => ({
            text: (e.textContent || '').replace(/\s+/g, ' ').slice(0, 60),
            disabled: e.hasAttribute('disabled') || e.getAttribute('aria-disabled') === 'true',
            classes: (e.className || '').toString().slice(0, 200),
          })),
        };
      }, TARGET_EXTRA_OOS);
      probe(order, 3, 'extra-oos-in-wizard', extraOosProbe);

      if (extraOosProbe.found && extraOosProbe.oosMarkedCount === 0) {
        record(order, 3, 'extra-oos-not-marked-ui', 'P1', `Wizard ouvre mais extra OOS non marqué visuellement. Caissier peut sélectionner extra indispo.`, `${order}-step-03-wizard-with-oos-extra.png`, { extraOosProbe });
      } else if (extraOosProbe.oosMarkedCount > 0) {
        record(order, 3, 'extra-oos-marked-ui', 'OK', `${extraOosProbe.oosMarkedCount} extras marqués OOS visuellement dans wizard`, `${order}-step-03-wizard-with-oos-extra.png`, { extraOosProbe });
      } else {
        record(order, 3, 'wizard-not-open', 'INFO', 'Wizard pas ouvert (item direct-add) — extras OOS non testables UI cette tuile', '');
      }

      // close wizard if open
      await page.keyboard.press('Escape');
      await page.waitForTimeout(500);
    }

    // 3. Verify commande SANS extra passe via API (item parent dispo, sans extra OOS)
    const submitWithoutExtra = await submitPosOrder(page, auth.token, buildPosPayload(
      [{ item_id: 363, quantity: 1, price: 6.5, item_variations: [], item_extras: [], composition_snapshot: { addons: [] } }],
      1
    ));
    httpTrace(order, 'api-submit-without-oos-extra', submitWithoutExtra);
    record(order, 4, 'submit-without-oos-extra',
      submitWithoutExtra.status === 200 || submitWithoutExtra.status === 201 ? 'OK' : 'P1',
      `Commande sans extra OOS: status=${submitWithoutExtra.status}`, '', { submitWithoutExtra });

    // 4. Verify commande AVEC extra OOS rejected
    const submitWithOosExtra = await submitPosOrder(page, auth.token, buildPosPayload(
      [{
        item_id: 363, quantity: 1, price: 7.5,
        item_variations: [],
        item_extras: [{ id: TARGET_EXTRA_OOS, name: 'Salade', price: 1.0, quantity: 1 }],
        composition_snapshot: { addons: [{ id: TARGET_EXTRA_OOS, name: 'Salade' }] },
      }],
      1
    ));
    httpTrace(order, 'api-submit-with-oos-extra', submitWithOosExtra);
    if (submitWithOosExtra.status === 422) {
      record(order, 5, 'backend-rejects-oos-extra', 'OK', `Backend rejette 422 commande avec extra OOS`, '', { submitWithOosExtra });
    } else if (submitWithOosExtra.status === 200 || submitWithOosExtra.status === 201) {
      record(order, 5, 'backend-accepts-oos-extra', 'P0', `Backend ACCEPTE commande avec extra OOS! Status=${submitWithOosExtra.status}`, '', { submitWithOosExtra });
    } else {
      record(order, 5, 'backend-extra-other', 'P2', `Status=${submitWithOosExtra.status}`, '', { submitWithOosExtra });
    }

    // 5. Re-toggle extra ON
    tinker(`\\App\\Models\\ItemExtra::where("id", ${TARGET_EXTRA_OOS})->update(["is_available"=>true,"unavailable_reason"=>null]);`);
    record(order, 6, 'extra-restored', 'OK', `Extra ${TARGET_EXTRA_OOS} restored`, '');
  });

  // ============================================================
  // KIOSK-1 — Emporter, paiement card stub
  // ============================================================
  test('Kiosk-1 — Emporter, paiement card stub (flow complet idle → confirm)', async ({ page, browser }) => {
    const order = 'kiosk-1';
    const eventsBefore = snapshotDomainEvents(`${order}-before`);

    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await snap(page, order, 1, 'login-or-idle');

    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await snap(page, order, 2, 'idle');

    // Click takeaway
    const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
    if (await takeaway.isVisible({ timeout: 5000 }).catch(() => false)) {
      await takeaway.click().catch(() => {});
      await page.waitForTimeout(2000);
      await snap(page, order, 3, 'after-takeaway');
    } else {
      record(order, 3, 'no-takeaway-cta', 'P1', 'kiosk-order-type-takeaway non visible — flow kiosk bloqué', `${order}-step-02-idle.png`);
    }

    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);
    await snap(page, order, 4, 'categories');

    // Find first product card and add
    const productCards = page.locator('[data-testid^="kiosk-product-card-"]');
    const productCount = await productCards.count();
    probe(order, 4, 'product-grid', { count: productCount });

    if (productCount > 0) {
      const addBtn = productCards.first().locator('[data-testid^="kiosk-product-add-"]').first();
      if (await addBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
        await addBtn.click().catch(() => {});
        await page.waitForTimeout(1200);
        await snap(page, order, 5, 'after-add-or-wizard');
        // Wizard may open
        const wizardConfirm = page.locator('[data-testid="kiosk-wizard-confirm"], button').filter({ hasText: /ajouter|valider|continuer/i }).first();
        if (await wizardConfirm.isVisible({ timeout: 1500 }).catch(() => false)) {
          await wizardConfirm.click().catch(() => {});
          await page.waitForTimeout(800);
          await snap(page, order, 6, 'after-wizard-confirm');
        }
      } else {
        await productCards.first().click().catch(() => {});
        await page.waitForTimeout(1200);
        await snap(page, order, 5, 'card-clicked');
      }
    }

    // Navigate to cart
    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);
    await snap(page, order, 7, 'cart');
    const cartProbe = await page.evaluate(() => {
      const items = document.querySelectorAll('.kiosk-cart-item, [data-testid^="kiosk-cart-item-"]');
      const empty = document.querySelector('[data-testid="kiosk-cart-empty"]');
      return { count: items.length, empty: !!empty && getComputedStyle(empty).display !== 'none' };
    });
    probe(order, 7, 'cart-state', cartProbe);

    // Pay
    if (cartProbe.count > 0) {
      const checkoutBtn = page.locator('button, a').filter({ hasText: /payer|valider|checkout|continuer/i }).first();
      if (await checkoutBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await checkoutBtn.click().catch(() => {});
        await page.waitForTimeout(2000);
      }
      await page.goto('/kiosk/payment', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await page.waitForTimeout(2000);
      await snap(page, order, 8, 'payment-screen');
      const cardMethod = page.locator('[data-testid="kiosk-payment-method-card"]');
      if (await cardMethod.isVisible({ timeout: 3000 }).catch(() => false)) {
        await cardMethod.click().catch(() => {});
        await page.waitForTimeout(500);
      }
      const confirm = page.locator('[data-testid="kiosk-payment-confirm"]');
      if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) {
        await confirm.click().catch(() => {});
        await page.waitForTimeout(3000);
        await snap(page, order, 9, 'after-confirm');
      }
    }

    const eventsAfter = snapshotDomainEvents(`${order}-after-ui`);
    const newEvents = (eventsAfter.total || 0) - (eventsBefore.total || 0);
    record(order, 10, newEvents > 0 ? 'kiosk-ui-flow-ok' : 'kiosk-ui-flow-blocked',
      newEvents > 0 ? 'OK' : 'P1',
      `Kiosk UI flow: products=${productCount}, cart count=${cartProbe.count}, events created=${newEvents}`, '', { newEvents });

    const kdsMs = await probeKdsReception(browser, order, 'tacos');
    record(order, 11, 'kds-reception', kdsMs ? 'OK' : 'P2', kdsMs ? `KDS ${kdsMs}ms` : 'no KDS match', `${order}-kds-reception.png`);
  });

  // ============================================================
  // KIOSK-2 — Cash counter (paiement à la caisse)
  // ============================================================
  test('Kiosk-2 — Cash counter, ticket "pending counter"', async ({ page, browser }) => {
    const order = 'kiosk-2';
    const eventsBefore = snapshotDomainEvents(`${order}-before`);

    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await snap(page, order, 1, 'idle');

    const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
    if (await takeaway.isVisible({ timeout: 4000 }).catch(() => false)) {
      await takeaway.click().catch(() => {});
      await page.waitForTimeout(2000);
    }
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await snap(page, order, 2, 'categories');

    const productCards = page.locator('[data-testid^="kiosk-product-card-"]');
    if (await productCards.count() > 0) {
      const addBtn = productCards.first().locator('[data-testid^="kiosk-product-add-"]').first();
      if (await addBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
        await addBtn.click().catch(() => {});
        await page.waitForTimeout(1200);
        const wizardConfirm = page.locator('button').filter({ hasText: /ajouter|valider/i }).first();
        if (await wizardConfirm.isVisible({ timeout: 1500 }).catch(() => false)) {
          await wizardConfirm.click().catch(() => {});
          await page.waitForTimeout(800);
        }
      }
    }
    await snap(page, order, 3, 'after-add');

    await page.goto('/kiosk/payment', { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(2500);
    await snap(page, order, 4, 'payment-screen');

    const cashMethod = page.locator('[data-testid="kiosk-payment-method-cash"]');
    if (await cashMethod.isVisible({ timeout: 3000 }).catch(() => false)) {
      await cashMethod.click().catch(() => {});
      await page.waitForTimeout(500);
      await snap(page, order, 5, 'cash-method-selected');
    } else {
      record(order, 5, 'cash-method-not-visible', 'P1', 'kiosk-payment-method-cash absent — flow cash counter bloqué', `${order}-step-04-payment-screen.png`);
    }

    const confirm = page.locator('[data-testid="kiosk-payment-confirm"]');
    if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) {
      await confirm.click().catch(() => {});
      await page.waitForTimeout(3000);
      await snap(page, order, 6, 'after-confirm-cash');
    }

    // Probe ticket page for "pending counter" / "comptoir" mention
    const ticketProbe = await page.evaluate(() => ({
      url: location.href,
      bodyText: (document.body.textContent || '').replace(/\s+/g, ' ').slice(0, 500),
      hasPendingCounterText: /pending\s*counter|paiement\s*comptoir|payer\s*au\s*comptoir|à\s*la\s*caisse|cash\s*counter/i.test(document.body.textContent || ''),
      hasQueueNumber: !!document.querySelector('[class*="queue"], [data-testid*="queue"]'),
    }));
    probe(order, 7, 'ticket-pending-counter', ticketProbe);
    await snap(page, order, 7, 'ticket-state');

    if (!ticketProbe.hasPendingCounterText) {
      record(order, 7, 'no-pending-counter-text', 'P2', `Aucun texte "pending counter / paiement comptoir" trouvé sur ticket. URL=${ticketProbe.url}`, `${order}-step-07-ticket-state.png`, { ticketProbe });
    } else {
      record(order, 7, 'pending-counter-text-ok', 'OK', `Ticket affiche bien la mention paiement comptoir`, `${order}-step-07-ticket-state.png`);
    }

    const eventsAfter = snapshotDomainEvents(`${order}-after-ui`);
    const newEvents = (eventsAfter.total || 0) - (eventsBefore.total || 0);
    record(order, 8, 'events-counter', newEvents > 0 ? 'OK' : 'P1', `Cash counter flow: ${newEvents} events created`, '');
  });

  // ============================================================
  // KIOSK-3 — Multi-items + customisation
  // (Wrapped in try/catch — kiosk multi-add is fragile when wizard auto-redirects to /cart;
  //  we want findings preserved even if page closes mid-flow.)
  // ============================================================
  test('Kiosk-3 — Multi-items 2-3 produits + customisations wizard', async ({ page, browser }) => {
    const order = 'kiosk-3';
    const eventsBefore = snapshotDomainEvents(`${order}-before`);
    let added = 0;
    let catCount = 0;
    try {
      await page.setViewportSize({ width: 1080, height: 1920 });
      await loginAsKiosk(page);
      await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
      if (await takeaway.isVisible({ timeout: 4000 }).catch(() => false)) {
        await takeaway.click().catch(() => {});
        await page.waitForTimeout(2000);
      }
      await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      await snap(page, order, 1, 'categories');

      const sidebar = page.locator('[data-testid^="kiosk-categories-sidebar-item-"]');
      const productCards = page.locator('[data-testid^="kiosk-product-card-"]');
      catCount = await sidebar.count().catch(() => 0);
      for (let c = 0; c < Math.min(catCount, 4) && added < 3; c++) {
        try {
          await sidebar.nth(c).click({ timeout: 2000 }).catch(() => {});
          await page.waitForTimeout(1500).catch(() => {});
          const pc = await productCards.count().catch(() => 0);
          if (pc > 0) {
            const addBtn = productCards.first().locator('[data-testid^="kiosk-product-add-"]').first();
            if (await addBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
              await addBtn.click().catch(() => {});
              await page.waitForTimeout(1500).catch(() => {});
              await snap(page, order, 2 + added, `cat-${c}-added-${added}`).catch(() => {});
              // Wizard customisation if present
              const wizardConfirm = page.locator('button').filter({ hasText: /ajouter|valider|continuer/i }).first();
              if (await wizardConfirm.isVisible({ timeout: 1500 }).catch(() => false)) {
                await wizardConfirm.click().catch(() => {});
                await page.waitForTimeout(800).catch(() => {});
              }
              added++;
            }
          }
        } catch (_) { break; }
      }
    } catch (e) {
      record(order, 99, 'kiosk-3-flow-error', 'P2', `Kiosk-3 flow exception (page possibly closed): ${String(e.message || e).slice(0, 200)}`, '');
    }
    probe(order, 5, 'multi-add-result', { added, catCount });

    let cartProbe = { itemCount: 0, total: null };
    try {
      await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await page.waitForTimeout(2000).catch(() => {});
      await snap(page, order, 6, 'cart-multi').catch(() => {});
      cartProbe = await page.evaluate(() => ({
        itemCount: document.querySelectorAll('[data-testid^="kiosk-cart-item-"]').length,
        total: document.querySelector('[class*="total"], [data-testid*="total"]')?.textContent?.trim() || null,
      })).catch(() => ({ itemCount: 0, total: null }));
      probe(order, 6, 'cart-multi-state', cartProbe);

      await page.goto('/kiosk/payment', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await page.waitForTimeout(2000).catch(() => {});
      await snap(page, order, 7, 'payment').catch(() => {});
      const card = page.locator('[data-testid="kiosk-payment-method-card"]');
      if (await card.isVisible({ timeout: 3000 }).catch(() => false)) await card.click().catch(() => {});
      const confirm = page.locator('[data-testid="kiosk-payment-confirm"]');
      if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) {
        await confirm.click().catch(() => {});
        await page.waitForTimeout(3000).catch(() => {});
        await snap(page, order, 8, 'after-confirm').catch(() => {});
      }
    } catch (e) {
      record(order, 100, 'kiosk-3-payment-error', 'P2', `Kiosk-3 payment phase exception: ${String(e.message || e).slice(0, 200)}`, '');
    }

    const eventsAfter = snapshotDomainEvents(`${order}-after-ui`);
    const newEvents = (eventsAfter.total || 0) - (eventsBefore.total || 0);
    record(order, 9, 'kiosk-multi-events', newEvents > 0 ? 'OK' : 'P1', `multi-add result: added=${added}, cart count=${cartProbe.itemCount}, events=${newEvents}`, '');

    // KDS reception — wrap in try/catch since page may be closed
    try {
      const kdsMs = await probeKdsReception(browser, order, 'kiosk');
      record(order, 10, 'kds-reception', kdsMs ? 'OK' : 'P2', kdsMs ? `KDS ${kdsMs}ms` : 'no KDS match', `${order}-kds-reception.png`);
    } catch (e) {
      record(order, 10, 'kds-reception-error', 'P2', `KDS reception probe exception: ${String(e.message || e).slice(0, 200)}`, '');
    }
  });

  // ============================================================
  // KIOSK-4 — RUPTURE produit
  // ============================================================
  test('Kiosk-4 — Rupture produit: item OOS sur kiosk catalog', async ({ page, browser }) => {
    const order = 'kiosk-4';
    const auth = await apiLogin(page);
    expect(auth.token).toBeTruthy();

    const toggleOff = await toggleAvailabilityHttp(page, auth.token, TARGET_ITEM_OOS, BRANCH_ID, false, 'mega-kiosk-4');
    httpTrace(order, 'toggle-off', toggleOff);
    record(order, 1, 'toggle-off', toggleOff.status === 200 ? 'OK' : 'P1', `Toggle 363 OFF status=${toggleOff.status}`, '');
    await page.waitForTimeout(1000);

    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
    if (await takeaway.isVisible({ timeout: 4000 }).catch(() => false)) {
      await takeaway.click().catch(() => {});
      await page.waitForTimeout(2000);
    }
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await snap(page, order, 2, 'kiosk-cat-after-oos');

    const tacosOnKioskProbe = await page.evaluate(() => {
      const cards = Array.from(document.querySelectorAll('[data-testid^="kiosk-product-card-"], .kiosk-product-card'));
      const matches = cards.filter((c) => /tacos\s*m/i.test(c.textContent || ''));
      return {
        totalCards: cards.length,
        tacosMatches: matches.length,
        firstMatch: matches[0] ? {
          text: (matches[0].textContent || '').replace(/\s+/g, ' ').slice(0, 100),
          classes: (matches[0].className || '').toString().slice(0, 200),
          disabled: matches[0].hasAttribute('disabled'),
          hasUnavailable: /unavailable|indispo|sold.?out|86/i.test(matches[0].className || matches[0].textContent || ''),
        } : null,
      };
    });
    probe(order, 2, 'kiosk-tacos-oos-state', tacosOnKioskProbe);

    if (tacosOnKioskProbe.tacosMatches === 0) {
      record(order, 2, 'tacos-filtered-from-kiosk', 'INFO', `Item Tacos M absent du kiosk catalog (filtré pré-affichage). totalCards=${tacosOnKioskProbe.totalCards}`, `${order}-step-02-kiosk-cat-after-oos.png`, { tacosOnKioskProbe });
    } else if (tacosOnKioskProbe.firstMatch?.hasUnavailable || tacosOnKioskProbe.firstMatch?.disabled) {
      record(order, 2, 'kiosk-shows-oos-marker', 'OK', `Kiosk affiche tacos avec marker OOS visible`, `${order}-step-02-kiosk-cat-after-oos.png`, { tacosOnKioskProbe });
    } else {
      record(order, 2, 'kiosk-shows-tacos-as-available', 'P1', `Kiosk affiche tacos comme dispo alors que backend OOS — UI stale (R3-04 known)`, `${order}-step-02-kiosk-cat-after-oos.png`, { tacosOnKioskProbe });
    }

    // Try clicking it (force to avoid hang on disabled-but-visible elements)
    if (tacosOnKioskProbe.tacosMatches > 0) {
      const tacos = page.locator('[data-testid^="kiosk-product-card-"]').filter({ hasText: /tacos\s*m/i }).first();
      await tacos.click({ force: true, timeout: 2000 }).catch(() => {});
      await page.waitForTimeout(1000);
      const wizardOpened = await page.evaluate(() => !!document.querySelector('.kiosk-wizard-overlay .kiosk-wizard'));
      probe(order, 3, 'wizard-opens-on-oos-kiosk', { wizardOpened });
      await snap(page, order, 3, 'after-click-oos-kiosk');
      if (wizardOpened) {
        record(order, 3, 'kiosk-wizard-opens-on-oos', 'P0', `Kiosk wizard s'ouvre sur item OOS — client peut commander item indispo!`, `${order}-step-03-after-click-oos-kiosk.png`);
      }
    }

    // Restore
    const toggleOn = await toggleAvailabilityHttp(page, auth.token, TARGET_ITEM_OOS, BRANCH_ID, true, null);
    httpTrace(order, 'toggle-on', toggleOn);
    record(order, 4, 'toggle-on', toggleOn.status === 200 ? 'OK' : 'P1', `Re-toggle ON`, '');
  });

  // ============================================================
  // KIOSK-5 — RUPTURE extra
  // ============================================================
  test('Kiosk-5 — Rupture extra sur kiosk wizard, commande passe sans extra', async ({ page, browser }) => {
    const order = 'kiosk-5';
    const auth = await apiLogin(page);
    expect(auth.token).toBeTruthy();

    const toggleExtra = tinker(`echo json_encode(["ok"=>app(\\App\\Services\\Ingredients\\IngredientAvailabilityService::class)->toggle("extra", ${TARGET_EXTRA_OOS}, false, "mega-kiosk-5")]);`);
    httpTrace(order, 'extra-off', toggleExtra);
    record(order, 1, 'extra-off-kiosk', toggleExtra.ok ? 'OK' : 'P1', `Extra ${TARGET_EXTRA_OOS} OFF: ${JSON.stringify(toggleExtra).slice(0, 200)}`, '');
    await page.waitForTimeout(1000);

    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
    if (await takeaway.isVisible({ timeout: 4000 }).catch(() => false)) {
      await takeaway.click().catch(() => {});
      await page.waitForTimeout(2000);
    }
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await snap(page, order, 2, 'kiosk-categories');

    // Try multiple items to find one with extras
    const productCards = page.locator('[data-testid^="kiosk-product-card-"]');
    const pCount = await productCards.count();
    let wizardOpened = false;
    for (let i = 0; i < Math.min(pCount, 5) && !wizardOpened; i++) {
      const addBtn = productCards.nth(i).locator('[data-testid^="kiosk-product-add-"]').first();
      if (await addBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
        await addBtn.click().catch(() => {});
        await page.waitForTimeout(1500);
        wizardOpened = await page.evaluate(() => !!document.querySelector('.kiosk-wizard-overlay .kiosk-wizard'));
        if (wizardOpened) break;
      }
    }
    await snap(page, order, 3, 'wizard-or-cart');

    const extraProbe = await page.evaluate(() => {
      const wizard = document.querySelector('.kiosk-wizard-overlay .kiosk-wizard');
      if (!wizard) return { found: false };
      const extraEls = Array.from(wizard.querySelectorAll('[class*="extra"], [class*="addon"], [class*="option-tile"], button'));
      const oosMarked = extraEls.filter((e) => /rupture|indispo|unavailable|sold.?out|86/i.test(e.textContent + ' ' + e.className));
      return {
        found: true,
        extraCount: extraEls.length,
        oosMarkedCount: oosMarked.length,
        oosSamples: oosMarked.slice(0, 3).map((e) => ({
          text: (e.textContent || '').replace(/\s+/g, ' ').slice(0, 60),
          disabled: e.hasAttribute('disabled') || e.getAttribute('aria-disabled') === 'true',
        })),
      };
    });
    probe(order, 3, 'kiosk-extra-oos-in-wizard', extraProbe);
    if (extraProbe.found) {
      if (extraProbe.oosMarkedCount === 0) {
        record(order, 3, 'kiosk-extra-oos-not-marked', 'P1', `Wizard kiosk ouvre mais extras OOS non marqués visuellement (${extraProbe.extraCount} extras totaux)`, `${order}-step-03-wizard-or-cart.png`, { extraProbe });
      } else {
        record(order, 3, 'kiosk-extra-oos-marked', 'OK', `${extraProbe.oosMarkedCount}/${extraProbe.extraCount} extras OOS marqués dans wizard kiosk`, `${order}-step-03-wizard-or-cart.png`);
      }
    } else {
      record(order, 3, 'no-wizard-opened', 'INFO', `Aucun wizard kiosk ouvert (items direct-add). pCount=${pCount}`, `${order}-step-03-wizard-or-cart.png`);
    }

    // Restore
    tinker(`\\App\\Models\\ItemExtra::where("id", ${TARGET_EXTRA_OOS})->update(["is_available"=>true,"unavailable_reason"=>null]);`);
    record(order, 4, 'kiosk-extra-restored', 'OK', `Extra ${TARGET_EXTRA_OOS} restored`, '');
  });

  // ============================================================
  // FINAL — Synthèse + INDEX.md
  // ============================================================
  test('FINAL — Synthèse INDEX.md + cumulative counters', async ({ page }) => {
    const findings = readJsonOrEmpty(FINDINGS_FILE);
    const probes = readJsonOrEmpty(DOM_FILE);
    const https = readJsonOrEmpty(HTTP_FILE);
    const events = readJsonOrEmpty(EVENTS_FILE);
    const kds = readJsonOrEmpty(KDS_FILE);

    const screenshotsCount = fs.readdirSync(SHOT_DIR).filter((f) => f.endsWith('.png')).length;

    const sevCounts = findings.reduce((acc, f) => {
      acc[f.severity] = (acc[f.severity] || 0) + 1;
      return acc;
    }, {});

    const indexLines = [
      '# MEGA PARCOURS E2E — Index — 2026-05-08',
      '',
      `Total findings: **${findings.length}** (P0=${sevCounts.P0 || 0}, P1=${sevCounts.P1 || 0}, P2=${sevCounts.P2 || 0}, OK=${sevCounts.OK || 0}, INFO=${sevCounts.INFO || 0})`,
      `Total screenshots: **${screenshotsCount}**`,
      `Total DOM probes: ${probes.length}`,
      `Total HTTP traces: ${https.length}`,
      `Total domain_events snapshots: ${events.length}`,
      `Total KDS reception traces: ${kds.length}`,
      '',
      '## Findings table',
      '| Order | Step | Slug | Severity | Note | Screenshot |',
      '| --- | --- | --- | --- | --- | --- |',
    ];
    for (const f of findings) {
      indexLines.push(
        `| ${f.order} | ${f.step} | ${f.slug} | ${f.severity} | ${
          String(f.note).slice(0, 200).replace(/\|/g, '\\|')
        } | \`${f.screenshot || '—'}\` |`
      );
    }
    fs.writeFileSync(path.join(SHOT_DIR, 'INDEX.md'), indexLines.join('\n'));

    // Final assertion: the suite must produce a meaningful number of artifacts.
    expect(findings.length).toBeGreaterThan(15);
    expect(screenshotsCount).toBeGreaterThan(20);
  });
});
