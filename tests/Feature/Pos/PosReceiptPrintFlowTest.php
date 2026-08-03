<?php

namespace Tests\Feature\Pos;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Printer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ULTRA-AUDIT 2026-06-28] Real e2e of the POS print FLOW through the HTTP
 * controller (not just the renderer): printer selection + station fallback,
 * best-effort ESC/POS, print-count. Bypass mode = NullPrinterTransport so the
 * full path runs without hardware.
 */
class PosReceiptPrintFlowTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['printing.bypass.enabled' => true]); // NullPrinterTransport → send() true
        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->user->assignRole('Admin');
    }

    private function printer(string $station): Printer
    {
        $p = (new Printer)->forceFill([
            'branch_id' => $this->branch->id,
            'name' => $station,
            'type' => 'escpos_usb_windows',
            'host' => 'SAGA',
            'station' => $station,
            'width_chars' => 48,
            'status' => Status::ACTIVE,
            'options' => ['code_page' => 19],
        ]);
        $p->save();

        return $p;
    }

    private function order(): Order
    {
        // The flow test validates printer SELECTION + transport + count; the ticket
        // CONTENT is covered by OrderReceiptEscPosRendererTest. An item-less order is
        // enough here and avoids the heavy order_items NOT-NULL fixture.
        return Order::factory()->create([
            'branch_id' => $this->branch->id,
            'queue_number' => 'A0099',
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'pos_payment_method' => 1,
            'pos_received_amount' => 9.90,
            'payment_status' => \App\Enums\PaymentStatus::PAID,
            'subtotal' => 9.90, 'total' => 9.90, 'total_tax' => 0.90,
        ]);
    }

    public function test_print_receipt_runs_full_flow_and_increments_count(): void
    {
        $this->printer('receipt');
        $order = $this->order();

        $res = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/admin/pos/orders/{$order->id}/print-receipt",
            [],
            ['X-Idempotency-Key' => 'print-' . uniqid()]
        );
        $res->assertOk()->assertJson(['printed_escpos' => true]);
        $this->assertSame(1, (int) $order->fresh()->receipt_print_count);
    }

    public function test_kitchen_falls_back_to_receipt_printer_when_no_kitchen_printer(): void
    {
        $this->printer('receipt'); // only a receipt printer exists
        $order = $this->order();

        $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/admin/pos/orders/{$order->id}/print-kitchen",
            [],
            ['X-Idempotency-Key' => 'kitchen-' . uniqid()]
        )->assertOk()->assertJson(['printed_escpos' => true]); // fell back to receipt
    }

    public function test_kitchen_routes_to_kitchen_printer_and_receipt_is_station_selective(): void
    {
        $this->printer('kitchen_hot'); // ONLY a kitchen printer, no receipt
        $order = $this->order();
        $auth = fn () => $this->actingAs($this->user, 'sanctum');

        // kitchen ticket finds the kitchen_hot printer
        $auth()->postJson("/api/admin/pos/orders/{$order->id}/print-kitchen", [], ['X-Idempotency-Key' => 'k-' . uniqid()])
            ->assertOk()->assertJson(['printed_escpos' => true]);
        // client ticket finds NO receipt printer → not printed thermally
        $auth()->postJson("/api/admin/pos/orders/{$order->id}/print-receipt", [], ['X-Idempotency-Key' => 'r-' . uniqid()])
            ->assertOk()->assertJson(['printed_escpos' => false]);
    }
}
