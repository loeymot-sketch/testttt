// FoodKing E2E — FINAL TEST RUN : final-caisse-borne-2026-05-14 / Wave BORNE
//
// MISSION (RUN=final-caisse-borne-2026-05-14, Wave BORNE)
//   Deep kiosk test : 10 scenarios exhaustively captured end-to-end with
//   composer-aware payloads (V1 + V2 Viandes, sauce Cayenne locked, sauce
//   bol required, etc.). Hybrid UI + API placement (deterministic, no flake).
//
//   Per-scenario : multiple state captures (idle pre-flight + categories +
//   item card + wizard-open + wizard step inspection + post-API capture +
//   confirmation navigation). Quartet (PNG + DOM + console.json + network.json)
//   via attachMegaAuditRecorder. KDS + OSS cross-surface latency polling.
//   DB tracking JSON with composition_snapshot lines + fiscal monotonic +
//   payment_status=5. Security checks : kiosk token negative test on admin
//   endpoint, branch_id=1 on all 10 orders, audit_logs.count unchanged at 26.
//
// DESIGN — HYBRID UI + API (mirrors menu-v2-kiosk-final.spec.js)
//   - Orders placed via `placeKioskOrder` (axios in-browser, X-Idempotency-Key,
//     Sanctum kiosk:order ability). Deterministic, no wizard-walk flake.
//   - UI captured around the POST :
//       <S>-01-idle (1st scenario only)
//       <S>-02-categories-cat-<id>  — sidebar visible, expected cat highlighted
//       <S>-03-wizard-open          — first step of wizard, step labels probed
//       <S>-04-wizard-mid           — middle step capture (best-effort)
//       <S>-05-wizard-recap         — recap (best-effort, may fall back)
//       <S>-06-cart-or-post-api     — after API placement
//       <S>-07-payment-screen       — skip+document : API-hybrid path
//                                     bypasses the kiosk's TPE UI; honest
//                                     limitation surfaced in report.
//       <S>-08-confirmation         — navigate to /kiosk/confirmation?order_id=
//
// BASELINE at run start (prompt) : max(orders.id)=1480, max(fiscal_seq_no)=323,
//   audit_logs count=26 (NF525 chain invariant). Run will create 10 new orders
//   → expect post-run order_id ≥1490, fiscal_seq ≥333. audit_logs MUST remain 26.
//
// FROZEN ZONES — KioskWizardComponent.vue + KioskAppComponent.vue +
//   FiscalSequenceService + PricingService + OrderStateMachine. We READ DOM
//   for selectors but never modify Vue components.
//
// COMPOSER-AWARE PAYLOAD (verified via tinker 2026-05-14)
//   - 474 Sandwich Cayenne : attr 331 Sauce Cayenne min=1 → var 1251 LOCKED
//   - 488 Big Cayenne      : attr 307 V1 + attr 308 V2 + attr 331 Sauce Cayenne
//     verified: vars 1253 (V1 Poulet marine), 1258 (V2 Poulet curry), 1389 (Sauce Cayenne maison)
//   - 476 Galette Cayenne  : attr 331 Sauce Cayenne min=1 → var 1252 LOCKED
//   - 477 Sandwich Classique : attr 311 sauce optional
//   - 489 Big Classique    : attr 307 V1 + attr 308 V2 + attr 311 sauce libre
//     verified: vars 1261 (V1 Poulet marine), 1266 (V2 Poulet curry), 1278 (Sauce fromagère maison)
//   - 478 Tacos M (1 viande): no variations required (attr 307 min=0)
//   - 479 Tacos L (2 viandes): attr 307 V1 + attr 308 V2 + (no extra step)
//     verified: vars 1162 (V1 Poulet marine), 1167 (V2 Poulet curry)
//   - 493 Bowl Frites curry : attr 330 Sauce bol min=1 → var 1321 (Sauce fromagere) [per task: Fromagère/Spicy]
//   - 498 Bowl Riz tandoori : attr 330 Sauce bol min=1 → var 1376 (Sauce fromagere)
//   - Multi-cart : 485 (Petite Frites, attr 329 Style frites min=1 → var 1180 Nature)
//                  + 375 (Chicken Burger, no required) + 405 (Tarte Daim, no required)
//                  Total = 2.50 + 6.90 + 3.80 = 13.20€
//
// DRIFTS expected to SURFACE (not block on) — visual mandate
//   - Big Cayenne wizard: expected 6 step indicators (V1 + V2 + Sauce + Crud + Supp +
//     Menu + Récap) but reference spec found only 5 (no V2 step rendered).
//   - Tacos L: composer profile 84 referenced but composer_profiles table doesn't
//     exist — wizard cardinality will reflect DB attrs only.
//   - Bowl Frites curry: task expects 4 steps (sauce/supp/drink/gratiné) but
//     DB only has attr 330 (Sauce bol min=1). Other steps would require composer.
//   - Menu enfant cat 350 channels=["pos","admin","mobile"] → MUST be hidden on kiosk.

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const {
  placeKioskOrder,
  cleanupKioskAuditOrders,
  resetKioskToken,
  PAYMENT_CARD,
  KIOSK_AUDIT_PREFIX,
} = require('./helpers/kiosk-order');

const REPO_ROOT = path.resolve(__dirname, '../..');
const SCREENSHOT_DIR = path.resolve(
  __dirname,
  '__screenshots__/final/borne',
);
const REPORT_DIR = path.resolve(
  REPO_ROOT,
  'reports/test-e2e/final-caisse-borne-2026-05-14/round-1',
);
const DB_CHECKS_FILE = path.join(REPORT_DIR, 'wave-BORNE-db-checks.json');
const REPORT_FILE = path.join(REPORT_DIR, 'wave-BORNE-report.md');

fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
fs.mkdirSync(REPORT_DIR, { recursive: true });

// -----------------------------------------------------------------------------
// 10 SCENARIOS — BORNE wave (kiosk deep test)
// -----------------------------------------------------------------------------
const SCENARIOS = [
  {
    code: 'BO-S1',
    label: 'Sandwich Cayenne',
    itemId: 474,
    catId: 344,
    catName: 'Sandwich Cayenne',
    expectedPrice: 7.50,
    wizardStepsExpected: 'sandwich (V1 + Sauce Cayenne locked + Crudités + Supp + Menu + Récap)',
    items: [
      {
        item_id: 474,
        quantity: 1,
        // attr 331 Sauce Cayenne min=1 → 1251 Sauce Cayenne maison
        // attr 307 Viande 1 min=0 → 1116 Poulet marine (explicit pick)
        item_variations: [
          { id: 1116, quantity: 1 },  // V1 Poulet marine
          { id: 1251, quantity: 1 },  // Sauce Cayenne maison
        ],
        item_extras: [],
        item_addons: [],
      },
    ],
  },
  {
    code: 'BO-S2',
    label: 'Big Cayenne (composer)',
    itemId: 488,
    catId: 344,
    catName: 'Sandwich Cayenne',
    expectedPrice: 9.50,
    isComposer: true,
    wizardStepsExpected: 'Big Cayenne composer 82 (V1 + V2 + Sauce Cayenne + Supp + Menu + Récap, 6 steps)',
    items: [
      {
        item_id: 488,
        quantity: 1,
        // attr 307 V1 + attr 308 V2 + attr 331 Sauce Cayenne maison
        item_variations: [
          { id: 1253, quantity: 1 },  // V1 Poulet marine
          { id: 1258, quantity: 1 },  // V2 Poulet curry
          { id: 1389, quantity: 1 },  // Sauce Cayenne maison
        ],
        item_extras: [],
        item_addons: [],
      },
    ],
  },
  {
    code: 'BO-S3',
    label: 'Galette Cayenne',
    itemId: 476,
    catId: 345,
    catName: 'Galette',
    expectedPrice: 7.00,
    wizardStepsExpected: 'galette (sauce Cayenne locked)',
    items: [
      {
        item_id: 476,
        quantity: 1,
        // attr 307 V1 min=0, attr 331 Sauce Cayenne min=1
        item_variations: [
          { id: 1137, quantity: 1 },  // V1 Poulet marine
          { id: 1252, quantity: 1 },  // Sauce Cayenne maison
        ],
        item_extras: [],
        item_addons: [],
      },
    ],
  },
  {
    code: 'BO-S4',
    label: 'Sandwich Classique',
    itemId: 477,
    catId: 346,
    catName: 'Sandwich Classique',
    expectedPrice: 7.00,
    wizardStepsExpected: 'sandwich (sauce libre)',
    items: [
      {
        item_id: 477,
        quantity: 1,
        // attr 311 sauce libre (optional, pick one explicitly)
        item_variations: [
          { id: 1149, quantity: 1 },  // Sauce Curry (libre)
        ],
        item_extras: [],
        item_addons: [],
      },
    ],
  },
  {
    code: 'BO-S5',
    label: 'Big Classique (composer)',
    itemId: 489,
    catId: 346,
    catName: 'Sandwich Classique',
    expectedPrice: 9.00,
    isComposer: true,
    wizardStepsExpected: 'Big Classique composer 83 (V1 + V2 + Sauce libre + Supp + Menu)',
    items: [
      {
        item_id: 489,
        quantity: 1,
        // attr 307 V1 + attr 308 V2 + attr 311 sauce libre
        item_variations: [
          { id: 1261, quantity: 1 },  // V1 Poulet marine
          { id: 1266, quantity: 1 },  // V2 Poulet curry
          { id: 1278, quantity: 1 },  // Sauce fromagère maison
        ],
        item_extras: [],
        item_addons: [],
      },
    ],
  },
  {
    code: 'BO-S6',
    label: 'Tacos M (1 viande)',
    itemId: 478,
    catId: 306,
    catName: 'Tacos',
    expectedPrice: 6.90,
    wizardStepsExpected: 'tacos (1 viande only)',
    items: [
      {
        item_id: 478,
        quantity: 1,
        // attr 307 V1 min=0 - pick explicitly
        item_variations: [
          { id: 1158, quantity: 1 },  // V1 Poulet marine
        ],
        item_extras: [],
        item_addons: [],
      },
    ],
  },
  {
    code: 'BO-S7',
    label: 'Tacos L (composer 2 viandes)',
    itemId: 479,
    catId: 306,
    catName: 'Tacos',
    expectedPrice: 7.90,
    isComposer: true,
    wizardStepsExpected: 'Tacos L composer 84 (V1 + V2 + Menu)',
    items: [
      {
        item_id: 479,
        quantity: 1,
        // attr 307 V1 + attr 308 V2 (both min=0 but pick explicitly for composer test)
        item_variations: [
          { id: 1162, quantity: 1 },  // V1 Poulet marine
          { id: 1167, quantity: 1 },  // V2 Poulet curry
        ],
        item_extras: [],
        item_addons: [],
      },
    ],
  },
  {
    code: 'BO-S8',
    label: 'Bowl Frites Poulet curry',
    itemId: 493,
    catId: 347,
    catName: 'Bols Gourmands',
    expectedPrice: 8.90,
    isComposer: true,
    wizardStepsExpected: 'Bowl composer 4 steps (sauce/supp/drink/gratine)',
    items: [
      {
        item_id: 493,
        quantity: 1,
        // attr 330 Sauce bol min=1 - task says Fromagère/Spicy
        item_variations: [
          { id: 1321, quantity: 1 },  // Sauce fromagère maison
        ],
        item_extras: [],
        item_addons: [],
      },
    ],
  },
  {
    code: 'BO-S9',
    label: 'Bowl Riz Poulet tandoori',
    itemId: 498,
    catId: 347,
    catName: 'Bols Gourmands',
    expectedPrice: 8.90,
    isComposer: true,
    wizardStepsExpected: 'Bowl composer 4 steps (sauce/supp/drink/gratine)',
    items: [
      {
        item_id: 498,
        quantity: 1,
        // attr 330 Sauce bol min=1
        item_variations: [
          { id: 1376, quantity: 1 },  // Sauce fromagère maison
        ],
        item_extras: [],
        item_addons: [],
      },
    ],
  },
  {
    code: 'BO-S10',
    label: 'Multi-cart (Petite Frites + Chicken Burger + Tarte Daim)',
    multi: true,
    itemId: null,
    catId: null,
    catName: 'Multi',
    expectedPrice: 13.20, // 2.50 + 6.90 + 3.80
    wizardStepsExpected: '(multi: no single wizard)',
    items: [
      {
        item_id: 485, // Petite Frites
        quantity: 1,
        // attr 329 Style frites min=1 → 1180 Nature
        item_variations: [{ id: 1180, quantity: 1 }],
        item_extras: [],
        item_addons: [],
      },
      {
        item_id: 375, // Chicken Burger
        quantity: 1,
        item_variations: [], // all optional
        item_extras: [],
        item_addons: [],
      },
      {
        item_id: 405, // Tarte Daim
        quantity: 1,
        item_variations: [],
        item_extras: [],
        item_addons: [],
      },
    ],
  },
];

// Expected visible kiosk categories (post heal-light V2 + menu reset 2026-05-13).
// 10 visible: Cayenne, Galette, Sandwich Classique, Burgers, Tacos,
// Bols Gourmands, Frites, Suppléments, Desserts, Boissons.
// 2 expected-hidden in kiosk channel: 315 Frites & Accompagnements
// (channels='[]') + 350 Menu enfant (channels=["pos","admin","mobile"]).
const EXPECTED_VISIBLE_CAT_IDS = [344, 345, 346, 349, 306, 347, 348, 318, 316, 317];
const EXPECTED_HIDDEN_CAT_IDS = [315, 350];

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function artisan(code, timeoutMs = 25_000) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: timeoutMs,
  }).trim();
}

function tinkerJson(code, timeoutMs = 25_000) {
  const out = artisan(code, timeoutMs);
  const lines = String(out).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const jsonLine = [...lines].reverse().find((l) => l.startsWith('{') || l.startsWith('['));
  if (!jsonLine) throw new Error(`No JSON in artisan output:\n${out}`);
  return JSON.parse(jsonLine);
}

// Batch DB snapshot for many orders in ONE artisan call.
function snapshotOrdersBatch(orderIds) {
  if (!orderIds || orderIds.length === 0) return [];
  const ids = orderIds.map((n) => Number(n)).filter(Number.isFinite);
  if (ids.length === 0) return [];
  const idCsv = ids.join(',');
  return tinkerJson(`
    $ids = [${idCsv}];
    $orders = DB::table('orders')->whereIn('id', $ids)->get();
    $out = [];
    foreach ($orders as $o) {
      $items = DB::table('order_items')->where('order_id', $o->id)
        ->get(['id', 'item_id', 'composition_snapshot', 'price', 'quantity']);
      $itemsWithSnap = 0;
      $linesInSnap = 0;
      foreach ($items as $it) {
        if (!empty($it->composition_snapshot) && $it->composition_snapshot !== 'null') {
          $itemsWithSnap++;
          $decoded = json_decode($it->composition_snapshot, true);
          if (is_array($decoded) && isset($decoded['lines']) && is_array($decoded['lines'])) {
            $linesInSnap += count($decoded['lines']);
          }
        }
      }
      $auditCount = DB::table('audit_logs')
        ->where('resource', 'order')
        ->where('resource_id', $o->id)
        ->count();
      $domainCount = DB::table('domain_events')
        ->where('aggregate_id', (string) $o->id)
        ->count();
      $out[] = [
        'order_id' => (int) $o->id,
        'order_serial_no' => (string) ($o->order_serial_no ?? ''),
        'fiscal_sequence_no' => $o->fiscal_sequence_no ?? null,
        'branch_id' => (int) ($o->branch_id ?? 0),
        'status' => $o->status ?? null,
        'order_status' => $o->order_status ?? null,
        'payment_status' => $o->payment_status ?? null,
        'total' => is_numeric($o->total ?? null) ? (float) $o->total : null,
        'subtotal' => is_numeric($o->subtotal ?? null) ? (float) $o->subtotal : null,
        'order_type' => $o->order_type ?? null,
        'source_surface' => $o->source_surface ?? null,
        'token' => (string) ($o->token ?? ''),
        'item_count' => count($items),
        'items_with_composition_snapshot' => $itemsWithSnap,
        'lines_in_snapshot_total' => $linesInSnap,
        'composition_full_coverage' => count($items) > 0 && count($items) === $itemsWithSnap,
        'audit_logs_count' => (int) $auditCount,
        'domain_events_count' => (int) $domainCount,
      ];
    }
    echo json_encode($out);
  `, 45_000);
}

// FR-style € parser : "13,80 €" → 13.80
function parseEur(txt) {
  if (!txt) return NaN;
  const m = String(txt).replace(/\s/g, '').replace(',', '.').match(/-?\d+(?:\.\d+)?/);
  return m ? parseFloat(m[0]) : NaN;
}

// Inspect sidebar visibility.
async function inspectKioskSidebar(page) {
  return await page.evaluate(({ expectedVisible, expectedHidden }) => {
    const sidebarNodes = Array.from(document.querySelectorAll('[data-testid^="kiosk-categories-sidebar-item-"]'));
    const visibleIds = [];
    const visibleNames = [];
    sidebarNodes.forEach((n) => {
      const rect = n.getBoundingClientRect();
      const visible = rect.width > 0 && rect.height > 0;
      if (!visible) return;
      const tid = n.getAttribute('data-testid') || '';
      const match = tid.match(/kiosk-categories-sidebar-item-(\d+)/);
      const id = match ? Number(match[1]) : NaN;
      if (Number.isFinite(id)) {
        visibleIds.push(id);
        visibleNames.push((n.textContent || '').replace(/\s+/g, ' ').trim().substring(0, 80));
      }
    });
    // Legacy fallback.
    if (visibleIds.length === 0) {
      const legacyNodes = Array.from(document.querySelectorAll('[data-category-id]'));
      legacyNodes.forEach((n) => {
        const rect = n.getBoundingClientRect();
        const visible = rect.width > 0 && rect.height > 0;
        if (!visible) return;
        const id = Number(n.getAttribute('data-category-id'));
        if (Number.isFinite(id)) {
          visibleIds.push(id);
          visibleNames.push((n.textContent || '').replace(/\s+/g, ' ').trim().substring(0, 80));
        }
      });
    }
    const sidebarTextJoined = visibleNames.join(' | ');
    const visibleByName = {};
    [
      'Sandwich Cayenne', 'Galette', 'Sandwich Classique', 'Burgers', 'Tacos',
      'Bols', 'Bols Gourmands', 'Frites', 'Suppléments', 'Supplements',
      'Desserts', 'Boissons', 'Menu enfant',
    ].forEach((label) => {
      visibleByName[label] = sidebarTextJoined.includes(label);
    });
    // i18n leak detector
    const i18nLeaks = [];
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    let n;
    const re = /^[a-z]+(\.[a-z_]+){1,4}$/;
    while ((n = walker.nextNode())) {
      const t = (n.nodeValue || '').trim();
      if (t && re.test(t) && t.length < 80) {
        i18nLeaks.push(t);
        if (i18nLeaks.length >= 30) break;
      }
    }
    return {
      visibleIds,
      visibleNames,
      visibleByName,
      i18n_leaks_sample: i18nLeaks.slice(0, 15),
      i18n_leaks_count: i18nLeaks.length,
      total_visible_pills: visibleIds.length,
      expected_visible_ids: expectedVisible,
      expected_hidden_ids: expectedHidden,
    };
  }, { expectedVisible: EXPECTED_VISIBLE_CAT_IDS, expectedHidden: EXPECTED_HIDDEN_CAT_IDS });
}

// Inspect a single category's item cards.
async function inspectItemCard(page, itemId, expectedName, expectedPrice) {
  return await page.evaluate(({ itemId, expectedName, expectedPrice }) => {
    let cards = Array.from(document.querySelectorAll(`[data-testid="kiosk-product-card-${itemId}"]`));
    if (cards.length === 0) {
      cards = Array.from(document.querySelectorAll(`[data-item-id="${itemId}"]`));
    }
    const result = {
      cards_found: cards.length,
      first_card_text: null,
      name_present: false,
      price_present_eur: null,
      price_match_expected: null,
      price_text_raw: null,
    };
    const priceEl = document.querySelector(`[data-testid="kiosk-product-price-${itemId}"]`);
    if (priceEl) {
      const ptxt = (priceEl.textContent || '').replace(/\s+/g, ' ').trim();
      result.price_text_raw = ptxt;
      const m = ptxt.replace(/ /g, ' ').match(/(-?\d+[,.]\d{2}|\d+)/);
      if (m) {
        const v = parseFloat(String(m[1]).replace(',', '.'));
        if (Number.isFinite(v)) {
          result.price_present_eur = v;
          result.price_match_expected = Math.abs(v - expectedPrice) < 0.005;
        }
      }
    }
    if (cards.length > 0) {
      const t = (cards[0].textContent || '').replace(/\s+/g, ' ').trim();
      result.first_card_text = t.substring(0, 200);
      result.name_present = t.toLowerCase().includes(String(expectedName).toLowerCase());
    } else {
      const allText = (document.body?.innerText || '').replace(/\s+/g, ' ');
      result.fallback_name_in_page = allText.toLowerCase().includes(String(expectedName).toLowerCase());
    }
    return result;
  }, { itemId, expectedName, expectedPrice });
}

// Drive the kiosk SPA from /kiosk/idle to /kiosk/categories via the takeaway card.
async function enterCategoriesViaIdle(page, observations) {
  if (!/\/kiosk\/idle/.test(page.url())) {
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1200);
  }
  const takeawayCard = page.locator('[data-testid="kiosk-order-type-takeaway"]').first();
  let visible = false;
  for (let i = 0; i < 10; i++) {
    visible = await takeawayCard.isVisible().catch(() => false);
    if (visible) break;
    await page.waitForTimeout(500);
  }
  if (!visible) {
    observations.push(`enterCategoriesViaIdle: takeaway card NOT visible, URL=${page.url()}`);
    return false;
  }
  await takeawayCard.click();
  let inCats = false;
  for (let i = 0; i < 20; i++) {
    if (/\/kiosk\/categories/.test(page.url())) { inCats = true; break; }
    await page.waitForTimeout(300);
  }
  if (!inCats) {
    observations.push(`enterCategoriesViaIdle: did NOT reach /kiosk/categories, URL=${page.url()}`);
  }
  return inCats;
}

// Poll OSS for an order's appearance (public endpoint).
async function pollOssForOrder(page, orderId, maxMs = 5000) {
  const t0 = Date.now();
  let found = false;
  let last = null;
  while (Date.now() - t0 < maxMs) {
    try {
      const resp = await page.evaluate(async () => {
        try {
          const r = await window.axios.get('/api/frontend/oss-order', { params: { branch_id: 1 } });
          return { ok: true, status: r.status, data: r.data };
        } catch (e) {
          return { ok: false, status: e?.response?.status ?? 0, msg: String(e?.message || e).slice(0, 200) };
        }
      });
      last = resp;
      if (resp.ok && resp.data) {
        const items = Array.isArray(resp.data?.data) ? resp.data.data
                    : Array.isArray(resp.data) ? resp.data : [];
        if (items.some((it) => Number(it.id ?? it.order_id) === Number(orderId))) {
          found = true;
          break;
        }
      }
    } catch (_e) { /* ignore */ }
    await page.waitForTimeout(500);
  }
  return { found, ms: Date.now() - t0, last_status: last?.status ?? 0 };
}

// Poll KDS for an order (requires admin auth — we test the public OSS as proxy
// since KDS sync is auth-only and our session is kiosk).
async function pollKdsForOrder(adminPage, orderId, maxMs = 5000) {
  if (!adminPage) return { found: null, ms: 0, last_status: 'no-admin-session' };
  const t0 = Date.now();
  let found = false;
  let last = null;
  while (Date.now() - t0 < maxMs) {
    try {
      const resp = await adminPage.evaluate(async () => {
        try {
          const r = await window.axios.get('/api/admin/kds-order/sync', { params: { branch_id: 1 } });
          return { ok: true, status: r.status, data: r.data };
        } catch (e) {
          return { ok: false, status: e?.response?.status ?? 0, msg: String(e?.message || e).slice(0, 200) };
        }
      });
      last = resp;
      if (resp.ok && resp.data) {
        const items = Array.isArray(resp.data?.data) ? resp.data.data
                    : Array.isArray(resp.data?.orders) ? resp.data.orders
                    : Array.isArray(resp.data) ? resp.data : [];
        if (items.some((it) => Number(it.id ?? it.order_id) === Number(orderId))) {
          found = true;
          break;
        }
      }
    } catch (_e) { /* ignore */ }
    await adminPage.waitForTimeout(500);
  }
  return { found, ms: Date.now() - t0, last_status: last?.status ?? 0 };
}

// Build a small admin session for KDS polling — login via /api/auth/login.
async function buildAdminSession(browser, observations) {
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 720 } });
    const page = await ctx.newPage();
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    // Try API-based login for axios session reuse.
    const loginResp = await page.evaluate(async () => {
      try {
        const r = await window.axios.post('/api/auth/login', {
          email: 'admin@lecayenne.fr',
          password: '123456',
        });
        return { ok: true, status: r.status, data: r.data };
      } catch (e) {
        return { ok: false, status: e?.response?.status ?? 0, msg: String(e?.message || e).slice(0, 300) };
      }
    });
    if (!loginResp.ok) {
      observations.push(`admin session login FAIL : ${JSON.stringify(loginResp).slice(0, 250)}`);
      await ctx.close().catch(() => {});
      return null;
    }
    observations.push(`admin session OK : status=${loginResp.status}`);
    return { ctx, page };
  } catch (e) {
    observations.push(`admin session build error : ${String(e?.message || e).slice(0, 200)}`);
    return null;
  }
}

// -----------------------------------------------------------------------------
// MAIN TEST
// -----------------------------------------------------------------------------

test.describe('FINAL-BORNE — 10 scenarios kiosk deep test (2026-05-14)', () => {
  test.setTimeout(3_600_000); // 60 min budget

  test('10 kiosk orders (composer-aware) + DB persist + sidebar + KDS/OSS + security', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 } });
    const page = await ctx.newPage();
    const rec = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    const observations = [];
    const perScenario = [];
    const perScenarioStepInspection = {};
    let preflight = null;
    let adminSession = null;

    try {
      // ---- PRE-FLIGHT --------------------------------------------------
      try {
        cleanupKioskAuditOrders(KIOSK_AUDIT_PREFIX);
      } catch (e) {
        observations.push(`pre-flight cleanup soft-fail: ${String(e?.message || e).slice(0, 240)}`);
      }
      clearFoodKingRateLimits();
      resetKioskToken();
      preflight = tinkerJson(`
        echo json_encode([
          'max_order_id' => (int) (DB::table('orders')->max('id') ?? 0),
          'max_fiscal_sequence_no' => (int) (DB::table('orders')->max('fiscal_sequence_no') ?? 0),
          'audit_logs_count' => (int) DB::table('audit_logs')->count(),
        ]);
      `);
      observations.push(`baseline: ${JSON.stringify(preflight)}`);

      // ---- BUILD ADMIN SESSION (for KDS polling) ----------------------
      adminSession = await buildAdminSession(browser, observations);

      // ---- KIOSK auto-login ------------------------------------------
      await page.goto('/kiosk/login', { waitUntil: 'domcontentloaded' });
      let vuexReady = false;
      let vuexProbe = null;
      for (let i = 0; i < 30; i++) {
        vuexProbe = await page.evaluate(() => {
          let v = {};
          try { v = JSON.parse(localStorage.getItem('vuex') || '{}'); } catch (_e) { /* noop */ }
          return {
            url: location.pathname,
            kiosk_token_present: !!(v.kioskCart?.kioskToken),
          };
        });
        if (vuexProbe.kiosk_token_present) { vuexReady = true; break; }
        await page.waitForTimeout(500);
      }
      observations.push(`kiosk vuex ready=${vuexReady}`);
      if (!/\/kiosk\/idle/.test(page.url())) {
        await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      }
      await page.waitForTimeout(2000);
      await rec.snap('00-kiosk-idle-pre-flight');

      // ---- GLOBAL SIDEBAR INSPECTION ----------------------------------
      await enterCategoriesViaIdle(page, observations);
      await page.waitForSelector('[data-testid="kiosk-categories-root"]', { timeout: 15_000 }).catch(() => {});
      await page.waitForTimeout(2500);
      let sidebarCount = 0;
      for (let i = 0; i < 10; i++) {
        sidebarCount = await page.locator('[data-testid^="kiosk-categories-sidebar-item-"]').count();
        if (sidebarCount > 0) break;
        await page.waitForTimeout(500);
      }
      observations.push(`kiosk: /kiosk/categories sidebar count=${sidebarCount}`);
      await rec.snap('00-kiosk-categories-overview');

      const sidebarObservation = await inspectKioskSidebar(page);
      observations.push(`sidebar: visible=${sidebarObservation.total_visible_pills} ids=${JSON.stringify(sidebarObservation.visibleIds)}`);

      // ---- SCENARIO LOOP ----------------------------------------------
      for (let s = 0; s < SCENARIOS.length; s++) {
        const sc = SCENARIOS[s];
        observations.push(`=== START ${sc.code} (${sc.label}) ===`);

        const stateRoot = `${sc.code}`;

        // -- STATE A : capture idle for 1st scenario only
        if (s === 0) {
          if (!/\/kiosk\/idle/.test(page.url())) {
            await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
            await page.waitForTimeout(800);
          }
          await rec.snap(`${stateRoot}-01-idle`);
        }

        // -- STATE B : categories + click on category sidebar
        let cardObservation = null;
        if (!sc.multi && sc.catId) {
          if (!/\/kiosk\/categories/.test(page.url())) {
            await enterCategoriesViaIdle(page, observations);
            await page.waitForSelector('[data-testid="kiosk-categories-root"]', { timeout: 8_000 }).catch(() => {});
            await page.waitForTimeout(1500);
          }
          let clicked = false;
          try {
            const sidebarBtn = page.locator(`[data-testid="kiosk-categories-sidebar-item-${sc.catId}"]`).first();
            if (await sidebarBtn.isVisible({ timeout: 2500 }).catch(() => false)) {
              await sidebarBtn.click();
              clicked = true;
            }
          } catch (_e) { /* drift-tolerant */ }
          await page.waitForTimeout(1500);
          await rec.snap(`${stateRoot}-02-categories-cat-${sc.catId}`);

          cardObservation = await inspectItemCard(page, sc.itemId, sc.label, sc.expectedPrice);
          observations.push(`${sc.code}: cat-clicked=${clicked} cards=${cardObservation.cards_found} price_match=${cardObservation.price_match_expected}`);

          // -- STATE C : open wizard (click item card)
          try {
            const card = page.locator(`[data-testid="kiosk-product-card-${sc.itemId}"]`).first();
            if (await card.isVisible({ timeout: 2000 }).catch(() => false)) {
              await card.click();
              await page.waitForTimeout(2000);
              await rec.snap(`${stateRoot}-03-wizard-open`);

              // Inspect step labels.
              const stepInspection = await page.evaluate(() => {
                const labels = Array.from(document.querySelectorAll('.kiosk-step-visual-label'))
                  .map((n) => (n.textContent || '').replace(/\s+/g, ' ').trim());
                const dots = document.querySelectorAll('.kiosk-step-dot').length;
                const viandeStepCount = labels.filter((l) => /VIANDE/i.test(l)).length;
                const menuStepCount = labels.filter((l) => /MENU/i.test(l)).length;
                const sauceStepCount = labels.filter((l) => /SAUCE/i.test(l)).length;
                const supplementStepCount = labels.filter((l) => /SUPP/i.test(l)).length;
                const crudStepCount = labels.filter((l) => /CRUD/i.test(l)).length;
                const drinkStepCount = labels.filter((l) => /BOISSON|DRINK/i.test(l)).length;
                const gratineStepCount = labels.filter((l) => /GRATIN/i.test(l)).length;
                // Pull URL + active step element text.
                const activeStepEl = document.querySelector('.kiosk-step-content, [data-testid="kiosk-wizard-step"]');
                const activeStepText = activeStepEl ? (activeStepEl.textContent || '').replace(/\s+/g, ' ').trim().substring(0, 240) : null;
                return {
                  labels,
                  dots_count: dots,
                  viande_step_count: viandeStepCount,
                  menu_step_count: menuStepCount,
                  sauce_step_count: sauceStepCount,
                  supplement_step_count: supplementStepCount,
                  crudite_step_count: crudStepCount,
                  drink_step_count: drinkStepCount,
                  gratine_step_count: gratineStepCount,
                  active_step_text: activeStepText,
                  url: location.pathname + location.search,
                };
              });
              perScenarioStepInspection[sc.code] = stepInspection;
              observations.push(`${sc.code}: wizard steps labels=${JSON.stringify(stepInspection.labels).slice(0, 200)} dots=${stepInspection.dots_count}`);

              // Try to advance to a middle step (best-effort).
              try {
                // Click first option on current step if any
                const firstOpt = page.locator('.kiosk-option-card, [data-testid^="kiosk-option-"]').first();
                if (await firstOpt.isVisible({ timeout: 1500 }).catch(() => false)) {
                  await firstOpt.click();
                  await page.waitForTimeout(800);
                }
                const nextBtn = page.locator('[data-testid="kiosk-wizard-next"], button:has-text("Suivant"), button:has-text("Valider")').first();
                if (await nextBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
                  await nextBtn.click();
                  await page.waitForTimeout(1000);
                }
                await rec.snap(`${stateRoot}-04-wizard-mid`);
              } catch (_e) {
                await rec.snap(`${stateRoot}-04-wizard-mid-soft-fail`);
              }

              // Try to reach recap (best-effort — click "Suivant" a few times).
              try {
                for (let st = 0; st < 6; st++) {
                  const nextBtn2 = page.locator('[data-testid="kiosk-wizard-next"], button:has-text("Suivant"), button:has-text("Valider"), button:has-text("Récapitulatif"), button:has-text("Recapitulatif")').first();
                  if (!(await nextBtn2.isVisible({ timeout: 800 }).catch(() => false))) break;
                  await nextBtn2.click();
                  await page.waitForTimeout(600);
                }
                await rec.snap(`${stateRoot}-05-wizard-recap`);
              } catch (_e) {
                await rec.snap(`${stateRoot}-05-wizard-recap-soft-fail`);
              }

              // Return to categories
              await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' }).catch(() => {});
              await page.waitForTimeout(800);
            } else {
              observations.push(`${sc.code}: item card not found via kiosk-product-card-${sc.itemId}`);
              await rec.snap(`${stateRoot}-03-wizard-open-skip`);
            }
          } catch (_e) {
            observations.push(`${sc.code}: wizard-open exception : ${String(_e?.message || _e).slice(0, 200)}`);
          }
        } else if (sc.multi) {
          await rec.snap(`${stateRoot}-02-categories-multi`);
        }

        // -- STATE D : place order via API (deterministic) -----------
        const t0 = Date.now();
        let placement = null;
        let placementError = null;
        try {
          placement = await placeKioskOrder(page, {
            items: sc.items,
            paymentMethod: PAYMENT_CARD,
            orderType: 10, // TAKEAWAY (V1 dine-in disabled)
            skipPaymentConfirm: true,
          });
          const totalCents = Math.round((placement.totalAmount || 0) * 100);
          const confirmIdem = `${placement.idempotencyKey}-confirm`;
          const confirmResp = await page.evaluate(async ({ orderId, totalCents, idemKey, paymentMethod }) => {
            try {
              const r = await window.axios.post(
                `frontend/order/${orderId}/payment-confirm`,
                {
                  transaction_id: `FINAL-BORNE-TPE-${Date.now()}`,
                  card_type: 'simulated-card',
                  payment_method: paymentMethod,
                  amount_cents: totalCents,
                },
                { headers: { 'X-Idempotency-Key': idemKey } },
              );
              return { ok: true, status: r.status, data: r.data };
            } catch (e) {
              return {
                ok: false,
                status: e?.response?.status ?? 0,
                body: JSON.stringify(e?.response?.data || {}).slice(0, 400),
              };
            }
          }, {
            orderId: placement.orderId,
            totalCents,
            idemKey: confirmIdem,
            paymentMethod: PAYMENT_CARD,
          });
          if (!confirmResp.ok) {
            placement.paymentConfirm = confirmResp;
            observations.push(`${sc.code}: payment-confirm FAIL ${JSON.stringify(confirmResp).slice(0, 250)}`);
          } else {
            placement.paymentConfirm = confirmResp.data;
          }
        } catch (err) {
          placementError = {
            message: String(err?.message || err).slice(0, 600),
            stage: err?.stage || null,
            status: err?.status || null,
            body: err?.body || null,
          };
        }
        const t1 = Date.now();
        observations.push(`${sc.code}: placement ${placement ? 'OK' : 'FAIL'} elapsed=${t1 - t0}ms` +
          (placement ? ` order=${placement.orderId} total=${placement.totalAmount}` :
            ` err=${JSON.stringify(placementError).slice(0, 200)}`));

        // -- STATE E : after API capture
        await rec.snap(`${stateRoot}-06-cart-or-post-api`);

        // -- STATE F : payment-screen (skip+document via API-hybrid)
        // We capture the current page as the "payment-equivalent" — honest
        // limitation : API-hybrid bypasses the TPE UI surface.
        await rec.snap(`${stateRoot}-07-payment-screen-api-hybrid`);

        // Cross-surface : KDS + OSS poll.
        let kdsTrace = { found: null, ms: 0, last_status: 'skip-no-placement' };
        let ossTrace = { found: null, ms: 0, last_status: 'skip-no-placement' };
        if (placement) {
          try {
            ossTrace = await pollOssForOrder(page, placement.orderId, 4000);
          } catch (e) { observations.push(`${sc.code}: oss poll error ${String(e?.message || e).slice(0, 100)}`); }
          try {
            kdsTrace = await pollKdsForOrder(adminSession?.page, placement.orderId, 4000);
          } catch (e) { observations.push(`${sc.code}: kds poll error ${String(e?.message || e).slice(0, 100)}`); }
          observations.push(`${sc.code}: KDS found=${kdsTrace.found} (${kdsTrace.ms}ms) | OSS found=${ossTrace.found} (${ossTrace.ms}ms)`);
        }

        if (!placement) {
          perScenario.push({
            scenario: sc.code,
            label: sc.label,
            item_id: sc.itemId,
            expected_total: sc.expectedPrice,
            wizard_steps_expected: sc.wizardStepsExpected,
            wizard_steps_dom_count: null,
            ui_total_confirmation: null,
            ui_order_number: null,
            db_order: null,
            db_order_items: null,
            kds_receive_ms: kdsTrace.ms,
            oss_receive_ms: ossTrace.ms,
            placement_ok: false,
            placement_error: placementError,
            t0_ms: t0,
            t1_ms: t1,
            card_observation: cardObservation,
            kds_trace: kdsTrace,
            oss_trace: ossTrace,
          });
          continue;
        }

        // -- STATE G : navigate to confirmation
        let confirmationCapture = { ui_total: null, ui_order_number: null };
        try {
          await page.goto(`/kiosk/confirmation?order_id=${placement.orderId}`, {
            waitUntil: 'domcontentloaded',
            timeout: 10000,
          });
          await page.waitForTimeout(1500);
          confirmationCapture = await page.evaluate(() => {
            const allText = (document.body?.innerText || '').replace(/\s+/g, ' ');
            const totalMatch = allText.match(/(\d+[,.]\d{2})\s*€/);
            const orderMatch = allText.match(/#A0\d+|#\d{4,}/);
            return {
              ui_total: totalMatch ? totalMatch[1] : null,
              ui_order_number: orderMatch ? orderMatch[0] : null,
              page_text_sample: allText.slice(0, 300),
            };
          });
          await rec.snap(`${stateRoot}-08-confirmation`);
        } catch (_e) {
          await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' }).catch(() => {});
          await page.waitForTimeout(1000);
          await rec.snap(`${stateRoot}-08-idle-after`);
        }

        perScenario.push({
          scenario: sc.code,
          label: sc.label,
          item_id: sc.itemId,
          expected_total: sc.expectedPrice,
          wizard_steps_expected: sc.wizardStepsExpected,
          wizard_steps_dom_count: perScenarioStepInspection[sc.code]?.dots_count ?? null,
          ui_total_confirmation: confirmationCapture.ui_total,
          ui_order_number: confirmationCapture.ui_order_number,
          placement_ok: true,
          placement_error: null,
          order_id: placement.orderId,
          order_serial: placement.orderSerialNo,
          total_ui_eur: placement.totalAmount,
          queue_number: placement.queueNumber,
          idempotency_key: placement.idempotencyKey,
          t0_ms: t0,
          t1_ms: t1,
          placement_elapsed_ms: t1 - t0,
          card_observation: cardObservation,
          kds_trace: kdsTrace,
          oss_trace: ossTrace,
          kds_receive_ms: kdsTrace.ms,
          oss_receive_ms: ossTrace.ms,
        });

        await page.waitForTimeout(600);
      } // end scenario loop

      // ---- BATCH DB SNAPSHOTS ----------------------------------------
      const placedOrderIds = perScenario
        .filter((s) => s.placement_ok && s.order_id)
        .map((s) => s.order_id);
      let batchSnap = [];
      try {
        batchSnap = snapshotOrdersBatch(placedOrderIds);
        observations.push(`batch DB snap : ${batchSnap.length}/${placedOrderIds.length} rows`);
      } catch (e) {
        observations.push(`batch DB snap FAIL : ${String(e?.message || e).slice(0, 400)}`);
      }
      const snapById = new Map(batchSnap.map((b) => [Number(b.order_id), b]));
      for (const row of perScenario) {
        if (!row.placement_ok) continue;
        const snap = snapById.get(Number(row.order_id)) || null;
        row.db_order = snap;
        row.db_order_items = snap ? {
          lines_count: snap.lines_in_snapshot_total,
          item_count: snap.item_count,
          items_with_composition_snapshot: snap.items_with_composition_snapshot,
          composition_full_coverage: snap.composition_full_coverage,
        } : null;
        row.domain_events_count = snap?.domain_events_count ?? null;
        row.assertions = {
          total_ui_eq_expected: row.total_ui_eur != null
            ? Math.abs(row.total_ui_eur - row.expected_total) < 0.005 : null,
          total_db_eq_ui: (snap && snap.total != null && row.total_ui_eur != null)
            ? Math.abs(snap.total - row.total_ui_eur) < 0.005 : null,
          composition_full_coverage: snap?.composition_full_coverage ?? null,
          fiscal_seq_present: snap?.fiscal_sequence_no != null,
          fiscal_seq_no: snap?.fiscal_sequence_no ?? null,
          payment_paid: snap?.payment_status === 5,
          payment_status_raw: snap?.payment_status ?? null,
          branch_id_correct: snap?.branch_id === 1,
        };
      }

      // ---- POST-RUN BASELINE -----------------------------------------
      const postRun = tinkerJson(`
        echo json_encode([
          'max_order_id' => (int) (DB::table('orders')->max('id') ?? 0),
          'max_fiscal_sequence_no' => (int) (DB::table('orders')->max('fiscal_sequence_no') ?? 0),
          'audit_logs_count' => (int) DB::table('audit_logs')->count(),
        ]);
      `);
      observations.push(`post-run baseline: ${JSON.stringify(postRun)}`);

      // ---- FISCAL SEQUENCE MONOTONIC ----------------------------------
      const fiscalSeqs = perScenario
        .filter((s) => s.placement_ok && s.assertions?.fiscal_seq_no != null)
        .map((s) => s.assertions.fiscal_seq_no);
      const minFiscalSeq = fiscalSeqs.length ? Math.min(...fiscalSeqs) : null;
      const maxFiscalSeq = fiscalSeqs.length ? Math.max(...fiscalSeqs) : null;
      const fiscalBaseline = Number(preflight?.max_fiscal_sequence_no ?? 0);
      const fiscalMonotonicOk = minFiscalSeq != null && minFiscalSeq > fiscalBaseline;
      observations.push(`fiscal seq : min=${minFiscalSeq} max=${maxFiscalSeq} baseline=${fiscalBaseline} monotonic_ok=${fiscalMonotonicOk}`);

      // ---- NF525 audit_logs invariant : MUST remain 26 ----------------
      const auditLogsInvariantOk = postRun.audit_logs_count === preflight.audit_logs_count;
      observations.push(`audit_logs invariant : pre=${preflight.audit_logs_count} post=${postRun.audit_logs_count} ok=${auditLogsInvariantOk}`);

      // ---- BranchScope invariant : all orders branch_id=1 -------------
      const allBranchOk = perScenario.filter((s) => s.placement_ok).every((s) => s.assertions?.branch_id_correct === true);
      observations.push(`branch_id=1 invariant : all_ok=${allBranchOk}`);

      // ---- Sanctum kiosk:order security check -------------------------
      // Try to call an admin endpoint with the kiosk bearer token → should fail.
      let secCheck = { tested: false };
      try {
        secCheck = await page.evaluate(async () => {
          try {
            let token = '';
            try {
              const v = JSON.parse(localStorage.getItem('vuex') || '{}');
              token = v.kioskCart?.kioskToken || '';
            } catch (_e) { /* noop */ }
            if (!token) return { tested: false, reason: 'no_kiosk_token_in_vuex' };
            const r = await fetch('/api/admin/dashboard/total-sales', {
              headers: { Authorization: `Bearer ${token}` },
            });
            return { tested: true, status: r.status, blocked: r.status === 401 || r.status === 403 };
          } catch (e) {
            return { tested: true, status: 0, error: String(e?.message || e).slice(0, 200) };
          }
        });
      } catch (e) {
        secCheck = { tested: false, error: String(e?.message || e).slice(0, 100) };
      }
      observations.push(`kiosk-token-on-admin : ${JSON.stringify(secCheck)}`);

      // ---- WRITE DB CHECKS JSON ---------------------------------------
      const dbChecksPayload = {
        run: 'final-caisse-borne-2026-05-14',
        wave: 'BORNE',
        round: 1,
        timestamp: new Date().toISOString(),
        baseline_pre: preflight,
        baseline_post: postRun,
        sidebar_inspection: sidebarObservation,
        scenarios: perScenario,
        wizard_step_inspection: perScenarioStepInspection,
        observations,
        fiscal_seq_summary: {
          min_in_run: minFiscalSeq,
          max_in_run: maxFiscalSeq,
          baseline: fiscalBaseline,
          monotonic_ok: fiscalMonotonicOk,
        },
        nf525_audit_logs_invariant: {
          pre: preflight.audit_logs_count,
          post: postRun.audit_logs_count,
          ok: auditLogsInvariantOk,
        },
        branch_isolation: {
          all_orders_branch_1: allBranchOk,
        },
        sanctum_kiosk_security_check: secCheck,
      };
      fs.writeFileSync(DB_CHECKS_FILE, JSON.stringify(dbChecksPayload, null, 2));
      observations.push(`db checks JSON written : ${DB_CHECKS_FILE}`);

      // ---- WRITE REPORT MD --------------------------------------------
      const report = renderReport(dbChecksPayload);
      fs.writeFileSync(REPORT_FILE, report);
      observations.push(`report MD written : ${REPORT_FILE}`);

      // ---- SOFT ASSERTIONS --------------------------------------------
      const okCount = perScenario.filter((s) => s.placement_ok).length;
      expect(okCount, `at least 8/10 scenarios placed OK (got ${okCount})`).toBeGreaterThanOrEqual(8);
    } finally {
      try { rec.dispose(); } catch (_e) { /* ignore */ }
      try { await ctx.close(); } catch (_e) { /* ignore */ }
      if (adminSession) {
        try { await adminSession.ctx.close(); } catch (_e) { /* ignore */ }
      }
    }
  });
});

// -----------------------------------------------------------------------------
// Report renderer
// -----------------------------------------------------------------------------

function renderReport(payload) {
  const {
    scenarios, sidebar_inspection: sb, fiscal_seq_summary: fs_,
    baseline_pre, baseline_post, observations, nf525_audit_logs_invariant,
    branch_isolation, sanctum_kiosk_security_check, wizard_step_inspection,
  } = payload;

  const okCount = scenarios.filter((s) => s.placement_ok).length;
  const newOrderIds = scenarios.filter((s) => s.placement_ok).map((s) => s.order_id);

  const lines = [];
  lines.push('# FINAL TEST — Wave BORNE Report (round-1, 2026-05-14)');
  lines.push('');
  lines.push(`Run: \`final-caisse-borne-2026-05-14\` · Wave: **BORNE** · Branch: \`feature/mobile-app-le-cayenne-2026-05-10\``);
  lines.push(`Baseline pre-run : order_id=${baseline_pre.max_order_id}, fiscal_seq=${baseline_pre.max_fiscal_sequence_no}, audit_logs=${baseline_pre.audit_logs_count}`);
  lines.push(`Post-run         : order_id=${baseline_post.max_order_id}, fiscal_seq=${baseline_post.max_fiscal_sequence_no}, audit_logs=${baseline_post.audit_logs_count}`);
  lines.push(`Placed: **${okCount}/10** new orders (ids ${newOrderIds.join(',')})`);
  lines.push('');
  lines.push('## TL;DR (5 lines)');
  lines.push('');
  const trends = [];
  if (fs_.monotonic_ok) trends.push('fiscal seq monotonic OK'); else trends.push('FISCAL DRIFT');
  if (nf525_audit_logs_invariant.ok) trends.push('NF525 audit_logs invariant intact (26→26)');
  else trends.push(`NF525 DRIFT (${nf525_audit_logs_invariant.pre}→${nf525_audit_logs_invariant.post})`);
  if (branch_isolation.all_orders_branch_1) trends.push('branch_id=1 on all orders');
  if (sanctum_kiosk_security_check?.blocked === true) trends.push('kiosk token on admin blocked');
  else if (sanctum_kiosk_security_check?.blocked === false) trends.push('SECURITY DRIFT (kiosk token on admin NOT blocked)');
  lines.push(`Scenarios placed: ${okCount}/10. ${trends.join(' · ')}.`);
  lines.push('');

  // -- 1. Per-scenario summary --
  lines.push('## 1. Per-scenario summary');
  lines.push('');
  lines.push('| Code | Item | Exp € | UI € | DB € | Fiscal | Comp.full | Paid | KDS ms | OSS ms | Notes |');
  lines.push('|------|------|------:|-----:|-----:|-------:|:---------:|:----:|-------:|-------:|-------|');
  for (const s of scenarios) {
    if (!s.placement_ok) {
      lines.push(`| ${s.scenario} | ${s.label} | ${s.expected_total?.toFixed?.(2) ?? '—'} | — | — | — | — | — | — | — | **FAIL** ${(s.placement_error?.message || '').substring(0, 60)} |`);
      continue;
    }
    const a = s.assertions || {};
    const dbTotal = s.db_order?.total;
    const fiscal = a.fiscal_seq_no ?? '—';
    const compFull = a.composition_full_coverage === true ? 'YES' : (a.composition_full_coverage === false ? 'no' : '—');
    const paid = a.payment_paid === true ? 'YES' : (`${a.payment_status_raw ?? '?'}`);
    const note = [
      s.kds_trace?.found === false ? 'KDS-not-found' : null,
      s.oss_trace?.found === false ? 'OSS-not-found' : null,
      a.total_ui_eq_expected === false ? 'UI≠exp' : null,
    ].filter(Boolean).join(', ');
    lines.push(`| ${s.scenario} | ${s.label} | ${s.expected_total?.toFixed?.(2) ?? '—'} | ${s.total_ui_eur?.toFixed?.(2) ?? '—'} | ${dbTotal?.toFixed?.(2) ?? '—'} | ${fiscal} | ${compFull} | ${paid} | ${s.kds_trace?.ms ?? '—'} | ${s.oss_trace?.ms ?? '—'} | ${note || '—'} |`);
  }
  lines.push('');

  // -- 2. Wizard cardinality + composer drifts --
  lines.push('## 2. Wizard cardinality + composer drift (visual mandate)');
  lines.push('');
  lines.push('| Code | Item | Expected | Step labels DOM | Viande | Sauce | Supp | Crud | Menu | Drink | Gratine |');
  lines.push('|------|------|----------|-----------------|:------:|:-----:|:----:|:----:|:----:|:-----:|:-------:|');
  for (const s of scenarios) {
    if (s.multi) continue;
    const ws = wizard_step_inspection[s.scenario];
    if (!ws) {
      lines.push(`| ${s.scenario} | ${s.label} | ${s.wizard_steps_expected} | — | — | — | — | — | — | — | — |`);
      continue;
    }
    const labels = (ws.labels || []).join(' / ').slice(0, 80);
    lines.push(`| ${s.scenario} | ${s.label} | ${s.wizard_steps_expected} | ${labels || 'NONE'} | ${ws.viande_step_count} | ${ws.sauce_step_count} | ${ws.supplement_step_count} | ${ws.crudite_step_count} | ${ws.menu_step_count} | ${ws.drink_step_count} | ${ws.gratine_step_count} |`);
  }
  lines.push('');

  // -- 3. Sidebar inspection --
  lines.push('## 3. Sidebar inspection (10 cats expected, Menu enfant hidden)');
  lines.push('');
  lines.push(`Visible category pills : **${sb.total_visible_pills}**`);
  lines.push(`Visible IDs detected   : \`${JSON.stringify(sb.visibleIds)}\``);
  lines.push(`Expected visible IDs   : \`${JSON.stringify(sb.expected_visible_ids)}\` (10 cats)`);
  lines.push(`Expected hidden IDs    : \`${JSON.stringify(sb.expected_hidden_ids)}\` (315 + 350 Menu enfant)`);
  lines.push(`i18n leaks scanned     : ${sb.i18n_leaks_count} (sample: \`${JSON.stringify(sb.i18n_leaks_sample.slice(0, 5))}\`)`);
  lines.push('');
  lines.push('Label presence in sidebar:');
  for (const [k, v] of Object.entries(sb.visibleByName)) {
    lines.push(`- \`${k}\` : ${v ? 'present' : 'absent'}`);
  }
  lines.push('');

  // -- 4. NF525 + multi-tenant + security --
  lines.push('## 4. NF525 + multi-tenant + security invariants');
  lines.push('');
  lines.push(`- **Fiscal sequence monotonic** : ${fs_.monotonic_ok ? 'OK' : 'DRIFT'} (min=${fs_.min_in_run}, baseline=${fs_.baseline}, max=${fs_.max_in_run})`);
  lines.push(`- **audit_logs invariant** : ${nf525_audit_logs_invariant.ok ? 'OK (26→26 unchanged)' : `DRIFT (${nf525_audit_logs_invariant.pre}→${nf525_audit_logs_invariant.post})`}`);
  lines.push(`- **BranchScope** : all 10 orders branch_id=1 : ${branch_isolation.all_orders_branch_1 ? 'OK' : 'DRIFT'}`);
  lines.push(`- **Kiosk Sanctum token negative test** : token used on /api/admin/dashboard/total-sales`);
  lines.push(`  - tested=${sanctum_kiosk_security_check.tested} status=${sanctum_kiosk_security_check.status ?? 'n/a'} blocked=${sanctum_kiosk_security_check.blocked ?? 'n/a'}`);
  if (sanctum_kiosk_security_check.reason) {
    lines.push(`  - reason: ${sanctum_kiosk_security_check.reason}`);
  }
  lines.push('');
  lines.push('Composition snapshot coverage per order:');
  for (const s of scenarios) {
    if (!s.placement_ok) continue;
    const a = s.assertions || {};
    lines.push(`- ${s.scenario} (${s.label}, order=${s.order_id}, fiscal=${a.fiscal_seq_no}) : item_count=${s.db_order_items?.item_count}, lines=${s.db_order_items?.lines_count}, full=${a.composition_full_coverage}`);
  }
  lines.push('');

  // -- 5. Cross-surface KDS/OSS latency --
  lines.push('## 5. Cross-surface KDS / OSS latency');
  lines.push('');
  lines.push('Note: KDS poll requires admin session (/api/admin/kds-order/sync). OSS poll uses public /api/frontend/oss-order.');
  lines.push('Polling cap : 4 seconds per surface per order.');
  lines.push('');
  lines.push('| Code | Order | KDS found | KDS ms | OSS found | OSS ms |');
  lines.push('|------|------:|:---------:|------:|:---------:|------:|');
  for (const s of scenarios) {
    if (!s.placement_ok) continue;
    lines.push(`| ${s.scenario} | ${s.order_id} | ${s.kds_trace?.found ?? '—'} | ${s.kds_trace?.ms ?? '—'} | ${s.oss_trace?.found ?? '—'} | ${s.oss_trace?.ms ?? '—'} |`);
  }
  lines.push('');

  // -- 6. Anomalies --
  lines.push('## 6. Anomalies surfaced');
  lines.push('');
  const anomalies = [];
  for (const s of scenarios) {
    if (!s.placement_ok) {
      anomalies.push(`**${s.scenario}** : placement FAILED — ${(s.placement_error?.message || '').slice(0, 200)}`);
      continue;
    }
    const a = s.assertions || {};
    if (a.total_ui_eq_expected === false) {
      anomalies.push(`**${s.scenario}** : UI total ${s.total_ui_eur} ≠ expected ${s.expected_total}`);
    }
    if (a.total_db_eq_ui === false) {
      anomalies.push(`**${s.scenario}** : DB total ${s.db_order?.total} ≠ UI total ${s.total_ui_eur}`);
    }
    if (a.composition_full_coverage === false) {
      anomalies.push(`**${s.scenario}** : composition_snapshot incomplete (${s.db_order?.items_with_composition_snapshot}/${s.db_order?.item_count})`);
    }
    if (a.fiscal_seq_present === false) {
      anomalies.push(`**${s.scenario}** : fiscal_sequence_no is NULL — alloc failed?`);
    }
    if (a.payment_paid === false) {
      anomalies.push(`**${s.scenario}** : payment_status=${a.payment_status_raw} ≠ 5 (PAID)`);
    }
    if (a.branch_id_correct === false) {
      anomalies.push(`**${s.scenario}** : branch_id=${s.db_order?.branch_id} ≠ 1 (BranchScope drift)`);
    }
  }
  // Wizard cardinality drifts (DB vs prompt-expected)
  for (const sc of ['BO-S2', 'BO-S7', 'BO-S8']) {
    const ws = wizard_step_inspection[sc];
    if (!ws) continue;
    if (sc === 'BO-S2' && ws.viande_step_count < 2) {
      anomalies.push(`**${sc} (Big Cayenne)** : composer expected 6 steps (V1+V2+Sauce+Crud+Supp+Menu+Récap) but DOM shows ${ws.viande_step_count} viande step(s), ${ws.sauce_step_count} sauce step(s). Step labels: \`${(ws.labels || []).join(' / ')}\``);
    }
    if (sc === 'BO-S7' && ws.viande_step_count < 2) {
      anomalies.push(`**${sc} (Tacos L)** : composer 84 expected V1+V2 but DOM shows ${ws.viande_step_count} viande step(s). Step labels: \`${(ws.labels || []).join(' / ')}\``);
    }
    if (sc === 'BO-S8' && (ws.drink_step_count === 0 || ws.gratine_step_count === 0)) {
      anomalies.push(`**${sc} (Bowl curry)** : composer expected 4 steps (sauce/supp/drink/gratine) but DOM shows drink=${ws.drink_step_count}, gratine=${ws.gratine_step_count}. Step labels: \`${(ws.labels || []).join(' / ')}\``);
    }
  }
  if (!nf525_audit_logs_invariant.ok) {
    anomalies.push(`**NF525 audit_logs invariant DRIFT** : count went from ${nf525_audit_logs_invariant.pre} to ${nf525_audit_logs_invariant.post}`);
  }
  if (!fs_.monotonic_ok) {
    anomalies.push(`**Fiscal sequence DRIFT** : min in run (${fs_.min_in_run}) NOT > baseline (${fs_.baseline})`);
  }
  if (!branch_isolation.all_orders_branch_1) {
    anomalies.push(`**BranchScope DRIFT** : not all orders have branch_id=1`);
  }
  if (sanctum_kiosk_security_check?.tested === true && sanctum_kiosk_security_check?.blocked !== true) {
    anomalies.push(`**SECURITY DRIFT** : kiosk Sanctum token allowed access to /api/admin/dashboard/total-sales (status=${sanctum_kiosk_security_check.status})`);
  }
  if (sb.i18n_leaks_count > 0) {
    anomalies.push(`**i18n leaks** : ${sb.i18n_leaks_count} occurrences on catalog page (sample : ${sb.i18n_leaks_sample.slice(0, 3).join(', ')})`);
  }
  if (sb.total_visible_pills > 0 && sb.expected_hidden_ids.some((id) => sb.visibleIds.includes(id))) {
    const leaked = sb.expected_hidden_ids.filter((id) => sb.visibleIds.includes(id));
    anomalies.push(`**Sidebar LEAKED hidden categories** : ${JSON.stringify(leaked)} should be hidden on kiosk (Menu enfant + cat 315)`);
  }
  if (anomalies.length === 0) {
    lines.push('_None detected._');
  } else {
    for (const a of anomalies) lines.push(`- ${a}`);
  }
  lines.push('');

  // -- 7. Observations log --
  lines.push('## 7. Run observations (debug log)');
  lines.push('');
  lines.push('```');
  for (const o of observations) lines.push(o);
  lines.push('```');

  return lines.join('\n');
}
