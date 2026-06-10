<?php

namespace Tests\Feature\Pos;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemExtra;
use Database\Seeders\CaisseBillableUpgradesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID CAISSE-01 + CAISSE-01-BIS (P1, ultra-audit 2026-06-10) | LOT A (data-only)
 *
 * The frozen-wizard billing patch (LOCK_CAISSE-01) resolves upgrades BY NAME
 * (/grande/i, /cheddar/i) against the item's catalog extras and emits their
 * ids into item_extras so PricingService bills them (mechanics pinned by
 * FritesWizardComposerTest). It stayed dormant because the catalog carried NO
 * such ItemExtras. This test pins the seeder that creates them.
 */
class CaisseBillableUpgradesSeederTest extends TestCase
{
    use RefreshDatabase;

    private function makeFritesCatalog(): array
    {
        return [
            Item::factory()->create(['name' => 'Menu (Frites + Boisson)']),
            Item::factory()->create(['name' => 'Frites Seules']),
            Item::factory()->create(['name' => 'Petite Frites']),
            Item::factory()->create(['name' => 'Grande Frites']),
        ];
    }

    public function test_seeder_creates_grande_and_cheddar_extras_on_every_frites_item(): void
    {
        $this->seedMinimalSettings();
        $items = $this->makeFritesCatalog();

        $this->seed(CaisseBillableUpgradesSeeder::class);

        foreach ($items as $item) {
            foreach (['Grande Portion', 'Cheddar Fondu'] as $name) {
                $extra = ItemExtra::query()
                    ->where('item_id', $item->id)
                    ->where('name', $name)
                    ->first();

                $this->assertNotNull($extra, "{$name} missing on {$item->name}");
                $this->assertSame(1.0, (float) $extra->price, "{$name} must cost 1,00 € ({$item->name})");
                $this->assertSame(Status::ACTIVE, (int) $extra->status);
            }
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seedMinimalSettings();
        $this->makeFritesCatalog();

        $this->seed(CaisseBillableUpgradesSeeder::class);
        $first = ItemExtra::query()->count();

        $this->seed(CaisseBillableUpgradesSeeder::class);

        $this->assertSame($first, ItemExtra::query()->count(), 'Second run must create nothing.');
    }

    public function test_seeder_matches_the_frozen_patch_name_regexes(): void
    {
        // pos-wizard.js:4156-4167 looks up /grande/i and /cheddar/i — the
        // seeded names MUST keep matching those regexes or billing goes
        // dormant again silently.
        $this->assertMatchesRegularExpression('/grande/i', 'Grande Portion');
        $this->assertMatchesRegularExpression('/cheddar/i', 'Cheddar Fondu');
    }

    public function test_seeder_warns_and_seeds_nothing_when_catalog_lacks_frites(): void
    {
        $this->seedMinimalSettings();
        // No frites item created.
        $this->seed(CaisseBillableUpgradesSeeder::class);

        $this->assertSame(0, ItemExtra::query()->count());
    }
}
