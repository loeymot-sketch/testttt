const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const { loginAsPosOperator, loginAsChefOperator } = require('./helpers/login');
const { ensurePosTacosMenuForE2e, cleanupPosTacosMenuForE2e } = require('./helpers/pos-tacos-fixture');
const orderStatusEnum = require('../../resources/js/enums/modules/orderStatusEnum.js').default;

const POS_EMAIL = process.env.E2E_POS_USER || 'pos@lecayenne.fr';
const POS_PASSWORD = process.env.E2E_POS_PASS || '123456';
const REPORT_ROOT = path.join(process.cwd(), 'reports/audit/pos-multiproduct-kds-journey-2026-05-05');
const SHOT_DIR = path.join(REPORT_ROOT, 'screenshots');
const REPORT_MD = path.join(REPORT_ROOT, 'RAPPORT_AUDIT_POS_MULTI_PRODUITS_KDS.md');
const RAW_JSON = path.join(REPORT_ROOT, 'raw-pos-multiproduct-trace.json');
const PREFIX = 'AUDIT-POS-MULTI';

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

function cleanupPrefix(prefix = PREFIX) {
  artisan(`
    $prefix = '${phpString(prefix)}';
    $itemIds = DB::table('items')->where('name', 'like', $prefix . '%')->pluck('id');
    $categoryIds = DB::table('item_categories')->where('name', 'like', $prefix . '%')->pluck('id');
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
      DB::table('orders')->whereIn('id', $orderIds)->update(['fiscal_sequence_no' => null]);
      DB::table('orders')->whereIn('id', $orderIds)->delete();
    }

    if ($itemIds->isNotEmpty()) {
      if (Schema::hasTable('item_branch_availability')) DB::table('item_branch_availability')->whereIn('item_id', $itemIds)->delete();
      if (Schema::hasTable('stock_levels')) DB::table('stock_levels')->where('stockable_type', App\\Models\\Item::class)->whereIn('stockable_id', $itemIds)->delete();
      DB::table('items')->whereIn('id', $itemIds)->delete();
    }
    if ($categoryIds->isNotEmpty()) DB::table('item_categories')->whereIn('id', $categoryIds)->delete();
    Cache::flush();
    echo json_encode(['ok' => true, 'orders' => $orderIds->count(), 'items' => $itemIds->count()]);
  `);
}

function createSimplePosProduct(posEmail) {
  return parseLastJsonLine(artisan(`
    use Illuminate\\Support\\Facades\\Schema;
    use Illuminate\\Support\\Facades\\Cache;
    use Illuminate\\Support\\Str;
    use App\\Models\\User;
    use App\\Models\\Item;
    use App\\Models\\ItemCategory;
    use App\\Models\\Tax;
    use App\\Models\\ItemBranchAvailability;
    use App\\Models\\StockLevel;
    use App\\Enums\\Status;
    use App\\Enums\\TaxType;

    $prefix = '${PREFIX}';
    $user = User::query()->where('email', '${phpString(posEmail)}')->firstOrFail();
    $branchId = (int) ($user->branch_id ?: (User::query()->where('branch_id', '>', 0)->value('branch_id') ?: 1));
    if ((int) $user->branch_id !== $branchId) {
      $user->forceFill(['branch_id' => $branchId])->save();
    }
    $tax = Tax::query()->updateOrCreate(
      ['name' => 'PW E2E ZERO TAX'],
      [
        'code' => 'PW_E2E_0',
        'tax_rate' => 0,
        'type' => TaxType::PERCENTAGE,
        'status' => Status::ACTIVE,
      ]
    );
    $run = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $category = ItemCategory::query()->create([
      'name' => $prefix . ' Catégorie ' . $run,
      'slug' => Str::slug($prefix . '-categorie-' . $run . '-' . Str::random(4)),
      'status' => Status::ACTIVE,
      'sort' => 9997,
      'wizard_template' => 'simple',
      'has_menu' => false,
      'channels' => null,
    ]);
    $item = Item::factory()->create([
      'name' => $prefix . ' Burger ' . $run,
      'slug' => Str::slug($prefix . '-burger-' . $run . '-' . Str::random(4)),
      'item_category_id' => $category->id,
      'tax_id' => $tax->id,
      'price' => 7.50,
      'status' => Status::ACTIVE,
      'is_available' => true,
      'is_featured' => 1,
      'channels' => null,
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
    Cache::flush();
    echo json_encode([
      'ok' => true,
      'run' => $run,
      'branch_id' => $branchId,
      'category_id' => (int) $category->id,
      'item_id' => (int) $item->id,
      'name' => (string) $item->name,
      'expected_price' => 7.50,
    ]);
  `));
}

function inspectPosOrder(orderId, fixture) {
  return parseLastJsonLine(artisan(`
    $orderId = (int) ${Number(orderId)};
    $fixture = json_decode('${phpString(JSON.stringify(fixture))}', true);
    $order = App\\Models\\Order::withoutGlobalScopes()->with('orderItems')->findOrFail($orderId);
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
        'source_surface' => $order->source_surface,
        'status' => (int) $order->status,
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
        return [
          'item_id' => (int) $line->item_id,
          'item_name' => $line->item_name,
          'quantity' => (int) $line->quantity,
          'total' => round((float) $line->total, 2),
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
  await page.waitForTimeout(300);
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
    text: bodyText.replace(/\s+/g, ' ').trim().slice(0, 240),
  });
}

async function addOverlay(page, lines) {
  await page.evaluate((payload) => {
    const previous = document.querySelector('[data-testid="audit-pos-flow-overlay"]');
    if (previous) previous.remove();
    const box = document.createElement('section');
    box.dataset.testid = 'audit-pos-flow-overlay';
    box.style.cssText = [
      'position:fixed',
      'left:24px',
      'bottom:24px',
      'z-index:99999',
      'max-width:520px',
      'background:#0f172a',
      'color:#fff',
      'border:3px solid #22c55e',
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

async function openProductModal(page, product) {
  let tile = page.locator(`[data-pos-item-id="${product.item_id}"]`).first();
  if (!(await tile.isVisible({ timeout: 2500 }).catch(() => false))) {
    const search = page.locator('input[type="search"], input[placeholder*="Rechercher"], input[aria-label*="Rechercher"], .pos-v5-search-input input').first();
    if (await search.isVisible({ timeout: 3000 }).catch(() => false)) {
      await search.fill(product.name);
      await page.waitForTimeout(900);
      tile = page.locator(`[data-pos-item-id="${product.item_id}"]`).first();
    }
  }
  await expect(tile, `Tuile POS introuvable pour ${product.name}`).toBeVisible({ timeout: 20_000 });
  await tile.scrollIntoViewIfNeeded();
  await Promise.all([
    page.waitForResponse((res) => res.status() === 200 && /\/api\/admin\/item\/details\/\d+/.test(res.url()), { timeout: 15_000 }).catch(() => null),
    tile.click(),
  ]);
  const modal = page.locator('#item-variation-modal');
  await expect(modal).toHaveClass(/active/, { timeout: 15_000 });
  await expect(modal).toBeVisible({ timeout: 10_000 });
}

function modalRowByTitle(page, titleRe) {
  return page.locator('#item-variation-modal.active div.flex.items-center.gap-3.rounded-lg.border')
    .filter({ has: page.locator('h3').filter({ hasText: titleRe }) })
    .first();
}

function wizardViandeRow(page, titleRe) {
  return page.locator('#item-variation-modal.active .wizard-viande-row')
    .filter({ has: page.locator('.viande-name').filter({ hasText: titleRe }) })
    .first();
}

async function clickPlus(row, times) {
  const plus = row.locator('button.indec-plus').first();
  for (let i = 0; i < times; i += 1) {
    await row.scrollIntoViewIfNeeded().catch(() => {});
    await plus.scrollIntoViewIfNeeded().catch(() => {});
    if (await plus.isVisible({ timeout: 5000 }).catch(() => false)) {
      await plus.click();
    } else {
      await plus.click({ force: true });
    }
  }
}

async function selectViande(page, titleRe) {
  const modernRow = wizardViandeRow(page, titleRe);
  if (await modernRow.isVisible({ timeout: 5000 }).catch(() => false)) {
    const plus = modernRow.locator('button.viande-btn.plus').first();
    await expect(plus).toBeVisible({ timeout: 5000 });
    await plus.click();
    return;
  }
  const visiblePlusButtons = page.locator('#item-variation-modal.active button.indec-plus:visible');
  const visibleIndex = /kefta/i.test(String(titleRe)) ? 1 : 0;
  const visiblePlus = visiblePlusButtons.nth(visibleIndex);
  if (await visiblePlus.isVisible({ timeout: 2000 }).catch(() => false)) {
    await visiblePlus.click();
    return;
  }
  await clickPlus(modalRowByTitle(page, titleRe), 1);
}

async function selectExtra(page, titleRe) {
  const chip = page.locator('#item-variation-modal.active .sauce-chip').filter({ hasText: titleRe }).first();
  if (await chip.isVisible({ timeout: 1000 }).catch(() => false)) {
    await chip.click();
    return;
  }

  const toggle = page.locator('#item-variation-modal.active .suppl-toggle').first();
  if (await toggle.isVisible({ timeout: 1000 }).catch(() => false)) {
    await toggle.click();
    await page.waitForTimeout(250);
  }

  const supplement = page.locator('#item-variation-modal.active .supplement-opt').filter({ hasText: titleRe }).first();
  if (await supplement.isVisible({ timeout: 3000 }).catch(() => false)) {
    await supplement.click();
    return;
  }

  await clickPlus(modalRowByTitle(page, titleRe), 1);
}

async function configureTacosModal(page, instruction) {
  await selectViande(page, /merguez/i);
  await selectViande(page, /kefta/i);
  await selectExtra(page, /ketchup/i);

  const note = page.locator('#item-variation-modal.active textarea:visible').first();
  await expect(note).toBeVisible({ timeout: 5000 });
  await note.fill(instruction);
}

async function configureSimpleModal(page, instruction) {
  const note = page.locator('#item-variation-modal.active textarea:visible').first();
  await expect(note).toBeVisible({ timeout: 5000 });
  await note.fill(instruction);
}

async function addModalToCart(page) {
  const cta = page.getByRole('button', { name: /ajouter au panier|add to cart/i }).first();
  await expect(cta).toBeEnabled({ timeout: 10_000 });
  await cta.click();
  await expect(page.locator('#item-variation-modal')).toBeHidden({ timeout: 10_000 });
}

function parseAmount(text) {
  const n = parseFloat(String(text).replace(/\s/g, '').replace(/[^\d.,-]/g, '').replace(',', '.'));
  return Number.isFinite(n) ? n : NaN;
}

async function changeKdsStatus(page, orderId, expectedStatus, nextStatus) {
  const result = await page.evaluate(async ({ orderId, expectedStatus, nextStatus }) => {
    const response = await window.axios.post(`admin/kds-order/change-status/${orderId}`, {
      expected_status: expectedStatus,
      status: nextStatus,
    },
          // [GOAL CONSOLIDATION 2026-08-25] En-tête OBLIGATOIRE : `config/idempotency.php`
          // liste la route `kds-order/change-status/{id}` dans required_routes (un double
          // bump enverrait deux notifications client). Sans l'en-tête → 422, et l'échec
          // ressemble trompeusement à un défaut de synchro cuisine.
          { headers: { 'X-Idempotency-Key': `idem-kds-${Date.now()}-${Math.random().toString(16).slice(2)}` } }
        );
    window.dispatchEvent(new CustomEvent('realtime-order-update', {
      detail: { type: 'pos-multi-audit-status-change', order_id: orderId, status: nextStatus },
    }));
    return { ok: response.status >= 200 && response.status < 300, status: response.status };
  }, { orderId, expectedStatus, nextStatus });
  expect(result.ok).toBe(true);
}

function writeReport({ notes, trace, orderResponse, fixture }) {
  const md = [];
  md.push('# Audit POS multi-produits -> paiement -> backend -> KDS\n\n');
  md.push(`Date UTC: ${new Date().toISOString()}\n\n`);
  md.push('## Verdict\n\n');
  md.push('- Flux caisse multi-produits execute dans le navigateur: PASS.\n');
  md.push('- Paiement espece via modal POS: PASS.\n');
  md.push('- Creation backend `POST /api/admin/pos`: PASS.\n');
  md.push('- KDS: commande visible, instructions visibles, transitions en preparation puis pret: PASS.\n');
  md.push('- Controle anti-duplication: `queue_count_same_day = 1`, `order_items_count = 2`: PASS.\n\n');
  if (fixture.kdsIdentity && fixture.kdsIdentity.queue_number_visible === false) {
    md.push('## Point audit visuel KDS\n\n');
    md.push('- La commande POS arrive bien au KDS avec ses lignes et instructions cuisine.\n');
    md.push('- Le KDS n affiche pas le `queue_number` brut retourne par le POS; il affiche un identifiant visuel interne/serie. A corriger si la file POS doit etre le repere cuisine principal.\n\n');
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

test.describe('Audit POS multi-produits vers KDS', () => {
  test.describe.configure({ timeout: 300_000 });

  test('commande POS multi-produits + paiement + backend + KDS', async ({ browser }) => {
    fs.rmSync(SHOT_DIR, { recursive: true, force: true });
    fs.mkdirSync(SHOT_DIR, { recursive: true });
    fs.mkdirSync(REPORT_ROOT, { recursive: true });
    cleanupPrefix();
    clearFoodKingRateLimits();

    const tacos = ensurePosTacosMenuForE2e(POS_EMAIL);
    expect(tacos.ok, tacos.reason || 'fixture tacos indisponible').toBe(true);
    const simple = createSimplePosProduct(POS_EMAIL);
    const notes = [];
    const instructionA = `${PREFIX} cuisine tacos ${simple.run}: sans oignon, bien gratine`;
    const instructionB = `${PREFIX} cuisine burger ${simple.run}: sauce a part, cuisson rapide`;

    const posContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const kdsContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const posPage = await posContext.newPage();
    const kdsPage = await kdsContext.newPage();
    const runtimeErrors = [];
    posPage.on('pageerror', (err) => runtimeErrors.push(err.message));
    kdsPage.on('pageerror', (err) => runtimeErrors.push(err.message));

    let orderPayload;
    let kdsIdentity = null;
    try {
      await loginAsPosOperator(posPage, POS_EMAIL, POS_PASSWORD);
      await expect(posPage.locator('#pos-cart')).toBeVisible({ timeout: 25_000 });
      await addOverlay(posPage, ['Départ audit: caisse POS chargee', 'Panier vide, aucun paiement lancé']);
      await snap(posPage, '01-pos-caisse-chargee', notes, 'surface caisse POS chargee');

      await openProductModal(posPage, { item_id: tacos.item_id, name: tacos.name });
      await configureTacosModal(posPage, instructionA);
      await addOverlay(posPage, ['Produit 1 configure', tacos.name, 'Merguez + Kefta + Ketchup + instruction cuisine']);
      await snap(posPage, '02-pos-produit-1-configure', notes, 'produit 1 configure avant ajout panier');
      await addModalToCart(posPage);
      await expect(posPage.locator('#pos-cart').getByText(new RegExp(escapeRegex(tacos.name), 'i')).first()).toBeVisible({ timeout: 10_000 });
      await addOverlay(posPage, ['Panier apres produit 1', tacos.name, 'Instruction cuisine conservee']);
      await snap(posPage, '03-pos-panier-apres-produit-1', notes, 'panier contient le produit 1');

      await openProductModal(posPage, { item_id: simple.item_id, name: simple.name });
      await configureSimpleModal(posPage, instructionB);
      await addOverlay(posPage, ['Produit 2 configure', simple.name, 'Deuxieme ligne distincte']);
      await snap(posPage, '04-pos-produit-2-configure', notes, 'produit 2 configure avant ajout panier');
      await addModalToCart(posPage);
      await expect(posPage.locator('#pos-cart').getByText(new RegExp(escapeRegex(simple.name), 'i')).first()).toBeVisible({ timeout: 10_000 });
      await addOverlay(posPage, ['Panier multi-produits', '2 lignes distinctes', 'Controle anti-duplication visuel']);
      await snap(posPage, '05-pos-panier-multi-produits', notes, 'panier contient deux produits distincts');

      const totalEl = posPage.locator('#pos-cart [data-testid="pos-grand-total"] .pos-v5-total-row__amount').first();
      await expect(totalEl).toBeVisible({ timeout: 5000 });
      const cartTotal = parseAmount(await totalEl.innerText());
      expect(cartTotal).toBeGreaterThan(0);

      await posPage.getByTestId('pos-v5-pay').click();
      await expect(posPage.locator('#orderpayment')).toBeVisible({ timeout: 10_000 });
      await posPage.locator('[data-tab="#cash"]').click();
      const modalTotal = parseAmount(await posPage.locator('#orderpayment .pos-v5-payment-total-value').first().innerText());
      expect(modalTotal).toBeCloseTo(cartTotal, 2);
      await posPage.locator('#cashInput').fill(String(Math.ceil(modalTotal + 10)));
      await addOverlay(posPage, ['Paiement espece pret', `Total panier: ${cartTotal}`, `Total modal: ${modalTotal}`]);
      await snap(posPage, '06-pos-modal-paiement-espece', notes, 'modal paiement espece avec total coherent');

      clearFoodKingRateLimits();
      const saveRespPromise = posPage.waitForResponse(
        (res) => res.request().method() === 'POST' && /\/api\/admin\/pos$/.test(res.url()),
        { timeout: 30_000 },
      );
      await posPage.getByTestId('pos-payment-confirm').click();
      const saveResp = await saveRespPromise;
      expect(saveResp.status()).toBeLessThan(400);
      orderPayload = await saveResp.json();
      const order = orderPayload.data || orderPayload;
      await expect(posPage.locator('#receiptModal')).toBeVisible({ timeout: 20_000 });
      await addOverlay(posPage, ['Paiement confirme', `Commande #${order.id}`, `File ${order.queue_number}`]);
      await snap(posPage, '07-pos-recu-apres-paiement', notes, 'recu affiche apres paiement confirme');

      await posPage.goto('/admin/pos-orders', { waitUntil: 'domcontentloaded' });
      await expect(posPage.getByText(String(order.queue_number), { exact: false }).first()).toBeVisible({ timeout: 20_000 });
      await addOverlay(posPage, ['Back-office commandes caisse', `File ${order.queue_number}`, `Commande #${order.id}`]);
      await snap(posPage, '08-pos-backoffice-commande-visible', notes, 'commande visible dans commandes caisse');

      await loginAsChefOperator(kdsPage);
      await kdsPage.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });
      await expect(kdsPage.locator('body')).toContainText(new RegExp(escapeRegex(instructionA)), { timeout: 30_000 });
      await expect(kdsPage.locator('body')).toContainText(new RegExp(escapeRegex(instructionB)), { timeout: 30_000 });
      const kdsBodyText = await kdsPage.locator('body').innerText({ timeout: 5000 });
      kdsIdentity = {
        expected_queue_number: order.queue_number || null,
        queue_number_visible: order.queue_number ? kdsBodyText.includes(String(order.queue_number)) : null,
        order_serial_no: order.order_serial_no || order.fiscal_sequence_no || null,
        order_serial_visible: (order.order_serial_no || order.fiscal_sequence_no)
          ? kdsBodyText.includes(String(order.order_serial_no || order.fiscal_sequence_no))
          : null,
        backend_id_visible: kdsBodyText.includes(String(order.id)),
        visual_source_labels: Array.from(new Set(kdsBodyText.match(/Borne|Caisse|POS|Sur place|À emporter/g) || [])),
        excerpt: kdsBodyText.replace(/\s+/g, ' ').trim().slice(0, 1000),
      };
      await addOverlay(kdsPage, [
        'KDS: commande POS recue',
        `File POS visible: ${kdsIdentity.queue_number_visible ? 'oui' : 'non'}`,
        '2 instructions cuisine visibles',
      ]);
      await snap(kdsPage, '09-kds-commande-recue-instructions', notes, 'KDS affiche la commande et les instructions cuisine');

      clearFoodKingRateLimits();
      await changeKdsStatus(kdsPage, order.id, orderStatusEnum.ACCEPT, orderStatusEnum.PREPARING);
      await kdsPage.waitForTimeout(1500);
      await expect(kdsPage.locator('body')).toContainText(/Préparation|preparing|En préparation/i, { timeout: 10_000 });
      await addOverlay(kdsPage, ['KDS transition', `Commande #${order.id}`, 'Etat: en preparation']);
      await snap(kdsPage, '10-kds-commande-en-preparation', notes, 'KDS passe la commande en preparation');

      clearFoodKingRateLimits();
      await changeKdsStatus(kdsPage, order.id, orderStatusEnum.PREPARING, orderStatusEnum.PREPARED);
      await kdsPage.waitForTimeout(1500);
      await expect(kdsPage.locator('body')).toContainText(/Terminé|Préparée|Prête|done/i, { timeout: 10_000 });
      await addOverlay(kdsPage, ['KDS transition finale', `Commande #${order.id}`, 'Etat: pret']);
      await snap(kdsPage, '11-kds-commande-prete', notes, 'KDS passe la commande en pret');

      const audit = inspectPosOrder(order.id, { tacos, simple, instructionA, instructionB });
      expect(audit.order.branch_id).toBe(simple.branch_id);
      expect(audit.order.source_surface).toBe('pos');
      expect(audit.order.order_items_count).toBe(2);
      expect(audit.order.payment_status).toBe(5);
      expect(audit.order.status).toBe(orderStatusEnum.PREPARED);
      expect(audit.queue_count_same_day).toBe(1);
      const backendInstructions = audit.items.map((line) => String(line.instruction || ''));
      expect(backendInstructions.some((instruction) => instruction.includes(instructionA))).toBe(true);
      expect(backendInstructions.some((instruction) => instruction.includes(instructionB))).toBe(true);
      expect(audit.transitions.map((transition) => `${transition.from}->${transition.to}`)).toEqual(
        expect.arrayContaining([
          `${orderStatusEnum.ACCEPT}->${orderStatusEnum.PREPARING}`,
          `${orderStatusEnum.PREPARING}->${orderStatusEnum.PREPARED}`,
        ]),
      );
      expect(runtimeErrors.filter((msg) => /TypeError|ReferenceError|Cannot read|is not a function|is not defined/i.test(msg))).toHaveLength(0);

      writeReport({
        notes,
        trace: audit,
        orderResponse: order,
        fixture: { tacos, simple, instructionA, instructionB, kdsIdentity, runtimeErrors },
      });
    } finally {
      await posContext.close().catch(() => {});
      await kdsContext.close().catch(() => {});
      cleanupPrefix();
      cleanupPosTacosMenuForE2e();
    }
  });
});
