<?php

namespace Tests\Feature\KioskPhase1;

use App\Models\Branch;
use App\Models\UpsellRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiosk Design V1 — Phase 1.2 : modèle UpsellRule, matching 3 triggers.
 */
class UpsellRuleModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_trigger_category_in_cart_matches_when_category_present(): void
    {
        $rule = $this->makeRule([
            'trigger_type' => UpsellRule::TRIGGER_CATEGORY_IN_CART,
            'trigger_value' => ['category_id' => 42],
        ]);
        $this->assertTrue($rule->matches([['item_id' => 1, 'category_id' => 42]], 0.0));
        $this->assertFalse($rule->matches([['item_id' => 1, 'category_id' => 99]], 0.0));
    }

    public function test_trigger_item_in_cart_matches_when_item_present(): void
    {
        $rule = $this->makeRule([
            'trigger_type' => UpsellRule::TRIGGER_ITEM_IN_CART,
            'trigger_value' => ['item_id' => 7],
        ]);
        $this->assertTrue($rule->matches([['item_id' => 7, 'category_id' => 1]], 0.0));
        $this->assertFalse($rule->matches([['item_id' => 8, 'category_id' => 1]], 0.0));
    }

    public function test_trigger_cart_total_gte(): void
    {
        $rule = $this->makeRule([
            'trigger_type' => UpsellRule::TRIGGER_CART_TOTAL_GTE,
            'trigger_value' => ['amount' => 15.0],
        ]);
        $this->assertTrue($rule->matches([], 15.0));
        $this->assertTrue($rule->matches([], 100.0));
        $this->assertFalse($rule->matches([], 14.99));
    }

    public function test_unknown_trigger_returns_false(): void
    {
        $rule = $this->makeRule([
            'trigger_type' => 'unknown_xyz',
            'trigger_value' => ['foo' => 'bar'],
        ]);
        $this->assertFalse($rule->matches([['item_id' => 1]], 50));
    }

    public function test_active_for_branch_scope_filters_inactive(): void
    {
        $branch = $this->makeBranch();
        $this->makeRule(['branch_id' => $branch->id, 'active' => false]);
        $active = $this->makeRule(['branch_id' => $branch->id, 'active' => true]);

        $rules = UpsellRule::activeForBranch($branch->id)->get();
        $this->assertSame([$active->id], $rules->pluck('id')->all());
    }

    public function test_active_for_branch_scope_respects_time_window(): void
    {
        $branch = $this->makeBranch();
        $future = $this->makeRule([
            'branch_id' => $branch->id,
            'starts_at' => now()->addDay(),
            'active' => true,
        ]);
        $past = $this->makeRule([
            'branch_id' => $branch->id,
            'ends_at' => now()->subDay(),
            'active' => true,
        ]);
        $ok = $this->makeRule([
            'branch_id' => $branch->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'active' => true,
        ]);

        $rules = UpsellRule::activeForBranch($branch->id)->get()->pluck('id')->all();
        $this->assertContains($ok->id, $rules);
        $this->assertNotContains($future->id, $rules);
        $this->assertNotContains($past->id, $rules);
    }

    public function test_priority_ordering_desc(): void
    {
        $branch = $this->makeBranch();
        $low = $this->makeRule(['branch_id' => $branch->id, 'priority' => 1]);
        $high = $this->makeRule(['branch_id' => $branch->id, 'priority' => 10]);

        $rules = UpsellRule::activeForBranch($branch->id)->get()->pluck('id')->all();
        $this->assertSame([$high->id, $low->id], $rules);
    }

    private function makeBranch(): Branch
    {
        return Branch::forceCreate([
            'name' => 'B'.uniqid(), 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75000', 'address' => '1 rue test', 'status' => 1,
        ]);
    }

    private function makeRule(array $overrides = []): UpsellRule
    {
        $branchId = $overrides['branch_id'] ?? $this->makeBranch()->id;

        // Create a dummy item for the FK constraint
        $categoryId = \DB::table('item_categories')->insertGetId([
            'name' => 'C'.uniqid(), 'slug' => 'c'.uniqid(), 'status' => 1,
            'sort' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId = \DB::table('items')->insertGetId([
            'name' => 'I'.uniqid(), 'slug' => 'i'.uniqid(),
            'item_category_id' => $categoryId, 'price' => 1,
            'item_type' => 1, 'status' => 1, 'order' => 1,
            'is_featured' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return UpsellRule::create(array_merge([
            'branch_id' => $branchId,
            'trigger_type' => UpsellRule::TRIGGER_CART_TOTAL_GTE,
            'trigger_value' => ['amount' => 10],
            'suggested_item_id' => $itemId,
            'active' => true,
            'priority' => 0,
        ], $overrides));
    }
}
