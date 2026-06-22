<?php

namespace Tests\Feature\Migrations;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WizardProfileOwnerXorTest extends TestCase
{
    use RefreshDatabase;

    public function test_xor_check_constraint_enforced_on_non_sqlite(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite does not enforce check constraints in this migration setup.');
        }

        $item = Item::factory()->create();
        $category = ItemCategory::factory()->create();

        $this->assertProfileCreationFails([
            'item_id' => null,
            'item_category_id' => null,
            'template' => 'custom',
        ]);

        $this->assertProfileCreationFails([
            'item_id' => $item->id,
            'item_category_id' => $category->id,
            'template' => 'custom',
        ]);

        $itemProfile = ItemWizardProfile::create([
            'item_id' => $item->id,
            'item_category_id' => null,
            'template' => 'custom',
        ]);

        $categoryProfile = ItemWizardProfile::create([
            'item_id' => null,
            'item_category_id' => $category->id,
            'template' => 'custom',
        ]);

        $this->assertSame('item', $itemProfile->ownerType());
        $this->assertSame('category', $categoryProfile->ownerType());
    }

    private function assertProfileCreationFails(array $attributes): void
    {
        try {
            DB::transaction(function () use ($attributes): void {
                ItemWizardProfile::create($attributes);
            });
        } catch (QueryException) {
            $this->assertTrue(true);

            return;
        }

        $this->fail('Wizard profile owner XOR constraint was not enforced.');
    }
}
