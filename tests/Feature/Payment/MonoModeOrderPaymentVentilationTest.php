<?php

namespace Tests\Feature\Payment;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Scopes\BranchScope;
use App\Models\Tax;
use App\Models\User;
use App\Services\Fiscal\ZReportCashEnrichmentService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * [HEAL dispute-r1 E-ADV-9 2026-06-12] order_payments ventilation.
 *
 * Pre-fix only SplitPaymentService (multi-tender, flag default OFF) wrote
 * `order_payments` rows — the mono-mode POS sale and the counter-collect
 * confirm (the QUASI-TOTALITY of V1 payments) never did. The Z report TPE
 * ventilation (frozen ZReportCashEnrichmentService::aggregateByTerminal,
 * additive decorator OUTSIDE the HMAC signature — adversarially verified)
 * reads OrderPayment exclusively → structurally empty.
 *
 * Healed: one OrderPayment row per mono-mode encashment (POS inline +
 * counter-collect), same shape as the split tranches. No double-count:
 * the writers skip when a split breakdown is active.
 */
class MonoModeOrderPaymentVentilationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    public function test_pos_direct_cash_sale_writes_order_payment_row(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload, $branch] = $this->fixture();

        $orderId = $this->commitPosSale($operator, $payload);

        $row = OrderPayment::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('order_id', $orderId)
            ->first();

        $this->assertNotNull($row, 'mono-mode POS sale must write its order_payments row');
        $this->assertSame(PosPaymentMethod::CASH, (int) $row->mode);
        $this->assertSame(10.0, round((float) $row->amount, 2));
        $this->assertSame(10.0, round((float) $row->tendered, 2));
        $this->assertSame((int) $branch->id, (int) $row->branch_id);
        $this->assertNotNull($row->paid_at);
    }

    public function test_counter_collect_confirm_writes_order_payment_row(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload] = $this->fixture();

        $payload['defer_to_counter'] = true;
        $payload['pos_payment_method'] = PosPaymentMethod::COUNTER_DEFERRED;
        unset($payload['pos_received_amount']);
        $orderId = $this->commitPosSale($operator, $payload, received: null);

        $this->assertSame(0, OrderPayment::query()->withoutGlobalScope(BranchScope::class)->where('order_id', $orderId)->count());

        $order = Order::query()->findOrFail($orderId);
        $this->actingAs($operator, 'sanctum');
        app(PaymentService::class)->confirmCounterPayment($order, PosPaymentMethod::CASH, 10.0);

        $rows = OrderPayment::query()->withoutGlobalScope(BranchScope::class)->where('order_id', $orderId)->get();
        $this->assertCount(1, $rows, 'counter-collect must write exactly one order_payments row');
        $this->assertSame(PosPaymentMethod::CASH, (int) $rows->first()->mode);
        $this->assertSame(10.0, round((float) $rows->first()->amount, 2));
    }

    public function test_z_terminal_ventilation_sees_the_mono_mode_sale(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload, $branch] = $this->fixture();

        $this->commitPosSale($operator, $payload);

        $buckets = app(ZReportCashEnrichmentService::class)->aggregateByTerminal(
            (int) $branch->id,
            null,
            now()->addMinute()
        );

        $this->assertNotEmpty($buckets, 'TPE ventilation must no longer be structurally empty');
        $this->assertSame(10.0, round((float) $buckets[0]['cash_total'], 2));
        $this->assertSame(1, (int) $buckets[0]['transactions_count']);
    }

    private function commitPosSale(User $operator, array $payload, ?float $received = 10.0): int
    {
        $quote = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        $commit = $payload;
        $commit['quote_token'] = $quote['quote_token'];
        $commit['quote_signature'] = $quote['signature'];
        $commit['total'] = $quote['total_ttc'];
        if ($received !== null) {
            $commit['pos_received_amount'] = $received;
        }

        $response = $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos', $commit);

        $this->assertContains($response->status(), [200, 201], $response->getContent());

        return (int) $response->json('data.id');
    }

    /**
     * @return array{0: User, 1: array<string, mixed>, 2: Branch}
     */
    private function fixture(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');
        $this->seedOpenSessionFor($operator, $branch);
        $customer = User::factory()->create(['branch_id' => $branch->id]);
        $customer->assignRole('Customer');

        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);

        return [$operator, [
            'token' => null,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'subtotal' => 0,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::POS,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 10,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $branch];
    }
}
