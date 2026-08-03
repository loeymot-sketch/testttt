/**
 * Garantit qu’au moins un article « tacos » (catégorie wizard tacos ou nom catégorie Tacos)
 * est mis en avant et disponible sur la branche du caissier — pour E2E POS landing / best-sellers.
 */
const { execFileSync } = require('child_process');
const path = require('path');

const repoRoot = path.resolve(__dirname, '../../..');

function parseLastJsonLine(output) {
  const lines = String(output)
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean);
  const jsonLine = [...lines].reverse().find((l) => l.startsWith('{'));
  if (!jsonLine) {
    throw new Error(`pos-tacos-fixture: no JSON in artisan output:\n${output}`);
  }
  return JSON.parse(jsonLine);
}

function cleanupPosTacosMenuForE2e() {
  execFileSync(
    'php',
    [
      'artisan',
      'foodking:cleanup-test-fixtures',
      '--prefix=PW-E2E',
      '--apply',
      '--confirm=PW-FIXTURES',
      '--json',
    ],
    { cwd: repoRoot, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
  );
  execFileSync(
    'php',
    ['artisan', 'tinker', '--execute', `
      use Illuminate\\Support\\Facades\\DB;
      use Illuminate\\Support\\Facades\\Schema;
      if (Schema::hasTable('item_attributes')) {
        DB::table('item_attributes')
          ->where('name', 'like', 'Viande A %')
          ->orWhere('name', 'like', 'Viande B %')
          ->delete();
      }
    `],
    { cwd: repoRoot, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
  );
}

/**
 * @param {string} posEmail
 * @returns {{ ok: boolean, reason?: string, item_id?: number, name?: string }}
 */
function ensurePosTacosMenuForE2e(posEmail) {
  cleanupPosTacosMenuForE2e();

  const escaped = String(posEmail || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  const out = execFileSync(
    'php',
    ['artisan', 'tinker', '--execute', `
    use Illuminate\\Support\\Facades\\Schema;
    use App\\Models\\User;
    use App\\Models\\Item;
    use App\\Models\\ItemCategory;
    use App\\Models\\ItemAttribute;
    use App\\Models\\ItemVariation;
    use App\\Models\\ItemExtra;
    use App\\Models\\Tax;
    use App\\Models\\ItemBranchAvailability;
    use App\\Models\\StockLevel;
    use App\\Enums\\Status;
    use App\\Enums\\TaxType;
    use Illuminate\\Support\\Str;
    use Illuminate\\Support\\Facades\\DB;

    $email = '${escaped}';
    $user = User::query()->where('email', $email)->first();
    if (! $user) {
        echo json_encode(['ok' => false, 'reason' => 'pos_user_missing']);
        return;
    }
    $branchId = (int) ($user->branch_id ?: 0);
    if ($branchId < 1) {
        $branchId = (int) (User::query()->where('branch_id', '>', 0)->value('branch_id') ?: 1);
        $user->forceFill(['branch_id' => $branchId])->save();
    }

    $e2eItemIds = Item::query()->where(function ($q) {
        $q->where('name', 'like', 'Tacos E2E Menu%')
            ->orWhere('name', 'like', 'Tacos L E2E Menu%');
    })->pluck('id');
    if ($e2eItemIds->isNotEmpty()) {
        if (Schema::hasTable('item_variations')) {
            DB::table('item_variations')->whereIn('item_id', $e2eItemIds)->delete();
        }
        if (Schema::hasTable('item_extras')) {
            DB::table('item_extras')->whereIn('item_id', $e2eItemIds)->delete();
        }
        if (Schema::hasTable('item_branch_availability')) {
            DB::table('item_branch_availability')->whereIn('item_id', $e2eItemIds)->delete();
        }
        if (Schema::hasTable('stock_levels')) {
            DB::table('stock_levels')->where('stockable_type', Item::class)->whereIn('stockable_id', $e2eItemIds)->delete();
        }
        if (Schema::hasTable('item_wizard_profiles')) {
            DB::table('item_wizard_profiles')->whereIn('item_id', $e2eItemIds)->delete();
        }
        Item::query()->whereIn('id', $e2eItemIds)->delete();
    }
    ItemCategory::query()->where('name', 'like', 'PW-E2E Tacos%')->delete();

    // Fixture deterministic: éviter une taxe existante inattendue (ex: 200%).
    // On force un taux 0% dédié E2E pour stabiliser les totaux des audits POS.
    $tax = Tax::query()->updateOrCreate(
        ['name' => 'PW E2E ZERO TAX'],
        [
            'code' => 'PW_E2E_0',
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]
    );
    $suffix = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    $category = ItemCategory::query()->create([
        'name' => 'PW-E2E Tacos '.$suffix,
        'slug' => 'pw-e2e-tacos-'.Str::lower(Str::random(8)),
        'status' => Status::ACTIVE,
        'sort' => 9999,
        'wizard_template' => 'simple',
        'has_menu' => false,
    ]);
    $item = Item::factory()->create([
        'name' => 'Tacos L E2E Menu '.$suffix,
        'item_category_id' => $category->id,
        'tax_id' => $tax->id,
        'price' => 12.00,
        'status' => Status::ACTIVE,
        'is_available' => true,
        'is_featured' => 1,
    ]);
    $attrA = ItemAttribute::factory()->create([
        'name' => 'Viande A '.$suffix,
        'status' => Status::ACTIVE,
        'min_select' => 1,
        'max_select' => 2,
        'allow_repeat' => true,
    ]);
    $attrB = ItemAttribute::factory()->create([
        'name' => 'Viande B '.$suffix,
        'status' => Status::ACTIVE,
        'min_select' => 1,
        'max_select' => 2,
        'allow_repeat' => true,
    ]);
    $variationA = ItemVariation::query()->create([
        'item_id' => $item->id,
        'item_attribute_id' => $attrA->id,
        'name' => 'Merguez',
        'price' => 1.0,
        'status' => Status::ACTIVE,
        'visible_on' => ['pos', 'kiosk'],
    ]);
    $variationB = ItemVariation::query()->create([
        'item_id' => $item->id,
        'item_attribute_id' => $attrB->id,
        'name' => 'Kefta',
        'price' => 1.0,
        'status' => Status::ACTIVE,
        'visible_on' => ['pos', 'kiosk'],
    ]);
    $extra = ItemExtra::query()->create([
        'item_id' => $item->id,
        'name' => 'Ketchup',
        'price' => 0.5,
        'status' => Status::ACTIVE,
        'visible_on' => ['pos', 'kiosk'],
        'group_label' => 'Sauces',
    ]);
    if (Schema::hasTable('item_wizard_profiles')) {
        DB::table('item_wizard_profiles')->where('item_id', $item->id)->delete();
    }

    $item->forceFill(['is_featured' => 1, 'is_available' => true])->save();

    if (Schema::hasTable('item_branch_availability')) {
        ItemBranchAvailability::query()->updateOrCreate(
            ['branch_id' => $branchId, 'item_id' => $item->id],
            [
                'is_available' => true,
                'unavailable_reason' => null,
                'daily_consumed_qty' => 0,
                'daily_reset_at' => now()->toDateString(),
            ]
        );
    }

    if (Schema::hasTable('stock_levels')) {
        foreach ([
            [Item::class, $item->id],
            [ItemVariation::class, $variationA->id],
            [ItemVariation::class, $variationB->id],
            [ItemExtra::class, $extra->id],
        ] as [$stockableType, $stockableId]) {
            StockLevel::query()->updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'stockable_type' => $stockableType,
                    'stockable_id' => $stockableId,
                ],
                ['on_hand' => 99, 'reserved' => 0]
            );
        }
    }

    echo json_encode([
        'ok' => true,
        'item_id' => (int) $item->id,
        'name' => (string) $item->name,
        'branch_id' => $branchId,
    ]);
  `],
    { cwd: repoRoot, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
  );

  return parseLastJsonLine(out);
}

module.exports = { ensurePosTacosMenuForE2e, cleanupPosTacosMenuForE2e };
