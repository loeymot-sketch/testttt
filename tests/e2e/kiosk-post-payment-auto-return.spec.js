const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const orderStatusEnum = require('../../resources/js/enums/modules/orderStatusEnum.js').default;
const paymentStatusEnum = require('../../resources/js/enums/modules/paymentStatusEnum.js').default;

const repoRoot = path.resolve(__dirname, '../..');

function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

function cleanupC0Orders() {
  artisan(`
    $ids = DB::table('orders')->where('order_serial_no', 'like', 'PW-C0-%')->pluck('id');
    if ($ids->isNotEmpty()) {
      if (Schema::hasTable('transactions')) DB::table('transactions')->whereIn('order_id', $ids)->delete();
      if (Schema::hasTable('order_items')) DB::table('order_items')->whereIn('order_id', $ids)->delete();
      if (Schema::hasTable('order_status_transitions')) DB::table('order_status_transitions')->whereIn('order_id', $ids)->delete();
      if (Schema::hasTable('domain_events')) DB::table('domain_events')->whereIn('aggregate_id', $ids)->delete();
      DB::table('orders')->whereIn('id', $ids)->delete();
    }
    echo $ids->count();
  `);
}

function createPaidKioskOrder() {
  const output = artisan(`
    $machine = App\\Models\\KioskMachine::query()->where('username', 'kiosk-lecayenne')->first()
      ?? App\\Models\\KioskMachine::query()->firstOrFail();
    $operator = App\\Models\\User::query()->findOrFail((int) $machine->user_id);
    $branchId = (int) $machine->branch_id;
    $queue = 'C0' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $serial = 'PW-C0-' . $queue;
    $order = App\\Models\\Order::withoutEvents(function () use ($operator, $branchId, $queue, $serial) {
      return App\\Models\\Order::forceCreate([
        'order_serial_no' => $serial,
        'queue_number' => $queue,
        'business_date' => now()->toDateString(),
        'token' => $queue,
        'user_id' => (int) $operator->id,
        'branch_id' => $branchId,
        'subtotal' => 8.00,
        'discount' => 0,
        'delivery_charge' => 0,
        'total_tax' => 0,
        'total' => 8.00,
        'order_type' => App\\Enums\\OrderType::KIOSK,
        'order_datetime' => now(),
        'preparation_time' => 30,
        'is_advance_order' => App\\Enums\\Ask::NO,
        'payment_method' => App\\Enums\\PaymentGateway::CARD,
        'payment_status' => App\\Enums\\PaymentStatus::PAID,
        'status' => App\\Enums\\OrderStatus::ACCEPT,
        'source' => App\\Enums\\Source::APP,
        'source_surface' => 'kiosk',
        'transaction_id' => 'C0-' . $queue,
      ]);
    });
    echo json_encode([
      'id' => (int) $order->id,
      'queue_number' => $queue,
      'total' => (float) $order->total,
      'status' => (int) $order->status,
      'payment_status' => (int) $order->payment_status,
    ]);
  `);

  return JSON.parse(output);
}

test.describe('C0 kiosk post-payment auto-return', () => {
  test.setTimeout(70_000);

  test.beforeEach(() => cleanupC0Orders());
  test.afterEach(() => cleanupC0Orders());

  test('paid kiosk order leaves waiting, shows confirmation, then returns to idle', async ({ page }) => {
    const browserErrors = [];
    page.on('console', (message) => {
      if (['error', 'warning'].includes(message.type())) {
        browserErrors.push(`${message.type()}: ${message.text()}`);
      }
    });
    page.on('pageerror', (error) => {
      browserErrors.push(`pageerror: ${error.message}`);
    });

    const order = createPaidKioskOrder();
    expect(order.status).toBe(orderStatusEnum.ACCEPT);
    expect(order.payment_status).toBe(paymentStatusEnum.PAID);

    await page.goto(`/kiosk/waiting/${order.id}?queue=${order.queue_number}&total=${order.total}`);
    try {
      await expect(page).toHaveURL(/\/kiosk\/confirmation/, { timeout: 10_000 });
    } catch (error) {
      const bodyText = await page.locator('body').textContent().catch(() => '');
      throw new Error(`${error.message}\nBrowser diagnostics:\n${browserErrors.join('\n') || '(none)'}\nBody:\n${bodyText || '(empty)'}`);
    }
    await expect(page.getByTestId('kiosk-confirmation-number')).toContainText(order.queue_number);
    await expect(page).toHaveURL(/\/kiosk\/idle/, { timeout: 45_000 });
  });
});
