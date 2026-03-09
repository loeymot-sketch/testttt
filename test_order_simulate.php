<?php
use App\Models\User;
use App\Models\Item;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Services\OrderService;
use Illuminate\Http\Request;
use App\Http\Requests\PosOrderRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// 1. Authentification
$admin = User::find(1);
Auth::guard('web')->login($admin);

// 2. Préparation du Cart (Le Terminator avec Viandes, Oignon, Sauce, Supplément et Menu)
$terminator = Item::where('name', 'Le Terminator (2 Viandes)')->first();

if (!$terminator) {
    die("Le Terminator n'est pas trouvé en DB.\n");
}

$cartItems = [
    [
        'branch_id' => 1,
        'item_id' => $terminator->id,
        'quantity' => 1,
        'discount' => 0,
        'tax' => 0,
        'price' => 9.00,
        'item_price' => 9.00,
        'instruction' => 'Sans sel SVP',
        'item_variations' => [],
        'item_extras' => [],
        'addons' => [],
        'item_variation_total' => 0,
        'item_extra_total' => 0,
        'total_price' => 9.00
    ]
];

// Fetch variations from terminator to put valid IDs
$viande1 = $terminator->variations()->where('name', 'Poulet')->first();
$viande2 = $terminator->variations()->where('name', 'Cordon Bleu')->first();
$sauce = $terminator->variations()->where('name', 'Algérienne')->first();
$crudite = $terminator->variations()->where('name', 'Sans Oignon')->first();
$extraCheddar = $terminator->extras()->where('name', 'Supplément Cheddar')->first();

// The Addon is itemMenuUpsell (+3€)
$addonMenu = $terminator->addons()->first();

$variations = [];
if ($viande1)
    $variations[] = $viande1->id;
if ($viande2)
    $variations[] = $viande2->id;
if ($sauce)
    $variations[] = $sauce->id;
if ($crudite)
    $variations[] = $crudite->id;

if (!empty($variations)) {
    // Note: OrderService line 407 JSON encodes $item->item_variations so it expects an array/object, not a JSON string.
    $cartItems[0]['item_variations'] = $variations;
}

if ($extraCheddar) {
    $cartItems[0]['item_extras'] = [$extraCheddar->id];
    $cartItems[0]['item_extra_total'] = 1.00;
}

if ($addonMenu) {
    // POS ne gère pas addon_total dans la DB table order_items nativement, on le met juste en addons
    $cartItems[0]['addons'] = [
        [
            'id' => $addonMenu->id,
            'addon_item_id' => $addonMenu->addon_item_id,
            'quantity' => 1,
            'price' => 3
        ]
    ];
}

// Ensure proper total math
$itemTotal = 9.00 + ($extraCheddar ? 1.00 : 0) + ($addonMenu ? 3.00 : 0);
$cartItems[0]['price'] = $itemTotal;
$cartItems[0]['total_price'] = $itemTotal; // Some frontends use total_price too

$requestData = [
    'customer_id' => 1, // Default customer
    'branch_id' => 1,
    'subtotal' => $itemTotal,
    'total' => $itemTotal,
    'discount' => 0,
    'delivery_charge' => 0,
    'order_type' => OrderType::TAKEAWAY,
    'is_advance_order' => 0,
    'source' => Source::WEB, // POS is technically WEB or POS specific source
    'pos_payment_method' => PosPaymentMethod::CASH,
    'pos_received_amount' => $itemTotal,
    'items' => json_encode($cartItems),
];

echo "Simulating Order submission...\n";

try {
    // Inject custom Validator for FormRequest to work properly out of HTTP context
    $request = new PosOrderRequest();
    $request->merge($requestData);
    $request->setContainer(app());
    $validator = Validator::make($request->all(), $request->rules());

    if ($validator->fails()) {
        echo "Validation Failed:\n";
        print_r($validator->errors()->all());
        exit(1);
    }

    // Bind validator to request
    $request->setValidator($validator);

    // Pass the request to OrderService
    $orderService = app(OrderService::class);
    if (method_exists($orderService, 'posOrderStore')) {
        $order = $orderService->posOrderStore($request);
        echo "✅ SUCCESS: Order #" . $order->order_serial_no . " created successfully!\n";
    } else {
        echo "❌ ERROR: method posOrderStore not found in OrderService.\n";
    }

} catch (\Exception $e) {
    echo "❌ EXCEPTION:\n";
    echo $e->getMessage() . "\n" . $e->getFile() . ":" . $e->getLine() . "\n";
}
