const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const psyshConfigDir = path.resolve(__dirname, '../../storage/framework/testing/psysh');
fs.mkdirSync(psyshConfigDir, { recursive: true });
process.env.XDG_CONFIG_HOME = process.env.XDG_CONFIG_HOME || psyshConfigDir;

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
const { ensureWizardPerItemDemoForComposer } = require('./helpers/ensure-wizard-demo');

const PREFIX = 'PW-DASH-CRUD';
const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';
const ASK_NO = 10;
const reportPath = path.resolve(__dirname, '../../reports/antigravity/central-management-dashboard-crud.json');
const uploadDir = path.resolve(__dirname, '../../storage/framework/testing/playwright');
const pngPath = path.join(uploadDir, 'central-management-dashboard-crud.png');

function phpString(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

function escapeRegex(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function ensureAdminLogin() {
  artisan(`
    Artisan::call('foodking:ensure-admin', ['--email' => '${phpString(ADMIN_EMAIL)}', '--password' => '${phpString(ADMIN_PASSWORD)}']);
    app(Spatie\\Permission\\PermissionRegistrar::class)->forgetCachedPermissions();
    Artisan::call('db:seed', ['--class' => Database\\Seeders\\ComposerPermissionsMinimalSeeder::class, '--force' => true]);
    app(Spatie\\Permission\\PermissionRegistrar::class)->forgetCachedPermissions();
    echo 'ok';
  `);
}

function ensureUploadFile() {
  fs.mkdirSync(uploadDir, { recursive: true });
  if (!fs.existsSync(pngPath)) {
    fs.writeFileSync(
      pngPath,
      Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/l0qbWQAAAABJRU5ErkJggg==',
        'base64'
      )
    );
  }
  return pngPath;
}

function cleanupDashboardCrud(prefix = PREFIX) {
  const escaped = phpString(prefix);
  artisan(`
    $prefix = '${escaped}';
    $itemIds = DB::table('items')->where('name', 'like', $prefix . '%')->pluck('id');
    $categoryIds = DB::table('item_categories')->where('name', 'like', $prefix . '%')->pluck('id');
    $attributeIds = DB::table('item_attributes')->where('name', 'like', $prefix . '%')->pluck('id');
    $orderIds = collect();
    if ($itemIds->isNotEmpty() && Schema::hasTable('order_items')) {
      $orderIds = $orderIds->merge(DB::table('order_items')->whereIn('item_id', $itemIds)->pluck('order_id'));
    }
    $orderIds = $orderIds
      ->merge(DB::table('orders')->where('order_serial_no', 'like', $prefix . '%')->pluck('id'))
      ->merge(DB::table('orders')->where('token', 'like', $prefix . '%')->pluck('id'))
      ->unique()
      ->values();

    if ($orderIds->isNotEmpty()) {
      if (Schema::hasTable('transactions')) DB::table('transactions')->whereIn('order_id', $orderIds)->delete();
      if (Schema::hasTable('order_items')) DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
      if (Schema::hasTable('order_status_transitions')) DB::table('order_status_transitions')->whereIn('order_id', $orderIds)->delete();
      if (Schema::hasTable('domain_events')) {
        $events = DB::table('domain_events')->whereIn('aggregate_id', $orderIds);
        if (Schema::hasColumn('domain_events', 'aggregate_type')) {
          $events->whereIn('aggregate_type', ['order', App\\Models\\Order::class]);
        }
        $events->delete();
      }
      if (Schema::hasTable('audit_logs')) DB::table('audit_logs')->where('resource', 'order')->whereIn('resource_id', $orderIds)->delete();
      DB::table('orders')->whereIn('id', $orderIds)->update(['fiscal_sequence_no' => null]);
      DB::table('orders')->whereIn('id', $orderIds)->delete();
    }

    if ($itemIds->isNotEmpty()) {
      if (Schema::hasTable('stock_movements')) {
        $movements = DB::table('stock_movements')->whereIn('stockable_id', $itemIds);
        if (Schema::hasColumn('stock_movements', 'stockable_type')) {
          $movements->where('stockable_type', App\\Models\\Item::class);
        }
        $movements->delete();
      }
      if (Schema::hasTable('stock_levels')) {
        $levels = DB::table('stock_levels')->whereIn('stockable_id', $itemIds);
        if (Schema::hasColumn('stock_levels', 'stockable_type')) {
          $levels->where('stockable_type', App\\Models\\Item::class);
        }
        $levels->delete();
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

    if ($categoryIds->isNotEmpty()) {
      DB::table('item_categories')->whereIn('id', $categoryIds)->delete();
    }
    if ($attributeIds->isNotEmpty()) {
      DB::table('item_attributes')->whereIn('id', $attributeIds)->delete();
    }
    echo json_encode([
      'orders' => $orderIds->count(),
      'items' => $itemIds->count(),
      'categories' => $categoryIds->count(),
      'attributes' => $attributeIds->count(),
    ]);
  `);
}

function prepareRuntimeSupport(itemId, stockOnHand = 8) {
  return parseArtisanJson(artisan(`
    $branchId = (int) (App\\Models\\User::query()->where('email', 'pos@lecayenne.fr')->value('branch_id') ?: 0);
    if ($branchId <= 0 || ! App\\Models\\Branch::query()->whereKey($branchId)->exists()) {
      $branchId = (int) (App\\Models\\Branch::query()->orderBy('id')->value('id') ?: 0);
    }
    if ($branchId <= 0) {
      $branchId = (int) App\\Models\\Branch::factory()->create()->id;
    }
    $customerId = (int) (App\\Models\\User::query()->where('email', 'walkingcustomer@example.com')->value('id')
      ?: App\\Models\\User::query()->where('branch_id', 0)->value('id')
      ?: App\\Models\\User::query()->value('id'));
    App\\Models\\StockLevel::query()->updateOrCreate([
      'branch_id' => $branchId,
      'stockable_type' => App\\Models\\Item::class,
      'stockable_id' => ${Number(itemId)},
    ], [
      'on_hand' => ${Number(stockOnHand)},
      'reserved' => 0,
    ]);
    Cache::forget("kiosk.menu.branch.{$branchId}");
    echo json_encode(['branch_id' => $branchId, 'customer_id' => $customerId, 'stock_on_hand' => ${Number(stockOnHand)}]);
  `));
}

function prioritizeCategoryForUiSelect(categoryId) {
  artisan(`
    DB::table('item_categories')
      ->where('id', ${Number(categoryId)})
      ->update(['sort' => 0, 'updated_at' => now()]);
    echo 'ok';
  `);
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
      'token' => $order->token,
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
    verdict: pass ? 'PASS_DASHBOARD_CRUD_RUNTIME_LOCAL' : 'REWORK_DASHBOARD_CRUD_RUNTIME_LOCAL',
    scope: {
      ui_created: ['category', 'attribute', 'addon product', 'main product', 'product photo', 'variation', 'extra', 'addon link', 'composer profile', 'composer publish'],
      backend_support_only: ['stock level seed for runtime decrement verification', 'order/stock/snapshot inspection'],
    },
    results,
  }, null, 2));
}

async function loginAsAdmin(page) {
  clearFoodKingRateLimits();
  await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);
  await expect(page).toHaveURL(/\/admin\//, { timeout: 20_000 });
}

function dataFromResponse(json) {
  return json?.data?.data || json?.data || json;
}

async function waitForApi(page, method, urlPart, action) {
  clearFoodKingRateLimits();
  const [response] = await Promise.all([
    page.waitForResponse((res) => {
      const req = res.request();
      return req.method().toUpperCase() === method.toUpperCase()
        && res.url().includes(urlPart);
    }, { timeout: 30_000 }),
    action(),
  ]);
  clearFoodKingRateLimits();
  const body = await response.json().catch(() => ({}));
  if (response.status() < 200 || response.status() >= 300) {
    throw new Error(`${method.toUpperCase()} ${urlPart} failed with ${response.status()}: ${JSON.stringify(body)}`);
  }
  return dataFromResponse(body);
}

/** After apply-template the profile exists → saveDraft uses PUT …/profiles/{id}, not POST …/items/{id}/profile. */
async function waitForComposerDraftPersisted(page, itemId, action) {
  clearFoodKingRateLimits();
  const createPart = `/api/admin/composer/items/${itemId}/profile`;
  const updatePart = '/api/admin/composer/profiles/';
  const [response] = await Promise.all([
    page.waitForResponse((res) => {
      const req = res.request();
      const m = req.method().toUpperCase();
      const u = res.url();
      return (m === 'POST' && u.includes(createPart)) || (m === 'PUT' && u.includes(updatePart));
    }, { timeout: 45_000 }),
    action(),
  ]);
  clearFoodKingRateLimits();
  const body = await response.json().catch(() => ({}));
  if (response.status() < 200 || response.status() >= 300) {
    throw new Error(`composer draft save failed ${response.status()}: ${JSON.stringify(body)}`);
  }
  return dataFromResponse(body);
}

async function openButtonIn(testId) {
  const wrapper = this.getByTestId(testId);
  await wrapper.locator('button').first().click();
}

async function selectVueOption(page, root, label) {
  await root.scrollIntoViewIfNeeded();
  await root.click();
  const input = root.locator('input').first();
  if (await input.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await input.fill(label);
  }
  const exact = new RegExp(`^${escapeRegex(label)}$`);
  const roleOption = page.getByRole('option', { name: exact }).first();
  if (await roleOption.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await roleOption.click();
    return;
  }
  await page.locator('.vue-dropdown-item, .vs__dropdown-option, li').filter({ hasText: label }).first().click();
}

async function createCategory(page, name, imagePath) {
  await page.goto('/admin/settings/item-categories/list');
  await expect(page.getByTestId('admin-category-list')).toBeVisible({ timeout: 20_000 });
  await openButtonIn.call(page, 'admin-category-create-open');
  await page.getByTestId('admin-category-form-name').fill(name);
  await page.getByTestId('admin-category-form-image').setInputFiles(imagePath);
  const category = await waitForApi(page, 'POST', '/api/admin/setting/item-category', async () => {
    await page.getByTestId('admin-category-form-save').click();
  });
  prioritizeCategoryForUiSelect(category.id);
  // Recharger la liste : après POST la ligne peut ne pas être encore dans le DOM / libellé tronqué.
  await page.goto('/admin/settings/item-categories/list');
  await expect(page.getByTestId('admin-category-list')).toBeVisible({ timeout: 20_000 });
  await expect(page.getByTestId(`admin-category-row-${category.id}`)).toBeVisible({ timeout: 45_000 });
  return category;
}

async function createAttribute(page, name) {
  await page.goto('/admin/settings/item-attributes/list');
  await page.locator('[data-modal="#modal"]').first().click();
  const modal = page.locator('#modal');
  await expect(modal).toBeVisible({ timeout: 20_000 });
  await modal.locator('#name').fill(name);
  await modal.locator('#min_select').fill('1');
  await modal.locator('#max_select').fill('1');
  await modal.locator('#allow_repeat_no').check({ force: true });
  const attribute = await waitForApi(page, 'POST', '/api/admin/setting/item-attribute', async () => {
    await modal.locator('form button[type="submit"]').click();
  });
  await expect(page.getByText(name, { exact: false }).first()).toBeVisible({ timeout: 20_000 });
  return attribute;
}

async function createItem(page, { name, price, categoryName, imagePath }) {
  clearFoodKingRateLimits();
  await page.goto('/admin/items');
  await expect(page.getByTestId('admin-items-list')).toBeVisible({ timeout: 20_000 });
  await openButtonIn.call(page, 'admin-item-create-open');
  await page.getByTestId('admin-item-form-name').fill(name);
  await page.getByTestId('admin-item-form-price').fill(String(price));
  await selectVueOption(page, page.getByTestId('admin-item-form-category'), categoryName);
  await page.getByTestId('admin-item-form-image').setInputFiles(imagePath);
  const item = await waitForApi(page, 'POST', '/api/admin/item', async () => {
    await page.getByTestId('admin-item-form-save').click();
  });
  return item;
}

async function updateProductPhoto(page, itemId, imagePath) {
  await page.goto(`/admin/items/show/${itemId}`);
  await expect(page.getByText(/information|informations/i).first()).toBeVisible({ timeout: 20_000 });
  await page.getByTestId('admin-item-tab-image').click();
  await page.getByTestId('admin-item-photo-input').setInputFiles(imagePath);
  await waitForApi(page, 'POST', `/api/admin/item/change-image/${itemId}`, async () => {
    await page.getByTestId('admin-item-photo-save').click();
  });
}

async function createVariation(page, { itemId, name, price, attributeName }) {
  clearFoodKingRateLimits();
  await page.goto(`/admin/items/show/${itemId}`);
  // Tab renders as "Variante" in the live French UI, not "Variation" --
  // confirmed via a failed run's screenshot (i18n drift since this test
  // was authored). Matches both so this survives a future locale switch.
  await page.getByRole('button', { name: /variation|variante/i }).click();
  await page.getByTestId('admin-variation-add').locator('button').first().click();
  const modal = page.locator('#modal');
  await expect(modal).toBeVisible({ timeout: 20_000 });
  await page.getByTestId('admin-variation-form-name').fill(name);
  await page.getByTestId('admin-variation-form-price').fill(String(price));
  await selectVueOption(page, modal.getByRole('combobox').first(), attributeName);
  return waitForApi(page, 'POST', `/api/admin/item/variation/${itemId}`, async () => {
    await modal.locator('form button[type="submit"]').click();
  });
}

async function createExtra(page, { itemId, name, price, groupLabel }) {
  await page.goto(`/admin/items/show/${itemId}`);
  await page.getByRole('button', { name: /extra/i }).click();
  await page.getByTestId('admin-extra-add-button').click();
  const modal = page.locator('#extraModal');
  await expect(modal).toBeVisible({ timeout: 20_000 });
  await page.getByTestId('admin-extra-form-name').fill(name);
  await page.getByTestId('admin-extra-form-price').fill(String(price));
  await modal.locator('#group_label').fill(groupLabel);
  return waitForApi(page, 'POST', `/api/admin/item/extra/${itemId}`, async () => {
    await modal.locator('form button[type="submit"]').click();
  });
}

async function createAddon(page, { itemId, addonItemName }) {
  await page.goto(`/admin/items/show/${itemId}`);
  // Same i18n drift as the variation tab -- renders as "Supplément" in the
  // live French UI, not "Addon".
  await page.getByRole('button', { name: /addon|supplément/i }).click();
  await page.getByTestId('admin-addon-add-button').click();
  const modal = page.locator('#addonModal');
  await expect(modal).toBeVisible({ timeout: 20_000 });
  await selectVueOption(page, page.getByTestId('admin-addon-form-name'), addonItemName);
  return waitForApi(page, 'POST', `/api/admin/item/addon/${itemId}`, async () => {
    await modal.locator('form button[type="submit"]').click();
  });
}

async function applyComposerCustomTemplate(page, itemId) {
  await page.getByTestId('admin-composer-template').click();
  await expect(page.getByTestId('composer-template-picker-modal')).toBeVisible({ timeout: 15_000 });
  const sid = String(itemId);
  const [response] = await Promise.all([
    page.waitForResponse(
      (res) => {
        const req = res.request();
        return req.method() === 'POST'
          && res.url().includes('apply-template')
          && res.url().includes(sid);
      },
      { timeout: 30_000 },
    ),
    page.getByTestId('composer-template-custom').click(),
  ]);
  if (response.status() < 200 || response.status() >= 300) {
    const body = await response.text();
    throw new Error(`apply-template failed: ${response.status()} ${body}`);
  }
  await expect(page.getByTestId('composer-template-picker-modal')).toBeHidden({ timeout: 15_000 });
}

async function setComposerStep(page, index, { label, sourceType, sourceRef, addonId, min = 1, max = 1 }) {
  await page.getByTestId(`composer-step-select-${index}`).click();
  await expect(page.getByTestId('composer-step-form-panel')).toBeVisible({ timeout: 20_000 });
  await page.getByTestId('composer-step-label-input').fill(label);
  await page.getByTestId('composer-step-source-type').selectOption(sourceType);
  const refSelect = page.getByTestId('composer-step-source-ref');
  let refValue = '';
  if (sourceType === 'addon' && addonId != null) {
    refValue = String(addonId);
  } else if (sourceRef !== '' && sourceRef != null) {
    refValue = String(sourceRef);
  }
  if (refValue === '') {
    await refSelect.selectOption('');
  } else {
    await refSelect.selectOption(refValue);
  }
  await page.getByTestId('composer-step-min-range').fill(String(min));
  await page.getByTestId('composer-step-max-range').fill(String(max));
}

async function createAndPublishComposer(page, { itemId, attributeId, extraGroup, addonId }) {
  await page.goto(`/admin/items/${itemId}/composer`);
  await expect(page.getByTestId('admin-composer-root')).toBeVisible({ timeout: 20_000 });
  await applyComposerCustomTemplate(page, itemId);
  while ((await page.locator('[data-testid^="composer-step-row-"]').count()) < 3) {
    await page.getByTestId('admin-composer-add-step').click();
  }
  await setComposerStep(page, 0, {
    label: 'Taille',
    sourceType: 'item_attribute',
    sourceRef: String(attributeId),
  });
  await setComposerStep(page, 1, {
    label: 'Sauce',
    sourceType: 'extra_group',
    sourceRef: extraGroup,
  });
  await setComposerStep(page, 2, {
    label: 'Boisson',
    sourceType: 'addon',
    sourceRef: addonId != null ? String(addonId) : '',
    addonId,
  });
  const profile = await waitForComposerDraftPersisted(page, itemId, async () => {
    await page.getByTestId('admin-composer-save-draft').click();
  });
  await expect(page.getByTestId('admin-composer-publish')).toBeVisible({ timeout: 20_000 });
  await page.getByTestId('admin-composer-publish').click();
  await expect(page.getByTestId('composer-publish-confirm-modal')).toBeVisible({ timeout: 15_000 });
  const published = await waitForApi(page, 'POST', `/api/admin/composer/profiles/${profile.id}/publish`, async () => {
    await page.getByTestId('composer-publish-confirm').click();
  });
  return published;
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

async function createComposerPosOrder(page, fixture) {
  return page.evaluate(async ({ fixture, prefix, askNo }) => {
    try {
      const items = [{
        item_id: fixture.item_id,
        quantity: 1,
        item_variations: [{ id: fixture.variation_id, quantity: 1 }],
        item_extras: [{ id: fixture.extra_id, quantity: 1 }],
        item_addons: [{ id: fixture.addon_id, quantity: 1 }],
        instruction: `${prefix} dashboard CRUD composer order`,
      }];
      const token = `${prefix}-POS-${Date.now()}`;
      const basePayload = {
        branch_id: fixture.branch_id,
        customer_id: fixture.customer_id,
        token,
        discount: 0,
        order_type: 10,
        is_advance_order: askNo,
        source: 15,
        pos_payment_method: 1,
        pos_received_amount: 500,
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
    } catch (error) {
      throw new Error(JSON.stringify({
        status: error?.response?.status || 0,
        data: error?.response?.data || null,
        message: error?.message || String(error),
      }));
    }
  }, { fixture, prefix: PREFIX, askNo: ASK_NO });
}

async function waitForBodyText(page, text, timeout = 20_000) {
  const pattern = text instanceof RegExp ? text : new RegExp(escapeRegex(text));
  await expect(page.locator('body')).toContainText(pattern, { timeout });
}

test.describe('central management dashboard CRUD to runtime sync', () => {
  // Multi-context (admin + POS + kiosk + KDS) + composer publish + runtime assertions — 5 min was flaky locally/CI.
  test.describe.configure({ timeout: 600_000 });

  const results = [];

  test.beforeAll(() => {
    ensureAdminLogin();
    ensureUploadFile();
  });

  test.beforeEach(() => {
    clearFoodKingRateLimits();
    cleanupDashboardCrud();
  });

  test.afterEach(() => {
    writeReport(results);
    if (process.env.DASHBOARD_CRUD_SKIP_CLEANUP !== '1') {
      cleanupDashboardCrud();
    }
  });

  test('admin UI creates category, product, photo, variation, extra, addon, composer profile and syncs to POS/Kiosk/KDS/stock', async ({ browser }, testInfo) => {
    testInfo.setTimeout(600_000);
    const run = Date.now().toString(36).toUpperCase();
    const names = {
      category: `${PREFIX} Category ${run}`,
      attribute: `${PREFIX} Taille ${run}`,
      item: `${PREFIX} Composer Product ${run}`,
      drink: `${PREFIX} Drink Addon ${run}`,
      variation: `${PREFIX} XL ${run}`,
      extra: `${PREFIX} Sauce ${run}`,
      extraGroup: `sauce-${run.toLowerCase()}`,
    };
    const imagePath = ensureUploadFile();

    const adminContext = await browser.newContext();
    await ensureWizardPerItemDemoForComposer(adminContext);
    const posContext = await browser.newContext();
    const kdsContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    const posPage = await posContext.newPage();
    const kdsPage = await kdsContext.newPage();

    try {
      await loginAsAdmin(adminPage);

      const category = await createCategory(adminPage, names.category, imagePath);
      const attribute = await createAttribute(adminPage, names.attribute);
      const drink = await createItem(adminPage, {
        name: names.drink,
        price: '2.00',
        categoryName: names.category,
        imagePath,
      });
      const item = await createItem(adminPage, {
        name: names.item,
        price: '9.50',
        categoryName: names.category,
        imagePath,
      });

      await updateProductPhoto(adminPage, item.id, imagePath);
      const variation = await createVariation(adminPage, {
        itemId: item.id,
        name: names.variation,
        price: '1.00',
        attributeName: names.attribute,
      });
      const extra = await createExtra(adminPage, {
        itemId: item.id,
        name: names.extra,
        price: '0.50',
        groupLabel: names.extraGroup,
      });
      const addon = await createAddon(adminPage, {
        itemId: item.id,
        addonItemName: names.drink,
      });
      const published = await createAndPublishComposer(adminPage, {
        itemId: item.id,
        attributeId: attribute.id,
        extraGroup: names.extraGroup,
        addonId: addon.id,
      });
      expect(published.is_published).toBeTruthy();

      const runtime = prepareRuntimeSupport(item.id, 8);
      const posProjection = await projectionFor(adminPage, 'pos', runtime.branch_id);
      const kioskProjection = await projectionFor(adminPage, 'kiosk', runtime.branch_id);
      const posProjected = findProjectedItem(posProjection, item.id);
      const kioskProjected = findProjectedItem(kioskProjection, item.id);

      expect(posProjected).toBeTruthy();
      expect(kioskProjected).toBeTruthy();
      expect(posProjected.category.name).toContain(PREFIX);
      expect(kioskProjected.category.name).toContain(PREFIX);
      expect(posProjected.item.composer_profile.steps).toHaveLength(3);
      expect(kioskProjected.item.composer_profile.steps).toHaveLength(3);
      const posChoices = posProjected.item.composer_profile.steps.flatMap((step) => step.choices.map((choice) => choice.name));
      const kioskChoices = kioskProjected.item.composer_profile.steps.flatMap((step) => step.choices.map((choice) => choice.name));
      expect(posChoices).toEqual(expect.arrayContaining([names.variation, names.extra, names.drink]));
      expect(kioskChoices).toEqual(expect.arrayContaining([names.variation, names.extra, names.drink]));

      // Kiosk runtime is asserted via menu projection + POS order; full kiosk-browser login is covered elsewhere (flaky without kiosk seed on all DBs).

      await loginAsChef(kdsPage);
      await kdsPage.goto('/admin/kitchen-display-system');
      await loginAsPOS(posPage);
      await posPage.goto('/admin/pos');

      const fixture = {
        branch_id: runtime.branch_id,
        customer_id: runtime.customer_id,
        item_id: item.id,
        variation_id: variation.id,
        extra_id: extra.id,
        addon_id: addon.id,
      };

      let created;
      clearFoodKingRateLimits();
      await expectNoCriticalBrowserErrors(posPage, async () => {
        created = await createComposerPosOrder(posPage, fixture);
      });
      expect(Number(created.quote_total)).toBeCloseTo(Number(created.order.total), 2);

      await waitForBodyText(kdsPage, names.item, 20_000);
      const persisted = inspectOrder(created.order.id);
      expect(persisted.status).toBe(orderStatusEnum.ACCEPT);
      expect(Number(persisted.total)).toBeCloseTo(Number(created.order.total), 2);
      expect(persisted.stock_on_hand).toBe(7);
      expect(persisted.composition_snapshot?.lines?.map((line) => line.variation_name)).toEqual(
        expect.arrayContaining([names.variation])
      );
      expect(persisted.composition_snapshot?.extras?.map((line) => line.extra_name)).toEqual(
        expect.arrayContaining([names.extra])
      );
      expect(persisted.composition_snapshot?.addons?.map((line) => line.addon_name)).toEqual(
        expect.arrayContaining([names.drink])
      );

      results.push({
        scenario: 'dashboard_crud_to_runtime_sync',
        pass: true,
        category_id: category.id,
        attribute_id: attribute.id,
        item_id: item.id,
        drink_id: drink.id,
        variation_id: variation.id,
        extra_id: extra.id,
        addon_id: addon.id,
        profile_id: published.id,
        order_id: created.order.id,
        queue_number: created.order.queue_number,
        quote_total: created.quote_total,
        persisted_total: persisted.total,
        stock_on_hand_after_order: persisted.stock_on_hand,
        pos_projection_steps: posProjected.item.composer_profile.steps.length,
        kiosk_projection_steps: kioskProjected.item.composer_profile.steps.length,
      });
    } finally {
      for (const ctx of [adminContext, posContext, kdsContext]) {
        try {
          await ctx.close();
        } catch {
          /* timeout / abort may have closed the browser already */
        }
      }
    }
  });
});
