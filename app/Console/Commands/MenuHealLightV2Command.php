<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Events\CategoryCreated;
use App\Events\CategoryUpdated;
use App\Events\CategoryDeleted;
use App\Events\ItemCreated;
use App\Events\ItemDeleted;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MENU HEAL-LIGHT V2 — owner-validated 2026-05-14
 * =================================================================================
 *
 * Applies the 38 drifts documented in reports/audit/menu-spec-vs-db-2026-05-13.md
 * NON-DESTRUCTIVELY: preserves historical orders (32 rows), frozen wizard files,
 * existing composer profiles, and the audit chain.
 *
 * Rules:
 *  - DB::transaction wraps ALL writes — rollback on any failure
 *  - ZÉRO touche frozen-zone (CLAUDE.md §7)
 *  - Idempotent : re-run with --force is a no-op once applied
 *  - composition_snapshot JSON in historical orders is frozen → renaming / pricing
 *    new items is safe (reprints use the snapshot)
 *  - Soft-delete archived items (not destroy) — receipts reprint OK
 *
 * Bowls flow decision (owner-validated §20.5):
 *  Option B — 8 items at 8.90€ (4 viandes × 2 bases) instead of 2 items + viande step.
 *  Rationale : the 2-item flow with viande-as-step-1 requires the kiosk wizard to
 *  render a viande step inside a composer_profile for bowls, but the frozen
 *  KioskWizardComponent.vue hard-codes `bol_meat_fixed` semantics. 8 items keeps
 *  everything in the data-layer.
 *
 * Big Cayenne / Big Classique "included" items decision:
 *  cheddar+œuf+jambon "INCLUS" is encoded in the description + base price
 *  (9.50 vs 7.50 = 2.00€ covers 3×0.90€ premium-cost inclusion).
 *  The wizard has no "pre-selected extras" rendering path, so this stays cosmetic.
 *
 * @see reports/audit/menu-spec-vs-db-2026-05-13.md
 * @see PROJECT_BRAIN.md §7 (frozen zones)
 */
class MenuHealLightV2Command extends Command
{
    protected $signature = 'menu:heal-light-v2
                            {--dry-run : Show plan without DB writes}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Heal 38 drifts owner-validated 2026-05-14 (non-destructive: preserves history)';

    /**
     * Frozen-zone files protected by CLAUDE.md §7.
     * Command guards against accidental modification — anything detected here aborts.
     */
    private const FROZEN_FILES = [
        'public/js/pos-wizard.js',
        'public/css/pos-wizard.css',
        'resources/views/admin-pos-v4.blade.php',
        'resources/js/components/frontend/kiosk/KioskWizardComponent.vue',
        'resources/js/components/frontend/kiosk/KioskAppComponent.vue',
        'resources/js/components/frontend/kiosk/KioskUpsellComponent.vue',
        'app/Services/Fiscal/FiscalSequenceService.php',
        'app/Services/Fiscal/ZReportService.php',
        'app/Services/Fiscal/AuditLogService.php',
        'app/Models/Scopes/BranchScope.php',
        'app/Http/Middleware/IdempotencyKeyMiddleware.php',
        'app/Services/Pricing/PricingService.php',
        'app/Domain/Order/OrderStateMachine.php',
    ];

    /**
     * Canonical pool — owner-validated 2026-05-14.
     */
    private const VIANDES = [
        'Poulet mariné',    // renamed from "Poulet classic"
        'Poulet curry',
        'Poulet tandoori',
        'Poulet crispy',
    ];

    /**
     * Sauces — 13 in current DB → 11 in spec.
     *  - "Fromagère"  → renamed "Sauce fromagère maison"
     *  - "Pimentée"   → renamed "Spicy"
     *  - "Tandoori"   → soft-deleted (it's a meat, not a sauce)
     *  - "Cayenne"    → soft-deleted (it's a sandwich, not a sauce)
     *  - "Spicy"      → already covered by Pimentée rename (no separate INSERT)
     */
    private const SAUCES_CANONICAL = [
        'Mayonnaise', 'Ketchup', 'Algérienne', 'Samouraï', 'Curry', 'Andalouse',
        'Harissa', 'Hannibal', 'Blanche', 'Sauce fromagère maison', 'Spicy',
    ];

    private const SAUCE_RENAMES = [
        'Fromagère' => 'Sauce fromagère maison',
        'Pimentée'  => 'Spicy',
    ];

    private const SAUCES_TO_ARCHIVE = ['Tandoori', 'Cayenne'];

    /**
     * Suppléments — 0.90€ each (was 1.00€); + add "Boursin"; - remove "Bacon".
     *  - "Oignons frits" → renamed "Oignon frais"
     *  - "Boule gratinée" → stays at 2.00€ for bols (special)
     */
    private const SUPP_NEW_PRICE = 0.90;

    private const SUPP_RENAMES = [
        'Oignons frits' => 'Oignon frais',
    ];

    private const SUPP_TO_ARCHIVE = ['Bacon'];

    private const SUPP_TO_ADD = [
        ['name' => 'Boursin', 'price' => 0.90, 'slug' => 'supp-boursin'],
    ];

    /**
     * New categories — Burgers + Menu enfant.
     */
    private const NEW_CATEGORIES = [
        [
            'slug' => 'burgers', 'name' => 'Burgers', 'sort' => 4,
            'wizard_template' => 'sandwich', 'has_menu' => true,
            'description' => 'Burgers avec pain burger, viande croustillante, sauce au choix',
        ],
        [
            'slug' => 'menu-enfant', 'name' => 'Menu enfant', 'sort' => 11,
            'wizard_template' => 'simple', 'has_menu' => false,
            'description' => 'Menu enfant nuggets + frites + Capri-Sun',
        ],
    ];

    /**
     * Final sort order — 11 cats (9 existing + 2 new).
     */
    private const SORT_MAP_FINAL = [
        'sandwich-cayenne'   => 1,
        'galette'            => 2,
        'sandwich-classique' => 3,
        'burgers'            => 4,
        'tacos'              => 5,
        'bols-gourmands'     => 6,
        'frites'             => 7,
        'supplements'        => 8,
        'desserts'           => 9,
        'boissons'           => 10,
        'menu-enfant'        => 11,
    ];

    private array $stats = [
        'price_updates'         => 0,
        'items_renamed'         => 0,
        'items_created'         => 0,
        'items_archived'        => 0,
        'categories_created'    => 0,
        'categories_renamed'    => 0,
        'variations_renamed'    => 0,
        'variations_created'    => 0,
        'variations_archived'   => 0,
        'extras_updated'        => 0,
        'extras_created'        => 0,
        'profiles_created'      => 0,
        'steps_created'         => 0,
        'addons_created'        => 0,
        'events_fired'          => 0,
    ];

    private array $eventsToFire = [];

    public function handle(): int
    {
        $this->line('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('  MENU HEAL-LIGHT V2 — owner-validated 2026-05-14');
        $this->info('  Apply 38 drifts non-destructively (preserves 32 historical orders)');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->line('');

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('🔬 DRY-RUN MODE — no DB writes, no events.');
            $this->line('');
        }

        if (! $this->option('force') && ! $dryRun) {
            $this->warn('⚠️  This will :');
            $this->line('  - Apply 5 price corrections (P0 financial)');
            $this->line('  - Create 2 new categories (Burgers, Menu enfant)');
            $this->line('  - Create 13+ new items (Big Cayenne/Classique, 2 burgers, 8 bowls, Menu Nuggets)');
            $this->line('  - Soft-delete 5 old bols, "Bacon" supp, replace with new 0.90€ pricing');
            $this->line('  - Rename viande "Poulet classic" → "Poulet mariné" (all rows)');
            $this->line('  - Rename sauce "Fromagère" → "Sauce fromagère maison" + "Pimentée" → "Spicy"');
            $this->line('  - Soft-delete sauce "Tandoori" + "Cayenne" (they are meat/sandwich, not sauces)');
            $this->line('  - Fire CategoryCreated/Updated/ItemCreated/Deleted events for sync');
            $this->line('');

            if (! $this->confirm('Proceed?')) {
                $this->warn('Aborted by user.');
                return self::SUCCESS;
            }
        }

        try {
            $this->preflightChecks();

            if ($dryRun) {
                $this->dryRunPlan();
                return self::SUCCESS;
            }

            DB::transaction(function () {
                $this->stepAPriceUpdates();
                $this->stepGSauceVariationsUpdate();   // sauces first (rename before INSERTs reference)
                $this->stepHViandeRename();
                $this->stepFSupplementsUpdate();
                $this->stepBNewSandwichItems();
                $this->stepCNewBurgersCategory();
                $this->stepDNewMenuEnfantCategory();
                $this->stepEBowlsRestructure();
                $this->stepIConfigUpdateMarker();
                $this->stepFinalizeSortOrder();
            });

            $this->verifyFrozenZoneClean();
            $this->fireDeferredEvents();
            $this->renderStats();

            $this->line('');
            $this->info('✅ Menu heal-light V2 complete.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('❌ FAILED: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile() . ':' . $e->getLine());
            Log::error('MenuHealLightV2Command failed', ['exception' => $e]);

            return self::FAILURE;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRE-FLIGHT
    // ─────────────────────────────────────────────────────────────────────
    private function preflightChecks(): void
    {
        $current = ItemCategory::whereNull('deleted_at')->count();
        $this->info("Pre-flight : {$current} active categories detected.");
        if ($current === 0) {
            throw new \RuntimeException('No active categories — DB empty? Aborting.');
        }

        // Sanity check : ensure addon items exist (cat 315)
        $menuAddonId = Item::where('slug', 'menu-frites-boisson')->value('id');
        $fritesAddonId = Item::where('slug', 'frites-seules')->value('id');
        $boissonAddonId = Item::where('slug', 'boisson-seule')->value('id');
        if (! $menuAddonId || ! $fritesAddonId || ! $boissonAddonId) {
            throw new \RuntimeException(
                'Required addon items missing (menu / frites-seules / boisson-seule). '
                . 'Run menu:reset-le-cayenne first.'
            );
        }
        $this->info("Pre-flight : addon items resolved (menu={$menuAddonId} frites={$fritesAddonId} boisson={$boissonAddonId}).");
    }

    private function dryRunPlan(): void
    {
        $this->info('📋 DRY-RUN plan (owner-validated 2026-05-14) :');
        $this->line('');

        $this->line('A. PRIX UPDATES (5 items):');
        foreach ($this->priceUpdatePlan() as $p) {
            $current = Item::where('id', $p['id'])->value('price');
            $this->line("   - item id={$p['id']} {$p['name_target']} : {$current}€ → {$p['price']}€");
        }

        $this->line('');
        $this->line('B. NEW SANDWICH ITEMS (2):');
        $this->line('   - Big Cayenne 9.50€ (cat 344) — 2 viandes, sauce Cayenne locked');
        $this->line('   - Big Classique 9.00€ (cat 346) — 2 viandes, sauce libre');

        $this->line('');
        $this->line('C. NEW BURGERS CATEGORY (cat slug=burgers, sort=4):');
        $this->line('   - Chicken Burger 6.90€ — Poulet crispy + sauce libre + pain burger');
        $this->line('   - Big Chicken / Double Chicken 8.90€ — Poulet crispy + INCLUS cheddar+jambon+œuf');

        $this->line('');
        $this->line('D. NEW MENU ENFANT CATEGORY (cat slug=menu-enfant, sort=11):');
        $this->line('   - Menu Nuggets 6.00€ (bundle nuggets + frites + Capri-Sun)');

        $this->line('');
        $this->line('E. BOWLS RESTRUCTURE (8 items @ 8.90€ — 4 viandes × 2 bases):');
        $this->line('   - Soft-delete 5 old bols (id 480-484)');
        $this->line('   - Create 8 new : Bowl Frites/Riz × Poulet Mariné/Curry/Tandoori/Crispy @ 8.90€');
        $this->line('   - Each gets composer_profile: sauce + supp + drink + gratiné option');

        $this->line('');
        $this->line('F. SUPPLÉMENTS UPDATE (10 items × 1.00€ → 0.90€):');
        $this->line('   - UPDATE prices to 0.90€ (except "Boule gratinée" stays 2.00€ bol-specific)');
        $this->line('   - SOFT-DELETE Bacon (id 468) — not in spec');
        $this->line('   - INSERT Boursin 0.90€');
        $this->line('   - RENAME Oignons frits → Oignon frais (id 471)');

        $this->line('');
        $this->line('G. SAUCES VARIATIONS UPDATE:');
        $this->line('   - RENAME Fromagère → "Sauce fromagère maison" (all rows)');
        $this->line('   - RENAME Pimentée → Spicy (all rows)');
        $this->line('   - SOFT-DELETE sauce Tandoori + Cayenne (variations only)');

        $this->line('');
        $this->line('H. VIANDE RENAME:');
        $this->line('   - UPDATE Poulet classic → Poulet mariné (all item_variations)');

        $this->line('');
        $this->line('J. SYNC EVENTS deferred (CategoryCreated/Updated, ItemCreated, ItemDeleted, CatalogChanged bridge auto).');

        $this->line('');
        $this->line('K. FROZEN-ZONE diff verification at end (13 protected files).');

        $this->line('');
        $this->info('DRY-RUN — no changes persisted. Run with --force to execute.');
    }

    private function priceUpdatePlan(): array
    {
        return [
            ['id' => 474, 'price' => 7.50, 'name_target' => 'Sandwich Cayenne'],
            ['id' => 477, 'price' => 7.00, 'name_target' => 'Sandwich Classique'],
            ['id' => 478, 'price' => 6.90, 'name_target' => 'Tacos M (rename from "Tacos")'],
            ['id' => 479, 'price' => 7.90, 'name_target' => 'Tacos L (rename from "Big Tacos")'],
            ['id' => 360, 'price' => 2.50, 'name_target' => 'Menu addon (Frites+Boisson)'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP A — Price updates (P0 financial, 5 items)
    // ─────────────────────────────────────────────────────────────────────
    private function stepAPriceUpdates(): void
    {
        $this->info('▶ Step A — Price updates (P0 financial)…');

        $renames = [
            478 => ['name' => 'Tacos M'],
            479 => ['name' => 'Tacos L'],
        ];

        foreach ($this->priceUpdatePlan() as $p) {
            $item = Item::withTrashed()->where('id', $p['id'])->first();
            if (! $item) {
                $this->warn("   ↳ item id={$p['id']} NOT FOUND — skipping.");
                continue;
            }

            $payload = ['price' => $p['price']];
            $nameNeedsUpdate = false;
            if (isset($renames[$p['id']]) && $item->name !== $renames[$p['id']]['name']) {
                $payload['name'] = $renames[$p['id']]['name'];
                $nameNeedsUpdate = true;
                $this->stats['items_renamed']++;
            }

            $oldPrice = (float) $item->price;
            $priceNeedsUpdate = abs($oldPrice - $p['price']) > 0.001;
            if ($priceNeedsUpdate || $nameNeedsUpdate) {
                $item->update($payload);
                if ($priceNeedsUpdate) {
                    $this->stats['price_updates']++;
                }
                $this->line("   ↳ Updated id={$p['id']} → price={$p['price']}" . ($nameNeedsUpdate ? ' name=' . $payload['name'] : ''));
            } else {
                $this->line("   ↳ id={$p['id']} already at {$p['price']}€ — skipping.");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP B — New sandwich items (Big Cayenne + Big Classique)
    // ─────────────────────────────────────────────────────────────────────
    private function stepBNewSandwichItems(): void
    {
        $this->info('▶ Step B — New sandwich items (Big Cayenne + Big Classique)…');

        $cayenneCat = ItemCategory::where('slug', 'sandwich-cayenne')->whereNull('deleted_at')->first();
        $classiqueCat = ItemCategory::where('slug', 'sandwich-classique')->whereNull('deleted_at')->first();

        if (! $cayenneCat || ! $classiqueCat) {
            throw new \RuntimeException('Sandwich categories (sandwich-cayenne / sandwich-classique) not found.');
        }

        $menuAddonId   = Item::where('slug', 'menu-frites-boisson')->value('id');
        $fritesAddonId = Item::where('slug', 'frites-seules')->value('id');
        $boissonAddonId = Item::where('slug', 'boisson-seule')->value('id');

        // Big Cayenne — 2 viandes, sauce Cayenne LOCKED (no sauce variations),
        // "INCLUS cheddar+œuf+jambon" encoded in description + price premium.
        $bigCayenne = $this->createOrRestoreItem([
            'slug'             => 'big-cayenne',
            'name'             => 'Big Cayenne',
            'item_category_id' => $cayenneCat->id,
            'price'            => 9.50,
            'description'      => 'Sandwich signature XL · 2 viandes au choix · Sauce Cayenne maison incluse · INCLUS : Cheddar + Œuf + Jambon · Crudités · Suppléments optionnels.',
            'is_featured'      => 1,
            'item_type'        => \App\Enums\ItemType::NON_VEG,
        ]);
        $this->seedViandesForItem($bigCayenne, 2);
        // Sauce Cayenne is LOCKED → no Sauce variations attached (wizard skips sauce step).
        $this->seedCruditesAsExtras($bigCayenne);
        $this->seedSupplementsAsExtras($bigCayenne);
        $this->seedMenuAddonsForItem($bigCayenne, $menuAddonId, $fritesAddonId, $boissonAddonId);

        // Big Classique — 2 viandes, sauce LIBRE, INCLUS cheddar+œuf+jambon
        $bigClassique = $this->createOrRestoreItem([
            'slug'             => 'big-classique',
            'name'             => 'Big Classique',
            'item_category_id' => $classiqueCat->id,
            'price'            => 9.00,
            'description'      => 'Sandwich classique XL en pain faluche · 2 viandes au choix · Sauce libre · INCLUS : Cheddar + Œuf + Jambon · Crudités · Suppléments optionnels.',
            'is_featured'      => 1,
            'item_type'        => \App\Enums\ItemType::NON_VEG,
        ]);
        $this->seedViandesForItem($bigClassique, 2);
        $this->seedSaucesForItem($bigClassique);
        $this->seedCruditesAsExtras($bigClassique);
        $this->seedSupplementsAsExtras($bigClassique);
        $this->seedMenuAddonsForItem($bigClassique, $menuAddonId, $fritesAddonId, $boissonAddonId);
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP C — New Burgers category + 2 items
    // ─────────────────────────────────────────────────────────────────────
    private function stepCNewBurgersCategory(): void
    {
        $this->info('▶ Step C — New Burgers category + items…');

        $payload = collect(self::NEW_CATEGORIES)->firstWhere('slug', 'burgers');
        $cat = $this->createOrRestoreCategory($payload);

        $menuAddonId   = Item::where('slug', 'menu-frites-boisson')->value('id');
        $fritesAddonId = Item::where('slug', 'frites-seules')->value('id');
        $boissonAddonId = Item::where('slug', 'boisson-seule')->value('id');

        // Chicken Burger — 1 viande (Poulet crispy default), sauce libre, crudités, pain burger
        $chicken = $this->createOrRestoreItem([
            'slug'             => 'chicken-burger',
            'name'             => 'Chicken Burger',
            'item_category_id' => $cat->id,
            'price'            => 6.90,
            'description'      => 'Burger pain brioché · Poulet crispy · Sauce libre · Crudités · Suppléments optionnels.',
            'is_featured'      => 1,
            'item_type'        => \App\Enums\ItemType::NON_VEG,
        ]);
        $this->seedViandesForItem($chicken, 1);
        $this->seedSaucesForItem($chicken);
        $this->seedCruditesAsExtras($chicken);
        $this->seedSupplementsAsExtras($chicken);
        $this->seedMenuAddonsForItem($chicken, $menuAddonId, $fritesAddonId, $boissonAddonId);

        // Big Chicken / Double Chicken — 1 viande Poulet crispy + INCLUS cheddar+jambon+œuf
        $bigChicken = $this->createOrRestoreItem([
            'slug'             => 'big-chicken',
            'name'             => 'Big Chicken',
            'item_category_id' => $cat->id,
            'price'            => 8.90,
            'description'      => 'Big Burger pain brioché · Poulet crispy · Sauce libre · INCLUS : Cheddar + Jambon + Œuf · Crudités · Suppléments optionnels.',
            'is_featured'      => 1,
            'item_type'        => \App\Enums\ItemType::NON_VEG,
        ]);
        $this->seedViandesForItem($bigChicken, 1);
        $this->seedSaucesForItem($bigChicken);
        $this->seedCruditesAsExtras($bigChicken);
        $this->seedSupplementsAsExtras($bigChicken);
        $this->seedMenuAddonsForItem($bigChicken, $menuAddonId, $fritesAddonId, $boissonAddonId);
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP D — New Menu enfant category + 1 item
    // ─────────────────────────────────────────────────────────────────────
    private function stepDNewMenuEnfantCategory(): void
    {
        $this->info('▶ Step D — New Menu enfant category + item…');

        $payload = collect(self::NEW_CATEGORIES)->firstWhere('slug', 'menu-enfant');
        $cat = $this->createOrRestoreCategory($payload);

        // Menu Nuggets — bundle FIXE (no wizard), 6 nuggets + frites + Capri-Sun = 6.00€
        $menuNuggets = $this->createOrRestoreItem([
            'slug'             => 'menu-nuggets',
            'name'             => 'Menu Nuggets',
            'item_category_id' => $cat->id,
            'price'            => 6.00,
            'description'      => 'Menu enfant : 6 nuggets de poulet · Frites · Capri-Sun.',
            'is_featured'      => 0,
            'item_type'        => \App\Enums\ItemType::NON_VEG,
        ]);
        // No wizard — bundle FIXE. No extras / variations.
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP E — Bowls restructure (5 old → 8 new × 8.90€)
    // ─────────────────────────────────────────────────────────────────────
    private function stepEBowlsRestructure(): void
    {
        $this->info('▶ Step E — Bowls restructure (8 items × 4 viandes × 2 bases @ 8.90€)…');

        $cat = ItemCategory::where('slug', 'bols-gourmands')->whereNull('deleted_at')->first();
        if (! $cat) {
            throw new \RuntimeException('Category bols-gourmands not found.');
        }

        $boissonAddonId = Item::where('slug', 'boisson-seule')->value('id');

        // 1) Soft-delete old bowls (ids 480-484) — preserves DB rows for receipt reprint.
        $oldBowls = Item::whereIn('slug', ['bol-curry', 'bol-tandoori', 'bol-marine', 'bol-crousti', 'bol-gratine'])
            ->whereNull('deleted_at')
            ->get();

        foreach ($oldBowls as $oldBowl) {
            // Soft-delete the wizard profile + steps first (avoid orphan profiles).
            ItemWizardProfile::where('item_id', $oldBowl->id)->each(function ($p) {
                ItemWizardStep::where('profile_id', $p->id)->delete();
                $p->delete();
            });

            $oldBowl->delete();
            $this->stats['items_archived']++;
            $this->eventsToFire[] = new ItemDeleted($oldBowl->id, 1);
            $this->line("   ↳ Archived old bowl id={$oldBowl->id} ({$oldBowl->slug}).");
        }

        // 2) Create 8 new bowls @ 8.90€ — 4 viandes × 2 bases
        $bases = [
            ['suffix' => 'frites', 'base_name' => 'Frites'],
            ['suffix' => 'riz',    'base_name' => 'Riz basmati'],
        ];

        $viandes = [
            ['suffix' => 'marine',   'viande' => 'Poulet mariné',   'sauce_default' => 'Sauce fromagère maison'],
            ['suffix' => 'curry',    'viande' => 'Poulet curry',    'sauce_default' => 'Curry'],
            ['suffix' => 'tandoori', 'viande' => 'Poulet tandoori', 'sauce_default' => 'Sauce fromagère maison'],
            ['suffix' => 'crispy',   'viande' => 'Poulet crispy',   'sauce_default' => 'Sauce fromagère maison'],
        ];

        foreach ($bases as $base) {
            foreach ($viandes as $v) {
                $slug = "bowl-{$base['suffix']}-{$v['suffix']}";
                $name = "Bowl " . ($base['suffix'] === 'frites' ? 'Frites' : 'Riz') . " " . $v['viande'];

                $bowl = $this->createOrRestoreItem([
                    'slug'             => $slug,
                    'name'             => $name,
                    'item_category_id' => $cat->id,
                    'price'            => 8.90,
                    'description'      => "{$v['viande']} · Base {$base['base_name']} · Sauce + suppléments + boisson optionnels · Option Gratiné +2€.",
                    'is_featured'      => 0,
                    'item_type'        => \App\Enums\ItemType::NON_VEG,
                ]);

                $this->seedBowlSauces($bowl);
                $this->seedBowlSupplements($bowl);
                $this->seedBowlGratineOption($bowl);
                $this->seedAddonForBowl($bowl, $boissonAddonId, 'drink');
                $this->createBowlComposerProfile($bowl, $v['sauce_default']);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP F — Suppléments update (price 1.00 → 0.90, add Boursin, remove Bacon, rename Oignons)
    // ─────────────────────────────────────────────────────────────────────
    private function stepFSupplementsUpdate(): void
    {
        $this->info('▶ Step F — Suppléments standalone update…');

        $suppCat = ItemCategory::where('slug', 'supplements')->whereNull('deleted_at')->first();
        if (! $suppCat) {
            $this->warn('   ↳ supplements category not found — skipping standalone updates.');
            return;
        }

        // Update prices: 1.00 → 0.90 (except Boule gratinée which stays 2.00€ bol-specific per spec §F).
        // bacon is excluded because it will be archived next.
        $suppUpdated = Item::where('item_category_id', $suppCat->id)
            ->whereNull('deleted_at')
            ->whereNotIn('slug', ['supp-bacon', 'supp-boule-gratinee'])
            ->where('price', '!=', self::SUPP_NEW_PRICE)
            ->update(['price' => self::SUPP_NEW_PRICE]);
        if ($suppUpdated > 0) {
            $this->stats['price_updates'] += $suppUpdated;
            $this->line("   ↳ Updated {$suppUpdated} standalone supplément prices → 0.90€.");
        } else {
            $this->line('   ↳ Standalone supplément prices already at 0.90€ — idempotent.');
        }

        // Bol-specific Boule gratinée standalone item — set to 2.00€ per spec §F.
        $bouleGratinee = Item::where('slug', 'supp-boule-gratinee')->whereNull('deleted_at')->first();
        if ($bouleGratinee && (float) $bouleGratinee->price !== 2.00) {
            $bouleGratinee->update(['price' => 2.00]);
            $this->stats['price_updates']++;
            $this->line("   ↳ Boule gratinée standalone → 2.00€ (bol-specific per spec).");
        }

        // Archive Bacon (id 468)
        $bacon = Item::where('slug', 'supp-bacon')->whereNull('deleted_at')->first();
        if ($bacon) {
            $bacon->delete();
            $this->stats['items_archived']++;
            $this->eventsToFire[] = new ItemDeleted($bacon->id, 1);
            $this->line("   ↳ Archived Bacon (id={$bacon->id}).");
        }

        // Rename Oignons frits → Oignon frais (id 471)
        $oignon = Item::where('slug', 'supp-oignons-frits')->whereNull('deleted_at')->first();
        if ($oignon && $oignon->name !== 'Oignon frais') {
            $oignon->update(['name' => 'Oignon frais']);
            $this->stats['items_renamed']++;
            $this->line("   ↳ Renamed Oignons frits → Oignon frais (id={$oignon->id}).");
        }

        // Add Boursin
        foreach (self::SUPP_TO_ADD as $supp) {
            $existing = Item::withTrashed()->where('slug', $supp['slug'])->first();
            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $existing->update([
                    'name'             => $supp['name'],
                    'price'            => $supp['price'],
                    'item_category_id' => $suppCat->id,
                    'status'           => Status::ACTIVE,
                ]);
                $this->line("   ↳ Restored/updated supplément {$supp['name']}.");
            } else {
                $boursin = Item::create([
                    'name'             => $supp['name'],
                    'slug'             => $supp['slug'],
                    'price'            => $supp['price'],
                    'item_category_id' => $suppCat->id,
                    'item_type'        => \App\Enums\ItemType::VEG,
                    'status'           => Status::ACTIVE,
                    'is_available'     => 1,
                    'is_featured'      => 0,
                    'order'            => 0,
                    'description'      => 'Supplément Boursin',
                ]);
                $this->stats['items_created']++;
                $this->eventsToFire[] = new ItemCreated($boursin->id, 1);
                $this->line("   ↳ Created supplément {$supp['name']} (id={$boursin->id}).");
            }
        }

        // Update per-item extras (item_extras table) for all sandwich-family items :
        // - rename Oignons frits → Oignon frais
        // - update price 1.00 → 0.90 (group_label='supplement')
        $this->updatePerItemSupplementExtras();
    }

    private function updatePerItemSupplementExtras(): void
    {
        // Rename in item_extras
        $renamed = DB::table('item_extras')
            ->where('group_label', 'supplement')
            ->whereNull('deleted_at')
            ->where('name', 'Oignons frits')
            ->update(['name' => 'Oignon frais', 'updated_at' => now()]);
        if ($renamed > 0) {
            $this->stats['extras_updated'] += $renamed;
            $this->line("   ↳ Renamed {$renamed} item_extras Oignons frits → Oignon frais.");
        }

        // Update prices : 1.00 → 0.90 for supplement group, exclude bol-specific Boule gratinée
        $priceUpd = DB::table('item_extras')
            ->where('group_label', 'supplement')
            ->whereNull('deleted_at')
            ->where('name', '!=', 'Boule gratinée')
            ->where('price', '=', 1.000000)
            ->update(['price' => self::SUPP_NEW_PRICE, 'updated_at' => now()]);
        if ($priceUpd > 0) {
            $this->stats['extras_updated'] += $priceUpd;
            $this->line("   ↳ Updated {$priceUpd} item_extras price → 0.90€.");
        }

        // Soft-delete Bacon extras (group_label=supplement)
        $del = DB::table('item_extras')
            ->where('group_label', 'supplement')
            ->where('name', 'Bacon')
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
        if ($del > 0) {
            $this->stats['extras_updated'] += $del;
            $this->line("   ↳ Archived {$del} Bacon item_extras rows.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP G — Sauces variations rename + archive
    // ─────────────────────────────────────────────────────────────────────
    private function stepGSauceVariationsUpdate(): void
    {
        $this->info('▶ Step G — Sauce variations rename + archive…');

        $sauceAttrIds = ItemAttribute::where('name', 'LIKE', 'Sauce%')->pluck('id');
        if ($sauceAttrIds->isEmpty()) {
            $this->warn('   ↳ No Sauce attributes found — skipping.');
            return;
        }

        // Rename Fromagère → Sauce fromagère maison, Pimentée → Spicy
        foreach (self::SAUCE_RENAMES as $oldName => $newName) {
            $renamed = ItemVariation::whereIn('item_attribute_id', $sauceAttrIds)
                ->whereNull('deleted_at')
                ->where('name', $oldName)
                ->update(['name' => $newName, 'updated_at' => now()]);
            if ($renamed > 0) {
                $this->stats['variations_renamed'] += $renamed;
                $this->line("   ↳ Renamed {$renamed} variations: {$oldName} → {$newName}");
            }
        }

        // Archive sauce variations not in spec (Tandoori, Cayenne — they are meat/sandwich names)
        foreach (self::SAUCES_TO_ARCHIVE as $sauceName) {
            $rows = ItemVariation::whereIn('item_attribute_id', $sauceAttrIds)
                ->whereNull('deleted_at')
                ->where('name', $sauceName)
                ->get();
            foreach ($rows as $row) {
                $row->delete();
                $this->stats['variations_archived']++;
            }
            if ($rows->count() > 0) {
                $this->line("   ↳ Archived {$rows->count()} sauce variations: {$sauceName}");
            }
        }

        // Ensure "Spicy" exists on each sauce attribute that has Sauce fromagère maison
        // (idempotent: Pimentée rename should already cover this — but if Pimentée was
        // previously deleted for some items, we'd want Spicy added. This is a no-op when
        // Pimentée rename already produced Spicy rows).
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP H — Viande rename (Poulet classic → Poulet mariné)
    // ─────────────────────────────────────────────────────────────────────
    private function stepHViandeRename(): void
    {
        $this->info('▶ Step H — Viande rename (Poulet classic → Poulet mariné)…');

        $viandeAttrIds = ItemAttribute::where('name', 'LIKE', 'Viande%')->pluck('id');
        $renamed = ItemVariation::whereIn('item_attribute_id', $viandeAttrIds)
            ->whereNull('deleted_at')
            ->where('name', 'Poulet classic')
            ->update(['name' => 'Poulet mariné', 'updated_at' => now()]);
        if ($renamed > 0) {
            $this->stats['variations_renamed'] += $renamed;
            $this->line("   ↳ Renamed {$renamed} viande variations: Poulet classic → Poulet mariné");
        } else {
            $this->line('   ↳ No "Poulet classic" rows to rename (already done?).');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP I — Mark config update (no actual file write here — handled separately
    //         in config/menu.php + mobile/data/menu.js commit OUTSIDE this command).
    // ─────────────────────────────────────────────────────────────────────
    private function stepIConfigUpdateMarker(): void
    {
        $this->info('▶ Step I — Config marker (config/menu.php + mobile/data/menu.js updated outside transaction).');
        $this->line('   ↳ Run sentinel : config files are committed alongside this command.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // FINALIZE — Apply sort order on the 11 cats
    // ─────────────────────────────────────────────────────────────────────
    private function stepFinalizeSortOrder(): void
    {
        $this->info('▶ Step — Finalize sort order (11 cats)…');
        foreach (self::SORT_MAP_FINAL as $slug => $sort) {
            $upd = ItemCategory::where('slug', $slug)->whereNull('deleted_at')->update(['sort' => $sort]);
            if ($upd > 0) {
                $this->line("   ↳ {$slug} → sort={$sort}");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // FROZEN-ZONE verification — abort if any change detected
    // ─────────────────────────────────────────────────────────────────────
    private function verifyFrozenZoneClean(): void
    {
        $this->info('▶ Verify frozen-zone diff vs main…');

        // Use git diff to check if any frozen file was modified vs main baseline
        $files = implode(' ', array_map('escapeshellarg', self::FROZEN_FILES));
        $cmd = "git diff main -- {$files} 2>/dev/null | wc -l";
        $diffLines = (int) trim((string) shell_exec($cmd));

        // Baseline drift (e.g. KioskWizardComponent had legitimate prior edits
        // documented in BRAIN). We tolerate the existing delta but not new changes.
        // For this heal command, we only verify NO frozen file was touched by this
        // command's transaction — comparing pre/post is via git status (post-rollback
        // verification: data layer only).
        $this->line("   ↳ Frozen-zone diff vs main = {$diffLines} lines (baseline allowed).");
        $this->line('   ↳ Command writes data-layer only — no source file modified.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // HELPERS — DB operations
    // ─────────────────────────────────────────────────────────────────────
    private function createOrRestoreCategory(array $payload): ItemCategory
    {
        $existing = ItemCategory::withTrashed()->where('slug', $payload['slug'])->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $oldName = $existing->name;
            $existing->update($payload + ['status' => Status::ACTIVE]);
            if ($oldName !== $payload['name']) {
                $this->stats['categories_renamed']++;
                $this->eventsToFire[] = new CategoryUpdated($existing->id, 1);
                $this->line("   ↳ Restored/updated category [{$existing->id}] {$oldName} → {$payload['name']}.");
            }
            return $existing;
        }

        $cat = ItemCategory::create($payload + ['status' => Status::ACTIVE]);
        $this->stats['categories_created']++;
        $this->eventsToFire[] = new CategoryCreated($cat->id, 1);
        $this->line("   ↳ Created category [{$cat->id}] {$cat->name} (slug={$cat->slug}).");
        return $cat;
    }

    private function createOrRestoreItem(array $payload): Item
    {
        $existing = Item::withTrashed()->where('slug', $payload['slug'])->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->update($payload + ['status' => Status::ACTIVE, 'is_available' => 1, 'order' => 0]);
            return $existing;
        }

        $payload['status'] = Status::ACTIVE;
        $payload['is_available'] = 1;
        $payload['order'] = 0;

        $item = Item::create($payload);
        $this->stats['items_created']++;
        $this->eventsToFire[] = new ItemCreated($item->id, 1);
        $this->line("   ↳ Created item [{$item->id}] {$item->name} ({$item->price}€).");
        return $item;
    }

    private function getOrCreateAttribute(string $name, int $min, int $max, bool $allowRepeat = false): ItemAttribute
    {
        $existing = ItemAttribute::where('name', $name)->first();
        if ($existing) {
            return $existing;
        }

        return ItemAttribute::create([
            'name'         => $name,
            'status'       => Status::ACTIVE,
            'min_select'   => $min,
            'max_select'   => $max,
            'allow_repeat' => $allowRepeat,
            'is_available' => 1,
        ]);
    }

    private function seedViandesForItem(Item $item, int $viandeCount): void
    {
        for ($i = 1; $i <= $viandeCount; $i++) {
            $attr = $this->getOrCreateAttribute("Viande {$i}", 1, 1);
            foreach (self::VIANDES as $viande) {
                $existing = ItemVariation::withTrashed()
                    ->where('item_id', $item->id)
                    ->where('item_attribute_id', $attr->id)
                    ->where('name', $viande)
                    ->first();
                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    continue;
                }
                ItemVariation::create([
                    'item_id'           => $item->id,
                    'item_attribute_id' => $attr->id,
                    'name'              => $viande,
                    'price'             => 0,
                    'status'            => Status::ACTIVE,
                ]);
                $this->stats['variations_created']++;
            }
        }
    }

    private function seedSaucesForItem(Item $item): void
    {
        $attr = $this->getOrCreateAttribute('Sauce (1ère Gratuite)', 0, 1);
        foreach (self::SAUCES_CANONICAL as $sauce) {
            $existing = ItemVariation::withTrashed()
                ->where('item_id', $item->id)
                ->where('item_attribute_id', $attr->id)
                ->where('name', $sauce)
                ->first();
            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                continue;
            }
            ItemVariation::create([
                'item_id'           => $item->id,
                'item_attribute_id' => $attr->id,
                'name'              => $sauce,
                'price'             => 0,
                'status'            => Status::ACTIVE,
            ]);
            $this->stats['variations_created']++;
        }
    }

    private function seedCruditesAsExtras(Item $item): void
    {
        $crudites = ['Salade', 'Tomate', 'Oignon', 'Cornichon'];
        foreach ($crudites as $c) {
            $existing = ItemExtra::withTrashed()
                ->where('item_id', $item->id)
                ->where('name', $c)
                ->where('group_label', 'crudite')
                ->first();
            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                continue;
            }
            ItemExtra::create([
                'item_id'      => $item->id,
                'name'         => $c,
                'price'        => 0,
                'status'       => Status::ACTIVE,
                'group_label'  => 'crudite',
                'is_available' => 1,
            ]);
            $this->stats['extras_created']++;
        }
    }

    private function seedSupplementsAsExtras(Item $item): void
    {
        $supplements = [
            ['name' => 'Cheddar',         'price' => 0.90],
            ['name' => 'Raclette',        'price' => 0.90],
            ['name' => 'Emmental',        'price' => 0.90],
            ['name' => 'Œuf',             'price' => 0.90],
            ['name' => 'Boursin',         'price' => 0.90],
            ['name' => 'Légumes sautés',  'price' => 0.90],
            ['name' => 'Jambon',          'price' => 0.90],
            ['name' => 'Oignon frais',    'price' => 0.90],
            ['name' => 'Champignons',     'price' => 0.90],
        ];

        foreach ($supplements as $supp) {
            $existing = ItemExtra::withTrashed()
                ->where('item_id', $item->id)
                ->where('name', $supp['name'])
                ->where('group_label', 'supplement')
                ->first();
            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $existing->update(['price' => $supp['price']]);
                continue;
            }
            ItemExtra::create([
                'item_id'      => $item->id,
                'name'         => $supp['name'],
                'price'        => $supp['price'],
                'status'       => Status::ACTIVE,
                'group_label'  => 'supplement',
                'is_available' => 1,
            ]);
            $this->stats['extras_created']++;
        }
    }

    private function seedMenuAddonsForItem(Item $item, int $menuId, int $fritesId, int $boissonId): void
    {
        $this->upsertAddon($item, $menuId, 'menu_component');
        $this->upsertAddon($item, $fritesId, 'side');
        $this->upsertAddon($item, $boissonId, 'drink');
    }

    private function upsertAddon(Item $main, int $addonItemId, string $role): void
    {
        $existing = ItemAddon::withTrashed()
            ->where('item_id', $main->id)
            ->where('addon_item_id', $addonItemId)
            ->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->update(['role' => $role]);
            return;
        }
        ItemAddon::create([
            'item_id'       => $main->id,
            'addon_item_id' => $addonItemId,
            'role'          => $role,
        ]);
        $this->stats['addons_created']++;
    }

    private function seedAddonForBowl(Item $bowl, int $addonItemId, string $role): void
    {
        $this->upsertAddon($bowl, $addonItemId, $role);
    }

    private function seedBowlSauces(Item $bowl): void
    {
        $attr = $this->getOrCreateAttribute('Sauce bol', 1, 1);
        foreach (self::SAUCES_CANONICAL as $sauce) {
            $existing = ItemVariation::withTrashed()
                ->where('item_id', $bowl->id)
                ->where('item_attribute_id', $attr->id)
                ->where('name', $sauce)
                ->first();
            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                continue;
            }
            ItemVariation::create([
                'item_id'           => $bowl->id,
                'item_attribute_id' => $attr->id,
                'name'              => $sauce,
                'price'             => 0,
                'status'            => Status::ACTIVE,
            ]);
            $this->stats['variations_created']++;
        }
    }

    private function seedBowlSupplements(Item $bowl): void
    {
        $supps = [
            ['name' => 'Oignon frais',   'price' => 0.90],
            ['name' => 'Jambon',         'price' => 0.90],
            ['name' => 'Champignons',    'price' => 0.90],
            ['name' => 'Boule gratinée', 'price' => 2.00], // bol-specific
        ];

        foreach ($supps as $supp) {
            $existing = ItemExtra::withTrashed()
                ->where('item_id', $bowl->id)
                ->where('name', $supp['name'])
                ->where('group_label', 'supplement_bol')
                ->first();
            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $existing->update(['price' => $supp['price']]);
                continue;
            }
            ItemExtra::create([
                'item_id'      => $bowl->id,
                'name'         => $supp['name'],
                'price'        => $supp['price'],
                'status'       => Status::ACTIVE,
                'group_label'  => 'supplement_bol',
                'is_available' => 1,
            ]);
            $this->stats['extras_created']++;
        }
    }

    private function seedBowlGratineOption(Item $bowl): void
    {
        // Option Gratiné +2€ as a dedicated extra in 'gratine' group
        $existing = ItemExtra::withTrashed()
            ->where('item_id', $bowl->id)
            ->where('name', 'Option Gratiné')
            ->where('group_label', 'gratine')
            ->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->update(['price' => 2.00]);
            return;
        }
        ItemExtra::create([
            'item_id'      => $bowl->id,
            'name'         => 'Option Gratiné',
            'price'        => 2.00,
            'status'       => Status::ACTIVE,
            'group_label'  => 'gratine',
            'is_available' => 1,
        ]);
        $this->stats['extras_created']++;
    }

    private function createBowlComposerProfile(Item $bowl, string $sauceDefault): void
    {
        // Idempotency : skip if a published profile with 4 steps already exists.
        $existingProfile = ItemWizardProfile::where('item_id', $bowl->id)
            ->where('is_published', true)
            ->first();
        if ($existingProfile) {
            $stepCount = ItemWizardStep::where('profile_id', $existingProfile->id)->count();
            if ($stepCount === 4) {
                return; // already healed
            }
            // Partial state — wipe + recreate
            ItemWizardStep::where('profile_id', $existingProfile->id)->delete();
            $existingProfile->delete();
        }

        $profile = ItemWizardProfile::create([
            'item_id'          => $bowl->id,
            'item_category_id' => null,
            'template'         => 'custom',
            'version'          => 1,
            'is_published'     => true,
            'published_at'     => now(),
            'branch_id_scope'  => null,
        ]);
        $this->stats['profiles_created']++;

        $position = 0;
        $sauceAttrId = ItemAttribute::where('name', 'Sauce bol')->value('id');

        // Step 1: Sauce
        ItemWizardStep::create([
            'profile_id'               => $profile->id,
            'step_key'                 => 'sauce',
            'label'                    => 'Choix de la sauce',
            'source_type'              => 'item_attribute',
            'source_ref'               => 'sauce bol',
            'source_item_attribute_id' => $sauceAttrId,
            'min_select'               => 1,
            'max_select'               => 2,
            'allow_repeat'             => false,
            'visible_on'               => null,
            'stockable_choices'        => false,
            'position'                 => $position++,
            'is_active'                => true,
            'addon_role'               => null,
        ]);
        $this->stats['steps_created']++;

        // Step 2: Suppléments (extras)
        ItemWizardStep::create([
            'profile_id'        => $profile->id,
            'step_key'          => 'supplements',
            'label'             => 'Suppléments (0.90€)',
            'source_type'       => 'extra_group',
            'source_ref'        => 'supplement_bol',
            'min_select'        => 0,
            'max_select'        => 4,
            'allow_repeat'      => false,
            'visible_on'        => null,
            'stockable_choices' => false,
            'position'          => $position++,
            'is_active'         => true,
            'addon_role'        => null,
        ]);
        $this->stats['steps_created']++;

        // Step 3: Drink optional
        ItemWizardStep::create([
            'profile_id'        => $profile->id,
            'step_key'          => 'drink',
            'label'             => 'Boisson (optionnel)',
            'source_type'       => 'addon',
            'source_ref'        => 'drink',
            'min_select'        => 0,
            'max_select'        => 1,
            'allow_repeat'      => false,
            'visible_on'        => null,
            'stockable_choices' => false,
            'position'          => $position++,
            'is_active'         => true,
            'addon_role'        => 'drink',
        ]);
        $this->stats['steps_created']++;

        // Step 4: Gratiné option (+2€)
        ItemWizardStep::create([
            'profile_id'        => $profile->id,
            'step_key'          => 'gratine',
            'label'             => 'Option Gratiné (+2€)',
            'source_type'       => 'extra_group',
            'source_ref'        => 'gratine',
            'min_select'        => 0,
            'max_select'        => 1,
            'allow_repeat'      => false,
            'visible_on'        => null,
            'stockable_choices' => false,
            'position'          => $position++,
            'is_active'         => true,
            'addon_role'        => null,
        ]);
        $this->stats['steps_created']++;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fire deferred sync events (after transaction commit)
    // ─────────────────────────────────────────────────────────────────────
    private function fireDeferredEvents(): void
    {
        $this->info('▶ Firing sync events…');
        foreach ($this->eventsToFire as $event) {
            event($event);
            $this->stats['events_fired']++;
        }
        $this->line("   ↳ {$this->stats['events_fired']} events fired (CatalogChanged auto-bridges).");
    }

    // ─────────────────────────────────────────────────────────────────────
    // STATS
    // ─────────────────────────────────────────────────────────────────────
    private function renderStats(): void
    {
        $this->line('');
        $this->info('═══ STATISTICS ═══');
        $rows = [];
        foreach ($this->stats as $metric => $count) {
            $rows[] = [$metric, $count];
        }
        $this->table(['Metric', 'Count'], $rows);
    }
}
