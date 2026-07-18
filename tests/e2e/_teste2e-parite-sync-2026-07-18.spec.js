// =============================================================================
// _teste2e-parite-sync-2026-07-18.spec.js
// VALIDATION E2E ABUSIVE — PARITÉ borne==web + SYNC unifiée cross-surface
// (borne ↔ web → POS → KDS). Prouve en conditions RÉELLES (serveur :8000,
// DB foodking_e2e) que la borne et le web suivent le MÊME chemin de pricing
// et que la synchronisation vers POS + KDS est unifiée. Valide aussi les
// heals du goal (S1 flip PENDING_COUNTER gaté COD, S3 coupon surface au commit).
//
//   W1 — Parité pricing borne==web : même panier → une commande source=kiosk
//        (KioskMachine) + une source=web (token guest), TOUTES DEUX via
//        POST /api/frontend/order → subtotal/total/TVA byte-identiques
//        (le web est traité forKiosk) + composition_snapshot identique.
//   W2 — Sync unifiée →POS : web takeaway COD → Accept (OnlineOrderController)
//        → counter-collect/pending → confirm CASH → PAID + fiscal_seq. La
//        commande BORNE (Plan B COD) suit le MÊME chemin (confirmCounterPayment,
//        même allocation fiscale). Preuve de la file d'encaissement unifiée.
//   W3 — Sync unifiée →KDS : après encaissement, web ET borne atteignent le
//        board KDS (KitchenReleaseRule::applyBoardReleaseFilter) à l'identique ;
//        OSS = même allowlist (order_type KIOSK|TAKEAWAY, surface-agnostique).
//   W4 — Heal S1 non-COD (régression) : web takeaway NON-COD (carte) → Accept
//        → PAS flippée en PENDING_COUNTER → PAS board-released orpheline ;
//        web takeaway COD → Accept → board-released + encaissable (non-régr. P1-3).
//   W5 — Heal S3 coupon (chemin legacy) : coupon surfaces=["kiosk"] → OK sur
//        kiosk, REJET sur web ; coupon sans restriction → OK partout. Prouvé au
//        niveau CouponService::resolveCouponById (le code EXACT threadé par S3).
//        Le chemin plein-API est gaté par le SSOT frozen (escalade documentée).
//
// Discipline : READ/TEST — aucun code applicatif modifié. Fixtures préfixées
// PARITEVAL- + nettoyées (beforeAll + afterAll, mirror _teste2e-heal-audit).
// Aucun paiement non-test finalisé hors du flux de test. La chaîne fiscale
// touchée est celle de foodking_e2e uniquement ; le cleanup ne supprime jamais
// une écriture fiscale immuable (cash_movements / audit_logs restent orphelins,
// comportement CORRECT). Item de test = item réel du menu (id 1) → 0 fixture item.
//
// Lancer : PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test \
//          tests/e2e/_teste2e-parite-sync-2026-07-18.spec.js
// =============================================================================

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const { loginAsPosOperator, loginAsAdmin } = require('./helpers/login');

const repoRoot = path.resolve(__dirname, '../..');
const SHOTS = path.join(repoRoot, 'reports/goal-parite-sync-2026-07-18/screenshots');
fs.mkdirSync(SHOTS, { recursive: true });

// -- Panier partagé (même item + mêmes options pour borne ET web) --------------
// Item 1 « Menu (Frites + Boisson) » (2,50 €) + extra 234 « Grande Portion »
// (1,00 €). qty 2 → subtotal 7,00 €, TVA 0,64 € (extraite TTC), total 7,00 €.
const BASE_ITEM_ID = 1;
const EXTRA_ID = 234;
const CART_RICH = [{ item_id: BASE_ITEM_ID, quantity: 2, item_extras: [{ id: EXTRA_ID, quantity: 1 }] }];
const CART_SIMPLE = [{ item_id: BASE_ITEM_ID, quantity: 1 }];

// Enums (app/Enums) — miroir du code.
const OT_TAKEAWAY = 10;
const PG_COD = 1;      // PaymentGateway::CASH_ON_DELIVERY
const PG_CARD = 4;     // PaymentGateway::CARD (web card OFF V1 → simulé pour W4)
const PS_PAID = 5;
const PS_UNPAID = 10;
const PS_PENDING_COUNTER = 15;
const PPM_COUNTER_DEFERRED = 6;
const OS_ACCEPT = 4;

// -----------------------------------------------------------------------------
// tinker helpers (fixtures + cleanup) — mirror _teste2e-heal-audit-2026-07-18.
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
  throw new Error('MIX_API_KEY introuvable (.env / env).');
}
const API_KEY = readApiKey();

// Out-of-browser API caller (page.request) — EXPLICIT x-api-key + Authorization.
// Ne passe PAS par l'intercepteur axios SPA → le `bearer` reçu par le serveur est
// exactement celui passé (indispensable pour tester l'identité borne vs guest).
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

// In-browser API caller (window.axios → token de session SPA courant). Utilisé
// pour les calls qui doivent tourner sous l'identité loggée (POS operator / admin).
async function browserApi(page, { method = 'get', url, params = null, data = null, idem = null }) {
  return page.evaluate(async ({ method, url, params, data, idem }) => {
    const cfg = {};
    if (params) cfg.params = params;
    if (idem) cfg.headers = { 'X-Idempotency-Key': idem };
    try {
      const m = method.toLowerCase();
      const r = m === 'get' ? await window.axios.get(url, cfg) : await window.axios[m](url, data || {}, cfg);
      return { status: r.status, data: r.data };
    } catch (e) {
      return { status: e?.response?.status ?? 0, data: e?.response?.data ?? { message: String(e?.message || e) } };
    }
  }, { method, url, params, data, idem });
}

// Token borne (kiosk:order + VRAIE KioskMachine) via page.request.
async function kioskLoginToken(page) {
  const r = await rawApi(page, {
    method: 'post', url: '/api/auth/kiosk-login',
    data: { username: 'kiosk-lecayenne', password: 'kiosk123' },
  });
  if (!r.data?.token) throw new Error(`kiosk-login HTTP ${r.status}: ${JSON.stringify(r.data).slice(0, 300)}`);
  return r.data.token;
}

// Token GUEST WEB : user PARITEVAL sans KioskMachine + token kiosk:order →
// FrontendOrderService dérive source_surface='web'. Fresh token à chaque appel.
function guestToken() {
  const j = tinkerJson(`
    $g = \\App\\Models\\User::updateOrCreate(['phone'=>'+330000000079'],['name'=>'PARITEVAL Guest Web','username'=>'pariteval-guest-web','status'=>5,'is_guest'=>5,'branch_id'=>0,'password'=>bcrypt('x')]);
    try { if(!$g->hasRole('Customer')) $g->assignRole('Customer'); } catch (\\Throwable $e) {}
    $g->tokens()->delete();
    echo json_encode(['token'=>$g->createToken('pariteval-guest',['kiosk:order'],now()->addDay())->plainTextToken]);
  `);
  return j.token;
}

// Placement UNIFIÉ via POST /api/frontend/order — MÊME endpoint pour borne et web.
// Borne : quote (sceau HMAC) → store. Web : store direct (le serveur recalcule via
// PricingRequest::forKiosk ; sealForCommit n'est appelé que si KioskMachine).
async function placeOrderViaApi(page, { bearer, isKiosk, token, orderType = OT_TAKEAWAY, paymentMethod = PG_COD, items, idem = null }) {
  const base = {
    branch_id: 1, token, discount: 0, order_type: orderType,
    is_advance_order: 10, source: 5, payment_method: paymentMethod,
    items: JSON.stringify(items),
  };
  let storePayload = { ...base };
  let quote = null;
  if (isKiosk) {
    const q = await rawApi(page, { method: 'post', url: 'frontend/order/quote', bearer, data: base });
    if (q.status !== 200) throw new Error(`[placeOrder kiosk quote] HTTP ${q.status}: ${JSON.stringify(q.data).slice(0, 300)}`);
    quote = q.data?.data || q.data;
    storePayload = {
      ...base, quote_token: quote.quote_token, quote_signature: quote.signature,
      subtotal: quote.subtotal, discount: quote.discount, delivery_charge: quote.delivery_charge, total: quote.total_ttc,
    };
  }
  const s = await rawApi(page, {
    method: 'post', url: 'frontend/order', bearer, data: storePayload,
    idem: idem || `${token}-${Date.now()}`,
  });
  if (s.status >= 300) throw new Error(`[placeOrder store ${isKiosk ? 'kiosk' : 'web'}] HTTP ${s.status}: ${JSON.stringify(s.data).slice(0, 300)}`);
  const od = s.data?.data || s.data;
  if (!od?.id) throw new Error(`[placeOrder] no order id: ${JSON.stringify(s.data).slice(0, 300)}`);
  return { id: Number(od.id), data: od, quote };
}

function orderState(id) {
  return tinkerJson(`
    $o = \\App\\Models\\Order::withoutGlobalScopes()->find(${id});
    echo json_encode($o ? [
      'source_surface'=>$o->source_surface,'order_type'=>(int)$o->order_type,'status'=>(int)$o->status,
      'payment_status'=>(int)$o->payment_status,
      'pos_payment_method'=>$o->pos_payment_method===null?null:(int)$o->pos_payment_method,
      'subtotal'=>(float)$o->subtotal,'total'=>(float)$o->total,'total_tax'=>(float)$o->total_tax,
      'fiscal_sequence_no'=>$o->fiscal_sequence_no===null?null:(int)$o->fiscal_sequence_no
    ] : ['missing'=>true]);
  `);
}

function orderSnapshot(id) {
  return tinkerJson(`
    $oi = \\DB::table('order_items')->where('order_id',${id})->orderBy('id')->first();
    echo json_encode(['snapshot'=>$oi ? json_decode($oi->composition_snapshot, true) : null]);
  `);
}

// Miroir SQL du board KDS : whereIn(status, visibleStatuses) + applyBoardReleaseFilter
// (PAID | PENDING_COUNTER | POS-cash) + branch. C'est la query board SSOT elle-même.
function boardReleasedIds(branchId = 1) {
  return tinkerJson(`
    $q = \\App\\Models\\Order::query()->whereIn('status', \\App\\Domain\\Kds\\KitchenReleaseRule::visibleStatuses());
    \\App\\Domain\\Kds\\KitchenReleaseRule::applyBoardReleaseFilter($q);
    $q->where('branch_id', ${branchId});
    echo json_encode(['ids'=>$q->pluck('id')->map(fn($v)=>(int)$v)->values()->all()]);
  `);
}

function bumpToPreparing(id) {
  tinker(`$o=\\App\\Models\\Order::withoutGlobalScopes()->find(${id}); if($o){ $o->status=\\App\\Enums\\OrderStatus::PREPARING; $o->saveQuietly(); }`);
}

// W4 : commande web takeaway seedée avec un payment_method arbitraire (CARD pour
// le cas NON-COD que l'API web refuse — carte web OFF V1). UNPAID/PENDING/web.
function seedWebOrderTinker({ paymentMethod, wave }) {
  return tinkerJson(`
    $admin = \\App\\Models\\User::where('email','admin@lecayenne.fr')->first();
    $o = new \\App\\Models\\Order();
    $o->branch_id=1; $o->user_id=$admin->id;
    $o->order_type=\\App\\Enums\\OrderType::TAKEAWAY; $o->source_surface='web';
    $o->payment_method=${paymentMethod}; $o->pos_payment_method=null;
    $o->payment_status=\\App\\Enums\\PaymentStatus::UNPAID; $o->status=\\App\\Enums\\OrderStatus::PENDING;
    $o->subtotal=2.5; $o->total=2.5; $o->total_tax=0.23; $o->discount=0; $o->delivery_charge=0;
    $o->token='PARITEVAL-${wave}-'.uniqid(); $o->order_serial_no=$o->token;
    $o->order_datetime=now(); $o->queue_number=(string)(9600+rand(0,300)); $o->saveQuietly();
    $oi=new \\App\\Models\\OrderItem();
    $oi->order_id=$o->id; $oi->item_id=${BASE_ITEM_ID}; $oi->branch_id=1; $oi->quantity=1;
    $oi->price=2.5; $oi->total_price=2.5; $oi->discount=0; $oi->item_extra_total=0; $oi->item_variation_total=0;
    $oi->tax_amount=0; $oi->tax_rate=0; $oi->composition_snapshot=json_encode(['name'=>'Menu']); $oi->saveQuietly();
    echo json_encode(['id'=>(int)$o->id,'token'=>$o->token]);
  `);
}

// W5 : 2 coupons de test — un restreint surface kiosk, un sans restriction.
function seedCoupons() {
  return tinkerJson(`
    $admin=\\App\\Models\\User::where('email','admin@lecayenne.fr')->first();
    $mk=function($code,$surfaces) use($admin){
      $c=\\App\\Models\\Coupon::where('code',$code)->first() ?: new \\App\\Models\\Coupon();
      $c->name=$code; $c->code=$code; $c->discount_type=1; $c->discount=10; $c->minimum_order=0;
      $c->status=\\App\\Enums\\Status::ACTIVE; $c->surfaces=$surfaces; $c->branch_scope=null;
      $c->maximum_discount=100; $c->limit_per_user=0; $c->max_uses_global=0;
      $c->start_date=null;$c->end_date=null;$c->valid_days_of_week=null;$c->valid_hours_start=null;$c->valid_hours_end=null;
      if($admin){$c->creator_id=$admin->id;$c->creator_type=get_class($admin);}
      $c->save(); return (int)$c->id;
    };
    echo json_encode(['kiosk'=>$mk('PARITEVAL-CPN-KIOSK',['kiosk']),'open'=>$mk('PARITEVAL-CPN-OPEN',null)]);
  `);
}

// W5 : matrice de résolution via le service EXACT threadé par S3 (5-arg surface).
function couponResolveMatrix(kioskId, openId) {
  return tinkerJson(`
    $svc=app(\\App\\Services\\CouponService::class);
    $try=function($id,$surface) use($svc){ try{ $svc->resolveCouponById($id,50.0,1,1,$surface); return 'OK'; }catch(\\Throwable $e){ return 'REJECT'; } };
    $tryNull=function($id) use($svc){ try{ $svc->resolveCouponById($id,50.0,1); return 'OK'; }catch(\\Throwable $e){ return 'REJECT'; } };
    echo json_encode([
      'ssot'=>(bool)config('pricing.use_ssot_service', true),
      'kiosk_on_kiosk'=>$try(${kioskId},'kiosk'),
      'kiosk_on_web'=>$try(${kioskId},'web'),
      'kiosk_on_null'=>$tryNull(${kioskId}),
      'open_on_kiosk'=>$try(${openId},'kiosk'),
      'open_on_web'=>$try(${openId},'web')
    ]);
  `);
}

function cleanupPariteval() {
  return tinker(`
    $del = function (callable $fn) { try { $fn(); } catch (\\Throwable $e) {} };
    $ids = \\App\\Models\\Order::withoutGlobalScopes()->where('token','like','PARITEVAL-%')->pluck('id');
    if ($ids->isNotEmpty()) {
      // cash_movements + audit_logs : NF525-IMMUTABLES (jamais supprimés) — restent
      // orphelins sur la DB e2e = comportement CORRECT (on ne supprime pas une écriture
      // fiscale). Chaque delete isolé en try/catch (order_payments a un trigger no-delete).
      $del(fn() => Schema::hasTable('transactions') && DB::table('transactions')->whereIn('order_id',$ids)->delete());
      $del(fn() => Schema::hasTable('order_status_transitions') && DB::table('order_status_transitions')->whereIn('order_id',$ids)->delete());
      $del(fn() => Schema::hasTable('domain_events') && DB::table('domain_events')->whereIn('aggregate_id',$ids)->delete());
      $del(fn() => Schema::hasTable('order_coupons') && DB::table('order_coupons')->whereIn('order_id',$ids)->delete());
      $del(fn() => DB::table('order_items')->whereIn('order_id',$ids)->delete());
      $del(fn() => DB::table('orders')->whereIn('id',$ids)->delete());
    }
    $del(fn() => \\App\\Models\\Coupon::where('code','like','PARITEVAL-CPN-%')->forceDelete());
    $del(function () {
      foreach (\\App\\Models\\User::withoutGlobalScopes()->where('username','like','pariteval-%')->get() as $u) { $u->tokens()->delete(); $u->forceDelete(); }
    });
    $remaining = \\App\\Models\\Order::withoutGlobalScopes()->where('token','like','PARITEVAL-%')->count();
    echo json_encode(['deleted_orders' => $ids->count(), 'remaining' => $remaining]);
  `);
}

// Provisionne une PAIRE encaissée (web + borne) via le chemin d'encaissement
// UNIFIÉ : web=Accept→counter-collect→confirm, borne=Plan B→counter-collect→confirm.
// Retourne les preuves intermédiaires pour assertion (état accept, file, confirm).
async function provisionEncashedPair(page, wave) {
  const stamp = `${Date.now()}-${Math.floor(Math.random() * 1e5)}`;
  const kioskToken = await kioskLoginToken(page);
  const webTok = guestToken();

  // web takeaway COD → PENDING/UNPAID/source=web
  const web = await placeOrderViaApi(page, {
    bearer: webTok, isKiosk: false, token: `PARITEVAL-${wave}-WEB-${stamp}`,
    orderType: OT_TAKEAWAY, paymentMethod: PG_COD, items: CART_SIMPLE, idem: `PARITEVAL-${wave}-WEB-${stamp}`,
  });
  // borne Plan B COD → auto-accept PENDING_COUNTER + COUNTER_DEFERRED/source=kiosk
  const kiosk = await placeOrderViaApi(page, {
    bearer: kioskToken, isKiosk: true, token: `PARITEVAL-${wave}-KIOSK-${stamp}`,
    orderType: OT_TAKEAWAY, paymentMethod: PG_COD, items: CART_SIMPLE, idem: `PARITEVAL-${wave}-KIOSK-${stamp}`,
  });

  const webBefore = orderState(web.id);
  const kioskBefore = orderState(kiosk.id);

  await loginAsPosOperator(page);

  // Accept web (OnlineOrderController::changeStatus, status=ACCEPT)
  const accept = await browserApi(page, {
    method: 'post', url: `admin/online-order/change-status/${web.id}`,
    data: { status: OS_ACCEPT }, idem: `PARITEVAL-${wave}-ACC-${web.id}`,
  });
  const webAfterAccept = orderState(web.id);

  // File d'encaissement unifiée (AVANT confirm) — doit contenir web ET borne
  const pending = await browserApi(page, { method: 'get', url: 'admin/pos/counter-collect/pending' });
  const pendingList = Array.isArray(pending.data?.data) ? pending.data.data : (pending.data || []);
  const pendingIds = pendingList.map((o) => Number(o.id));

  // confirm CASH (mode=1) → PAID + fiscal_seq (confirmCounterPayment, MÊME chemin)
  const confWeb = await browserApi(page, {
    method: 'post', url: `admin/pos/counter-collect/${web.id}/confirm`,
    data: { mode: 1, received: 20 }, idem: `PARITEVAL-${wave}-CW-${web.id}`,
  });
  const confKiosk = await browserApi(page, {
    method: 'post', url: `admin/pos/counter-collect/${kiosk.id}/confirm`,
    data: { mode: 1, received: 20 }, idem: `PARITEVAL-${wave}-CK-${kiosk.id}`,
  });

  return {
    web, kiosk, webBefore, kioskBefore, accept, webAfterAccept, pending, pendingIds,
    confWeb, confKiosk, webFinal: orderState(web.id), kioskFinal: orderState(kiosk.id),
  };
}

// -----------------------------------------------------------------------------
// NB : pas de mode serial — chaque test provisionne ses propres fixtures
// PARITEVAL-<wave>-* et reste vert même si un autre échoue (workers:1).
// -----------------------------------------------------------------------------
test.describe('PARITE-SYNC 2026-07-18 — parité borne==web + sync unifiée POS/KDS (E2E abusive)', () => {
  test.setTimeout(240_000);

  test.beforeAll(() => { console.log('[cleanup:before]', cleanupPariteval().trim().split('\n').pop()); });
  test.afterAll(() => { console.log('[cleanup:after]', cleanupPariteval().trim().split('\n').pop()); });

  // ===========================================================================
  // W1 — Parité pricing borne == web (POST /api/frontend/order, même panier)
  // ===========================================================================
  test('W1 — pricing borne==web byte-identique + composition_snapshot identique', async ({ page }) => {
    const stamp = `${Date.now()}`;
    const kioskToken = await kioskLoginToken(page);
    const webTok = guestToken();

    // WEB : store direct (guest token, PAS de KioskMachine → source_surface=web)
    const web = await placeOrderViaApi(page, {
      bearer: webTok, isKiosk: false, token: `PARITEVAL-W1-WEB-${stamp}`,
      orderType: OT_TAKEAWAY, paymentMethod: PG_COD, items: CART_RICH,
    });
    // BORNE : quote (sceau forKiosk) → store (KioskMachine → source_surface=kiosk)
    const kiosk = await placeOrderViaApi(page, {
      bearer: kioskToken, isKiosk: true, token: `PARITEVAL-W1-KIOSK-${stamp}`,
      orderType: OT_TAKEAWAY, paymentMethod: PG_COD, items: CART_RICH,
    });

    const w = orderState(web.id);
    const k = orderState(kiosk.id);
    console.log('[W1] WEB   =', JSON.stringify(w));
    console.log('[W1] KIOSK =', JSON.stringify(k));

    // Surfaces distinctes = la SEULE différence attendue.
    expect(w.source_surface).toBe('web');
    expect(k.source_surface).toBe('kiosk');

    // Pricing byte-identique (le web est traité forKiosk : PricingRequest::forKiosk
    // partagé par les deux surfaces dans FrontendOrderService::store).
    expect(w.subtotal).toBe(k.subtotal);
    expect(w.total).toBe(k.total);
    expect(w.total_tax).toBe(k.total_tax);
    console.log('[W1] pricing identique → subtotal', w.subtotal, '| total', w.total, '| tva', w.total_tax);

    // La borne a été quotée : le total_ttc du quote == total persisté (cohérence sceau).
    expect(Number(kiosk.quote.total_ttc)).toBe(k.total);
    expect(Number(kiosk.quote.subtotal)).toBe(k.subtotal);
    expect(Number(kiosk.quote.total_tax)).toBe(k.total_tax);

    // composition_snapshot identique modulo captured_at (horodatage naturel).
    const stripTs = (s) => { const c = JSON.parse(JSON.stringify(s || {})); delete c.captured_at; return c; };
    const ws = orderSnapshot(web.id).snapshot;
    const ks = orderSnapshot(kiosk.id).snapshot;
    console.log('[W1] WEB snapshot   =', JSON.stringify(ws));
    console.log('[W1] KIOSK snapshot =', JSON.stringify(ks));
    expect(ws).toBeTruthy();
    expect(ks).toBeTruthy();
    expect(stripTs(ws)).toEqual(stripTs(ks));   // deep-equal (extras/lines/addons/prix)
    // L'option (extra 234) est présente à l'identique des deux côtés (preuve « mêmes options »).
    const extraOf = (s) => (Array.isArray(s?.extras) ? s.extras : []).find((e) => Number(e.extra_id) === EXTRA_ID);
    expect(extraOf(ws)).toBeTruthy();
    expect(extraOf(ks)).toBeTruthy();
    expect(extraOf(ws)).toEqual(extraOf(ks));
  });

  // ===========================================================================
  // W2 — Sync unifiée → POS (file counter-collect + encaissement fiscal commun)
  // ===========================================================================
  test('W2 — web + borne convergent dans counter-collect → confirm CASH → PAID + fiscal_seq', async ({ page }) => {
    const r = await provisionEncashedPair(page, 'W2');
    console.log('[W2] web=', r.web.id, 'kiosk=', r.kiosk.id);
    console.log('[W2] web before accept =', JSON.stringify(r.webBefore));
    console.log('[W2] kiosk (Plan B, auto) =', JSON.stringify(r.kioskBefore));
    console.log('[W2] web after accept =', JSON.stringify(r.webAfterAccept));
    console.log('[W2] counter-collect pending ids =', JSON.stringify(r.pendingIds));

    // Web arrive UNPAID/source=web ; borne arrive déjà PENDING_COUNTER+COUNTER_DEFERRED.
    expect(r.webBefore.payment_status).toBe(PS_UNPAID);
    expect(r.webBefore.source_surface).toBe('web');
    expect(r.kioskBefore.payment_status).toBe(PS_PENDING_COUNTER);
    expect(r.kioskBefore.pos_payment_method).toBe(PPM_COUNTER_DEFERRED);
    expect(r.kioskBefore.source_surface).toBe('kiosk');

    // Accept web → flip COD → PENDING_COUNTER + COUNTER_DEFERRED (encaissable).
    expect(r.accept.status).toBe(200);
    expect(r.webAfterAccept.payment_status).toBe(PS_PENDING_COUNTER);
    expect(r.webAfterAccept.pos_payment_method).toBe(PPM_COUNTER_DEFERRED);

    // FILE UNIFIÉE : les DEUX surfaces remontent dans la même file /counter-collect.
    expect(r.pending.status).toBe(200);
    expect(r.pendingIds).toContain(r.web.id);
    expect(r.pendingIds).toContain(r.kiosk.id);

    // ENCAISSEMENT COMMUN : même endpoint confirmCounterPayment → PAID + fiscal_seq.
    expect(r.confWeb.status).toBe(200);
    expect(r.confKiosk.status).toBe(200);
    console.log('[W2] web final  =', JSON.stringify(r.webFinal));
    console.log('[W2] kiosk final =', JSON.stringify(r.kioskFinal));
    expect(r.webFinal.payment_status).toBe(PS_PAID);
    expect(r.kioskFinal.payment_status).toBe(PS_PAID);
    expect(Number(r.webFinal.fiscal_sequence_no)).toBeGreaterThan(0);
    expect(Number(r.kioskFinal.fiscal_sequence_no)).toBeGreaterThan(0);
    console.log('[W2] fiscal_seq → web', r.webFinal.fiscal_sequence_no, '| borne', r.kioskFinal.fiscal_sequence_no);
  });

  // ===========================================================================
  // W3 — Sync unifiée → KDS (board applyBoardReleaseFilter) + OSS allowlist
  // ===========================================================================
  test('W3 — web + borne encaissées atteignent le board KDS + OSS à l\'identique', async ({ page, browser }) => {
    const r = await provisionEncashedPair(page, 'W3');
    expect(r.confWeb.status).toBe(200);
    expect(r.confKiosk.status).toBe(200);

    // -- KDS board (SSOT) : KitchenReleaseRule::applyBoardReleaseFilter -------
    const board = boardReleasedIds(1).ids;
    console.log('[W3] board-released contient web?', board.includes(r.web.id), '| borne?', board.includes(r.kiosk.id));
    expect(board).toContain(r.web.id);
    expect(board).toContain(r.kiosk.id);

    // -- KDS board (endpoint réel) : GET /api/admin/kds-order (admin = toutes branches).
    // Corroboration résiliente : si joignable, les 2 doivent être présents.
    const adminCtx = await browser.newContext({ baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000' });
    try {
      const adminPage = await adminCtx.newPage();
      await loginAsAdmin(adminPage);
      const kds = await browserApi(adminPage, { method: 'get', url: 'admin/kds-order' });
      const kdsList = Array.isArray(kds.data?.data) ? kds.data.data : (Array.isArray(kds.data) ? kds.data : []);
      const kdsIds = kdsList.map((o) => Number(o.id));
      console.log('[W3] endpoint kds-order status =', kds.status, '| web présent?', kdsIds.includes(r.web.id), '| borne présent?', kdsIds.includes(r.kiosk.id), '| total board =', kdsIds.length);
      if (kds.status === 200 && kdsIds.length < 50) {
        expect(kdsIds).toContain(r.web.id);
        expect(kdsIds).toContain(r.kiosk.id);
      }
    } finally {
      await adminCtx.close();
    }

    // -- OSS allowlist (surface-agnostique : order_type KIOSK|TAKEAWAY, statut PREPARING/PREPARED).
    // On amène les 2 en PREPARING puis on interroge le mur public OSS.
    bumpToPreparing(r.web.id);
    bumpToPreparing(r.kiosk.id);
    const oss = await rawApi(page, { method: 'get', url: 'frontend/oss-order', params: { branch_id: 1 } });
    const ossList = Array.isArray(oss.data?.data) ? oss.data.data : (Array.isArray(oss.data) ? oss.data : []);
    const ossIds = ossList.map((o) => Number(o.id));
    console.log('[W3] OSS status =', oss.status, '| web présent?', ossIds.includes(r.web.id), '| borne présent?', ossIds.includes(r.kiosk.id));
    expect(oss.status).toBe(200);
    expect(ossIds).toContain(r.web.id);      // web takeaway dans l'allowlist…
    expect(ossIds).toContain(r.kiosk.id);    // …exactement comme la borne
  });

  // ===========================================================================
  // W4 — Heal S1 : web takeaway NON-COD PAS board-released orpheline (+ non-régr. COD)
  // ===========================================================================
  test('W4 — web NON-COD Accept → non board-released ; web COD Accept → board-released + encaissable', async ({ page }) => {
    const nonCod = seedWebOrderTinker({ paymentMethod: PG_CARD, wave: 'W4NONCOD' });
    const cod = seedWebOrderTinker({ paymentMethod: PG_COD, wave: 'W4COD' });
    console.log('[W4] nonCod=', nonCod.id, '| cod=', cod.id);

    await loginAsPosOperator(page);

    // (a) NON-COD (carte) : Accept → PAS de flip → reste UNPAID → PAS board-released.
    const accNon = await browserApi(page, {
      method: 'post', url: `admin/online-order/change-status/${nonCod.id}`,
      data: { status: OS_ACCEPT }, idem: `PARITEVAL-W4-ACCNON-${nonCod.id}`,
    });
    expect(accNon.status).toBe(200);
    const nonSt = orderState(nonCod.id);
    console.log('[W4] non-COD after accept =', JSON.stringify(nonSt));
    expect(nonSt.status).toBe(OS_ACCEPT);
    expect(nonSt.payment_status).toBe(PS_UNPAID);        // PAS flippée en PENDING_COUNTER
    expect(nonSt.pos_payment_method).toBeNull();          // aucun marqueur counter-deferred
    let board = boardReleasedIds(1).ids;
    expect(board).not.toContain(nonCod.id);               // PAS orpheline en cuisine

    // (b) COD : Accept → flip → PENDING_COUNTER + COUNTER_DEFERRED → board-released + encaissable.
    const accCod = await browserApi(page, {
      method: 'post', url: `admin/online-order/change-status/${cod.id}`,
      data: { status: OS_ACCEPT }, idem: `PARITEVAL-W4-ACCCOD-${cod.id}`,
    });
    expect(accCod.status).toBe(200);
    const codSt = orderState(cod.id);
    console.log('[W4] COD after accept =', JSON.stringify(codSt));
    expect(codSt.payment_status).toBe(PS_PENDING_COUNTER);
    expect(codSt.pos_payment_method).toBe(PPM_COUNTER_DEFERRED);
    board = boardReleasedIds(1).ids;
    expect(board).toContain(cod.id);                      // board-released (non-régression P1-3)
    const pending = await browserApi(page, { method: 'get', url: 'admin/pos/counter-collect/pending' });
    const pendingIds = (Array.isArray(pending.data?.data) ? pending.data.data : (pending.data || [])).map((o) => Number(o.id));
    console.log('[W4] COD encaissable (counter-collect) ?', pendingIds.includes(cod.id));
    expect(pendingIds).toContain(cod.id);                 // encaissable
  });

  // ===========================================================================
  // W5 — Heal S3 : coupon surface au commit (chemin legacy CouponService)
  // ===========================================================================
  test('W5 — coupon surfaces=["kiosk"] OK sur kiosk / REJET sur web ; sans restriction OK partout', async () => {
    const c = seedCoupons();
    console.log('[W5] coupons kiosk=', c.kiosk, '| open=', c.open);
    const m = couponResolveMatrix(c.kiosk, c.open);
    console.log('[W5] matrice =', JSON.stringify(m));

    // Le chemin plein-API est gaté par le SSOT frozen (DiscountCalculator 3-arg) :
    // on documente l'escalade et on prouve le heal S3 au niveau du service EXACT threadé.
    console.log('[W5] pricing.use_ssot_service =', m.ssot, '(true → chemin plein-API gaté SSOT frozen = escalade documentée ; heal prouvé au niveau CouponService::resolveCouponById)');

    // Coupon restreint surface=kiosk : applicable sur SA surface, refusé ailleurs.
    expect(m.kiosk_on_kiosk).toBe('OK');       // ✅ utilisable sur borne
    expect(m.kiosk_on_web).toBe('REJECT');     // ⛔ refusé sur web (bonne surface exigée)
    expect(m.kiosk_on_null).toBe('REJECT');    // ⛔ surface=null (comportement PRÉ-fix) = sur-rejet → le bug que S3 corrige

    // Coupon sans restriction : marche sur les deux surfaces (inchangé par S3).
    expect(m.open_on_kiosk).toBe('OK');
    expect(m.open_on_web).toBe('OK');
  });
});
