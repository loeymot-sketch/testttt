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

    public function test_apply_requires_explicit_confirmation(): void
    {
        ItemCategory::factory()->create(['name' => 'PW-C2 Category P1_DINE_IN_CASH', 'slug' => 'pw-c2-category-p1']);

        $this->artisan('foodking:cleanup-test-fixtures --prefix=PW- --apply --json')
            ->assertExitCode(2);

        $this->assertDatabaseHas('item_categories', ['name' => 'PW-C2 Category P1_DINE_IN_CASH']);
    }
}
