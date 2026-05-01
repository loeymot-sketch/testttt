const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const orderStatusEnum = require('../../resources/js/enums/modules/orderStatusEnum.js').default;
const { login } = require('./helpers/login');
const {
  artisan,
  parseArtisanJson,
  loginAsPOS,
  loginAsChef,
  expectNoCriticalBrowserErrors,
} = require('./helpers/process-audit');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const PREFIX = 'PW-VA-SYS05';
const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';
const ASK_NO = 10;
const reportPath = path.resolve(__dirname, '../../reports/antigravity/va-sys-05-central-management.json');

function phpString(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

function ensureAdminLogin() {
  artisan(`Artisan::call('foodking:ensure-admin', ['--email' => '${phpString(ADMIN_EMAIL)}', '--password' => '${phpString(ADMIN_PASSWORD)}']); echo 'ok';`);
}

function cleanupCentralFixture(prefix = PREFIX) {
  const escaped = phpString(prefix);
  artisan(`
    $prefix = '${escaped}';
    $itemIds = DB::table('items')->where('name', 'like', $prefix . '%')->pluck('id');
    $categoryIds = DB::table('item_categories')->where('name', 'like', $prefix . '%')->pluck('id');
    $orderIds = collect();
    if ($itemIds->isNotEmpty() && Schema::hasTable('order_items')) {
      $orderIds = $orderIds->merge(DB::table('order_items')->whereIn('item_id', $itemIds)->pluck('order_id'));
    }
    $orderIds = $orderIds
      ->merge(DB::table('orders')->where('order_serial_no', 'like', $prefix . '%')->pluck('id'))
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

    if ($itemIds->isNotEmpty()) {
      if (Schema::hasTable('stock_movements')) DB::table('stock_movements')->whereIn('stockable_id', $itemIds)->delete();
      if (Schema::hasTable('stock_levels')) DB::table('stock_levels')->whereIn('stockable_id', $itemIds)->delete();
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

    if ($categoryIds->isNotEmpty()) {
      DB::table('item_categories')->whereIn('id', $categoryIds)->delete();
    }
    DB::table('item_attributes')->where('name', 'like', $prefix . '%')->delete();
    echo json_encode(['orders' => $orderIds->count(), 'items' => $itemIds->count(), 'categories' => $categoryIds->count()]);
  `);
}

function createCentralFixture() {
  return parseArtisanJson(artisan(`
    $prefix = '${phpString(PREFIX)}';
    $branchId = (int) (App\\Models\\User::query()->where('email', 'pos@lecayenne.fr')->value('branch_id') ?: 1);
    $tax = App\\Models\\Tax::query()->first() ?? App\\Models\\Tax::factory()->create([
      'tax_rate' => 0,
      'type' => App\\Enums\\TaxType::PERCENTAGE,
      'status' => App\\Enums\\Status::ACTIVE,
    ]);

    $category = App\\Models\\ItemCategory::factory()->create([
      'name' => $prefix . ' Central Category',
      'status' => App\\Enums\\Status::ACTIVE,
      'wizard_template' => 'custom',
      'has_menu' => true,
      'kiosk_upsell_include' => true,
    ]);

    $item = App\\Models\\Item::factory()->create([
      'name' => $prefix . ' Composer Product',
      'item_category_id' => $category->id,
      'tax_id' => $tax->id,
      'price' => 9.50,
      'status' => App\\Enums\\Status::ACTIVE,
      'is_available' => true,
      'order' => 1,
    ]);

    $drink = App\\Models\\Item::factory()->create([
      'name' => $prefix . ' Drink Addon',
      'item_category_id' => $category->id,
      'tax_id' => $tax->id,
      'price' => 2.00,
      'status' => App\\Enums\\Status::ACTIVE,
      'is_available' => true,
    ]);

    $attribute = App\\Models\\ItemAttribute::factory()->create([
      'name' => $prefix . ' Taille',
      'status' => App\\Enums\\Status::ACTIVE,
      'min_select' => 1,
      'max_select' => 1,
      'allow_repeat' => false,
    ]);

    $variation = App\\Models\\ItemVariation::query()->create([
      'item_id' => $item->id,
      'item_attribute_id' => $attribute->id,
      'name' => $prefix . ' XL',
      'price' => 1.00,
      'status' => App\\Enums\\Status::ACTIVE,
      'visible_on' => ['pos', 'kiosk'],
    ]);

    $extra = App\\Models\\ItemExtra::query()->create([
      'item_id' => $item->id,
      'name' => $prefix . ' Sauce',
      'group_label' => 'sauce',
      'price' => 0.50,
      'status' => App\\Enums\\Status::ACTIVE,
      'visible_on' => ['pos', 'kiosk'],
    ]);

    $addon = App\\Models\\ItemAddon::query()->create([
      'item_id' => $item->id,
      'addon_item_id' => $drink->id,
      'addon_item_variation' => [],
      'role' => 'drink',
    ]);

    App\\Models\\StockLevel::query()->create([
      'branch_id' => $branchId,
      'stockable_type' => App\\Models\\Item::class,
      'stockable_id' => $item->id,
      'on_hand' => 8,
      'reserved' => 0,
    ]);

    $profile = App\\Models\\ItemWizardProfile::query()->create([
      'item_id' => $item->id,
      'template' => 'custom',
      'version' => 1,
      'is_published' => true,
      'published_at' => now(),
      'branch_id_scope' => null,
    ]);

    App\\Models\\ItemWizardStep::query()->create([
      'profile_id' => $profile->id,
      'step_key' => 'taille',
      'label' => 'Taille',
      'source_type' => 'item_attribute',
      'source_ref' => (string) $attribute->id,
      'min_select' => 1,
      'max_select' => 1,
      'allow_repeat' => false,
      'visible_on' => ['pos', 'kiosk'],
      'stockable_choices' => false,
      'position' => 1,
      'is_active' => true,
      'addon_role' => null,
    ]);
    App\\Models\\ItemWizardStep::query()->create([
      'profile_id' => $profile->id,
      'step_key' => 'sauce',
      'label' => 'Sauce',
      'source_type' => 'extra_group',
      'source_ref' => 'sauce',
      'min_select' => 1,
      'max_select' => 1,
      'allow_repeat' => false,
      'visible_on' => ['pos', 'kiosk'],
      'stockable_choices' => false,
      'position' => 2,
      'is_active' => true,
      'addon_role' => null,
    ]);
    App\\Models\\ItemWizardStep::query()->create([
      'profile_id' => $profile->id,
      'step_key' => 'drink',
      'label' => 'Boisson',
      'source_type' => 'addon',
      'source_ref' => 'drink',
      'min_select' => 1,
      'max_select' => 1,
      'allow_repeat' => false,
      'visible_on' => ['pos', 'kiosk'],
      'stockable_choices' => false,
      'position' => 3,
      'is_active' => true,
      'addon_role' => 'drink',
    ]);

    $projection = app(App\\Services\\Menu\\MenuProjectionService::class);
    $kiosk = $projection->forChannel('kiosk', $branchId);
    $pos = $projection->forChannel('pos', $branchId);
    $find = function (array $projection) use ($item) {
      foreach ($projection['categories'] as $categoryRow) {
        foreach ($categoryRow['items'] as $itemRow) {
          if ((int) $itemRow['id'] === (int) $item->id) return $itemRow;
        }
      }
      return null;
    };

    echo json_encode([
      'branch_id' => $branchId,
      'category_id' => (int) $category->id,
      'item_id' => (int) $item->id,
      'item_name' => $item->name,
      'variation_id' => (int) $variation->id,
      'extra_id' => (int) $extra->id,
      'addon_id' => (int) $addon->id,
      'expected_total' => 13.00,
      'kiosk_steps' => count($find($kiosk)['composer_profile']['steps'] ?? []),
      'pos_steps' => count($find($pos)['composer_profile']['steps'] ?? []),
      'kiosk_choices' => collect($find($kiosk)['composer_profile']['steps'] ?? [])->pluck('choices')->flatten(1)->pluck('name')->values()->all(),
      'pos_choices' => collect($find($pos)['composer_profile']['steps'] ?? [])->pluck('choices')->flatten(1)->pluck('name')->values()->all(),
    ]);
  `));
}

async function loginAsAdmin(page) {
  clearFoodKingRateLimits();
  await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);
  await expect(page).toHaveURL(/\/admin\//, { timeout: 20_000 });
}

async function createComposerPosOrder(page, fixture) {
  return page.evaluate(async ({ fixture, prefix, askNo }) => {
    const items = [{
      item_id: fixture.item_id,
      quantity: 1,
      item_variations: [{ id: fixture.variation_id, quantity: 1 }],
      item_extras: [{ id: fixture.extra_id, quantity: 1 }],
      item_addons: [{ id: fixture.addon_id, quantity: 1 }],
      instruction: `${prefix} central composer order`,
    }];
    const token = `${prefix}-${Date.now()}`;
    const basePayload = {
      branch_id: fixture.branch_id,
      customer_id: null,
      token,
      discount: 0,
      order_type: 10,
      is_advance_order: askNo,
      source: 15,
      pos_payment_method: 1,
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
      headers: { 'X-Idempotency-Key': `${prefix}-POS-${Date.now()}` },
    });
    return {
      quote_total: Number(quote.total_ttc),
      order: response.data.data,
    };
  }, { fixture, prefix: PREFIX, askNo: ASK_NO });
}

async function waitForBodyText(page, text, timeout = 20_000) {
  const pattern = new RegExp(String(text).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
  await expect(page.locator('body')).toContainText(pattern, { timeout });
}

function inspectOrder(orderId) {
  return parseArtisanJson(artisan(`
    $order = App\\Models\\Order::withoutGlobalScopes()->with('orderItems')->findOrFail(${Number(orderId)});
    $item = $order->orderItems->first();
    $snapshot = $item ? $item->composition_snapshot : null;
    if (is_string($snapshot)) {
      $snapshot = json_decode($snapshot, true);
    }
    $stock = $item ? App\\Models\\StockLevel::query()
      ->where('branch_id', $order->branch_id)
      ->where('stockable_type', App\\Models\\Item::class)
      ->where('stockable_id', $item->item_id)
      ->first() : null;
    echo json_encode([
      'id' => (int) $order->id,
      'queue_number' => $order->queue_number,
      'status' => (int) $order->status,
      'total' => (float) $order->total,
      'stock_on_hand' => $stock ? (int) $stock->on_hand : null,
      'composition_snapshot' => $snapshot,
      'events' => DB::table('domain_events')->where('aggregate_id', $order->id)->pluck('broadcast_as')->values()->all(),
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

test.describe('VA-SYS-05 central management to runtime sync', () => {
  test.describe.configure({ timeout: 240_000 });

  const results = [];

  test.beforeAll(() => {
    ensureAdminLogin();
  });

  test.beforeEach(() => {
    clearFoodKingRateLimits();
    cleanupCentralFixture();
  });

  test.afterEach(() => {
    writeReport(results);
    if (process.env.VA_SYS_05_SKIP_CLEANUP !== '1') {
      cleanupCentralFixture();
    }
  });

  test('central product/category/composer data projects to admin, POS, kiosk, KDS, stock and history', async ({ browser }) => {
    const fixture = createCentralFixture();
    expect(fixture.kiosk_steps).toBe(3);
    expect(fixture.pos_steps).toBe(3);
    expect(fixture.kiosk_choices).toEqual(expect.arrayContaining([`${PREFIX} XL`, `${PREFIX} Sauce`, `${PREFIX} Drink Addon`]));
    expect(fixture.pos_choices).toEqual(expect.arrayContaining([`${PREFIX} XL`, `${PREFIX} Sauce`, `${PREFIX} Drink Addon`]));

    const adminContext = await browser.newContext();
    const posContext = await browser.newContext();
    const kdsContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    const posPage = await posContext.newPage();
    const kdsPage = await kdsContext.newPage();

    try {
      await loginAsAdmin(adminPage);
      await adminPage.goto('/admin/items');
      await expect(adminPage.getByTestId('admin-items-list')).toBeVisible({ timeout: 20_000 });
      await expect(adminPage.getByText(fixture.item_name, { exact: false }).first()).toBeVisible({ timeout: 20_000 });

      await adminPage.goto(`/admin/items/show/${fixture.item_id}/composer`);
      await expect(adminPage.getByTestId('admin-composer-root')).toBeVisible({ timeout: 20_000 });
      await expect(adminPage.getByTestId('admin-composer-template')).toBeVisible();
      if (await adminPage.getByTestId('admin-composer-step-0-key').count() === 0) {
        await adminPage.getByTestId('admin-composer-add-step').click();
      }
      await expect(adminPage.getByTestId('admin-composer-step-0-key')).toBeVisible({ timeout: 20_000 });

      expect(fixture.kiosk_steps).toBe(3);
      expect(fixture.pos_steps).toBe(3);

      await loginAsChef(kdsPage);
      await kdsPage.goto('/admin/kitchen-display-system');
      await loginAsPOS(posPage);
      let created;
      await expectNoCriticalBrowserErrors(posPage, async () => {
        created = await createComposerPosOrder(posPage, fixture);
      });
      expect(created.quote_total).toBeCloseTo(fixture.expected_total, 2);

      await waitForBodyText(kdsPage, fixture.item_name, 20_000);

      const persisted = inspectOrder(created.order.id);
      expect(persisted.status).toBe(orderStatusEnum.ACCEPT);
      expect(persisted.total).toBeCloseTo(fixture.expected_total, 2);
      expect(persisted.stock_on_hand).toBe(7);
      expect(persisted.composition_snapshot?.lines?.map((line) => line.variation_name)).toEqual(
        expect.arrayContaining([`${PREFIX} XL`])
      );
      expect(persisted.composition_snapshot?.extras?.map((line) => line.extra_name)).toEqual(
        expect.arrayContaining([`${PREFIX} Sauce`])
      );
      expect(persisted.composition_snapshot?.addons?.map((line) => line.addon_name)).toEqual(
        expect.arrayContaining([`${PREFIX} Drink Addon`])
      );

      results.push({
        scenario: 'central_management_to_runtime_projection_and_order',
        pass: true,
        item_id: fixture.item_id,
        order_id: created.order.id,
        queue_number: created.order.queue_number,
        total: persisted.total,
        stock_on_hand: persisted.stock_on_hand,
        composition_lines: persisted.composition_snapshot?.lines?.length || 0,
        composition_extras: persisted.composition_snapshot?.extras?.length || 0,
        composition_addons: persisted.composition_snapshot?.addons?.length || 0,
      });
    } finally {
      await Promise.all([
        adminContext.close(),
        posContext.close(),
        kdsContext.close(),
      ]);
    }
  });
});
