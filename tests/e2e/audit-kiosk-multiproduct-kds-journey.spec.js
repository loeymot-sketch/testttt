const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const { loginAsKiosk, loginAsChefOperator, loginAsAdmin } = require('./helpers/login');
const {
  PAYMENT_CARD,
  DEFAULT_KIOSK_USERNAME,
  assertDedicatedE2EWriteScope,
} = require('./helpers/kiosk-order');

const REPORT_ROOT = path.join(process.cwd(), 'reports/audit/kiosk-multiproduct-kds-journey-2026-05-05');
const SHOT_DIR = path.join(REPORT_ROOT, 'screenshots');
const REPORT_MD = path.join(REPORT_ROOT, 'RAPPORT_AUDIT_BORNE_MULTI_PRODUITS_KDS.md');
const RAW_JSON = path.join(REPORT_ROOT, 'raw-kiosk-multiproduct-trace.json');
const PREFIX = 'AUDIT-KIOSK-MULTI';

function loadNumericEnum(relativePath) {
  const source = fs.readFileSync(path.resolve(__dirname, relativePath), 'utf8');
  const entries = [...source.matchAll(/^\s*([A-Z][A-Z0-9_]*)\s*:\s*(\d+)\s*,?$/gm)]
    .map((match) => [match[1], Number(match[2])]);
  if (entries.length === 0) {
    throw new Error(`Unable to load canonical numeric enum from ${relativePath}`);
  }
  return Object.freeze(Object.fromEntries(entries));
}

const orderStatusEnum = loadNumericEnum('../../resources/js/enums/modules/orderStatusEnum.js');
const paymentStatusEnum = loadNumericEnum('../../resources/js/enums/modules/paymentStatusEnum.js');
const orderTypeEnum = loadNumericEnum('../../resources/js/enums/modules/orderTypeEnum.js');
const paymentTypeEnum = loadNumericEnum('../../resources/js/enums/modules/paymentTypeEnum.js');
const posPaymentMethodEnum = loadNumericEnum('../../resources/js/enums/modules/posPaymentMethodEnum.js');
const ACTIVE_SYNTHETIC_ORDER_STATUSES = Object.freeze([
  orderStatusEnum.PENDING,
  orderStatusEnum.ACCEPT,
  orderStatusEnum.PREPARING,
  orderStatusEnum.PREPARED,
  orderStatusEnum.OUT_FOR_DELIVERY,
]);

function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: process.cwd(),
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

function parseLastJsonLine(output) {
  const lines = String(output).split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
  const jsonLine = [...lines].reverse().find((line) => line.startsWith('{') || line.startsWith('['));
  if (!jsonLine) throw new Error(`No JSON payload in artisan output:\n${output}`);
  return JSON.parse(jsonLine);
}

function phpString(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

function escapeRegex(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function parseAmount(text) {
  const value = parseFloat(String(text).replace(/\s/g, '').replace(/[^\d.,-]/g, '').replace(',', '.'));
  return Number.isFinite(value) ? value : NaN;
}

function verifyDedicatedTestDatabase() {
  const identity = parseLastJsonLine(artisan(`
    echo json_encode([
      'database' => (string) DB::connection()->getDatabaseName(),
    ]);
  `));
  assertDedicatedE2EWriteScope(identity.database);
  return identity;
}

async function cancelOrderThroughPosApi(page, orderId) {
  if (!orderId) return { ok: true, skipped: true };
  return page.evaluate(async ({ id, canceledStatus, idempotencyKey }) => {
    try {
      const response = await window.axios.post(`admin/pos-order/change-status/${id}`, {
        id,
        status: canceledStatus,
        reason: 'Kiosk multi-product canonical synthetic cleanup',
      }, {
        headers: { 'X-Idempotency-Key': idempotencyKey },
      });
      return { ok: response.status >= 200 && response.status < 300, status: response.status };
    } catch (error) {
      return {
        ok: false,
        status: error?.response?.status || 0,
        error: error?.response?.data?.message || error?.message || String(error),
      };
    }
  }, {
    id: Number(orderId),
    canceledStatus: orderStatusEnum.CANCELED,
    idempotencyKey: `e2e-kiosk-multi-cancel-${orderId}-${Date.now()}`,
  });
}

function resolveExistingKioskIdentity() {
  const username = phpString(DEFAULT_KIOSK_USERNAME);
  const identity = parseLastJsonLine(artisan(`
    $machine = App\\Models\\KioskMachine::query()
      ->where('username', '${username}')
      ->first();
    $user = $machine ? App\\Models\\User::query()->find($machine->user_id) : null;
    $otherBranchId = $machine
      ? DB::table('branches')->where('id', '!=', $machine->branch_id)->orderBy('id')->value('id')
      : null;
    $otherCacheKey = $otherBranchId ? 'kiosk.menu.branch.' . $otherBranchId : null;
    $otherCacheValue = $otherCacheKey
      ? Illuminate\\Support\\Facades\\Cache::get($otherCacheKey)
      : null;
    $identitySnapshot = static function ($model, array $fields): ?array {
      if (! $model) return null;
      $snapshot = collect($model->getAttributes())
        ->only($fields)
        ->all();
      if (array_key_exists('password', $snapshot)) {
        $snapshot['password_fingerprint'] = hash('sha256', (string) $snapshot['password']);
        unset($snapshot['password']);
      }
      foreach (['remember_token', 'device_token', 'web_token', 'nfc_uid'] as $secretKey) {
        if (! array_key_exists($secretKey, $snapshot)) continue;
        $snapshot[$secretKey . '_fingerprint'] = hash('sha256', serialize($snapshot[$secretKey]));
        unset($snapshot[$secretKey]);
      }
      ksort($snapshot);
      return $snapshot;
    };
    echo json_encode([
      'machine' => $identitySnapshot($machine, [
        'id', 'user_id', 'branch_id', 'username', 'machine_id', 'password', 'status',
      ]),
      'user' => $identitySnapshot($user, [
        'id', 'branch_id', 'username', 'email', 'password', 'status', 'is_guest',
      ]),
      'other_branch_cache' => [
        'key' => $otherCacheKey,
        'exists' => $otherCacheKey ? Illuminate\\Support\\Facades\\Cache::has($otherCacheKey) : false,
        'sha256' => hash('sha256', serialize($otherCacheValue)),
      ],
    ]);
  `));
  if (!identity.machine?.id || !identity.user?.id || Number(identity.machine.branch_id) <= 0) {
    throw new Error(`Existing kiosk machine/user required: ${JSON.stringify(identity)}`);
  }
  if (Number(identity.user.branch_id) !== Number(identity.machine.branch_id)) {
    throw new Error(`Kiosk machine/user branch mismatch: ${JSON.stringify(identity)}`);
  }
  return identity;
}

async function collectCounterOrderThroughPosApi(page, orderId) {
  return page.evaluate(async ({ id, mode, idempotencyKey }) => {
    try {
      const response = await window.axios.post(`admin/pos/counter-collect/${id}/confirm`, {
        mode,
        received: null,
        note: 'E2E borne multi-produits — encaissement carte au comptoir',
      }, {
        headers: { 'X-Idempotency-Key': idempotencyKey },
      });
      const payload = response.data?.data || response.data;
      return {
        ok: response.status >= 200 && response.status < 300,
        status: response.status,
        payment_status: Number(payload?.payment_status),
        pos_payment_method: Number(payload?.pos_payment_method),
      };
    } catch (error) {
      return {
        ok: false,
        status: error?.response?.status || 0,
        error: error?.response?.data?.message || error?.message || String(error),
      };
    }
  }, {
    id: Number(orderId),
    mode: posPaymentMethodEnum.CARD,
    idempotencyKey: `e2e-kiosk-multi-collect-${orderId}-${Date.now()}`,
  });
}

function createKioskFixture(kioskIdentity) {
  const machineId = Number(kioskIdentity?.machine?.id || 0);
  const userId = Number(kioskIdentity?.user?.id || 0);
  const branchId = Number(kioskIdentity?.machine?.branch_id || 0);
  const username = phpString(kioskIdentity?.machine?.username || DEFAULT_KIOSK_USERNAME);
  if (!machineId || !userId || branchId <= 0) {
    throw new Error(`Invalid read-only kiosk identity: ${JSON.stringify(kioskIdentity)}`);
  }
  return parseLastJsonLine(artisan(`
    use Illuminate\\Support\\Facades\\Schema;
    use Illuminate\\Support\\Facades\\Cache;
    use Illuminate\\Support\\Str;
    use App\\Models\\User;
    use App\\Models\\KioskMachine;
    use App\\Models\\Item;
    use App\\Models\\ItemCategory;
    use App\\Models\\Tax;
    use App\\Models\\ItemBranchAvailability;
    use App\\Models\\StockLevel;
    use App\\Enums\\Status;
    use App\\Enums\\TaxType;

    $prefix = '${PREFIX}';
    $branchId = ${branchId};
    $machine = KioskMachine::query()
      ->where('id', ${machineId})
      ->where('username', '${username}')
      ->first();
    $machineUser = User::query()->find(${userId});
    if (! $machine || ! $machineUser) {
      throw new RuntimeException('Existing kiosk machine/user disappeared before fixture creation.');
    }
    if ((int) $machine->user_id !== (int) $machineUser->id
      || (int) $machine->branch_id !== $branchId
      || (int) $machineUser->branch_id !== $branchId) {
      throw new RuntimeException('Kiosk machine/user branch identity changed before fixture creation.');
    }

    $run = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $tax = Tax::query()->create([
      'name' => $prefix . ' TVA 0 ' . $run,
      'code' => 'AKM-' . $run,
      'tax_rate' => 0,
      'type' => TaxType::PERCENTAGE,
      'status' => Status::ACTIVE,
    ]);
    $category = ItemCategory::query()->create([
      'name' => $prefix . ' Categorie borne ' . $run,
      'slug' => Str::slug($prefix . '-categorie-borne-' . $run . '-' . Str::random(4)),
      'status' => Status::ACTIVE,
      'sort' => 9996,
      'kiosk_sort' => 1,
      'wizard_template' => 'simple',
      'has_menu' => false,
      'kiosk_upsell_skip_after_cart' => true,
      'channels' => ['kiosk'],
    ]);

    $products = collect([
      ['label' => 'Burger borne', 'price' => 8.90, 'emoji' => 'burger'],
      ['label' => 'Dessert borne', 'price' => 4.20, 'emoji' => 'dessert'],
    ])->map(function ($row, $index) use ($prefix, $run, $category, $tax, $branchId) {
      $item = Item::query()->create([
        // Keep the culinary discriminator first. The KDS deliberately renders
        // owner-approved short symbols from the first meaningful word; a test
        // prefix first would turn every synthetic product into the same "AUD".
        'name' => $row['label'] . ' ' . $prefix . ' ' . $run,
        'slug' => Str::slug($prefix . '-' . $row['label'] . '-' . $run . '-' . Str::random(4)),
        'item_category_id' => $category->id,
        'tax_id' => $tax->id,
        'price' => $row['price'],
        'status' => Status::ACTIVE,
        'is_available' => true,
        'is_featured' => 1,
        'order' => $index + 1,
        'channels' => ['kiosk'],
        'description' => 'Produit audit borne multi-produits',
        'kiosk_emoji' => $row['emoji'],
      ]);
      if (Schema::hasTable('item_branch_availability')) {
        ItemBranchAvailability::query()->updateOrCreate(
          ['branch_id' => $branchId, 'item_id' => $item->id],
          ['is_available' => true, 'unavailable_reason' => null, 'daily_consumed_qty' => 0, 'daily_reset_at' => now()->toDateString()]
        );
      }
      if (Schema::hasTable('stock_levels')) {
        StockLevel::query()->updateOrCreate(
          ['branch_id' => $branchId, 'stockable_type' => Item::class, 'stockable_id' => $item->id],
          ['on_hand' => 99, 'reserved' => 0]
        );
      }
      return [
        'item_id' => (int) $item->id,
        'name' => (string) $item->name,
        'price' => (float) $row['price'],
      ];
    })->values();

    $cacheKey = 'kiosk.menu.branch.' . $branchId;
    Cache::forget($cacheKey);
    echo json_encode([
      'ok' => true,
      'run' => $run,
      'branch_id' => $branchId,
      'tax_id' => (int) $tax->id,
      'category_id' => (int) $category->id,
      'category_name' => (string) $category->name,
      'products' => $products,
      'expected_total' => round((float) $products->sum('price'), 2),
      'cache_key_invalidated' => $cacheKey,
      'cache_present_after_invalidation' => Cache::has($cacheKey),
    ]);
  `));
}

function findActiveSyntheticOrderIds(branchId) {
  const statuses = phpString(JSON.stringify(ACTIVE_SYNTHETIC_ORDER_STATUSES));
  const result = parseLastJsonLine(artisan(`
    $branchId = (int) ${Number(branchId)};
    $prefix = '${PREFIX}';
    $activeStatuses = json_decode('${statuses}', true);
    $query = DB::table('orders')
      ->join('order_items', 'order_items.order_id', '=', 'orders.id')
      ->join('items', 'items.id', '=', 'order_items.item_id')
      ->where('orders.branch_id', $branchId)
      ->whereIn('orders.status', $activeStatuses)
      ->where('items.name', 'like', '%' . $prefix . '%');
    if (Schema::hasColumn('orders', 'deleted_at')) {
      $query->whereNull('orders.deleted_at');
    }
    echo json_encode($query->distinct()->orderBy('orders.id')->pluck('orders.id')->map(fn ($id) => (int) $id)->values());
  `));
  return Array.isArray(result) ? result.map(Number).filter((id) => id > 0) : [];
}

async function cancelActiveSyntheticOrders(page, branchId) {
  const attempted = findActiveSyntheticOrderIds(branchId);
  const failures = [];
  for (const orderId of attempted) {
    const result = await cancelOrderThroughPosApi(page, orderId);
    if (!result.ok) failures.push({ order_id: orderId, ...result });
  }
  return {
    attempted,
    failures,
    remaining: findActiveSyntheticOrderIds(branchId),
  };
}

function deactivateKioskFixtures(branchId, fixture = null) {
  const branch = Number(branchId);
  if (!Number.isInteger(branch) || branch <= 0) {
    throw new Error(`Cannot deactivate synthetic fixtures without an exact branch: ${branchId}`);
  }
  const retired = parseLastJsonLine(artisan(`
    use App\\Enums\\Status;
    use Illuminate\\Support\\Facades\\Cache;
    $branchId = ${branch};
    $fixture = json_decode('${phpString(JSON.stringify(fixture || {}))}', true);
    $prefix = '${PREFIX}';
    $itemQuery = DB::table('items')->where('items.name', 'like', '%' . $prefix . '%');
    $itemQuery->where(function ($query) use ($branchId) {
      if (Schema::hasTable('item_branch_availability')) {
        $query->whereExists(function ($sub) use ($branchId) {
          $sub->selectRaw('1')
            ->from('item_branch_availability as iba')
            ->whereColumn('iba.item_id', 'items.id')
            ->where('iba.branch_id', $branchId);
        });
      }
      $method = Schema::hasTable('item_branch_availability') ? 'orWhereExists' : 'whereExists';
      $query->{$method}(function ($sub) use ($branchId) {
        $sub->selectRaw('1')
          ->from('order_items as oi')
          ->join('orders as synthetic_orders', 'synthetic_orders.id', '=', 'oi.order_id')
          ->whereColumn('oi.item_id', 'items.id')
          ->where('synthetic_orders.branch_id', $branchId);
      });
    });
    $itemIds = $itemQuery->pluck('items.id')
      ->merge(collect($fixture['products'] ?? [])->pluck('item_id'))
      ->map(fn ($id) => (int) $id)
      ->unique()
      ->values();
    $categoryIds = DB::table('items')->whereIn('id', $itemIds)->pluck('item_category_id')
      ->merge(isset($fixture['category_id']) ? [(int) $fixture['category_id']] : [])
      ->filter()->unique()->values();
    $taxIds = DB::table('items')->whereIn('id', $itemIds)->pluck('tax_id')
      ->merge(isset($fixture['tax_id']) ? [(int) $fixture['tax_id']] : [])
      ->filter()->unique()->values();

    $items = DB::table('items')
      ->whereIn('id', $itemIds)
      ->where('name', 'like', '%' . $prefix . '%')
      ->update(['status' => Status::INACTIVE, 'is_available' => false, 'updated_at' => now()]);
    if (Schema::hasTable('item_branch_availability')) {
      DB::table('item_branch_availability')
        ->where('branch_id', $branchId)
        ->whereIn('item_id', $itemIds)
        ->update([
          'is_available' => false,
          'unavailable_reason' => 'manual',
          'updated_at' => now(),
        ]);
    }
    $categories = DB::table('item_categories')
      ->whereIn('id', $categoryIds)
      ->where('name', 'like', $prefix . '%')
      ->update(['status' => Status::INACTIVE, 'updated_at' => now()]);
    $taxes = DB::table('taxes')
      ->whereIn('id', $taxIds)
      ->where('name', 'like', $prefix . '%')
      ->update(['status' => Status::INACTIVE, 'updated_at' => now()]);

    $cacheKey = 'kiosk.menu.branch.' . $branchId;
    Cache::forget($cacheKey);
    echo json_encode([
      'ok' => true,
      'branch_id' => $branchId,
      'item_ids' => $itemIds,
      'category_ids' => $categoryIds,
      'tax_ids' => $taxIds,
      'items_updated' => (int) $items,
      'categories_updated' => (int) $categories,
      'taxes_updated' => (int) $taxes,
      'cache_key_invalidated' => $cacheKey,
      'cache_present_after_invalidation' => Cache::has($cacheKey),
    ]);
  `));
  return retired;
}

function inspectSyntheticState(branchId, fixture = null) {
  const branch = Number(branchId);
  const catalog = parseLastJsonLine(artisan(`
    use App\\Enums\\Status;
    $branchId = ${branch};
    $fixture = json_decode('${phpString(JSON.stringify(fixture || {}))}', true);
    $prefix = '${PREFIX}';
    $itemQuery = DB::table('items')->where('items.name', 'like', '%' . $prefix . '%');
    $itemQuery->where(function ($query) use ($branchId) {
      if (Schema::hasTable('item_branch_availability')) {
        $query->whereExists(function ($sub) use ($branchId) {
          $sub->selectRaw('1')->from('item_branch_availability as iba')
            ->whereColumn('iba.item_id', 'items.id')->where('iba.branch_id', $branchId);
        });
      }
      $method = Schema::hasTable('item_branch_availability') ? 'orWhereExists' : 'whereExists';
      $query->{$method}(function ($sub) use ($branchId) {
        $sub->selectRaw('1')->from('order_items as oi')
          ->join('orders as synthetic_orders', 'synthetic_orders.id', '=', 'oi.order_id')
          ->whereColumn('oi.item_id', 'items.id')->where('synthetic_orders.branch_id', $branchId);
      });
    });
    $itemIds = $itemQuery->pluck('items.id')
      ->merge(collect($fixture['products'] ?? [])->pluck('item_id'))
      ->map(fn ($id) => (int) $id)->unique()->values();
    $categoryIds = DB::table('items')->whereIn('id', $itemIds)->pluck('item_category_id')
      ->merge(isset($fixture['category_id']) ? [(int) $fixture['category_id']] : [])
      ->filter()->unique()->values();
    $taxIds = DB::table('items')->whereIn('id', $itemIds)->pluck('tax_id')
      ->merge(isset($fixture['tax_id']) ? [(int) $fixture['tax_id']] : [])
      ->filter()->unique()->values();
    echo json_encode([
      'branch_id' => $branchId,
      'active_items' => DB::table('items')->whereIn('id', $itemIds)->where('status', Status::ACTIVE)->count(),
      'active_categories' => DB::table('item_categories')->whereIn('id', $categoryIds)->where('status', Status::ACTIVE)->count(),
      'active_taxes' => DB::table('taxes')->whereIn('id', $taxIds)->where('status', Status::ACTIVE)->count(),
    ]);
  `));
  const activeOrderIds = findActiveSyntheticOrderIds(branch);
  return {
    ...catalog,
    active_orders: activeOrderIds.length,
    active_order_ids: activeOrderIds,
  };
}

async function cleanupSyntheticScope(browser, branchId, fixture, phase) {
  const failures = [];
  let cancellation = { attempted: [], failures: [], remaining: [] };
  let retired = null;
  let cleanupContext = null;
  try {
    cleanupContext = await browser.newContext();
    const cleanupPage = await cleanupContext.newPage();
    await loginAsAdmin(cleanupPage);
    cancellation = await cancelActiveSyntheticOrders(cleanupPage, branchId);
    failures.push(...cancellation.failures.map((failure) => ({
      stage: `${phase}:cancel`,
      ...failure,
    })));
    if (cancellation.remaining.length > 0) {
      failures.push({
        stage: `${phase}:cancel-postcondition`,
        active_order_ids: cancellation.remaining,
      });
    }
  } catch (error) {
    failures.push({ stage: `${phase}:cancel-runtime`, error: error?.message || String(error) });
  } finally {
    await cleanupContext?.close().catch((error) => {
      failures.push({ stage: `${phase}:context-close`, error: error?.message || String(error) });
    });
    try {
      retired = deactivateKioskFixtures(branchId, fixture);
      if (retired.cache_key_invalidated !== `kiosk.menu.branch.${branchId}`
        || retired.cache_present_after_invalidation !== false) {
        failures.push({ stage: `${phase}:cache-invalidation`, retired });
      }
    } catch (error) {
      failures.push({ stage: `${phase}:catalog-deactivate`, error: error?.message || String(error) });
    }
  }

  let state = null;
  try {
    state = inspectSyntheticState(branchId, fixture);
    if (Number(state.active_orders) !== 0
      || Number(state.active_items) !== 0
      || Number(state.active_categories) !== 0
      || Number(state.active_taxes) !== 0) {
      failures.push({ stage: `${phase}:final-postcondition`, state });
    }
  } catch (error) {
    failures.push({ stage: `${phase}:inspect-postcondition`, error: error?.message || String(error) });
  }

  return { cancellation, retired, state, failures };
}

function inspectKioskOrder(orderId, fixture) {
  return parseLastJsonLine(artisan(`
    $orderId = (int) ${Number(orderId)};
    $fixture = json_decode('${phpString(JSON.stringify(fixture))}', true);
    $order = App\\Models\\Order::withoutGlobalScopes()->with('orderItems.orderItem')->findOrFail($orderId);
    $transitions = DB::table('order_status_transitions')
      ->where('order_id', $orderId)
      ->orderBy('id')
      ->get()
      ->map(fn ($row) => ['from' => (int) $row->from_status, 'to' => (int) $row->to_status])
      ->values();
    $events = collect();
    if (Schema::hasTable('domain_events')) {
      $events = DB::table('domain_events')->where('aggregate_id', $orderId)->pluck('broadcast_as');
    }
    $movements = collect();
    if (Schema::hasTable('stock_movements')) {
      $movements = DB::table('stock_movements')
        ->where('reference_id', $orderId)
        ->where('reason', 'order_created')
        ->get();
    }
    $queueCount = DB::table('orders')
      ->where('branch_id', (int) $order->branch_id)
      ->where('business_date', $order->business_date)
      ->where('queue_number', $order->queue_number)
      ->count();
    echo json_encode([
      'order' => [
        'id' => (int) $order->id,
        'branch_id' => (int) $order->branch_id,
        'business_date' => optional($order->business_date)->format('Y-m-d'),
        'queue_number' => $order->queue_number,
        'order_serial_no' => $order->order_serial_no,
        'source_surface' => $order->source_surface,
        'order_type' => (int) $order->order_type,
        'status' => (int) $order->status,
        'payment_method' => (int) $order->payment_method,
        'payment_status' => (int) $order->payment_status,
        'pos_payment_method' => $order->pos_payment_method === null ? null : (int) $order->pos_payment_method,
        'subtotal' => round((float) $order->subtotal, 2),
        'total' => round((float) $order->total, 2),
        'order_items_count' => $order->orderItems->count(),
        'fiscal_sequence_no' => $order->fiscal_sequence_no === null ? null : (int) $order->fiscal_sequence_no,
      ],
      'items' => $order->orderItems->map(function ($line) {
        $snapshot = $line->composition_snapshot;
        if (is_string($snapshot)) $snapshot = json_decode($snapshot, true);
        $lineTotal = (float) ($line->total_price ?? 0);
        if ($lineTotal <= 0) {
          $lineTotal = ((float) $line->price + (float) $line->item_variation_total + (float) $line->item_extra_total - (float) $line->discount) * (int) $line->quantity;
        }
        return [
          'item_id' => (int) $line->item_id,
          'item_name' => $line->item_name ?: optional($line->orderItem)->name,
          'quantity' => (int) $line->quantity,
          'total' => round($lineTotal, 2),
          'instruction' => $line->instruction,
          'composition_snapshot' => $snapshot,
        ];
      })->values(),
      'transitions' => $transitions,
      'domain_events' => $events->values(),
      'stock_movement' => [
        'count' => $movements->count(),
        'delta_sum' => (int) $movements->sum('delta'),
      ],
      'queue_count_same_day' => (int) $queueCount,
      'fixture' => $fixture,
    ]);
  `));
}

async function snap(page, slug, notes, label) {
  fs.mkdirSync(SHOT_DIR, { recursive: true });
  await page.waitForLoadState('networkidle', { timeout: 2500 }).catch(() => {});
  await page.waitForTimeout(350);
  const file = `${slug}.png`;
  const abs = path.join(SHOT_DIR, file);
  await page.screenshot({ path: abs, fullPage: false });
  const bodyText = await page.locator('body').innerText({ timeout: 3000 }).catch(() => '');
  const sha256 = crypto.createHash('sha256').update(fs.readFileSync(abs)).digest('hex');
  notes.push({
    slug,
    file,
    label,
    url: page.url(),
    sha256,
    text: bodyText.replace(/\s+/g, ' ').trim().slice(0, 260),
  });
}

async function hideDevelopmentDebugbar(page) {
  await page.addStyleTag({
    content: '.phpdebugbar, .phpdebugbar-openhandler { display: none !important; pointer-events: none !important; }',
  }).catch(() => {});
}

async function addOverlay(page, lines) {
  await page.evaluate((payload) => {
    const previous = document.querySelector('[data-testid="audit-kiosk-flow-overlay"]');
    if (previous) previous.remove();
    const box = document.createElement('section');
    box.dataset.testid = 'audit-kiosk-flow-overlay';
    box.style.cssText = [
      'position:fixed',
      'left:24px',
      'bottom:24px',
      'z-index:99999',
      'max-width:560px',
      'background:#111827',
      'color:#fff',
      'border:3px solid #38bdf8',
      'border-radius:10px',
      'padding:14px 16px',
      'box-shadow:0 24px 70px rgba(0,0,0,.35)',
      'font:700 14px/1.45 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
    ].join(';');
    box.innerHTML = payload.map((line) => `<div>${String(line).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;',
    }[c]))}</div>`).join('');
    document.body.appendChild(box);
  }, lines);
}

async function addSimpleProductFromKiosk(page, product, notes, stepNo, expectedCount) {
  const card = page.getByTestId(`kiosk-product-card-${product.item_id}`);
  await expect(card, `Carte produit borne introuvable: ${product.name}`).toBeVisible({ timeout: 20_000 });
  await card.scrollIntoViewIfNeeded();
  await page.getByTestId(`kiosk-product-add-${product.item_id}`).click();

  await page.waitForFunction((count) => {
    const summary = document.querySelector('[data-testid="kiosk-order-summary-root"]');
    const cart = document.querySelector('[data-testid="kiosk-categories-cart-indicator"]');
    const text = cart ? cart.textContent || '' : '';
    return !!summary || new RegExp(`${count}\\s+article`, 'i').test(text);
  }, expectedCount, { timeout: 20_000 });

  if (await page.getByTestId('kiosk-order-summary-root').isVisible({ timeout: 1000 }).catch(() => false)) {
    await expect(page.getByTestId('kiosk-order-summary-main-name')).toContainText(new RegExp(escapeRegex(product.name), 'i'), { timeout: 10_000 });
    await addOverlay(page, [`Produit ${stepNo}: recap borne`, product.name, 'Validation avant ajout panier']);
    await snap(page, `0${stepNo + 2}-kiosk-produit-${stepNo}-recap`, notes, `recap produit ${stepNo} avant ajout panier`);

    const addButton = page.locator('.kiosk-wizard .kiosk-btn-next').last();
    await expect(addButton).toBeEnabled({ timeout: 10_000 });
    await addButton.click();
  }

  await expect(page.getByTestId('kiosk-categories-root')).toBeVisible({ timeout: 15_000 });
  await expect(page.getByTestId('kiosk-categories-cart-indicator')).toBeVisible({ timeout: 10_000 });
  await expect(page.getByTestId('kiosk-categories-cart-indicator')).toContainText(new RegExp(`${expectedCount}\\s+article`, 'i'), { timeout: 10_000 });
  await addOverlay(page, [`Produit ${stepNo} ajoute`, product.name, 'Retour catalogue avec panier actif']);
  await snap(page, `0${stepNo + 3}-kiosk-apres-ajout-produit-${stepNo}`, notes, `catalogue apres ajout produit ${stepNo}`);
}

async function proceedPastUpsellIfNeeded(page, notes) {
  await page.waitForFunction(() => (
    document.querySelector('[data-testid="kiosk-payment-root"]')
    || document.querySelector('[data-testid="kiosk-upsell-root"]')
    || document.querySelector('[data-testid="kiosk-cart-quote-error"]')
  ), null, { timeout: 30_000 });

  const quoteError = page.getByTestId('kiosk-cart-quote-error');
  if (await quoteError.isVisible({ timeout: 1000 }).catch(() => false)) {
    throw new Error(`Erreur quote panier borne: ${await quoteError.innerText()}`);
  }

  if (await page.getByTestId('kiosk-upsell-root').isVisible({ timeout: 1000 }).catch(() => false)) {
    await addOverlay(page, ['Upsell borne detecte', 'Capture puis passage volontaire', 'Aucun produit supplementaire ajoute']);
    await snap(page, '08-kiosk-upsell-affiche-skip', notes, 'ecran upsell affiche puis refuse');
    await page.getByTestId('kiosk-upsell-skip').click();
  }

  await expect(page.getByTestId('kiosk-payment-root')).toBeVisible({ timeout: 20_000 });
}

async function changeKdsStatus(page, orderId, expectedStatus, nextStatus) {
  const result = await page.evaluate(async ({ orderId, expectedStatus, nextStatus, idempotencyKey }) => {
    try {
      const response = await window.axios.post(`admin/kds-order/change-status/${orderId}`, {
        expected_status: expectedStatus,
        status: nextStatus,
      }, {
        headers: { 'X-Idempotency-Key': idempotencyKey },
      });
      window.dispatchEvent(new CustomEvent('realtime-order-update', {
        detail: { type: 'kiosk-multi-audit-status-change', order_id: orderId, status: nextStatus },
      }));
      return { ok: response.status >= 200 && response.status < 300, status: response.status };
    } catch (error) {
      return {
        ok: false,
        status: error?.response?.status || 0,
        error: error?.response?.data?.message || error?.message || String(error),
      };
    }
  }, {
    orderId,
    expectedStatus,
    nextStatus,
    idempotencyKey: `e2e-kds-${orderId}-${expectedStatus}-${nextStatus}-${Date.now()}`,
  });
  expect(result, `Transition KDS refusée: ${JSON.stringify(result)}`).toMatchObject({ ok: true });
}

function writeReport({ notes, trace, orderResponse, fixture, paymentMode }) {
  const missingInstructionLines = (trace.items || []).filter((line) => !String(line.instruction || '').trim());
  const md = [];
  md.push('# Audit borne multi-produits -> paiement -> backend -> KDS\n\n');
  md.push(`Date UTC: ${new Date().toISOString()}\n\n`);
  md.push('## Verdict\n\n');
  md.push('- Flux borne multi-produits execute dans le navigateur: PASS.\n');
  md.push(paymentMode === 'counter'
    ? '- Paiement différé au comptoir: commande créée en `PENDING_COUNTER`, sans faux encaissement borne: PASS.\n'
    : '- Paiement carte avec stub TPE navigateur + confirmation backend: PASS.\n');
  md.push(paymentMode === 'counter'
    ? '- Creation backend `POST /api/frontend/order`; encaissement carte ensuite confirmé par le point d entrée POS canonique avant admission KDS: PASS.\n'
    : '- Creation backend `POST /api/frontend/order` + `payment-confirm`: PASS.\n');
  md.push('- KDS: commande visible, deux lignes produit visibles, transitions en preparation puis pret: PASS.\n');
  md.push('- Controle anti-duplication: `queue_count_same_day = 1`, `order_items_count = 2`: PASS.\n\n');

  md.push('## Point audit visuel borne\n\n');
  md.push('- La capture catalogue montre que les noms longs de categorie et de produit peuvent deborder et se superposer dans la borne. Le flux reste fonctionnel, mais la finition UI doit tronquer, reduire ou reflow ces libelles.\n\n');

  if (missingInstructionLines.length > 0) {
    md.push('## Point audit cuisine\n\n');
    md.push('- La borne simple produit ne propose pas de champ instruction libre dans ce parcours; le KDS recoit les lignes produit, mais pas de note client cuisine personnalisee.\n');
    md.push('- Pour une V1 restaurant plus forte, ajouter un champ instruction client borne ou imposer des choix composer visibles sur KDS pour les produits qui le demandent.\n\n');
  }

  if (Array.isArray(fixture.runtimeErrors) && fixture.runtimeErrors.length > 0) {
    md.push('## Point audit runtime navigateur\n\n');
    md.push('- Un evenement `pageerror` non bloquant a ete observe pendant le parcours. Le test n a pas echoue car les validations metier, backend et KDS sont passees, mais ce point doit etre investigue si recurrent.\n\n');
    md.push('```json\n');
    md.push(`${JSON.stringify(fixture.runtimeErrors, null, 2)}\n`);
    md.push('```\n\n');
  }

  if (fixture.kdsIdentity && fixture.kdsIdentity.queue_number_visible === false) {
    md.push('## Point audit visuel KDS\n\n');
    md.push('- La commande borne arrive bien au KDS avec ses deux lignes.\n');
    md.push('- Le `queue_number` backend n est pas visible tel quel dans la capture KDS; l ecran semble privilegier un autre identifiant visuel. A aligner si la file borne doit etre le repere principal cuisine/client.\n\n');
    md.push('```json\n');
    md.push(`${JSON.stringify(fixture.kdsIdentity, null, 2)}\n`);
    md.push('```\n\n');
  }

  md.push('## Donnees commande\n\n');
  md.push('```json\n');
  md.push(`${JSON.stringify({ orderResponse, trace: trace.order, fixture }, null, 2)}\n`);
  md.push('```\n\n');

  md.push('## Lignes cuisine\n\n');
  md.push('| Produit | Quantite | Total | Instruction |\n');
  md.push('|---|---:|---:|---|\n');
  for (const line of trace.items || []) {
    md.push(`| ${line.item_name || line.item_id} | ${line.quantity} | ${line.total} | ${String(line.instruction || '').replace(/\|/g, '\\|')} |\n`);
  }

  md.push('\n## Transitions et stock\n\n');
  md.push('```json\n');
  md.push(`${JSON.stringify({
    transitions: trace.transitions,
    domain_events: trace.domain_events,
    stock_movement: trace.stock_movement,
    queue_count_same_day: trace.queue_count_same_day,
  }, null, 2)}\n`);
  md.push('```\n\n');

  md.push('## Captures\n\n');
  md.push('| Capture | Assertion | URL | Extrait visible |\n');
  md.push('|---|---|---|---|\n');
  for (const shot of notes) {
    md.push(`| [${shot.file}](screenshots/${shot.file}) | ${shot.label} | \`${shot.url}\` | ${shot.text.replace(/\|/g, '\\|')} |\n`);
  }

  fs.mkdirSync(REPORT_ROOT, { recursive: true });
  fs.writeFileSync(REPORT_MD, md.join(''), 'utf8');
  fs.writeFileSync(RAW_JSON, JSON.stringify({ fixture, orderResponse, trace, screenshots: notes }, null, 2), 'utf8');
}

test.describe('Audit borne multi-produits vers KDS', () => {
  test.describe.configure({ timeout: 360_000 });
  let kioskIdentityBefore = null;
  let createdFixture = null;

  test.beforeAll(async ({ browser }, testInfo) => {
    testInfo.setTimeout(120_000);
    const database = verifyDedicatedTestDatabase();
    kioskIdentityBefore = resolveExistingKioskIdentity();
    // eslint-disable-next-line no-console
    console.log(
      `[Kiosk multi-product] dedicated database: ${JSON.stringify(database)}, `
      + `kiosk_machine_id=${kioskIdentityBefore.machine.id}, branch_id=${kioskIdentityBefore.machine.branch_id}`,
    );
    const cleanup = await cleanupSyntheticScope(
      browser,
      Number(kioskIdentityBefore.machine.branch_id),
      null,
      'beforeAll',
    );
    if (cleanup.failures.length > 0) {
      throw new Error(`Kiosk multi-product preflight cleanup failed: ${JSON.stringify(cleanup.failures)}`);
    }
  });

  test.afterAll(async ({ browser }, testInfo) => {
    testInfo.setTimeout(120_000);
    if (!kioskIdentityBefore?.machine?.branch_id) return;
    const cleanup = await cleanupSyntheticScope(
      browser,
      Number(kioskIdentityBefore.machine.branch_id),
      createdFixture,
      'afterAll',
    );
    if (cleanup.failures.length > 0) {
      throw new Error(`Kiosk multi-product cleanup failed: ${JSON.stringify(cleanup.failures)}`);
    }
    const kioskIdentityAfter = resolveExistingKioskIdentity();
    expect(kioskIdentityAfter.machine, 'La configuration persistante de la machine borne doit rester identique')
      .toEqual(kioskIdentityBefore.machine);
    expect(kioskIdentityAfter.user, 'L’utilisateur lié à la borne doit rester identique')
      .toEqual(kioskIdentityBefore.user);
    expect(kioskIdentityAfter.other_branch_cache, 'Aucune clé menu d’une autre branche ne doit changer')
      .toEqual(kioskIdentityBefore.other_branch_cache);
  });

  test('commande borne multi-produits + paiement carte + backend + KDS', async ({ browser }) => {
    fs.rmSync(SHOT_DIR, { recursive: true, force: true });
    fs.mkdirSync(SHOT_DIR, { recursive: true });
    fs.mkdirSync(REPORT_ROOT, { recursive: true });
    clearFoodKingRateLimits();

    const fixture = await test.step('Préflight: provisionner une fixture dans la base E2E dédiée', async () => (
      createKioskFixture(kioskIdentityBefore)
    ));
    createdFixture = fixture;
    expect(fixture.cache_key_invalidated).toBe(`kiosk.menu.branch.${fixture.branch_id}`);
    expect(fixture.cache_present_after_invalidation).toBe(false);
    const [productA, productB] = fixture.products;
    const notes = [];
    const runtimeErrors = [];

    const kioskContext = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const kdsContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const posContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const kioskPage = await kioskContext.newPage();
    const kdsPage = await kdsContext.newPage();
    const posPage = await posContext.newPage();
    kioskPage.setDefaultTimeout(20_000);
    kioskPage.setDefaultNavigationTimeout(30_000);
    kdsPage.setDefaultTimeout(20_000);
    kdsPage.setDefaultNavigationTimeout(30_000);
    posPage.setDefaultTimeout(20_000);
    posPage.setDefaultNavigationTimeout(30_000);
    kioskPage.on('pageerror', (err) => runtimeErrors.push({
      page: 'kiosk',
      message: err.message,
      stack: String(err.stack || '').slice(0, 1200),
    }));
    kdsPage.on('pageerror', (err) => runtimeErrors.push({
      page: 'kds',
      message: err.message,
      stack: String(err.stack || '').slice(0, 1200),
    }));

    let orderPayload;
    let order;
    let kdsIdentity = null;
    let selectedOrderType = orderTypeEnum.TAKEAWAY;
    let paymentMode = 'card';

    try {
      await test.step('Borne: authentification, catalogue et panier multi-produits', async () => {
        await loginAsKiosk(kioskPage);
      await expect(kioskPage.getByTestId('kiosk-idle-root')).toBeVisible({ timeout: 25_000 });
      await addOverlay(kioskPage, ['Borne authentifiee', 'Depart parcours client', 'Panier vide']);
      await snap(kioskPage, '01-kiosk-auth-idle', notes, 'borne connectee sur ecran accueil');

      const dineIn = kioskPage.getByTestId('kiosk-order-type-dine-in');
      if (await dineIn.isVisible({ timeout: 2_000 }).catch(() => false)) {
        selectedOrderType = orderTypeEnum.KIOSK;
        await dineIn.click({ timeout: 10_000, force: true });
      } else {
        // Le CTA d'attract tourne en animation continue : Playwright ne le
        // considère jamais "stable". Le force reproduit le toucher borne sans
        // attendre une stabilité visuelle impossible.
        await kioskPage.getByTestId('kiosk-order-type-takeaway').click({ timeout: 10_000, force: true });
      }
      await expect(kioskPage.getByTestId('kiosk-categories-root')).toBeVisible({ timeout: 25_000 });
      await kioskPage.goto(`/kiosk/categories?cat=${fixture.category_id}`, { waitUntil: 'domcontentloaded' });
      await hideDevelopmentDebugbar(kioskPage);
      await expect(kioskPage.getByTestId(`kiosk-product-card-${productA.item_id}`)).toBeVisible({ timeout: 25_000 });
      await expect(kioskPage.getByTestId(`kiosk-product-card-${productB.item_id}`)).toBeVisible({ timeout: 25_000 });
      await addOverlay(kioskPage, ['Catalogue borne charge', fixture.category_name, 'Deux produits audit visibles']);
      await snap(kioskPage, '02-kiosk-catalogue-produits-visibles', notes, 'catalogue borne avec deux produits visibles');

      await addSimpleProductFromKiosk(kioskPage, productA, notes, 1, 1);
      await addSimpleProductFromKiosk(kioskPage, productB, notes, 2, 2);

      await kioskPage.getByTestId('kiosk-categories-pay').click();
      await expect(kioskPage.getByTestId('kiosk-cart-root')).toBeVisible({ timeout: 20_000 });
      await expect(kioskPage.getByTestId('kiosk-cart-item-name-0')).toContainText(new RegExp(`${escapeRegex(productA.name)}|${escapeRegex(productB.name)}`, 'i'));
      await expect(kioskPage.getByTestId('kiosk-cart-items')).toContainText(new RegExp(escapeRegex(productA.name), 'i'));
      await expect(kioskPage.getByTestId('kiosk-cart-items')).toContainText(new RegExp(escapeRegex(productB.name), 'i'));
      const cartTotal = parseAmount(await kioskPage.getByTestId('kiosk-cart-total').innerText());
      expect(cartTotal).toBeCloseTo(fixture.expected_total, 2);
      await addOverlay(kioskPage, ['Panier borne multi-produits', '2 lignes distinctes', `Total attendu: ${fixture.expected_total}`]);
      await snap(kioskPage, '06-kiosk-panier-multi-produits', notes, 'panier borne contient deux lignes distinctes');

      if (selectedOrderType === orderTypeEnum.KIOSK) {
        await kioskPage.getByTestId('kiosk-cart-order-type-dinein').click({ timeout: 10_000 });
      } else {
        await kioskPage.getByTestId('kiosk-cart-order-type-takeaway').click({ timeout: 10_000 });
      }
      await kioskPage.getByTestId('kiosk-cart-checkout').click();
      await proceedPastUpsellIfNeeded(kioskPage, notes);
      const counterRoute = kioskPage.getByTestId('kiosk-payment-counter-route');
      paymentMode = await counterRoute.isVisible({ timeout: 1_500 }).catch(() => false)
        ? 'counter'
        : 'card';
      const totalLocator = paymentMode === 'counter'
        ? kioskPage.getByTestId('kiosk-payment-counter-total')
        : kioskPage.getByTestId('kiosk-payment-total');
      await expect(totalLocator).toBeVisible({ timeout: 20_000 });
      const paymentTotal = parseAmount(await totalLocator.innerText());
      expect(paymentTotal).toBeCloseTo(fixture.expected_total, 2);
      if (paymentMode === 'card') {
        await kioskPage.getByTestId('kiosk-payment-method-card').click();
        await expect(kioskPage.getByTestId('kiosk-payment-confirm')).toBeEnabled({ timeout: 10_000 });
        await addOverlay(kioskPage, ['Paiement borne', 'Carte sélectionnée', `Total: ${paymentTotal}`]);
      } else {
        await expect(kioskPage.getByTestId('kiosk-payment-counter-confirm')).toBeEnabled({ timeout: 10_000 });
        await addOverlay(kioskPage, ['Paiement borne', 'Règlement différé au comptoir', `Total: ${paymentTotal}`]);
      }
      await snap(
        kioskPage,
        '09-kiosk-mode-paiement-selectionne',
        notes,
        paymentMode === 'counter' ? 'écran paiement au comptoir cohérent' : 'écran paiement carte cohérent',
      );
      });

      await test.step('Paiement: création idempotente et confirmation backend', async () => {
        clearFoodKingRateLimits();
      const orderRespPromise = kioskPage.waitForResponse(
        (res) => res.request().method() === 'POST' && /\/api\/frontend\/order$/.test(res.url()),
        { timeout: 30_000 },
      );
      const confirmRespPromise = paymentMode === 'card'
        ? kioskPage.waitForResponse(
          (res) => res.request().method() === 'POST' && /\/api\/frontend\/order\/\d+\/payment-confirm$/.test(res.url()),
          { timeout: 45_000 },
        )
        : null;
      if (paymentMode === 'card') {
        await kioskPage.getByTestId('kiosk-payment-confirm').click();
        await expect(kioskPage.getByTestId('kiosk-payment-tpe-overlay')).toBeVisible({ timeout: 20_000 });
        await addOverlay(kioskPage, ['TPE navigateur', 'Paiement carte simulé', 'En attente validation backend']);
        await snap(kioskPage, '10-kiosk-tpe-paiement-en-cours', notes, 'overlay TPE visible pendant paiement');
      } else {
        await kioskPage.getByTestId('kiosk-payment-counter-confirm').click();
      }

      const orderResp = await orderRespPromise;
      expect(orderResp.status()).toBeLessThan(400);
      orderPayload = await orderResp.json();
      order = orderPayload.data || orderPayload;
      if (confirmRespPromise) {
        const confirmResp = await confirmRespPromise;
        expect(confirmResp.status()).toBeLessThan(400);
      }
      await kioskPage.waitForFunction(() => (
        document.querySelector('[data-testid="kiosk-waiting-root"]')
        || document.querySelector('[data-testid="kiosk-confirmation-root"]')
        || document.querySelector('[data-testid="kiosk-cash-title"]')
      ), null, { timeout: 30_000 });
      const postPaymentState = paymentMode === 'counter'
        ? 'paiement au comptoir'
        : (await kioskPage.getByTestId('kiosk-confirmation-root').isVisible({ timeout: 1000 }).catch(() => false)
          ? 'confirmation'
          : 'attente');
      await addOverlay(kioskPage, ['Paiement confirme', `Commande #${order.id}`, `File ${order.queue_number}`, `Etat borne: ${postPaymentState}`]);
        await snap(kioskPage, '11-kiosk-apres-paiement-confirme', notes, 'borne affiche confirmation ou attente apres paiement confirme');
      });

      await test.step('KDS: réception et transitions métier via API canonique', async () => {
      if (paymentMode === 'counter') {
        await loginAsAdmin(posPage);
        const collection = await collectCounterOrderThroughPosApi(posPage, order.id);
        expect(collection, `Encaissement POS refusé: ${JSON.stringify(collection)}`).toMatchObject({
          ok: true,
          payment_status: paymentStatusEnum.PAID,
          pos_payment_method: posPaymentMethodEnum.CARD,
        });
      }
      await loginAsChefOperator(kdsPage);
      await kdsPage.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });
      await hideDevelopmentDebugbar(kdsPage);
      const kdsCard = kdsPage.locator(`[data-order-id="${order.id}"]`);
      await expect(kdsCard).toBeVisible({ timeout: 30_000 });
      await expect(kdsCard.locator('.kds-line--symbolic-main')).toHaveCount(2);
      await expect(kdsCard).toContainText(/BUR/i);
      await expect(kdsCard).toContainText(/DES/i);
      const kdsBodyText = await kdsCard.innerText({ timeout: 5000 });
      kdsIdentity = {
        expected_queue_number: order.queue_number || null,
        queue_number_visible: order.queue_number ? kdsBodyText.includes(String(order.queue_number)) : null,
        order_serial_no: order.order_serial_no || order.fiscal_sequence_no || null,
        order_serial_visible: (order.order_serial_no || order.fiscal_sequence_no)
          ? kdsBodyText.includes(String(order.order_serial_no || order.fiscal_sequence_no))
          : null,
        backend_id_visible: kdsBodyText.includes(String(order.id)),
        visual_source_labels: Array.from(new Set(kdsBodyText.match(/Borne|Caisse|POS|Sur place|A emporter|À emporter/g) || [])),
        excerpt: kdsBodyText.replace(/\s+/g, ' ').trim().slice(0, 1000),
      };
      await addOverlay(kdsPage, [
        'KDS: commande borne recue',
        '2 lignes produit visibles',
        `File visible: ${kdsIdentity.queue_number_visible ? 'oui' : 'non'}`,
      ]);
      await snap(kdsPage, '12-kds-commande-borne-recue', notes, 'KDS affiche la commande borne et ses deux produits');

      clearFoodKingRateLimits();
      const statusBeforeChefAction = inspectKioskOrder(order.id, fixture).order.status;
      if (statusBeforeChefAction === orderStatusEnum.ACCEPT) {
        await changeKdsStatus(kdsPage, order.id, orderStatusEnum.ACCEPT, orderStatusEnum.PREPARING);
      } else {
        // PaymentService may auto-promote ACCEPT -> PREPARING at collection;
        // assert that exact canonical state instead of replaying the transition.
        expect(statusBeforeChefAction).toBe(orderStatusEnum.PREPARING);
      }
      await kdsPage.waitForTimeout(1500);
      await expect(kdsCard).toContainText(/EN COURS|Prépar/i, { timeout: 10_000 });
      await addOverlay(kdsPage, ['KDS transition', `Commande #${order.id}`, 'Etat: en preparation']);
      await snap(kdsPage, '13-kds-commande-en-preparation', notes, 'KDS passe la commande en preparation');

      clearFoodKingRateLimits();
      await changeKdsStatus(kdsPage, order.id, orderStatusEnum.PREPARING, orderStatusEnum.PREPARED);
      await kdsPage.waitForTimeout(1500);
      await expect(kdsPage.getByTestId(`kds-served-reopen-${order.id}`)).toBeVisible({ timeout: 10_000 });
      await addOverlay(kdsPage, ['KDS transition finale', `Commande #${order.id}`, 'Etat: pret']);
      await snap(kdsPage, '14-kds-commande-prete', notes, 'KDS passe la commande en pret');

      await kioskPage.waitForTimeout(16_000);
      await addOverlay(kioskPage, ['Retour borne apres KDS pret', `Commande #${order.id}`, 'Controle attente client']);
        await snap(kioskPage, '15-kiosk-apres-commande-prete-kds', notes, 'borne apres passage KDS en pret');
      });

      await test.step('Contrôles: branche, prix backend, transitions, stock et rapport', async () => {
        const audit = inspectKioskOrder(order.id, { ...fixture, kdsIdentity, runtimeErrors });
      expect(audit.order.branch_id).toBe(fixture.branch_id);
      expect(audit.order.source_surface).toBe('kiosk');
      expect(audit.order.order_type).toBe(selectedOrderType);
      expect(audit.order.payment_method).toBe(
        paymentMode === 'counter' ? paymentTypeEnum.CASH_ON_DELIVERY : PAYMENT_CARD,
      );
      expect(audit.order.payment_status).toBe(
        paymentStatusEnum.PAID,
      );
      if (paymentMode === 'counter') {
        expect(audit.order.pos_payment_method).toBe(posPaymentMethodEnum.CARD);
      }
      expect(audit.order.status).toBe(orderStatusEnum.PREPARED);
      expect(audit.order.order_items_count).toBe(2);
      expect(audit.order.total).toBeCloseTo(fixture.expected_total, 2);
      expect(audit.queue_count_same_day).toBe(1);
      expect(new Set(audit.items.map((line) => line.item_id))).toEqual(new Set(fixture.products.map((product) => product.item_id)));
      expect(audit.stock_movement.count).toBeGreaterThanOrEqual(2);
      expect(audit.stock_movement.delta_sum).toBeLessThanOrEqual(-2);
      expect(audit.transitions.map((transition) => `${transition.from}->${transition.to}`)).toEqual(
        expect.arrayContaining([
          `${orderStatusEnum.ACCEPT}->${orderStatusEnum.PREPARING}`,
          `${orderStatusEnum.PREPARING}->${orderStatusEnum.PREPARED}`,
        ]),
      );
      expect(runtimeErrors.filter((err) => /TypeError|ReferenceError|Cannot read|is not a function|is not defined/i.test(err.message || String(err)))).toHaveLength(0);

        writeReport({
          notes,
          trace: audit,
          orderResponse: order,
          fixture: { ...fixture, kdsIdentity, runtimeErrors },
          paymentMode,
        });
      });
    } finally {
      await kioskContext.close().catch(() => {});
      await kdsContext.close().catch(() => {});
      await posContext.close().catch(() => {});
    }
  });
});
