<?php
// [LIVRAISON QR 2026-11-01] Terrain du test navigateur : base SQLITE jetable (jamais la base locale ni la production).
// Lancé par run.js avec DB_CONNECTION=sqlite DB_DATABASE=<fichier jetable> déjà migré. Imprime UNE ligne JSON.
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\User;
use App\Services\Delivery\DeliveryHandoverService;
use App\Services\Delivery\DeliveryWaveService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (config('database.default') !== 'sqlite' || str_contains((string) config('database.connections.sqlite.database'), ':memory:') || ! str_contains((string) config('database.connections.sqlite.database'), 'qr-remise-e2e')) {
    fwrite(STDERR, "REFUS : ce script ne tourne que sur la base jetable qr-remise-e2e.\n");
    exit(2);
}

foreach (['Admin', 'Customer', 'Delivery Boy', 'POS Operator'] as $r) {
    Role::firstOrCreate(['name' => $r, 'guard_name' => 'sanctum']);
}
$now = now();
foreach ([
    ['site_title', 'site', 'Le Cayenne E2E'], ['currency', 'site', 'EUR'], ['currency_symbol', 'site', '€'], ['order_prefix', 'order_setup', 'FK'],
    ['order_setup_delivery', 'order_setup', 1], ['order_setup_takeaway', 'order_setup', 1], ['company_name', 'company', 'Le Cayenne'],
    ['company_email', 'company', 'e2e@example.test'], ['company_phone', 'company', '+33600000000'],
] as [$k, $g, $v]) {
    DB::table('settings')->insert(['key' => $k, 'payload' => json_encode($v), 'group' => $g, 'created_at' => $now, 'updated_at' => $now]);
}

$branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
function account(string $role, string $email, int $branchId): User {
    $u = User::factory()->create(['email' => $email, 'status' => Status::ACTIVE, 'branch_id' => $branchId, 'name' => 'Compte '.$role]);
    $u->assignRole($role);
    return $u;
}
$courier = account('Delivery Boy', 'livreur.a@e2e.test', $branch->id);
$other = account('Delivery Boy', 'livreur.b@e2e.test', $branch->id);
$customer = account('Customer', 'client@e2e.test', $branch->id);
$staff = account('POS Operator', 'caisse@e2e.test', $branch->id);

$mkStop = function (string $ref) use ($branch, $courier): array {
    $cust = User::factory()->create(['branch_id' => $branch->id, 'name' => 'Karim Test '.$ref, 'phone' => '0612345678']);
    $order = Order::factory()->create([
        'branch_id' => $branch->id, 'user_id' => $cust->id, 'order_type' => OrderType::DELIVERY, 'status' => OrderStatus::PREPARED,
        'payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'total' => 27.50, 'delivery_charge' => 3.90,
        'subtotal' => 23.60, 'discount' => 0, 'total_tax' => 0,
    ]);
    OrderAddress::query()->create(['order_id' => $order->id, 'user_id' => $cust->id, 'label' => 'x', 'address' => '10 Rue Test '.$ref.', Hénin-Beaumont',
        'apartment' => '2B', 'latitude' => '50.43', 'longitude' => '2.96', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110']);
    $stop = app(DeliveryWaveService::class)->assign($order->fresh());
    DeliveryStop::query()->withoutGlobalScopes()->whereKey($stop->id)->update(['delivery_boy_id' => $courier->id, 'left_at' => now()]);
    Order::query()->withoutGlobalScopes()->whereKey($order->id)->update(['delivery_boy_id' => $courier->id, 'status' => OrderStatus::OUT_FOR_DELIVERY]);
    $svc = app(DeliveryHandoverService::class);
    $url = $svc->qrUrlForOrder($order->id);
    return ['order_id' => $order->id, 'stop_id' => $stop->id, 'qr_url' => $url, 'qr_token' => substr($url, strpos($url, '#') + 1)];
};

echo json_encode([
    'branch_id' => $branch->id,
    'happy' => $mkStop('HAPPY'),
    'guarded' => $mkStop('GARDE'),
    'hostile' => $mkStop('HOSTILE'),
    'stale_session_token' => $courier->createToken('auth_token', ['*'], now()->addHours(8))->plainTextToken,
]), "\n";
