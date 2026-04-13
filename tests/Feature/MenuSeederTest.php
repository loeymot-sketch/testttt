<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Enums\TaxType;
use App\Enums\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * MenuSeederTest — Exécutable en sandbox (SQLite in-memory, pas de MySQL)
 *
 * Ce test permet la validation Playwright / E2E et aux agents de valider le MenuSeeder
 * SANS exécuter `php artisan db:seed` qui bloque (connexion MySQL).
 *
 * Usage: php artisan test --filter=MenuSeederTest
 */
class MenuSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        // Tax id=1 requis par config/menu.php default_tax_id
        Tax::firstOrCreate(
            ['id' => 1],
            [
                'name'       => 'VAT',
                'code'       => 'VAT-10',
                'tax_rate'   => 10,
                'type'       => TaxType::PERCENTAGE,
                'status'     => Status::ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * MenuSeeder s'exécute sans erreur avec SQLite (sandbox-compatible)
     */
    public function test_menu_seeder_runs_with_sqlite(): void
    {
        $this->seed(\Database\Seeders\MenuSeeder::class);

        $this->assertGreaterThan(0, ItemCategory::count(), 'Categories should be created');
        $this->assertGreaterThan(0, Item::count(), 'Items should be created');
    }

    /**
     * MenuSeeder crée le menu français (Le Grill House)
     */
    public function test_menu_seeder_creates_french_menu(): void
    {
        $this->seed(\Database\Seeders\MenuSeeder::class);

        $categories = ItemCategory::pluck('name')->toArray();
        $this->assertContains('Nos Tacos', $categories);
        $this->assertContains('Nos Sandwichs', $categories);
        $this->assertContains('Nos Burgers', $categories);

        $items = Item::pluck('name')->toArray();
        $this->assertNotEmpty(array_filter($items, fn ($n) => str_contains($n, 'Tacos')));
    }

    /**
     * MenuSeeder est idempotent (2e run skip)
     */
    public function test_menu_seeder_is_idempotent(): void
    {
        $this->seed(\Database\Seeders\MenuSeeder::class);
        $count1 = Item::count();

        $this->seed(\Database\Seeders\MenuSeeder::class);
        $count2 = Item::count();

        $this->assertEquals($count1, $count2, 'Second run should skip (idempotent)');
    }
}
