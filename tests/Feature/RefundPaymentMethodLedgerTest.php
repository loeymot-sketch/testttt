<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Http\Requests\OrderStatusRequest;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * [HEAL dispute-r1 B-R1-15 / E-ADV-5 2026-06-12] Refund ledger mode.
 *
 * Pre-fix, every changeStatus→RETURNED / CANCELED refund called
 * PaymentService::cashBack($order, 'credit', 'TXN-…') with a HARDCODED
 * 'credit' slug — TransactionResource maps 'credit' to « Carte bancaire »,
 * so a CASH refund (money physically out of the drawer, cash_movements OUT)
 * displayed « Carte bancaire » on /admin/transactions: the ledger lied about
 * the refund mode (×2 observed live: −3,80 € and −25,00 €).
 *
 * Fix: the cash_back row carries the REAL mode of the original encashment —
 * the prior `payment` Transaction's payment_method (cash→cash,
 * counter_cash→counter_cash, …).
 */
class RefundPaymentMethodLedgerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    public function test_pos_cash_refund_writes_cash_mode_not_credit(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload] = $this->fixture();

        $orderId = $this->commitPosSale($operator, $payload);
        $order = Order::query()->findOrFail($orderId);
        $this->assertSame('cash', (string) $order->transaction?->payment_method, 'precondition: sale ledger row is cash');

        $this->refund($operator, $order);

        $cashBack = Transaction::query()->where('order_id', $orderId)->where('type', 'cash_back')->first();
        $this->assertNotNull($cashBack, 'refund must write a cash_back row');
        $this->assertSame('cash', (string) $cashBack->payment_method, 'refund mode must mirror the cash encashment, not the hardcoded credit slug');
        $this->assertSame('-', (string) $cashBack->sign);
    }

    public function test_counter_collected_kiosk_style_refund_keeps_counter_cash_mode(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload] = $this->fixture();

        $payload['defer_to_counter'] = true;
        $payload['pos_payment_method'] = PosPaymentMethod::COUNTER_DEFERRED;
        unset($payload['pos_received_amount']);
        $orderId = $this->commitPosSale($operator, $payload, received: null);

        $order = Order::query()->findOrFail($orderId);
        $this->actingAs($operator, 'sanctum');
        app(PaymentService::class)->confirmCounterPayment($order, PosPaymentMethod::CASH, 10.0);
        $order->refresh();
        $this->assertSame('counter_cash', (string) $order->transaction?->payment_method);

        $this->refund($operator, $order);

        $cashBack = Transaction::query()->where('order_id', $orderId)->where('type', 'cash_back')->first();
        $this->assertNotNull($cashBack);
        $this->assertSame('counter_cash', (string) $cashBack->payment_method);
    }

    private function refund(User $operator, Order $order): void
    {
        Permission::firstOrCreate(['name' => 'pos-refund', 'guard_name' => 'sanctum']);
        $operator->givePermissionTo('pos-refund');
        Auth::guard('sanctum')->setUser($operator);
        $this->actingAs($operator, 'sanctum');

        $statusRequest = OrderStatusRequest::create('/', 'POST', [
            'status' => OrderStatus::RETURNED,
            'reason' => 'repro B-R1-15 dispute round-1',
        ]);
        $statusRequest->setContainer(app())->setRedirector(app('redirect'));
        $statusRequest->setUserResolver(fn () => $operator);

        $result = app(OrderService::class)->changeStatus($order->fresh(), $statusRequest);
        $this->assertNotNull($result);
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
     * @return array{0: User, 1: array<string, mixed>}
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
        ]];
    }
}
