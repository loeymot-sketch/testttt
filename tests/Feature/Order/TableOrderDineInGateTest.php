<?php

namespace Tests\Feature\Order;

use App\Enums\OrderType;
use App\Http\Requests\TableOrderRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * S17-01 — the public QR table-order endpoint must honor the V1 dine-in flag.
 *
 * The POS (PosOrderRequest) and kiosk (OrderRequest) paths reject DINING_TABLE
 * server-side when `pos_dine_in_enabled` is off, but TableOrderRequest had NO
 * such gate — anyone could POST a table order and create a dine-in order while
 * V1 ships "a emporter only" (owner 2026-06-05: close the hole / honor OFF).
 */
class TableOrderDineInGateTest extends TestCase
{
    use RefreshDatabase;

    private function runValidator(int $orderType = OrderType::DINING_TABLE)
    {
        $data = [
            'dining_table_id' => 1,
            'customer_id' => 1,
            'branch_id' => 1,
            'subtotal' => 10,
            'total' => 10,
            'order_type' => $orderType,
            'is_advance_order' => 0,
            'source' => 1,
            'items' => '[]',
        ];

        $request = TableOrderRequest::create('/api/frontend/table-order', 'POST', $data);
        // Bind as the global request so the withValidator() closure's request()
        // helper reads our payload (mirrors the production request lifecycle).
        app()->instance('request', $request);

        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);
        $validator->passes();

        return $validator;
    }

    public function test_table_order_rejected_when_dine_in_disabled(): void
    {
        Settings::group('pos')->set(['pos_dine_in_enabled' => 0]);

        $validator = $this->runValidator(OrderType::DINING_TABLE);

        $this->assertTrue(
            $validator->errors()->has('order_type'),
            'Public QR table order must be rejected when dine-in is disabled (V1)'
        );
    }

    public function test_table_order_allowed_when_dine_in_enabled(): void
    {
        Settings::group('pos')->set(['pos_dine_in_enabled' => 1]);

        $validator = $this->runValidator(OrderType::DINING_TABLE);

        $this->assertFalse(
            $validator->errors()->has('order_type'),
            'With dine-in enabled, a DINING_TABLE table order must not be blocked by the dine-in gate'
        );
    }
}
