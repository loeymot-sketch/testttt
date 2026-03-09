<?php
// Script de stress test E2E pour générer 10 commandes POS rapidement
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Item;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Services\OrderService;
use App\Http\Requests\PosOrderRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

$admin = User::find(1);
Auth::guard('web')->login($admin);

$terminator = Item::where('name', 'Le Terminator (2 Viandes)')->first();
$viande1 = $terminator->variations()->where('name', 'Poulet')->first();
$sauce = $terminator->variations()->where('name', 'Algérienne')->first();

$variations = [];
if ($viande1)
    $variations[] = $viande1->id;
if ($sauce)
    $variations[] = $sauce->id;

echo "⚠️ Lancement de 10 commandes concurrentes vers le Backend (POS -> OrderService)\n";
$timeStart = microtime(true);
$successes = 0;

for ($i = 0; $i < 10; $i++) {
    $cartItems = [
        [
            'branch_id' => 1,
            'item_id' => $terminator->id,
            'quantity' => rand(1, 3), // Quantité variée
            'discount' => 0,
            'tax' => 0,
            'price' => 9.00,
            'item_price' => 9.00,
            'instruction' => "Stress test order $i",
            'item_variations' => $variations,
            'item_extras' => [],
            'addons' => [],
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 9.00
        ]
    ];

    $qty = $cartItems[0]['quantity'];
    $cartItems[0]['total_price'] = $qty * 9.00;

    $requestData = [
        'customer_id' => 1,
        'branch_id' => 1,
        'subtotal' => $cartItems[0]['total_price'],
        'total' => $cartItems[0]['total_price'],
        'discount' => 0,
        'delivery_charge' => 0,
        'order_type' => OrderType::TAKEAWAY,
        'is_advance_order' => 0,
        'source' => Source::WEB,
        'pos_payment_method' => PosPaymentMethod::CASH,
        'pos_received_amount' => $cartItems[0]['total_price'],
        'items' => json_encode($cartItems),
    ];

    $request = new PosOrderRequest();
    $request->merge($requestData);
    $request->setContainer(app());
    $validator = Validator::make($request->all(), $request->rules());
    $request->setValidator($validator);

    $orderService = app(OrderService::class);
    $order = $orderService->posOrderStore($request);
    echo "[$i] Commande générée : #" . $order->order_serial_no . " | Queue: " . $order->queue_number . "\n";
    $successes++;
}
$timeEnd = microtime(true);
$duration = round($timeEnd - $timeStart, 3);
echo "✅ Bilan : $successes commandes générées en $duration s.\n";
