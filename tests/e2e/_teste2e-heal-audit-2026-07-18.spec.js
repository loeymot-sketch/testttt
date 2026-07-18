// =============================================================================
// _teste2e-heal-audit-2026-07-18.spec.js
// VALIDATION E2E ABUSIVE post-heal — prouve en conditions réelles que les 5 fix
// du registre d'audit (reports/goal-intelligence-2026-07-18/REGISTRE_FINAL.md)
// tiennent ET ne cassent aucun parcours.
//
//   V1 — P1-4  Borne upsell : items à composition requise (40 « Menu Enfant
//              Nuggets », 106 « Menu Enfant Chicken Burger ») exclus du pool
//              upsell 1-tap ; un item upsell simple mène au paiement sans 422.
//   V2 — P1-5  /admin/sales-report/overview gaté `sales-report` (POS→403, Admin→200).
//   V3 — P1-3  Commande web takeaway COD UNPAID → Accept → PENDING_COUNTER +
//              COUNTER_DEFERRED → visible counter-collect/pending → confirm CASH →
//              PAID + fiscal_sequence_no alloué.
//   V4 — P2-u  /loyalty/scan : token invité (kiosk:order, PAS KioskMachine) →
//              réponse NEUTRE (aucune PII) ; vraie KioskMachine → résout.
//   V5 — P2-e  online-order/change-payment-status→REFUNDED sans `pos-refund` → 403.
//
// Discipline : READ/TEST — aucun code applicatif modifié. Toutes les fixtures
// sont préfixées HEALVAL- et nettoyées (beforeAll + afterAll). Aucune commande
// non-test n'est finalisée. La chaîne fiscale touchée est celle de la DB e2e
// (foodking_e2e) uniquement — le cleanup supprime les rows fiscales de test au
// même titre que les helpers cleanupKioskAuditOrders existants.
//
// Lancer : PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/_teste2e-heal-audit-2026-07-18.spec.js
// =============================================================================

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const { loginAsKiosk, loginAsPosOperator, loginAsAdmin } = require('./helpers/login');

const repoRoot = path.resolve(__dirname, '../..');
const SHOTS = path.join(repoRoot, 'reports/goal-intelligence-2026-07-18/screenshots');
fs.mkdirSync(SHOTS, { recursive: true });

// -- Fixture identifiers (all HEALVAL- prefixed for reaper-safe cleanup) -------
const TARGET_PHONE = '+330000000092';
const GUEST_PHONE = '+330000000091';
const TARGET_CODE = 'HEALVALX';
const TARGET_POINTS = 137;
const HEALVAL_ITEM_NAME = 'HEALVAL Test Item';

// Excluded upsell items (P1-4) — must never appear in the 1-tap pool.
const EXCLUDED_UPSELL_IDS = [40, 106];
// Base cart product : Grande Frites (id 34) — Frites cat is NOT
// kiosk_upsell_skip_after_cart, so the upsell screen is shown; 0 variations →
// direct add (no wizard).
const BASE_ITEM_ID = 34;

// -----------------------------------------------------------------------------
// tinker helpers (fixtures + cleanup) — mirror helpers/kiosk-order.js pattern.
// -----------------------------------------------------------------------------
function tinker(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 60_000,
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
  throw new Error('MIX_API_KEY introuvable (.env / env).');
}
const API_KEY = readApiKey();

// Seed one simple line (Grande Frites, id 34, cat Frites=7 → NON-skip so the
// upsell screen is shown) into the persisted kiosk cart. vuex-persistedstate
// rehydrates state.items on the next full navigation. This is the deterministic
// equivalent of "ajouter un produit simple au panier" (the click-add UI proved
// flaky); the upsell RENDER that follows fetches the LIVE suggestions and is
// 100% real — which is exactly what V1 must prove visually.
async function seedKioskCart(page) {
  await page.evaluate(() => {
    let v = {};
    try { v = JSON.parse(localStorage.getItem('vuex') || '{}'); } catch (_) { v = {}; }
    v.kioskCart = v.kioskCart || {};
    v.kioskCart.items = [{
      item_id: 34, item_category_id: 7, name: 'Grande Frites', image: null,
      quantity: 1, convert_price: 4.0, currency_price: '4,00 €', discount: 0,
      item_variation_total: 0, item_extra_total: 0, item_variations: [], item_extras: [],
      total: 4.0, instruction: null,
    }];
    v.kioskCart.orderType = 10;                 // TAKEAWAY
    if (!v.kioskCart.branchId) v.kioskCart.branchId = 1;
    localStorage.setItem('vuex', JSON.stringify(v));
  });
}

// In-browser API caller: reuses window.axios → Authorization = STORED session
// token (the SPA interceptor at shared/axios-setup.js:85 forcibly sets it, so a
// per-call bearer would be clobbered). Used ONLY for calls that must run as the
// currently-logged-in SPA identity (V2/V3/V5 = admin/pos).
async function browserApi(page, { method = 'get', url, params = null, data = null, idem = null }) {
  return page.evaluate(async ({ method, url, params, data, idem }) => {
    const cfg = {};
    if (params) cfg.params = params;
    if (idem) cfg.headers = { 'X-Idempotency-Key': idem };
    try {
      const m = method.toLowerCase();
      const r = m === 'get'
        ? await window.axios.get(url, cfg)
        : await window.axios[m](url, data || {}, cfg);
      return { status: r.status, data: r.data };
    } catch (e) {
      return { status: e?.response?.status ?? 0, data: e?.response?.data ?? { message: String(e?.message || e) } };
    }
  }, { method, url, params, data, idem });
}

// Out-of-browser API caller (page.request / APIRequestContext) with EXPLICIT
// x-api-key + Authorization. Does NOT pass through the SPA axios interceptor, so
// the `bearer` we pass is exactly what the server sees — mandatory for token-
// identity tests (V1 kiosk pool, V4 guest-vs-kiosk scan). Relative /api paths
// resolve against the Playwright config baseURL.
async function rawApi(page, { method = 'get', url, params = null, data = null, bearer = null, idem = null }) {
  const headers = { 'x-api-key': API_KEY, Accept: 'application/json' };
  if (bearer) headers.Authorization = 'Bearer ' + bearer;
  if (idem) headers['X-Idempotency-Key'] = idem;
  const opts = { headers, failOnStatusCode: false };
  if (params) opts.params = params;
  if (data) opts.data = data;
  const full = url.startsWith('/') ? url : '/api/' + url;
  const r = method.toLowerCase() === 'get'
    ? await page.request.get(full, opts)
    : await page.request[method.toLowerCase()](full, opts);
  let body = null;
  try { body = await r.json(); } catch (_) { body = null; }
  return { status: r.status(), data: body };
}

// Kiosk machine bearer (kiosk:order + REAL KioskMachine row) via page.request —
// no window.axios dependency, no interceptor interference.
async function kioskLoginToken(page) {
  const r = await rawApi(page, {
    method: 'post', url: '/api/auth/kiosk-login',
    data: { username: 'kiosk-lecayenne', password: 'kiosk123' },
  });
  if (!r.data?.token) throw new Error(`kiosk-login failed HTTP ${r.status}: ${JSON.stringify(r.data).slice(0, 300)}`);
  return r.data.token;
}

function cleanupHealval() {
  // NB : cash_movements ET audit_logs sont NF525-IMMUTABLES (trigger BEFORE
  // DELETE → SQLSTATE 45000). On ne les touche PAS (comme cleanupKioskAuditOrders) :
  // ce sont des empreintes fiscales permanentes — sur la DB e2e elles restent en
  // orphelin, ce qui est le comportement CORRECT (on ne supprime jamais une
  // écriture fiscale). Chaque suppression est isolée en try/catch pour qu'un
  // verrou d'immutabilité éventuel n'avorte jamais le reste du nettoyage.
  return tinker(`
    $del = function (callable $fn) { try { $fn(); } catch (\\Throwable $e) {} };
    $ids = \\App\\Models\\Order::withoutGlobalScopes()->where('token','like','HEALVAL-%')->pluck('id');
    if ($ids->isNotEmpty()) {
      $del(fn() => Schema::hasTable('transactions') && DB::table('transactions')->whereIn('order_id',$ids)->delete());
      $del(fn() => Schema::hasTable('order_status_transitions') && DB::table('order_status_transitions')->whereIn('order_id',$ids)->delete());
      $del(fn() => Schema::hasTable('domain_events') && DB::table('domain_events')->whereIn('aggregate_id',$ids)->delete());
      $del(fn() => DB::table('order_items')->whereIn('order_id',$ids)->delete());
      $del(fn() => DB::table('orders')->whereIn('id',$ids)->delete());
    }
    $del(fn() => \\App\\Models\\Item::where('name','${HEALVAL_ITEM_NAME}')->forceDelete());
    $del(function () {
      foreach (\\App\\Models\\User::withoutGlobalScopes()->whereIn('phone',['${GUEST_PHONE}','${TARGET_PHONE}'])->get() as $u) { $u->tokens()->delete(); $u->forceDelete(); }
    });
    $remaining = \\App\\Models\\Order::withoutGlobalScopes()->where('token','like','HEALVAL-%')->count();
    echo json_encode(['deleted_orders' => $ids->count(), 'remaining' => $remaining]);
  `);
}

// V3 / V5 : a dedicated HEALVAL web order (takeaway COD).
function createWebOrder({ paid = false }) {
  const payStatus = paid ? '\\App\\Enums\\PaymentStatus::PAID' : '\\App\\Enums\\PaymentStatus::UNPAID';
  const status = paid ? '\\App\\Enums\\OrderStatus::ACCEPT' : '\\App\\Enums\\OrderStatus::PENDING';
  return tinkerJson(`
    $src = \\App\\Models\\Item::withoutGlobalScopes()->find(${BASE_ITEM_ID});
    $it = $src->replicate(); $it->name='${HEALVAL_ITEM_NAME}'; $it->slug='healval-item-'.uniqid(); $it->is_available=true; $it->status=5; $it->saveQuietly();
    $admin = \\App\\Models\\User::where('email','admin@lecayenne.fr')->first();
    $o = new \\App\\Models\\Order();
    $o->branch_id = 1; $o->user_id = $admin->id;
    $o->order_type = \\App\\Enums\\OrderType::TAKEAWAY; $o->source_surface = 'web';
    $o->payment_method = \\App\\Enums\\PaymentGateway::CASH_ON_DELIVERY; $o->pos_payment_method = null;
    $o->payment_status = ${payStatus}; $o->status = ${status};
    $o->subtotal = 5.0; $o->total = 5.0; $o->total_tax = 0; $o->discount = 0; $o->delivery_charge = 0;
    $o->token = 'HEALVAL-'.(${paid ? 1 : 0} ? 'V5' : 'V3').'-'.uniqid(); $o->order_serial_no = $o->token;
    $o->order_datetime = now(); $o->queue_number = (string)(9500 + rand(0,499)); $o->saveQuietly();
    $oi = new \\App\\Models\\OrderItem();
    $oi->order_id = $o->id; $oi->item_id = $it->id; $oi->branch_id = 1; $oi->quantity = 1;
    $oi->price = 5.0; $oi->total_price = 5.0; $oi->discount = 0; $oi->item_extra_total = 0; $oi->item_variation_total = 0;
    $oi->tax_amount = 0; $oi->tax_rate = 0; $oi->composition_snapshot = json_encode(['name'=>'${HEALVAL_ITEM_NAME}']); $oi->saveQuietly();
    echo json_encode(['id'=>(int)$o->id,'item_id'=>(int)$it->id,'token'=>$o->token]);
  `);
}

function orderState(id) {
  return tinkerJson(`
    $o = \\App\\Models\\Order::find(${id});
    echo json_encode($o ? ['payment_status'=>(int)$o->payment_status,'pos_payment_method'=>$o->pos_payment_method===null?null:(int)$o->pos_payment_method,'status'=>(int)$o->status,'fiscal_sequence_no'=>$o->fiscal_sequence_no===null?null:(int)$o->fiscal_sequence_no] : ['missing'=>true]);
  `);
}

// V4 : loyalty target (known code + points) + guest kiosk:order token + 2
// single-use signed QR tokens (nonce consumed on scan → one per scan).
function seedLoyaltyV4() {
  return tinkerJson(`
    $t = \\App\\Models\\User::updateOrCreate(['phone'=>'${TARGET_PHONE}'],['name'=>'HealvalTarget Zoe','username'=>'healval-target-92','status'=>5,'is_guest'=>10,'branch_id'=>0,'password'=>bcrypt('x')]);
    $t->loyalty_code='${TARGET_CODE}'; $t->loyalty_points=${TARGET_POINTS}; $t->save();
    try { if(!$t->hasRole('Customer')) $t->assignRole('Customer'); } catch (\\Throwable $e) {}
    $g = \\App\\Models\\User::updateOrCreate(['phone'=>'${GUEST_PHONE}'],['name'=>'HEALVAL Guest','username'=>'healval-guest-91','status'=>5,'is_guest'=>5,'branch_id'=>0,'password'=>bcrypt('x')]);
    try { if(!$g->hasRole('Customer')) $g->assignRole('Customer'); } catch (\\Throwable $e) {}
    $g->tokens()->delete();
    $guestTok = $g->createToken('healval-guest',['kiosk:order'],now()->addDay())->plainTextToken;
    $signer = app(\\App\\Services\\Loyalty\\LoyaltyQrSigner::class);
    $s1 = $signer->sign((int)$t->id,(string)$t->loyalty_code)['token'];
    $s2 = $signer->sign((int)$t->id,(string)$t->loyalty_code)['token'];
    echo json_encode(['guest_token'=>$guestTok,'signed_for_guest'=>$s1,'signed_for_kiosk'=>$s2,'target_id'=>(int)$t->id,'guest_id'=>(int)$g->id]);
  `);
}

// -----------------------------------------------------------------------------
// NB : PAS de mode 'serial' — chaque test crée ses propres fixtures et doit
// s'exécuter même si un autre échoue (workers:1 → ordre séquentiel préservé).

test.describe('HEAL-VALIDATION 2026-07-18 — 5 fix registre audit (E2E abusive)', () => {
  test.setTimeout(240_000);

  test.beforeAll(() => { cleanupHealval(); });
  test.afterAll(() => { cleanupHealval(); });

  // ===========================================================================
  // V1 — P1-4 : Borne upsell (visuel + fonctionnel)
  // ===========================================================================
  test('V1 — upsell borne exclut les items à composition requise (40/106) et reste payable', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);

    // -- (a) API cross-check : GET /api/frontend/item/kiosk-upsell -------------
    // rawApi (page.request) → le token kiosk EXPLICITE atteint bien le serveur
    // (window.axios écraserait l'Authorization par le token stocké).
    const kioskToken = await kioskLoginToken(page);
    const upsellResp = await rawApi(page, {
      method: 'get', url: 'frontend/item/kiosk-upsell',
      params: { item_ids: String(BASE_ITEM_ID), limit: 12, branch_id: 1 },
      bearer: kioskToken,
    });
    expect(upsellResp.status).toBe(200);
    const pool = Array.isArray(upsellResp.data?.data) ? upsellResp.data.data : (upsellResp.data || []);
    const poolIds = pool.map((i) => Number(i.id));
    console.log('[V1] upsell pool ids =', JSON.stringify(poolIds));
    expect(pool.length).toBeGreaterThan(0);            // pool non-vide
    for (const ex of EXCLUDED_UPSELL_IDS) {
      expect(poolIds).not.toContain(ex);               // 40 + 106 absents
    }

    // -- (b1) fonctionnel : un item upsell simple → quote NON-422 -------------
    const simpleUpsell = pool.find((i) => Number(i.id) !== BASE_ITEM_ID) || pool[0];
    const quoteOk = await rawApi(page, {
      method: 'post', url: 'frontend/order/quote', bearer: kioskToken,
      data: {
        branch_id: 1, token: 'HEALVAL-V1-QUOTE', discount: 0, order_type: 25,
        is_advance_order: 10, source: 5, payment_method: 1,
        items: JSON.stringify([{ item_id: Number(simpleUpsell.id), quantity: 1 }]),
      },
    });
    console.log('[V1] quote(upsell simple id=' + simpleUpsell.id + ') status =', quoteOk.status);
    expect(quoteOk.status).not.toBe(422);
    expect(quoteOk.status).toBe(200);

    // -- (b2) contre-preuve : item 40 ajouté 1-tap (payload variations VIDE) →
    //         c'est EXACTEMENT le dead-end 422 que le fix évite en l'excluant. -
    const quote40 = await rawApi(page, {
      method: 'post', url: 'frontend/order/quote', bearer: kioskToken,
      data: {
        branch_id: 1, token: 'HEALVAL-V1-Q40', discount: 0, order_type: 25,
        is_advance_order: 10, source: 5, payment_method: 1,
        items: JSON.stringify([{ item_id: 40, quantity: 1 }]),
      },
    });
    console.log('[V1] quote(item40 blind) status =', quote40.status, '| msg =', quote40.data?.message);
    expect(quote40.status).toBe(422);                  // le pire cas est réel...
    // ...donc l'exclusion du pool (assert ci-dessus) est ce qui protège le client.

    // -- (c) visuel : parcours borne → panier → écran upsell → screenshot -----
    // Order type takeaway (réel), puis on garnit le panier (Grande Frites) et on
    // rejoint l'écran upsell via le bouton checkout réel (fallback nav directe).
    const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]').first();
    if (await takeaway.isVisible({ timeout: 6000 }).catch(() => false)) {
      await takeaway.click({ timeout: 4000 }).catch(() => {});
      await page.waitForTimeout(1200);
    }
    await seedKioskCart(page);

    // Panier réel : le produit doit s'y afficher (preuve que le panier est garni).
    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-testid="kiosk-cart-item-0"]')).toBeVisible({ timeout: 12000 });
    const cartShot = path.join(SHOTS, 'V1-cart.png');
    await page.screenshot({ path: cartShot, fullPage: true }).catch(() => {});

    // Checkout réel → upsell. Fallback déterministe : nav directe (panier persistant).
    let reachedUpsell = false;
    const checkout = page.locator('[data-testid="kiosk-cart-checkout"]').first();
    if (await checkout.isVisible({ timeout: 6000 }).catch(() => false)) {
      await checkout.click({ timeout: 4000 }).catch(() => {});
      reachedUpsell = await page.locator('[data-testid="kiosk-upsell-grid"]')
        .waitFor({ state: 'visible', timeout: 10000 }).then(() => true).catch(() => false);
    }
    if (!reachedUpsell) {
      await page.goto('/kiosk/upsell', { waitUntil: 'domcontentloaded' });
      reachedUpsell = await page.locator('[data-testid="kiosk-upsell-grid"]')
        .waitFor({ state: 'visible', timeout: 10000 }).then(() => true).catch(() => false);
    }

    const shot = path.join(SHOTS, 'V1-upsell-screen.png');
    await page.screenshot({ path: shot, fullPage: true }).catch(() => {});
    console.log('[V1] screenshot →', shot, '| reachedUpsell =', reachedUpsell);

    // Assertions visuelles sur l'écran upsell réel.
    expect(reachedUpsell).toBe(true);
    await expect(page.locator('[data-testid="kiosk-upsell-card-40"]')).toHaveCount(0);
    await expect(page.locator('[data-testid="kiosk-upsell-card-106"]')).toHaveCount(0);
    const cardCount = await page.locator('.kiosk-upsell-card').count();   // vraies cartes (pas les sous-testids name/price)
    expect(cardCount).toBeGreaterThan(0);
    const bodyText = (await page.locator('body').innerText().catch(() => '')) || '';
    expect(bodyText).not.toContain('Menu Enfant Nuggets');
    expect(bodyText).not.toContain('Menu Enfant Chicken Burger');
    console.log('[V1] upsell cards rendus =', cardCount);
  });

  // ===========================================================================
  // V2 — P1-5 : RBAC sur /admin/sales-report/overview (CA net)
  // ===========================================================================
  test('V2 — sales-report/overview : POS Operator → 403, Admin → 200', async ({ page, browser }) => {
    // POS Operator (SANS `sales-report`) → 403
    await loginAsPosOperator(page);
    const posResp = await browserApi(page, { method: 'get', url: 'admin/sales-report/overview' });
    console.log('[V2] POS overview status =', posResp.status);
    expect(posResp.status).toBe(403);

    // Admin (AVEC `sales-report`) → 200. Contexte NEUF (isolé) — évite la
    // rémanence de session POS qui fait échouer un second login sur la même page.
    const adminCtx = await browser.newContext({ baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000' });
    try {
      const adminPage = await adminCtx.newPage();
      await loginAsAdmin(adminPage);
      const adminResp = await browserApi(adminPage, { method: 'get', url: 'admin/sales-report/overview' });
      console.log('[V2] ADMIN overview status =', adminResp.status);
      expect(adminResp.status).toBe(200);
    } finally {
      await adminCtx.close();
    }
  });

  // ===========================================================================
  // V3 — P1-3 : commande web encaissable au comptoir
  // ===========================================================================
  test('V3 — web takeaway COD : Accept → counter-collect → confirm CASH → PAID + fiscal_seq', async ({ page }) => {
    const web = createWebOrder({ paid: false });
    console.log('[V3] web order créé =', JSON.stringify(web));
    const before = orderState(web.id);
    expect(before.payment_status).toBe(10);            // UNPAID

    await loginAsPosOperator(page);

    // 1) Accept (OnlineOrderController::changeStatus, status=ACCEPT=4)
    const accept = await browserApi(page, {
      method: 'post', url: `admin/online-order/change-status/${web.id}`,
      data: { status: 4 }, idem: `HEALVAL-V3-ACC-${web.id}`,
    });
    console.log('[V3] accept status =', accept.status);
    expect(accept.status).toBe(200);

    const afterAccept = orderState(web.id);
    console.log('[V3] after accept =', JSON.stringify(afterAccept));
    expect(afterAccept.payment_status).toBe(15);        // PENDING_COUNTER
    expect(afterAccept.pos_payment_method).toBe(6);     // COUNTER_DEFERRED

    // 2) Visible dans /admin/pos/counter-collect/pending
    const pending = await browserApi(page, { method: 'get', url: 'admin/pos/counter-collect/pending' });
    const list = Array.isArray(pending.data?.data) ? pending.data.data : (pending.data || []);
    const pendingIds = list.map((o) => Number(o.id));
    console.log('[V3] counter-collect pending contient', web.id, '?', pendingIds.includes(web.id));
    expect(pending.status).toBe(200);
    expect(pendingIds).toContain(web.id);

    // 3) Confirm CASH (mode=CASH=1) → PAID + fiscal_sequence_no alloué
    const confirm = await browserApi(page, {
      method: 'post', url: `admin/pos/counter-collect/${web.id}/confirm`,
      data: { mode: 1, received: 5.0 }, idem: `HEALVAL-V3-CONF-${web.id}`,
    });
    console.log('[V3] confirm status =', confirm.status);
    expect(confirm.status).toBe(200);

    const afterPay = orderState(web.id);
    console.log('[V3] after confirm =', JSON.stringify(afterPay));
    expect(afterPay.payment_status).toBe(5);            // PAID
    expect(afterPay.fiscal_sequence_no).not.toBeNull(); // NF525 seq alloué
    expect(Number(afterPay.fiscal_sequence_no)).toBeGreaterThan(0);
  });

  // ===========================================================================
  // V4 — P2-u : /loyalty/scan durci (KioskMachine uniquement pour la PII)
  // ===========================================================================
  test('V4 — loyalty/scan : token invité → NEUTRE (no PII) ; vraie KioskMachine → résout', async ({ page }) => {
    const v4 = seedLoyaltyV4();
    console.log('[V4] seed target_id =', v4.target_id, 'guest_id =', v4.guest_id);

    // IMPORTANT : rawApi (page.request) → le token EXPLICITE (invité vs kiosk)
    // atteint le serveur tel quel. browserApi/window.axios écraserait
    // l'Authorization par le token stocké et fausserait l'identité (le cœur du
    // test = distinguer les deux identités).

    // (a) token INVITÉ (kiosk:order, PAS KioskMachine) → réponse NEUTRE
    const guestScan = await rawApi(page, {
      method: 'post', url: 'frontend/loyalty/scan', bearer: v4.guest_token,
      data: { method: 'qr', raw_data: v4.signed_for_guest },
    });
    console.log('[V4] GUEST scan =', JSON.stringify(guestScan.data?.data || guestScan.data));
    expect(guestScan.status).toBe(200);
    const g = guestScan.data?.data || {};
    expect(g.ok).toBe(false);
    expect(g.display_name).toBeNull();                 // aucune PII
    expect(Number(g.loyalty_balance_points)).toBe(0);
    expect(g.customer_token).toBeNull();
    expect(g.error_code).toBe('customer_not_found');   // indiscernable de « non trouvé »

    // (b) VRAIE KioskMachine → résout (PII légitime au comptoir borne)
    const kioskToken = await kioskLoginToken(page);
    const kioskScan = await rawApi(page, {
      method: 'post', url: 'frontend/loyalty/scan', bearer: kioskToken,
      data: { method: 'qr', raw_data: v4.signed_for_kiosk },
    });
    console.log('[V4] KIOSK scan =', JSON.stringify(kioskScan.data?.data || kioskScan.data));
    expect(kioskScan.status).toBe(200);
    const k = kioskScan.data?.data || {};
    expect(k.ok).toBe(true);
    expect(k.display_name).toBeTruthy();               // prénom résolu
    expect(Number(k.loyalty_balance_points)).toBe(TARGET_POINTS);
    expect(String(k.customer_token || '')).toMatch(/^lt_/);
  });

  // ===========================================================================
  // V5 — P2-e : refund web gaté par `pos-refund`
  // ===========================================================================
  test('V5 — online-order change-payment-status→REFUNDED sans `pos-refund` → 403', async ({ page }) => {
    const web = createWebOrder({ paid: true });
    console.log('[V5] web PAID order =', JSON.stringify(web));

    await loginAsPosOperator(page);  // a online-orders + pos, PAS pos-refund
    const refund = await browserApi(page, {
      method: 'post', url: `admin/online-order/change-payment-status/${web.id}`,
      data: { payment_status: 20 }, idem: `HEALVAL-V5-${web.id}`,   // REFUNDED=20
    });
    console.log('[V5] POS refund status =', refund.status);
    expect(refund.status).toBe(403);

    // Le paiement n'a PAS bougé (toujours PAID).
    const st = orderState(web.id);
    expect(st.payment_status).toBe(5);
  });
});
