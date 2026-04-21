<?php

namespace Tests\Feature;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Http\Resources\OrderDetailsResource;
use App\Http\Resources\OrderItemResource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosReceiptFiscalExposureTest extends TestCase
{
    use RefreshDatabase;

    private function makePosOrderWithBranchUser(): array
    {
        $branch = Branch::factory()->create([
            'siret' => '73282932000074',
            'vat_intra' => 'FR12345678901',
            'register_id' => 'POS-001',
            'legal_footer' => 'Merci de votre visite.',
        ]);
        $user = User::factory()->create(['branch_id' => $branch->id, 'name' => 'Jane Operator']);
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'order_type' => OrderType::POS,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 50.00,
            'total' => 42.00,
        ]);
        $order->forceFill(['fiscal_sequence_no' => 1001])->save();
        $order->refresh();
        $order->load(['branch', 'user', 'orderItems']);

        return [$branch, $user, $order];
    }

    public function test_order_details_resource_exposes_fiscal_branch_operator_and_payments_breakdown(): void
    {
        [, , $order] = $this->makePosOrderWithBranchUser();

        $data = (new OrderDetailsResource($order))->toArray(request());

        $this->assertSame(1001, $data['fiscal_sequence_no']);
        $this->assertSame('73282932000074', $data['pos_siret']);
        $this->assertSame('POS-001', $data['pos_register_id']);
        $this->assertSame('Jane Operator', $data['operator_name']);
        $this->assertIsArray($data['payments_breakdown']);
        $this->assertCount(1, $data['payments_breakdown']);
        $this->assertSame(PosPaymentMethod::CASH, $data['payments_breakdown'][0]['method']);
        $this->assertEqualsWithDelta(50.0, (float) $data['payments_breakdown'][0]['amount'], 0.01);
    }

    public function test_legacy_order_without_fiscal_sequence_exposes_null(): void
    {
        [, , $order] = $this->makePosOrderWithBranchUser();
        $order->forceFill(['fiscal_sequence_no' => null])->save();
        $order->refresh();
        $order->load(['branch', 'user', 'orderItems']);

        $data = (new OrderDetailsResource($order))->toArray(request());

        $this->assertNull($data['fiscal_sequence_no']);
    }

    public function test_audit_chain_fingerprint_is_twelve_hex_chars_when_audit_log_exists(): void
    {
        [, , $order] = $this->makePosOrderWithBranchUser();
        $hash = str_repeat('c', 64);
        $log = AuditLog::create([
            'branch_id' => $order->branch_id,
            'user_id' => $order->user_id,
            'action' => 'order.test_audit',
            'resource' => 'order',
            'resource_id' => $order->id,
            'payload' => ['ok' => true],
            'prev_hash' => str_repeat('d', 64),
            'current_hash' => $hash,
        ]);

        $order->refresh();
        $order->load(['branch', 'user', 'orderItems']);

        $expected = substr(hash('sha256', $hash.'|'.$log->id), 0, 12);
        $data = (new OrderDetailsResource($order))->toArray(request());

        $this->assertSame($expected, $data['audit_chain_fingerprint']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', (string) $data['audit_chain_fingerprint']);
    }

    public function test_audit_chain_fingerprint_is_null_without_audit_row(): void
    {
        [, , $order] = $this->makePosOrderWithBranchUser();

        $data = (new OrderDetailsResource($order))->toArray(request());

        $this->assertNull($data['audit_chain_fingerprint']);
    }

    /**
     * [V14 GLOBAL FINDING G-1 P0 + G-1B P1] Cross-wave sentinel A↔C-β.
     *
     * Vague A (T07) writes an immutable `composition_snapshot` JSON column on
     * order_items. Vague C-β (T21) added the receipt redesign but the
     * frontend template historically read `variation.name` — which does NOT
     * exist in the snapshot shape (`{variation_id, attribute_name,
     * variation_name, quantity, unit_price}`). The ReceiptComponent now goes
     * through `normalizeReceiptVariations` (frontend helper) which assumes
     * the resource exposes the snapshot shape on `item_variations`.
     *
     * This test locks the API contract : `OrderItemResource` MUST surface
     * the snapshot lines under the `item_variations` key, with the keys
     * `attribute_name`, `variation_name`, and `quantity` populated. If a
     * future refactor breaks that contract, the receipt would print
     * "undefined" for every line of every order created post-T07 — a NF525
     * fiscal immutability incident.
     */
    public function test_order_item_resource_returns_snapshot_lines_for_receipt_consumption(): void
    {
        [$branch, , $order] = $this->makePosOrderWithBranchUser();

        $item = Item::factory()->create();

        $snapshot = [
            'schema_version' => 1,
            'item_name' => $item->name,
            'lines' => [
                [
                    'variation_id' => 5,
                    'attribute_name' => 'Viande',
                    'variation_name' => 'Steak',
                    'quantity' => 3,
                    'unit_price' => 0,
                ],
                [
                    'variation_id' => 7,
                    'attribute_name' => 'Viande',
                    'variation_name' => 'Poulet',
                    'quantity' => 1,
                    'unit_price' => 0,
                ],
            ],
            'extras' => [
                ['extra_id' => 11, 'name' => 'Cheddar', 'quantity' => 2, 'unit_price' => 0.5],
            ],
            'captured_at' => '2026-04-20T12:00:00Z',
        ];

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'discount' => 0,
            'price' => 9.90,
            'item_variations' => json_encode([['id' => 5, 'variation_name' => 'Viande', 'name' => 'Steak']]),
            'item_extras' => json_encode([['name' => 'Cheddar']]),
            'composition_snapshot' => $snapshot,
            'item_variation_total' => 0,
            'item_extra_total' => 1,
            'total_price' => 10.90,
            'tax_name' => 'TVA 10',
            'tax_rate' => 10,
            'tax_type' => 1,
            'tax_amount' => 0.99,
        ]);

        $payload = (new OrderItemResource($orderItem))->toArray(request());

        $this->assertSame(2, count($payload['item_variations']),
            'Expected 2 snapshot variation lines (3× Steak + 1× Poulet).');
        $first = $payload['item_variations'][0];
        $this->assertSame('Viande', $first['attribute_name'] ?? null);
        $this->assertSame('Steak', $first['variation_name'] ?? null);
        $this->assertSame(3, (int) ($first['quantity'] ?? 0),
            'Receipt template needs `quantity` to render "3× Steak" (G-2).');

        $this->assertSame(1, count($payload['item_extras']));
        $this->assertSame('Cheddar', $payload['item_extras'][0]['name'] ?? null);
        $this->assertSame(2, (int) ($payload['item_extras'][0]['quantity'] ?? 0));

        $this->assertNotEmpty($payload['composition_snapshot']);
        $this->assertSame(1, $payload['composition_snapshot']['schema_version']);
    }
}
