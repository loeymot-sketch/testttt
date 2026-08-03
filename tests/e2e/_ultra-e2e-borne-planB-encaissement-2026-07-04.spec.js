// [ULTRA A→Z e2e-en-boucle 2026-07-04] Chaîne LIVE cross-surface Plan B :
// borne takeaway → commande PENDING_COUNTER → KDS board-release → file encaissement caisse →
// confirmCounterPayment → PAID + fiscal_sequence_no alloué (valide le heal Wave 2 fiscal-at-encaissement).
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAsKiosk } = require('./helpers/login');

const SHOT_DIR = path.join(process.cwd(), 'reports/ultra-review/2026-07-04/e2e');
const PREFIX = 'ULTRA-E2E-BORNE';

function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: process.cwd(), encoding: 'utf8' }).trim();
}
function lastJson(out) {
  const line = String(out).split(/\r?\n/).map(s => s.trim()).filter(Boolean).reverse().find(s => s.startsWith('{') || s.startsWith('['));
  if (!line) throw new Error('No JSON in artisan output:\n' + out);
  return JSON.parse(line);
}
function phpStr(v) { return String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'"); }

function cleanup() {
  artisan(`
    $ids = DB::table('items')->where('name','like','${PREFIX}%')->pluck('id');
    if ($ids->isNotEmpty()) {
      $oids = DB::table('order_items')->whereIn('item_id',$ids)->pluck('order_id')->unique();
      if ($oids->isNotEmpty()) {
        foreach(['order_status_transitions','stock_movements','domain_events','order_payments','order_items'] as $t){ if(Schema::hasTable($t)){ $col=$t==='stock_movements'?'reference_id':($t==='domain_events'?'aggregate_id':'order_id'); DB::table($t)->whereIn($col,$oids)->delete(); } }
        DB::table('orders')->whereIn('id',$oids)->update(['fiscal_sequence_no' => null]);
        DB::table('orders')->whereIn('id',$oids)->delete();
      }
      DB::table('stock_levels')->where('stockable_type',App\\Models\\Item::class)->whereIn('stockable_id',$ids)->delete();
      DB::table('item_branch_availability')->whereIn('item_id',$ids)->delete();
      DB::table('items')->whereIn('id',$ids)->delete();
    }
    DB::table('item_categories')->where('name','like','${PREFIX}%')->delete();
    DB::table('taxes')->where('name','like','${PREFIX}%')->delete();
    Cache::flush();
    echo json_encode(['ok'=>true]);
  `);
}

function fixture() {
  return lastJson(artisan(`
    use App\\Models\\{User,KioskMachine,Item,ItemCategory,Tax,ItemBranchAvailability,StockLevel};
    use App\\Enums\\{Status,TaxType};
    use Illuminate\\Support\\Str; use Illuminate\\Support\\Facades\\Hash;
    $mu = User::where('username','kiosk-lecayenne')->first() ?: User::where('branch_id','>',0)->firstOrFail();
    $bid = (int)($mu->branch_id ?: 1);
    KioskMachine::updateOrCreate(['username'=>'kiosk-lecayenne'],['user_id'=>$mu->id,'branch_id'=>$bid,'machine_id'=>'${PREFIX}','password'=>Hash::make('kiosk123'),'is_login'=>1,'status'=>Status::ACTIVE]);
    $run = strtoupper(substr(bin2hex(random_bytes(3)),0,6));
    $tax = Tax::create(['name'=>'${PREFIX} TVA '.$run,'code'=>'UE-'.$run,'tax_rate'=>0,'type'=>TaxType::PERCENTAGE,'status'=>Status::ACTIVE]);
    $cat = ItemCategory::create(['name'=>'${PREFIX} Cat '.$run,'slug'=>Str::slug('${PREFIX}-cat-'.$run.'-'.Str::random(4)),'status'=>Status::ACTIVE,'sort'=>9995,'kiosk_sort'=>1,'wizard_template'=>'simple','has_menu'=>false,'kiosk_upsell_skip_after_cart'=>true,'channels'=>['kiosk']]);
    $it = Item::create(['name'=>'${PREFIX} Burger '.$run,'slug'=>Str::slug('${PREFIX}-burger-'.$run.'-'.Str::random(4)),'item_category_id'=>$cat->id,'tax_id'=>$tax->id,'price'=>8.50,'status'=>Status::ACTIVE,'is_available'=>true,'is_featured'=>1,'order'=>1,'channels'=>['kiosk'],'kiosk_emoji'=>'burger']);
    ItemBranchAvailability::updateOrCreate(['branch_id'=>$bid,'item_id'=>$it->id],['is_available'=>true,'daily_consumed_qty'=>0,'daily_reset_at'=>now()->toDateString()]);
    StockLevel::updateOrCreate(['branch_id'=>$bid,'stockable_type'=>Item::class,'stockable_id'=>$it->id],['on_hand'=>99,'reserved'=>0]);
    Cache::flush();
    echo json_encode(['ok'=>true,'branch_id'=>$bid,'category_id'=>(int)$cat->id,'item_id'=>(int)$it->id,'item_name'=>(string)$it->name,'price'=>8.50]);
  `));
}

function inspect(orderId) {
  return lastJson(artisan(`
    $o = App\\Models\\Order::withoutGlobalScopes()->findOrFail(${Number(orderId)});
    $onBoard = App\\Domain\\Kds\\KitchenReleaseRule::orderIsReleasedForBoard($o) ?? null;
    echo json_encode([
      'id'=>(int)$o->id,'source_surface'=>$o->source_surface,'order_type'=>(int)$o->order_type,
      'status'=>(int)$o->status,'payment_status'=>(int)$o->payment_status,
      'pos_payment_method'=>$o->pos_payment_method===null?null:(int)$o->pos_payment_method,
      'total'=>round((float)$o->total,2),'fiscal_sequence_no'=>$o->fiscal_sequence_no===null?null:(int)$o->fiscal_sequence_no,
      'released_for_board'=>$onBoard,
    ]);
  `));
}

// Encaissement Plan B via le service réel (confirmCounterPayment) — comme la caisse.
function encaisse(orderId) {
  return lastJson(artisan(`
    $o = App\\Models\\Order::withoutGlobalScopes()->findOrFail(${Number(orderId)});
    try {
      app(App\\Services\\PaymentService::class)->confirmCounterPayment($o, App\\Enums\\PosPaymentMethod::CASH, (float)$o->total, null);
      $f = App\\Models\\Order::withoutGlobalScopes()->findOrFail($o->id);
      echo json_encode(['ok'=>true,'payment_status'=>(int)$f->payment_status,'fiscal_sequence_no'=>$f->fiscal_sequence_no===null?null:(int)$f->fiscal_sequence_no]);
    } catch (\\Throwable $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
  `));
}

async function shot(page, name) {
  fs.mkdirSync(SHOT_DIR, { recursive: true });
  await page.screenshot({ path: path.join(SHOT_DIR, name + '.png'), fullPage: false }).catch(() => {});
}

test.describe('ULTRA e2e borne Plan B → encaissement → fiscal', () => {
  test.describe.configure({ timeout: 240_000, retries: 0 });

  test('borne takeaway → PENDING_COUNTER → KDS board → encaissement → PAID+fiscal', async ({ browser }) => {
    cleanup();
    const fx = fixture();
    const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    let order = null;

    try {
      await loginAsKiosk(page);
      await expect(page.getByTestId('kiosk-idle-root')).toBeVisible({ timeout: 25_000 });
      await shot(page, 'e2e-01-idle');

      await page.getByTestId('kiosk-order-type-takeaway').click();
      await expect(page.getByTestId('kiosk-categories-root')).toBeVisible({ timeout: 25_000 });
      await page.goto(`/kiosk/categories?cat=${fx.category_id}`, { waitUntil: 'domcontentloaded' });
      await expect(page.getByTestId(`kiosk-product-card-${fx.item_id}`)).toBeVisible({ timeout: 25_000 });
      await shot(page, 'e2e-02-catalogue');

      await page.getByTestId(`kiosk-product-add-${fx.item_id}`).click();
      // simple wizard : un récap peut apparaître → valider
      await page.waitForTimeout(1200);
      const summary = page.getByTestId('kiosk-order-summary-root');
      if (await summary.isVisible({ timeout: 1500 }).catch(() => false)) {
        await page.locator('.kiosk-wizard .kiosk-btn-next').last().click().catch(() => {});
      }
      await expect(page.getByTestId('kiosk-categories-cart-indicator')).toContainText(/1\s+article/i, { timeout: 20_000 });
      await shot(page, 'e2e-03-cart-indicator');

      await page.getByTestId('kiosk-categories-pay').click();
      await expect(page.getByTestId('kiosk-cart-root')).toBeVisible({ timeout: 20_000 });
      await page.getByTestId('kiosk-cart-order-type-takeaway').click().catch(() => {});
      await shot(page, 'e2e-04-cart');

      // Waiter POSÉ AVANT le checkout — la commande borne est POSTée au clic « confirmer au comptoir ».
      const orderRespP = page.waitForResponse(
        r => r.request().method() === 'POST' && /\/api\/frontend\/order$/.test(r.url()),
        { timeout: 90_000 },
      ).catch(() => null);

      await page.getByTestId('kiosk-cart-checkout').click();

      // L'upsell (« ET POUR TERMINER ? ») s'intercale entre panier et paiement. Le bouton skip se
      // re-rend toutes les 100 ms (timer autoskip 30 s) → un clic unique peut rater. On clique en
      // boucle jusqu'à quitter l'upsell, avec l'autoskip (30 s) comme filet déterministe.
      const upsellRoot = page.getByTestId('kiosk-upsell-root');
      if (await upsellRoot.isVisible({ timeout: 6_000 }).catch(() => false)) {
        await shot(page, 'e2e-05a-upsell');
        for (let i = 0; i < 6; i++) {
          if (await page.getByTestId('kiosk-payment-root').isVisible({ timeout: 1_500 }).catch(() => false)) break;
          await page.getByTestId('kiosk-upsell-skip').click({ force: true, timeout: 4_000 }).catch(() => {});
          await page.waitForTimeout(1_500);
        }
      }

      // Écran paiement Plan B (route comptoir). 45 s couvre le filet autoskip (30 s) + chargement.
      await expect(page.getByTestId('kiosk-payment-root')).toBeVisible({ timeout: 45_000 });
      await shot(page, 'e2e-05-payment');
      const confirmBtn = page.getByTestId('kiosk-payment-counter-confirm');
      await expect(confirmBtn, 'bouton « confirmer au comptoir » (Plan B)').toBeVisible({ timeout: 25_000 });
      await confirmBtn.click();

      const orderResp = await orderRespP;
      expect(orderResp, 'POST /api/frontend/order capturé').not.toBeNull();
      expect(orderResp.status(), 'POST /api/frontend/order doit réussir').toBeLessThan(400);
      const body = await orderResp.json();
      order = body.data || body;
      await page.waitForTimeout(1800);
      await shot(page, 'e2e-06-confirmation');

      // ---- Vérifications backend cross-surface ----
      const a = inspect(order.id);
      expect(a.source_surface, 'source_surface=kiosk').toBe('kiosk');
      expect(a.payment_status, 'borne Plan B = PENDING_COUNTER (15)').toBe(15);
      expect(a.fiscal_sequence_no, 'pas de fiscal avant encaissement (Plan B)').toBeNull();
      expect(a.released_for_board, 'commande sur le board KDS (board-release PENDING_COUNTER)').toBe(true);

      // ---- Encaissement (valide le heal fiscal-at-encaissement) ----
      const enc = encaisse(order.id);
      expect(enc.ok, 'encaissement réussit: ' + JSON.stringify(enc)).toBe(true);
      expect(enc.payment_status, 'PAID après encaissement (5)').toBe(5);
      expect(enc.fiscal_sequence_no, 'fiscal_sequence_no ALLOUÉ à l\'encaissement').not.toBeNull();
      expect(enc.fiscal_sequence_no).toBeGreaterThan(0);

      fs.mkdirSync(SHOT_DIR, { recursive: true });
      fs.writeFileSync(path.join(SHOT_DIR, 'e2e-borne-planB-result.json'),
        JSON.stringify({ fixture: fx, order_id: order.id, before: a, after_encaissement: enc, pageerrors: errors }, null, 2));
      expect(errors.filter(e => /TypeError|ReferenceError|Cannot read|is not a function/i.test(e))).toHaveLength(0);
    } finally {
      await ctx.close().catch(() => {});
      cleanup();
    }
  });
});
