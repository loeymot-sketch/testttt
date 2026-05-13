<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Events\CategoryCreated;
use App\Events\CategoryDeleted;
use App\Events\CategoryUpdated;
use App\Models\DeletionLog;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MenuResetLeCayenneCommand extends Command
{
    protected $signature = 'menu:reset-le-cayenne
                            {--dry-run : Show what would be done, no DB writes}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Archive 8 old categories + rename 4 kept + create 5 new (Le Cayenne 2026-05-13 spec)';

    private const ARCHIVE_SLUGS = [
        'nos-sandwichs', 'nos-burgers', 'nos-assiettes',
        'ojja', 'omelettes', 'nos-salades', 'chicken-tenders', 'nos-menus-enfants',
    ];

    private const RENAMES = [
        'nos-tacos'    => ['slug' => 'tacos',       'name' => 'Tacos',       'sort' => 4],
        'nos-desserts' => ['slug' => 'desserts',    'name' => 'Desserts',    'sort' => 8],
        'nos-boissons' => ['slug' => 'boissons',    'name' => 'Boissons',    'sort' => 9],
        'supplements'  => ['slug' => 'supplements', 'name' => 'Suppléments', 'sort' => 7],
    ];

    private const NEW_CATEGORIES = [
        ['slug' => 'sandwich-cayenne',   'name' => 'Sandwich Cayenne',   'wizard_template' => 'sandwich', 'has_menu' => true,  'sort' => 1],
        ['slug' => 'galette',            'name' => 'Galette',            'wizard_template' => 'sandwich', 'has_menu' => true,  'sort' => 2],
        ['slug' => 'sandwich-classique', 'name' => 'Sandwich Classique', 'wizard_template' => 'sandwich', 'has_menu' => true,  'sort' => 3],
        ['slug' => 'bols-gourmands',     'name' => 'Bols Gourmands',     'wizard_template' => 'custom',   'has_menu' => false, 'sort' => 5],
        ['slug' => 'frites',             'name' => 'Frites',             'wizard_template' => 'custom',   'has_menu' => false, 'sort' => 6],
    ];

    private const VIANDES = ['Poulet classic', 'Poulet curry', 'Poulet tandoori', 'Poulet crispy'];

    private const SAUCES = [
        'Mayonnaise', 'Ketchup', 'Algérienne', 'Samouraï', 'Curry', 'Andalouse',
        'Harissa', 'Hannibal', 'Blanche', 'Tandoori', 'Fromagère', 'Pimentée', 'Cayenne',
    ];

    private const CRUDITES = ['Salade', 'Tomate', 'Oignon', 'Cornichon'];

    private const SUPPLEMENTS = [
        ['name' => 'Cheddar',         'price' => 1.0],
        ['name' => 'Raclette',        'price' => 1.0],
        ['name' => 'Emmental',        'price' => 1.0],
        ['name' => 'Œuf',             'price' => 1.0],
        ['name' => 'Bacon',           'price' => 1.0],
        ['name' => 'Légumes sautés',  'price' => 1.0],
        ['name' => 'Jambon',          'price' => 1.0],
        ['name' => 'Oignons frits',   'price' => 1.0],
        ['name' => 'Champignons',     'price' => 1.0],
        ['name' => 'Boule gratinée',  'price' => 1.0],
    ];

    private array $stats = [
        'archived_categories' => 0,
        'archived_items'      => 0,
        'archived_variations' => 0,
        'archived_extras'     => 0,
        'renamed_categories'  => 0,
        'created_categories'  => 0,
        'created_items'       => 0,
        'created_variations'  => 0,
        'created_extras'      => 0,
        'created_addons'      => 0,
        'created_attributes'  => 0,
        'created_profiles'    => 0,
        'created_steps'       => 0,
        'events_fired'        => 0,
    ];

    private array $eventsToFire = [];

    public function handle(): int
    {
        $this->line('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('  MENU RESET LE CAYENNE 2026-05-13');
        $this->info('  Archive 8 cats + rename 4 + create 5 new + composer profiles');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->line('');

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('🔬 DRY-RUN MODE — no DB writes, no events.');
            $this->line('');
        }

        if (!$this->option('force') && !$dryRun) {
            $this->warn('⚠️  This will :');
            $this->line('  - Soft-delete 8 categories + ~35 items + 9 viandes + 6 sauces');
            $this->line('  - Rename 4 kept categories (Tacos/Desserts/Boissons/Suppléments)');
            $this->line('  - Create 5 new categories (Cayenne/Galette/Classique/Bols/Frites)');
            $this->line('  - Create composer_profiles for 5 bols + 2 frites items');
            $this->line('  - Fire CategoryCreated/Updated/Deleted events for sync');
            $this->line('');
            if (!$this->confirm('Proceed?')) {
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
                $this->step1ArchiveOldCategoriesAndItems();
                $this->step2RenameKeptCategories();
                $this->step3CreateNewCategories();
                $this->step4ArchiveOldViandes();
                $this->step5SeedNewViandes();
                $this->step6ArchiveOldSauces();
                $this->step7SeedNewSauces();
                $this->step8SeedSupplementsCatalog();
                $this->step9CreateNewItems();
                $this->step10CreateBolsComposerProfiles();
                $this->step11CreateFritesComposerProfile();
                $this->step12FinalizeSortOrder();
            });

            $this->fireDeferredEvents();
            $this->renderStats();
            $this->line('');
            $this->info('✅ Menu reset complete.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('❌ FAILED: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile() . ':' . $e->getLine());
            Log::error('MenuResetLeCayenneCommand failed', ['exception' => $e]);
            return self::FAILURE;
        }
    }

    private function preflightChecks(): void
    {
        $current = ItemCategory::whereNull('deleted_at')->count();
        $this->info("Pre-flight : {$current} active categories detected.");
        if ($current === 0) {
            throw new \RuntimeException('No active categories — DB empty? Aborting.');
        }

        $branchCount = DB::table('branches')->count();
        $this->info("Pre-flight : {$branchCount} branch(es) detected.");
    }

    private function dryRunPlan(): void
    {
        $this->info('📋 DRY-RUN plan :');
        $this->line('');

        $this->line('1. Archive (soft-delete) categories :');
        foreach (self::ARCHIVE_SLUGS as $slug) {
            $cat = ItemCategory::where('slug', $slug)->first();
            if ($cat) {
                $itemCount = $cat->items()->count();
                $this->line("   - [{$cat->id}] {$cat->name} (slug={$slug}) + {$itemCount} items");
            } else {
                $this->warn("   - {$slug} NOT FOUND (already archived?)");
            }
        }

        $this->line('');
        $this->line('2. Rename categories :');
        foreach (self::RENAMES as $oldSlug => $new) {
            $cat = ItemCategory::where('slug', $oldSlug)->first();
            if ($cat) {
                $this->line("   - [{$cat->id}] {$cat->name} → {$new['name']} (slug={$new['slug']})");
            } else {
                $this->warn("   - {$oldSlug} NOT FOUND");
            }
        }

        $this->line('');
        $this->line('3. Create new categories :');
        foreach (self::NEW_CATEGORIES as $cat) {
            $exists = ItemCategory::where('slug', $cat['slug'])->exists();
            $this->line("   - {$cat['name']} (slug={$cat['slug']}, template={$cat['wizard_template']}, has_menu=" . ($cat['has_menu'] ? 'true' : 'false') . ')' . ($exists ? ' [EXISTS]' : ''));
        }

        $this->line('');
        $this->line('4. New variations / sauces / supplements seed planned :');
        $this->line('   - ' . count(self::VIANDES) . ' viandes (Poulet classic/curry/tandoori/crispy)');
        $this->line('   - ' . count(self::SAUCES) . ' sauces');
        $this->line('   - ' . count(self::SUPPLEMENTS) . ' suppléments');

        $this->line('');
        $this->line('5. New items to create per category :');
        $this->line('   - Sandwich Cayenne   : 1 item (Sandwich Cayenne 7.00€, sauce locked)');
        $this->line('   - Galette            : 2 items (Galette Normale 6.50€ sauce libre + Galette Cayenne 7.00€ sauce locked)');
        $this->line('   - Sandwich Classique : 1 item (Sandwich Classique pain faluche 6.50€)');
        $this->line('   - Tacos              : 2 items (Tacos 1 viande 8.50€ + Big Tacos 2 viandes 11.50€)');
        $this->line('   - Bols Gourmands     : 5 items (Curry/Tandoori/Mariné/Crousti 10.50€ + Gratiné 12.50€)');
        $this->line('   - Frites             : 2 items (Petite 2.50€ + Grande 4.00€)');

        $this->line('');
        $this->line('6. Composer profiles to create :');
        $this->line('   - 5 profiles for bols (steps: base, sauce, supplements, drink)');
        $this->line('   - 2 profiles for frites (step: style upgrade)');

        $this->line('');
        $this->info('DRY-RUN — no changes persisted. Run without --dry-run to execute.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 1 — Archive 8 old categories + their items (soft-delete)
    // ─────────────────────────────────────────────────────────────────────
    private function step1ArchiveOldCategoriesAndItems(): void
    {
        $this->info('▶ Step 1 — Archive old categories + items…');
        foreach (self::ARCHIVE_SLUGS as $slug) {
            $cat = ItemCategory::where('slug', $slug)->whereNull('deleted_at')->first();
            if (!$cat) {
                $this->warn("   ↳ {$slug} : already archived or not found, skipping.");
                continue;
            }

            $items = $cat->items()->get();
            foreach ($items as $item) {
                $item->delete();
                $this->stats['archived_items']++;
            }

            $cat->delete();
            $this->stats['archived_categories']++;
            $this->eventsToFire[] = new CategoryDeleted($cat->id, null);

            DeletionLog::create([
                'model_type' => ItemCategory::class,
                'model_id'   => $cat->id,
                'actor_type' => 'console',
                'reason'     => 'menu:reset-le-cayenne 2026-05-13 owner-gated archive',
                'deleted_at' => now(),
            ]);

            $this->line("   ↳ Archived [{$cat->id}] {$cat->name} + " . count($items) . ' items');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 2 — Rename 4 kept categories
    // ─────────────────────────────────────────────────────────────────────
    private function step2RenameKeptCategories(): void
    {
        $this->info('▶ Step 2 — Rename kept categories…');
        foreach (self::RENAMES as $oldSlug => $new) {
            $cat = ItemCategory::where('slug', $oldSlug)->first();
            if (!$cat) {
                $this->warn("   ↳ {$oldSlug} not found — skipping.");
                continue;
            }
            $oldName = $cat->name;
            $cat->update([
                'name' => $new['name'],
                'slug' => $new['slug'],
                'sort' => $new['sort'],
            ]);
            $this->stats['renamed_categories']++;
            $this->eventsToFire[] = new CategoryUpdated($cat->id, null);
            $this->line("   ↳ [{$cat->id}] {$oldName} → {$new['name']} (slug={$new['slug']}, sort={$new['sort']})");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 3 — Create 5 new categories
    // ─────────────────────────────────────────────────────────────────────
    private function step3CreateNewCategories(): void
    {
        $this->info('▶ Step 3 — Create new categories…');
        foreach (self::NEW_CATEGORIES as $payload) {
            $existing = ItemCategory::where('slug', $payload['slug'])->first();
            if ($existing) {
                $existing->restore();
                $existing->update($payload + ['status' => Status::ACTIVE]);
                $this->line("   ↳ [{$existing->id}] {$payload['name']} already existed — restored + updated.");
                $this->stats['created_categories']++;
                $this->eventsToFire[] = new CategoryUpdated($existing->id, null);
                continue;
            }
            $cat = ItemCategory::create($payload + ['status' => Status::ACTIVE]);
            $this->stats['created_categories']++;
            $this->eventsToFire[] = new CategoryCreated($cat->id, null);
            $this->line("   ↳ Created [{$cat->id}] {$cat->name} (slug={$cat->slug})");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 4 — Archive all old ItemVariations under Viande attributes (307/308/309/310)
    // ─────────────────────────────────────────────────────────────────────
    private function step4ArchiveOldViandes(): void
    {
        $this->info('▶ Step 4 — Archive old viandes variations…');
        $viandeAttributeIds = ItemAttribute::where('name', 'LIKE', 'Viande%')->pluck('id');
        $archived = ItemVariation::whereIn('item_attribute_id', $viandeAttributeIds)
            ->whereNull('deleted_at')
            ->whereNotIn('name', self::VIANDES) // keep new viandes if any
            ->get();
        foreach ($archived as $v) {
            $v->delete();
            $this->stats['archived_variations']++;
        }
        $this->line("   ↳ Archived {$archived->count()} old viande variations.");
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 5 — Seed new viandes (4) on each Viande attribute that needs them
    // ─────────────────────────────────────────────────────────────────────
    private function step5SeedNewViandes(): void
    {
        $this->info('▶ Step 5 — Seed new viandes (4)…');
        // Viande variations are per-item (each item has its own variation rows).
        // We don't seed here at attribute-level — viandes will be created per-item in step9.
        // This step just confirms the 4 viandes are the canonical pool.
        $this->line('   ↳ Canonical viandes: ' . implode(', ', self::VIANDES));
        $this->line('   ↳ (per-item viande variations will be created in step 9)');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 6 — Archive old sauces (variations under Sauce attribute 311)
    // ─────────────────────────────────────────────────────────────────────
    private function step6ArchiveOldSauces(): void
    {
        $this->info('▶ Step 6 — Archive old sauces variations not in new canonical list…');
        $sauceAttribute = ItemAttribute::where('name', 'LIKE', 'Sauce%')->first();
        if (!$sauceAttribute) {
            $this->warn('   ↳ No Sauce attribute found — skipping.');
            return;
        }
        $archived = ItemVariation::where('item_attribute_id', $sauceAttribute->id)
            ->whereNull('deleted_at')
            ->whereNotIn('name', self::SAUCES)
            ->get();
        foreach ($archived as $v) {
            $v->delete();
            $this->stats['archived_variations']++;
        }
        $this->line("   ↳ Archived {$archived->count()} obsolete sauces.");
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 7 — Seed new sauces (per-item — same pattern as viandes)
    // ─────────────────────────────────────────────────────────────────────
    private function step7SeedNewSauces(): void
    {
        $this->info('▶ Step 7 — Seed new sauces canonical pool…');
        $this->line('   ↳ Canonical sauces: ' . implode(', ', self::SAUCES));
        $this->line('   ↳ (per-item sauce variations will be created in step 9)');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 8 — Archive old standalone supplement items (cat=318) + reseed
    // ─────────────────────────────────────────────────────────────────────
    private function step8SeedSupplementsCatalog(): void
    {
        $this->info('▶ Step 8 — Reseed standalone supplements catalog (cat supplements)…');
        $suppCat = ItemCategory::where('slug', 'supplements')->first();
        if (!$suppCat) {
            $this->warn('   ↳ supplements cat not found — skipping.');
            return;
        }
        // Archive old standalone supplements items
        $oldItems = Item::where('item_category_id', $suppCat->id)->whereNull('deleted_at')->get();
        foreach ($oldItems as $item) {
            $item->delete();
            $this->stats['archived_items']++;
        }
        $this->line("   ↳ Archived {$oldItems->count()} old supplement items.");

        // Reseed 10 standalone supplement items
        foreach (self::SUPPLEMENTS as $supp) {
            $slug = 'supp-' . Str::slug($supp['name']);
            $existing = Item::where('slug', $slug)->first();
            if ($existing) {
                $existing->restore();
                $existing->update([
                    'name' => $supp['name'],
                    'price' => $supp['price'],
                    'status' => Status::ACTIVE,
                    'item_category_id' => $suppCat->id,
                ]);
            } else {
                Item::create([
                    'name'             => $supp['name'],
                    'slug'             => $slug,
                    'price'            => $supp['price'],
                    'status'           => Status::ACTIVE,
                    'item_category_id' => $suppCat->id,
                    'item_type'        => \App\Enums\ItemType::NON_VEG,
                    'description'      => '',
                    'is_featured'      => 0,
                    'is_available'     => 1,
                    'order'            => 0,
                ]);
                $this->stats['created_items']++;
            }
        }
        $this->line("   ↳ Seeded " . count(self::SUPPLEMENTS) . ' canonical supplements.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 9 — Create new items in new cats + their variations + extras + addons
    // ─────────────────────────────────────────────────────────────────────
    private function step9CreateNewItems(): void
    {
        $this->info('▶ Step 9 — Create new items + variations + extras + addons…');

        // Resolve cat IDs
        $cats = ItemCategory::whereIn('slug', [
            'sandwich-cayenne', 'galette', 'sandwich-classique', 'tacos',
            'bols-gourmands', 'frites',
        ])->whereNull('deleted_at')->get()->keyBy('slug');

        // Existing addon items (cat 315 hidden, items 360/361/362)
        $menuAddonId = Item::where('slug', 'menu-frites-boisson')->orWhere('name', 'Menu (Frites + Boisson)')->value('id')
            ?? Item::where('name', 'LIKE', 'Menu (Frites%')->value('id')
            ?? 360;
        $fritesAddonId = Item::where('slug', 'frites-seules')->orWhere('name', 'Frites Seules')->value('id') ?? 361;
        $boissonAddonId = Item::where('slug', 'boisson-seule')->orWhere('name', 'Boisson Seule')->value('id') ?? 362;

        // ── 9a Sandwich Cayenne (1 item, sauce locked Cayenne)
        if ($cat = $cats['sandwich-cayenne'] ?? null) {
            $item = $this->createOrRestoreItem([
                'slug'             => 'sandwich-cayenne-classique',
                'name'             => 'Sandwich Cayenne',
                'item_category_id' => $cat->id,
                'price'            => 7.00,
                'description'      => 'Sandwich signature avec sauce Cayenne maison. Choix de viande + crudités + suppléments.',
                'is_featured'      => 1,
                'item_type'        => \App\Enums\ItemType::NON_VEG,
            ]);
            $this->seedViandesForItem($item, 1);
            $this->seedCruditesAsExtras($item);
            $this->seedGenericSupplementsAsExtras($item);
            $this->seedMenuAddonsForItem($item, $menuAddonId, $fritesAddonId, $boissonAddonId);
        }

        // ── 9b Galette (2 items)
        if ($cat = $cats['galette'] ?? null) {
            $g1 = $this->createOrRestoreItem([
                'slug'             => 'galette-normale',
                'name'             => 'Galette Normale',
                'item_category_id' => $cat->id,
                'price'            => 6.50,
                'description'      => 'Galette traditionnelle. Sauce au choix parmi 13 sauces.',
                'is_featured'      => 0,
                'item_type'        => \App\Enums\ItemType::NON_VEG,
            ]);
            $this->seedViandesForItem($g1, 1);
            $this->seedSaucesForItem($g1);
            $this->seedCruditesAsExtras($g1);
            $this->seedGenericSupplementsAsExtras($g1);
            $this->seedMenuAddonsForItem($g1, $menuAddonId, $fritesAddonId, $boissonAddonId);

            $g2 = $this->createOrRestoreItem([
                'slug'             => 'galette-cayenne',
                'name'             => 'Galette Cayenne',
                'item_category_id' => $cat->id,
                'price'            => 7.00,
                'description'      => 'Galette signature avec sauce Cayenne maison incluse.',
                'is_featured'      => 1,
                'item_type'        => \App\Enums\ItemType::NON_VEG,
            ]);
            $this->seedViandesForItem($g2, 1);
            // No sauce variations — Cayenne locked included
            $this->seedCruditesAsExtras($g2);
            $this->seedGenericSupplementsAsExtras($g2);
            $this->seedMenuAddonsForItem($g2, $menuAddonId, $fritesAddonId, $boissonAddonId);
        }

        // ── 9c Sandwich Classique (1 item, pain faluche)
        if ($cat = $cats['sandwich-classique'] ?? null) {
            $item = $this->createOrRestoreItem([
                'slug'             => 'sandwich-classique-faluche',
                'name'             => 'Sandwich Classique',
                'item_category_id' => $cat->id,
                'price'            => 6.50,
                'description'      => 'Sandwich classique servi en pain faluche. Viande + crudités + sauce au choix.',
                'is_featured'      => 0,
                'item_type'        => \App\Enums\ItemType::NON_VEG,
            ]);
            $this->seedViandesForItem($item, 1);
            $this->seedSaucesForItem($item);
            $this->seedCruditesAsExtras($item);
            $this->seedGenericSupplementsAsExtras($item);
            $this->seedMenuAddonsForItem($item, $menuAddonId, $fritesAddonId, $boissonAddonId);
        }

        // ── 9d Tacos (2 items)
        if ($cat = $cats['tacos'] ?? null) {
            $t1 = $this->createOrRestoreItem([
                'slug'             => 'tacos-1-viande',
                'name'             => 'Tacos',
                'item_category_id' => $cat->id,
                'price'            => 8.50,
                'description'      => '1 viande au choix + frites maison + sauce fromagère maison.',
                'is_featured'      => 1,
                'item_type'        => \App\Enums\ItemType::NON_VEG,
            ]);
            $this->seedViandesForItem($t1, 1);
            $this->seedMenuAddonsForItem($t1, $menuAddonId, $fritesAddonId, $boissonAddonId);

            $t2 = $this->createOrRestoreItem([
                'slug'             => 'big-tacos-2-viandes',
                'name'             => 'Big Tacos',
                'item_category_id' => $cat->id,
                'price'            => 11.50,
                'description'      => '2 viandes au choix + frites maison + sauce fromagère maison.',
                'is_featured'      => 1,
                'item_type'        => \App\Enums\ItemType::NON_VEG,
            ]);
            $this->seedViandesForItem($t2, 2);
            $this->seedMenuAddonsForItem($t2, $menuAddonId, $fritesAddonId, $boissonAddonId);
        }

        // ── 9e Bols Gourmands (5 items)
        if ($cat = $cats['bols-gourmands'] ?? null) {
            $bols = [
                ['slug' => 'bol-curry',    'name' => 'Bol Curry',    'price' => 10.50, 'desc' => 'Poulet curry + sauce curry maison + base au choix.'],
                ['slug' => 'bol-tandoori', 'name' => 'Bol Tandoori', 'price' => 10.50, 'desc' => 'Poulet tandoori + sauce tandoori + base au choix.'],
                ['slug' => 'bol-marine',   'name' => 'Bol Mariné',   'price' => 10.50, 'desc' => 'Poulet mariné + sauce blanche maison + base au choix.'],
                ['slug' => 'bol-crousti',  'name' => 'Bol Crousti',  'price' => 10.50, 'desc' => 'Poulet crispy + sauce fromagère maison + base au choix.'],
                ['slug' => 'bol-gratine',  'name' => 'Bol Gratiné',  'price' => 12.50, 'desc' => 'Poulet mariné + sauce fromagère maison + boule gratinée incluse.'],
            ];
            foreach ($bols as $b) {
                $bol = $this->createOrRestoreItem([
                    'slug'             => $b['slug'],
                    'name'             => $b['name'],
                    'item_category_id' => $cat->id,
                    'price'            => $b['price'],
                    'description'      => $b['desc'],
                    'is_featured'      => 0,
                    'item_type'        => \App\Enums\ItemType::NON_VEG,
                ]);
                // Bols use custom composer profile (step10), no legacy variations needed.
                // But seed base attribute + supplements as data sources for the profile.
                $this->seedBolBaseAttribute($bol);
                $this->seedBolSupplements($bol);
                $this->seedBolBoissonAddon($bol, $boissonAddonId);
            }
        }

        // ── 9f Frites (2 items)
        if ($cat = $cats['frites'] ?? null) {
            $petite = $this->createOrRestoreItem([
                'slug'             => 'petite-frites',
                'name'             => 'Petite Frites',
                'item_category_id' => $cat->id,
                'price'            => 2.50,
                'description'      => 'Petites frites maison. Style nature ou avec cheddar/oignons.',
                'is_featured'      => 0,
                'item_type'        => \App\Enums\ItemType::VEG,
            ]);
            $this->seedFritesStyleAttribute($petite);

            $grande = $this->createOrRestoreItem([
                'slug'             => 'grande-frites',
                'name'             => 'Grande Frites',
                'item_category_id' => $cat->id,
                'price'            => 4.00,
                'description'      => 'Grandes frites maison. Style nature ou avec cheddar/oignons.',
                'is_featured'      => 0,
                'item_type'        => \App\Enums\ItemType::VEG,
            ]);
            $this->seedFritesStyleAttribute($grande);
        }
    }

    private function createOrRestoreItem(array $payload): Item
    {
        $existing = Item::withTrashed()->where('slug', $payload['slug'])->first();
        if ($existing) {
            if ($existing->trashed()) $existing->restore();
            $existing->update($payload + ['status' => Status::ACTIVE, 'is_available' => 1, 'order' => 0]);
            return $existing;
        }
        $payload['status'] = Status::ACTIVE;
        $payload['is_available'] = 1;
        $payload['order'] = 0;
        $this->stats['created_items']++;
        return Item::create($payload);
    }

    private function getOrCreateAttribute(string $name, int $min, int $max, bool $allowRepeat = false): ItemAttribute
    {
        $existing = ItemAttribute::where('name', $name)->first();
        if ($existing) return $existing;
        $this->stats['created_attributes']++;
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
                    if ($existing->trashed()) $existing->restore();
                    continue;
                }
                ItemVariation::create([
                    'item_id'           => $item->id,
                    'item_attribute_id' => $attr->id,
                    'name'              => $viande,
                    'price'             => 0,
                    'status'            => Status::ACTIVE,
                ]);
                $this->stats['created_variations']++;
            }
        }
    }

    private function seedSaucesForItem(Item $item): void
    {
        $attr = $this->getOrCreateAttribute('Sauce (1ère Gratuite)', 0, 1);
        foreach (self::SAUCES as $sauce) {
            $existing = ItemVariation::withTrashed()
                ->where('item_id', $item->id)
                ->where('item_attribute_id', $attr->id)
                ->where('name', $sauce)
                ->first();
            if ($existing) {
                if ($existing->trashed()) $existing->restore();
                continue;
            }
            ItemVariation::create([
                'item_id'           => $item->id,
                'item_attribute_id' => $attr->id,
                'name'              => $sauce,
                'price'             => 0,
                'status'            => Status::ACTIVE,
            ]);
            $this->stats['created_variations']++;
        }
    }

    private function seedCruditesAsExtras(Item $item): void
    {
        foreach (self::CRUDITES as $crudite) {
            $existing = ItemExtra::withTrashed()
                ->where('item_id', $item->id)
                ->where('name', $crudite)
                ->where('group_label', 'crudite')
                ->first();
            if ($existing) {
                if ($existing->trashed()) $existing->restore();
                continue;
            }
            ItemExtra::create([
                'item_id'         => $item->id,
                'name'            => $crudite,
                'price'           => 0,
                'status'          => Status::ACTIVE,
                'group_label'     => 'crudite',
                'is_available'    => 1,
            ]);
            $this->stats['created_extras']++;
        }
    }

    private function seedGenericSupplementsAsExtras(Item $item): void
    {
        foreach (self::SUPPLEMENTS as $supp) {
            $existing = ItemExtra::withTrashed()
                ->where('item_id', $item->id)
                ->where('name', $supp['name'])
                ->where('group_label', 'supplement')
                ->first();
            if ($existing) {
                if ($existing->trashed()) $existing->restore();
                $existing->update(['price' => $supp['price']]);
                continue;
            }
            ItemExtra::create([
                'item_id'         => $item->id,
                'name'            => $supp['name'],
                'price'           => $supp['price'],
                'status'          => Status::ACTIVE,
                'group_label'     => 'supplement',
                'is_available'    => 1,
            ]);
            $this->stats['created_extras']++;
        }
    }

    private function seedMenuAddonsForItem(Item $item, int $menuId, int $fritesId, int $boissonId): void
    {
        $this->upsertAddon($item, $menuId,    'menu_component');
        $this->upsertAddon($item, $fritesId,  'side');
        $this->upsertAddon($item, $boissonId, 'drink');
    }

    private function upsertAddon(Item $main, int $addonItemId, string $role): void
    {
        $existing = ItemAddon::withTrashed()
            ->where('item_id', $main->id)
            ->where('addon_item_id', $addonItemId)
            ->first();
        if ($existing) {
            if ($existing->trashed()) $existing->restore();
            $existing->update(['role' => $role]);
            return;
        }
        ItemAddon::create([
            'item_id'       => $main->id,
            'addon_item_id' => $addonItemId,
            'role'          => $role,
        ]);
        $this->stats['created_addons']++;
    }

    private function seedBolBaseAttribute(Item $bol): void
    {
        $attr = $this->getOrCreateAttribute('Base bol', 1, 1);
        foreach (['Frites', 'Riz basmati'] as $base) {
            $existing = ItemVariation::withTrashed()
                ->where('item_id', $bol->id)
                ->where('item_attribute_id', $attr->id)
                ->where('name', $base)
                ->first();
            if ($existing) {
                if ($existing->trashed()) $existing->restore();
                continue;
            }
            ItemVariation::create([
                'item_id'           => $bol->id,
                'item_attribute_id' => $attr->id,
                'name'              => $base,
                'price'             => 0,
                'status'            => Status::ACTIVE,
            ]);
            $this->stats['created_variations']++;
        }
    }

    private function seedBolSupplements(Item $bol): void
    {
        $bolSupplements = [
            ['name' => 'Oignons frits',  'price' => 1.0],
            ['name' => 'Jambon',         'price' => 1.0],
            ['name' => 'Champignons',    'price' => 1.0],
            ['name' => 'Boule gratinée', 'price' => 2.0],
        ];
        foreach ($bolSupplements as $supp) {
            $existing = ItemExtra::withTrashed()
                ->where('item_id', $bol->id)
                ->where('name', $supp['name'])
                ->where('group_label', 'supplement_bol')
                ->first();
            if ($existing) {
                if ($existing->trashed()) $existing->restore();
                $existing->update(['price' => $supp['price']]);
                continue;
            }
            ItemExtra::create([
                'item_id'      => $bol->id,
                'name'         => $supp['name'],
                'price'        => $supp['price'],
                'status'       => Status::ACTIVE,
                'group_label'  => 'supplement_bol',
                'is_available' => 1,
            ]);
            $this->stats['created_extras']++;
        }
    }

    private function seedBolBoissonAddon(Item $bol, int $boissonId): void
    {
        $this->upsertAddon($bol, $boissonId, 'drink');
    }

    private function seedFritesStyleAttribute(Item $frites): void
    {
        $attr = $this->getOrCreateAttribute('Style frites', 1, 1);
        $styles = [
            ['name' => 'Nature',                          'price' => 0],
            ['name' => 'Cheddar fondu',                   'price' => 1.0],
            ['name' => 'Cheddar + Oignons frits',         'price' => 2.0],
        ];
        foreach ($styles as $style) {
            $existing = ItemVariation::withTrashed()
                ->where('item_id', $frites->id)
                ->where('item_attribute_id', $attr->id)
                ->where('name', $style['name'])
                ->first();
            if ($existing) {
                if ($existing->trashed()) $existing->restore();
                $existing->update(['price' => $style['price']]);
                continue;
            }
            ItemVariation::create([
                'item_id'           => $frites->id,
                'item_attribute_id' => $attr->id,
                'name'              => $style['name'],
                'price'             => $style['price'],
                'status'            => Status::ACTIVE,
            ]);
            $this->stats['created_variations']++;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 10 — Create composer profiles for 5 bols (base → sauce → supp → drink)
    // ─────────────────────────────────────────────────────────────────────
    private function step10CreateBolsComposerProfiles(): void
    {
        $this->info('▶ Step 10 — Create composer profiles for bols…');
        $bols = Item::whereIn('slug', ['bol-curry', 'bol-tandoori', 'bol-marine', 'bol-crousti', 'bol-gratine'])
            ->whereNull('deleted_at')->get();

        foreach ($bols as $bol) {
            // Idempotency: delete any existing profile for this item first
            ItemWizardProfile::where('item_id', $bol->id)->each(function ($p) {
                ItemWizardStep::where('profile_id', $p->id)->delete();
                $p->delete();
            });

            $profile = ItemWizardProfile::create([
                'item_id'          => $bol->id,
                'item_category_id' => null,
                'template'         => 'custom',
                'version'          => 1,
                'is_published'     => true,
                'published_at'     => now(),
                'branch_id_scope'  => null,
            ]);
            $this->stats['created_profiles']++;

            $position = 0;
            // Step 1: Base
            ItemWizardStep::create([
                'profile_id'               => $profile->id,
                'step_key'                 => 'base',
                'label'                    => 'Choix de la base',
                'source_type'              => 'item_attribute',
                'source_ref'               => 'base bol',
                'source_item_attribute_id' => ItemAttribute::where('name', 'Base bol')->value('id'),
                'min_select'               => 1,
                'max_select'               => 1,
                'allow_repeat'             => false,
                'visible_on'               => null,
                'stockable_choices'        => false,
                'position'                 => $position++,
                'is_active'                => true,
                'addon_role'               => null,
            ]);
            // Step 2: Suppléments (extras)
            ItemWizardStep::create([
                'profile_id'        => $profile->id,
                'step_key'          => 'supplements',
                'label'             => 'Suppléments',
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
            // Step 3: Boisson optionnelle (addon role=drink)
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
            $this->stats['created_steps'] += 3;
            $this->line("   ↳ Profile created for {$bol->name} (3 steps).");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 11 — Composer profile for 2 frites (1 step: style)
    // ─────────────────────────────────────────────────────────────────────
    private function step11CreateFritesComposerProfile(): void
    {
        $this->info('▶ Step 11 — Create composer profiles for frites…');
        $fritesItems = Item::whereIn('slug', ['petite-frites', 'grande-frites'])
            ->whereNull('deleted_at')->get();

        foreach ($fritesItems as $f) {
            ItemWizardProfile::where('item_id', $f->id)->each(function ($p) {
                ItemWizardStep::where('profile_id', $p->id)->delete();
                $p->delete();
            });

            $profile = ItemWizardProfile::create([
                'item_id'          => $f->id,
                'item_category_id' => null,
                'template'         => 'custom',
                'version'          => 1,
                'is_published'     => true,
                'published_at'     => now(),
                'branch_id_scope'  => null,
            ]);
            $this->stats['created_profiles']++;

            ItemWizardStep::create([
                'profile_id'               => $profile->id,
                'step_key'                 => 'style',
                'label'                    => 'Choix du style',
                'source_type'              => 'item_attribute',
                'source_ref'               => 'style frites',
                'source_item_attribute_id' => ItemAttribute::where('name', 'Style frites')->value('id'),
                'min_select'               => 1,
                'max_select'               => 1,
                'allow_repeat'             => false,
                'visible_on'               => null,
                'stockable_choices'        => false,
                'position'                 => 0,
                'is_active'                => true,
                'addon_role'               => null,
            ]);
            $this->stats['created_steps']++;
            $this->line("   ↳ Profile created for {$f->name}.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 12 — Finalize sort order (sort field on cats)
    // ─────────────────────────────────────────────────────────────────────
    private function step12FinalizeSortOrder(): void
    {
        $this->info('▶ Step 12 — Finalize sort order…');
        $sortMap = [
            'sandwich-cayenne'   => 1,
            'galette'            => 2,
            'sandwich-classique' => 3,
            'tacos'              => 4,
            'bols-gourmands'     => 5,
            'frites'             => 6,
            'supplements'        => 7,
            'desserts'           => 8,
            'boissons'           => 9,
        ];
        foreach ($sortMap as $slug => $sort) {
            ItemCategory::where('slug', $slug)->update(['sort' => $sort]);
        }
        $this->line('   ↳ 9 categories sort order applied.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fire deferred events (after transaction commit)
    // ─────────────────────────────────────────────────────────────────────
    private function fireDeferredEvents(): void
    {
        $this->info('▶ Firing sync events…');
        foreach ($this->eventsToFire as $event) {
            event($event);
            $this->stats['events_fired']++;
        }
        $this->line("   ↳ {$this->stats['events_fired']} events fired (Category Created/Updated/Deleted).");
    }

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
