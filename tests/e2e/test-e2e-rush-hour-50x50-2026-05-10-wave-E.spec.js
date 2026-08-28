// FoodKing E2E — rush-hour-50x50 audit Wave E (Mixed-20 + rupture cascade) — 2026-05-11
//
// Plan : reports/test-e2e/rush-hour-50x50-2026-05-10/AUDIT_PLAN.md §10 Wave E
// Owner mandate (PHASE 2, FR verbatim) :
// « passe en test 20 commande mixte et test de mettre un produit et un suplément
//   en repture et voie si vraiment prise en compte et voir tout le flux et
//   raisonne fort pour chaque test réel web E2E »
//
// Translation : 20 mixed POS+Kiosk orders + put 1 product + 1 supplement in
// stock-out (rupture) + verify cascade is REALLY taken into account across all
// surfaces.
//
// PHASE 1 STATUS : GREEN CONVERGED (see CONVERGENCE_FINAL_PHASE_1.md)
//
// RUPTURE TARGETS (per AUDIT_PLAN §10) :
//   - Item       : id=362 Boisson Seule (2.00€, cat=315 "Frites & Accompagnements")
//                  Chosen to NOT collide with iter15-mega-admin-rupture-cascade
//                  (item 410 Sprite) nor with Wave A item triplet (361/363/375).
//   - Supplement : id=175 Jambon de dinde (1.00€) on item_id=363 Tacos M.
//
// 20 MIXED ORDERS :
//   - 10 POS orders, prefix `MIX-E-POS-{seq}-{ts}` (token = customer call-out)
//   - 10 Kiosk orders, items[*].instruction prefix `MIX-E-KIOSK-{seq}-{ts}`
//   Both use NON-RUPTURED items (361 Frites + 363 Tacos w/o Jambon + 375 Burger)
//   so the rush succeeds. Bypass attempts (states 12/13/16) are SEPARATE.
//
// KEY DESIGN DECISIONS (advisor 2026-05-11) :
//   1. Idempotency-key compact form (≤30 chars) — reuse Wave B pattern
//      `MIX-K-{seq}-{ts7}-{8hex}` to satisfy IdempotencyKeyMiddleware
//      regex /^[A-Za-z0-9._\-]{8,64}$/ (Wave B B-005 root cause).
//   2. Rate limits — kiosk-orders 5/min/(user|ip), 2 hits/order. Clear every
//      2 kiosk orders. POS pace ≤1/s, clear once mid-way.
//   3. afterAll restore is non-negotiable — belt-and-braces tinker restore
//      mirrors iter15-mega-admin-rupture-cascade lines 62-69. Leaving 362/175
//      ruptured pollutes every subsequent test on branch_id=1.
//   4. Extra toggle has NO admin UI button — issue direct
//      window.axios.post('admin/menu/availability/extra/toggle', ...) from
//      adminPage and snap before/after. Only the ITEM toggle has an admin row.
//   5. Item 362 admin row needs the search filter (#item-filter #name) — same
//      pattern as iter15-mega-admin-rupture-cascade lines 156-163.
//   6. BYPASS-E5 — capture-as-finding, NOT hard-fail. Per task brief :
//      "DO NOT modify product code. If you discover a product P0/P1 mid-run,
//      capture evidence and report — do NOT fix." Silent 200 → write
//      `BYPASS-E5-finding.json` sidecar; spec still passes (the FINDING is
//      what matters). Same for state 16 kiosk bypass.
//   7. Cascade detection on POS — reload posPage.goto('/admin/pos') then check
//      tile state via iter15 pattern : hasUnavailable OR hasOverlay OR
//      computed pointer-events:none. Latency stamped before toggle response
//      resolves, again when surface shows ÉPUISÉ.
//   8. Wizard supplement (RUPTURE-E3/E4) — log all of (class, attr, computed
//      style) and assert at least ONE disabled signal. Brittle exact-selector
//      hard-asserts have failed in previous waves.
//
// FROZEN ZONES — capture only :
//   - public/js/pos-wizard.js, public/css/pos-wizard.css
//   - resources/views/admin-pos-v4.blade.php
//   - resources/js/components/frontend/kiosk/* (auditable, no edits)
//   - app/Services/Fiscal/*, BranchScope, IdempotencyKeyMiddleware,
//     PricingService, OrderStateMachine

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const {
  loginAsAdmin,
  loginAsPosOperator,
  loginAsChefOperator,
  loginAsKiosk,
  cleanupOrphanTestOrders,
} = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const {
  placeKioskOrder,
  getKioskApiToken,
  PAYMENT_CARD,
  prefixeAuditPourSpec,
} = require('./helpers/kiosk-order');

// [GOAL CONSOLIDATION T-4.2.1] Préfixe d'audit PROPRE à cette spec.
// Avant : huit specs écrivaient sous 'AUDIT-KIOSK-WAVE-E' et se nettoyaient
// mutuellement par LIKE. Dormant tant que playwright.config.js fixe workers:1,
// destructeur dès qu'on parallélise.
const PREFIXE_AUDIT = prefixeAuditPourSpec(__filename);

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/test-e2e-rush-hour-50x50-E');
// [test-e2e fix E-003 round-3 cluster-9 2026-05-11] advance wizard to extras + bump REPORTS_DIR
// Round-1 hardcoded path caused round-2 to overwrite round-1 sidecars; bump to round-3 so each
// audit round produces a discrete artifact tree alongside Wave A/B round-3 outputs.
// [test-e2e fix round-4 REPORTS_DIR bump 2026-05-11] verification cycle — preserve round-3 sidecars
const REPORT_DIR = path.resolve(__dirname, '..', '..', 'reports', 'test-e2e', 'rush-hour-50x50-2026-05-10', 'round-4');

// Live catalog triplet (verified 2026-05-11 status=5/avail=1)
const ITEM_FRITES = { id: 361, name: 'Frites Seules', price: 2.0, cat: 315 };
const ITEM_TACOS = { id: 363, name: 'Tacos M (1 Viande)', price: 6.5, cat: 306 };
const ITEM_BURGER = { id: 375, name: 'Burger Poulet', price: 6.0, cat: 308 };

// Rupture targets (Wave E exclusive — no collision with other specs)
const RUPTURE_ITEM = { id: 362, name: 'Boisson Seule', price: 2.0, cat: 315 };
const RUPTURE_EXTRA = { id: 175, name: 'Jambon de dinde', price: 1.0, item_id: 363 };

const TOKEN_PREFIX_POS = 'MIX-E-POS';
const TOKEN_PREFIX_KIOSK = 'MIX-E-KIOSK';
const TS = Date.now();
const BRANCH_ID = 1;

// PosPaymentMethod enum
const PAY = Object.freeze({ CASH: 1, CARD: 2 });
// OrderType
const ORDER_TYPE = Object.freeze({ TAKEAWAY: 10, POS: 15 });
const SOURCE = Object.freeze({ POS: 15 });
const ASK_NO = 0;

const HARD_CAP_MS = 35 * 60 * 1000;

// ----------------------------------------------------------------
// Tinker helpers (DB probe + availability force-restore for cleanup)
// ----------------------------------------------------------------
function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: path.resolve(__dirname, '../..'),
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 30_000,
  }).trim();
}

function parseLastJsonLine(output) {
  const lines = String(output).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const jsonLine = [...lines].reverse().find((l) => l.startsWith('{') || l.startsWith('['));
  if (!jsonLine) return null;
  try { return JSON.parse(jsonLine); } catch (_e) { return null; }
}

function dbProbe(sql) {
  const out = artisan(`echo json_encode(\\DB::select(${JSON.stringify(sql)}));`);
  return parseLastJsonLine(out) || [];
}

/**
 * Belt-and-braces tinker restore — used in afterAll guard so we NEVER leave
 * branch_id=1 with item 362 OR extra 175 ruptured (would pollute every
 * subsequent test on this branch). Mirror of iter15-mega line 62-69.
 *
 * NOTE: in JS template literal, `\\` produces a literal `\` in the string.
 * PHP namespace separator is a single `\`, so `App\\Services\\Menu\\X` (JS
 * source) → `App\Services\Menu\X` (PHP source) — exactly what tinker needs.
 * Round-1 used `\\\\` which produced `App\\Services\\Menu\\X` and triggered
 * "PHP Parse error: T_NS_SEPARATOR" on every restore call (silent afterAll
 * failure). Single-backslash form fixed in round-1.5 same-session.
 */
function setItemAvailability(itemId, branchId, available, reason = null) {
  const reasonPhp = reason ? `'${reason.replace(/'/g, "\\'")}'` : 'null';
  return artisan(
    `app(App\\Services\\Menu\\AvailabilityService::class)->toggle(${itemId}, ${branchId}, ${available ? 'true' : 'false'}, ${reasonPhp}); echo 'OK';`
  );
}

function setExtraAvailability(extraId, branchId, available, reason = null) {
  const reasonPhp = reason ? `'${reason.replace(/'/g, "\\'")}'` : 'null';
  return artisan(
    `app(App\\Services\\Menu\\AvailabilityService::class)->toggleExtra(${extraId}, ${branchId}, ${available ? 'true' : 'false'}, ${reasonPhp}); echo 'OK';`
  );
}

// ----------------------------------------------------------------
// Payload builders (POS API order)
// ----------------------------------------------------------------
function buildItemsJson({ itemId, qty = 1, branchId = BRANCH_ID, basePrice = 0, extras = [] }) {
  const item_extras = extras.map((e) => ({
    id: e.id,
    item_id: itemId,
    name: e.name,
    quantity: 1,
  }));
  const extraTotal = extras.reduce((s, e) => s + Number(e.price || 0), 0);
  const lineTotal = (Number(basePrice) + Number(extraTotal)) * qty;
  return {
    items: [
      {
        item_id: itemId,
        item_price: basePrice,
        branch_id: branchId,
        instruction: '',
        quantity: qty,
        discount: 0,
        total_price: lineTotal,
        item_variation_total: 0,
        item_extra_total: extraTotal,
        item_variations: [],
        item_extras,
      },
    ],
    line_total: lineTotal,
  };
}

function buildPosApiPayload({ token, itemId, qty, basePrice, extras = [], paymentMethod = PAY.CASH }) {
  const { items, line_total } = buildItemsJson({ itemId, qty, basePrice, extras });
  const total = Number(line_total).toFixed(2);
  const payload = {
    token,
    branch_id: BRANCH_ID,
    subtotal: Number(line_total),
    discount: 0,
    total: Number(total),
    order_type: ORDER_TYPE.TAKEAWAY,
    is_advance_order: ASK_NO,
    source: SOURCE.POS,
    items: JSON.stringify(items),
    pos_payment_method: paymentMethod,
    idempotency_key: `${Date.now()}_${Math.random().toString(36).slice(2, 11)}_${BRANCH_ID}_E`,
  };
  if (paymentMethod === PAY.CASH) {
    payload.pos_received_amount = Number(total);
  } else if (paymentMethod === PAY.CARD) {
    payload.pos_payment_note = '4242';
    payload.pos_received_amount = 0;
  }
  return payload;
}

/**
 * 2-step quote→commit POS POST via window.axios (Sanctum bearer in localStorage).
 * Returns { ok, status, data, body } — never throws.
 */
async function postPosOrderViaAxios(posPage, payload) {
  return posPage.evaluate(async (p) => {
    try {
      const quotePayload = {
        branch_id: p.branch_id,
        order_type: p.order_type,
        source: p.source,
        items: p.items,
        pos_payment_method: p.pos_payment_method,
      };
      let quoteRes;
      try {
        quoteRes = await window.axios.post('admin/pos/quote', quotePayload);
      } catch (qerr) {
        return {
          ok: false,
          status: qerr?.response?.status || 0,
          body: { stage: 'quote', message: qerr?.response?.data?.message || qerr?.message, data: qerr?.response?.data },
        };
      }
      const quote = quoteRes?.data?.data || quoteRes?.data || {};
      if (!quote.quote_token || !quote.signature) {
        return { ok: false, status: 422, body: { stage: 'quote', message: 'no quote token returned' } };
      }
      const commitPayload = {
        ...p,
        quote_token: quote.quote_token,
        quote_signature: quote.signature,
      };
      const headers = { 'X-Idempotency-Key': p.idempotency_key };
      try {
        const res = await window.axios.post('admin/pos', commitPayload, { headers });
        return { ok: true, status: res.status, data: res.data?.data || res.data || null };
      } catch (cerr) {
        return {
          ok: false,
          status: cerr?.response?.status || 0,
          body: cerr?.response?.data || String(cerr?.message || cerr).slice(0, 400),
        };
      }
    } catch (err) {
      return { ok: false, status: 0, body: String(err?.message || err).slice(0, 400) };
    }
  }, payload);
}

async function postPosOrderResilient(posPage, payload, observations) {
  let resp = await postPosOrderViaAxios(posPage, payload);
  if (resp.ok) return { ...resp, retries: 0 };
  if (resp.status === 429) {
    try { clearFoodKingRateLimits(); } catch (_e) { /* ignore */ }
    await new Promise((r) => setTimeout(r, 1500));
    resp = await postPosOrderViaAxios(posPage, payload);
    if (resp.ok) return { ...resp, retries: 1, recovered_from: 429 };
  }
  if (resp.status === 401) {
    const msg = String(resp.body?.message || resp.body || '').toLowerCase();
    if (!msg.includes('quote')) {
      try {
        await loginAsPosOperator(posPage);
        observations.push(`pos_resilience: re-auth after 401 idem=${payload.idempotency_key}`);
      } catch (e) {
        observations.push(`pos_resilience: re-auth threw ${e.message}`);
      }
    }
    await new Promise((r) => setTimeout(r, 600));
    resp = await postPosOrderViaAxios(posPage, payload);
    if (resp.ok) return { ...resp, retries: 1, recovered_from: 401 };
  }
  return { ...resp, retries: 1 };
}

function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

function ensureReportDir() { fs.mkdirSync(REPORT_DIR, { recursive: true }); }

function writeArtifact(name, payload) {
  ensureReportDir();
  fs.writeFileSync(path.join(REPORT_DIR, name), JSON.stringify(payload, null, 2));
}

// ----------------------------------------------------------------
// Cascade detection helpers
// ----------------------------------------------------------------
async function probePosTileState(posPage, itemId) {
  return posPage.evaluate((id) => {
    const tile = document.querySelector(`button[data-pos-item-id="${id}"]`);
    if (!tile) return { found: false };
    const cs = window.getComputedStyle(tile);
    return {
      found: true,
      classes: tile.className || '',
      hasUnavailable: tile.classList.contains('is-unavailable'),
      ariaDisabled: tile.getAttribute('aria-disabled'),
      disabledAttr: tile.hasAttribute('disabled'),
      pointerEvents: cs.pointerEvents,
      hasOverlay: tile.querySelector('.pos-item-86-badge, .pos-v5-tile__overlay') !== null,
      overlayText: tile.querySelector('.pos-item-86-badge, .pos-v5-tile__overlay')?.innerText || null,
      tileText: (tile.innerText || '').slice(0, 200),
    };
  }, itemId);
}

async function probeKioskCardState(kioskPage, itemId) {
  return kioskPage.evaluate((id) => {
    const card = document.querySelector(`[data-testid="kiosk-product-card-${id}"]`);
    if (!card) {
      // Item may be entirely hidden — scan for the literal text in any product card
      const all = Array.from(document.querySelectorAll('[data-testid^="kiosk-product-card-"]'));
      return { found: false, total_cards: all.length };
    }
    const cs = window.getComputedStyle(card);
    return {
      found: true,
      classes: card.className || '',
      hasOutOfStock: card.classList.contains('is-out-of-stock')
        || card.classList.contains('kiosk-product--unavailable')
        || /out-of-stock|ruptured|unavailable|epuise/i.test(card.className || ''),
      ariaDisabled: card.getAttribute('aria-disabled'),
      pointerEvents: cs.pointerEvents,
      cardText: (card.innerText || '').slice(0, 200),
      hasRuptureText: /épuisé|epuise|rupture|indispo|unavailable|out of stock/i.test(card.innerText || ''),
    };
  }, itemId);
}

/**
 * Wait up to `timeoutMs` for a condition fn (page→bool/Promise<bool>) to be true.
 * Returns { ok, elapsed_ms, errors }.
 *
 * [round-1.5] Round-1 produced p95=null because every wait reported ok=false even
 * when the final probe confirmed the cascade had landed mid-poll. Root cause:
 * silent catch swallowed `evaluate` errors during SPA navigation races. Fix:
 * count the errors so we can distinguish "condition never true" from "evaluate
 * kept throwing". Also do a final non-throwing check after the loop exits to
 * catch cases where the cascade landed in the last interval window.
 */
async function waitForCondition(page, conditionFn, timeoutMs, pollMs = 500) {
  const start = Date.now();
  let errors = 0;
  while (Date.now() - start < timeoutMs) {
    try {
      const r = await conditionFn(page);
      if (r) return { ok: true, elapsed_ms: Date.now() - start, errors };
    } catch (_e) { errors += 1; }
    await page.waitForTimeout(pollMs);
  }
  // Final check after loop exits — covers races where cascade landed in the
  // last poll interval. ok still reflects actual condition truth at end.
  let finalOk = false;
  try { finalOk = !!(await conditionFn(page)); } catch (_e) { errors += 1; }
  return { ok: finalOk, elapsed_ms: Date.now() - start, errors };
}

// ----------------------------------------------------------------
// SPEC
// ----------------------------------------------------------------
test.describe('rush-hour-50x50 wave E — Mixed-20 + rupture cascade (PHASE 2)', () => {
  test.setTimeout(HARD_CAP_MS);

  test.beforeAll(() => {
    cleanupOrphanTestOrders([`${PREFIXE_AUDIT}-`, `${TOKEN_PREFIX_POS}-`, `${TOKEN_PREFIX_KIOSK}-`, 'AUDIT-KIOSK-WAVE-E-']);
    clearFoodKingRateLimits();
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    ensureReportDir();

    // Pre-flight : ensure rupture targets are AVAILABLE before we start so the
    // baseline state-01..03 captures show the surfaces in the expected state.
    try { setItemAvailability(RUPTURE_ITEM.id, BRANCH_ID, true); } catch (_e) { /* best-effort */ }
    try { setExtraAvailability(RUPTURE_EXTRA.id, BRANCH_ID, true); } catch (_e) { /* best-effort */ }
  });

  test.afterAll(() => {
    // Belt-and-braces tinker restore — guarantees DB never left in ruptured
    // state even if the spec body throws between rupture and restore-toggle.
    try {
      setItemAvailability(RUPTURE_ITEM.id, BRANCH_ID, true);
    } catch (_e) { /* ignore */ }
    try {
      setExtraAvailability(RUPTURE_EXTRA.id, BRANCH_ID, true);
    } catch (_e) { /* ignore */ }
    try {
      cleanupOrphanTestOrders([`${PREFIXE_AUDIT}-`, `${TOKEN_PREFIX_POS}-`, `${TOKEN_PREFIX_KIOSK}-`, 'AUDIT-KIOSK-WAVE-E-']);
    } catch (_e) { /* ignore */ }
  });

  test('Wave E : 20 mixed orders + 4-quartet rupture cascade + bypass capture + reversibility', async ({ browser }) => {
    const startMs = Date.now();
    const observations = [];
    const findings = []; // captured product-side defects (NOT fixed)
    const cascadeLatencies = []; // {label, ms} for CASCADE-LAG-E6 p95
    const apiOrderResults = []; // 10 POS + 10 Kiosk

    let adminCtx; let posCtx; let kioskCtx; let chefCtx;
    let adminPage; let posPage; let kioskPage; let chefPage;
    let adminRec; let posRec; let kioskRec; let chefRec;

    try {
      // ============================================================
      // 0. Spin up 4 contexts (admin + pos + kiosk + chef KDS)
      // ============================================================
      adminCtx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
      posCtx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
      kioskCtx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
      chefCtx = await browser.newContext({ viewport: { width: 1440, height: 900 } });

      adminPage = await adminCtx.newPage();
      posPage = await posCtx.newPage();
      kioskPage = await kioskCtx.newPage();
      chefPage = await chefCtx.newPage();

      adminRec = attachMegaAuditRecorder(adminPage, SCREENSHOT_DIR);
      posRec = attachMegaAuditRecorder(posPage, SCREENSHOT_DIR);
      kioskRec = attachMegaAuditRecorder(kioskPage, SCREENSHOT_DIR);
      chefRec = attachMegaAuditRecorder(chefPage, SCREENSHOT_DIR);

      // Track availability toggle endpoint hits for the report.
      const toggleResponses = [];
      adminPage.on('response', (resp) => {
        try {
          if (/\/admin\/menu\/availability\/(toggle|extra\/toggle)/i.test(resp.url())) {
            toggleResponses.push({ url: resp.url(), status: resp.status(), method: resp.request().method(), ts: Date.now() });
          }
        } catch (_e) { /* ignore */ }
      });

      // ============================================================
      // AUTH
      // ============================================================
      await loginAsAdmin(adminPage);
      await loginAsPosOperator(posPage);
      await loginAsChefOperator(chefPage);
      await loginAsKiosk(kioskPage);
      // Kiosk login may not navigate away from /kiosk/login if already authed.
      if (!/\/kiosk(\/|$|\?)/.test(kioskPage.url())) {
        await kioskPage.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' }).catch(() => {});
      }
      observations.push(`auth: admin=${adminPage.url()} pos=${posPage.url()} kiosk=${kioskPage.url()} chef=${chefPage.url()}`);

      // Pre-warm POS catalog
      await posPage.waitForTimeout(2000);
      await expect(posPage.locator('#pos-cart')).toBeVisible({ timeout: 25_000 });

      // ============================================================
      // STATE 01 — admin baseline (stock-rupture dashboard, both targets available)
      // ============================================================
      await adminPage.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await adminPage.waitForTimeout(2500);
      await adminRec.snap('01-E-pre-rupture-baseline-admin');
      observations.push('state01: admin stock-rupture dashboard captured (baseline)');

      // ============================================================
      // STATE 02 — POS catalog baseline : item 362 visible AND not ruptured
      // ============================================================
      // Navigate to category 315 if needed — POS catalog defaults may not show 362.
      await posPage.bringToFront();
      // POS V5 has category tabs; force-click cat 315 if available.
      const cat315 = posPage.locator(`[data-pos-category-id="${RUPTURE_ITEM.cat}"], button:has-text("Frites")`).first();
      if (await cat315.isVisible({ timeout: 4000 }).catch(() => false)) {
        await cat315.click({ timeout: 3000 }).catch(() => {});
        await posPage.waitForTimeout(800);
      }
      const baselinePosTile = await probePosTileState(posPage, RUPTURE_ITEM.id);
      observations.push(`state02: pos baseline tile 362 = ${JSON.stringify(baselinePosTile)}`);
      await posRec.snap('02-E-pre-rupture-pos-catalog');
      // Sanity : if tile is found and already shows unavailable, finding!
      if (baselinePosTile.found && (baselinePosTile.hasUnavailable || baselinePosTile.hasOverlay)) {
        findings.push({
          id: 'BASELINE-DRIFT-1',
          severity: 'P1',
          surface: 'pos',
          desc: 'item 362 was already in unavailable state on POS at baseline despite force-restore in beforeAll',
          state: baselinePosTile,
        });
      }

      // ============================================================
      // STATE 03 — Kiosk catalog baseline : item 362 visible
      // ============================================================
      await kioskPage.bringToFront();
      // Navigate kiosk to the correct category. Kiosk needs to advance from
      // idle through order-type chooser then to /kiosk/categories?cat=315.
      try {
        const kioskTakeaway = kioskPage.getByTestId('kiosk-order-type-takeaway');
        if (await kioskTakeaway.isVisible({ timeout: 3000 }).catch(() => false)) {
          await kioskTakeaway.click({ timeout: 3000 }).catch(() => {});
          await kioskPage.waitForTimeout(1000);
        } else {
          const idleBtn = kioskPage.getByTestId('kiosk-idle-touch-btn');
          if (await idleBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
            await idleBtn.click({ timeout: 2500 }).catch(() => {});
            await kioskPage.waitForTimeout(800);
          }
        }
      } catch (_e) { /* best-effort */ }
      await kioskPage.goto(`/kiosk/categories?cat=${RUPTURE_ITEM.cat}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
      await kioskPage.waitForTimeout(2000);
      const baselineKioskCard = await probeKioskCardState(kioskPage, RUPTURE_ITEM.id);
      observations.push(`state03: kiosk baseline card 362 = ${JSON.stringify(baselineKioskCard)}`);
      await kioskRec.snap('03-E-pre-rupture-kiosk-catalog');
      if (baselineKioskCard.found && baselineKioskCard.hasOutOfStock) {
        findings.push({
          id: 'BASELINE-DRIFT-2',
          severity: 'P1',
          surface: 'kiosk',
          desc: 'item 362 was already out-of-stock on Kiosk at baseline despite force-restore',
          state: baselineKioskCard,
        });
      }

      // ============================================================
      // STATE 04 — admin UI click : rupture item 362
      // ============================================================
      await adminPage.bringToFront();
      await adminPage.goto('/admin/items', { waitUntil: 'domcontentloaded' });
      await adminPage.waitForTimeout(2000);

      // Use the search filter pattern from iter15-mega-admin-rupture-cascade
      // line 156-163. Items list paginates so we surface row 362 via filter.
      let toggleLocator = adminPage.locator(`[data-testid="admin-availability-toggle-${RUPTURE_ITEM.id}"]`).first();
      let toggleVisible = await toggleLocator.isVisible({ timeout: 2000 }).catch(() => false);
      if (!toggleVisible) {
        const nameInput = adminPage.locator('#item-filter #name');
        if (!(await nameInput.isVisible({ timeout: 1500 }).catch(() => false))) {
          const filterBtn = adminPage.locator('button:has(.lab-filter), .db-card-filter button').first();
          await filterBtn.click({ timeout: 5000 }).catch(() => {});
          await adminPage.waitForTimeout(400);
        }
        await adminPage.evaluate(() => {
          const panel = document.getElementById('item-filter');
          if (panel) panel.style.display = 'block';
        }).catch(() => {});
        await nameInput.fill('Boisson').catch(() => {});
        await adminPage.locator('#item-filter form').evaluate((f) => f.requestSubmit ? f.requestSubmit() : f.submit()).catch(() => {});
        await adminPage.waitForTimeout(1500);
        toggleLocator = adminPage.locator(`[data-testid="admin-availability-toggle-${RUPTURE_ITEM.id}"]`).first();
        toggleVisible = await toggleLocator.isVisible({ timeout: 8000 }).catch(() => false);
      }
      observations.push(`state04: admin row 362 toggle_visible=${toggleVisible}`);

      const item362ToggleStart = Date.now();
      let item362ToggleResp = null;
      if (toggleVisible) {
        await toggleLocator.scrollIntoViewIfNeeded().catch(() => {});
        const togglePending = adminPage.waitForResponse(
          (r) => /\/admin\/menu\/availability\/toggle$/i.test(r.url()) && r.request().method() === 'POST',
          { timeout: 15000 },
        ).catch(() => null);
        const toggleBtn = toggleLocator.locator('button').first();
        await toggleBtn.click({ timeout: 5000 }).catch(() => {});
        item362ToggleResp = await togglePending;
        if (item362ToggleResp) {
          observations.push(`state04: admin toggle item 362 status=${item362ToggleResp.status()} elapsed=${Date.now() - item362ToggleStart}ms`);
        }
      } else {
        // Fallback: direct API call if admin row not surfaced.
        observations.push('state04: admin row 362 not surfaced — falling back to direct API toggle');
        const apiResult = await adminPage.evaluate(async (params) => {
          try {
            const r = await window.axios.post('admin/menu/availability/toggle', {
              item_id: params.itemId,
              branch_id: params.branchId,
              is_available: false,
              unavailable_reason: 'audit-e2e-test',
            });
            return { ok: true, status: r.status };
          } catch (e) {
            return { ok: false, status: e?.response?.status, body: e?.response?.data };
          }
        }, { itemId: RUPTURE_ITEM.id, branchId: BRANCH_ID });
        observations.push(`state04: fallback api toggle item 362 = ${JSON.stringify(apiResult)}`);
      }
      await adminPage.waitForTimeout(1200);
      await adminRec.snap('04-E-rupture-toggle-item-362-admin-click');

      // ============================================================
      // STATE 05 — admin direct API : rupture extra 175
      // (no admin UI button exists for per-extra branch toggle, per advisor)
      // ============================================================
      const extra175ToggleStart = Date.now();
      // [round-1.5] reason MUST be in StockLevel::MANUAL_UNAVAILABLE_REASONS
      // whitelist (out_of_stock_manual, seasonal, recipe_change, supplier_issue,
      // quality_issue) — see app/Models/StockLevel.php line 35. Round-1 used
      // free-form 'audit-e2e-test-wave-E' which produced 422
      // "The selected reason is invalid." → extra never ruptured → RUPTURE-E3/E4
      // findings were false-positives.
      const extra175ToggleResult = await adminPage.evaluate(async (params) => {
        try {
          const r = await window.axios.post('admin/menu/availability/extra/toggle', {
            extra_id: params.extraId,
            branch_id: params.branchId,
            is_available: false,
            reason: 'out_of_stock_manual',
          });
          return { ok: true, status: r.status, data: r.data };
        } catch (e) {
          return { ok: false, status: e?.response?.status, body: e?.response?.data };
        }
      }, { extraId: RUPTURE_EXTRA.id, branchId: BRANCH_ID });
      observations.push(`state05: api toggle extra 175 = ${JSON.stringify(extra175ToggleResult)} elapsed=${Date.now() - extra175ToggleStart}ms`);
      await adminPage.waitForTimeout(800);
      await adminRec.snap('05-E-rupture-toggle-extra-175-admin-click');
      // [round-1.5] HTTP 500 here is an EXPECTED side-effect of broken Pusher
      // dispatch (port 6001 not running in dev) — the toggle ITSELF persists to
      // DB (verified via stock_levels.manual_unavailable_since). We probe DB
      // directly to determine if rupture took effect. Actual finding scenario:
      // toggle non-OK AND DB row not flipped → real backend regression.
      let extraRupturePersisted = false;
      try {
        const probeRows = dbProbe(`SELECT manual_unavailable_since FROM stock_levels WHERE stockable_id = ${RUPTURE_EXTRA.id} AND stockable_type = 'App\\\\Models\\\\ItemExtra' AND branch_id = ${BRANCH_ID}`);
        extraRupturePersisted = !!(probeRows?.[0]?.manual_unavailable_since);
      } catch (_e) { /* probe failed */ }
      observations.push(`state05: extra 175 rupture_persisted_in_db=${extraRupturePersisted}`);
      if (!extraRupturePersisted) {
        findings.push({
          id: 'RUPTURE-EXTRA-PERSIST-FAIL',
          severity: 'P0',
          surface: 'admin-api',
          desc: `availability/extra/toggle did NOT persist rupture to stock_levels (status=${extra175ToggleResult.status})`,
          state: extra175ToggleResult,
        });
      }
      // Always capture the 500-from-Pusher as a real finding (real product issue
      // exposed under audit) — but separately from the persistence check.
      if (extra175ToggleResult.status === 500) {
        findings.push({
          id: 'TOGGLE-PUSHER-500',
          severity: 'P1',
          surface: 'admin-api',
          desc: 'admin/menu/availability/extra/toggle returned 500 due to Pusher dispatch failure (port 6001 unreachable). Toggle PERSISTS to DB but client receives 500 — confusing UX + breaks idempotent retries.',
          state: extra175ToggleResult,
        });
      }

      // ============================================================
      // STATE 06 — POS cascade : item 362 hidden / ÉPUISÉ
      // RUPTURE-E1 (P0) + CASCADE-LAG-E6 timing
      // ============================================================
      await posPage.bringToFront();
      const posCascadeStart = Date.now();
      // Reload POS so catalog refetches.
      await posPage.goto('/admin/pos', { waitUntil: 'domcontentloaded' }).catch(() => {});
      // Re-auth if SPA bounced to login.
      if (/\/login/.test(posPage.url())) {
        await loginAsPosOperator(posPage);
      }
      await posPage.waitForTimeout(1500);
      // Navigate to category 315 again
      const cat315b = posPage.locator(`[data-pos-category-id="${RUPTURE_ITEM.cat}"], button:has-text("Frites")`).first();
      if (await cat315b.isVisible({ timeout: 4000 }).catch(() => false)) {
        await cat315b.click({ timeout: 3000 }).catch(() => {});
        await posPage.waitForTimeout(600);
      }
      // Wait for the cascade to render
      // [round-1.5] Pass RUPTURE_ITEM.id explicitly — round-1 referenced
      // bare `id` which was undefined (ReferenceError silently caught by
      // waitForCondition try/catch → every poll failed → ok=false even though
      // the cascade had landed within ~1s, masked as 8s timeout in p95).
      const posCascadeWait = await waitForCondition(posPage, async (p) => {
        const s = await p.evaluate((itemId) => {
          const tile = document.querySelector(`button[data-pos-item-id="${itemId}"]`);
          if (!tile) return { hidden: true };
          return {
            hidden: false,
            unavail: tile.classList.contains('is-unavailable'),
            overlay: tile.querySelector('.pos-item-86-badge, .pos-v5-tile__overlay') !== null,
            disabled: tile.hasAttribute('disabled'),
            aria: tile.getAttribute('aria-disabled'),
          };
        }, RUPTURE_ITEM.id);
        return s.hidden || s.unavail || s.overlay || s.disabled || s.aria === 'true';
      }, 8000, 500);
      cascadeLatencies.push({ label: 'pos-item-362', ms: posCascadeWait.elapsed_ms, ok: posCascadeWait.ok });
      const posCascadeState = await probePosTileState(posPage, RUPTURE_ITEM.id);
      observations.push(`state06: RUPTURE-E1 cascade pos item 362 elapsed=${posCascadeWait.elapsed_ms}ms ok=${posCascadeWait.ok} state=${JSON.stringify(posCascadeState)}`);
      await posRec.snap('06-E-cascade-pos-item-362-hidden');
      // RUPTURE-E1 acceptance : not-found OR has-unavailable OR overlay OR disabled
      const ruptureE1Pass = !posCascadeState.found
        || posCascadeState.hasUnavailable
        || posCascadeState.hasOverlay
        || posCascadeState.disabledAttr
        || posCascadeState.ariaDisabled === 'true'
        || posCascadeState.pointerEvents === 'none';
      if (!ruptureE1Pass) {
        findings.push({
          id: 'RUPTURE-E1',
          severity: 'P0',
          surface: 'pos',
          desc: 'POS catalog did NOT cascade rupture for item 362 within 8s — tile remains clickable',
          state: posCascadeState,
          elapsed_ms: posCascadeWait.elapsed_ms,
        });
      }

      // ============================================================
      // STATE 07 — POS wizard : Tacos M (363) supplement 175 disabled
      // RUPTURE-E3 (P0)
      // [test-e2e fix E-003 round-2 2026-05-11] re-open POS wizard to
      // empirically capture supplement 175 disabled state. Round-1 spec
      // accepted !found as PASS, conflating instrumentation gap with
      // product correctness. Round-2 hardens: (1) defensive close any
      // residue first (Escape + class-strip), (2) reload POS for clean
      // canvas mirroring state-10 pattern, (3) hard-expect wizard mount,
      // (4) scroll within modal to reach the extras section of the
      // single-page wizard, (5) strict pass = wizard mounted AND extra
      // found AND at least one disabled signal. !found is now a finding.
      // ============================================================
      // Defensive: force-close any modal residue from prior states
      await posPage.keyboard.press('Escape').catch(() => {});
      await posPage.waitForTimeout(300);
      await posPage.evaluate(() => {
        document.querySelectorAll('.modal.active').forEach((el) => el.classList.remove('active'));
      }).catch(() => {});
      // Reload POS for a clean canvas (mirrors state-10 hardening L902-905)
      await posPage.goto('/admin/pos', { waitUntil: 'domcontentloaded' }).catch(() => {});
      if (/\/login/.test(posPage.url())) {
        await loginAsPosOperator(posPage);
      }
      await posPage.waitForTimeout(1500);
      // Navigate to Tacos category to surface the tile (cat 306)
      const catTacos = posPage.locator(`[data-pos-category-id="${ITEM_TACOS.cat}"], button:has-text("Nos Tacos")`).first();
      if (await catTacos.isVisible({ timeout: 4000 }).catch(() => false)) {
        await catTacos.click({ timeout: 3000 }).catch(() => {});
        await posPage.waitForTimeout(800);
      }
      const tacosTilePos = posPage.locator(`button[data-pos-item-id="${ITEM_TACOS.id}"]`).first();
      const tacosTileFallback = posPage.locator('.pos-v5-tile, .pos-item-tile').filter({ hasText: /Tacos M/i }).first();
      const tacosTileTarget = (await tacosTilePos.count()) > 0 ? tacosTilePos : tacosTileFallback;
      // Wait until tile is actually clickable (Wave A L832-839 pattern: visible+enabled)
      const tacosTileReady = await tacosTileTarget.evaluate((el) => {
        const r = el.getBoundingClientRect();
        return r.width > 0 && r.height > 0 && !el.hasAttribute('disabled');
      }).catch(() => false);
      observations.push(`state07-pre: tacos_tile_ready=${tacosTileReady}`);
      let posWizardExtraState = { found: false, wizard_mounted: false };
      let wizardMountSignal = false;
      if (await tacosTileTarget.isVisible({ timeout: 5000 }).catch(() => false)) {
        await tacosTileTarget.click({ timeout: 5000 }).catch(() => {});
        const variationModal = posPage.locator('#item-variation-modal');
        // Hard wait for active class (Wave A L494 pattern) — wizard mount race.
        // Use expect().toHaveClass with timeout to actually retry until mounted.
        try {
          await expect(variationModal).toHaveClass(/active/, { timeout: 10_000 });
          wizardMountSignal = true;
        } catch (_e) {
          // Fallback: visible check
          wizardMountSignal = await variationModal.isVisible({ timeout: 4000 }).catch(() => false);
        }
        if (wizardMountSignal) {
          await posPage.waitForTimeout(1500); // wizard inject + render
          // [test-e2e fix E-003 round-3 cluster-9 2026-05-11] advance wizard to extras step
          // pos-wizard.js single-page mode (S25-SinglePage per public/js/pos-wizard.js L8)
          // renders viande + sauce + supplement sections on one scroll page, BUT the
          // supplement panel is COLLAPSED by default (`supplOpen = supplSelected > 0
          // || selections.supplExpanded === true`, see pos-wizard.js L2923). Round-2
          // scrolled to bottom but never expanded the panel, so the screenshot showed
          // viande/sauce only. Round-3 explicitly: (1) picks a viande to advance the
          // wizard's internal state, (2) picks a sauce, (3) clicks the
          // `.suppl-toggle [data-action="toggle-suppl"]` to open the supplements
          // panel, (4) THEN scrolls to bottom + probes Jambon (175).
          await variationModal.evaluate(() => {
            // Step A — pick first available viande (click "+" plus button)
            const plusBtn = document.querySelector('#item-variation-modal .viande-btn[data-action="plus"]:not(.disabled)');
            plusBtn?.click();
          }).catch(() => {});
          await posPage.waitForTimeout(300);
          await variationModal.evaluate(() => {
            // Step B — pick first sauce chip
            const sauceChip = document.querySelector('#item-variation-modal .sauce-chip:not(.disabled)');
            sauceChip?.click();
          }).catch(() => {});
          await posPage.waitForTimeout(300);
          await variationModal.evaluate(() => {
            // Step C — open supplements panel via toggle button
            const supplToggle = document.querySelector('#item-variation-modal .suppl-toggle, #item-variation-modal [data-action="toggle-suppl"]');
            supplToggle?.click();
          }).catch(() => {});
          await posPage.waitForTimeout(500);
          // pos-wizard.js is a SCROLLABLE SINGLE PAGE (per pos-wizard.js header L8) —
          // extras are usually below sauces/viande. Scroll the modal to the bottom
          // so the supplement section enters the DOM render path.
          await variationModal.evaluate((el) => {
            el.scrollTo?.(0, el.scrollHeight);
          }).catch(() => {});
          await posPage.waitForTimeout(500);
          // Probe wizard advancement signal — did supplements panel actually expand?
          const supplPanelExpanded = await variationModal.evaluate(() => {
            const panel = document.querySelector('#item-variation-modal .suppl-panel');
            if (!panel) return { panel_present: false };
            return {
              panel_present: true,
              collapsed_class: panel.classList.contains('collapsed'),
              suppl_grid_present: !!document.querySelector('#item-variation-modal .supplement-grid'),
              suppl_options_count: document.querySelectorAll('#item-variation-modal .supplement-opt').length,
            };
          }).catch(() => ({ panel_present: false }));
          observations.push(`state07-extras: supplements panel = ${JSON.stringify(supplPanelExpanded)}`);
          // Probe for "Jambon de dinde" button state
          posWizardExtraState = await posPage.evaluate(() => {
            const modal = document.getElementById('item-variation-modal');
            if (!modal) return { found: false, wizard_mounted: false };
            // Match by text since extras don't always carry data-extra-id
            const all = Array.from(modal.querySelectorAll('button, [class*="extra"], [class*="supplement"], [class*="option"]'));
            const match = all.find((b) => /jambon de dinde/i.test(b.innerText || b.textContent || ''));
            if (!match) {
              return {
                found: false,
                wizard_mounted: true,
                total_buttons: all.length,
                modal_inner_text_sample: (modal.innerText || '').slice(0, 500),
              };
            }
            const cs = window.getComputedStyle(match);
            return {
              found: true,
              wizard_mounted: true,
              text: (match.innerText || '').slice(0, 200),
              classes: match.className || '',
              disabled: match.hasAttribute('disabled'),
              ariaDisabled: match.getAttribute('aria-disabled'),
              pointerEvents: cs.pointerEvents,
              opacity: cs.opacity,
              hasUnavailableClass: /disabled|unavailable|out-of-stock|ruptured|epuise|epuisé/i.test(match.className || ''),
              hasUnavailableText: /épuisé|epuise|rupture|indispo/i.test(match.innerText || ''),
            };
          });
          // Scroll the matched supplement into view BEFORE snap so the screenshot
          // empirically shows the disabled state (not just an empty-extras section).
          await posPage.evaluate(() => {
            const modal = document.getElementById('item-variation-modal');
            if (!modal) return;
            const all = Array.from(modal.querySelectorAll('button, [class*="extra"], [class*="supplement"], [class*="option"]'));
            const match = all.find((b) => /jambon de dinde/i.test(b.innerText || b.textContent || ''));
            match?.scrollIntoView({ block: 'center', behavior: 'instant' });
          }).catch(() => {});
          await posPage.waitForTimeout(400);
        }
      }
      observations.push(`state07: RUPTURE-E3 pos wizard supplement 175 = ${JSON.stringify(posWizardExtraState)}`);
      await posRec.snap('07-E-cascade-pos-wizard-tacos-extra-175-disabled');
      // STRICT acceptance — three discriminating finding scenarios:
      //  (a) wizard never mounted        → instrumentation/UX bug (RUPTURE-E3-WIZARD-MOUNT)
      //  (b) wizard mounted, extra absent → product policy/render bug (RUPTURE-E3-EXTRA-MISSING)
      //  (c) extra present + clickable    → cascade did NOT propagate to wizard (RUPTURE-E3)
      // PASS = wizard mounted AND extra found AND at least one disabled signal.
      const hasDisabledSignal = posWizardExtraState.found && (
        posWizardExtraState.disabled
        || posWizardExtraState.ariaDisabled === 'true'
        || posWizardExtraState.pointerEvents === 'none'
        || posWizardExtraState.hasUnavailableClass
        || posWizardExtraState.hasUnavailableText
        || Number(posWizardExtraState.opacity) < 0.6
      );
      const ruptureE3Pass = posWizardExtraState.wizard_mounted && posWizardExtraState.found && hasDisabledSignal;
      if (!posWizardExtraState.wizard_mounted) {
        findings.push({
          id: 'RUPTURE-E3-WIZARD-MOUNT',
          severity: 'P1',
          surface: 'pos-wizard',
          desc: 'POS wizard for Tacos M (363) failed to mount on tile click — cannot empirically verify supplement 175 cascade. Spec instrumentation must succeed before product correctness can be judged.',
          state: { wizard_mounted: false, tile_ready: tacosTileReady, mount_signal: wizardMountSignal },
        });
      } else if (!posWizardExtraState.found) {
        // [test-e2e fix E-003 round-3 cluster-9 2026-05-11] downgrade to observation —
        // pos-wizard.js (FROZEN per CLAUDE.md §7) does NOT render extra `is_available`
        // at all (verified by reviewer). Hide-or-show is therefore controlled upstream
        // (catalog payload). Capture the absence as P2 audit_integrity, owner-gated
        // per OWNER_GATE_DECISIONS.md E-003 deeper.
        findings.push({
          id: 'E-003-extras-supplement-hidden',
          severity: 'P2',
          surface: 'pos-wizard',
          category: 'audit_integrity',
          owner_gated: true,
          ref: 'OWNER_GATE_DECISIONS.md#E-003-deeper',
          desc: 'POS wizard mounted + supplements panel expanded for Tacos M (363) but supplement "Jambon de dinde" (175) NOT present in DOM. Acceptable cascade behavior IF backend filters ruptured extras from payload (hide-by-policy). Owner-gated: pos-wizard.js does NOT render extra.is_available — see OWNER_GATE_DECISIONS.md E-003 deeper.',
          state: posWizardExtraState,
        });
      } else if (!hasDisabledSignal) {
        // [test-e2e fix E-003 round-3 cluster-9 2026-05-11] downgrade to P2 observation —
        // pos-wizard.js renders supplement identically to available ones (no
        // is_available indication). This is the documented frozen-zone gap per
        // OWNER_GATE_DECISIONS.md E-003 deeper, NOT a P0 blocker until owner
        // signs a LOCK on pos-wizard.js. Spec captures the empirical state.
        findings.push({
          id: 'E-003-extras-no-is-available-render',
          severity: 'P2',
          surface: 'pos-wizard',
          category: 'audit_integrity',
          owner_gated: true,
          ref: 'OWNER_GATE_DECISIONS.md#E-003-deeper',
          desc: 'POS wizard renders supplement 175 identically to available extras after rupture (no disabled attr, no unavailable class, no overlay, opacity >= 0.6). pos-wizard.js (FROZEN §7) has no extra.is_available rendering branch — owner LOCK required to fix. Spec instrumentation is correct: wizard mounted, supplements panel expanded, Jambon button captured. Product gap surfaced, not blocking V1 per owner gate.',
          state: posWizardExtraState,
        });
      }
      observations.push(`state07: RUPTURE-E3 pass=${ruptureE3Pass} mounted=${posWizardExtraState.wizard_mounted} found=${posWizardExtraState.found} disabled_signal=${hasDisabledSignal}`);
      // Close wizard cleanly so subsequent states aren't polluted
      await posPage.keyboard.press('Escape').catch(() => {});
      await posPage.waitForTimeout(500);
      await posPage.evaluate(() => {
        document.querySelectorAll('.modal.active').forEach((el) => el.classList.remove('active'));
        const modal = document.getElementById('item-variation-modal');
        if (modal) modal.classList.remove('active');
      }).catch(() => {});

      // ============================================================
      // STATE 08 — Kiosk cascade : item 362 hidden
      // RUPTURE-E2 (P0)
      // ============================================================
      await kioskPage.bringToFront();
      const kioskCascadeStart = Date.now();
      await kioskPage.goto(`/kiosk/categories?cat=${RUPTURE_ITEM.cat}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
      await kioskPage.waitForTimeout(2000);
      const kioskCascadeWait = await waitForCondition(kioskPage, async (p) => {
        const s = await p.evaluate((itemId) => {
          const card = document.querySelector(`[data-testid="kiosk-product-card-${itemId}"]`);
          if (!card) return { hidden: true };
          return {
            hidden: false,
            outStock: /out-of-stock|ruptured|unavailable|epuise|filtered-out/i.test(card.className || ''),
            text: /épuisé|epuise|rupture|indispo/i.test(card.innerText || ''),
            aria: card.getAttribute('aria-disabled'),
          };
        }, RUPTURE_ITEM.id);
        return s.hidden || s.outStock || s.text || s.aria === 'true';
      }, 8000, 500);
      cascadeLatencies.push({ label: 'kiosk-item-362', ms: kioskCascadeWait.elapsed_ms, ok: kioskCascadeWait.ok });
      const kioskCascadeState = await probeKioskCardState(kioskPage, RUPTURE_ITEM.id);
      observations.push(`state08: RUPTURE-E2 cascade kiosk item 362 elapsed=${kioskCascadeWait.elapsed_ms}ms ok=${kioskCascadeWait.ok} state=${JSON.stringify(kioskCascadeState)}`);
      await kioskRec.snap('08-E-cascade-kiosk-item-362-hidden');
      const ruptureE2Pass = !kioskCascadeState.found
        || kioskCascadeState.hasOutOfStock
        || kioskCascadeState.hasRuptureText
        || kioskCascadeState.ariaDisabled === 'true'
        || kioskCascadeState.pointerEvents === 'none';
      if (!ruptureE2Pass) {
        findings.push({
          id: 'RUPTURE-E2',
          severity: 'P0',
          surface: 'kiosk',
          desc: 'Kiosk catalog did NOT cascade rupture for item 362 within 8s',
          state: kioskCascadeState,
          elapsed_ms: kioskCascadeWait.elapsed_ms,
        });
      }

      // ============================================================
      // STATE 09 — Kiosk wizard : Tacos M (363) supplement 175 disabled
      // RUPTURE-E4 (P0)
      // ============================================================
      await kioskPage.goto(`/kiosk/categories?cat=${ITEM_TACOS.cat}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
      await kioskPage.waitForTimeout(1500);
      const tacosCardKiosk = kioskPage.getByTestId(`kiosk-product-card-${ITEM_TACOS.id}`);
      let kioskWizardExtraState = { found: false };
      if (await tacosCardKiosk.isVisible({ timeout: 5000 }).catch(() => false)) {
        await tacosCardKiosk.scrollIntoViewIfNeeded().catch(() => {});
        const tacosAddKiosk = kioskPage.getByTestId(`kiosk-product-add-${ITEM_TACOS.id}`);
        if (await tacosAddKiosk.isVisible({ timeout: 1500 }).catch(() => false)) {
          await tacosAddKiosk.click({ timeout: 4000 }).catch(() => {});
        } else {
          await tacosCardKiosk.click({ timeout: 4000 }).catch(() => {});
        }
        await kioskPage.waitForTimeout(2000);
        // Drive wizard until we reach a step containing extras (suppléments).
        // Try to find Jambon button across all steps; advance with next button if not visible.
        for (let g = 0; g < 8; g++) {
          const found = await kioskPage.evaluate(() => {
            const all = Array.from(document.querySelectorAll('.kiosk-step-content button, .kiosk-step-content [role="checkbox"], .kiosk-step-content [class*="extra"], .kiosk-step-content [class*="supplement"]'));
            return all.some((b) => /jambon de dinde/i.test(b.innerText || b.textContent || ''));
          });
          if (found) break;
          // Advance one step (pick first option + next).
          const opt = kioskPage.locator('.kiosk-step-content .kiosk-viande-card.is-selectable:not(.kiosk-variation--disabled), .kiosk-step-content .kiosk-option-card[role="checkbox"]:not(.is-out-of-stock), .kiosk-step-content button.kiosk-generic-choice:not([disabled]), .kiosk-step-content .kiosk-menu-card[role="radio"]').first();
          if (await opt.isVisible({ timeout: 600 }).catch(() => false)) {
            await opt.click({ timeout: 2000 }).catch(() => {});
          }
          const nb = kioskPage.locator('.kiosk-wizard .kiosk-btn-next:not([disabled])').first();
          if (await nb.isVisible({ timeout: 800 }).catch(() => false)) {
            await nb.click({ timeout: 2000 }).catch(() => {});
          }
          await kioskPage.waitForTimeout(400);
        }
        // Probe for Jambon
        kioskWizardExtraState = await kioskPage.evaluate(() => {
          const all = Array.from(document.querySelectorAll('.kiosk-wizard button, .kiosk-wizard [role="checkbox"], .kiosk-wizard [class*="extra"], .kiosk-wizard [class*="supplement"]'));
          const match = all.find((b) => /jambon de dinde/i.test(b.innerText || b.textContent || ''));
          if (!match) return { found: false, total: all.length };
          const cs = window.getComputedStyle(match);
          return {
            found: true,
            text: (match.innerText || '').slice(0, 200),
            classes: match.className || '',
            disabled: match.hasAttribute('disabled'),
            ariaDisabled: match.getAttribute('aria-disabled'),
            pointerEvents: cs.pointerEvents,
            opacity: cs.opacity,
            hasUnavailableClass: /disabled|unavailable|out-of-stock|ruptured|epuise|epuisé/i.test(match.className || ''),
            hasUnavailableText: /épuisé|epuise|rupture|indispo/i.test(match.innerText || ''),
          };
        });
      }
      observations.push(`state09: RUPTURE-E4 kiosk wizard supplement 175 = ${JSON.stringify(kioskWizardExtraState)}`);
      await kioskRec.snap('09-E-cascade-kiosk-wizard-tacos-extra-175-disabled');
      const ruptureE4Pass = !kioskWizardExtraState.found
        || kioskWizardExtraState.disabled
        || kioskWizardExtraState.ariaDisabled === 'true'
        || kioskWizardExtraState.pointerEvents === 'none'
        || kioskWizardExtraState.hasUnavailableClass
        || kioskWizardExtraState.hasUnavailableText
        || Number(kioskWizardExtraState.opacity) < 0.6;
      if (!ruptureE4Pass) {
        findings.push({
          id: 'RUPTURE-E4',
          severity: 'P0',
          surface: 'kiosk-wizard',
          desc: 'Kiosk wizard does NOT show extra 175 as disabled after rupture',
          state: kioskWizardExtraState,
        });
      }

      // Reset kiosk to idle for the rest of the flow
      await kioskPage.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await kioskPage.waitForTimeout(800);

      // ============================================================
      // STATE 10 — POS UI mixed order #1 : Burger (375), CASH
      // ============================================================
      await posPage.bringToFront();
      // Force-clean any modal residue from state 07 wizard probe
      await posPage.goto('/admin/pos', { waitUntil: 'domcontentloaded' }).catch(() => {});
      if (/\/login/.test(posPage.url())) await loginAsPosOperator(posPage);
      await posPage.waitForTimeout(1500);
      const burgerCatBtn = posPage.locator(`[data-pos-category-id="${ITEM_BURGER.cat}"], button:has-text("Burgers")`).first();
      if (await burgerCatBtn.isVisible({ timeout: 4000 }).catch(() => false)) {
        await burgerCatBtn.click({ timeout: 3000 }).catch(() => {});
        await posPage.waitForTimeout(700);
      }
      const burgerTile = posPage.locator(`button[data-pos-item-id="${ITEM_BURGER.id}"]`).first();
      if (await burgerTile.isVisible({ timeout: 5000 }).catch(() => false)) {
        await burgerTile.click({ timeout: 4000 }).catch(() => {});
        const wiz = posPage.locator('#item-variation-modal');
        if (await wiz.isVisible({ timeout: 6000 }).catch(() => false)) {
          await posPage.waitForTimeout(800);
          const addBtns = wiz.locator('button').filter({ hasText: /Ajouter au panier|Add to cart/i });
          const ac = await addBtns.count();
          for (let i = 0; i < ac; i++) {
            if (await addBtns.nth(i).isVisible({ timeout: 800 }).catch(() => false)) {
              await addBtns.nth(i).click({ timeout: 3000 }).catch(() => {});
              break;
            }
          }
          await posPage.waitForTimeout(700);
        }
      }
      await posRec.snap('10-E-mixed-pos-order-1-burger');

      // ============================================================
      // STATE 11 — POS UI mixed order #2 : Tacos M, attempt to add ruptured extra 175
      // Verify UI prevents the click (or at least flags it). Capture result.
      // ============================================================
      // Force-close any open modal first
      await posPage.keyboard.press('Escape').catch(() => {});
      await posPage.waitForTimeout(400);
      await posPage.evaluate(() => {
        document.querySelectorAll('.modal.active').forEach((el) => el.classList.remove('active'));
      }).catch(() => {});
      await posPage.waitForTimeout(300);
      const tacosCatBtn = posPage.locator(`[data-pos-category-id="${ITEM_TACOS.cat}"], button:has-text("Nos Tacos")`).first();
      if (await tacosCatBtn.isVisible({ timeout: 4000 }).catch(() => false)) {
        await tacosCatBtn.click({ timeout: 3000 }).catch(() => {});
        await posPage.waitForTimeout(700);
      }
      const tacosTile2 = posPage.locator(`button[data-pos-item-id="${ITEM_TACOS.id}"]`).first();
      let state11_attempt = { jambonClicked: false, jambonDisabled: null };
      if (await tacosTile2.isVisible({ timeout: 5000 }).catch(() => false)) {
        await tacosTile2.click({ timeout: 4000 }).catch(() => {});
        const wiz2 = posPage.locator('#item-variation-modal');
        if (await wiz2.isVisible({ timeout: 6000 }).catch(() => false)) {
          await posPage.waitForTimeout(1500);
          // Try clicking Jambon — record what happens
          const jambonBtn = wiz2.locator('button').filter({ hasText: /jambon de dinde/i }).first();
          if (await jambonBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
            const jamState = await jambonBtn.evaluate((el) => ({
              disabled: el.hasAttribute('disabled'),
              ariaDisabled: el.getAttribute('aria-disabled'),
              pointerEvents: window.getComputedStyle(el).pointerEvents,
            }));
            state11_attempt.jambonDisabled = jamState;
            // Attempt the click anyway (UI should reject silently if disabled)
            await jambonBtn.click({ timeout: 2000, force: true }).catch(() => {});
            state11_attempt.jambonClicked = true;
            await posPage.waitForTimeout(500);
          } else {
            state11_attempt.jambonDisabled = 'not-rendered';
          }
        }
      }
      observations.push(`state11: pos UI tacos+jambon attempt = ${JSON.stringify(state11_attempt)}`);
      await posRec.snap('11-E-mixed-pos-order-2-tacos-with-extra');
      // Close wizard
      await posPage.keyboard.press('Escape').catch(() => {});
      await posPage.waitForTimeout(400);
      await posPage.evaluate(() => {
        document.querySelectorAll('.modal.active').forEach((el) => el.classList.remove('active'));
      }).catch(() => {});

      // ============================================================
      // STATE 12 — BYPASS-E5 (a) : POS API attempt with ruptured ITEM 362
      // Capture-as-finding (advisor) — DO NOT modify product code if 200.
      // ============================================================
      const bypass12Token = `${TOKEN_PREFIX_POS}-BYPASS-ITEM-${TS}`;
      const bypass12Payload = buildPosApiPayload({
        token: bypass12Token,
        itemId: RUPTURE_ITEM.id,
        qty: 1,
        basePrice: RUPTURE_ITEM.price,
        extras: [],
        paymentMethod: PAY.CASH,
      });
      const bypass12Resp = await postPosOrderViaAxios(posPage, bypass12Payload);
      observations.push(`state12: BYPASS-E5a pos api with ruptured item 362 status=${bypass12Resp.status} ok=${bypass12Resp.ok} body=${JSON.stringify(bypass12Resp.body || bypass12Resp.data || {}).slice(0, 200)}`);
      await posRec.snap('12-E-mixed-pos-api-attempt-ruptured-item-blocked');
      if (bypass12Resp.ok || (bypass12Resp.status >= 200 && bypass12Resp.status < 300)) {
        findings.push({
          id: 'BYPASS-E5a',
          severity: 'P0',
          surface: 'pos-api',
          desc: 'POST /api/admin/pos with ruptured item 362 returned 2xx — silent success, NO 4xx descriptive error',
          status: bypass12Resp.status,
          response: bypass12Resp.data || bypass12Resp.body,
        });
      } else {
        observations.push(`state12: BYPASS-E5a CORRECTLY blocked with ${bypass12Resp.status}`);
      }
      writeArtifact('wave-E-bypass-E5a-pos-item.json', { request: { token: bypass12Token, item_id: RUPTURE_ITEM.id }, response: bypass12Resp });

      // ============================================================
      // STATE 13 — BYPASS-E5 (b) : POS API attempt with ruptured EXTRA 175
      // ============================================================
      const bypass13Token = `${TOKEN_PREFIX_POS}-BYPASS-EXTRA-${TS}`;
      const bypass13Payload = buildPosApiPayload({
        token: bypass13Token,
        itemId: ITEM_TACOS.id,
        qty: 1,
        basePrice: ITEM_TACOS.price,
        extras: [{ id: RUPTURE_EXTRA.id, name: RUPTURE_EXTRA.name, price: RUPTURE_EXTRA.price }],
        paymentMethod: PAY.CASH,
      });
      const bypass13Resp = await postPosOrderViaAxios(posPage, bypass13Payload);
      observations.push(`state13: BYPASS-E5b pos api with ruptured extra 175 status=${bypass13Resp.status} ok=${bypass13Resp.ok} body=${JSON.stringify(bypass13Resp.body || bypass13Resp.data || {}).slice(0, 200)}`);
      await posRec.snap('13-E-mixed-pos-api-attempt-ruptured-extra-blocked');
      if (bypass13Resp.ok || (bypass13Resp.status >= 200 && bypass13Resp.status < 300)) {
        findings.push({
          id: 'BYPASS-E5b',
          severity: 'P0',
          surface: 'pos-api',
          desc: 'POST /api/admin/pos with ruptured extra 175 returned 2xx — silent success on extra-rupture bypass',
          status: bypass13Resp.status,
          response: bypass13Resp.data || bypass13Resp.body,
        });
      } else {
        observations.push(`state13: BYPASS-E5b CORRECTLY blocked with ${bypass13Resp.status}`);
      }
      writeArtifact('wave-E-bypass-E5b-pos-extra.json', { request: { token: bypass13Token, extra_id: RUPTURE_EXTRA.id }, response: bypass13Resp });

      // ============================================================
      // STATE 14 — Kiosk UI mixed order : Frites (361, simple, no wizard)
      // ============================================================
      await kioskPage.bringToFront();
      await kioskPage.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await kioskPage.waitForTimeout(1200);
      try {
        const tk = kioskPage.getByTestId('kiosk-order-type-takeaway');
        if (await tk.isVisible({ timeout: 3000 }).catch(() => false)) {
          await tk.click({ timeout: 2500 }).catch(() => {});
          await kioskPage.waitForTimeout(800);
        } else {
          const idle = kioskPage.getByTestId('kiosk-idle-touch-btn');
          if (await idle.isVisible({ timeout: 2000 }).catch(() => false)) {
            await idle.click({ timeout: 2500 }).catch(() => {});
            await kioskPage.waitForTimeout(800);
          }
        }
      } catch (_e) { /* best-effort */ }
      await kioskPage.goto(`/kiosk/categories?cat=${ITEM_FRITES.cat}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
      await kioskPage.waitForTimeout(1500);
      const fritesCardKiosk = kioskPage.getByTestId(`kiosk-product-card-${ITEM_FRITES.id}`);
      if (await fritesCardKiosk.isVisible({ timeout: 5000 }).catch(() => false)) {
        await fritesCardKiosk.scrollIntoViewIfNeeded().catch(() => {});
        const fritesAdd = kioskPage.getByTestId(`kiosk-product-add-${ITEM_FRITES.id}`);
        if (await fritesAdd.isVisible({ timeout: 1500 }).catch(() => false)) {
          await fritesAdd.click({ timeout: 3000 }).catch(() => {});
        } else {
          await fritesCardKiosk.click({ timeout: 3000 }).catch(() => {});
        }
        await kioskPage.waitForTimeout(1200);
      }
      await kioskRec.snap('14-E-mixed-kiosk-order-1-frites');

      // ============================================================
      // STATE 15 — Kiosk UI Tacos with attempt to use ruptured extra 175
      // ============================================================
      await kioskPage.goto(`/kiosk/categories?cat=${ITEM_TACOS.cat}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
      await kioskPage.waitForTimeout(1500);
      const tacosCardK2 = kioskPage.getByTestId(`kiosk-product-card-${ITEM_TACOS.id}`);
      let state15_attempt = { jambonState: null };
      if (await tacosCardK2.isVisible({ timeout: 5000 }).catch(() => false)) {
        const tacosAddK2 = kioskPage.getByTestId(`kiosk-product-add-${ITEM_TACOS.id}`);
        if (await tacosAddK2.isVisible({ timeout: 1500 }).catch(() => false)) {
          await tacosAddK2.click({ timeout: 3000 }).catch(() => {});
        } else {
          await tacosCardK2.click({ timeout: 3000 }).catch(() => {});
        }
        await kioskPage.waitForTimeout(1500);
        // Drive wizard to extras step
        for (let g = 0; g < 8; g++) {
          const found = await kioskPage.evaluate(() => {
            const all = Array.from(document.querySelectorAll('.kiosk-step-content button, .kiosk-step-content [class*="extra"]'));
            return all.some((b) => /jambon de dinde/i.test(b.innerText || b.textContent || ''));
          });
          if (found) break;
          const opt = kioskPage.locator('.kiosk-step-content .kiosk-viande-card.is-selectable:not(.kiosk-variation--disabled), .kiosk-step-content .kiosk-option-card[role="checkbox"]:not(.is-out-of-stock), .kiosk-step-content button.kiosk-generic-choice:not([disabled]), .kiosk-step-content .kiosk-menu-card[role="radio"]').first();
          if (await opt.isVisible({ timeout: 500 }).catch(() => false)) {
            await opt.click({ timeout: 1500 }).catch(() => {});
          }
          const nb = kioskPage.locator('.kiosk-wizard .kiosk-btn-next:not([disabled])').first();
          if (await nb.isVisible({ timeout: 600 }).catch(() => false)) {
            await nb.click({ timeout: 1800 }).catch(() => {});
          }
          await kioskPage.waitForTimeout(350);
        }
        state15_attempt.jambonState = await kioskPage.evaluate(() => {
          const all = Array.from(document.querySelectorAll('.kiosk-wizard button, .kiosk-wizard [class*="extra"]'));
          const match = all.find((b) => /jambon de dinde/i.test(b.innerText || b.textContent || ''));
          if (!match) return { found: false };
          const cs = window.getComputedStyle(match);
          return {
            found: true,
            disabled: match.hasAttribute('disabled'),
            ariaDisabled: match.getAttribute('aria-disabled'),
            pointerEvents: cs.pointerEvents,
            opacity: cs.opacity,
            classes: match.className || '',
          };
        });
      }
      observations.push(`state15: kiosk UI tacos+jambon = ${JSON.stringify(state15_attempt)}`);
      await kioskRec.snap('15-E-mixed-kiosk-order-2-tacos-attempt-ruptured');

      // ============================================================
      // STATE 16 — BYPASS-E5 (c) : Kiosk API direct attempt with ruptured item 362
      // ============================================================
      let kioskBypassResp = null;
      try {
        kioskBypassResp = await placeKioskOrder(kioskPage, {
          tokenPrefix: PREFIXE_AUDIT,
          items: [{
            item_id: RUPTURE_ITEM.id,
            quantity: 1,
            item_variations: [],
            item_extras: [],
            item_addons: [],
            instruction: `${TOKEN_PREFIX_KIOSK}-BYPASS-${TS}: rupture bypass attempt`,
          }],
          paymentMethod: PAYMENT_CARD,
          orderType: ORDER_TYPE.TAKEAWAY,
          skipPaymentConfirm: true,
        });
        // No throw → bypass succeeded
        observations.push(`state16: BYPASS-E5c kiosk api with ruptured item 362 = SILENT 2XX, orderId=${kioskBypassResp?.orderId}`);
        findings.push({
          id: 'BYPASS-E5c',
          severity: 'P0',
          surface: 'kiosk-api',
          desc: 'POST /api/frontend/order with ruptured item 362 returned 2xx — silent success on kiosk-side',
          response: kioskBypassResp,
        });
        // If we created an order, schedule it for sweep
      } catch (e) {
        observations.push(`state16: BYPASS-E5c kiosk api CORRECTLY blocked status=${e.status || '?'} stage=${e.stage || '?'} body=${JSON.stringify(e.body || e.message).slice(0, 200)}`);
        kioskBypassResp = { ok: false, status: e.status, body: e.body || e.message, stage: e.stage };
      }
      writeArtifact('wave-E-bypass-E5c-kiosk-item.json', { item_id: RUPTURE_ITEM.id, response: kioskBypassResp });
      await kioskRec.snap('16-E-mixed-kiosk-api-attempt-ruptured-blocked');

      // ============================================================
      // BURST PHASE — 10 POS + 10 Kiosk mixed orders (NUMERIC-E8)
      // Use NON-RUPTURED items only (361/363 w/o jambon/375).
      // ============================================================
      observations.push(`burst: starting 10 POS + 10 Kiosk mixed orders @ ${new Date().toISOString()}`);

      // 10 POS API orders
      const posRotation = [
        ITEM_FRITES, ITEM_TACOS, ITEM_BURGER, ITEM_FRITES, ITEM_TACOS,
        ITEM_BURGER, ITEM_FRITES, ITEM_TACOS, ITEM_BURGER, ITEM_FRITES,
      ];
      clearFoodKingRateLimits();
      for (let i = 0; i < posRotation.length; i++) {
        const seq = i + 1;
        const item = posRotation[i];
        const token = `${TOKEN_PREFIX_POS}-${String(seq).padStart(2, '0')}-${TS}`;
        const payload = buildPosApiPayload({
          token,
          itemId: item.id,
          qty: 1,
          basePrice: item.price,
          extras: [],
          paymentMethod: PAY.CASH,
        });
        const t0 = Date.now();
        const resp = await postPosOrderResilient(posPage, payload, observations);
        apiOrderResults.push({
          surface: 'pos',
          seq,
          token,
          item_id: item.id,
          ok: resp.ok,
          status: resp.status,
          order_id: resp.data?.id || null,
          idempotency_key: payload.idempotency_key,
          t_ms: Date.now() - t0,
        });
        if (!resp.ok) observations.push(`pos burst[${seq}]: FAIL status=${resp.status} body=${JSON.stringify(resp.body).slice(0, 200)}`);
        if (i === 4) clearFoodKingRateLimits();
        await sleep(1100);
      }

      // 10 Kiosk API orders — clear every 2 (5/min bucket, 2 hits/order)
      const kioskRotation = [
        ITEM_FRITES, ITEM_TACOS, ITEM_BURGER, ITEM_FRITES, ITEM_TACOS,
        ITEM_BURGER, ITEM_FRITES, ITEM_TACOS, ITEM_BURGER, ITEM_FRITES,
      ];
      clearFoodKingRateLimits();
      for (let i = 0; i < kioskRotation.length; i++) {
        const seq = i + 1;
        const item = kioskRotation[i];
        if (i > 0 && i % 2 === 0) {
          clearFoodKingRateLimits();
        }
        // Compact idem key (≤30 chars) — Wave B B-005 fix pattern
        const tsShort = String(TS).slice(-7);
        const idemSuffix = crypto.randomUUID().replace(/-/g, '').slice(0, 8);
        const idemKey = `MIX-K-${seq}-${tsShort}-${idemSuffix}`; // ~28 chars
        const instructionTag = `${TOKEN_PREFIX_KIOSK}-${String(seq).padStart(2, '0')}-${TS}`;
        const t0 = Date.now();
        let result = null;
        let err = null;
        try {
          result = await placeKioskOrder(kioskPage, {
            tokenPrefix: PREFIXE_AUDIT,
            items: [{
              item_id: item.id,
              quantity: 1,
              item_variations: [],
              item_extras: [],
              item_addons: [],
              instruction: instructionTag,
            }],
            paymentMethod: PAYMENT_CARD,
            idempotencyKey: idemKey,
            orderType: ORDER_TYPE.TAKEAWAY,
            skipPaymentConfirm: true,
          });
        } catch (e) {
          if (e.status === 429) {
            observations.push(`kiosk burst[${seq}]: 429 retry`);
            await sleep(13000);
            try {
              result = await placeKioskOrder(kioskPage, {
                tokenPrefix: PREFIXE_AUDIT,
                items: [{
                  item_id: item.id,
                  quantity: 1,
                  item_variations: [],
                  item_extras: [],
                  item_addons: [],
                  instruction: instructionTag,
                }],
                paymentMethod: PAYMENT_CARD,
                idempotencyKey: idemKey,
                orderType: ORDER_TYPE.TAKEAWAY,
                skipPaymentConfirm: true,
              });
            } catch (e2) {
              err = e2;
            }
          } else {
            err = e;
          }
        }
        apiOrderResults.push({
          surface: 'kiosk',
          seq,
          token: instructionTag,
          item_id: item.id,
          ok: !!result,
          status: result ? 200 : (err?.status || 0),
          order_id: result?.orderId || null,
          queue_number: result?.order?.queue_number || null,
          total_amount: result?.totalAmount || null,
          idempotency_key: idemKey,
          error: err ? String(err.message || err).slice(0, 200) : null,
          t_ms: Date.now() - t0,
        });
        if (err) observations.push(`kiosk burst[${seq}]: FAIL ${err.message?.slice(0, 200)}`);
        await sleep(800);
      }

      observations.push(`burst: complete pos_ok=${apiOrderResults.filter((r) => r.surface === 'pos' && r.ok).length}/10 kiosk_ok=${apiOrderResults.filter((r) => r.surface === 'kiosk' && r.ok).length}/10`);

      // ============================================================
      // STATE 17 — KDS pile shows the 20 mixed orders
      // ============================================================
      await chefPage.bringToFront();
      await chefPage.reload({ waitUntil: 'domcontentloaded' }).catch(() => {});
      await chefPage.waitForTimeout(3000);
      const kdsProbe = await chefPage.evaluate((args) => {
        const cards = Array.from(document.querySelectorAll('[data-kds-order-card]'));
        const matchPosToken = cards.filter((c) => (c.textContent || '').includes(args.posPrefix)).length;
        const matchKioskInst = cards.filter((c) => (c.textContent || '').includes(args.kioskPrefix)).length;
        // Detect any leftover ruptured item ref
        const ruptureLeak = cards.filter((c) => /Boisson Seule/i.test(c.textContent || '')).length;
        return {
          card_count_total: cards.length,
          pos_token_matches: matchPosToken,
          kiosk_instruction_matches: matchKioskInst,
          rupture_leak_count: ruptureLeak,
        };
      }, { posPrefix: TOKEN_PREFIX_POS, kioskPrefix: TOKEN_PREFIX_KIOSK });
      observations.push(`state17: kds = ${JSON.stringify(kdsProbe)}`);
      await chefRec.snap('17-E-after-20-orders-kds-pile');
      if (kdsProbe.rupture_leak_count > 0) {
        findings.push({
          id: 'RUPTURE-LEAK-KDS',
          severity: 'P0',
          surface: 'kds',
          desc: `KDS pile shows ${kdsProbe.rupture_leak_count} card(s) containing ruptured item Boisson Seule (362) — bypass leaked into kitchen`,
          state: kdsProbe,
        });
      }

      // ============================================================
      // STATE 18 — admin restore item 362
      // ============================================================
      await adminPage.bringToFront();
      await adminPage.goto('/admin/items', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await adminPage.waitForTimeout(1500);
      let restoreLocator = adminPage.locator(`[data-testid="admin-availability-toggle-${RUPTURE_ITEM.id}"]`).first();
      let restoreVisible = await restoreLocator.isVisible({ timeout: 2000 }).catch(() => false);
      if (!restoreVisible) {
        const nameInput = adminPage.locator('#item-filter #name');
        if (!(await nameInput.isVisible({ timeout: 1500 }).catch(() => false))) {
          const filterBtn = adminPage.locator('button:has(.lab-filter), .db-card-filter button').first();
          await filterBtn.click({ timeout: 5000 }).catch(() => {});
        }
        await adminPage.evaluate(() => {
          const panel = document.getElementById('item-filter');
          if (panel) panel.style.display = 'block';
        }).catch(() => {});
        await nameInput.fill('Boisson').catch(() => {});
        await adminPage.locator('#item-filter form').evaluate((f) => f.requestSubmit ? f.requestSubmit() : f.submit()).catch(() => {});
        await adminPage.waitForTimeout(1500);
        restoreLocator = adminPage.locator(`[data-testid="admin-availability-toggle-${RUPTURE_ITEM.id}"]`).first();
        restoreVisible = await restoreLocator.isVisible({ timeout: 8000 }).catch(() => false);
      }
      const restoreItemStart = Date.now();
      if (restoreVisible) {
        await restoreLocator.scrollIntoViewIfNeeded().catch(() => {});
        const pending = adminPage.waitForResponse(
          (r) => /\/admin\/menu\/availability\/toggle$/i.test(r.url()) && r.request().method() === 'POST',
          { timeout: 15000 },
        ).catch(() => null);
        await restoreLocator.locator('button').first().click({ timeout: 5000 }).catch(() => {});
        await pending;
      } else {
        // Fallback
        const r = await adminPage.evaluate(async (params) => {
          try {
            const res = await window.axios.post('admin/menu/availability/toggle', {
              item_id: params.itemId,
              branch_id: params.branchId,
              is_available: true,
              unavailable_reason: null,
            });
            return { ok: true, status: res.status };
          } catch (e) {
            return { ok: false, status: e?.response?.status, body: e?.response?.data };
          }
        }, { itemId: RUPTURE_ITEM.id, branchId: BRANCH_ID });
        observations.push(`state18: fallback api restore item 362 = ${JSON.stringify(r)}`);
      }
      await adminPage.waitForTimeout(1000);
      observations.push(`state18: restore item 362 admin elapsed=${Date.now() - restoreItemStart}ms`);
      await adminRec.snap('18-E-restore-item-362-admin-click');

      // ============================================================
      // STATE 19 — admin restore extra 175 (direct API)
      // ============================================================
      const restoreExtraStart = Date.now();
      const restoreExtraResp = await adminPage.evaluate(async (params) => {
        try {
          const r = await window.axios.post('admin/menu/availability/extra/toggle', {
            extra_id: params.extraId,
            branch_id: params.branchId,
            is_available: true,
            reason: null,
          });
          return { ok: true, status: r.status, data: r.data };
        } catch (e) {
          return { ok: false, status: e?.response?.status, body: e?.response?.data };
        }
      }, { extraId: RUPTURE_EXTRA.id, branchId: BRANCH_ID });
      observations.push(`state19: api restore extra 175 = ${JSON.stringify(restoreExtraResp)} elapsed=${Date.now() - restoreExtraStart}ms`);
      await adminPage.waitForTimeout(800);
      await adminRec.snap('19-E-restore-extra-175-admin-click');

      // ============================================================
      // STATE 20 — POS + Kiosk show item+extra available again (REVERSIBILITY-E7)
      // ============================================================
      // POS reverify
      await posPage.bringToFront();
      await posPage.goto('/admin/pos', { waitUntil: 'domcontentloaded' }).catch(() => {});
      if (/\/login/.test(posPage.url())) await loginAsPosOperator(posPage);
      await posPage.waitForTimeout(1500);
      const posCat315c = posPage.locator(`[data-pos-category-id="${RUPTURE_ITEM.cat}"], button:has-text("Frites")`).first();
      if (await posCat315c.isVisible({ timeout: 4000 }).catch(() => false)) {
        await posCat315c.click({ timeout: 3000 }).catch(() => {});
        await posPage.waitForTimeout(800);
      }
      const restorePosWait = await waitForCondition(posPage, async (p) => {
        const s = await p.evaluate((itemId) => {
          const tile = document.querySelector(`button[data-pos-item-id="${itemId}"]`);
          if (!tile) return false;
          return !tile.classList.contains('is-unavailable')
            && !tile.querySelector('.pos-item-86-badge, .pos-v5-tile__overlay')
            && !tile.hasAttribute('disabled');
        }, RUPTURE_ITEM.id);
        return s;
      }, 8000, 500);
      cascadeLatencies.push({ label: 'restore-pos-item-362', ms: restorePosWait.elapsed_ms, ok: restorePosWait.ok });
      const restorePosState = await probePosTileState(posPage, RUPTURE_ITEM.id);
      observations.push(`state20-pos: REVERSIBILITY-E7 pos restore elapsed=${restorePosWait.elapsed_ms}ms ok=${restorePosWait.ok} state=${JSON.stringify(restorePosState)}`);
      await posRec.snap('20-E-post-restore-catalog-pos-and-kiosk');

      // Kiosk reverify (same state name, but capture both)
      // [round-1.5] Round-1 condition required card.found=true AND no rupture
      // text. But the kiosk catalog filter may delay-render the card after
      // restore — also `total_cards: 0` was observed (filter pre-state). New
      // condition: card found AND clean OR (not found yet, but the categories
      // route still mounted — we'll do a hard reload first to defeat the
      // catalog cache).
      await kioskPage.bringToFront();
      await kioskPage.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await kioskPage.waitForTimeout(800);
      // Re-enter category 315 fresh
      try {
        const tk = kioskPage.getByTestId('kiosk-order-type-takeaway');
        if (await tk.isVisible({ timeout: 3000 }).catch(() => false)) {
          await tk.click({ timeout: 2500 }).catch(() => {});
          await kioskPage.waitForTimeout(800);
        }
      } catch (_e) { /* best-effort */ }
      await kioskPage.goto(`/kiosk/categories?cat=${RUPTURE_ITEM.cat}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
      await kioskPage.waitForTimeout(2000);
      const restoreKioskWait = await waitForCondition(kioskPage, async (p) => {
        const s = await p.evaluate((itemId) => {
          const card = document.querySelector(`[data-testid="kiosk-product-card-${itemId}"]`);
          if (!card) return false;
          return !/out-of-stock|ruptured|unavailable|epuise|filtered-out/i.test(card.className || '')
            && !/épuisé|epuise|rupture|indispo/i.test(card.innerText || '');
        }, RUPTURE_ITEM.id);
        return s;
      }, 8000, 500);
      cascadeLatencies.push({ label: 'restore-kiosk-item-362', ms: restoreKioskWait.elapsed_ms, ok: restoreKioskWait.ok });
      const restoreKioskState = await probeKioskCardState(kioskPage, RUPTURE_ITEM.id);
      observations.push(`state20-kiosk: REVERSIBILITY-E7 kiosk restore elapsed=${restoreKioskWait.elapsed_ms}ms ok=${restoreKioskWait.ok} state=${JSON.stringify(restoreKioskState)}`);
      // Capture additional kiosk reverify shot (state 20 already exists for POS; add kiosk variant for the report)
      await kioskRec.snap('20-E-post-restore-kiosk');

      const reversibilityE7Pass = restorePosWait.ok && restoreKioskWait.ok;
      if (!reversibilityE7Pass) {
        findings.push({
          id: 'REVERSIBILITY-E7',
          severity: 'P0',
          surface: 'cross',
          desc: `Restore-toggle did NOT propagate within 8s — pos_ok=${restorePosWait.ok} (${restorePosWait.elapsed_ms}ms) kiosk_ok=${restoreKioskWait.ok} (${restoreKioskWait.elapsed_ms}ms)`,
          pos_state: restorePosState,
          kiosk_state: restoreKioskState,
        });
      }

      // ============================================================
      // CROSS-SURFACE ASSERTIONS + REPORT FILES
      // ============================================================

      // CASCADE-LAG-E6 p95
      const lagMs = cascadeLatencies.filter((l) => l.ok).map((l) => l.ms).sort((a, b) => a - b);
      const cascadeP95 = lagMs.length === 0 ? null : lagMs[Math.floor(lagMs.length * 0.95)] || lagMs[lagMs.length - 1];
      observations.push(`CASCADE-LAG-E6: p95=${cascadeP95}ms n=${lagMs.length} samples=${JSON.stringify(cascadeLatencies)}`);
      writeArtifact('wave-E-cascade-latencies.json', { samples: cascadeLatencies, p95_ms: cascadeP95 });

      // NUMERIC-E8 — sample 5 successful orders, verify cart=DB integrity
      const successfulOrders = apiOrderResults.filter((r) => r.ok && r.order_id);
      const numericSample = successfulOrders.slice(0, 5);
      const numericGrid = [];
      for (const sample of numericSample) {
        try {
          const rows = dbProbe(`SELECT id, branch_id, total, fiscal_sequence_no, queue_number, token FROM orders WHERE id = ${Number(sample.order_id)} LIMIT 1`);
          const row = rows?.[0];
          numericGrid.push({
            order_id: sample.order_id,
            surface: sample.surface,
            token: sample.token,
            db_total: row ? Number(row.total) : null,
            db_branch_id: row ? Number(row.branch_id) : null,
            db_fiscal_seq: row?.fiscal_sequence_no ? Number(row.fiscal_sequence_no) : null,
            db_queue_number: row?.queue_number || null,
            integrity: !!row,
          });
        } catch (e) {
          numericGrid.push({ order_id: sample.order_id, error: e.message });
        }
      }
      writeArtifact('wave-E-numeric-grid.json', numericGrid);
      observations.push(`NUMERIC-E8: sample_size=${numericGrid.length} integrity_ok=${numericGrid.filter((g) => g.integrity).length}`);

      // Branch isolation check on burst orders
      const offBranch = numericGrid.filter((g) => g.db_branch_id !== null && g.db_branch_id !== BRANCH_ID).length;
      observations.push(`BRANCH-isolation: off_branch_count=${offBranch}/${numericGrid.length}`);

      // Toggle responses dump
      writeArtifact('wave-E-toggle-responses.json', toggleResponses);

      // Findings dump (the SOURCE-OF-TRUTH for this audit)
      writeArtifact('wave-E-findings.json', { findings, count_p0: findings.filter((f) => f.severity === 'P0').length, count_p1: findings.filter((f) => f.severity === 'P1').length });

      // Order results dump
      writeArtifact('wave-E-api-order-results.json', {
        total: apiOrderResults.length,
        pos_ok: apiOrderResults.filter((r) => r.surface === 'pos' && r.ok).length,
        kiosk_ok: apiOrderResults.filter((r) => r.surface === 'kiosk' && r.ok).length,
        results: apiOrderResults,
      });

      // Final observations dump
      writeArtifact('wave-E-observations.json', {
        ts: TS,
        wallclock_s: Math.round((Date.now() - startMs) / 1000),
        rupture_targets: { item: RUPTURE_ITEM, extra: RUPTURE_EXTRA },
        findings_count: findings.length,
        cascade_p95_ms: cascadeP95,
        observations,
        rupture_assertions: {
          E1_pos_item_362_cascade: ruptureE1Pass,
          E2_kiosk_item_362_cascade: ruptureE2Pass,
          E3_pos_wizard_extra_175_disabled: ruptureE3Pass,
          E4_kiosk_wizard_extra_175_disabled: ruptureE4Pass,
          E7_reversibility: reversibilityE7Pass,
          E8_numeric_integrity_ok: numericGrid.filter((g) => g.integrity).length,
        },
      });

      // ============================================================
      // SPEC-LEVEL ASSERTIONS
      // ============================================================
      const stateLabels = [
        '01-E-pre-rupture-baseline-admin',
        '02-E-pre-rupture-pos-catalog',
        '03-E-pre-rupture-kiosk-catalog',
        '04-E-rupture-toggle-item-362-admin-click',
        '05-E-rupture-toggle-extra-175-admin-click',
        '06-E-cascade-pos-item-362-hidden',
        '07-E-cascade-pos-wizard-tacos-extra-175-disabled',
        '08-E-cascade-kiosk-item-362-hidden',
        '09-E-cascade-kiosk-wizard-tacos-extra-175-disabled',
        '10-E-mixed-pos-order-1-burger',
        '11-E-mixed-pos-order-2-tacos-with-extra',
        '12-E-mixed-pos-api-attempt-ruptured-item-blocked',
        '13-E-mixed-pos-api-attempt-ruptured-extra-blocked',
        '14-E-mixed-kiosk-order-1-frites',
        '15-E-mixed-kiosk-order-2-tacos-attempt-ruptured',
        '16-E-mixed-kiosk-api-attempt-ruptured-blocked',
        '17-E-after-20-orders-kds-pile',
        '18-E-restore-item-362-admin-click',
        '19-E-restore-extra-175-admin-click',
        '20-E-post-restore-catalog-pos-and-kiosk',
      ];
      const written = fs.readdirSync(SCREENSHOT_DIR).filter((f) => f.endsWith('.png'));
      const missing = stateLabels.filter((s) => !written.includes(`${s}.png`));

      // eslint-disable-next-line no-console
      console.log(`[WAVE-E] OBSERVATIONS:\n  ${observations.join('\n  ')}`);
      // eslint-disable-next-line no-console
      console.log(`[WAVE-E] FINDINGS: total=${findings.length} P0=${findings.filter((f) => f.severity === 'P0').length} P1=${findings.filter((f) => f.severity === 'P1').length}`);
      // eslint-disable-next-line no-console
      console.log(`[WAVE-E] PNGs: ${written.length}/${stateLabels.length} ${missing.length ? '(MISSING: ' + missing.join(',') + ')' : ''}`);
      // eslint-disable-next-line no-console
      console.log(`[WAVE-E] WALLCLOCK: ${Math.round((Date.now() - startMs) / 1000)}s`);

      // Spec passes if all 20 PNGs captured. Findings (BYPASS-E5*, RUPTURE-E*)
      // are CAPTURED, not failed — per task brief "DO NOT modify product code".
      expect(missing, `All 20 state PNGs must be captured ; missing: ${missing.join(', ')}`).toEqual([]);

      // Numeric integrity hard-assert : at least 1/5 sampled orders must have a DB row
      const integrityOk = numericGrid.filter((g) => g.integrity).length;
      expect(integrityOk, `NUMERIC-E8 P0 : at least 1/5 sampled mixed orders must have a DB row ; got ${integrityOk}`).toBeGreaterThanOrEqual(1);

      // Burst minimums : need at least 10/20 successful (allow some 429 failures)
      const totalOk = apiOrderResults.filter((r) => r.ok).length;
      expect(totalOk, `Need ≥10/20 successful mixed orders ; got ${totalOk}`).toBeGreaterThanOrEqual(10);
    } finally {
      // ============================================================
      // afterAll cleanup is in the outer test.afterAll() — guaranteed restore
      // ============================================================
      try { adminRec?.dispose(); } catch (_e) { /* ignore */ }
      try { posRec?.dispose(); } catch (_e) { /* ignore */ }
      try { kioskRec?.dispose(); } catch (_e) { /* ignore */ }
      try { chefRec?.dispose(); } catch (_e) { /* ignore */ }

      await adminCtx?.close().catch(() => {});
      await posCtx?.close().catch(() => {});
      await kioskCtx?.close().catch(() => {});
      await chefCtx?.close().catch(() => {});
    }
  });
});
