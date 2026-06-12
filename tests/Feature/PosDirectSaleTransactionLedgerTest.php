<?php

namespace Tests\Feature;

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
use App\Models\Tax;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * [HEAL dispute-r1 ADV-B-07 / E-ADV-2 2026-06-12] POS direct sales must land
 * in the unified `transactions` ledger.
 *
 * Pre-fix, `type='payment'` rows were only written by gateway callbacks
 * (PaymentService::payment, assertGatewayContext-gated) and by the counter
 * collect confirm (COUNTER-*). A POS direct sale (paid inline at creation)
 * had NO Transaction writer at all → « Vue Caisse Unifiée » and
 * /admin/transactions showed the gérant 39,80 € while ~89,17 € were actually
 * collected (−55%) — every direct caisse sale invisible in the money ledger.
 *
 * No double-count: deferred (counter-collect) POS orders get their single
 * Transaction at collection time only.
 */
class PosDirectSaleTransactionLedgerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    public function test_pos_direct_cash_sale_writes_payment_transaction(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload] = $this->fixture();

        $orderId = $this->commitPosSale($operator, $payload);

        $txn = Transaction::query()->where('order_id', $orderId)->where('type', 'payment')->first();
        $this->assertNotNull($txn, 'POS direct sale must write a payment Transaction');
        $this->assertSame(10.0, round((float) $txn->amount, 2));
        $this->assertSame('cash', (string) $txn->payment_method);
        $this->assertSame('+', (string) $txn->sign);
        $this->assertSame(1, Transaction::query()->where('order_id', $orderId)->count(), 'exactly one ledger row');
    }

    public function test_pos_deferred_counter_order_writes_single_transaction_at_collection_only(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload] = $this->fixture();

        $payload['defer_to_counter'] = true;
        $payload['pos_payment_method'] = PosPaymentMethod::COUNTER_DEFERRED;
        unset($payload['pos_received_amount']);

        $orderId = $this->commitPosSale($operator, $payload, received: null);

        $this->assertSame(
            0,
            Transaction::query()->where('order_id', $orderId)->count(),
            'deferred order must NOT mint a transaction at creation (double-count guard)'
        );

        $order = Order::query()->findOrFail($orderId);
        $this->actingAs($operator, 'sanctum');
        app(PaymentService::class)->confirmCounterPayment($order, PosPaymentMethod::CASH, 10.0);

        $rows = Transaction::query()->where('order_id', $orderId)->where('type', 'payment')->get();
        $this->assertCount(1, $rows, 'exactly ONE transaction after counter collection');
        $this->assertStringStartsWith('COUNTER-', (string) $rows->first()->transaction_no);
    }

    public function test_cash_overview_aggregates_include_pos_direct_sales(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload, $branch] = $this->fixture();

        $this->commitPosSale($operator, $payload);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('cash-sessions-report');

        $summary = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/cash-overview')
            ->assertOk()
            ->json('summary');

        $this->assertSame(10.0, (float) $summary['total'], 'grand total must include the POS direct sale');
        $this->assertSame(10.0, (float) ($summary['by_source']['caisse']['total'] ?? 0), 'caisse bucket must see the sale');
        $this->assertSame(10.0, (float) ($summary['by_mode']['cash']['total'] ?? 0), 'cash mode bucket must see the sale');
    }

    /**
     * Quote + commit a POS sale through the real HTTP flow. Returns order id.
     */
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
