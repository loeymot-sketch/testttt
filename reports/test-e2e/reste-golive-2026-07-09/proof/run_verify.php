<?php
require __DIR__.'/../../../../vendor/autoload.php';
$app = require_once __DIR__.'/../../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Order;
use App\Enums\PaymentStatus;
use App\Enums\OrderType;
use App\Enums\OrderStatus;
use App\Enums\PosPaymentMethod;
use App\Domain\Kds\KitchenReleaseRule;

$id = 5622;
$co = Order::find($id);
echo "=== ORDER $id CORE ===\n";
echo json_encode(['id'=>$co->id,'source_surface'=>$co->source_surface,'order_type'=>$co->order_type,'status'=>$co->status,'payment_status'=>$co->payment_status,'payment_method'=>$co->payment_method,'pos_payment_method'=>$co->pos_payment_method,'total'=>$co->total,'subtotal'=>$co->subtotal,'total_tax'=>$co->total_tax,'queue_number'=>$co->queue_number,'branch_id'=>$co->branch_id,'fiscal_sequence_no'=>$co->fiscal_sequence_no])."\n";

$caisse = Order::query()
    ->where('payment_status', PaymentStatus::PENDING_COUNTER)
    ->whereNotIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED])
    ->where(function ($q) {
        $q->where(function ($k) { $k->where('source_surface','kiosk')->whereIn('order_type',[OrderType::KIOSK,OrderType::TAKEAWAY]); })
          ->orWhere(function ($p) { $p->where('source_surface','pos')->where('pos_payment_method', PosPaymentMethod::COUNTER_DEFERRED); });
    })->where('branch_id',1)->orderBy('created_at')->pluck('id')->all();
echo "=== (A) CAISSE ===\n";
echo "order $id in caisse counter-collect/pending: ".(in_array($id,$caisse)?'YES':'NO')."\n";
echo "caisse queue tail: ".implode(',',array_slice($caisse,-6))."\n";

$kq = Order::query()->whereIn('status', KitchenReleaseRule::visibleStatuses());
KitchenReleaseRule::applyBoardReleaseFilter($kq);
$kq->where('branch_id',1);
$kdsIds = $kq->pluck('id')->all();
echo "=== (B) KDS ===\n";
echo "visibleStatuses=".implode(',',KitchenReleaseRule::visibleStatuses())."\n";
echo "order $id on KDS board: ".(in_array($id,$kdsIds)?'YES':'NO')."\n";
echo "orderIsReleasedForBoard=".(KitchenReleaseRule::orderIsReleasedForBoard($co)?'YES':'NO')."\n";
echo "KDS board tail: ".implode(',',array_slice($kdsIds,-6))."\n";
