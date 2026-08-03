<?php
use App\Models\Order;
use App\Models\User;
use App\Enums\PaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Services\KitchenDisplaySystemOrderService;
use App\Domain\Kds\KitchenReleaseRule;

$out = [];
$admin = User::withoutGlobalScopes()->where('branch_id', 0)->first();
$out[] = 'ADMIN user id='.($admin? $admin->id : 'NONE').' branch_id='.($admin? $admin->branch_id : '-').' can(pos)='.($admin && $admin->can('pos')?'Y':'N');
if(!$admin){ echo implode(PHP_EOL,$out); return; }
Auth::shouldUse('web');
Auth::guard('web')->setUser($admin);

// ---- (1) REAL KDS service list() ----
$svc = app(KitchenDisplaySystemOrderService::class);
$req = Request::create('/kds/list', 'GET', []);
app()->instance('request', $req);
$resp = $svc->list($req);
$coll = (is_object($resp) && property_exists($resp,'collection')) ? $resp->collection : $resp;
$ids = collect($coll)->map(function($r){
   if (is_array($r)) return (int)($r['id']??0);
   if (is_object($r) && isset($r->resource)) return (int)$r->resource->id;
   if (is_object($r) && isset($r->id)) return (int)$r->id;
   return 0;
})->all();
$out[] = 'KDS list count='.count($ids);
$out[] = 'KDS contains 5622 = '.(in_array(5622,$ids,true)?'YES':'NO');
$out[] = 'KDS contains 5621 = '.(in_array(5621,$ids,true)?'YES(unexpected)':'NO(expected absent)');
$out[] = 'KDS tail ids = '.implode(',', array_slice($ids,-10));

// ---- (2) REAL caisse counter-collect pending query ----
$query = Order::query()
    ->where('payment_status', PaymentStatus::PENDING_COUNTER)
    ->whereNotIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED])
    ->where(function ($q) {
        $q->where(function ($k) {
            $k->where('source_surface', 'kiosk')->whereIn('order_type', [OrderType::KIOSK, OrderType::TAKEAWAY]);
        })->orWhere(function ($p) {
            $p->where('source_surface', 'pos')->where('pos_payment_method', PosPaymentMethod::COUNTER_DEFERRED);
        })->orWhere(function ($tel) {
            $tel->where('source_surface', 'phone')->where('pos_payment_method', PosPaymentMethod::COUNTER_DEFERRED);
        })->orWhere(function ($n) {
            $n->whereNull('source_surface')->whereIn('order_type', [OrderType::KIOSK, OrderType::TAKEAWAY]);
        });
    })->orderBy('created_at');
$branchId = (int) ($admin->branch_id ?? 0);
if ($branchId > 0) { $query->where('branch_id', $branchId); }
$caisseIds = $query->limit(200)->pluck('id')->map(fn($x)=>(int)$x)->all();
$out[] = 'CAISSE pending count='.count($caisseIds);
$out[] = 'CAISSE contains 5622 = '.(in_array(5622,$caisseIds,true)?'YES':'NO');
$out[] = 'CAISSE tail ids = '.implode(',', array_slice($caisseIds,-10));

$o5622 = Order::withoutGlobalScopes()->find(5622);
$out[] = 'ORDER5622 total='.$o5622->total.' pay_status='.$o5622->payment_status.' status='.$o5622->status.' released='.(KitchenReleaseRule::orderIsReleasedForBoard($o5622)?'YES':'NO');
$o5621 = Order::withoutGlobalScopes()->find(5621);
if($o5621){ $out[] = 'ORDER5621 pay_method='.$o5621->payment_method.' pay_status='.$o5621->payment_status.' status='.$o5621->status.' source='.$o5621->source_surface.' type='.$o5621->order_type.' released='.(KitchenReleaseRule::orderIsReleasedForBoard($o5621)?'YES':'NO'); }
else { $out[] = 'ORDER5621 NOT FOUND'; }

echo implode(PHP_EOL,$out).PHP_EOL;
