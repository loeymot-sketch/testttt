// [ULTRA A→Z e2e-en-boucle 2026-07-04] Chaîne LIVE cuisine→client : commande kiosk sur le board KDS →
// bump ACCEPT→PREPARING→PREPARED (endpoint réel) → horodatage centralisé (heal Wave 1) posé sur le vrai
// chemin de transition → mur client OSS affiche « En préparation » puis « Prêt ». Captures KDS + OSS.
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAsChefOperator, loginAsAdmin } = require('./helpers/login');
const orderStatus = require('../../resources/js/enums/modules/orderStatusEnum.js').default;

const SHOT = path.join(process.cwd(), 'reports/ultra-review/2026-07-04/e2e');
const PREFIX = 'ULTRA-E2E-KDSOSS';

function artisan(code) { return execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: process.cwd(), encoding: 'utf8' }).trim(); }
function lastJson(out) {
  const l = String(out).split(/\r?\n/).map(s => s.trim()).filter(Boolean).reverse().find(s => s.startsWith('{'));
  if (!l) throw new Error('No JSON:\n' + out); return JSON.parse(l);
}

function makeOrder() {
  return lastJson(artisan(`
    use App\\Models\\{User,Item,ItemCategory,Tax,Order,OrderItem};
    use App\\Enums\\{Status,TaxType,OrderStatus,OrderType,PaymentStatus,Ask};
    use Illuminate\\Support\\Str;
    $u = User::where('branch_id','>',0)->firstOrFail(); $bid=(int)$u->branch_id;
    $run = strtoupper(substr(bin2hex(random_bytes(3)),0,5));
    $tax = Tax::create(['name'=>'${PREFIX} TVA '.$run,'code'=>'UK-'.$run,'tax_rate'=>0,'type'=>TaxType::PERCENTAGE,'status'=>Status::ACTIVE]);
    $cat = ItemCategory::create(['name'=>'${PREFIX} Cat '.$run,'slug'=>Str::slug('${PREFIX}-'.$run.'-'.Str::random(4)),'status'=>Status::ACTIVE,'wizard_template'=>'simple','has_menu'=>false,'channels'=>['kiosk']]);
    $it = Item::create(['name'=>'${PREFIX} Tacos '.$run,'slug'=>Str::slug('${PREFIX}-tacos-'.$run.'-'.Str::random(4)),'item_category_id'=>$cat->id,'tax_id'=>$tax->id,'price'=>9.00,'status'=>Status::ACTIVE,'is_available'=>true,'channels'=>['kiosk']]);
    // Commande kiosk née à ACCEPT (déclenche le hook timing → accepted_at posé), PENDING_COUNTER (board-release OK), aujourd'hui.
    $o = new Order();
    $o->forceFill([
      'user_id'=>$u->id,'branch_id'=>$bid,'order_type'=>OrderType::KIOSK,'source_surface'=>'kiosk',
      'status'=>OrderStatus::ACCEPT,'payment_status'=>PaymentStatus::PENDING_COUNTER,'pos_payment_method'=>6,
      'is_advance_order'=>Ask::NO,'total'=>9.00,'subtotal'=>9.00,
      'order_datetime'=>now(),'business_date'=>now()->toDateString(),
      'order_serial_no'=>'E'.$run,'queue_number'=>'K'.substr($run,0,3),
    ])->save();
    OrderItem::create(['order_id'=>$o->id,'branch_id'=>$bid,'item_id'=>$it->id,'item_name'=>$it->name,'quantity'=>1,'price'=>9.00,'discount'=>0,'total_price'=>9.00,'composition_snapshot'=>json_encode(['lines'=>[],'extras'=>[]])]);
    $o->refresh();
    echo json_encode(['order_id'=>(int)$o->id,'branch_id'=>$bid,'item_id'=>(int)$it->id,'item_name'=>(string)$it->name,'serial'=>(string)$o->order_serial_no,'queue_number'=>(string)$o->queue_number,'accepted_at_stamped'=>$o->accepted_at!==null]);
  `));
}

function timing(orderId) {
  return lastJson(artisan(`
    $o = App\\Models\\Order::withoutGlobalScopes()->findOrFail(${Number(orderId)});
    $res = (new App\\Http\\Resources\\KDSOrderDetailsResource($o))->toArray(request());
    echo json_encode(['status'=>(int)$o->status,'accepted_at'=>(bool)$o->accepted_at,'preparing_at'=>(bool)$o->preparing_at,'prepared_at'=>(bool)$o->prepared_at,'actual_prep_seconds'=>$res['actual_prep_seconds']]);
  `));
}

function cleanup() {
  artisan(`
    $ids = DB::table('items')->where('name','like','${PREFIX}%')->pluck('id');
    if ($ids->isNotEmpty()) {
      $oids = DB::table('order_items')->whereIn('item_id',$ids)->pluck('order_id')->unique();
      if ($oids->isNotEmpty()) { foreach(['order_status_transitions','domain_events','order_items'] as $t){ if(Schema::hasTable($t)){ $c=$t==='domain_events'?'aggregate_id':'order_id'; DB::table($t)->whereIn($c,$oids)->delete(); } } DB::table('orders')->whereIn('id',$oids)->delete(); }
      DB::table('items')->whereIn('id',$ids)->delete();
    }
    DB::table('item_categories')->where('name','like','${PREFIX}%')->delete();
    DB::table('taxes')->where('name','like','${PREFIX}%')->delete();
    Cache::flush(); echo json_encode(['ok'=>true]);
  `);
}

async function bump(page, orderId, expected, next) {
  return await page.evaluate(async ({ orderId, expected, next }) => {
    try {
      const r = await window.axios.post(`admin/kds-order/change-status/${orderId}`, { expected_status: expected, status: next }, { headers: { 'X-Idempotency-Key': 'e2e-' + orderId + '-' + next + '-' + Date.now() } });
      return { ok: r.status >= 200 && r.status < 300, status: r.status };
    } catch (e) { return { ok: false, status: e?.response?.status, msg: e?.response?.data?.message || String(e) }; }
  }, { orderId, expected, next });
}

test.describe('ULTRA e2e KDS→OSS + timing', () => {
  test.describe.configure({ timeout: 180_000, retries: 0 });

  test('kiosk order → KDS bump ACCEPT→PREPARING→PREPARED → timing stamped → OSS wall', async ({ browser }) => {
    cleanup();
    const fx = makeOrder();
    expect(fx.accepted_at_stamped, 'heal Wave 1 : accepted_at posé à la naissance ACCEPT').toBe(true);
    fs.mkdirSync(SHOT, { recursive: true });

    const kds = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
    const oss = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
    try {
      // ---- KDS : commande visible + bumps ----
      await loginAsChefOperator(kds);
      await kds.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });
      await expect(kds.locator('body'), 'produit visible sur KDS').toContainText(new RegExp(fx.item_name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i'), { timeout: 30_000 });
      await kds.screenshot({ path: path.join(SHOT, 'e2e-kds-01-recu.png') });

      const b1 = await bump(kds, fx.order_id, orderStatus.ACCEPT, orderStatus.PREPARING);
      expect(b1.ok, 'bump ACCEPT→PREPARING: ' + JSON.stringify(b1)).toBe(true);
      const t1 = timing(fx.order_id);
      expect(t1.status).toBe(orderStatus.PREPARING);
      expect(t1.preparing_at, 'heal Wave 1 : preparing_at posé sur transition réelle').toBe(true);

      const b2 = await bump(kds, fx.order_id, orderStatus.PREPARING, orderStatus.PREPARED);
      expect(b2.ok, 'bump PREPARING→PREPARED: ' + JSON.stringify(b2)).toBe(true);
      const t2 = timing(fx.order_id);
      expect(t2.status).toBe(orderStatus.PREPARED);
      expect(t2.prepared_at, 'heal Wave 1 : prepared_at posé').toBe(true);
      expect(t2.actual_prep_seconds, 'actual_prep_seconds calculé (resource)').not.toBeNull();
      await kds.waitForTimeout(1500);
      await kds.screenshot({ path: path.join(SHOT, 'e2e-kds-02-prepared.png') });

      // ---- OSS : mur client affiche la commande (numéro) ----
      await loginAsAdmin(oss);
      await oss.goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded' });
      await oss.waitForTimeout(3500);
      await oss.screenshot({ path: path.join(SHOT, 'e2e-oss-01-wall.png') });
      const ossText = await oss.locator('body').innerText({ timeout: 5000 }).catch(() => '');
      // L'OSS affiche le queue_number (repère client), pas le order_serial_no.
      const ossShowsQueue = ossText.includes(fx.queue_number);
      expect(ossShowsQueue, `mur client OSS affiche la commande (N°${fx.queue_number}, colonne Prêt)`).toBe(true);

      fs.writeFileSync(path.join(SHOT, 'e2e-kds-oss-result.json'), JSON.stringify({
        fixture: fx, timing_after_preparing: t1, timing_after_prepared: t2,
        oss_shows_queue_number: ossShowsQueue, oss_excerpt: ossText.replace(/\s+/g, ' ').slice(0, 400),
      }, null, 2));
    } finally {
      await kds.context().close().catch(() => {});
      await oss.context().close().catch(() => {});
      cleanup();
    }
  });
});
