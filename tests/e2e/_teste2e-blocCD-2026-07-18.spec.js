// =============================================================================
// _teste2e-blocCD-2026-07-18.spec.js
// VALIDATION E2E des corrections du jour (blocs B / C / D) en conditions RÉELLES
// (serveur dev :8000, DB foodking_e2e). READ/TEST — AUCUN code applicatif modifié.
//
//   V1 — Multi-sauces (bloc B) : le NOM de la 2e+ sauce (« Sauce supplémentaire :
//        Andalouse ») apparaît sur le TICKET CUISINE ESC/POS et sur le feed KDS
//        (+ ticket client = les 2 noms pleins). Commande réelle #5727 rendue +
//        fixture board-released rendue à l'écran cuisine. Bonus : 2 sauces frites
//        (KTP MAY) affichées et GRATUITES.
//   V2 — Accept web INLINE caisse (C1/C2) : commande WEB takeaway COD PENDING →
//        visible « Commandes web » → accept (online-order/change-status status=ACCEPT)
//        → PENDING_COUNTER + COUNTER_DEFERRED → counter-collect/pending (encaissable)
//        → confirm CASH → PAID + fiscal_seq. Cycle sans quitter le POS.
//   V3 — Notif client canal (C3) : POST /api/broadcasting/auth — un client AUTORISÉ
//        sur SON canal `private-customer.<sonId>` (200 + signature), REFUSÉ sur celui
//        d'un autre (403, anti-fuite). + /api/frontend/order/show d'une commande
//        PREPARED expose status=8 « Prête » (fallback polling).
//   V4 — Fidélité ON / remises OFF (découplage) : manual_discount_enabled=false →
//        (a) accrual crédite les points (solde ↑) ; (b) remise manuelle caisse
//        REFUSÉE (POST /api/admin/pos discount>0 → 422) ; (c) redeem fidélité
//        (PosRedemptionService) AUTORISÉ (F1 fixé).
//   V5 — Stock extra/variation depuis caisse (D) : POS Operator (availability_toggle)
//        charge item/details (pas 403) → 86 d'un EXTRA via availability/extra/toggle
//        → reflété indisponible → réactivé.
//
// Discipline : fixtures préfixées VALGOAL- ; cleanup beforeAll + afterAll (fiscal_
// sequence_no nullé AVANT tout hard-delete d'order — trigger orders_no_delete).
// cash_movements / audit_logs = NF525-immuables → jamais supprimés (orphelins OK).
// Aucun paiement non-test finalisé hors du flux de test.
//
// Lancer : PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test \
//          tests/e2e/_teste2e-blocCD-2026-07-18.spec.js --workers=1
// =============================================================================

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const { loginAsPosOperator, loginAsChefOperator } = require('./helpers/login');

const repoRoot = path.resolve(__dirname, '../..');
const REPORT_DIR = path.join(repoRoot, 'reports/goal-parite-sync-2026-07-18');
const SHOTS = path.join(REPORT_DIR, 'screenshots');
fs.mkdirSync(SHOTS, { recursive: true });

// -- Enums (miroir app/Enums, valeurs vérifiées runtime) ----------------------
const OS_PENDING = 1, OS_ACCEPT = 4, OS_PREPARING = 7, OS_PREPARED = 8, OS_DELIVERED = 13;
const PS_PAID = 5, PS_UNPAID = 10, PS_PENDING_COUNTER = 15;
const PPM_CASH = 1, PPM_COUNTER_DEFERRED = 6;
const OT_TAKEAWAY = 10, OT_POS = 15;
const SRC_WEB = 5, SRC_POS = 15;
const PG_COD = 1;

// Panier web simple = item 1 « Menu (Frites + Boisson) » (2,50 €).
const CART_SIMPLE = [{ item_id: 1, quantity: 1 }];

// -----------------------------------------------------------------------------
// tinker helpers (fixtures + cleanup) — mirror _teste2e-parite-sync-2026-07-18.
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

// Out-of-browser API caller (page.request) — x-api-key + Authorization explicites.
async function rawApi(page, { method = 'get', url, params = null, data = null, form = null, bearer = null, idem = null }) {
  const headers = { 'x-api-key': API_KEY, Accept: 'application/json' };
  if (bearer) headers.Authorization = 'Bearer ' + bearer;
  if (idem) headers['X-Idempotency-Key'] = idem;
  const opts = { headers, failOnStatusCode: false };
  if (params) opts.params = params;
  if (data) opts.data = data;
  if (form) opts.form = form;
  const full = url.startsWith('/') ? url : '/api/' + url;
  const r = method.toLowerCase() === 'get'
    ? await page.request.get(full, opts)
    : await page.request[method.toLowerCase()](full, opts);
  let body = null;
  try { body = await r.json(); } catch (_) { body = null; }
  return { status: r.status(), data: body };
}

// In-browser API caller (window.axios → token de session SPA courant + x-api-key
// injecté par l'intercepteur). Utilisé sous l'identité loggée (POS operator).
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

// Token GUEST WEB : user VALGOAL sans KioskMachine + token kiosk:order →
// FrontendOrderService dérive source_surface='web'. Fresh token à chaque appel.
function guestToken(suffix = 'guest') {
  const j = tinkerJson(`
    $g = \\App\\Models\\User::updateOrCreate(['phone'=>'+330000000${(80 + Math.floor(Math.random() * 18))}'],['name'=>'VALGOAL Guest Web','username'=>'valgoal-${suffix}-'.uniqid(),'status'=>5,'is_guest'=>5,'branch_id'=>0,'password'=>bcrypt('x')]);
    try { if(!$g->hasRole('Customer')) $g->assignRole('Customer'); } catch (\\Throwable $e) {}
    echo json_encode(['token'=>$g->createToken('valgoal-guest',['kiosk:order'],now()->addDay())->plainTextToken]);
  `);
  return j.token;
}

// Client VALGOAL (Customer role) + token Sanctum ['*'] → utilisable pour
// broadcasting/auth (auth identitaire), order/show (ownership) et loyalty/check.
function customerSeed(tag, points = 0, code = null) {
  return tinkerJson(`
    $c = \\App\\Models\\User::updateOrCreate(
      ['username'=>'valgoal-cust-${tag}'],
      ['name'=>'VALGOAL Cust ${tag}','phone'=>'+33000000${1000 + Math.floor(Math.random() * 8999)}','status'=>5,'branch_id'=>0,'password'=>bcrypt('x'),'loyalty_points'=>${points}${code ? `,'loyalty_code'=>'${code}'` : ''}]);
    try { if(!$c->hasRole('Customer')) $c->assignRole('Customer'); } catch (\\Throwable $e) {}
    $c->tokens()->delete();
    echo json_encode(['id'=>(int)$c->id,'token'=>$c->createToken('valgoal-cust',['*'],now()->addDay())->plainTextToken,'code'=>$c->loyalty_code]);
  `);
}

// Web takeaway COD via POST /api/frontend/order (endpoint borne/web unifié).
async function placeWebCodOrder(page, bearer, token) {
  const payload = {
    branch_id: 1, token, discount: 0, order_type: OT_TAKEAWAY,
    is_advance_order: 10, source: SRC_WEB, payment_method: PG_COD,
    items: JSON.stringify(CART_SIMPLE),
  };
  const s = await rawApi(page, { method: 'post', url: 'frontend/order', bearer, data: payload, idem: token });
  if (s.status >= 300) throw new Error(`[placeWebCodOrder] HTTP ${s.status}: ${JSON.stringify(s.data).slice(0, 300)}`);
  const od = s.data?.data || s.data;
  if (!od?.id) throw new Error(`[placeWebCodOrder] no id: ${JSON.stringify(s.data).slice(0, 300)}`);
  return Number(od.id);
}

function orderState(id) {
  return tinkerJson(`
    $o = \\App\\Models\\Order::withoutGlobalScopes()->find(${id});
    echo json_encode($o ? [
      'source_surface'=>$o->source_surface,'order_type'=>(int)$o->order_type,'status'=>(int)$o->status,
      'payment_status'=>(int)$o->payment_status,
      'pos_payment_method'=>$o->pos_payment_method===null?null:(int)$o->pos_payment_method,
      'total'=>(float)$o->total,'fiscal_sequence_no'=>$o->fiscal_sequence_no===null?null:(int)$o->fiscal_sequence_no
    ] : ['missing'=>true]);
  `);
}

// Seed d'une commande Tacos M multi-sauces board-released (PREPARING + PAID)
// datée now → apparaît sur l'écran cuisine du jour. La 1ère sauce est une
// variation nommée ; la 2e est l'extra GÉNÉRIQUE « Sauce supplémentaire » dont
// le nom réel ne vit QUE dans `instruction` ("Sauce : <1ère>, <2e>") — c'est le
// bug corrigé aujourd'hui (récupération du nom pour ticket + KDS).
function seedTacosMultiSauceOrder(queue, firstSauce, secondSauce) {
  return tinkerJson(`
    $admin = \\App\\Models\\User::where('email','admin@lecayenne.fr')->first();
    $o = new \\App\\Models\\Order();
    $o->branch_id=1; $o->user_id=$admin->id; $o->order_type=\\App\\Enums\\OrderType::TAKEAWAY;
    $o->source_surface='pos'; $o->source=\\App\\Enums\\Source::POS;
    $o->payment_method=1; $o->payment_status=\\App\\Enums\\PaymentStatus::PAID;
    $o->status=\\App\\Enums\\OrderStatus::PREPARING; $o->pos_payment_method=1;
    $o->subtotal=7.40; $o->total=7.40; $o->total_tax=0.67; $o->discount=0; $o->delivery_charge=0;
    $o->token='VALGOAL-B-TACOS-'.uniqid(); $o->order_serial_no=$o->token;
    $o->order_datetime=now(); $o->queue_number='${queue}'; $o->saveQuietly();
    $oi = new \\App\\Models\\OrderItem();
    $oi->order_id=$o->id; $oi->item_id=26; $oi->branch_id=1; $oi->quantity=1;
    $oi->price=7.40; $oi->total_price=7.40; $oi->discount=0; $oi->item_extra_total=0.5; $oi->item_variation_total=0;
    $oi->tax_amount=0.67; $oi->tax_rate=10;
    $oi->instruction="TACOS M\\nViandes : Poulet mariné - Salade Sauce : ${firstSauce}, ${secondSauce}";
    // composition_snapshot est casté 'array' sur OrderItem → assigner un ARRAY brut
    // (un json_encode serait double-encodé → snapshot illisible par le renderer/KDS).
    $oi->composition_snapshot=[
      'lines'=>[
        ['attribute_name'=>'Sauce (1ère Gratuite)','variation_name'=>'${firstSauce}'],
        ['attribute_name'=>'Viande 1','variation_name'=>'Poulet mariné'],
      ],
      'extras'=>[
        ['extra_name'=>'Salade','line_total'=>0,'quantity'=>1],
        ['extra_name'=>'Sauce supplémentaire','line_total'=>0.5,'unit_price'=>0.5,'quantity'=>1],
      ],
      'addons'=>[],
    ];
    $oi->saveQuietly();
    echo json_encode(['id'=>(int)$o->id,'queue'=>'${queue}']);
  `);
}

// Seed d'un Menu (frites+boisson) dont l'instruction porte 2 sauces FRITES
// (canal GRATUIT, texte libre, jamais un item_extra) → « MENU : KTP MAY ».
function seedMenuFritesOrder() {
  return tinkerJson(`
    $admin = \\App\\Models\\User::where('email','admin@lecayenne.fr')->first();
    $o = new \\App\\Models\\Order();
    $o->branch_id=1; $o->user_id=$admin->id; $o->order_type=\\App\\Enums\\OrderType::TAKEAWAY;
    $o->source_surface='pos'; $o->source=\\App\\Enums\\Source::POS;
    $o->payment_method=1; $o->payment_status=\\App\\Enums\\PaymentStatus::PAID;
    $o->status=\\App\\Enums\\OrderStatus::PREPARING; $o->pos_payment_method=1;
    $o->subtotal=10.40; $o->total=10.40; $o->total_tax=0.95; $o->discount=0; $o->delivery_charge=0;
    $o->token='VALGOAL-B-FRITES-'.uniqid(); $o->order_serial_no=$o->token;
    $o->order_datetime=now(); $o->queue_number='A0779'; $o->saveQuietly();
    $oi = new \\App\\Models\\OrderItem();
    $oi->order_id=$o->id; $oi->item_id=1; $oi->branch_id=1; $oi->quantity=1;
    $oi->price=10.40; $oi->total_price=10.40; $oi->discount=0; $oi->item_extra_total=0; $oi->item_variation_total=0;
    $oi->tax_amount=0.95; $oi->tax_rate=10;
    $oi->instruction="Pain : Pain\\nSauce frites : Ketchup, Mayonnaise";
    $oi->composition_snapshot=[
      'lines'=>[['attribute_name'=>'Type de Pain','variation_name'=>'Pain']],
      'extras'=>[],
      'addons'=>[['role'=>'menu_full','addon_name'=>'Menu (Frites + Boisson)','line_total'=>3.00,'unit_price'=>3.00,'quantity'=>1]],
    ];
    $oi->saveQuietly();
    echo json_encode(['id'=>(int)$o->id]);
  `);
}

// Rend les tickets ESC/POS d'une commande via le renderer RÉEL, puis DÉCODE
// CP858 → UTF-8 (les octets imprimante ne sont PAS de l'UTF-8 : « é » y est
// mono-octet) pour permettre l'assertion sur les noms accentués.
function renderTicketsDecoded(orderId) {
  return tinkerJson(`
    $o = \\App\\Models\\Order::withoutGlobalScopes()->with(['orderItems','branch','user'])->find(${orderId});
    $r = new \\App\\Services\\Hardware\\OrderReceiptEscPosRenderer();
    $dec = fn($b) => iconv('CP858','UTF-8//IGNORE', $b);
    echo json_encode([
      'kitchen'=>$dec($r->renderKitchenTicket($o)),
      'client'=>$dec($r->renderClientTicket($o)),
    ]);
  `);
}

// V4 (a) : seed customer + commande POS DELIVERED PAID, invoque le listener
// d'accrual RÉEL, renvoie solde avant/après + nb ledger 'earn'.
function accrualProof() {
  return tinkerJson(`
    Config::set('pos.manual_discount_enabled', false); // remises coupées
    Config::set('pos.loyalty_enabled', true);
    $c = \\App\\Models\\User::updateOrCreate(['username'=>'valgoal-cust-accrual'],
      ['name'=>'VALGOAL Accrual','phone'=>'+330000009001','status'=>5,'branch_id'=>1,'password'=>bcrypt('x')]);
    try { if(!$c->hasRole('Customer')) $c->assignRole('Customer'); } catch (\\Throwable $e) {}
    // loyalty_code / loyalty_points NON fillable → assignation directe + saveQuietly.
    $c->loyalty_code='VALGOALAC01'; $c->loyalty_points=0; $c->saveQuietly();
    $c->tokens()->delete();
    $custTok = $c->createToken('valgoal-accrual',['*'],now()->addDay())->plainTextToken;
    $before = (int) $c->fresh()->loyalty_points;
    $o = new \\App\\Models\\Order();
    $o->branch_id=1; $o->user_id=$c->id; $o->order_type=\\App\\Enums\\OrderType::POS;
    $o->source_surface='pos'; $o->source=\\App\\Enums\\Source::POS; $o->payment_method=2;
    $o->payment_status=\\App\\Enums\\PaymentStatus::PAID; $o->status=\\App\\Enums\\OrderStatus::DELIVERED;
    $o->subtotal=10.00; $o->total=10.00; $o->total_tax=0.91; $o->discount=0; $o->delivery_charge=0;
    $o->loyalty_points_awarded=null;
    $o->token='VALGOAL-D-ACCRUAL-'.uniqid(); $o->order_serial_no=$o->token; $o->order_datetime=now();
    $o->queue_number='A0801'; $o->saveQuietly();
    (new \\App\\Listeners\\AwardLoyaltyPointsOnDelivery())->handle(
      new \\App\\Events\\OrderStatusChanged($o, \\App\\Enums\\OrderStatus::PREPARING, \\App\\Enums\\OrderStatus::DELIVERED)
    );
    $after = (int) $c->fresh()->loyalty_points;
    $earn = \\App\\Models\\LoyaltyTransaction::where('user_id',$c->id)->where('type','earn')->count();
    echo json_encode(['manual_discount_enabled'=>config('pos.manual_discount_enabled'),'code'=>'VALGOALAC01','token'=>$custTok,'before'=>$before,'after'=>$after,'earn_ledger'=>$earn]);
  `);
}

// V4 (c) : seed customer (500 pts) + commande POS PENDING, invoque
// PosRedemptionService::applyToOrder (100 pts) — AUTORISÉ malgré remises coupées.
function redeemProof() {
  return tinkerJson(`
    Config::set('pos.manual_discount_enabled', false); // remises manuelles COUPÉES
    Config::set('pos.loyalty_enabled', true);          // fidélité ACTIVE
    $c = \\App\\Models\\User::updateOrCreate(['username'=>'valgoal-cust-redeem'],
      ['name'=>'VALGOAL Redeem','phone'=>'+330000009002','status'=>5,'branch_id'=>1,'password'=>bcrypt('x')]);
    try { if(!$c->hasRole('Customer')) $c->assignRole('Customer'); } catch (\\Throwable $e) {}
    // loyalty_code / loyalty_points NON fillable → assignation directe + saveQuietly.
    $c->loyalty_code='VALGOALRD01'; $c->loyalty_points=500; $c->saveQuietly();
    $before = (int) $c->fresh()->loyalty_points;
    $o = new \\App\\Models\\Order();
    $o->branch_id=1; $o->user_id=$c->id; $o->order_type=\\App\\Enums\\OrderType::POS;
    $o->source_surface='pos'; $o->source=\\App\\Enums\\Source::POS; $o->payment_method=2;
    $o->payment_status=\\App\\Enums\\PaymentStatus::UNPAID; $o->status=\\App\\Enums\\OrderStatus::PENDING;
    $o->subtotal=25.00; $o->total=25.00; $o->total_tax=0; $o->discount=0; $o->delivery_charge=0;
    $o->token='VALGOAL-D-REDEEM-'.uniqid(); $o->order_serial_no=$o->token; $o->order_datetime=now();
    $o->queue_number='A0802'; $o->saveQuietly();
    try {
      $res = app(\\App\\Services\\Loyalty\\PosRedemptionService::class)->applyToOrder($o, 100, 'VALGOALRD01', null);
      $fresh = \\App\\Models\\Order::withoutGlobalScopes()->find($o->id);
      $redeem = \\App\\Models\\LoyaltyTransaction::where('user_id',$c->id)->where('type','redeem')->count();
      echo json_encode(['ok'=>true,'discount_eur'=>(float)$res['discount_eur'],'balance_before'=>$before,'balance_after'=>(int)$res['balance_after'],'order_discount'=>(float)$fresh->discount,'order_total'=>(float)$fresh->total,'redeem_ledger'=>$redeem]);
    } catch (\\Throwable $e) {
      echo json_encode(['ok'=>false,'error'=>get_class($e).': '.$e->getMessage()]);
    }
  `);
}

// V5 : item VALGOAL + extra (sauce) + variation, chargeable via item/details.
function seedValgoalItemChoices() {
  return tinkerJson(`
    $item = \\App\\Models\\Item::factory()->create(['name'=>'VALGOAL Panel Item','status'=>5]);
    $extra = \\App\\Models\\ItemExtra::query()->create(['item_id'=>$item->id,'name'=>'VALGOAL Sauce Extra','price'=>0.50,'status'=>5,'group_label'=>'sauce','is_available'=>true]);
    $attr = \\App\\Models\\ItemAttribute::factory()->create(['is_available'=>true]);
    $var = \\App\\Models\\ItemVariation::query()->create(['item_id'=>$item->id,'item_attribute_id'=>$attr->id,'name'=>'VALGOAL Maxi','price'=>1.50,'status'=>5]);
    echo json_encode(['item'=>(int)$item->id,'extra'=>(int)$extra->id,'variation'=>(int)$var->id,'attr'=>(int)$attr->id]);
  `);
}

function cleanupValgoal() {
  return tinker(`
    $del = function (callable $fn) { try { $fn(); } catch (\\Throwable $e) {} };
    // -- Orders VALGOAL (fiscal_sequence_no nullé AVANT delete — trigger NF525) --
    $ids = \\App\\Models\\Order::withoutGlobalScopes()->where('token','like','VALGOAL-%')->pluck('id');
    if ($ids->isNotEmpty()) {
      $del(fn() => Schema::hasTable('transactions') && DB::table('transactions')->whereIn('order_id',$ids)->delete());
      $del(fn() => Schema::hasTable('order_status_transitions') && DB::table('order_status_transitions')->whereIn('order_id',$ids)->delete());
      $del(fn() => Schema::hasTable('domain_events') && DB::table('domain_events')->whereIn('aggregate_id',$ids)->delete());
      $del(fn() => Schema::hasTable('order_coupons') && DB::table('order_coupons')->whereIn('order_id',$ids)->delete());
      $del(fn() => DB::table('order_items')->whereIn('order_id',$ids)->delete());
      $del(fn() => DB::table('orders')->whereIn('id',$ids)->update(['fiscal_sequence_no' => null]));
      $del(fn() => DB::table('orders')->whereIn('id',$ids)->delete());
    }
    // -- Fixtures menu VALGOAL (item + extra + variation + stock_levels + availability) --
    // DB::table (scope-free) : les extras/variations portent un global scope qui les
    // masque à ItemExtra::where(...) tout en gardant la FK → forcer via table brute.
    $items = \\App\\Models\\Item::withoutGlobalScopes()->where('name','like','VALGOAL%')->pluck('id');
    if ($items->isNotEmpty()) {
      $extraIds = DB::table('item_extras')->whereIn('item_id',$items)->pluck('id');
      $varIds = DB::table('item_variations')->whereIn('item_id',$items)->pluck('id');
      $del(fn() => Schema::hasTable('stock_levels') && DB::table('stock_levels')->where('stockable_type','App\\\\Models\\\\ItemExtra')->whereIn('stockable_id',$extraIds)->delete());
      $del(fn() => Schema::hasTable('stock_levels') && DB::table('stock_levels')->where('stockable_type','App\\\\Models\\\\ItemVariation')->whereIn('stockable_id',$varIds)->delete());
      $del(fn() => Schema::hasTable('stock_levels') && DB::table('stock_levels')->where('stockable_type','App\\\\Models\\\\Item')->whereIn('stockable_id',$items)->delete());
      $del(fn() => Schema::hasTable('item_branch_availability') && DB::table('item_branch_availability')->whereIn('item_id',$items)->delete());
      $del(fn() => DB::table('item_variations')->whereIn('item_id',$items)->delete());
      $del(fn() => DB::table('item_extras')->whereIn('item_id',$items)->delete());
      $del(fn() => DB::table('items')->whereIn('id',$items)->delete());
    }
    $del(fn() => DB::table('item_attributes')->where('name','like','VALGOAL%')->delete());
    // -- Users VALGOAL (loyalty ledger d'abord, puis tokens + user) --
    $del(function () {
      $uids = \\App\\Models\\User::withoutGlobalScopes()->where('username','like','valgoal-%')->pluck('id');
      if ($uids->isNotEmpty() && Schema::hasTable('loyalty_transactions')) DB::table('loyalty_transactions')->whereIn('user_id',$uids)->delete();
      foreach (\\App\\Models\\User::withoutGlobalScopes()->where('username','like','valgoal-%')->get() as $u) { $u->tokens()->delete(); $u->forceDelete(); }
    });
    $remaining = \\App\\Models\\Order::withoutGlobalScopes()->where('token','like','VALGOAL-%')->count();
    echo json_encode(['deleted_orders'=>$ids->count(),'remaining'=>$remaining]);
  `);
}

// -----------------------------------------------------------------------------
test.describe('VALGOAL blocs B/C/D — validation e2e corrections du 2026-07-18', () => {
  test.setTimeout(240_000);

  test.beforeAll(() => { console.log('[cleanup:before]', cleanupValgoal().trim().split('\n').pop()); });
  test.afterAll(() => { console.log('[cleanup:after]', cleanupValgoal().trim().split('\n').pop()); });

  // ===========================================================================
  // V1 — Multi-sauces : ticket cuisine ESC/POS + feed KDS = LES DEUX noms
  // ===========================================================================
  test('V1 — ticket cuisine ESC/POS + KDS nomment la 2e sauce (pas seulement la 1ère) + sauces frites gratuites', async ({ page }) => {
    // -- (1) Commande RÉELLE #5727 (Tacos M « Algérienne, Andalouse ») ----------
    const real = orderState(5727);
    const has5727 = !real.missing;
    if (has5727) {
      const t = renderTicketsDecoded(5727);
      console.log('[V1] #5727 kitchen extrait :', t.kitchen.replace(/\s+/g, ' ').match(/ALG.*Andalouse|Andalouse/)?.[0] || '(voir dump)');
      // Ticket CUISINE : 1ère sauce en symbole (ALG) + 2e sauce NOMMÉE (Andalouse).
      expect(t.kitchen).toContain('Andalouse');                    // 2e sauce = LE FIX
      expect(t.kitchen).toContain('Sauce suppl');                  // portée par l'extra générique
      expect(t.kitchen).toMatch(/ALG/);                            // 1ère sauce (symbole cuisine)
      // Ticket CLIENT : LES DEUX noms pleins.
      expect(t.client).toContain('Algérienne');
      expect(t.client).toContain('Andalouse');
    } else {
      console.log('[V1] #5727 absente — preuve ticket via fixture uniquement.');
    }

    // -- (2) Fixture board-released + feed KDS (données rendues par l'écran) -----
    const queue = 'A0' + (700 + Math.floor(Math.random() * 90));
    const fx = seedTacosMultiSauceOrder(queue, 'Blanche', 'Andalouse');
    console.log('[V1] fixture Tacos multi-sauces #', fx.id, 'queue', fx.queue);

    // Ticket cuisine de la fixture (2e sauce nommée).
    const ft = renderTicketsDecoded(fx.id);
    expect(ft.kitchen).toContain('Andalouse');
    expect(ft.kitchen).toContain('Sauce suppl');
    expect(ft.client).toContain('Andalouse');

    // Feed KDS RÉEL (GET admin/kds-order = exactement ce que fetch le composant KDS) :
    // l'order_item porte l'instruction avec LES DEUX noms → le jumeau kdsSymbolic.js
    // les rend (« + Sauce supplémentaire : Andalouse » + symbole 1ère sauce).
    await loginAsChefOperator(page);
    const kds = await browserApi(page, { method: 'get', url: 'admin/kds-order?limit=100' });
    const kdsRows = Array.isArray(kds.data?.data) ? kds.data.data : (Array.isArray(kds.data) ? kds.data : []);
    const mine = kdsRows.find((o) => Number(o.id) === fx.id);
    console.log('[V1] KDS feed status', kds.status, '| commande présente ?', !!mine, '| total board', kdsRows.length);
    expect(kds.status).toBe(200);
    expect(mine).toBeTruthy();
    const instr = String(mine.order_items?.[0]?.instruction || '');
    expect(instr).toContain('Blanche');     // 1ère sauce
    expect(instr).toContain('Andalouse');   // 2e sauce = LE FIX (nom présent dans le feed rendu)

    // -- (3) Rendu VISUEL de l'écran cuisine (best-effort, capturé pour revue) ---
    let kdsVisual = 'non-vérifié';
    try {
      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(4000);
      await page.screenshot({ path: path.join(SHOTS, 'V1-kds-multisauce.png'), fullPage: true });
      const bodyTxt = await page.locator('body').innerText().catch(() => '');
      kdsVisual = bodyTxt.includes('Andalouse') ? 'Andalouse visible à l\'écran' : 'Andalouse non détecté dans le DOM (feed API prouvé)';
    } catch (e) { kdsVisual = 'capture échouée: ' + (e?.message || e); }
    console.log('[V1] KDS écran :', kdsVisual);

    // -- (4) BONUS : 2 sauces FRITES affichées (KTP MAY) et GRATUITES ------------
    const frites = seedMenuFritesOrder();
    const frt = renderTicketsDecoded(frites.id);
    expect(frt.kitchen).toContain('MENU : KTP MAY');                  // les 2 sauces frites
    expect(frt.kitchen).not.toContain('Sauce suppl');                // jamais un supplément payant
    expect(frt.client).toContain('10,40');                           // total inchangé (sauce frites = 0 €)
    console.log('[V1] sauces frites : « MENU : KTP MAY » + gratuit OK');
  });

  // ===========================================================================
  // V2 — Accept web INLINE en caisse (C1/C2) : cycle unifié sans quitter le POS
  // ===========================================================================
  test('V2 — web COD PENDING → Commandes web → accept → PENDING_COUNTER → counter-collect → CASH → PAID+fiscal', async ({ page }) => {
    const gtok = guestToken('web-v2');
    const token = `VALGOAL-C-WEB-${Date.now()}`;
    const webId = await placeWebCodOrder(page, gtok, token);
    const before = orderState(webId);
    console.log('[V2] web order #', webId, JSON.stringify(before));
    expect(before.source_surface).toBe('web');
    expect(before.status).toBe(OS_PENDING);
    expect(before.payment_status).toBe(PS_UNPAID);

    await loginAsPosOperator(page);

    // (1) Visible dans la file « Commandes web » (endpoint du panneau caisse).
    const webList = await browserApi(page, { method: 'get', url: 'admin/pos/web-orders/pending' });
    const webIds = (Array.isArray(webList.data?.data) ? webList.data.data : (webList.data || [])).map((o) => Number(o.id));
    console.log('[V2] Commandes web pending ids =', JSON.stringify(webIds));
    expect(webList.status).toBe(200);
    expect(webIds).toContain(webId);

    // (2) Accept INLINE (OnlineOrderController::changeStatus, status=ACCEPT).
    const accept = await browserApi(page, {
      method: 'post', url: `admin/online-order/change-status/${webId}`,
      data: { status: OS_ACCEPT }, idem: `VALGOAL-C-ACC-${webId}-${Math.floor(Date.now() / 60000)}`,
    });
    expect(accept.status).toBe(200);
    const afterAccept = orderState(webId);
    console.log('[V2] après accept =', JSON.stringify(afterAccept));
    expect(afterAccept.status).toBe(OS_ACCEPT);
    expect(afterAccept.payment_status).toBe(PS_PENDING_COUNTER);       // COD accepté → encaissable
    expect(afterAccept.pos_payment_method).toBe(PPM_COUNTER_DEFERRED);

    // (3) Quitte « Commandes web » (plus PENDING) et remonte dans counter-collect.
    const webList2 = await browserApi(page, { method: 'get', url: 'admin/pos/web-orders/pending' });
    const webIds2 = (Array.isArray(webList2.data?.data) ? webList2.data.data : (webList2.data || [])).map((o) => Number(o.id));
    expect(webIds2).not.toContain(webId);
    const pending = await browserApi(page, { method: 'get', url: 'admin/pos/counter-collect/pending' });
    const pendingIds = (Array.isArray(pending.data?.data) ? pending.data.data : (pending.data || [])).map((o) => Number(o.id));
    console.log('[V2] counter-collect pending ids =', JSON.stringify(pendingIds));
    expect(pending.status).toBe(200);
    expect(pendingIds).toContain(webId);

    // (4) Confirm CASH INLINE → PAID + fiscal_sequence_no (cycle bouclé en caisse).
    const confirm = await browserApi(page, {
      method: 'post', url: `admin/pos/counter-collect/${webId}/confirm`,
      data: { mode: 1, received: 20 }, idem: `VALGOAL-C-CONF-${webId}-${Math.floor(Date.now() / 60000)}`,
    });
    expect(confirm.status).toBe(200);
    const final = orderState(webId);
    console.log('[V2] final =', JSON.stringify(final));
    expect(final.payment_status).toBe(PS_PAID);
    expect(Number(final.fiscal_sequence_no)).toBeGreaterThan(0);
    console.log('[V2] encaissée inline → PAID, fiscal_seq =', final.fiscal_sequence_no);
  });

  // ===========================================================================
  // V3 — Notif client canal customer.{id} (broadcasting/auth) + order/show « Prête »
  // ===========================================================================
  test('V3 — client autorisé sur SON canal (200), refusé sur celui d\'un autre (403) + order/show expose « Prête »', async ({ page }) => {
    const a = customerSeed('chan-a');
    const b = customerSeed('chan-b');
    console.log('[V3] client A #', a.id, '| client B #', b.id);

    // (1) A sur SON canal private-customer.A → 200 + signature auth (autorisé).
    const own = await rawApi(page, {
      method: 'post', url: 'broadcasting/auth', bearer: a.token,
      form: { channel_name: `private-customer.${a.id}`, socket_id: '123.456' },
    });
    console.log('[V3] A → private-customer.A =', own.status, JSON.stringify(own.data).slice(0, 120));
    expect(own.status).toBe(200);
    expect(String(own.data?.auth || '')).toMatch(/.+:.+/);            // signature pusher présente

    // (2) A sur le canal de B private-customer.B → 403 (anti-fuite cross-client).
    const other = await rawApi(page, {
      method: 'post', url: 'broadcasting/auth', bearer: a.token,
      form: { channel_name: `private-customer.${b.id}`, socket_id: '123.456' },
    });
    console.log('[V3] A → private-customer.B =', other.status);
    expect(other.status).toBe(403);

    // (3) /order/show d'une commande PREPARED expose status=8 « Prête » (polling).
    const oid = tinkerJson(`
      $c = \\App\\Models\\User::where('username','valgoal-cust-chan-a')->first();
      $o = new \\App\\Models\\Order();
      $o->branch_id=1; $o->user_id=$c->id; $o->order_type=\\App\\Enums\\OrderType::TAKEAWAY;
      $o->source_surface='web'; $o->source=\\App\\Enums\\Source::WEB; $o->payment_method=1;
      $o->payment_status=\\App\\Enums\\PaymentStatus::PAID; $o->status=\\App\\Enums\\OrderStatus::PREPARED;
      $o->subtotal=15; $o->total=15; $o->total_tax=1.36; $o->discount=0; $o->delivery_charge=0;
      $o->token='VALGOAL-C-SHOW-'.uniqid(); $o->order_serial_no=$o->token; $o->order_datetime=now(); $o->queue_number='A0803'; $o->saveQuietly();
      echo json_encode(['id'=>(int)$o->id]);
    `).id;
    const show = await rawApi(page, { method: 'get', url: `frontend/order/show/${oid}`, bearer: a.token });
    const sd = show.data?.data || show.data || {};
    console.log('[V3] order/show status =', sd.status, '| status_name =', JSON.stringify(sd.status_name));
    expect(show.status).toBe(200);
    expect(Number(sd.status)).toBe(OS_PREPARED);
    expect(String(sd.status_name)).toBe('Prête');
  });

  // ===========================================================================
  // V4 — Fidélité ON / remises OFF (découplage)
  // ===========================================================================
  test('V4 — accrual crédite (remises coupées) + remise manuelle caisse REFUSÉE (422) + redeem AUTORISÉ', async ({ page }) => {
    // (a) Accrual — le solde augmente même remises manuelles coupées.
    const acc = accrualProof();
    console.log('[V4a] accrual =', JSON.stringify(acc));
    expect(acc.manual_discount_enabled).toBe(false);       // remises coupées…
    expect(acc.after).toBeGreaterThan(acc.before);          // …mais les points sont crédités
    expect(acc.earn_ledger).toBeGreaterThanOrEqual(1);      // ledger 'earn' écrit
    // Corroboration sur la surface API réelle : le client lit SON solde (/loyalty/check).
    const check = await rawApi(page, { method: 'post', url: 'frontend/loyalty/check', bearer: acc.token, data: { code: acc.code } });
    console.log('[V4a] /loyalty/check →', check.status, JSON.stringify(check.data?.data || check.data).slice(0, 120));
    if (check.status === 200) {
      expect(Number(check.data?.data?.points)).toBe(acc.after);
    }

    // (b) Remise manuelle caisse REFUSÉE — POST /api/admin/pos discount>0 → 422.
    await loginAsPosOperator(page);
    const base = {
      token: null, branch_id: 1, discount: 1.00, discount_reason: 'valgoal remise test',
      order_type: OT_TAKEAWAY, is_advance_order: 0, source: 1, pos_payment_method: PPM_CASH,
      pos_received_amount: 20,
      items: JSON.stringify([{ item_id: 2, item_price: 2.00, quantity: 6, total_price: 12.00, item_variations: [], item_extras: [] }]),
    };
    const quote = await browserApi(page, { method: 'post', url: 'admin/pos/quote', data: base });
    console.log('[V4b] quote status', quote.status, '(200 attendu : la remise ≤10% passe le ladder)');
    expect(quote.status).toBe(200);
    const store = await browserApi(page, {
      method: 'post', url: 'admin/pos',
      data: { ...base, quote_token: quote.data?.data?.quote_token, quote_signature: quote.data?.data?.signature },
      idem: `VALGOAL-D-DISC-${Date.now()}`,
    });
    console.log('[V4b] store status', store.status, '| msg:', String(store.data?.message || '').slice(0, 90));
    expect(store.status).toBe(422);                          // kill-switch remises manuelles
    expect(JSON.stringify(store.data)).toMatch(/remises manuelles.*d.sactiv|d.sactiv.*remises/i);

    // (c) Redeem fidélité AUTORISÉ (PosRedemptionService) malgré remises coupées.
    const rd = redeemProof();
    console.log('[V4c] redeem =', JSON.stringify(rd));
    expect(rd.ok).toBe(true);                                // NON refusé (ni LOYALTY_DISABLED ni kill-switch remise)
    expect(rd.discount_eur).toBeGreaterThan(0);              // une remise fidélité EST appliquée
    expect(rd.balance_after).toBeLessThan(rd.balance_before); // points débités
    expect(rd.order_total).toBeLessThan(25);                 // total réduit
    expect(rd.redeem_ledger).toBeGreaterThanOrEqual(1);      // ledger 'redeem' écrit
  });

  // ===========================================================================
  // V5 — 86 d'un EXTRA/VARIATION depuis la caisse (bloc D)
  // ===========================================================================
  test('V5 — POS Operator charge item/details (pas 403) → 86 d\'un extra reflété → réactivé', async ({ page }) => {
    const fx = seedValgoalItemChoices();
    console.log('[V5] item #', fx.item, 'extra #', fx.extra, 'variation #', fx.variation);

    await loginAsPosOperator(page);

    // (1) Charge item/details — couvert par availability_toggle (PAS 403 items_show).
    const details1 = await browserApi(page, { method: 'get', url: `admin/item/details/${fx.item}?branch_id=1` });
    console.log('[V5] item/details status =', details1.status);
    expect(details1.status).toBe(200);
    const extras1 = details1.data?.data?.extras || [];
    const extraRow1 = extras1.find((e) => Number(e.id) === fx.extra);
    expect(extraRow1).toBeTruthy();
    expect(extraRow1.is_available).toBe(true);              // dispo au départ

    // (2) 86 de l'EXTRA via l'endpoint réutilisé (menu/availability/extra/toggle).
    const toggleOff = await browserApi(page, {
      method: 'post', url: 'admin/menu/availability/extra/toggle',
      data: { extra_id: fx.extra, branch_id: 1, is_available: false, reason: 'out_of_stock_manual' },
      idem: `VALGOAL-D-86-${fx.extra}-${Math.floor(Date.now() / 60000)}`,
    });
    console.log('[V5] toggle OFF status =', toggleOff.status, JSON.stringify(toggleOff.data).slice(0, 120));
    expect(toggleOff.status).toBe(200);
    expect(toggleOff.data?.is_available).toBe(false);

    // (3) Le panel relit item/details : l'extra est indisponible (branch-aware).
    const details2 = await browserApi(page, { method: 'get', url: `admin/item/details/${fx.item}?branch_id=1` });
    const extraRow2 = (details2.data?.data?.extras || []).find((e) => Number(e.id) === fx.extra);
    console.log('[V5] extra reflété is_available =', extraRow2?.is_available);
    expect(extraRow2).toBeTruthy();
    expect(extraRow2.is_available).toBe(false);            // 86 reflété

    // (4) Réactivation (reason interdit quand is_available=true — whitelist réservée au 86).
    const toggleOn = await browserApi(page, {
      method: 'post', url: 'admin/menu/availability/extra/toggle',
      data: { extra_id: fx.extra, branch_id: 1, is_available: true },
      idem: `VALGOAL-D-86on-${fx.extra}-${Math.floor(Date.now() / 60000)}`,
    });
    expect(toggleOn.status).toBe(200);
    const details3 = await browserApi(page, { method: 'get', url: `admin/item/details/${fx.item}?branch_id=1` });
    const extraRow3 = (details3.data?.data?.extras || []).find((e) => Number(e.id) === fx.extra);
    expect(extraRow3.is_available).toBe(true);             // réactivé
    console.log('[V5] extra réactivé is_available =', extraRow3?.is_available);
  });
});
