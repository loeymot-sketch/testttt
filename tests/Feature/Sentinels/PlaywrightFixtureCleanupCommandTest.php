<?php

namespace Tests\Feature\Sentinels;

use App\Console\Commands\CleanupTestFixturesCommand;
use App\Models\Item;
use App\Models\ItemCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaywrightFixtureCleanupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_playwright_fixtures_without_mutating_database(): void
    {
        ItemCategory::factory()->create(['name' => 'PW-C2 Category P1_DINE_IN_CASH', 'slug' => 'pw-c2-category-p1']);
        Item::factory()->create(['name' => 'PW-C2 Item P1_DINE_IN_CASH', 'slug' => 'pw-c2-item-p1']);
        ItemCategory::factory()->create(['name' => 'Tacos', 'slug' => 'tacos']);

        $this->artisan('foodking:cleanup-test-fixtures --prefix=PW- --dry-run --json')
            ->assertExitCode(0);

        $payload = app(CleanupTestFixturesCommand::class)->buildReport('PW-');

        $this->assertSame(1, $payload['item_categories']);
        $this->assertSame(1, $payload['items']);
        $this->assertDatabaseHas('item_categories', ['name' => 'PW-C2 Category P1_DINE_IN_CASH']);
        $this->assertDatabaseHas('item_categories', ['name' => 'Tacos']);
    }

    public function test_apply_removes_only_prefixed_playwright_fixtures(): void
    {
        ItemCategory::factory()->create(['name' => 'PW-C2 Category P1_DINE_IN_CASH', 'slug' => 'pw-c2-category-p1']);
        Item::factory()->create(['name' => 'PW-C2 Item P1_DINE_IN_CASH', 'slug' => 'pw-c2-item-p1']);
        ItemCategory::factory()->create(['name' => 'Tacos', 'slug' => 'tacos']);

        $this->artisan('foodking:cleanup-test-fixtures --prefix=PW- --apply --confirm=PW-FIXTURES --json')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('item_categories', ['name' => 'PW-C2 Category P1_DINE_IN_CASH']);
        $this->assertDatabaseMissing('items', ['name' => 'PW-C2 Item P1_DINE_IN_CASH']);
        $this->assertDatabaseHas('item_categories', ['name' => 'Tacos']);
    }

    /**
     * [2026-11-04 · P0 latent] Un nettoyage qui ne trouve AUCUN article préfixé ne doit toucher à AUCUN niveau
     * de stock. Avant : trois listes vides → `where(function () {})` vide → TOUS les niveaux de stock de la base
     * étaient sélectionnés pour suppression (sauvés par accident, en MySQL, par l'échec du DELETE sur
     * stock_movements, table en ajout seul).
     */
    public function test_sans_fixture_prefixee_aucun_niveau_de_stock_reel_n_est_supprime(): void
    {
        $branche = \Database\Factories\BranchFactory::new()->create();
        $vrai = Item::factory()->create(['name' => 'Coca-Cola 33cl', 'slug' => 'coca-cola-33cl']);
        \Illuminate\Support\Facades\DB::table('stock_levels')->insert([
            'branch_id' => $branche->id, 'stockable_type' => Item::class, 'stockable_id' => $vrai->id, 'on_hand' => 48, 'reserved' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('foodking:cleanup-test-fixtures --prefix=PW-E2E --apply --confirm=PW-FIXTURES --json')
            ->assertExitCode(0);

        $this->assertDatabaseHas('items', ['id' => $vrai->id]);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('stock_levels')->where('stockable_id', $vrai->id)->count(),
            'le stock réel du Coca ne doit pas être supprimé par un nettoyage qui ne trouve rien');
    }

    /** Un article de test dont le stock a bougé reste en place : son mouvement (ajout seul) ne se supprime pas. */
    public function test_article_de_test_avec_mouvement_de_stock_reste_en_place(): void
    {
        $branche = \Database\Factories\BranchFactory::new()->create();
        $test = Item::factory()->create(['name' => 'PW-E2E Article bouge', 'slug' => 'pw-e2e-article-bouge']);
        $niveau = \Illuminate\Support\Facades\DB::table('stock_levels')->insertGetId([
            'branch_id' => $branche->id, 'stockable_type' => Item::class, 'stockable_id' => $test->id, 'on_hand' => 5, 'reserved' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('stock_movements')->insert([
            'stock_level_id' => $niveau, 'branch_id' => $branche->id, 'delta' => 5, 'reason' => 'manual_in', 'created_at' => now(),
        ]);

        $this->artisan('foodking:cleanup-test-fixtures --prefix=PW-E2E --apply --confirm=PW-FIXTURES --json')
            ->assertExitCode(0);

        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('stock_movements')->where('stock_level_id', $niveau)->count());
        $this->assertDatabaseHas('stock_levels', ['id' => $niveau]);
        $this->assertDatabaseHas('items', ['id' => $test->id]);
    }

    public function test_apply_requires_explicit_confirmation(): void
    {
        ItemCategory::factory()->create(['name' => 'PW-C2 Category P1_DINE_IN_CASH', 'slug' => 'pw-c2-category-p1']);

        $this->artisan('foodking:cleanup-test-fixtures --prefix=PW- --apply --json')
            ->assertExitCode(2);

        $this->assertDatabaseHas('item_categories', ['name' => 'PW-C2 Category P1_DINE_IN_CASH']);
    }
}
