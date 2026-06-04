<?php

namespace Tests\Unit\Rules;

use Tests\TestCase;
use App\Rules\ValidJsonOrder;

/**
 * Gap-Hunt 2026-05-25 Phase A.2 — DoS protection: cap order items at 50.
 *
 * Sentinel locks the cap=50 contract — count > 50 must be rejected,
 * count == 50 must pass, baseline 1-item must pass.
 */
class ValidJsonOrderItemCapTest extends TestCase
{
    private function rule(): ValidJsonOrder
    {
        return new ValidJsonOrder();
    }

    /** @test */
    public function it_rejects_51_items()
    {
        $rule = $this->rule();
        $items = json_encode(array_fill(0, 51, ['item_id' => 1, 'quantity' => 1]));
        $result = $rule->passes('items', $items);
        $this->assertFalse($result, '51 items must be rejected to prevent DoS');
        $this->assertStringContainsString('50', $rule->message());
    }

    /** @test */
    public function it_accepts_50_items()
    {
        $rule = $this->rule();
        $items = json_encode(array_fill(0, 50, ['item_id' => 1, 'quantity' => 1]));
        $result = $rule->passes('items', $items);
        $this->assertTrue($result, '50 items is the inclusive cap and must pass');
    }

    /** @test */
    public function it_accepts_1_item_baseline()
    {
        $rule = $this->rule();
        $items = json_encode([['item_id' => 1, 'quantity' => 1]]);
        $result = $rule->passes('items', $items);
        $this->assertTrue($result, 'baseline single-item order must still pass');
    }
}
