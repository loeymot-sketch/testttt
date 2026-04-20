<?php

namespace Tests\Feature;

use App\Models\ItemAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ItemAttributeMultiSelectMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_exist_after_migration(): void
    {
        $this->assertTrue(Schema::hasColumns('item_attributes', ['min_select', 'max_select', 'allow_repeat']));
    }

    public function test_defaults_preserve_legacy_single_select(): void
    {
        $a = ItemAttribute::create(['name' => 'Viande', 'status' => 1]);
        $a->refresh();
        $this->assertSame(0, $a->min_select);
        $this->assertSame(1, $a->max_select);
        $this->assertFalse($a->allow_repeat);
    }

    public function test_multi_select_values_persist_with_correct_casts(): void
    {
        $a = ItemAttribute::create([
            'name' => 'Viande',
            'status' => 1,
            'min_select' => 1,
            'max_select' => 4,
            'allow_repeat' => true,
        ]);
        $a->refresh();
        $this->assertSame(1, $a->min_select);
        $this->assertSame(4, $a->max_select);
        $this->assertTrue($a->allow_repeat);
    }
}
