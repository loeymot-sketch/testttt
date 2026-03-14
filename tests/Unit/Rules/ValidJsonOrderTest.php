<?php

namespace Tests\Unit\Rules;

use Tests\TestCase;
use App\Rules\ValidJsonOrder;

/**
 * PLAN_03 D-004 — Tests de la règle ValidJsonOrder
 */
class ValidJsonOrderTest extends TestCase
{
    private function rule(): ValidJsonOrder
    {
        return new ValidJsonOrder();
    }

    /** @test */
    public function it_rejects_items_without_item_id()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', json_encode([['quantity' => 1, 'item_price' => 5.00]]));
        $this->assertFalse($result);
        $this->assertStringContainsString('item_id', $rule->message());
    }

    /** @test */
    public function it_rejects_items_with_zero_item_id()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', json_encode([['item_id' => 0, 'quantity' => 1]]));
        $this->assertFalse($result);
    }

    /** @test */
    public function it_rejects_items_with_negative_item_id()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', json_encode([['item_id' => -1, 'quantity' => 1]]));
        $this->assertFalse($result);
    }

    /** @test */
    public function it_rejects_items_without_quantity()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', json_encode([['item_id' => 4, 'item_price' => 5.00]]));
        $this->assertFalse($result);
        $this->assertStringContainsString('quantité', $rule->message());
    }

    /** @test */
    public function it_rejects_items_with_zero_quantity()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', json_encode([['item_id' => 4, 'quantity' => 0]]));
        $this->assertFalse($result);
    }

    /** @test */
    public function it_rejects_empty_array()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', json_encode([]));
        $this->assertFalse($result);
        $this->assertStringContainsString('au moins un article', $rule->message());
    }

    /** @test */
    public function it_rejects_invalid_json()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', 'not-json');
        $this->assertFalse($result);
        $this->assertStringContainsString('JSON invalide', $rule->message());
    }

    /** @test */
    public function it_rejects_non_string_input()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', 12345);
        $this->assertFalse($result);
    }

    /** @test */
    public function it_accepts_valid_item_array()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', json_encode([
            ['item_id' => 4, 'quantity' => 2, 'item_price' => 8.50]
        ]));
        $this->assertTrue($result);
    }

    /** @test */
    public function it_accepts_multiple_valid_items()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', json_encode([
            ['item_id' => 4, 'quantity' => 1],
            ['item_id' => 7, 'quantity' => 3],
        ]));
        $this->assertTrue($result);
    }

    /** @test */
    public function it_includes_index_in_error_message()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', json_encode([
            ['item_id' => 1, 'quantity' => 1],
            ['quantity' => 1], // index 1 sans item_id
        ]));
        $this->assertFalse($result);
        $this->assertStringContainsString('index 1', $rule->message());
    }
}
