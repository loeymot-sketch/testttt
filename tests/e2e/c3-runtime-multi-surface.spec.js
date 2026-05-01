const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const orderStatusEnum = require('../../resources/js/enums/modules/orderStatusEnum.js').default;
const {
  artisan,
  parseArtisanJson,
  loginAsPOS,
  loginAsChef,
  expectNoCriticalBrowserErrors,
} = require('./helpers/process-audit');
const { loginAsKiosk } = require('./helpers/login');

const PREFIX = 'PW-C3';
const ASK_NO = 10;
const reportPath = path.resolve(__dirname, '../../reports/antigravity/c3-runtime-multi-surface.json');

function cleanupRuntimeAudit(prefix = PREFIX) {
  const escapedPrefix = String(prefix).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  artisan(`
    $prefix = '${escapedPrefix}';
    $itemIds = DB::table('items')->where('name', 'like', $prefix . '%')->pluck('id');
    $orderIdsByItems = collect();
    if ($itemIds->isNotEmpty() && Schema::hasTable('order_items')) {
      $orderIdsByItems = DB::table('order_items')->whereIn('item_id', $itemIds)->pluck('order_id');
    }
    if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'instruction')) {
      $orderIdsByItems = $orderIdsByItems
        ->merge(DB::table('order_items')->where('instruction', 'like', $prefix . '%')->pluck('order_id'));
    }
    $orderIds = DB::table('orders')
      ->where('order_serial_no', 'like', $prefix . '%')
      ->orWhere('token', 'like', $prefix . '%')
      ->pluck('id')
      ->merge($orderIdsByItems)
      ->unique()
      ->values();
    if ($orderIds->isNotEmpty()) {
      if (Schema::hasTable('transactions')) DB::table('transactions')->whereIn('order_id', $orderIds)->delete();
      if (Schema::hasTable('order_items')) DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
      if (Schema::hasTable('order_status_transitions')) DB::table('order_status_transitions')->whereIn('order_id', $orderIds)->delete();
      if (Schema::hasTable('domain_events')) DB::table('domain_events')->whereIn('aggregate_id', $orderIds)->delete();
      if (Schema::hasTable('audit_logs')) DB::table('audit_logs')->where('resource', 'order')->whereIn('resource_id', $orderIds)->delete();
      DB::table('orders')->whereIn('id', $orderIds)->delete();
    }

    if (Schema::hasTable('stock_movements')) {
      DB::table('stock_movements')->where('idempotency_key', 'like', $prefix . '%')->delete();
    }
    if (Schema::hasTable('order_quotes')) {
      DB::table('order_quotes')->whereJsonContains('canonical_payload->items_json', $prefix)->delete();
    }
    if ($itemIds->isNotEmpty()) {
      if (Schema::hasTable('stock_levels')) DB::table('stock_levels')->whereIn('stockable_id', $itemIds)->delete();
      if (Schema::hasTable('item_branch_availability')) DB::table('item_branch_availability')->whereIn('item_id', $itemIds)->delete();
      DB::table('items')->whereIn('id', $itemIds)->delete();
    }
    DB::table('item_categories')->where('name', 'like', $prefix . '%')->delete();
    echo json_encode(['deleted_orders' => $orderIds->count(), 'deleted_items' => $itemIds->count()]);
  `);
}

function createRuntimeItem(label, stockOnHand = 20) {
  const escapedLabel = String(label).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  return parseArtisanJson(artisan(`
    $branchId = (int) (App\\Models\\User::query()->where('email', 'pos@lecayenne.fr')->value('branch_id') ?: 1);
    $tax = App\\Models\\Tax::query()->first() ?? App\\Models\\Tax::factory()->create([
      'tax_rate' => 0,
      'type' => App\\Enums\\TaxType::PERCENTAGE,
      'status' => App\\Enums\\Status::ACTIVE,
    ]);
    $category = App\\Models\\ItemCategory::factory()->create([
      'name' => '${PREFIX} Category ${escapedLabel}',
      'status' => App\\Enums\\Status::ACTIVE,
      'wizard_template' => 'simple',
      'has_menu' => false,
    ]);
    $item = App\\Models\\Item::factory()->create([
      'name' => '${PREFIX} Item ${escapedLabel} ' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)),
      'item_category_id' => $category->id,
      'tax_id' => $tax->id,
      'price' => 10.00,
      'status' => App\\Enums\\Status::ACTIVE,
      'is_available' => true,
    ]);
    $stock = App\\Models\\StockLevel::query()->create([
      'branch_id' => $branchId,
      'stockable_type' => App\\Models\\Item::class,
      'stockable_id' => $item->id,
      'on_hand' => ${Number(stockOnHand)},
      'reserved' => 0,
    ]);
    $customerId = (int) (App\\Models\\User::query()->where('email', 'walkingcustomer@example.com')->value('id')
      ?: App\\Models\\User::query()->where('branch_id', 0)->value('id')
      ?: App\\Models\\User::query()->value('id'));
    echo json_encode([
      'branch_id' => $branchId,
      'customer_id' => $customerId,
      'item_id' => (int) $item->id,
      'item_name' => $item->name,
      'price' => (float) $item->price,
      'stock_level_id' => (int) $stock->id,
    ]);
  `));
}

async function createPosOrderViaApi(page, fixture, label) {
  return page.evaluate(async ({ fixture, label, prefix, askNo }) => {
    const items = [{
      item_id: fixture.item_id,
      quantity: 1,
      item_variations: [],
      item_extras: [],
      instruction: `${prefix} ${label}`,
    }];
    const token = `${prefix}-${label}-${Date.now()}`;
    const basePayload = {
      branch_id: fixture.branch_id,
      customer_id: fixture.customer_id,
      token,
      discount: 0,
      order_type: 10,
      is_advance_order: askNo,
      source: 15,
      pos_payment_method: 1,
      pos_received_amount: 20,
      items: JSON.stringify(items),
    };
    const quote = (await window.axios.post('admin/pos/quote', basePayload)).data.data;
    const response = await window.axios.post('admin/pos', {
      ...basePayload,
      quote_token: quote.quote_token,
      quote_signature: quote.signature,
      subtotal: quote.subtotal,
      discount: quote.discount,
      delivery_charge: quote.delivery_charge,
      total: quote.total_ttc,
    }, {
      headers: { 'X-Idempotency-Key': `${prefix}-POS-${label}-${Date.now()}` },
    });
    return response.data.data;
  }, { fixture, label, prefix: PREFIX, askNo: ASK_NO });
}

async function createKioskCashOrderViaApi(page, fixture, label) {
  return page.evaluate(async ({ fixture, label, prefix, askNo }) => {
    const items = [{
      item_id: fixture.item_id,
      quantity: 1,
      item_variations: [],
      item_extras: [],
      instruction: `${prefix} ${label}`,
    }];
    const basePayload = {
      order_type: 25,
      is_advance_order: askNo,
      source: 5,
      payment_method: 1,
      items: JSON.stringify(items),
    };
    const quote = (await window.axios.post('frontend/order/quote', basePayload)).data.data;
    const response = await window.axios.post('frontend/order', {
      ...basePayload,
      quote_token: quote.quote_token,
      quote_signature: quote.signature,
      subtotal: quote.subtotal,
      discount: quote.discount,
      delivery_charge: quote.delivery_charge,
      total: quote.total_ttc,
    }, {
      headers: { 'X-Idempotency-Key': `${prefix}-KIOSK-${label}-${Date.now()}` },
    });
    return response.data.data;
  }, { fixture, label, prefix: PREFIX, askNo: ASK_NO });
}

async function waitForBodyText(page, pattern, timeout = 12_000) {
  const started = Date.now();
  try {
    await expect(page.locator('body')).toContainText(pattern, { timeout });
  } catch (error) {
    const diagnostics = await page.evaluate(async () => {
      try {
        const vuex = JSON.parse(localStorage.getItem('vuex') || '{}');
        const response = await window.axios.get('admin/kds-order');
        return {
          url: window.location.href,
          branch_id: vuex.auth?.authBranchId || null,
          count: response.data.data.length,
          meta: response.data.meta || null,
          orders: response.data.data.slice(0, 5).map((order) => ({
            id: order.id,
            serial: order.order_serial_no,
            token: order.token,
            queue_number: order.queue_number,
            order_type: order.order_type,
            status: order.status,
            payment_status: order.payment_status,
          })),
        };
      } catch (diagError) {
        return { error: diagError?.message || String(diagError) };
      }
    }).catch((diagError) => ({ error: diagError?.message || String(diagError) }));
    error.message += `\nC3 KDS diagnostics: ${JSON.stringify(diagnostics)}`;
    throw error;
  }
  return Date.now() - started;
}

async function kdsChangeStatus(page, orderId, expectedStatus, nextStatus) {
  const result = await page.evaluate(async ({ orderId, expectedStatus, nextStatus }) => {
    const response = await window.axios.post(`admin/kds-order/change-status/${orderId}`, {
      expected_status: expectedStatus,
      status: nextStatus,
    });
    return { ok: response.status >= 200 && response.status < 300, status: response.status };
  }, { orderId, expectedStatus, nextStatus });
  expect(result.ok).toBe(true);
}

async function inspectRuntimeOrder(orderId) {
  return parseArtisanJson(artisan(`
    $order = App\\Models\\Order::withoutGlobalScopes()->with('orderItems')->findOrFail(${Number(orderId)});
    $stock = $order->orderItems->first()
      ? App\\Models\\StockLevel::query()
        ->where('branch_id', $order->branch_id)
        ->where('stockable_type', App\\Models\\Item::class)
        ->where('stockable_id', $order->orderItems->first()->item_id)
        ->first()
      : null;
    echo json_encode([
      'id' => (int) $order->id,
      'branch_id' => (int) $order->branch_id,
      'queue_number' => $order->queue_number,
      'token' => $order->token,
      'status' => (int) $order->status,
      'payment_status' => (int) $order->payment_status,
      'source_surface' => $order->source_surface,
      'stock_on_hand' => $stock ? (int) $stock->on_hand : null,
      'domain_events' => DB::table('domain_events')->where('aggregate_id', $order->id)->pluck('broadcast_as')->values()->all(),
    ]);
  `));
}

function writeReport(results) {
  fs.mkdirSync(path.dirname(reportPath), { recursive: true });
  const pass = results.length > 0 && results.every((row) => row.pass);
  fs.writeFileSync(reportPath, JSON.stringify({
    generated_at: new Date().toISOString(),
    verdict: pass ? 'PASS_RUNTIME_LOCAL' : 'REWORK_RUNTIME_LOCAL',
    results,
  }, null, 2));
}

test.describe('C3 runtime multi-surface sync', () => {
  test.describe.configure({ timeout: 180_000 });

  let results = [];

  test.beforeAll(() => {
    results = [];
  });

  test.beforeEach(() => {
    cleanupRuntimeAudit();
  });

  test.afterEach(() => {
    writeReport(results);
    if (process.env.C3_SKIP_CLEANUP !== '1') {
      cleanupRuntimeAudit();
    }
  });

  test('kiosk cash order reaches KDS, POS counter-collect, and OSS without manual reload', async ({ browser }) => {
    const fixture = createRuntimeItem('KIOSK_CASH', 10);
    const kdsContext = await browser.newContext();
    const ossContext = await browser.newContext();
    const posContext = await browser.newContext();
    const kioskContext = await browser.newContext();
    const kdsPage = await kdsContext.newPage();
    const ossPage = await ossContext.newPage();
    const posPage = await posContext.newPage();
    const kioskPage = await kioskContext.newPage();

    try {
      await loginAsChef(kdsPage);
      await kdsPage.goto('/admin/kitchen-display-system');
      await loginAsChef(ossPage);
      await ossPage.goto('/admin/order-status-screen');
      await loginAsPOS(posPage);
      await loginAsKiosk(kioskPage);
      await kioskPage.goto('/kiosk/idle');

      let order;
      await expectNoCriticalBrowserErrors(kioskPage, async () => {
        order = await createKioskCashOrderViaApi(kioskPage, fixture, 'KIOSK_CASH');
      });
      const createdKioskOrder = await inspectRuntimeOrder(order.id);
      expect(createdKioskOrder.branch_id).toBe(fixture.branch_id);
      expect(createdKioskOrder.status).toBe(orderStatusEnum.ACCEPT);

      const queuePattern = new RegExp(String(order.queue_number).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
      await kdsPage.bringToFront();
      const kdsMs = await waitForBodyText(kdsPage, queuePattern, 15_000);

      await posPage.bringToFront();
      await expect(posPage.locator('.kiosk-cash-fab')).toBeVisible({ timeout: 15_000 });
      await posPage.locator('.kiosk-cash-fab').click();
      await posPage.locator('.kiosk-cash-refresh-btn').click();
      await expect(posPage.locator('.kiosk-cash-order-card').filter({ hasText: order.queue_number })).toBeVisible({ timeout: 15_000 });

      await kdsChangeStatus(kdsPage, order.id, orderStatusEnum.ACCEPT, orderStatusEnum.PREPARING);
      await ossPage.bringToFront();
      const ossPreparingMs = await waitForBodyText(ossPage, queuePattern, 15_000);

      const persisted = await inspectRuntimeOrder(order.id);
      expect(persisted.status).toBe(orderStatusEnum.PREPARING);
      expect(persisted.source_surface).toBe('kiosk');
      expect(persisted.stock_on_hand).toBe(9);

      results.push({
        scenario: 'kiosk_cash_to_kds_pos_oss',
        pass: true,
        kds_ms: kdsMs,
        oss_preparing_ms: ossPreparingMs,
        queue_number: order.queue_number,
        order_id: order.id,
      });
    } finally {
      await Promise.all([
        kdsContext.close(),
        ossContext.close(),
        posContext.close(),
        kioskContext.close(),
      ]);
    }
  });

  test('POS order reaches KDS and OSS without manual reload', async ({ browser }) => {
    const fixture = createRuntimeItem('POS_CASH', 10);
    const kdsContext = await browser.newContext();
    const ossContext = await browser.newContext();
    const posContext = await browser.newContext();
    const kdsPage = await kdsContext.newPage();
    const ossPage = await ossContext.newPage();
    const posPage = await posContext.newPage();

    try {
      await loginAsChef(kdsPage);
      await kdsPage.goto('/admin/kitchen-display-system');
      await loginAsChef(ossPage);
      await ossPage.goto('/admin/order-status-screen');
      await loginAsPOS(posPage);

      let order;
      await expectNoCriticalBrowserErrors(posPage, async () => {
        order = await createPosOrderViaApi(posPage, fixture, 'POS_CASH');
      });
      const createdPosOrder = await inspectRuntimeOrder(order.id);
      expect(createdPosOrder.branch_id).toBe(fixture.branch_id);
      expect(createdPosOrder.status).toBe(orderStatusEnum.ACCEPT);

      const tokenPattern = new RegExp(String(order.token).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
      const queuePattern = new RegExp(String(order.queue_number).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
      await kdsPage.bringToFront();
      const kdsMs = await waitForBodyText(kdsPage, tokenPattern, 15_000);

      await kdsChangeStatus(kdsPage, order.id, orderStatusEnum.ACCEPT, orderStatusEnum.PREPARING);
      await ossPage.bringToFront();
      const ossPreparingMs = await waitForBodyText(ossPage, queuePattern, 15_000);

      const persisted = await inspectRuntimeOrder(order.id);
      expect(persisted.status).toBe(orderStatusEnum.PREPARING);
      expect(persisted.source_surface).toBe('pos');
      expect(persisted.stock_on_hand).toBe(9);

      results.push({
        scenario: 'pos_to_kds_oss',
        pass: true,
        kds_ms: kdsMs,
        oss_preparing_ms: ossPreparingMs,
        token: order.token,
        queue_number: order.queue_number,
        order_id: order.id,
      });
    } finally {
      await Promise.all([
        kdsContext.close(),
        ossContext.close(),
        posContext.close(),
      ]);
    }
  });
});
