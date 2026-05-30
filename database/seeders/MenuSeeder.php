<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Tax;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ============================================================================
 * MENU SEEDER - SINGLE SOURCE OF TRUTH
 * ============================================================================
 *
 * This is the ONLY authorized seeder for menu items.
 * ALL other menu seeders are DEPRECATED and should NOT be used.
 *
 * Features:
 * - Prevents duplicate runs (idempotent)
 * - Purges all old data before seeding
 * - Creates ONLY French menu items
 * - Prices in EUR only (€)
 * - No DEMO mode check - always runs
 * - Verifies French integrity after creation
 *
 * Usage:
 *   php artisan db:seed --class=MenuSeeder
 *
 * DEPRECATED: ItemTableSeeder, ItemCategoryTableSeeder, GrillHouseMenuSeeder
 * USE INSTEAD: This MenuSeeder class ONLY
 * ============================================================================
 */
class MenuSeeder extends Seeder
{
    /**
     * Configuration from config/menu.php
     */
    protected array $config;

    /**
     * Created category IDs mapping
     */
    protected array $categoryIds = [];

    /**
     * Created addon item IDs
     */
    protected array $addonIds = [];

    /**
     * Resolved default tax for items (config default_tax_id may not exist after
     * MySQL transaction rollbacks: AUTO_INCREMENT is not reset, first row ≠ 1).
     */
    protected ?int $defaultTaxId = null;

    /**
     * Item attributes
     */
    protected ?ItemAttribute $attrViande1 = null;

    protected ?ItemAttribute $attrViande2 = null;

    protected ?ItemAttribute $attrViande3 = null;

    protected ?ItemAttribute $attrViande4 = null;

    protected ?ItemAttribute $attrSauce = null;

    protected ?ItemAttribute $attrCrudite = null;

    protected ?ItemAttribute $attrPain = null;  // [UI/UX Sprint 4] Type de Pain pour Sandwichs

    /**
     * Run the database seeds.
     *
     * @throws \Exception
     */
    public function run(): void
    {
        $this->config = Config::get('menu');
        $this->categoryIds = [];
        $this->addonIds = [];
        $this->defaultTaxId = null;

        echo "=== MENU SEEDER - Le Grill House ===\n";
        echo "Restaurant: {$this->config['restaurant']['name']}\n";
        echo "Locale: {$this->config['locale']} | Currency: {$this->config['currency']}\n";
        echo "====================================\n\n";

        // Step 1: Pre-flight checks
        $this->runPreflightChecks();

        // Step 2: Check for English contamination (warning only, don't throw)
        // $this->checkForEnglishContamination(); // Disabled - auto-purge instead

        // Step 3: Check if menu already exists
        $menuExists = $this->menuExists();
        $englishContaminated = $this->isEnglishContaminated();

        if ($menuExists && ! $englishContaminated) {
            echo "✅ French menu already exists and is valid. Skipping...\n";

            return;
        }

        if ($englishContaminated) {
            echo "🚨 ENGLISH MENU DETECTED! Force purging...\n";
        }

        // Step 4: Purge all existing menu data
        $this->purgeExistingData();

        // Step 5: Create categories
        $this->createCategories();

        // Step 6: Create item attributes
        $this->createAttributes();

        // Step 7: Create addon items (upsell)
        $this->createAddons();

        // Step 8: Create menu items
        $this->createItems();

        // Step 9: Verify French integrity
        $this->verifyFrenchIntegrity();

        echo "\n✅ Menu seeding completed successfully!\n";
        echo "====================================\n";
    }

    /**
     * Run pre-flight validation checks
     *
     * @throws \Exception
     */
    protected function runPreflightChecks(): void
    {
        echo "Running pre-flight checks...\n";

        // Verify config exists
        if (empty($this->config)) {
            throw new \Exception('CRITICAL: config/menu.php not found or empty!');
        }

        // Verify French locale
        if ($this->config['locale'] !== 'fr') {
            throw new \Exception('CRITICAL: Locale must be "fr" (French)!');
        }

        // Verify EUR currency
        if ($this->config['currency'] !== 'EUR') {
            throw new \Exception('CRITICAL: Currency must be "EUR" (Euro)!');
        }

        // Verify categories exist
        if (empty($this->config['categories'])) {
            throw new \Exception('CRITICAL: No categories defined in config!');
        }

        // Verify items exist
        if (empty($this->config['items'])) {
            throw new \Exception('CRITICAL: No items defined in config!');
        }

        // Verify protection settings
        if (! ($this->config['protection']['block_english_items'] ?? false)) {
            throw new \Exception('CRITICAL: block_english_items must be true in config!');
        }

        echo "✓ Pre-flight checks passed\n\n";
    }

    /**
     * Check for existing English menu contamination
     *
     * @throws \Exception
     */
    protected function checkForEnglishContamination(): void
    {
        echo "Checking for English menu contamination...\n";

        $englishPatterns = [
            'Appetizer', 'Burger', 'Sandwich', 'Chicken', 'Beef', 'Seafood',
            'Salad', 'Soup', 'Side', 'Beverage', 'Drink', 'Dessert',
            'Flame Grill', 'Veggie', 'Plant Based', 'House Special',
            'Entree', 'Zoop', 'Order', 'Add ', 'BBQ', 'Bacon', 'Vegan',
            'Regular', 'Large', 'Medium', 'Small', 'pcs', 'Pack',
        ];

        // Check categories
        $categories = ItemCategory::all();
        foreach ($categories as $cat) {
            foreach ($englishPatterns as $pattern) {
                if (stripos($cat->name, $pattern) !== false) {
                    throw new \Exception("CRITICAL: Database contains English category '{$cat->name}'. Run 'php artisan menu:reset' to purge.");
                }
            }
        }

        // Check items
        $items = Item::all();
        foreach ($items as $item) {
            foreach ($englishPatterns as $pattern) {
                if (stripos($item->name, $pattern) !== false) {
                    throw new \Exception("CRITICAL: Database contains English item '{$item->name}'. Run 'php artisan menu:reset' to purge.");
                }
            }
        }

        echo "✓ No English contamination detected\n\n";
    }

    /**
     * Check if database contains English menu items
     */
    protected function isEnglishContaminated(): bool
    {
        $englishPatterns = [
            'Appetizer', 'Burger', 'Sandwich', 'Chicken', 'Beef', 'Seafood',
            'Salad', 'Soup', 'Side', 'Beverage', 'Drink', 'Dessert',
            'Flame Grill', 'Veggie', 'Plant Based', 'House Special',
            'Entree', 'Zoop', 'Order', 'Add ', 'BBQ', 'Bacon', 'Vegan',
            'Regular', 'Large', 'Medium', 'Small', 'pcs', 'Pack',
            'Dumplings', 'Egg Roll', 'Wonton', 'Spring Roll',
        ];

        // Check categories
        $categories = ItemCategory::all();
        foreach ($categories as $cat) {
            foreach ($englishPatterns as $pattern) {
                if (stripos($cat->name, $pattern) !== false) {
                    echo "  ⚠️ Found English category: '{$cat->name}'\n";

                    return true;
                }
            }
        }

        // Check items
        $items = Item::all();
        foreach ($items as $item) {
            foreach ($englishPatterns as $pattern) {
                if (stripos($item->name, $pattern) !== false) {
                    echo "  ⚠️ Found English item: '{$item->name}'\n";

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if menu already exists
     */
    protected function menuExists(): bool
    {
        $existingCategories = ItemCategory::count();
        $existingItems = Item::count();

        echo "Checking existing menu data...\n";
        echo "  - Existing categories: {$existingCategories}\n";
        echo "  - Existing items: {$existingItems}\n";

        // If we have French categories, menu likely exists
        if ($existingCategories > 0) {
            $frenchCategories = ItemCategory::where('name', 'like', '%Tacos%')
                ->orWhere('name', 'like', '%Sandwich%')
                ->orWhere('name', 'like', '%Burger%')
                ->count();

            if ($frenchCategories > 0) {
                echo "  - Found French categories: {$frenchCategories}\n";

                return true;
            }
        }

        return false;
    }

    /**
     * Purge all existing menu-related data
     * [AUDIT FIX] SQLite-compatible for sandbox/CI (no MySQL required)
     */
    protected function purgeExistingData(): void
    {
        echo "\nPurging existing menu data...\n";

        // [BUG-WIZ FIX] Purge Spatie Media models first since truncate() bypasses Eloquent events
        try {
            \Spatie\MediaLibrary\MediaCollections\Models\Media::where('model_type', 'App\Models\Item')
                ->orWhere('model_type', 'App\Models\ItemCategory')
                ->delete();
            echo "  ✓ Purged legacy media from database\n";
        } catch (\Exception $e) {
            echo "  ⚠️ Could not purge legacy media\n";
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF;');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }

        // Delete in correct order to avoid constraint issues.
        // On MySQL, TRUNCATE issues an implicit COMMIT which breaks Laravel's
        // RefreshDatabase per-test transaction — MenuSeederTest then leaks
        // the full French menu into every subsequent test (phpunit-mysql CI).
        // Use DELETE (same as SQLite path) whenever not on a real prod DB.
        $useDelete = $driver === 'sqlite' || app()->environment('testing', 'local');

        if ($useDelete) {
            DB::table('item_addons')->delete();
            DB::table('item_extras')->delete();
            DB::table('item_variations')->delete();
            DB::table('item_attributes')->delete();
            DB::table('items')->delete();
            DB::table('item_categories')->delete();
            if ($driver === 'sqlite') {
                DB::statement("DELETE FROM sqlite_sequence WHERE name IN ('items', 'item_categories', 'item_addons', 'item_extras', 'item_variations', 'item_attributes');");
            }
        } else {
            DB::table('item_addons')->truncate();
            DB::table('item_extras')->truncate();
            DB::table('item_variations')->truncate();
            DB::table('item_attributes')->truncate();
            DB::table('items')->truncate();
            DB::table('item_categories')->truncate();
        }

        echo "  ✓ Purged item_addons\n";
        echo "  ✓ Purged item_extras\n";
        echo "  ✓ Purged item_variations\n";
        echo "  ✓ Purged item_attributes\n";
        echo "  ✓ Purged items\n";
        echo "  ✓ Purged item_categories\n";

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }

        echo "✓ All menu data purged\n\n";
    }

    /**
     * Items reference taxes.id; config default_tax_id is often 1 but tests/CI
     * may have no row at 1 (e.g. InnoDB rollback leaves AUTO_INCREMENT > 1).
     *
     * [GOAL-GOLIVE-VAT10 2026-05-30] Owner mandate: Le Cayenne is a French
     * fast-food charging 10% VAT INCLUDED in the price (TTC). The menu must
     * therefore seed with the canonical VAT 10% row, NOT the 0% "No-VAT" row
     * that config default_tax_id=1 historically pointed at. We resolve the VAT
     * row BY ATTRIBUTES (rate=10 + PERCENTAGE type), not by a hardcoded id —
     * there are two 10% rows (VAT id=3 and GST id=5) and AUTO_INCREMENT is not
     * deterministic after rollbacks, so an id literal would silently bind the
     * wrong tax. Prefer name='VAT' to disambiguate from GST. Falls back to the
     * configured id only if it is itself a non-zero-rate tax, then creates the
     * canonical VAT 10% row as a last resort. PricingService runs in
     * tax-inclusive mode (config pricing.tax_inclusive_prices=true) so this
     * EXTRACTS 0,45 € from a 5,00 € line — it does NOT add 10% on top.
     */
    protected function defaultTaxId(): int
    {
        if ($this->defaultTaxId !== null) {
            return $this->defaultTaxId;
        }

        // 1. Canonical resolve: VAT 10% PERCENTAGE by attributes (deterministic).
        $vat10 = Tax::query()
            ->where('tax_rate', 10)
            ->where('type', TaxType::PERCENTAGE)
            ->where('name', 'VAT')
            ->orderBy('id')
            ->value('id');
        if ($vat10) {
            return $this->defaultTaxId = (int) $vat10;
        }

        // 2. Any 10% PERCENTAGE row (covers a non-'VAT'-named 10% row).
        $anyTen = Tax::query()
            ->where('tax_rate', 10)
            ->where('type', TaxType::PERCENTAGE)
            ->orderBy('id')
            ->value('id');
        if ($anyTen) {
            return $this->defaultTaxId = (int) $anyTen;
        }

        // 3. Configured id — ONLY if it is a non-zero-rate tax (never bind 0%).
        $configured = (int) ($this->config['settings']['default_tax_id'] ?? 0);
        if ($configured > 0) {
            $configuredRate = (float) (Tax::query()->whereKey($configured)->value('tax_rate') ?? 0);
            if ($configuredRate > 0) {
                return $this->defaultTaxId = $configured;
            }
        }

        // 4. Last resort: create the canonical VAT 10% row.
        $tax = Tax::create([
            'name' => 'VAT',
            'code' => 'VAT-MENUSEEDER-DEFAULT',
            'tax_rate' => 10,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);

        return $this->defaultTaxId = (int) $tax->id;
    }

    /**
     * Create categories from config
     */
    protected function createCategories(): void
    {
        echo "Creating categories...\n";

        foreach ($this->config['categories'] as $category) {
            // [BUG-4 FIX] Include wizard_template and has_menu from config
            $slug = $category['slug'] ?? Str::slug($category['name']);

            $cat = ItemCategory::create([
                'name' => $category['name'],
                'slug' => $slug,
                'description' => $category['description'] ?? null,
                'status' => $this->config['settings']['status_active'],
                'sort' => $category['sort'],
                'wizard_template' => $category['wizard_template'] ?? 'simple',
                'has_menu' => $category['has_menu'] ?? false,
                'default_menu_kiosk' => $category['default_menu_kiosk'] ?? false,
            ]);

            $this->categoryIds[$slug] = $cat->id;
            echo "  ✓ Created: {$category['name']} (template: {$cat->wizard_template})\n";
        }

        echo '✓ Created '.count($this->config['categories'])." categories\n\n";
    }

    /**
     * Create item attributes (Viandes, Sauces, Crudités)
     */
    protected function createAttributes(): void
    {
        echo "Creating item attributes...\n";

        $this->attrViande1 = ItemAttribute::create(['name' => 'Viande 1', 'status' => Status::ACTIVE]);
        $this->attrViande2 = ItemAttribute::create(['name' => 'Viande 2', 'status' => Status::ACTIVE]);
        $this->attrViande3 = ItemAttribute::create(['name' => 'Viande 3', 'status' => Status::ACTIVE]);
        $this->attrViande4 = ItemAttribute::create(['name' => 'Viande 4', 'status' => Status::ACTIVE]);
        $this->attrSauce = ItemAttribute::create(['name' => 'Sauce (1ère Gratuite)', 'status' => Status::ACTIVE]);
        $this->attrPain = ItemAttribute::create(['name' => 'Type de Pain', 'status' => Status::ACTIVE]);  // [UI/UX Sprint 4]

        echo "  ✓ Created: Viande 1, Viande 2, Viande 3, Viande 4\n";
        echo "  ✓ Created: Sauce (1ère Gratuite)\n";
        echo "  ✓ Created: Type de Pain\n";  // [UI/UX Sprint 4]
        echo "✓ Attributes created\n\n";
    }

    /**
     * Create addon items (upsell items)
     */
    protected function createAddons(): void
    {
        echo "Creating addon items (upsell)...\n";

        // Use Snacking category for addon items
        $addonCategoryId = $this->categoryIds['frites-accompagnements']
            ?? $this->categoryIds['nos-boissons']
            ?? collect($this->categoryIds)->first();

        if ($addonCategoryId === null) {
            throw new \RuntimeException('MenuSeeder::createAddons: no category ids (createCategories did not run or failed).');
        }

        foreach ($this->config['addons'] as $addon) {
            $item = Item::create([
                'name' => $addon['name'],
                'slug' => Str::slug($addon['name']),
                'item_category_id' => $addonCategoryId,
                'price' => $addon['price'],
                'description' => 'Upsell item',
                'status' => Status::ACTIVE,
                'tax_id' => $this->defaultTaxId(),
            ]);

            $this->attachItemImage($item, Str::slug($addon['name']), 'addons');
            $this->addonIds[Str::slug($addon['name'])] = $item->id;
            echo "  ✓ Created addon: {$addon['name']} ({$addon['price']}€)\n";
        }

        echo '✓ Created '.count($this->config['addons'])." addon items\n\n";
    }

    /**
     * Create all menu items from config
     */
    protected function createItems(): void
    {
        echo "Creating menu items...\n";

        $itemCount = 0;

        foreach ($this->config['items'] as $categorySlug => $items) {
            if (! isset($this->categoryIds[$categorySlug])) {
                echo "  ⚠️  Skipping unknown category: {$categorySlug}\n";

                continue;
            }

            $categoryId = $this->categoryIds[$categorySlug];

            foreach ($items as $itemData) {
                $this->createItem($itemData, $categoryId, $categorySlug); // [BUG-WIZ FIX] Pass categorySlug
                $itemCount++;
            }
        }

        echo "✓ Created {$itemCount} menu items\n\n";
    }

    /**
     * Create a single item with all its variations and extras
     */
    protected function createItem(array $data, int $categoryId, string $categorySlug = ''): void
    {
        // Create the item
        $item = Item::create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'item_category_id' => $categoryId,
            'price' => $data['price'],
            'description' => $data['description'] ?? '',
            'status' => Status::ACTIVE,
            'tax_id' => $this->defaultTaxId(),
        ]);

        // Attach addons
        $this->attachAddons($item);

        // Add meat variations if applicable
        if ($data['viandes'] > 0) {
            $this->attachMeatVariations($item, $data['viandes']);
        }

        // Add sauce variations if applicable
        if ($data['has_sauce']) {
            $this->attachSauceVariations($item, $data['sauce_special'] ?? null);
        }

        // Add crudité extras if applicable
        if ($data['has_crudites']) {
            $this->attachCruditeExtras($item);
        }

        // [UI/UX Sprint 4] Add bread type variations for sandwichs
        if ($categorySlug === 'nos-sandwichs') {
            $this->attachPainVariations($item);
        }

        // Add supplements/extras (for most items except simple ones)
        if (! isset($data['is_frites']) || ! $data['is_frites']) {
            $this->attachSupplements($item, $categorySlug); // [BUG-WIZ FIX] Pass categorySlug
        }

        // Special handling for frites
        if (isset($data['is_frites']) && $data['is_frites']) {
            $this->attachFritesExtras($item);
        }

        // Attach product image from config
        $this->attachItemImage($item, $item->slug, 'items');

        echo "  ✓ {$data['name']} ({$data['price']}€)\n";
    }

    /**
     * Attach image to item from config/menu_images.php if file exists
     *
     * @param  string  $configKey  'items' or 'addons'
     */
    protected function attachItemImage(Item $item, string $slug, string $configKey = 'items'): void
    {
        $images = Config::get("menu_images.{$configKey}", []);
        $basePath = Config::get('menu_images.base_path', 'images/menu');
        $defaultFile = Config::get('menu_images.default', 'item-default.svg');

        $filename = $images[$slug] ?? $defaultFile;
        $fullPath = public_path("{$basePath}/{$filename}");

        if (! file_exists($fullPath)) {
            $fullPath = public_path("{$basePath}/{$defaultFile}");
        }

        if (file_exists($fullPath)) {
            try {
                $item->addMedia($fullPath)->preservingOriginal()->toMediaCollection('item');
            } catch (\Exception $e) {
                // Silent fail - item will use fallback thumb/cover
            }
        }
    }

    /**
     * Attach addon items to an item
     */
    protected function attachAddons(Item $item): void
    {
        foreach ($this->addonIds as $addonId) {
            ItemAddon::create([
                'item_id' => $item->id,
                'addon_item_id' => $addonId,
            ]);
        }
    }

    /**
     * Attach meat variations to an item
     */
    protected function attachMeatVariations(Item $item, int $count): void
    {
        $meats = $this->config['meats'];
        $attributes = [$this->attrViande1, $this->attrViande2, $this->attrViande3, $this->attrViande4];

        for ($i = 0; $i < $count && $i < 4; $i++) {
            foreach ($meats as $meat) {
                ItemVariation::create([
                    'item_id' => $item->id,
                    'item_attribute_id' => $attributes[$i]->id,
                    'name' => $meat,
                    'price' => 0.00,
                    'status' => Status::ACTIVE,
                ]);
            }
        }
    }

    /**
     * Attach sauce variations to an item
     */
    protected function attachSauceVariations(Item $item, ?array $specialSauces = null): void
    {
        $sauces = $specialSauces ?? $this->config['sauces'];

        foreach ($sauces as $sauce) {
            ItemVariation::create([
                'item_id' => $item->id,
                'item_attribute_id' => $this->attrSauce->id,
                'name' => $sauce,
                'price' => 0.00,
                'status' => Status::ACTIVE,
            ]);
        }
    }

    /**
     * Attach crudité extras to an item
     */
    protected function attachCruditeExtras(Item $item): void
    {
        foreach ($this->config['crudites'] as $crudite) {
            ItemExtra::create([
                'item_id' => $item->id,
                'name' => $crudite,
                'price' => 0.00,
                'status' => Status::ACTIVE,
            ]);
        }
    }

    /**
     * Attach bread type variations to an item (Pain/Galette)
     * [UI/UX Sprint 4] New step for sandwichs
     */
    protected function attachPainVariations(Item $item): void
    {
        $painTypes = ['Pain', 'Galette'];
        foreach ($painTypes as $painType) {
            ItemVariation::create([
                'item_id' => $item->id,
                'item_attribute_id' => $this->attrPain->id,
                'name' => $painType,
                'price' => 0.00,
                'status' => Status::ACTIVE,
            ]);
        }
    }

    /**
     * Attach supplements/extras to an item
     */
    protected function attachSupplements(Item $item, string $categorySlug = ''): void
    {
        // [BUG-WIZ-001/002 FIX] Categories that should NOT have food supplements
        $noSupplementCategories = [
            'ojja', 'omelettes', 'nos-salades', 'nos-desserts',
            'nos-boissons', 'frites-accompagnements', 'chicken-tenders',
        ];

        // [BUG-WIZ-001 FIX] Categories that should have extra sauce options
        // Only Tacos, Sandwichs, Burgers need "Sauce supplémentaire" in extras
        $hasSauceExtras = in_array($categorySlug, ['nos-tacos', 'nos-sandwichs', 'nos-burgers'], true);

        // Add regular supplements only for appropriate categories
        if (! in_array($categorySlug, $noSupplementCategories)) {
            foreach ($this->config['supplements'] as $name => $price) {
                ItemExtra::create([
                    'item_id' => $item->id,
                    'name' => $name,
                    'price' => $price,
                    'status' => Status::ACTIVE,
                ]);
            }
        }

        // Add extra sauce options ONLY for specific categories (not for everyone)
        if ($hasSauceExtras) {
            foreach ($this->config['sauces'] as $sauce) {
                if ($sauce !== 'Sans Sauce') { // Don't offer "Sauce suppl: Sans Sauce"
                    ItemExtra::create([
                        'item_id' => $item->id,
                        'name' => "Sauce supplémentaire: {$sauce}",
                        'price' => $this->config['supplement_sauce_price'],
                        'status' => Status::ACTIVE,
                    ]);
                }
            }
        }
    }

    /**
     * Attach extras specifically for frites
     */
    protected function attachFritesExtras(Item $item): void
    {
        // Add sauce options
        foreach ($this->config['sauces'] as $sauce) {
            ItemExtra::create([
                'item_id' => $item->id,
                'name' => "Sauce {$sauce}",
                'price' => $this->config['supplement_sauce_price'],
                'status' => Status::ACTIVE,
            ]);
        }

        // Add cheddar option based on frite size
        $cheddarPrice = str_contains($item->name, 'Grande') ? 1.50 : 1.00;
        ItemExtra::create([
            'item_id' => $item->id,
            'name' => 'Cheddar fondu',
            'price' => $cheddarPrice,
            'status' => Status::ACTIVE,
        ]);
    }

    /**
     * Verify French integrity of the created menu
     */
    protected function verifyFrenchIntegrity(): void
    {
        echo "\nVerifying French integrity...\n";

        $errors = [];

        // Check 1: All categories should be French
        $categories = ItemCategory::all();
        foreach ($categories as $cat) {
            // Check for English words
            $englishWords = ['Appetizer', 'Burger', 'Sandwich', 'Chicken', 'Beef', 'Seafood', 'Salad', 'Soup', 'Side', 'Beverage', 'Drink'];
            foreach ($englishWords as $word) {
                if (stripos($cat->name, $word) !== false) {
                    $errors[] = "Category '{$cat->name}' contains English word: {$word}";
                }
            }
        }

        // Check 2: All items should have EUR prices (no $ signs in names/descriptions)
        $items = Item::all();
        $dollarSignCount = $items->filter(fn ($item) => str_contains($item->name, '$'))->count();
        if ($dollarSignCount > 0) {
            $errors[] = "{$dollarSignCount} items contain dollar signs";
        }

        // Check 3: All prices should be reasonable (between 0.50 and 50.00 EUR)
        $invalidPrices = $items->filter(fn ($item) => $item->price < 0.50 || $item->price > 50.00)->count();
        if ($invalidPrices > 0) {
            $errors[] = "{$invalidPrices} items have suspicious prices";
        }

        // Check 4: Count summary
        echo '  - Categories: '.ItemCategory::count()."\n";
        echo '  - Items: '.Item::count()."\n";
        echo '  - Item Attributes: '.ItemAttribute::count()."\n";
        echo '  - Item Variations: '.ItemVariation::count()."\n";
        echo '  - Item Extras: '.ItemExtra::count()."\n";
        echo '  - Item Addons: '.ItemAddon::count()."\n";

        if (empty($errors)) {
            echo "✓ French integrity verified - NO ISSUES FOUND\n";
        } else {
            echo "\n⚠️  INTEGRITY WARNINGS:\n";
            foreach ($errors as $error) {
                echo "  - {$error}\n";
            }
        }
    }

    /**
     * Get menu statistics
     */
    public static function getStats(): array
    {
        return [
            'categories' => ItemCategory::count(),
            'items' => Item::count(),
            'attributes' => ItemAttribute::count(),
            'variations' => ItemVariation::count(),
            'extras' => ItemExtra::count(),
            'addons' => ItemAddon::count(),
        ];
    }
}
