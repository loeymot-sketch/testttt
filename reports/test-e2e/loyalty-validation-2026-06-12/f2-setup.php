<?php
// F2 lane setup — run via: APP_ENV=e2e php artisan tinker reports/test-e2e/loyalty-validation-2026-06-12/f2-setup.php
use App\Models\User;
use App\Models\Order;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentGateway;
use App\Enums\PosPaymentMethod;
use App\Enums\OrderType;
use Illuminate\Support\Facades\DB;

echo 'DB=' . DB::connection()->getDatabaseName() . PHP_EOL;
echo 'api_key=' . config('app.api_key') . PHP_EOL;

// Customer with 500 pts
$cust = User::withoutGlobalScopes()->where('loyalty_code', 'F2LOYAL1')->first();
if (!$cust) {
    $cust = User::factory()->create([
        'branch_id' => 1,
        'name' => 'F2 Lane Customer',
        'phone' => '0699000201',
    ]);
}
DB::table('users')->where('id', $cust->id)->update([
    'loyalty_code' => 'F2LOYAL1',
    'loyalty_points' => 500,
    'status' => \App\Enums\Status::ACTIVE,
]);
echo 'customer id=' . $cust->id . ' code=F2LOYAL1 points=500' . PHP_EOL;

$mk = function (string $serial, float $subtotal) {
    $o = Order::withoutGlobalScopes()->where('order_serial_no', $serial)->first();
    if ($o) { echo "order $serial exists id=" . $o->id . PHP_EOL; return $o; }
    $o = Order::factory()->create([
        'order_serial_no'    => $serial,
        'branch_id'          => 1,
        'subtotal'           => $subtotal,
        'discount'           => 0.00,
        'total_tax'          => 0.00,
        'delivery_charge'    => 0.00,
        'total'              => $subtotal,
        'status'             => OrderStatus::PENDING,
        'payment_status'     => PaymentStatus::UNPAID,
        'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
        'pos_payment_method' => PosPaymentMethod::CASH,
        'order_type'         => OrderType::TAKEAWAY,
        'source_surface'     => 'pos',
    ]);
    echo "order $serial created id=" . $o->id . ' subtotal=' . $subtotal . PHP_EOL;
    return $o;
};

$o1 = $mk('F2-RDM-1', 25.00);   // refus cases + exact-100 redeem + reversal cancel
$o2 = $mk('F2-RDM-2', 3.00);    // redeem > total commande
$o3 = $mk('F2-RDM-3', 25.00);   // double-redeem race
$o4 = $mk('F2-RDM-4', 25.00);   // refund-with-counter-entry reversal + clawback (will be paid)
echo 'SETUP_OK ' . json_encode(['cust' => $cust->id, 'o1' => $o1->id, 'o2' => $o2->id, 'o3' => $o3->id, 'o4' => $o4->id]) . PHP_EOL;
