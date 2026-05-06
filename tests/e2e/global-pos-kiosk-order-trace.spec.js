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
const { login, loginAsKiosk } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const PREFIX = 'PW-GLOBAL-TRACE';
const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';
const ASK_NO = 10;
const SOURCE_KIOSK = 5;
const SOURCE_POS = 15;
const ORDER_TYPE_KIOSK = 25;
const ORDER_TYPE_TAKEAWAY = 10;
const PAYMENT_CARD = 4;
const POS_PAYMENT_CASH = 1;
const reportPath = path.resolve(__dirname, '../../reports/antigravity/global-pos-kiosk-order-trace.json');

function phpString(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

function escapeRegex(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function writeTraceReport(results) {
  fs.mkdirSync(path.dirname(reportPath), { recursive: true });
  const pass = results.length > 0 && results.every((row) => row.pass);
  fs.writeFileSync(reportPath, JSON.stringify({
    generated_at: new Date().toISOString(),
    verdict: pass ? 'PASS_GLOBAL_POS_KIOSK_TRACE' : 'REWORK_GLOBAL_POS_KIOSK_TRACE',
    scope: {
      surfaces: ['POS cashier', 'Kiosk machine', 'KDS'],
      runtime_paths: [
        'admin/pos/quote -> admin/pos',
        'frontend/order/quote -> frontend/order -> frontend/order/{order}/payment-confirm',
        'admin/kds-order/change-status ACCEPT->PREPARING->PREPARED',
      ],
      backend_assertions: [
        'queue uniqueness',
        'branch isolation',
        'backend pricing SSOT',
        'composition snapshot',
        'KDS addon visibility',
        'stock decrement for item/variation/extra/addon',
        'domain outbox events',
        'status transition history',
      ],
    },
    results,
  }, null, 2));
}

function cleanupTraceAudit(prefix = PREFIX) {
  const escaped = phpString(prefix);
  artisan(`
    $prefix = '${escaped}';
    $itemIds = DB::table('items')->where('name', 'like', $prefix . '%')->pluck('id');
    $categoryIds = DB::table('item_categories')->where('name', 'like', $prefix . '%')->pluck('id');
    $attributeIds = DB::table('item_attributes')->where('name', 'like', $prefix . '%')->pluck('id');
    $variationIds = $itemIds->isNotEmpty() && Schema::hasTable('item_variations')
      ? DB::table('item_variations')->whereIn('item_id', $itemIds)->pluck('id')
      : collect();
    $extraIds = $itemIds->isNotEmpty() && Schema::hasTable('item_extras')
      ? DB::table('item_extras')->whereIn('item_id', $itemIds)->pluck('id')
      : collect();
    $stockLevelIds = collect();
    if ($itemIds->isNotEmpty() && Schema::hasTable('stock_levels')) {
      $stockLevelIds = DB::table('stock_levels')->where(function ($query) use ($itemIds, $variationIds, $extraIds) {
        $query->where(function ($stock) use ($itemIds) {
          $stock->where('stockable_type', App\\Models\\Item::class)->whereIn('stockable_id', $itemIds);
        });
        if ($variationIds->isNotEmpty()) {
          $query->orWhere(function ($stock) use ($variationIds) {
            $stock->where('stockable_type', App\\Models\\ItemVariation::class)->whereIn('stockable_id', $variationIds);
          });
        }
        if ($extraIds->isNotEmpty()) {
          $query->orWhere(function ($stock) use ($extraIds) {
            $stock->where('stockable_type', App\\Models\\ItemExtra::class)->whereIn('stockable_id', $extraIds);
          });
        }
      })->pluck('id');
    }

    $orderIds = collect();
    if ($itemIds->isNotEmpty() && Schema::hasTable('order_items')) {
      $orderIds = $orderIds->merge(DB::table('order_items')->whereIn('item_id', $itemIds)->pluck('order_id'));
    }
    if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'instruction')) {
      $orderIds = $orderIds->merge(DB::table('order_items')->where('instruction', 'like', $prefix . '%')->pluck('order_id'));
    }
    $orderIds = $orderIds
      ->merge(DB::table('orders')->where('order_serial_no', 'like', $prefix . '%')->pluck('id'))
      ->merge(DB::table('orders')->where('token', 'like', $prefix . '%')->pluck('id'))
      ->unique()
      ->values();

    if ($orderIds->isNotEmpty()) {
      if (Schema::hasTable('transactions')) DB::table('transactions')->whereIn('order_id', $orderIds)->delete();
      if (Schema::hasTable('order_status_transitions')) DB::table('order_status_transitions')->whereIn('order_id', $orderIds)->delete();
      if (Schema::hasTable('stock_movements')) DB::table('stock_movements')->whereIn('reference_id', $orderIds)->delete();
      if (Schema::hasTable('domain_events')) {
        $events = DB::table('domain_events')->whereIn('aggregate_id', $orderIds);
        if (Schema::hasColumn('domain_events', 'aggregate_type')) {
          $events->whereIn('aggregate_type', ['order', App\\Models\\Order::class, App\\Models\\FrontendOrder::class]);
        }
        $events->delete();
      }
      if (Schema::hasTable('audit_logs')) DB::table('audit_logs')->where('resource', 'order')->whereIn('resource_id', $orderIds)->delete();
      if (Schema::hasTable('order_items')) DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
      DB::table('orders')->whereIn('id', $orderIds)->delete();
    }

    if ($itemIds->isNotEmpty()) {
      if (Schema::hasTable('stock_movements')) {
        if (Schema::hasColumn('stock_movements', 'stock_level_id')) {
          DB::table('stock_movements')->whereIn('stock_level_id', $stockLevelIds)->delete();
        } elseif (Schema::hasColumn('stock_movements', 'stockable_type')) {
          DB::table('stock_movements')->where(function ($query) use ($itemIds, $variationIds, $extraIds) {
            $query->where(function ($stock) use ($itemIds) {
              $stock->where('stockable_type', App\\Models\\Item::class)->whereIn('stockable_id', $itemIds);
            });
            if ($variationIds->isNotEmpty()) {
              $query->orWhere(function ($stock) use ($variationIds) {
                $stock->where('stockable_type', App\\Models\\ItemVariation::class)->whereIn('stockable_id', $variationIds);
              });
            }
            if ($extraIds->isNotEmpty()) {
              $query->orWhere(function ($stock) use ($extraIds) {
                $stock->where('stockable_type', App\\Models\\ItemExtra::class)->whereIn('stockable_id', $extraIds);
              });
            }
          })->delete();
        }
      }
      if (Schema::hasTable('stock_levels')) {
        DB::table('stock_levels')->whereIn('id', $stockLevelIds)->delete();
      }
      if (Schema::hasTable('item_branch_availability')) DB::table('item_branch_availability')->whereIn('item_id', $itemIds)->delete();
      if (Schema::hasTable('item_wizard_steps')) {
        DB::table('item_wizard_steps')->whereIn('profile_id', DB::table('item_wizard_profiles')->whereIn('item_id', $itemIds)->pluck('id'))->delete();
      }
      if (Schema::hasTable('item_wizard_profiles')) DB::table('item_wizard_profiles')->whereIn('item_id', $itemIds)->delete();
      if (Schema::hasTable('item_addons')) DB::table('item_addons')->whereIn('item_id', $itemIds)->orWhereIn('addon_item_id', $itemIds)->delete();
      if (Schema::hasTable('item_extras')) DB::table('item_extras')->whereIn('item_id', $itemIds)->delete();
      if (Schema::hasTable('item_variations')) DB::table('item_variations')->whereIn('item_id', $itemIds)->delete();
      DB::table('items')->whereIn('id', $itemIds)->delete();
    }

    if ($categoryIds->isNotEmpty()) DB::table('item_categories')->whereIn('id', $categoryIds)->delete();
    if ($attributeIds->isNotEmpty()) DB::table('item_attributes')->whereIn('id', $attributeIds)->delete();
    if (Schema::hasTable('taxes')) DB::table('taxes')->where('name', 'like', $prefix . '%')->delete();
    Cache::flush();

    echo json_encode([
      'orders' => $orderIds->count(),
      'items' => $itemIds->count(),
      'categories' => $categoryIds->count(),
      'attributes' => $attributeIds->count(),
    ]);
  `);
}

function ensureAdminLogin() {
  artisan(`
    Artisan::call('foodking:ensure-admin', ['--email' => '${phpString(ADMIN_EMAIL)}', '--password' => '${phpString(ADMIN_PASSWORD)}']);
    app(Spatie\\Permission\\PermissionRegistrar::class)->forgetCachedPermissions();
    echo 'ok';
  `);
}

function createTraceFixture() {
  return parseArtisanJson(artisan(`
    $prefix = '${PREFIX}';
    $run = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $posUser = App\\Models\\User::query()->where('email', 'pos@lecayenne.fr')->first()
      ?? App\\Models\\User::query()->where('branch_id', '>', 0)->first()
      ?? App\\Models\\User::query()->firstOrFail();
    $branchId = (int) ($posUser->branch_id ?: (App\\Models\\Branch::query()->value('id') ?? 1));
    if ((int) $posUser->branch_id !== $branchId) {
      $posUser->forceFill(['branch_id' => $branchId])->save();
    }
    $machine = App\\Models\\KioskMachine::query()->where('username', 'kiosk-lecayenne')->first()
      ?? App\\Models\\KioskMachine::query()->first();
    if (! $machine) {
      throw new RuntimeException('No kiosk machine found for e2e kiosk order trace.');
    }
    $machine->forceFill([
      'branch_id' => $branchId,
      'status' => App\\Enums\\Status::ACTIVE,
    ])->save();
    $kioskUser = App\\Models\\User::query()->find((int) $machine->user_id);
    if ($kioskUser && (int) $kioskUser->branch_id !== $branchId) {
      $kioskUser->forceFill(['branch_id' => $branchId])->save();
    }

    $customerId = (int) (App\\Models\\User::query()->where('email', 'walkingcustomer@example.com')->value('id')
      ?: App\\Models\\User::query()->where('branch_id', 0)->value('id')
      ?: App\\Models\\User::query()->value('id'));

    $tax = App\\Models\\Tax::query()->create([
      'name' => $prefix . ' Tax ' . $run,
      'code' => $prefix . '-TAX-' . $run,
      'tax_rate' => 0,
      'type' => App\\Enums\\TaxType::PERCENTAGE,
      'status' => App\\Enums\\Status::ACTIVE,
    ]);
    $category = App\\Models\\ItemCategory::factory()->create([
      'name' => $prefix . ' Category ' . $run,
      'slug' => strtolower($prefix . '-category-' . $run),
      'status' => App\\Enums\\Status::ACTIVE,
      'channels' => null,
      'wizard_template' => 'assiette',
      'has_menu' => true,
      'sort' => 9998,
    ]);
    $attribute = App\\Models\\ItemAttribute::factory()->create([
      'name' => $prefix . ' Taille ' . $run,
      'status' => App\\Enums\\Status::ACTIVE,
      'min_select' => 1,
      'max_select' => 1,
      'allow_repeat' => false,
    ]);
    $item = App\\Models\\Item::factory()->create([
      'name' => $prefix . ' Assiette ' . $run,
      'slug' => strtolower($prefix . '-assiette-' . $run),
      'item_category_id' => $category->id,
      'tax_id' => $tax->id,
      'price' => 9.00,
      'status' => App\\Enums\\Status::ACTIVE,
      'is_available' => true,
      'channels' => null,
    ]);
    $addonItem = App\\Models\\Item::factory()->create([
      'name' => $prefix . ' Boisson ' . $run,
      'slug' => strtolower($prefix . '-boisson-' . $run),
      'item_category_id' => $category->id,
      'tax_id' => $tax->id,
      'price' => 2.00,
      'status' => App\\Enums\\Status::ACTIVE,
      'is_available' => true,
      'channels' => null,
    ]);
    $variation = App\\Models\\ItemVariation::query()->create([
      'item_id' => $item->id,
      'item_attribute_id' => $attribute->id,
      'name' => $prefix . ' XL ' . $run,
      'price' => 1.00,
      'status' => App\\Enums\\Status::ACTIVE,
      'visible_on' => ['pos', 'kiosk'],
    ]);
    $extra = App\\Models\\ItemExtra::query()->create([
      'item_id' => $item->id,
      'name' => $prefix . ' Sauce ' . $run,
      'price' => 0.50,
      'status' => App\\Enums\\Status::ACTIVE,
      'visible_on' => ['pos', 'kiosk'],
      'group_label' => $prefix . ' Sauces ' . $run,
    ]);
    $addon = App\\Models\\ItemAddon::query()->create([
      'item_id' => $item->id,
      'addon_item_id' => $addonItem->id,
      'addon_item_variation' => null,
      'role' => 'drink',
    ]);
    $profile = App\\Models\\ItemWizardProfile::query()->create([
      'item_id' => $item->id,
      'template' => 'assiette',
      'version' => 1,
      'is_published' => true,
      'published_at' => now(),
      'branch_id_scope' => $branchId,
    ]);
    $profile->steps()->create([
      'step_key' => 'taille',
      'label' => 'Taille',
      'source_type' => 'item_attribute',
      'source_ref' => (string) $attribute->id,
      'min_select' => 1,
      'max_select' => 1,
      'allow_repeat' => false,
      'visible_on' => ['pos', 'kiosk'],
      'stockable_choices' => true,
      'position' => 0,
      'is_active' => true,
    ]);
    $profile->steps()->create([
      'step_key' => 'sauce',
      'label' => 'Sauce',
      'source_type' => 'extra_group',
      'source_ref' => $extra->group_label,
      'min_select' => 1,
      'max_select' => 1,
      'allow_repeat' => false,
      'visible_on' => ['pos', 'kiosk'],
      'stockable_choices' => true,
      'position' => 1,
      'is_active' => true,
    ]);
    $profile->steps()->create([
      'step_key' => 'boisson',
      'label' => 'Boisson',
      'source_type' => 'addon',
      'source_ref' => 'drink',
      'min_select' => 1,
      'max_select' => 1,
      'allow_repeat' => false,
      'visible_on' => ['pos', 'kiosk'],
      'stockable_choices' => true,
      'position' => 2,
      'is_active' => true,
      'addon_role' => 'drink',
    ]);

    foreach ([
      [App\\Models\\Item::class, $item->id],
      [App\\Models\\ItemVariation::class, $variation->id],
      [App\\Models\\ItemExtra::class, $extra->id],
      [App\\Models\\Item::class, $addonItem->id],
    ] as [$type, $id]) {
      App\\Models\\StockLevel::query()->updateOrCreate([
        'branch_id' => $branchId,
        'stockable_type' => $type,
        'stockable_id' => $id,
      ], [
        'on_hand' => 20,
        'reserved' => 0,
      ]);
    }

    Cache::forget("kiosk.menu.branch.{$branchId}");

    echo json_encode([
      'run' => $run,
      'branch_id' => $branchId,
      'customer_id' => $customerId,
      'category_id' => (int) $category->id,
      'item_id' => (int) $item->id,
      'item_name' => $item->name,
      'addon_item_id' => (int) $addonItem->id,
      'addon_name' => $addonItem->name,
      'variation_id' => (int) $variation->id,
      'variation_name' => $variation->name,
      'extra_id' => (int) $extra->id,
      'extra_name' => $extra->name,
      'addon_id' => (int) $addon->id,
      'expected_total' => 12.50,
    ]);
  `));
}

async function projectionFor(page, channel, branchId) {
  return page.evaluate(async ({ channel, branchId }) => {
    const response = await window.axios.get('admin/menu-projection', {
      params: { channel, branch_id: branchId },
    });
    return response.data.data || response.data;
  }, { channel, branchId });
}

function findProjectedItem(projection, itemId) {
  for (const category of projection.categories || []) {
    for (const item of category.items || []) {
      if (Number(item.id) === Number(itemId)) {
        return { category, item };
      }
    }
  }
  return null;
}

function orderLine(fixture, instruction) {
  return {
    item_id: fixture.item_id,
    quantity: 1,
    item_variations: [{ id: fixture.variation_id, quantity: 1 }],
    item_extras: [{ id: fixture.extra_id, quantity: 1 }],
    item_addons: [{ id: fixture.addon_id, quantity: 1 }],
    instruction,
  };
}

async function createPosOrderViaApi(page, fixture) {
  return page.evaluate(async ({ fixture, prefix, askNo, sourcePos, orderTypeTakeaway, posPaymentCash }) => {
    const items = [fixture.line];
    const token = `${prefix}-POS-${fixture.run}-${Date.now()}`;
    const basePayload = {
      branch_id: fixture.branch_id,
      customer_id: fixture.customer_id,
      token,
      discount: 0,
      order_type: orderTypeTakeaway,
      is_advance_order: askNo,
      source: sourcePos,
      pos_payment_method: posPaymentCash,
      pos_received_amount: 30,
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
      headers: { 'X-Idempotency-Key': `${prefix}-POS-${fixture.run}-${Date.now()}` },
    });
    return {
      quote,
      order: response.data.data,
    };
  }, {
    fixture: {
      ...fixture,
      line: orderLine(fixture, `${PREFIX} POS line ${fixture.run}`),
    },
    prefix: PREFIX,
    askNo: ASK_NO,
    sourcePos: SOURCE_POS,
    orderTypeTakeaway: ORDER_TYPE_TAKEAWAY,
    posPaymentCash: POS_PAYMENT_CASH,
  });
}

async function createKioskCardOrderViaApi(page, fixture) {
  return page.evaluate(async ({ fixture, prefix, askNo, sourceKiosk, orderTypeKiosk, paymentCard }) => {
    const items = [fixture.line];
    const basePayload = {
      order_type: orderTypeKiosk,
      is_advance_order: askNo,
      source: sourceKiosk,
      payment_method: paymentCard,
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
      headers: { 'X-Idempotency-Key': `${prefix}-KIOSK-${fixture.run}-${Date.now()}` },
    });
    const order = response.data.data;
    const confirm = await window.axios.post(`frontend/order/${order.id}/payment-confirm`, {
      transaction_id: `${prefix}-TPE-SIM-${fixture.run}-${Date.now()}`,
      card_type: 'simulated-card',
      payment_method: paymentCard,
    });
    return {
      quote,
      order,
      payment_confirm: confirm.data,
    };
  }, {
    fixture: {
      ...fixture,
      line: orderLine(fixture, `${PREFIX} KIOSK card line ${fixture.run}`),
    },
    prefix: PREFIX,
    askNo: ASK_NO,
    sourceKiosk: SOURCE_KIOSK,
    orderTypeKiosk: ORDER_TYPE_KIOSK,
    paymentCard: PAYMENT_CARD,
  });
}

async function waitForBodyText(page, text, timeout = 20_000) {
  const pattern = text instanceof RegExp ? text : new RegExp(escapeRegex(text));
  const started = Date.now();
  await expect(page.locator('body')).toContainText(pattern, { timeout });
  return Date.now() - started;
}

async function kdsChangeStatus(page, orderId, expectedStatus, nextStatus) {
  clearFoodKingRateLimits();
  const result = await page.evaluate(async ({ orderId, expectedStatus, nextStatus }) => {
    try {
      const response = await window.axios.post(`admin/kds-order/change-status/${orderId}`, {
        expected_status: expectedStatus,
        status: nextStatus,
      });
      window.dispatchEvent(new CustomEvent('realtime-order-update', {
        detail: { id: orderId, status: nextStatus },
      }));
      return { ok: response.status >= 200 && response.status < 300, status: response.status };
    } catch (error) {
      return {
        ok: false,
        status: error?.response?.status ?? null,
        message: error?.response?.data?.message || error?.message || String(error),
      };
    }
  }, { orderId, expectedStatus, nextStatus });
  expect(result).toMatchObject({ ok: true });
}

async function openKioskCategoryAsCustomer(page, categoryId) {
  await loginAsKiosk(page);
  await page.goto('/kiosk/idle');
  await expect(page.getByTestId('kiosk-order-type-dine-in')).toBeVisible({ timeout: 20_000 });
  await page.getByTestId('kiosk-order-type-dine-in').click();
  await expect(page.getByTestId('kiosk-categories-root')).toBeVisible({ timeout: 20_000 });
  await page.goto(`/kiosk/categories?cat=${categoryId}`);
  await expect(page.getByTestId('kiosk-categories-root')).toBeVisible({ timeout: 20_000 });
}

function inspectGlobalTrace(orderIds, fixture) {
  return parseArtisanJson(artisan(`
    $orderIds = json_decode('${JSON.stringify(orderIds)}', true);
    $fixture = json_decode('${phpString(JSON.stringify(fixture))}', true);
    $orders = App\\Models\\Order::withoutGlobalScopes()
      ->with('orderItems')
      ->whereIn('id', $orderIds)
      ->orderBy('id')
      ->get();
    $stockTypes = [
      'main_item' => [App\\Models\\Item::class, (int) $fixture['item_id']],
      'variation' => [App\\Models\\ItemVariation::class, (int) $fixture['variation_id']],
      'extra' => [App\\Models\\ItemExtra::class, (int) $fixture['extra_id']],
      'addon_item' => [App\\Models\\Item::class, (int) $fixture['addon_item_id']],
    ];
    $stock = [];
    foreach ($stockTypes as $label => [$type, $id]) {
      $row = App\\Models\\StockLevel::query()
        ->where('branch_id', (int) $fixture['branch_id'])
        ->where('stockable_type', $type)
        ->where('stockable_id', $id)
        ->first();
      $stock[$label] = $row ? (int) $row->on_hand : null;
    }
    $eventQuery = DB::table('domain_events')->whereIn('aggregate_id', $orderIds);
    if (Schema::hasColumn('domain_events', 'aggregate_type')) {
      $eventQuery->whereIn('aggregate_type', ['order', App\\Models\\Order::class, App\\Models\\FrontendOrder::class]);
    }
    $events = $eventQuery->get()->groupBy('aggregate_id')->map(fn ($rows) => $rows->pluck('broadcast_as')->values()->all())->all();
    $transitions = DB::table('order_status_transitions')
      ->whereIn('order_id', $orderIds)
      ->orderBy('id')
      ->get()
      ->groupBy('order_id')
      ->map(fn ($rows) => $rows->map(fn ($row) => [
        'from' => (int) $row->from_status,
        'to' => (int) $row->to_status,
      ])->values()->all())
      ->all();
    $movementQuery = DB::table('stock_movements')
      ->whereIn('stock_movements.reference_id', $orderIds)
      ->where('stock_movements.reason', 'order_created');
    if (Schema::hasColumn('stock_movements', 'stock_level_id')) {
      $movementQuery
        ->leftJoin('stock_levels', 'stock_movements.stock_level_id', '=', 'stock_levels.id')
        ->select(
          'stock_movements.*',
          'stock_levels.stockable_type as movement_stockable_type',
          'stock_levels.stockable_id as movement_stockable_id'
        );
    }
    $movements = $movementQuery->get()
      ->groupBy('reference_id')
      ->map(fn ($rows) => [
        'count' => $rows->count(),
        'delta_sum' => (int) $rows->sum('delta'),
        'stockables' => $rows->map(fn ($row) => class_basename($row->movement_stockable_type ?? $row->stockable_type ?? '') . '#' . ($row->movement_stockable_id ?? $row->stockable_id ?? '') . ':' . $row->delta)->values()->all(),
      ])
      ->all();
    $queueCounts = DB::table('orders')
      ->select('queue_number', DB::raw('COUNT(*) as c'))
      ->where('branch_id', (int) $fixture['branch_id'])
      ->whereIn('business_date', $orders->pluck('business_date')->filter()->values())
      ->whereIn('queue_number', $orders->pluck('queue_number')->filter()->values())
      ->groupBy('queue_number')
      ->pluck('c', 'queue_number')
      ->all();
    echo json_encode([
      'orders' => $orders->map(function ($order) use ($events, $transitions, $movements) {
        $item = $order->orderItems->first();
        $snapshot = $item ? $item->composition_snapshot : null;
        if (is_string($snapshot)) {
          $snapshot = json_decode($snapshot, true);
        }
        return [
          'id' => (int) $order->id,
          'branch_id' => (int) $order->branch_id,
          'business_date' => optional($order->business_date)->format('Y-m-d'),
          'queue_number' => $order->queue_number,
          'token' => $order->token,
          'source_surface' => $order->source_surface,
          'order_type' => (int) $order->order_type,
          'status' => (int) $order->status,
          'payment_method' => $order->payment_method === null ? null : (int) $order->payment_method,
          'payment_status' => (int) $order->payment_status,
          'pos_payment_method' => $order->pos_payment_method === null ? null : (int) $order->pos_payment_method,
          'total' => round((float) $order->total, 2),
          'subtotal' => round((float) $order->subtotal, 2),
          'discount' => round((float) $order->discount, 2),
          'fiscal_sequence_no' => $order->fiscal_sequence_no === null ? null : (int) $order->fiscal_sequence_no,
          'transaction_id' => $order->transaction_id,
          'order_items_count' => $order->orderItems->count(),
          'composition_snapshot' => $snapshot,
          'domain_events' => $events[$order->id] ?? [],
          'transitions' => $transitions[$order->id] ?? [],
          'stock_movement' => $movements[$order->id] ?? null,
        ];
      })->values()->all(),
      'stock' => $stock,
      'queue_counts' => $queueCounts,
    ]);
  `));
}

test.describe('global POS + kiosk order trace audit', () => {
  test.describe.configure({ timeout: 240_000 });

  const results = [];

  test.beforeEach(() => {
    clearFoodKingRateLimits();
    cleanupTraceAudit();
  });

  test.afterEach(() => {
    writeTraceReport(results);
    if (process.env.GLOBAL_TRACE_SKIP_CLEANUP !== '1') {
      cleanupTraceAudit();
    }
  });

  test('tracks one POS order and one simulated-card kiosk order from entry to KDS prepared with backend audit', async ({ browser }) => {
    const fixture = createTraceFixture();
    const adminContext = await browser.newContext();
    const posContext = await browser.newContext();
    const kioskContext = await browser.newContext();
    const kdsContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    const posPage = await posContext.newPage();
    const kioskPage = await kioskContext.newPage();
    const kdsPage = await kdsContext.newPage();

    const timeline = [];
    const mark = (label, payload = {}) => timeline.push({ at: new Date().toISOString(), label, ...payload });

    try {
      ensureAdminLogin();
      await login(adminPage, ADMIN_EMAIL, ADMIN_PASSWORD);
      await adminPage.goto('/admin/items');
      await loginAsPOS(posPage);
      await posPage.goto('/admin/pos');
      await openKioskCategoryAsCustomer(kioskPage, fixture.category_id);
      await loginAsChef(kdsPage);
      await kdsPage.goto('/admin/kitchen-display-system');
      mark('surfaces_authenticated', {
        pos_url: posPage.url(),
        kiosk_url: kioskPage.url(),
        kds_url: kdsPage.url(),
        admin_url: adminPage.url(),
      });

      const posProjection = await projectionFor(adminPage, 'pos', fixture.branch_id);
      const kioskProjection = await projectionFor(adminPage, 'kiosk', fixture.branch_id);
      const posProjected = findProjectedItem(posProjection, fixture.item_id);
      const kioskProjected = findProjectedItem(kioskProjection, fixture.item_id);
      expect(posProjected).toBeTruthy();
      expect(kioskProjected).toBeTruthy();
      expect(posProjected.item.composer_profile.steps).toHaveLength(3);
      expect(kioskProjected.item.composer_profile.steps).toHaveLength(3);
      mark('central_projection_visible', {
        pos_steps: posProjected.item.composer_profile.steps.length,
        kiosk_steps: kioskProjected.item.composer_profile.steps.length,
      });

      await expect(kioskPage.getByTestId(`kiosk-product-card-${fixture.item_id}`)).toBeVisible({ timeout: 30_000 });
      mark('kiosk_catalog_card_visible', { item_id: fixture.item_id });

      let posCreated;
      await expectNoCriticalBrowserErrors(posPage, async () => {
        posCreated = await createPosOrderViaApi(posPage, fixture);
      });
      expect(Number(posCreated.quote.total_ttc)).toBeCloseTo(fixture.expected_total, 2);
      expect(Number(posCreated.order.total)).toBeCloseTo(fixture.expected_total, 2);
      mark('pos_order_created', {
        order_id: posCreated.order.id,
        queue_number: posCreated.order.queue_number,
        total: posCreated.order.total,
      });

      let kioskCreated;
      await expectNoCriticalBrowserErrors(kioskPage, async () => {
        kioskCreated = await createKioskCardOrderViaApi(kioskPage, fixture);
      });
      expect(Number(kioskCreated.quote.total_ttc)).toBeCloseTo(fixture.expected_total, 2);
      mark('kiosk_order_created_and_payment_confirmed', {
        order_id: kioskCreated.order.id,
        queue_number: kioskCreated.order.queue_number,
        total: kioskCreated.order.total,
        payment_confirm_status: kioskCreated.payment_confirm?.status ?? null,
      });

      const kioskQueue = new RegExp(escapeRegex(kioskCreated.order.queue_number));
      await kdsPage.bringToFront();
      const posKdsMs = await waitForBodyText(kdsPage, `${PREFIX} POS line ${fixture.run}`, 30_000);
      const kioskKdsMs = await waitForBodyText(kdsPage, kioskQueue, 30_000);
      const addonKdsMs = await waitForBodyText(kdsPage, fixture.addon_name, 30_000);
      mark('kds_received_both_orders', {
        pos_kds_ms: posKdsMs,
        kiosk_kds_ms: kioskKdsMs,
        addon_visible_ms: addonKdsMs,
      });

      for (const orderId of [posCreated.order.id, kioskCreated.order.id]) {
        await kdsChangeStatus(kdsPage, orderId, orderStatusEnum.ACCEPT, orderStatusEnum.PREPARING);
        await kdsChangeStatus(kdsPage, orderId, orderStatusEnum.PREPARING, orderStatusEnum.PREPARED);
      }
      mark('kds_marked_both_orders_prepared', {
        status: orderStatusEnum.PREPARED,
      });

      const audit = inspectGlobalTrace([posCreated.order.id, kioskCreated.order.id], fixture);
      expect(audit.orders).toHaveLength(2);
      const bySurface = Object.fromEntries(audit.orders.map((order) => [order.source_surface, order]));
      expect(bySurface.pos).toBeTruthy();
      expect(bySurface.kiosk).toBeTruthy();
      expect(bySurface.pos.branch_id).toBe(fixture.branch_id);
      expect(bySurface.kiosk.branch_id).toBe(fixture.branch_id);
      expect(bySurface.pos.status).toBe(orderStatusEnum.PREPARED);
      expect(bySurface.kiosk.status).toBe(orderStatusEnum.PREPARED);
      expect(bySurface.pos.payment_status).toBe(5);
      expect(bySurface.kiosk.payment_status).toBe(5);
      expect(bySurface.kiosk.payment_method).toBe(PAYMENT_CARD);
      expect(bySurface.pos.pos_payment_method).toBe(POS_PAYMENT_CASH);
      expect(bySurface.pos.total).toBeCloseTo(fixture.expected_total, 2);
      expect(bySurface.kiosk.total).toBeCloseTo(fixture.expected_total, 2);
      expect(bySurface.pos.queue_number).not.toBe(bySurface.kiosk.queue_number);
      expect(audit.queue_counts[bySurface.pos.queue_number]).toBe(1);
      expect(audit.queue_counts[bySurface.kiosk.queue_number]).toBe(1);

      for (const order of audit.orders) {
        expect(order.composition_snapshot?.lines?.map((line) => line.variation_name)).toEqual(expect.arrayContaining([fixture.variation_name]));
        expect(order.composition_snapshot?.extras?.map((line) => line.extra_name)).toEqual(expect.arrayContaining([fixture.extra_name]));
        expect(order.composition_snapshot?.addons?.map((line) => line.addon_name)).toEqual(expect.arrayContaining([fixture.addon_name]));
        expect(order.domain_events).toEqual(expect.arrayContaining(['OrderCreated', 'OrderStatusChanged']));
        expect(order.transitions.map((row) => row.to)).toEqual(expect.arrayContaining([orderStatusEnum.PREPARING, orderStatusEnum.PREPARED]));
        expect(order.stock_movement?.count).toBe(4);
        expect(order.stock_movement?.delta_sum).toBe(-4);
      }

      expect(audit.stock.main_item).toBe(18);
      expect(audit.stock.variation).toBe(18);
      expect(audit.stock.extra).toBe(18);
      expect(audit.stock.addon_item).toBe(18);
      mark('backend_audit_passed', {
        order_ids: audit.orders.map((order) => order.id),
        queues: audit.orders.map((order) => order.queue_number),
        stock: audit.stock,
      });

      results.push({
        pass: true,
        fixture: {
          run: fixture.run,
          branch_id: fixture.branch_id,
          item_id: fixture.item_id,
          item_name: fixture.item_name,
          variation_id: fixture.variation_id,
          extra_id: fixture.extra_id,
          addon_id: fixture.addon_id,
          expected_total: fixture.expected_total,
        },
        pos_order_id: posCreated.order.id,
        kiosk_order_id: kioskCreated.order.id,
        pos_queue_number: bySurface.pos.queue_number,
        kiosk_queue_number: bySurface.kiosk.queue_number,
        audit,
        timeline,
      });
    } finally {
      await Promise.all([
        adminContext.close(),
        posContext.close(),
        kioskContext.close(),
        kdsContext.close(),
      ]);
    }
  });
});
