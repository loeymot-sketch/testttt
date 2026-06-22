const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const { loginAsKiosk, loginAsChefOperator } = require('./helpers/login');
const orderStatusEnum = require('../../resources/js/enums/modules/orderStatusEnum.js').default;
const paymentStatusEnum = require('../../resources/js/enums/modules/paymentStatusEnum.js').default;
const orderTypeEnum = require('../../resources/js/enums/modules/orderTypeEnum.js').default;

const REPORT_ROOT = path.join(process.cwd(), 'reports/audit/kiosk-multiproduct-kds-journey-2026-05-05');
const SHOT_DIR = path.join(REPORT_ROOT, 'screenshots');
const REPORT_MD = path.join(REPORT_ROOT, 'RAPPORT_AUDIT_BORNE_MULTI_PRODUITS_KDS.md');
const RAW_JSON = path.join(REPORT_ROOT, 'raw-kiosk-multiproduct-trace.json');
const PREFIX = 'AUDIT-KIOSK-MULTI';
const PAYMENT_CARD = 4;

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

function cleanupPrefix(prefix = PREFIX) {
  artisan(`
    $prefix = '${phpString(prefix)}';
    $itemIds = DB::table('items')->where('name', 'like', $prefix . '%')->pluck('id');
    $categoryIds = DB::table('item_categories')->where('name', 'like', $prefix . '%')->pluck('id');
    $taxIds = DB::table('taxes')->where('name', 'like', $prefix . '%')->pluck('id');
    $orderIds = collect();
    if ($itemIds->isNotEmpty() && Schema::hasTable('order_items')) {
      $orderIds = $orderIds->merge(DB::table('order_items')->whereIn('item_id', $itemIds)->pluck('order_id'));
    }
    if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'instruction')) {
      $orderIds = $orderIds->merge(DB::table('order_items')->where('instruction', 'like', $prefix . '%')->pluck('order_id'));
    }
    $orderIds = $orderIds
      ->merge(DB::table('orders')->where('token', 'like', $prefix . '%')->pluck('id'))
      ->unique()
      ->values();

    if ($orderIds->isNotEmpty()) {
      if (Schema::hasTable('transactions')) DB::table('transactions')->whereIn('order_id', $orderIds)->delete();
      if (Schema::hasTable('order_status_transitions')) DB::table('order_status_transitions')->whereIn('order_id', $orderIds)->delete();
      if (Schema::hasTable('stock_movements')) DB::table('stock_movements')->whereIn('reference_id', $orderIds)->delete();
      if (Schema::hasTable('domain_events')) DB::table('domain_events')->whereIn('aggregate_id', $orderIds)->delete();
      if (Schema::hasTable('audit_logs')) DB::table('audit_logs')->where('resource', 'order')->whereIn('resource_id', $orderIds)->delete();
      if (Schema::hasTable('order_items')) DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
      DB::table('orders')->whereIn('id', $orderIds)->delete();
    }

    if ($itemIds->isNotEmpty()) {
      if (Schema::hasTable('item_branch_availability')) DB::table('item_branch_availability')->whereIn('item_id', $itemIds)->delete();
      if (Schema::hasTable('stock_levels')) DB::table('stock_levels')->where('stockable_type', App\\Models\\Item::class)->whereIn('stockable_id', $itemIds)->delete();
      DB::table('items')->whereIn('id', $itemIds)->delete();
    }
    if ($categoryIds->isNotEmpty()) DB::table('item_categories')->whereIn('id', $categoryIds)->delete();
    if ($taxIds->isNotEmpty()) DB::table('taxes')->whereIn('id', $taxIds)->delete();
    Cache::flush();
    echo json_encode(['ok' => true, 'orders' => $orderIds->count(), 'items' => $itemIds->count()]);
  `);
}

function createKioskFixture() {
  return parseLastJsonLine(artisan(`
    use Illuminate\\Support\\Facades\\Schema;
    use Illuminate\\Support\\Facades\\Cache;
    use Illuminate\\Support\\Str;
    use Illuminate\\Support\\Facades\\Hash;
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
    $machineUser = User::query()->where('username', 'kiosk-lecayenne')->first()
      ?: User::query()->where('email', 'kiosk@lecayenne.fr')->first()
      ?: User::query()->where('branch_id', '>', 0)->firstOrFail();
    $branchId = (int) ($machineUser->branch_id ?: (User::query()->where('branch_id', '>', 0)->value('branch_id') ?: 1));
    if ((int) $machineUser->branch_id !== $branchId) {
      $machineUser->forceFill(['branch_id' => $branchId])->save();
    }
    KioskMachine::query()->updateOrCreate(
      ['username' => 'kiosk-lecayenne'],
      [
        'user_id' => $machineUser->id,
        'branch_id' => $branchId,
        'machine_id' => 'AUDIT-KIOSK-MULTI',
        'password' => Hash::make('kiosk123'),
        'is_login' => 1,
        'status' => Status::ACTIVE,
      ]
    );

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
        'name' => $prefix . ' ' . $row['label'] . ' ' . $run,
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

    Cache::flush();
    echo json_encode([
      'ok' => true,
      'run' => $run,
      'branch_id' => $branchId,
      'category_id' => (int) $category->id,
      'category_name' => (string) $category->name,
      'products' => $products,
      'expected_total' => round((float) $products->sum('price'), 2),
    ]);
  `));
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
  const result = await page.evaluate(async ({ orderId, expectedStatus, nextStatus }) => {
    const response = await window.axios.post(`admin/kds-order/change-status/${orderId}`, {
      expected_status: expectedStatus,
      status: nextStatus,
    });
    window.dispatchEvent(new CustomEvent('realtime-order-update', {
      detail: { type: 'kiosk-multi-audit-status-change', order_id: orderId, status: nextStatus },
    }));
    return { ok: response.status >= 200 && response.status < 300, status: response.status };
  }, { orderId, expectedStatus, nextStatus });
  expect(result.ok).toBe(true);
}

function writeReport({ notes, trace, orderResponse, fixture }) {
  const missingInstructionLines = (trace.items || []).filter((line) => !String(line.instruction || '').trim());
  const md = [];
  md.push('# Audit borne multi-produits -> paiement -> backend -> KDS\n\n');
  md.push(`Date UTC: ${new Date().toISOString()}\n\n`);
  md.push('## Verdict\n\n');
  md.push('- Flux borne multi-produits execute dans le navigateur: PASS.\n');
  md.push('- Paiement carte avec stub TPE navigateur + confirmation backend: PASS.\n');
  md.push('- Creation backend `POST /api/frontend/order` + `payment-confirm`: PASS.\n');
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

  test('commande borne multi-produits + paiement carte + backend + KDS', async ({ browser }) => {
    fs.rmSync(SHOT_DIR, { recursive: true, force: true });
    fs.mkdirSync(SHOT_DIR, { recursive: true });
    fs.mkdirSync(REPORT_ROOT, { recursive: true });
    cleanupPrefix();
    clearFoodKingRateLimits();

    const fixture = createKioskFixture();
    const [productA, productB] = fixture.products;
    const notes = [];
    const runtimeErrors = [];

    const kioskContext = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const kdsContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const kioskPage = await kioskContext.newPage();
    const kdsPage = await kdsContext.newPage();
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

    try {
      await loginAsKiosk(kioskPage);
      await expect(kioskPage.getByTestId('kiosk-idle-root')).toBeVisible({ timeout: 25_000 });
      await addOverlay(kioskPage, ['Borne authentifiee', 'Depart parcours client', 'Panier vide']);
      await snap(kioskPage, '01-kiosk-auth-idle', notes, 'borne connectee sur ecran accueil');

      await kioskPage.getByTestId('kiosk-order-type-dine-in').click();
      await expect(kioskPage.getByTestId('kiosk-categories-root')).toBeVisible({ timeout: 25_000 });
      await kioskPage.goto(`/kiosk/categories?cat=${fixture.category_id}`, { waitUntil: 'domcontentloaded' });
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

      await kioskPage.getByTestId('kiosk-cart-order-type-dinein').click();
      await kioskPage.getByTestId('kiosk-cart-checkout').click();
      await proceedPastUpsellIfNeeded(kioskPage, notes);
      await expect(kioskPage.getByTestId('kiosk-payment-total')).toBeVisible({ timeout: 20_000 });
      const paymentTotal = parseAmount(await kioskPage.getByTestId('kiosk-payment-total').innerText());
      expect(paymentTotal).toBeCloseTo(fixture.expected_total, 2);
      await kioskPage.getByTestId('kiosk-payment-method-card').click();
      await expect(kioskPage.getByTestId('kiosk-payment-confirm')).toBeEnabled({ timeout: 10_000 });
      await addOverlay(kioskPage, ['Paiement borne', 'Carte selectionnee', `Total: ${paymentTotal}`]);
      await snap(kioskPage, '09-kiosk-paiement-carte-selectionne', notes, 'ecran paiement carte avec total coherent');

      clearFoodKingRateLimits();
      const orderRespPromise = kioskPage.waitForResponse(
        (res) => res.request().method() === 'POST' && /\/api\/frontend\/order$/.test(res.url()),
        { timeout: 30_000 },
      );
      const confirmRespPromise = kioskPage.waitForResponse(
        (res) => res.request().method() === 'POST' && /\/api\/frontend\/order\/\d+\/payment-confirm$/.test(res.url()),
        { timeout: 45_000 },
      );
      await kioskPage.getByTestId('kiosk-payment-confirm').click();
      await expect(kioskPage.getByTestId('kiosk-payment-tpe-overlay')).toBeVisible({ timeout: 20_000 });
      await addOverlay(kioskPage, ['TPE navigateur', 'Paiement carte simule', 'En attente validation backend']);
      await snap(kioskPage, '10-kiosk-tpe-paiement-en-cours', notes, 'overlay TPE visible pendant paiement');

      const orderResp = await orderRespPromise;
      expect(orderResp.status()).toBeLessThan(400);
      orderPayload = await orderResp.json();
      order = orderPayload.data || orderPayload;
      const confirmResp = await confirmRespPromise;
      expect(confirmResp.status()).toBeLessThan(400);
      await kioskPage.waitForFunction(() => (
        document.querySelector('[data-testid="kiosk-waiting-root"]')
        || document.querySelector('[data-testid="kiosk-confirmation-root"]')
      ), null, { timeout: 30_000 });
      const postPaymentState = await kioskPage.getByTestId('kiosk-confirmation-root').isVisible({ timeout: 1000 }).catch(() => false)
        ? 'confirmation'
        : 'attente';
      await addOverlay(kioskPage, ['Paiement confirme', `Commande #${order.id}`, `File ${order.queue_number}`, `Etat borne: ${postPaymentState}`]);
      await snap(kioskPage, '11-kiosk-apres-paiement-confirme', notes, 'borne affiche confirmation ou attente apres paiement confirme');

      await loginAsChefOperator(kdsPage);
      await kdsPage.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });
      await expect(kdsPage.locator('body')).toContainText(new RegExp(escapeRegex(productA.name), 'i'), { timeout: 30_000 });
      await expect(kdsPage.locator('body')).toContainText(new RegExp(escapeRegex(productB.name), 'i'), { timeout: 30_000 });
      const kdsBodyText = await kdsPage.locator('body').innerText({ timeout: 5000 });
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
      await changeKdsStatus(kdsPage, order.id, orderStatusEnum.ACCEPT, orderStatusEnum.PREPARING);
      await kdsPage.waitForTimeout(1500);
      await expect(kdsPage.locator('body')).toContainText(/Préparation|preparing|En préparation/i, { timeout: 10_000 });
      await addOverlay(kdsPage, ['KDS transition', `Commande #${order.id}`, 'Etat: en preparation']);
      await snap(kdsPage, '13-kds-commande-en-preparation', notes, 'KDS passe la commande en preparation');

      clearFoodKingRateLimits();
      await changeKdsStatus(kdsPage, order.id, orderStatusEnum.PREPARING, orderStatusEnum.PREPARED);
      await kdsPage.waitForTimeout(1500);
      await expect(kdsPage.locator('body')).toContainText(/Terminé|Préparée|Prête|done/i, { timeout: 10_000 });
      await addOverlay(kdsPage, ['KDS transition finale', `Commande #${order.id}`, 'Etat: pret']);
      await snap(kdsPage, '14-kds-commande-prete', notes, 'KDS passe la commande en pret');

      await kioskPage.waitForTimeout(16_000);
      await addOverlay(kioskPage, ['Retour borne apres KDS pret', `Commande #${order.id}`, 'Controle attente client']);
      await snap(kioskPage, '15-kiosk-apres-commande-prete-kds', notes, 'borne apres passage KDS en pret');

      const audit = inspectKioskOrder(order.id, { ...fixture, kdsIdentity, runtimeErrors });
      expect(audit.order.branch_id).toBe(fixture.branch_id);
      expect(audit.order.source_surface).toBe('kiosk');
      expect(audit.order.order_type).toBe(orderTypeEnum.KIOSK);
      expect(audit.order.payment_method).toBe(PAYMENT_CARD);
      expect(audit.order.payment_status).toBe(paymentStatusEnum.PAID);
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
      });
    } finally {
      await kioskContext.close().catch(() => {});
      await kdsContext.close().catch(() => {});
      cleanupPrefix();
    }
  });
});
