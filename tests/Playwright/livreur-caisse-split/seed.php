<?php
// [LIVREUR CAISSE 2026-11-02] Terrain du banc navigateur : base SQLITE jetable (jamais la base locale ni la production).
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
use App\Services\Delivery\DeliveryBoyCashSessionService;
use App\Services\Delivery\DeliveryHandoverService;
use App\Services\Delivery\DeliveryWaveService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (config('database.default') !== 'sqlite' || ! str_contains((string) config('database.connections.sqlite.database'), 'livcaisse-e2e')) {
    fwrite(STDERR, "REFUS : ce script ne tourne que sur la base jetable livcaisse-e2e.\n");
    exit(2);
}

foreach (['Admin', 'Customer', 'Delivery Boy', 'POS Operator'] as $r) {
    Role::firstOrCreate(['name' => $r, 'guard_name' => 'sanctum']);
}
foreach (['delivery-boys', 'delivery-boys_show'] as $perm) {
    Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
}
Role::where('name', 'Admin')->first()->givePermissionTo(['delivery-boys', 'delivery-boys_show']);
$now = now();
foreach ([
    ['site_title', 'site', 'Le Cayenne E2E'], ['currency', 'site', 'EUR'], ['currency_symbol', 'site', '€'], ['order_prefix', 'order_setup', 'FK'],
    ['order_setup_delivery', 'order_setup', 1], ['order_setup_takeaway', 'order_setup', 1], ['company_name', 'company', 'Le Cayenne'],
    ['company_email', 'company', 'e2e@example.test'], ['company_phone', 'company', '+33600000000'],
] as [$k, $g, $v]) {
    DB::table('settings')->insert(['key' => $k, 'payload' => json_encode($v), 'group' => $g, 'created_at' => $now, 'updated_at' => $now]);
}

$branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
function account(string $role, string $email, int $branchId, string $name): User {
    $u = User::factory()->create(['email' => $email, 'status' => Status::ACTIVE, 'branch_id' => $branchId, 'name' => $name]);
    $u->assignRole($role);
    return $u;
}
$courier = account('Delivery Boy', 'livreur.a@e2e.test', $branch->id, 'Samir Livreur');
$other = account('Delivery Boy', 'livreur.b@e2e.test', $branch->id, 'Nadia Livreuse');
account('Customer', 'client@e2e.test', $branch->id, 'Client Test');
account('POS Operator', 'caisse@e2e.test', $branch->id, 'Caissier Test');
$admin = account('Admin', 'admin@e2e.test', 0, 'Admin Test');

// L'administration remet 40 € de fond de début au livreur A (c'est le « combien on lui a donné »).
$shift = app(DeliveryBoyCashSessionService::class)->openSession($branch->id, $courier->id, 40.0, $admin->id);

$waveAt = now()->subMinute()->startOfMinute();
$mk = function (string $ref, array $over = [], float $total = 27.50) use ($branch, $courier, $waveAt): array {
    $cust = User::factory()->create(['branch_id' => $branch->id, 'name' => 'Client '.$ref, 'phone' => '0612345678']);
    $order = Order::factory()->create($over + [
        'branch_id' => $branch->id, 'user_id' => $cust->id, 'order_type' => OrderType::DELIVERY, 'status' => OrderStatus::PREPARED,
        'payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'total' => $total, 'delivery_charge' => 3.90,
        'subtotal' => $total - 3.9, 'discount' => 0, 'total_tax' => 0,
    ]);
    OrderAddress::query()->create(['order_id' => $order->id, 'user_id' => $cust->id, 'label' => 'x', 'address' => '10 Rue '.$ref.', Hénin-Beaumont',
        'apartment' => '2B', 'latitude' => '50.43', 'longitude' => '2.96', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110']);
    $stop = app(DeliveryWaveService::class)->assign($order->fresh());
    DeliveryStop::query()->withoutGlobalScopes()->whereKey($stop->id)->update(['delivery_boy_id' => $courier->id, 'wave_at' => $waveAt]);
    Order::query()->withoutGlobalScopes()->whereKey($order->id)->update(['delivery_boy_id' => $courier->id]);
    return ['order_id' => $order->id, 'stop_id' => $stop->id, 'serial' => $order->fresh()->order_serial_no];
};

$s = [
    'cash'  => $mk('ESPECES'),                                                        // espèces seules (choix du client)
    'card'  => $mk('CARTE', ['door_payment_mode' => 'card']),                         // carte seule
    'meal'  => $mk('TITRE', ['payment_method' => PaymentGateway::TICKET_RESTAURANT]), // titre-restaurant seul
    'split' => $mk('MIXTE'),                                                          // en plusieurs fois, 3 moyens
    'qr'    => $mk('QRSPLIT'),                                                        // en plusieurs fois par le QR de remise
    'last'  => $mk('DERNIERE'),                                                       // reste en route : bloque la clôture
    'net'   => $mk('RESEAU', ['door_payment_mode' => 'card']),                        // règlement avec réseau coupé
    'guard' => $mk('GARDE'),                                                          // refus serveur (somme fausse envoyée à la main)
];
$qrUrl = app(DeliveryHandoverService::class)->qrUrlForOrder($s['qr']['order_id']);
$s['qr']['qr_url'] = $qrUrl;

echo json_encode(['branch_id' => $branch->id, 'courier_id' => $courier->id, 'other_id' => $other->id, 'shift_id' => $shift->id, 'stops' => $s]), "\n";
