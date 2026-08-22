<?php

namespace Tests\Feature;

use App\Models\ItemAttribute;
use App\Support\GeneratedReportPath;
use Database\Seeders\RepairMultiVariationFixturesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairMultiVariationFixturesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_mutate_db(): void
    {
        ItemAttribute::factory()->create(['name' => 'Viande', 'max_select' => 1]);
        ItemAttribute::factory()->create(['name' => 'Sauce', 'max_select' => 1]);
        ItemAttribute::factory()->create(['name' => 'Couleur', 'max_select' => 1]);

        $before = ItemAttribute::all()->map->only(['id', 'name', 'min_select', 'max_select', 'allow_repeat'])->toArray();

        $seeder = new RepairMultiVariationFixturesSeeder();
        $seeder->run(false);

        $after = ItemAttribute::all()->map->only(['id', 'name', 'min_select', 'max_select', 'allow_repeat'])->toArray();
        $this->assertEquals($before, $after, 'DRY-RUN must not mutate any item_attribute row.');
    }

    public function test_dry_run_writes_audit_report(): void
    {
        ItemAttribute::factory()->create(['name' => 'Viande', 'max_select' => 1]);

        $seeder = new RepairMultiVariationFixturesSeeder();
        $seeder->run(false);

        $reportPath = GeneratedReportPath::resolve('reports/data-repair/MULTI_VARIATION_AUDIT_' . now()->toDateString() . '.md');
        $this->assertFileExists($reportPath);
        $content = file_get_contents($reportPath);
        $this->assertStringContainsString('DRY-RUN', $content);
        $this->assertStringContainsString('Viande', $content);
    }

    public function test_force_with_empty_rules_does_not_mutate_db(): void
    {
        ItemAttribute::factory()->create(['name' => 'Sauce', 'max_select' => 1]);

        $before = ItemAttribute::all()->map->only(['id', 'name', 'min_select', 'max_select', 'allow_repeat'])->toArray();

        $seeder = new RepairMultiVariationFixturesSeeder();
        $seeder->run(true);

        $after = ItemAttribute::all()->map->only(['id', 'name', 'min_select', 'max_select', 'allow_repeat'])->toArray();
        $this->assertEquals($before, $after, 'With empty policy.rules, --force must remain no-op.');
    }
}
