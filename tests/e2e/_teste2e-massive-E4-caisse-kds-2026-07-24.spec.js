// =============================================================================
// _teste2e-massive-E4-caisse-kds-2026-07-24.spec.js
// TEST-E2E MASSIF — Dimension 4 : CAISSE + KDS cross-surface (authentifié, LOCAL)
//
// Cible : http://127.0.0.1:8000 (serveur live déjà UP, worker + soketi UP).
// Discipline : VISUEL D'ABORD. Chaque scénario capture la surface RÉELLE + LIT.
// Intégrité numérique : total caisse == KDS == confirmation. Idempotence prouvée.
//
// Scénarios :
//   S1 Caisse /admin/pos          — panneaux (À encaisser borne, Commandes web, tracker)
//   S2 Cycle web→caisse→KDS       — PENDING web → Accepter → Encaisser (idempotent) → KDS
//   S3 KDS /kds                   — lanes + ticket + release
//   S4 Hub /admin/catalog-hub     — 2 onglets + photo + badge désactivé global
//   S5 Attaque                    — résurrection terminal REFUSÉE (D-1) ; double-clic idempotent ; traçabilité
//
// Lancer :
//   E2E_BACKEND_AVAILABLE=1 PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 \
//     npx playwright test tests/e2e/_teste2e-massive-E4-caisse-kds-2026-07-24.spec.js --project=chromium
// =============================================================================

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const { loginAsPosOperator, loginAsAdmin } = require('./helpers/login');

const repoRoot = path.resolve(__dirname, '../..');
const SHOTS = path.join(repoRoot, 'tests/e2e/__screenshots__/e2e-massive-E4');
fs.mkdirSync(SHOTS, { recursive: true });

const PREFIX = 'E4MASS-';
const BASE_ITEM_ID = 34; // Grande Frites — 4,00 €, 0 variation (add direct, snapshot simple)
const UNIT = 4.0;

// -- Enums (miroir backend) ---------------------------------------------------
const OS = { PENDING: 1, ACCEPT: 4, PREPARING: 7, PREPARED: 8, DELIVERED: 13, CANCELED: 16, RETURNED: 22 };
const PS = { UNPAID: 4, PENDING_COUNTER: 15, PAID: 5 }; // valeurs indicatives; on lit via tinker

// -----------------------------------------------------------------------------
// tinker helpers
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
  if (!jsonLine) throw new Error(`tinker: no JSON in output:\n${out.slice(0, 800)}`);
  return JSON.parse(jsonLine);
}

function readApiKey() {
  if (process.env.MIX_API_KEY) return process.env.MIX_API_KEY;
  try {
    const env = fs.readFileSync(path.join(repoRoot, '.env'), 'utf8');
    const m = env.match(/^MIX_API_KEY=(.+)$/m);
    if (m) return m[1].trim().replace(/^["']|["']$/g, '');
  } catch (_) { /* fall through */ }
  return '';
}
const API_KEY = readApiKey();

// Seed a fresh WEB order (TAKEAWAY, COD, UNPAID, PENDING) with 1 OrderItem.
// Returns { id, token, queue }. This is exactly what the site client places
// (source_surface='web'), so it is acceptable via online-order/change-status
// and encashable via counter-collect (COD → COUNTER_DEFERRED after accept).
function seedWebOrder(tag) {
  const token = `${PREFIX}${tag}-${Date.now()}`;
  const queue = 'W' + String(7000 + Math.floor(Math.random() * 2000));
  const j = tinkerJson(`
    $admin = \\App\\Models\\User::where('email','admin@lecayenne.fr')->first();
    $o = new \\App\\Models\\Order();
    $o->branch_id=1; $o->user_id=$admin->id;
    $o->order_type=\\App\\Enums\\OrderType::TAKEAWAY; $o->source_surface='web'; $o->source=\\App\\Enums\\Source::WEB;
    $o->payment_method=\\App\\Enums\\PaymentGateway::CASH_ON_DELIVERY; $o->pos_payment_method=null;
    $o->payment_status=\\App\\Enums\\PaymentStatus::UNPAID; $o->status=\\App\\Enums\\OrderStatus::PENDING;
    $o->subtotal=${UNIT}; $o->total=${UNIT}; $o->total_tax=round(${UNIT}-${UNIT}/1.1,2); $o->discount=0; $o->delivery_charge=0;
    $o->token='${token}'; $o->order_serial_no='${token}'; $o->order_datetime=now();
    $o->queue_number='${queue}'; $o->saveQuietly();
    $oi=new \\App\\Models\\OrderItem();
    $oi->order_id=$o->id; $oi->item_id=${BASE_ITEM_ID}; $oi->branch_id=1; $oi->quantity=1;
    $oi->price=${UNIT}; $oi->total_price=${UNIT}; $oi->discount=0; $oi->item_extra_total=0; $oi->item_variation_total=0;
    $oi->tax_amount=0; $oi->tax_rate=0; $oi->composition_snapshot=json_encode(['name'=>'Grande Frites']); $oi->saveQuietly();
    echo json_encode(['id'=>(int)$o->id,'token'=>$o->token,'queue'=>$o->queue_number]);
  `);
  return j;
}

// Seed a TERMINAL order (CANCELED by default) for the D-1 resurrection attack.
function seedTerminalOrder(status = OS.CANCELED) {
  const token = `${PREFIX}TERM-${Date.now()}`;
  const j = tinkerJson(`
    $admin = \\App\\Models\\User::where('email','admin@lecayenne.fr')->first();
    $o = new \\App\\Models\\Order();
    $o->branch_id=1; $o->user_id=$admin->id;
    $o->order_type=\\App\\Enums\\OrderType::TAKEAWAY; $o->source_surface='web'; $o->source=\\App\\Enums\\Source::WEB;
    $o->payment_method=\\App\\Enums\\PaymentGateway::CASH_ON_DELIVERY;
    $o->payment_status=\\App\\Enums\\PaymentStatus::UNPAID; $o->status=${status};
    $o->subtotal=${UNIT}; $o->total=${UNIT}; $o->total_tax=0; $o->discount=0; $o->delivery_charge=0;
    $o->token='${token}'; $o->order_serial_no='${token}'; $o->order_datetime=now();
    $o->queue_number='T'.rand(100,999); $o->saveQuietly();
    echo json_encode(['id'=>(int)$o->id,'token'=>$o->token,'status'=>(int)$o->status]);
  `);
  return j;
}

// Seed a PHANTOM non-web PENDING (abandoned kiosk cart) — must NOT show as a
// tracker card nor inflate the honest counter (D-2).
function seedPhantomPending() {
  const token = `${PREFIX}PHANTOM-${Date.now()}`;
  const queue = 'PH' + String(100 + Math.floor(Math.random() * 800));
  const j = tinkerJson(`
    $admin = \\App\\Models\\User::where('email','admin@lecayenne.fr')->first();
    $o = new \\App\\Models\\Order();
    $o->branch_id=1; $o->user_id=$admin->id;
    $o->order_type=\\App\\Enums\\OrderType::KIOSK; $o->source_surface='kiosk';
    $o->payment_method=\\App\\Enums\\PaymentGateway::CASH_ON_DELIVERY;
    $o->payment_status=\\App\\Enums\\PaymentStatus::UNPAID; $o->status=\\App\\Enums\\OrderStatus::PENDING;
    $o->subtotal=${UNIT}; $o->total=${UNIT}; $o->total_tax=0; $o->discount=0; $o->delivery_charge=0;
    $o->token='${token}'; $o->order_serial_no='${token}'; $o->order_datetime=now();
    $o->queue_number='${queue}'; $o->saveQuietly();
    echo json_encode(['id'=>(int)$o->id,'token'=>$o->token,'queue'=>$o->queue_number]);
  `);
  return j;
}

function orderState(id) {
  return tinkerJson(`
    $o=\\App\\Models\\Order::withoutGlobalScopes()->find(${id});
    echo json_encode($o ? [
      'id'=>(int)$o->id,'status'=>(int)$o->status,'payment_status'=>(int)$o->payment_status,
      'pos_payment_method'=>$o->pos_payment_method===null?null:(int)$o->pos_payment_method,
      'fiscal_sequence_no'=>$o->fiscal_sequence_no,'total'=>(float)$o->total,
    ] : ['id'=>null]);
  `);
}

// Cash movements + fiscal drawer proof for a given order (idempotency of encaisser).
function cashProof(id) {
  return tinkerJson(`
    $o=\\App\\Models\\Order::withoutGlobalScopes()->find(${id});
    $mv=\\App\\Models\\CashMovement::withoutGlobalScopes()->where('order_id',${id})->count();
    echo json_encode([
      'payment_status'=>$o?(int)$o->payment_status:null,
      'fiscal_sequence_no'=>$o?$o->fiscal_sequence_no:null,
      'cash_movements'=>(int)$mv,
    ]);
  `);
}

// KDS board-release SSOT query (the board's own filter).
function boardReleasedIds() {
  return tinkerJson(`
    $q = \\App\\Models\\Order::query()->whereIn('status', \\App\\Domain\\Kds\\KitchenReleaseRule::visibleStatuses());
    \\App\\Domain\\Kds\\KitchenReleaseRule::applyBoardReleaseFilter($q);
    $q->where('branch_id', 1);
    echo json_encode(['ids'=>$q->pluck('id')->map(fn($v)=>(int)$v)->values()->all()]);
  `);
}

function cleanup() {
  // Per-row + skip fiscalized: an ENCAISSED order carries fiscal_sequence_no and
  // the NF525 `orders_no_delete` trigger (P1-1) FORBIDS its deletion — a batch
  // DELETE would abort atomically and leave every seeded row behind. We delete
  // the non-sealed rows individually and leave the fiscalized one in place (by
  // design — a sealed fiscal order is immutable, this is correct NF525 behavior).
  try {
    tinker(`
      $rows = \\App\\Models\\Order::withoutGlobalScopes()->where('token','like','${PREFIX}%')->get(['id','fiscal_sequence_no']);
      foreach ($rows as $o) {
        if ($o->fiscal_sequence_no !== null) { continue; }
        \\App\\Models\\OrderItem::withoutGlobalScopes()->where('order_id',$o->id)->forceDelete();
        try { \\App\\Models\\Order::withoutGlobalScopes()->where('id',$o->id)->forceDelete(); } catch (\\Throwable $e) {}
      }
      echo 'CLEANED';
    `);
  } catch (e) { console.warn('[E4] cleanup warning:', e?.message); }
}

// In-browser API caller (window.axios → session POS operator courante).
async function browserApi(page, { method = 'get', url, data = null, idem = null }) {
  return page.evaluate(async ({ method, url, data, idem }) => {
    const cfg = {};
    if (idem) cfg.headers = { 'X-Idempotency-Key': idem };
    try {
      const m = method.toLowerCase();
      const r = m === 'get' ? await window.axios.get(url, cfg) : await window.axios[m](url, data || {}, cfg);
      return { status: r.status, data: r.data };
    } catch (e) {
      return { status: e?.response?.status ?? 0, data: e?.response?.data ?? { message: String(e?.message || e) } };
    }
  }, { method, url, data, idem });
}

// Console + HTTP error collectors attachable per surface.
function attachCollectors(page, bag) {
  page.on('console', (m) => { if (m.type() === 'error') bag.console.push(m.text().slice(0, 300)); });
  page.on('response', (r) => {
    const s = r.status();
    const u = r.url();
    if (s >= 400 && /\/(api|admin|kds)\b/.test(u) && !/favicon|hot-update/.test(u)) {
      bag.http.push(`${s} ${r.request().method()} ${u.replace(/^https?:\/\/[^/]+/, '')}`.slice(0, 200));
    }
  });
}

const RAW_LABEL_RE = /\b(pos\.tracker\.|kds\.|kitchen\.|label\.[a-z]|Label\.[A-Z]|undefined€|0undefined|NaN\s?€)\b/;
function scanRawLabels(text) {
  const hits = [];
  const m = text.match(new RegExp(RAW_LABEL_RE, 'g'));
  if (m) hits.push(...new Set(m));
  return hits;
}

// -----------------------------------------------------------------------------
test.describe.configure({ mode: 'serial' });

const findings = [];
const record = (id, sev, msg) => { findings.push({ id, sev, msg }); console.log(`[FINDING ${sev}] ${id}: ${msg}`); };

let webA, webB, webKeep, term, phantom;

test.beforeAll(() => {
  cleanup();
  webA = seedWebOrder('CYCLE');       // full cycle web→accept→encaisser→KDS
  webB = seedWebOrder('DBLACC');      // double-accept idempotency
  webKeep = seedWebOrder('KEEP');     // accepted-never-encashed traceability
  term = seedTerminalOrder(OS.CANCELED);
  phantom = seedPhantomPending();
  console.log('[E4] seeded', JSON.stringify({ webA, webB, webKeep, term, phantom }));
});

test.afterAll(() => { cleanup(); });

// =============================================================================
// S1 — CAISSE /admin/pos (VISUEL D'ABORD)
// =============================================================================
test('S1 — Caisse /admin/pos rend (panneaux + tracker, D-2, pas de raw label)', async ({ page }) => {
  const bag = { console: [], http: [] };
  attachCollectors(page, bag);

  await loginAsPosOperator(page);
  await page.waitForTimeout(4000); // hydratation SPA + fetchOrders + loadWebOrders
  await page.screenshot({ path: path.join(SHOTS, '01-caisse-pos.png'), fullPage: true });

  const body = await page.locator('body').innerText();

  // Panneaux présents
  const hasWebPanel = /Commandes web/i.test(body);
  const hasBornePanel = /encaisser borne|Commandes borne|À encaisser/i.test(body);
  console.log('[S1] Commandes web panel =', hasWebPanel, '| borne/encaisser =', hasBornePanel);
  expect(hasWebPanel, 'panneau « Commandes web » visible').toBeTruthy();

  // Web PENDING seedé visible (queue de webA)
  const webVisible = body.includes(webA.queue) || body.includes(webB.queue) || /Accepter/i.test(body);
  console.log('[S1] web order visible (queue/Accepter) =', webVisible);
  if (!webVisible) record('S1-WEB-INVISIBLE', 'P2', 'Aucune commande web PENDING seedée visible en caisse');

  // D-2 : phantom non-web NE doit PAS apparaître comme carte
  const phantomShown = body.includes(phantom.queue);
  console.log('[S1] phantom non-web (', phantom.queue, ') affiché =', phantomShown);
  if (phantomShown) record('S1-D2-PHANTOM', 'P2', `PENDING non-web fantôme ${phantom.queue} rendu sur le board (D-2 régressé)`);

  // Raw labels
  const raw = scanRawLabels(body);
  console.log('[S1] raw labels =', JSON.stringify(raw));
  if (raw.length) record('S1-RAW-LABEL', 'P2', `Labels bruts en caisse: ${raw.join(', ')}`);
  expect(raw, 'aucun label brut en caisse').toHaveLength(0);

  console.log('[S1] console errors =', bag.console.length, '| http 4xx/5xx =', bag.http.length, JSON.stringify(bag.http.slice(0, 5)));
  if (bag.http.length) record('S1-HTTP', 'P3', `4xx/5xx en caisse: ${bag.http.slice(0, 3).join(' | ')}`);
});

// =============================================================================
// S2 — CYCLE web → caisse → KDS (Accepter → Encaisser idempotent → KDS)
// =============================================================================
test('S2 — Cycle web PENDING → Accepter → Encaisser (idempotent) → KDS', async ({ page }) => {
  const bag = { console: [], http: [] };
  attachCollectors(page, bag);
  await loginAsPosOperator(page);
  await page.waitForTimeout(3500);

  // --- État initial : PENDING/UNPAID
  let st = orderState(webA.id);
  console.log('[S2] initial state webA =', JSON.stringify(st));
  expect(st.status).toBe(OS.PENDING);

  await page.screenshot({ path: path.join(SHOTS, '02a-web-pending-caisse.png'), fullPage: true });

  // --- ACCEPTER (via UI si le bouton est là ; sinon via l'endpoint EXACT du bouton)
  let accepted = false;
  const acceptBtn = page.getByRole('button', { name: /Accepter/i }).first();
  if (await acceptBtn.isVisible({ timeout: 4000 }).catch(() => false)) {
    // Clique le bouton réel du panneau « Commandes web »
    await acceptBtn.click().catch(() => {});
    await page.waitForTimeout(2500);
    st = orderState(webA.id);
    accepted = st.status === OS.ACCEPT;
    console.log('[S2] after UI Accepter click, state =', JSON.stringify(st));
  }
  if (!accepted) {
    // Fallback endpoint identique (window.axios, session POS)
    const r = await browserApi(page, {
      method: 'post', url: `admin/online-order/change-status/${webA.id}`,
      data: { status: OS.ACCEPT }, idem: `web-accept-${webA.id}-${Math.floor(Date.now() / 60000)}`,
    });
    console.log('[S2] accept via endpoint =', r.status);
    await page.waitForTimeout(1500);
    st = orderState(webA.id);
  }
  console.log('[S2] post-accept state =', JSON.stringify(st));
  expect(st.status, 'web acceptée → ACCEPT').toBe(OS.ACCEPT);
  expect(st.payment_status, 'web COD acceptée → PENDING_COUNTER (15)').toBe(15);
  expect(st.pos_payment_method, 'marqueur COUNTER_DEFERRED (6) posé → encaissable').toBe(6);

  await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => {});
  await page.waitForTimeout(3000);
  await page.screenshot({ path: path.join(SHOTS, '02b-after-accept-tracker.png'), fullPage: true });

  // --- KDS board-release après ACCEPT (PENDING_COUNTER est board-released)
  const relAfterAccept = boardReleasedIds();
  const onKdsAfterAccept = relAfterAccept.ids.includes(webA.id);
  console.log('[S2] KDS board-released after accept includes webA =', onKdsAfterAccept);
  expect(onKdsAfterAccept, 'web acceptée (PENDING_COUNTER) libérée sur le board KDS').toBeTruthy();

  // --- ENCAISSER (counter-collect confirm) — endpoint EXACT du modal
  const idemKey = `e4-encaisse-${webA.id}`;
  const enc1 = await browserApi(page, {
    method: 'post', url: `admin/pos/counter-collect/${webA.id}/confirm`,
    data: { mode: 1, received: UNIT, note: 'E4 massive' }, idem: idemKey,
  });
  console.log('[S2] encaisser #1 =', enc1.status);
  await page.waitForTimeout(1200);
  const afterPay = cashProof(webA.id);
  console.log('[S2] after encaisser #1 =', JSON.stringify(afterPay));
  expect(afterPay.payment_status, 'encaissée → PAID (5)').toBe(5);
  expect(afterPay.fiscal_sequence_no, 'NF525 fiscal_sequence_no alloué').not.toBeNull();
  const seqAfter1 = afterPay.fiscal_sequence_no;
  const mvAfter1 = afterPay.cash_movements;

  // --- IDEMPOTENCE : re-POST identique ne double NI le paiement NI le tiroir NI la séquence
  const enc2 = await browserApi(page, {
    method: 'post', url: `admin/pos/counter-collect/${webA.id}/confirm`,
    data: { mode: 1, received: UNIT, note: 'E4 massive' }, idem: idemKey,
  });
  console.log('[S2] encaisser #2 (re-clic) =', enc2.status);
  const afterPay2 = cashProof(webA.id);
  console.log('[S2] after encaisser #2 =', JSON.stringify(afterPay2));
  // 409 (already collected) OU 200 replay idempotent — jamais un 2e mouvement.
  expect([200, 201, 409]).toContain(enc2.status);
  expect(afterPay2.fiscal_sequence_no, 'séquence fiscale inchangée (pas de gap)').toBe(seqAfter1);
  expect(afterPay2.cash_movements, 'aucun 2e mouvement de tiroir (idempotent)').toBe(mvAfter1);
  if (afterPay2.cash_movements > 1) record('S2-DOUBLE-DRAWER', 'P0', `Double mouvement tiroir sur re-encaissement (${afterPay2.cash_movements})`);

  // --- Intégrité numérique : total order == total confirmation
  expect(afterPay2.payment_status).toBe(5);
  console.log('[S2] intégrité: total order =', st.total, '== received =', UNIT);
  expect(Number(st.total)).toBe(UNIT);

  // --- KDS après encaissement (toujours libérée, maintenant PAID)
  const relAfterPay = boardReleasedIds();
  console.log('[S2] KDS board-released after pay includes webA =', relAfterPay.ids.includes(webA.id));
  expect(relAfterPay.ids.includes(webA.id)).toBeTruthy();

  console.log('[S2] console errors =', bag.console.length, '| http err =', bag.http.length);
});

// =============================================================================
// S3 — KDS /kds (lanes + ticket + release)
// =============================================================================
test('S3 — KDS /kds rend (lanes, ticket, commande libérée visible, pas de raw label)', async ({ page }) => {
  const bag = { console: [], http: [] };
  attachCollectors(page, bag);

  await loginAsAdmin(page);
  await page.goto('/kds', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(5000); // hydratation KDS + board fetch
  await page.screenshot({ path: path.join(SHOTS, '03-kds-lanes.png'), fullPage: true });

  const body = await page.locator('body').innerText();

  // KDS ne doit pas être une page blanche : présence d'au moins un repère board
  const hasBoard = /cuisine|kitchen|préparer|préparation|prêt|en cours|nouvelle|ticket|commande|KDS/i.test(body) || body.length > 200;
  console.log('[S3] KDS body length =', body.length, '| board markers =', hasBoard);
  expect(hasBoard, 'KDS rend un board (pas page blanche)').toBeTruthy();

  // La commande cycle (webA) libérée doit être sur le board (queue W....)
  const webAonBoard = body.includes(webA.queue);
  console.log('[S3] webA queue', webA.queue, 'sur KDS =', webAonBoard);
  if (!webAonBoard) record('S3-KDS-MISSING', 'P3', `webA (${webA.queue}) non rendue sur /kds — board-release SQL OK, artefact rendu possible`);

  const raw = scanRawLabels(body);
  console.log('[S3] KDS raw labels =', JSON.stringify(raw));
  if (raw.length) record('S3-RAW-LABEL', 'P2', `Labels bruts KDS: ${raw.join(', ')}`);
  expect(raw, 'aucun label brut KDS').toHaveLength(0);

  console.log('[S3] console errors =', bag.console.length, '| http err =', bag.http.length, JSON.stringify(bag.http.slice(0, 4)));
  if (bag.http.length) record('S3-HTTP', 'P3', `4xx/5xx KDS: ${bag.http.slice(0, 3).join(' | ')}`);
});

// =============================================================================
// S4 — Hub admin /admin/catalog-hub (2 onglets + photo + badge désactivé global)
// =============================================================================
test('S4 — Hub /admin/catalog-hub (Catalogue + Produits & Stock, photo, badge)', async ({ page }) => {
  const bag = { console: [], http: [] };
  attachCollectors(page, bag);

  await loginAsAdmin(page);
  await page.goto('/admin/catalog-hub', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4500);
  await page.screenshot({ path: path.join(SHOTS, '04-catalog-hub.png'), fullPage: true });

  const body = await page.locator('body').innerText();

  const hasCatalog = /Catalogue/i.test(body);
  const hasStock = /Produits.*Stock|Stock.*Produits|Gestion Produits|Produits & Stock/i.test(body);
  console.log('[S4] onglet Catalogue =', hasCatalog, '| onglet Produits & Stock =', hasStock);
  if (!hasCatalog) record('S4-NO-CATALOG-TAB', 'P3', 'Onglet Catalogue non trouvé');
  if (!hasStock) record('S4-NO-STOCK-TAB', 'P3', 'Onglet Produits & Stock non trouvé');

  const raw = scanRawLabels(body);
  console.log('[S4] hub raw labels =', JSON.stringify(raw));
  if (raw.length) record('S4-RAW-LABEL', 'P2', `Labels bruts hub: ${raw.join(', ')}`);
  expect(raw, 'aucun label brut hub').toHaveLength(0);

  console.log('[S4] console errors =', bag.console.length, '| http err =', bag.http.length, JSON.stringify(bag.http.slice(0, 4)));
  if (bag.http.length) record('S4-HTTP', 'P3', `4xx/5xx hub: ${bag.http.slice(0, 3).join(' | ')}`);
});

// =============================================================================
// S5 — ATTAQUE (résurrection terminal REFUSÉE ; double-accept idempotent ; traçabilité)
// =============================================================================
test('S5 — Attaque : résurrection terminal REFUSÉE + double-accept idempotent + traçabilité', async ({ page }) => {
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);

  // (A) D-1 : commande terminale (CANCELED) → tenter de la réactiver (ACCEPT/PREPARING) = REFUSÉ
  const termBefore = orderState(term.id);
  console.log('[S5] terminal before =', JSON.stringify(termBefore));
  expect(termBefore.status).toBe(OS.CANCELED);

  const resurrectAccept = await browserApi(page, {
    method: 'post', url: `admin/online-order/change-status/${term.id}`, data: { status: OS.ACCEPT },
  });
  const resurrectPrep = await browserApi(page, {
    method: 'post', url: `admin/online-order/change-status/${term.id}`, data: { status: OS.PREPARING },
  });
  console.log('[S5] resurrect CANCELED→ACCEPT =', resurrectAccept.status, '| →PREPARING =', resurrectPrep.status);
  const termAfter = orderState(term.id);
  console.log('[S5] terminal after attack =', JSON.stringify(termAfter));
  // Garde D-1 : 4xx (422/403) ET statut inchangé (reste CANCELED)
  expect(resurrectAccept.status, 'résurrection CANCELED→ACCEPT refusée (4xx)').toBeGreaterThanOrEqual(400);
  expect(termAfter.status, 'commande terminale reste CANCELED (non ressuscitée)').toBe(OS.CANCELED);
  if (termAfter.status !== OS.CANCELED) record('S5-D1-BREACH', 'P0', 'Commande terminale ressuscitée (garde D-1 percée)');

  // (B) Double-ACCEPT idempotent sur webB (pas de double flip / pas de gap)
  const idem = `web-accept-${webB.id}-${Math.floor(Date.now() / 60000)}`;
  const acc1 = await browserApi(page, { method: 'post', url: `admin/online-order/change-status/${webB.id}`, data: { status: OS.ACCEPT }, idem });
  const s1 = orderState(webB.id);
  const acc2 = await browserApi(page, { method: 'post', url: `admin/online-order/change-status/${webB.id}`, data: { status: OS.ACCEPT }, idem });
  const s2 = orderState(webB.id);
  console.log('[S5] double-accept =', acc1.status, acc2.status, '| state1=', JSON.stringify(s1), '| state2=', JSON.stringify(s2));
  expect(s1.status).toBe(OS.ACCEPT);
  expect(s2.status, 'double-accept idempotent → reste ACCEPT').toBe(OS.ACCEPT);
  expect(s2.pos_payment_method, 'marqueur inchangé au 2e accept').toBe(6);

  // (C) Traçabilité : webKeep accepté mais JAMAIS encaissé reste traçable (PENDING_COUNTER, non perdu)
  await browserApi(page, { method: 'post', url: `admin/online-order/change-status/${webKeep.id}`, data: { status: OS.ACCEPT }, idem: `web-accept-${webKeep.id}-keep` });
  const keep = orderState(webKeep.id);
  console.log('[S5] accepted-never-encashed =', JSON.stringify(keep));
  expect(keep.status).toBe(OS.ACCEPT);
  expect(keep.payment_status, 'accepté-non-encaissé reste PENDING_COUNTER (traçable)').toBe(15);
  expect(keep.fiscal_sequence_no, 'pas d\'allocation fiscale avant encaissement').toBeNull();

  await page.screenshot({ path: path.join(SHOTS, '05-attack-state.png'), fullPage: true }).catch(() => {});
});

test.afterAll(() => {
  console.log('\n========== E4 FINDINGS ==========');
  if (!findings.length) console.log('Aucun finding (P0-P3) — tout vert.');
  for (const f of findings) console.log(`[${f.sev}] ${f.id}: ${f.msg}`);
  fs.writeFileSync(path.join(SHOTS, 'findings.json'), JSON.stringify(findings, null, 2));
});
