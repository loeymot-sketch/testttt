const { expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { loginAsPosOperator, loginAsChefOperator } = require('./login');
const { clearFoodKingRateLimits } = require('./rate-limit');

const repoRoot = path.resolve(__dirname, '../../..');

const POS_EMAIL = process.env.E2E_POS_USER || 'pos@lecayenne.fr';
const POS_PASSWORD = process.env.E2E_POS_PASS || '123456';
const CHEF_EMAIL = process.env.E2E_CHEF_USER || 'chef@lecayenne.fr';
const CHEF_PASSWORD = process.env.E2E_CHEF_PASS || '123456';

function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

function phpJson(value) {
  return JSON.stringify(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

function parseArtisanJson(output) {
  const lines = String(output)
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);
  const jsonLine = [...lines].reverse().find((line) => line.startsWith('{') || line.startsWith('['));
  if (!jsonLine) {
    throw new Error(`No JSON payload found in artisan output:\n${output}`);
  }
  return JSON.parse(jsonLine);
}

function cleanupProcessAudit(prefix) {
  const escapedPrefix = String(prefix).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  artisan(`
    $prefix = '${escapedPrefix}';
    $ids = DB::table('orders')->where('order_serial_no', 'like', $prefix . '%')->pluck('id');
    if ($ids->isNotEmpty()) {
      if (Schema::hasTable('transactions')) DB::table('transactions')->whereIn('order_id', $ids)->delete();
      if (Schema::hasTable('order_items')) DB::table('order_items')->whereIn('order_id', $ids)->delete();
      if (Schema::hasTable('order_status_transitions')) DB::table('order_status_transitions')->whereIn('order_id', $ids)->delete();
      if (Schema::hasTable('domain_events')) DB::table('domain_events')->whereIn('aggregate_id', $ids)->delete();
      if (Schema::hasTable('audit_logs')) DB::table('audit_logs')->where('resource', 'order')->whereIn('resource_id', $ids)->delete();
      DB::table('orders')->whereIn('id', $ids)->delete();
    }

    if (Schema::hasTable('stock_movements')) {
      DB::table('stock_movements')->where('idempotency_key', 'like', $prefix . '%')->delete();
    }
    if (Schema::hasTable('stock_levels')) {
      DB::table('stock_levels')->whereIn('stockable_id', function ($query) use ($prefix) {
        $query->select('id')->from('items')->where('name', 'like', $prefix . '%');
      })->delete();
    }
    DB::table('items')->where('name', 'like', $prefix . '%')->delete();
    DB::table('item_categories')->where('name', 'like', $prefix . '%')->delete();
    echo $ids->count();
  `);
}

function createProcessOrder(options = {}) {
  const payload = {
    prefix: 'PW-PROCESS',
    label: 'ORDER',
    surface: 'kiosk',
    orderType: 'KIOSK',
    paymentGateway: 'CARD',
    paymentStatus: 'PAID',
    posPaymentMethod: null,
    status: 'ACCEPT',
    total: 12,
    stockOnHand: 3,
    decrementStock: false,
    withComposition: false,
    deliveryCharge: 0,
    deliveryDistanceKm: null,
    ...options,
  };

  const output = artisan(`
    $options = json_decode('${phpJson(payload)}', true);
    $prefix = $options['prefix'];
    $label = preg_replace('/[^A-Z0-9_\\-]/i', '_', $options['label']);
    $machine = App\\Models\\KioskMachine::query()->where('username', 'kiosk-lecayenne')->first()
      ?? App\\Models\\KioskMachine::query()->first();
    $posOperator = App\\Models\\User::query()->where('email', '${POS_EMAIL}')->first();
    $kioskOperator = $machine ? App\\Models\\User::query()->find((int) $machine->user_id) : null;
    $operator = $options['surface'] === 'kiosk'
      ? ($kioskOperator ?? $posOperator ?? App\\Models\\User::query()->firstOrFail())
      : ($posOperator ?? $kioskOperator ?? App\\Models\\User::query()->firstOrFail());
    $branchId = (int) ($operator->branch_id ?: ($machine?->branch_id ?? 0));
    if ($branchId <= 0 || ! App\\Models\\Branch::query()->whereKey($branchId)->exists()) {
      $branchId = (int) (App\\Models\\Branch::query()->orderBy('id')->value('id') ?: 0);
    }
    if ($branchId <= 0) {
      $branchId = (int) App\\Models\\Branch::factory()->create()->id;
    }
    $operator->forceFill(['branch_id' => $branchId])->save();

    $tax = App\\Models\\Tax::query()->first() ?? App\\Models\\Tax::factory()->create([
      'tax_rate' => 0,
      'type' => App\\Enums\\TaxType::PERCENTAGE,
      'status' => App\\Enums\\Status::ACTIVE,
    ]);
    $category = App\\Models\\ItemCategory::factory()->create([
      'name' => $prefix . ' Category ' . $label,
      'status' => App\\Enums\\Status::ACTIVE,
      'wizard_template' => $options['withComposition'] ? 'tacos' : 'simple',
      'has_menu' => $options['withComposition'] ? true : false,
    ]);
    $item = App\\Models\\Item::factory()->create([
      'name' => $prefix . ' Item ' . $label . ' ' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)),
      'item_category_id' => $category->id,
      'tax_id' => $tax->id,
      'price' => (float) $options['total'],
      'status' => App\\Enums\\Status::ACTIVE,
      'is_available' => true,
    ]);
    $stock = App\\Models\\StockLevel::query()->create([
      'branch_id' => $branchId,
      'stockable_type' => App\\Models\\Item::class,
      'stockable_id' => $item->id,
      'on_hand' => (int) $options['stockOnHand'],
      'reserved' => 0,
    ]);

    $queue = 'A' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT) . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    $serial = $prefix . '-' . strtoupper($label) . '-' . $queue;

    $orderType = match ($options['orderType']) {
      'DELIVERY' => App\\Enums\\OrderType::DELIVERY,
      'TAKEAWAY' => App\\Enums\\OrderType::TAKEAWAY,
      'POS' => App\\Enums\\OrderType::POS,
      default => App\\Enums\\OrderType::KIOSK,
    };
    $paymentGateway = match ($options['paymentGateway']) {
      'CASH_ON_DELIVERY' => App\\Enums\\PaymentGateway::CASH_ON_DELIVERY,
      default => App\\Enums\\PaymentGateway::CARD,
    };
    $paymentStatus = match ($options['paymentStatus']) {
      'PENDING_COUNTER' => App\\Enums\\PaymentStatus::PENDING_COUNTER,
      'UNPAID' => App\\Enums\\PaymentStatus::UNPAID,
      'REFUNDED' => App\\Enums\\PaymentStatus::REFUNDED,
      default => App\\Enums\\PaymentStatus::PAID,
    };
    $posPaymentMethod = match ($options['posPaymentMethod']) {
      'CASH' => App\\Enums\\PosPaymentMethod::CASH,
      'CARD' => App\\Enums\\PosPaymentMethod::CARD,
      'COUNTER_DEFERRED' => App\\Enums\\PosPaymentMethod::COUNTER_DEFERRED,
      default => null,
    };
    $status = match ($options['status']) {
      'PENDING' => App\\Enums\\OrderStatus::PENDING,
      'PREPARING' => App\\Enums\\OrderStatus::PREPARING,
      'PREPARED' => App\\Enums\\OrderStatus::PREPARED,
      'CANCELED' => App\\Enums\\OrderStatus::CANCELED,
      default => App\\Enums\\OrderStatus::ACCEPT,
    };
    $fiscal = $paymentStatus === App\\Enums\\PaymentStatus::PAID
      ? app(App\\Services\\Fiscal\\FiscalSequenceService::class)->next($branchId)
      : null;

    $order = App\\Models\\Order::withoutEvents(function () use ($operator, $branchId, $queue, $serial, $orderType, $paymentGateway, $paymentStatus, $posPaymentMethod, $status, $fiscal, $options) {
      return App\\Models\\Order::forceCreate([
        'order_serial_no' => $serial,
        'queue_number' => $queue,
        'business_date' => now()->toDateString(),
        'token' => $queue,
        'user_id' => (int) $operator->id,
        'branch_id' => $branchId,
        'subtotal' => (float) $options['total'],
        'discount' => 0,
        'delivery_charge' => (float) $options['deliveryCharge'],
        'total_tax' => 0,
        'total' => (float) $options['total'] + (float) $options['deliveryCharge'],
        'order_type' => $orderType,
        'order_datetime' => now(),
        'preparation_time' => 30,
        'is_advance_order' => App\\Enums\\Ask::NO,
        'payment_method' => $paymentGateway,
        'payment_status' => $paymentStatus,
        'pos_payment_method' => $posPaymentMethod,
        'status' => $status,
        'source' => App\\Enums\\Source::APP,
        'source_surface' => $options['surface'],
        'fiscal_sequence_no' => $fiscal,
        'transaction_id' => $fiscal ? 'PW-' . $queue : null,
      ]);
    });

    $snapshot = $options['withComposition'] ? [
      'schema_version' => 1,
      'lines' => [
        ['role' => 'viande', 'name' => 'Poulet', 'quantity' => 1, 'line_total' => 0],
        ['role' => 'sauce', 'name' => 'Algerienne', 'quantity' => 1, 'line_total' => 0],
        ['role' => 'extra', 'name' => 'Fromage', 'quantity' => 1, 'line_total' => 1.50],
      ],
    ] : ['schema_version' => 1, 'lines' => []];

    $orderItem = App\\Models\\OrderItem::query()->create([
      'order_id' => $order->id,
      'branch_id' => $branchId,
      'item_id' => $item->id,
      'quantity' => 1,
      'price' => (float) $options['total'],
      'discount' => 0,
      'total_price' => (float) $options['total'],
      'item_variations' => json_encode([]),
      'item_extras' => json_encode([]),
      'composition_snapshot' => json_encode($snapshot),
    ]);

    if ((bool) $options['decrementStock']) {
      app(App\\Services\\Stock\\StockService::class)->decrementForOrder($order, $prefix . '-' . $label);
      $stock->refresh();
    }

    echo json_encode([
      'id' => (int) $order->id,
      'branch_id' => $branchId,
      'item_id' => (int) $item->id,
      'order_item_id' => (int) $orderItem->id,
      'queue_number' => $queue,
      'order_serial_no' => $serial,
      'total' => (float) $order->total,
      'status' => (int) $order->status,
      'payment_status' => (int) $order->payment_status,
      'pos_payment_method' => $order->pos_payment_method === null ? null : (int) $order->pos_payment_method,
      'fiscal_sequence_no' => $order->fiscal_sequence_no === null ? null : (int) $order->fiscal_sequence_no,
      'stock_on_hand' => (int) $stock->on_hand,
      'composition_snapshot' => json_decode($orderItem->composition_snapshot, true),
    ]);
  `);

  return parseArtisanJson(output);
}

function inspectOrder(orderId) {
  const output = artisan(`
    $order = App\\Models\\Order::withoutGlobalScopes()->with('orderItems')->findOrFail(${Number(orderId)});
    $item = $order->orderItems->first();
    $stock = $item ? App\\Models\\StockLevel::query()
      ->where('branch_id', $order->branch_id)
      ->where('stockable_type', App\\Models\\Item::class)
      ->where('stockable_id', $item->item_id)
      ->first() : null;
    echo json_encode([
      'id' => (int) $order->id,
      'branch_id' => (int) $order->branch_id,
      'queue_number' => $order->queue_number,
      'status' => (int) $order->status,
      'payment_status' => (int) $order->payment_status,
      'pos_payment_method' => $order->pos_payment_method === null ? null : (int) $order->pos_payment_method,
      'fiscal_sequence_no' => $order->fiscal_sequence_no === null ? null : (int) $order->fiscal_sequence_no,
      'stock_on_hand' => $stock ? (int) $stock->on_hand : null,
      'movement_reasons' => DB::table('stock_movements')->where('reference_id', $order->id)->pluck('reason')->values()->all(),
      'composition_snapshot' => $item ? json_decode($item->composition_snapshot, true) : null,
      'domain_events' => DB::table('domain_events')->where('aggregate_id', $order->id)->pluck('broadcast_as')->values()->all(),
    ]);
  `);

  return parseArtisanJson(output);
}

async function loginAsPOS(page) {
  await loginAsPosOperator(page, POS_EMAIL, POS_PASSWORD);
}

async function loginAsChef(page) {
  await loginAsChefOperator(page, CHEF_EMAIL, CHEF_PASSWORD);
}

async function expectNoCriticalBrowserErrors(page, action) {
  const errors = [];
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') {
      errors.push(`console: ${message.text()}`);
    }
  });

  await action();

  const critical = errors.filter((message) => /TypeError|ReferenceError|Cannot read|is not a function|is not defined|Whoops|Fatal/i.test(message));
  expect(critical, critical.join('\n')).toHaveLength(0);
}

module.exports = {
  POS_EMAIL,
  POS_PASSWORD,
  artisan,
  parseArtisanJson,
  cleanupProcessAudit,
  createProcessOrder,
  inspectOrder,
  loginAsPOS,
  loginAsChef,
  expectNoCriticalBrowserErrors,
};
