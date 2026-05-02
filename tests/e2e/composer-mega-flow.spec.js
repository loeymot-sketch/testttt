const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { login } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const orderStatusEnum = require('../../resources/js/enums/modules/orderStatusEnum.js').default;
const paymentStatusEnum = require('../../resources/js/enums/modules/paymentStatusEnum.js').default;

const repoRoot = path.resolve(__dirname, '../..');
const POS_EMAIL = 'pos@lecayenne.fr';
const POS_PASSWORD = '123456';
const CHEF_EMAIL = 'chef@lecayenne.fr';
const CHEF_PASSWORD = '123456';
const KDS_SURFACE_RE = /\/(kds|admin\/kitchen-display-system)/;

function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

function cleanupB9Orders() {
  artisan(`
    $ids = DB::table('orders')->where('order_serial_no', 'like', 'PW-B9-%')->pluck('id');
    if ($ids->isNotEmpty()) {
      if (Schema::hasTable('transactions')) {
        DB::table('transactions')->whereIn('order_id', $ids)->delete();
      }
      if (Schema::hasTable('order_items')) {
        DB::table('order_items')->whereIn('order_id', $ids)->delete();
      }
      if (Schema::hasTable('order_status_transitions')) {
        DB::table('order_status_transitions')->whereIn('order_id', $ids)->delete();
      }
      if (Schema::hasTable('domain_events')) {
        DB::table('domain_events')->whereIn('aggregate_id', $ids)->delete();
      }
      DB::table('orders')->whereIn('id', $ids)->delete();
    }
    echo $ids->count();
  `);
}

function createPendingCounterOrder(label) {
  const output = artisan(`
    $operator = App\\Models\\User::query()->where('email', '${POS_EMAIL}')->firstOrFail();
    $branchId = (int) $operator->branch_id;
    $queue = 'B9' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $serial = 'PW-B9-' . strtoupper('${label}') . '-' . $queue;
    $order = App\\Models\\Order::withoutEvents(function () use ($operator, $branchId, $queue, $serial) {
      return App\\Models\\Order::forceCreate([
        'order_serial_no' => $serial,
        'queue_number' => $queue,
        'business_date' => now()->toDateString(),
        'token' => $queue,
        'user_id' => (int) $operator->id,
        'branch_id' => $branchId,
        'subtotal' => 12.00,
        'discount' => 0,
        'delivery_charge' => 0,
        'total_tax' => 0,
        'total' => 12.00,
        'order_type' => App\\Enums\\OrderType::KIOSK,
        'order_datetime' => now(),
        'preparation_time' => 30,
        'is_advance_order' => App\\Enums\\Ask::NO,
        'payment_method' => App\\Enums\\PaymentGateway::CASH_ON_DELIVERY,
        'payment_status' => App\\Enums\\PaymentStatus::PENDING_COUNTER,
        'pos_payment_method' => App\\Enums\\PosPaymentMethod::COUNTER_DEFERRED,
        'status' => App\\Enums\\OrderStatus::ACCEPT,
        'source' => App\\Enums\\Source::APP,
        'source_surface' => 'kiosk',
        'fiscal_sequence_no' => null,
      ]);
    });
    echo json_encode([
      'id' => (int) $order->id,
      'queue_number' => $queue,
      'order_serial_no' => $serial,
    ]);
  `);

  return JSON.parse(output);
}

function inspectOrder(orderId) {
  const output = artisan(`
    $order = App\\Models\\Order::withoutGlobalScopes()->findOrFail(${Number(orderId)});
    echo json_encode([
      'id' => (int) $order->id,
      'status' => (int) $order->status,
      'payment_status' => (int) $order->payment_status,
      'pos_payment_method' => (int) $order->pos_payment_method,
      'fiscal_sequence_no' => $order->fiscal_sequence_no === null ? null : (int) $order->fiscal_sequence_no,
      'events' => DB::table('domain_events')
        ->where('aggregate_id', $order->id)
        ->pluck('broadcast_as')
        ->values()
        ->all(),
    ]);
  `);

  return JSON.parse(output);
}

async function loginAsPOS(page) {
  await login(page, POS_EMAIL, POS_PASSWORD);
  await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 20_000 });
}

async function loginAsChef(page) {
  await login(page, CHEF_EMAIL, CHEF_PASSWORD);
  await expect(page).toHaveURL(KDS_SURFACE_RE, { timeout: 20_000 });
}

async function waitForPendingOrderInPosApi(page, orderId) {
  try {
    await page.waitForFunction(async (id) => {
      try {
        const response = await window.axios.get('admin/pos/counter-collect/pending');
        const rows = response?.data?.data || [];
        window.__b9PendingCounterState = {
          ok: true,
          hasOrder: rows.some((order) => Number(order.id) === Number(id)),
          count: rows.length,
        };
      } catch (error) {
        window.__b9PendingCounterState = {
          ok: false,
          status: error?.response?.status || 0,
          message: error?.response?.data?.message || error.message,
        };
      }

      return window.__b9PendingCounterState.ok && window.__b9PendingCounterState.hasOrder;
    }, orderId, { timeout: 20_000 });
  } catch (error) {
    const state = await page.evaluate(() => window.__b9PendingCounterState || null).catch(() => null);
    throw new Error(`POS pending counter API did not expose order ${orderId}: ${JSON.stringify(state)}`);
  }
}

test.describe('Product Composer B9 — cash-at-counter E2E', () => {
  test.beforeEach(() => {
    clearFoodKingRateLimits();
    cleanupB9Orders();
  });
  test.afterEach(() => cleanupB9Orders());

  test('kiosk cash order reaches KDS, is collected at POS, and cancel path remains non-fiscal', async ({ browser }) => {
    let kdsPage;
    let posPage;

    try {
      const confirmOrder = createPendingCounterOrder('confirm');

      kdsPage = await browser.newPage();
      await loginAsChef(kdsPage);
      await expect(kdsPage.getByText(confirmOrder.queue_number, { exact: false }).first()).toBeVisible({ timeout: 20_000 });
      await expect(kdsPage.getByText(/PAIEMENT COMPTOIR/i).first()).toBeVisible({ timeout: 10_000 });

      posPage = await browser.newPage();
      await loginAsPOS(posPage);
      await waitForPendingOrderInPosApi(posPage, confirmOrder.id);
      await expect(posPage.locator('[data-testid="kiosk-cash-open"]')).toBeVisible({ timeout: 20_000 });
      await posPage.locator('[data-testid="kiosk-cash-open"]').click();

      const confirmCard = posPage
        .locator('.kiosk-cash-order-card')
        .filter({ hasText: confirmOrder.queue_number });
      await expect(confirmCard).toBeVisible({ timeout: 10_000 });
      const [confirmResponse] = await Promise.all([
        posPage.waitForResponse((response) =>
          response.url().includes('/api/admin/pos/counter-collect/')
            && response.url().includes('/confirm')
            && response.request().method() === 'POST'
        ),
        confirmCard.getByRole('button', { name: /Encaisser/i }).click(),
      ]);
      expect(
        confirmResponse.ok(),
        `confirm counter payment failed with ${confirmResponse.status()}: ${await confirmResponse.text().catch(() => '')}`
      ).toBeTruthy();
      await expect(confirmCard).toBeHidden({ timeout: 20_000 });

      const paid = inspectOrder(confirmOrder.id);
      expect(paid.payment_status).toBe(paymentStatusEnum.PAID);
      expect(paid.pos_payment_method).toBe(1);
      expect(paid.fiscal_sequence_no).toBeGreaterThan(0);
      expect(paid.events).toContain('OrderPaidAtCounter');

      await kdsPage.reload();
      await expect(kdsPage.getByText(confirmOrder.queue_number, { exact: false }).first()).toBeVisible({ timeout: 20_000 });
      const kdsTextAfterPayment = await kdsPage.locator('body').innerText();
      expect(kdsTextAfterPayment).not.toMatch(/PAIEMENT COMPTOIR/i);

      const cancelOrder = createPendingCounterOrder('cancel');
      await posPage.locator('.kiosk-cash-refresh-btn').click();
      const cancelCard = posPage
        .locator('.kiosk-cash-order-card')
        .filter({ hasText: cancelOrder.queue_number });
      await expect(cancelCard).toBeVisible({ timeout: 10_000 });
      const [cancelResponse] = await Promise.all([
        posPage.waitForResponse((response) =>
          response.url().includes('/api/admin/pos/counter-collect/')
            && response.url().includes('/cancel')
            && response.request().method() === 'POST'
        ),
        cancelCard.getByRole('button', { name: /Annuler/i }).click(),
      ]);
      expect(
        cancelResponse.ok(),
        `cancel counter payment failed with ${cancelResponse.status()}: ${await cancelResponse.text().catch(() => '')}`
      ).toBeTruthy();
      await expect(cancelCard).toBeHidden({ timeout: 20_000 });

      const canceled = inspectOrder(cancelOrder.id);
      expect(canceled.payment_status).toBe(paymentStatusEnum.REFUNDED);
      expect(canceled.status).toBe(orderStatusEnum.CANCELED);
      expect(canceled.fiscal_sequence_no).toBeNull();
    } finally {
      await posPage?.close().catch(() => {});
      await kdsPage?.close().catch(() => {});
    }
  });

  test('kiosk cash order can be directly canceled at POS without passing through PAID', async ({ browser }) => {
    let posPage;

    try {
      const directCancelOrder = createPendingCounterOrder('direct');

      posPage = await browser.newPage();
      await loginAsPOS(posPage);
      await waitForPendingOrderInPosApi(posPage, directCancelOrder.id);

      await expect(posPage.locator('[data-testid="kiosk-cash-open"]')).toBeVisible({ timeout: 20_000 });
      await posPage.locator('[data-testid="kiosk-cash-open"]').click();
      await posPage.locator('.kiosk-cash-refresh-btn').click();

      const cancelCard = posPage
        .locator('.kiosk-cash-order-card')
        .filter({ hasText: directCancelOrder.queue_number });
      await expect(cancelCard).toBeVisible({ timeout: 20_000 });

      await posPage.locator('.kiosk-cash-refresh-btn').click();
      const [cancelResponse] = await Promise.all([
        posPage.waitForResponse((response) =>
          response.url().includes('/api/admin/pos/counter-collect/')
            && response.url().includes('/cancel')
            && response.request().method() === 'POST'
        ),
        cancelCard.getByRole('button', { name: /Annuler/i }).click(),
      ]);
      expect(
        cancelResponse.ok(),
        `cancel counter payment failed with ${cancelResponse.status()}: ${await cancelResponse.text().catch(() => '')}`
      ).toBeTruthy();
      await expect(cancelCard).toBeHidden({ timeout: 20_000 });

      const canceled = inspectOrder(directCancelOrder.id);
      expect(canceled.payment_status).toBe(paymentStatusEnum.REFUNDED);
      expect(canceled.status).toBe(orderStatusEnum.CANCELED);
      expect(canceled.fiscal_sequence_no).toBeNull();
    } finally {
      await posPage?.close().catch(() => {});
    }
  });
});
