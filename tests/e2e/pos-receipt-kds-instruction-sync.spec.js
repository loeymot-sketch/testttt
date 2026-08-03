/**
 * E2E — Vérifie que l’instruction article POS (données « ticket cuisine » côté contenu)
 * est bien persistée, exposée par l’API détail commande, et affichée sur le KDS
 * sans dépendre de l’impression papier (OrderCreated / données persistées).
 *
 * Prérequis : API Laravel sur PLAYWRIGHT_BASE_URL (ex. php artisan serve).
 *
 * @see reports/audit/POS_RECEIPT_KITCHEN_KDS_BACKEND_SYNC_2026-05-01.md
 */

const { test, expect } = require('@playwright/test');
const {
  artisan,
  parseArtisanJson,
  loginAsPOS,
  loginAsChef,
  expectNoCriticalBrowserErrors,
  POS_EMAIL,
} = require('./helpers/process-audit');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const PREFIX = 'PW-RKS';
const ASK_NO = 10;

function cleanupPrefix(prefix = PREFIX) {
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
      DB::table('orders')->whereIn('id', $orderIds)->update(['fiscal_sequence_no' => null]);
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
    echo json_encode(['ok' => true]);
  `);
}

function createRuntimeItem(label, stockOnHand = 20) {
  const escapedLabel = String(label).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  const posEmailEscaped = String(POS_EMAIL).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  return parseArtisanJson(artisan(`
    $posEmail = '${posEmailEscaped}';
    $branchId = (int) (App\\Models\\User::query()->where('email', $posEmail)->value('branch_id') ?: 0);
    if ($branchId <= 0 || ! App\\Models\\Branch::query()->whereKey($branchId)->exists()) {
      $branchId = (int) (App\\Models\\Branch::query()->orderBy('id')->value('id') ?: 0);
    }
    if ($branchId <= 0) {
      $branchId = (int) App\\Models\\Branch::factory()->create()->id;
    }
    $posUser = App\\Models\\User::query()->where('email', $posEmail)->first();
    if ($posUser) {
      $posUser->forceFill(['branch_id' => $branchId])->save();
    }
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
    App\\Models\\StockLevel::query()->create([
      'branch_id' => $branchId,
      'stockable_type' => App\\Models\\Item::class,
      'stockable_id' => $item->id,
      'on_hand' => ${Number(stockOnHand)},
      'reserved' => 0,
    ]);
    if (Schema::hasTable('item_wizard_profiles')) {
      DB::table('item_wizard_profiles')->where('item_id', $item->id)->delete();
    }
    $customerId = (int) (App\\Models\\User::query()->where('email', 'walkingcustomer@example.com')->value('id')
      ?: App\\Models\\User::query()->where('branch_id', 0)->value('id')
      ?: App\\Models\\User::query()->value('id'));
    echo json_encode([
      'branch_id' => $branchId,
      'customer_id' => $customerId,
      'item_id' => (int) $item->id,
      'item_name' => $item->name,
      'price' => (float) $item->price,
    ]);
  `));
}

async function createPosOrderViaApi(page, fixture, label) {
  return page.evaluate(async ({ fixture, label, prefix, askNo }) => {
    try {
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
        /** OrderType::POS (15) — comptoir ; 10=TAKEAWAY peut faire échouer le devis selon règles livraison. */
        order_type: 15,
        is_advance_order: askNo,
        source: 15,
        pos_payment_method: 1,
        pos_received_amount: 20,
        items: JSON.stringify(items),
      };
      const quoteResp = await window.axios.post('admin/pos/quote', basePayload);
      if (!quoteResp.data?.status) {
        return {
          ok: false,
          step: 'quote',
          status: quoteResp.status,
          body: quoteResp.data,
        };
      }
      const quote = quoteResp.data.data;
      const totalTtc = Number(quote.total_ttc);
      const receivedAmount = Number.isFinite(totalTtc) && totalTtc > 0 ? Math.ceil(totalTtc + 5) : 50;
      const response = await window.axios.post('admin/pos', {
        ...basePayload,
        pos_received_amount: receivedAmount,
        quote_token: quote.quote_token,
        quote_signature: quote.signature,
        subtotal: quote.subtotal,
        discount: quote.discount,
        delivery_charge: quote.delivery_charge,
        total: quote.total_ttc,
      }, {
        headers: { 'X-Idempotency-Key': `${prefix}-POS-${label}-${Date.now()}` },
      });
      const orderPayload = response.data?.data;
      if (!orderPayload?.id) {
        return { ok: false, step: 'pos_store', status: response.status, body: response.data };
      }

      return { ok: true, data: orderPayload };
    } catch (e) {
      const status = e?.response?.status;
      const body = e?.response?.data;

      return {
        ok: false,
        step: 'axios',
        status,
        body: body ?? String(e?.message || e),
      };
    }
  }, { fixture, label, prefix: PREFIX, askNo: ASK_NO });
}

async function waitForBodyText(page, pattern, timeout = 15_000) {
  await expect(page.locator('body')).toContainText(pattern, { timeout });
}

test.describe('POS instruction ↔ KDS ↔ API (kitchen ticket data path)', () => {
  test.describe.configure({ timeout: 120_000 });

  test.beforeEach(() => {
    cleanupPrefix(PREFIX);
  });

  test.afterEach(() => {
    if (process.env.PW_RKS_SKIP_CLEANUP !== '1') {
      cleanupPrefix(PREFIX);
    }
  });

  test('persisted instruction appears on KDS and matches pos-order show API', async ({ browser }) => {
    clearFoodKingRateLimits();
    const fixture = createRuntimeItem('INST', 10);
    const kdsContext = await browser.newContext();
    const posContext = await browser.newContext();
    const kdsPage = await kdsContext.newPage();
    const posPage = await posContext.newPage();

    try {
      await loginAsChef(kdsPage);
      await kdsPage.goto('/admin/kitchen-display-system');

      await loginAsPOS(posPage);
      await posPage.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
      await posPage.locator('#pos-cart').waitFor({ state: 'visible', timeout: 25_000 });

      let orderResult;
      await expectNoCriticalBrowserErrors(posPage, async () => {
        clearFoodKingRateLimits();
        await posPage.waitForTimeout(600);
        orderResult = await createPosOrderViaApi(posPage, fixture, 'INST');
      });

      expect(orderResult?.ok, JSON.stringify(orderResult)).toBe(true);
      const order = orderResult.data;
      expect(order?.id).toBeTruthy();
      const marker = `${PREFIX} INST`;

      const apiInstruction = await posPage.evaluate(async (orderId) => {
        const r = await window.axios.get(`admin/pos-order/show/${orderId}`);
        const items = r.data?.data?.order_items || [];
        return items[0]?.instruction || '';
      }, order.id);

      expect(apiInstruction).toContain(marker);

      await kdsPage.bringToFront();
      await waitForBodyText(kdsPage, marker, 15_000);

      const instructionCells = kdsPage.locator('.kds-instruction').filter({ hasText: marker });
      await expect(instructionCells.first()).toBeVisible({ timeout: 10_000 });
      expect(await instructionCells.count()).toBeGreaterThanOrEqual(1);
    } finally {
      await Promise.all([kdsContext.close(), posContext.close()]);
    }
  });
});
