<?php

namespace Tests\Feature\Abuse;

use App\Rules\ValidJsonOrder;
use Tests\TestCase;

/**
 * VECTOR order_max_quantity — abuse harness.
 * [abuse-heal 2026-06-18 engines]
 *
 * Finding (5-engine hard discovery, adversarially confirmed): ValidJsonOrder
 * rejected quantity <= 0 but had NO upper bound. A forged order line with
 * quantity = 1_000_000_000 was ACCEPTED → the per-line and order total scale
 * to absurd / integer-overflow-prone values (and the kiosk/POS/web create
 * paths all funnel their `items` JSON through this rule). The fix caps the
 * per-item quantity at a sane ceiling (9999) so an abusive line is rejected
 * at the validation layer, before any pricing / fiscal computation.
 *
 * Pairs with the existing items-count DoS cap (ValidJsonOrderItemCapTest,
 * cap = 50 lines) — this is the per-line quantity counterpart.
 */
class OrderMaxQuantityAbuseTest extends TestCase
{
    private function rule(): ValidJsonOrder
    {
        return new ValidJsonOrder();
    }

    private function itemsJson(int $quantity): string
    {
        return json_encode([['item_id' => 1, 'quantity' => $quantity]]);
    }

    /**
     * ABUSE — a billion-unit line is rejected (was accepted before the cap).
     */
    public function test_absurd_quantity_one_billion_is_rejected(): void
    {
        $rule = $this->rule();
        $this->assertFalse(
            $rule->passes('items', $this->itemsJson(1_000_000_000)),
            'quantity 10^9 must be rejected to prevent overflow / absurd totals'
        );
    }

    /**
     * CONTROL — a normal quantity still passes.
     */
    public function test_normal_quantity_five_is_accepted(): void
    {
        $rule = $this->rule();
        $this->assertTrue(
            $rule->passes('items', $this->itemsJson(5)),
            'a normal order line (qty 5) must still pass'
        );
    }

    /**
     * BOUNDARY — 9999 is the inclusive cap and must pass.
     */
    public function test_boundary_9999_is_accepted(): void
    {
        $rule = $this->rule();
        $this->assertTrue(
            $rule->passes('items', $this->itemsJson(9999)),
            '9999 is the inclusive per-line quantity cap and must pass'
        );
    }

    /**
     * BOUNDARY — 10000 is over the cap and must be rejected.
     */
    public function test_boundary_10000_is_rejected(): void
    {
        $rule = $this->rule();
        $this->assertFalse(
            $rule->passes('items', $this->itemsJson(10000)),
            '10000 is one over the per-line cap and must be rejected'
        );
    }
}
