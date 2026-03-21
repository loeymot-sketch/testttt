<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Database\Seeders\MenuSeeder;
use App\Models\ItemCategory;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use App\Models\ItemExtra;
use App\Models\ItemAddon;

/**
 * ============================================================================
 * MENU MANAGEMENT COMMAND
 * ============================================================================
 *
 * Provides artisan commands for menu management:
 * - menu:create  : Creates menu (fails if exists)
 * - menu:reset   : Purges and recreates
 * - menu:verify  : Validates French menu integrity
 *
 * USAGE:
 *   php artisan menu:create
 *   php artisan menu:reset [--force]
 *   php artisan menu:verify
 * ============================================================================
 */
class MenuCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'menu
                            {action : Action to perform (create, reset, verify)}
                            {--force : Force reset without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage the French menu (create, reset, verify)';

    /**
     * Configuration from config/menu.php
     */
    protected array $config;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->config = Config::get('menu');
        $action = $this->argument('action');

        // Display header
        $this->displayHeader();

        // Route to appropriate handler
        return match($action) {
            'create'  => $this->handleCreate(),
            'reset'   => $this->handleReset(),
            'verify'  => $this->handleVerify(),
            default   => $this->handleUnknown($action),
        };
    }

    /**
     * Display command header
     */
    protected function displayHeader(): void
    {
        $this->output->writeln('');
        $this->output->writeln('╔════════════════════════════════════════════════════════════════╗');
        $this->output->writeln('║           FOODKING MENU MANAGEMENT SYSTEM                      ║');
        $this->output->writeln('║           Le Grill House - French Menu Only                    ║');
        $this->output->writeln('╚════════════════════════════════════════════════════════════════╝');
        $this->output->writeln('');
    }

    /**
     * Handle menu:create - Create menu if it doesn't exist
     *
     * @return int
     */
    protected function handleCreate(): int
    {
        $this->info('Action: CREATE Menu');
        $this->output->writeln('');

        // Check if menu already exists
        if ($this->menuExists()) {
            $this->warn('⚠️  Menu already exists!');
            $this->output->writeln('');
            $this->line('Options:');
            $this->line('  - To reset: php artisan menu:reset');
            $this->line('  - To verify: php artisan menu:verify');
            return 1;
        }

        $this->info('Menu does not exist. Creating...');
        $this->output->writeln('');

        // Run the seeder
        $seeder = new MenuSeeder();
        $seeder->setCommand($this);

        try {
            $seeder->run();
        } catch (\Exception $e) {
            $this->error('❌ ERROR: ' . $e->getMessage());
            return 1;
        }

        $this->output->writeln('');
        $this->info('✅ Menu created successfully!');
        $this->displayStats();

        return 0;
    }

    /**
     * Handle menu:reset - Purge and recreate menu
     *
     * @return int
     */
    protected function handleReset(): int
    {
        $this->info('Action: RESET Menu');
        $this->output->writeln('');

        // Confirm unless --force flag is used
        if (!$this->option('force')) {
            $confirmed = $this->confirm(
                '⚠️  This will DELETE ALL existing menu data (items, categories, etc.) and recreate it. Are you sure?',
                false
            );

            if (!$confirmed) {
                $this->warn('Operation cancelled.');
                return 1;
            }
        }

        $this->info('Resetting menu...');
        $this->output->writeln('');

        // Run the seeder
        $seeder = new MenuSeeder();
        $seeder->setCommand($this);

        try {
            $seeder->run();
        } catch (\Exception $e) {
            $this->error('❌ ERROR: ' . $e->getMessage());
            return 1;
        }

        $this->output->writeln('');
        $this->info('✅ Menu reset completed!');
        $this->displayStats();

        return 0;
    }

    /**
     * Handle menu:verify - Verify menu integrity
     *
     * @return int
     */
    protected function handleVerify(): int
    {
        $this->info('Action: VERIFY Menu Integrity');
        $this->output->writeln('');

        // Check if menu exists
        if (!$this->menuExists()) {
            $this->warn('⚠️  No menu found in database!');
            $this->output->writeln('');
            $this->line('Run: php artisan menu:create');
            return 1;
        }

        $this->info('Verifying menu integrity...');
        $this->output->writeln('');

        $errors = [];
        $warnings = [];

        // 1. Verify locale
        $this->line('1. Checking locale configuration...');
        $locale = Config::get('menu.locale');
        if ($locale !== 'fr') {
            $errors[] = "Config locale is '{$locale}', expected 'fr'";
        } else {
            $this->line('   ✓ Locale is French (fr)');
        }

        // 2. Verify currency
        $this->line('2. Checking currency configuration...');
        $currency = Config::get('menu.currency');
        if ($currency !== 'EUR') {
            $errors[] = "Config currency is '{$currency}', expected 'EUR'";
        } else {
            $this->line('   ✓ Currency is Euro (EUR)');
        }

        // 3. Check for English categories
        $this->line('3. Checking categories for English words...');
        // Mots réellement anglais non-admis en contexte restaurant français
        // Sandwich, Burger, Dessert, Salad, Chicken, Tenders sont acceptés dans les menus français
        $englishCategoryWords = [
            'Appetizer', 'Beef', 'Seafood',
            'Soup', 'Side', 'Beverage', 'Drink',
            'Flame Grill', 'Veggie', 'Plant Based', 'House Special',
            'Entree', 'Zoop', 'Order'
        ];

        $categories = ItemCategory::all();
        foreach ($categories as $cat) {
            foreach ($englishCategoryWords as $word) {
                if (stripos($cat->name, $word) !== false) {
                    $errors[] = "Category contains English: '{$cat->name}' -> '{$word}'";
                }
            }
        }

        if (empty(array_filter($errors, fn($e) => str_contains($e, 'Category')))) {
            $this->line('   ✓ All categories are in French');
        }

        // 4. Check for English items
        $this->line('4. Checking items for English names...');
        $englishItemPatterns = [
            '/chicken.*dumpling/i', '/egg.*roll/i', '/fried.*cheese/i',
            '/bbq/i', '/bacon/i', '/steak/i', '/shrimp/i', '/salmon/i',
            '/classic.*caesar/i', '/mojito/i', '/soda/i', '/cappuccino/i',
            '/espresso/i', '/iced.*coffee/i', '/homemade.*lemonade/i'
        ];

        $items = Item::all();
        $englishItemCount = 0;
        foreach ($items as $item) {
            foreach ($englishItemPatterns as $pattern) {
                if (preg_match($pattern, $item->name)) {
                    $errors[] = "Item has English name: '{$item->name}'";
                    $englishItemCount++;
                }
            }
        }

        if ($englishItemCount === 0) {
            $this->line('   ✓ All items have French names');
        }

        // 5. Verify prices are in reasonable EUR range
        $this->line('5. Checking prices are in EUR range...');
        $suspiciousPrices = 0;
        foreach ($items as $item) {
            // Prices should be between 0.50 and 50.00 EUR
            if ($item->price < 0.50 || $item->price > 50.00) {
                $warnings[] = "Suspicious price: '{$item->name}' = {$item->price}€";
                $suspiciousPrices++;
            }
        }

        if ($suspiciousPrices === 0) {
            $this->line('   ✓ All prices are in valid EUR range');
        }

        // 6. Check config matches database
        $this->line('6. Checking config matches database...');
        $configCategories = count(Config::get('menu.categories'));
        $dbCategories = ItemCategory::count();

        if ($configCategories !== $dbCategories) {
            $warnings[] = "Category count mismatch: config={$configCategories}, db={$dbCategories}";
        } else {
            $this->line('   ✓ Category count matches config');
        }

        // 7. Display stats
        $this->output->writeln('');
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->line('VERIFICATION SUMMARY');
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->displayStats();

        // 8. Display results
        $this->output->writeln('');

        if (empty($errors) && empty($warnings)) {
            $this->info('✅ ALL CHECKS PASSED - Menu is properly configured in French!');
            return 0;
        }

        if (!empty($errors)) {
            $this->error('❌ ERRORS FOUND:');
            foreach ($errors as $error) {
                $this->error("   - {$error}");
            }
        }

        if (!empty($warnings)) {
            $this->warn('⚠️  WARNINGS:');
            foreach ($warnings as $warning) {
                $this->warn("   - {$warning}");
            }
        }

        return !empty($errors) ? 1 : 0;
    }

    /**
     * Handle unknown action
     *
     * @param string $action
     * @return int
     */
    protected function handleUnknown(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->output->writeln('');
        $this->line('Available actions:');
        $this->line('  menu:create  - Create menu (fails if exists)');
        $this->line('  menu:reset   - Purge and recreate');
        $this->line('  menu:verify  - Validate French menu integrity');
        return 1;
    }

    /**
     * Check if menu already exists
     *
     * @return bool
     */
    protected function menuExists(): bool
    {
        return ItemCategory::count() > 0 || Item::count() > 0;
    }

    /**
     * Display current statistics
     */
    protected function displayStats(): void
    {
        $stats = [
            ['Categories', ItemCategory::count()],
            ['Items', Item::count()],
            ['Item Attributes', ItemAttribute::count()],
            ['Item Variations', ItemVariation::count()],
            ['Item Extras', ItemExtra::count()],
            ['Item Addons', ItemAddon::count()],
        ];

        $this->table(['Entity', 'Count'], $stats);
    }
}
