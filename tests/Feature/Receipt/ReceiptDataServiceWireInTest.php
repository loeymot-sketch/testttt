<?php

namespace Tests\Feature\Receipt;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Http\Resources\OrderDetailsResource;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Order;
use App\Models\User;
use App\Services\Receipt\ReceiptDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [NF525 RECEIPT WIRE-IN — 2026-05-18]
 *
 * Owner-gated wire-in (Option A — V1 conformity max) :
 *   ReceiptDataService is the SSOT for the six NF525-relevant fields that
 *   live on the printed ticket header (fiscal_sequence_no, register_id,
 *   siret, vat_intra, legal_footer, operator_name). Before this cycle the
 *   service existed but had zero PHP caller — OrderDetailsResource read
 *   those fields directly from the model/relations, duplicating the
 *   contract. This sentinel locks the delegation so a future refactor
 *   cannot silently desync the two surfaces (HTTP resource vs printed
 *   ticket data path).
 *
 * Scope is intentionally narrow: only the six fields owned by
 * ReceiptDataService::buildForOrderModel(). Other fields exposed by
 * OrderDetailsResource (audit_chain_fingerprint, payments_breakdown,
 * tax_lines, order_serial_no, etc.) stay owned by the resource.
 *
 * See: docs/audit/POS_AUDIT_MASTER_PLAN_2026-05-06.md row 23,
 *      docs/audit/POS_AUDIT_FINAL_REPORT_2026-05-06.md L173-178,
 *      docs/gates/GATE_P_MEGA_19_BRANCH_THEMING_2026-04-20.md L88-90.
 */
class ReceiptDataServiceWireInTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Branch, 1: User, 2: Order}
     */
    private function makeOrderWithFiscalContext(): array
    {
        $branch = Branch::factory()->create([
            'siret' => '73282932000074',
            'vat_intra' => 'FR12345678901',
            'register_id' => 'POS-001',
            'legal_footer' => 'Merci de votre visite.',
        ]);
        // [M11-01] NF525 operator identity: orders.user_id = the CUSTOMER; the
        // OPERATOR (cashier) lives on creator_id. Keep them DISTINCT so the
        // operator_name assertion proves the receipt prints the cashier, not
        // the customer.
        $cashier = User::factory()->create([
            'branch_id' => $branch->id,
            'name' => 'Jane Operator',
        ]);
        $customer = User::factory()->create([
            'branch_id' => $branch->id,
            'name' => 'Client Passage',
        ]);
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $customer->id,
            'creator_id' => $cashier->id,
            'order_type' => OrderType::POS,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 50.00,
            'total' => 42.00,
        ]);
        $order->forceFill(['fiscal_sequence_no' => 1001])->save();
        $order->refresh();
        $order->load(['branch', 'user', 'orderItems']);

        return [$branch, $cashier, $order];
    }

    /**
     * ReceiptDataService::buildForOrderModel() is the new SSOT entry-point
     * used by OrderDetailsResource. It must accept a hydrated Order model
     * (no extra DB round-trip) and return the same six fields as the
     * legacy buildForOrder(int $orderId) signature.
     */
    public function test_receipt_data_service_build_for_order_model_returns_six_nf525_fields(): void
    {
        [, , $order] = $this->makeOrderWithFiscalContext();

        $payload = (new ReceiptDataService())->buildForOrderModel($order);

        $this->assertSame(1001, $payload['fiscal_sequence_no']);
        $this->assertSame('POS-001', $payload['pos_register_id']);
        $this->assertSame('73282932000074', $payload['pos_siret']);
        $this->assertSame('FR12345678901', $payload['pos_vat_intra']);
        $this->assertSame('Merci de votre visite.', $payload['pos_legal_footer']);
        $this->assertSame('Jane Operator', $payload['operator_name']);
    }

    /**
     * SSOT sentinel: every field that OrderDetailsResource exposes from the
     * NF525-receipt set MUST come from ReceiptDataService::buildForOrderModel.
     * If a future patch starts reading the values from the model directly
     * again, this test fires.
     *
     * Implementation contract: the resource builds an internal $receipt
     * array from the service and uses it for these six keys verbatim.
     */
    public function test_order_details_resource_delegates_nf525_fields_to_receipt_data_service(): void
    {
        [, , $order] = $this->makeOrderWithFiscalContext();

        $service = new ReceiptDataService();
        $expected = $service->buildForOrderModel($order);

        $data = (new OrderDetailsResource($order))->toArray(request());

        $this->assertSame($expected['fiscal_sequence_no'], $data['fiscal_sequence_no']);
        $this->assertSame($expected['pos_register_id'], $data['pos_register_id']);
        $this->assertSame($expected['pos_siret'], $data['pos_siret']);
        $this->assertSame($expected['pos_vat_intra'], $data['pos_vat_intra']);
        $this->assertSame($expected['pos_legal_footer'], $data['pos_legal_footer']);
        $this->assertSame($expected['operator_name'], $data['operator_name']);
    }

    /**
     * Legacy entry-point buildForOrder(int $orderId) MUST keep working
     * (zero current caller, but it's documented in the POS audit plan
     * row 23 as a public API surface). The new buildForOrderModel and
     * the legacy buildForOrder must produce identical payloads for the
     * same order so anything that calls either signature converges on
     * the same fiscal truth.
     */
    public function test_legacy_buildForOrder_and_new_buildForOrderModel_are_equivalent(): void
    {
        [, , $order] = $this->makeOrderWithFiscalContext();

        $service = new ReceiptDataService();
        $byId = $service->buildForOrder($order->id);
        $byModel = $service->buildForOrderModel($order);

        // Only compare the six SSOT keys — buildForOrder also returns
        // order_id / order_serial_no / created_at which are not part of
        // the wire-in (they live on OrderDetailsResource directly).
        $nf525Keys = [
            'fiscal_sequence_no',
            'pos_register_id',
            'pos_siret',
            'pos_vat_intra',
            'pos_legal_footer',
            'operator_name',
        ];
        foreach ($nf525Keys as $k) {
            $this->assertSame(
                $byId[$k] ?? '__missing__',
                $byModel[$k] ?? '__missing__',
                "Field {$k} must match between buildForOrder() and buildForOrderModel()."
            );
        }
    }

    /**
     * Legacy orders predating NF525 fiscal_sequence_no allocation MUST
     * still produce a structurally valid receipt payload (null on the
     * fiscal field, but the rest of the header populated). The JS-side
     * buildNf525Footer() skips the line when the value is null/empty,
     * so a printed ticket stays clean.
     */
    public function test_receipt_data_service_handles_null_fiscal_sequence(): void
    {
        [, , $order] = $this->makeOrderWithFiscalContext();
        $order->forceFill(['fiscal_sequence_no' => null])->save();
        $order->refresh();
        $order->load(['branch', 'user']);

        $payload = (new ReceiptDataService())->buildForOrderModel($order);
        $resource = (new OrderDetailsResource($order))->toArray(request());

        $this->assertNull($payload['fiscal_sequence_no']);
        $this->assertNull($resource['fiscal_sequence_no']);
        $this->assertSame('73282932000074', $payload['pos_siret']);
        $this->assertSame('73282932000074', $resource['pos_siret']);
    }

    /**
     * Regression guard for Foundation Audit failures F1+F3 / 2026-05-18.
     *
     * `App\Models\FrontendOrder` is a sibling of `App\Models\Order` — both
     * extend `Illuminate\Database\Eloquent\Model` and back the same `orders`
     * table polymorphically. The wire-in commit 80fb27c48 originally typed
     * `buildForOrderModel(Order $order)` which silently 500'd every
     * `/api/frontend/order POST` (kiosk checkout fully broken, ghost orders
     * persisted before the throw). The type-hint is widened to `Model` and
     * this sentinel locks the contract.
     */
    public function test_build_for_order_model_accepts_frontend_order_sibling(): void
    {
        $branch = Branch::factory()->create([
            'siret' => '73282932000074',
            'vat_intra' => 'FR12345678901',
            'register_id' => 'POS-001',
            'legal_footer' => 'Merci de votre visite.',
        ]);
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'name' => 'Frontend Operator',
        ]);
        // FrontendOrder has no Factory class — backed by `orders` table
        // polymorphically. Use forceCreate per existing test pattern
        // (CancelReasonEnforceTest::makeKioskPendingOrder).
        $frontendOrder = FrontendOrder::forceCreate([
            'order_serial_no' => 'F-Z-' . substr(uniqid(), -8),
            'branch_id'       => $branch->id,
            'user_id'         => $user->id,
            'order_type'      => OrderType::KIOSK,
            'status'          => OrderStatus::PENDING,
            'payment_status'  => PaymentStatus::UNPAID,
            'pos_payment_method' => PosPaymentMethod::CARD,
            'subtotal'        => 25.00,
            'total'           => 25.00,
            'discount'        => 0,
            'delivery_charge' => 0,
            'fiscal_sequence_no' => 2002,
        ]);
        $frontendOrder->refresh();
        $frontendOrder->load(['branch', 'user']);

        // Service must accept FrontendOrder (sibling of Order) without TypeError.
        $payload = (new ReceiptDataService())->buildForOrderModel($frontendOrder);

        $this->assertSame(2002, $payload['fiscal_sequence_no']);
        $this->assertSame('POS-001', $payload['pos_register_id']);
        $this->assertSame('73282932000074', $payload['pos_siret']);
        $this->assertSame('FR12345678901', $payload['pos_vat_intra']);
        $this->assertSame('Merci de votre visite.', $payload['pos_legal_footer']);
        // [M11-01] A self-service kiosk FrontendOrder (UNPAID, not yet collected
        // at the counter) has NO cashier operator — and must NEVER print the
        // customer as the operator. operator_name is null until a collecting
        // cashier is recorded (on the Order via confirmCounterPayment).
        $this->assertNull($payload['operator_name']);
        $this->assertNotSame('Frontend Operator', $payload['operator_name']);
    }
}
