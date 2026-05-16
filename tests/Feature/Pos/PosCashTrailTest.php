<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Tax;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [Sprint 1B 2026-05-16] NF525 cash trail backend tests.
 *
 * Couvre les paths suivants :
 *   1. POS direct CASH + session OPEN → order créé + 1 cash_movement IN
 *   2. POS direct CASH sans session OPEN → 422 + 0 order + 0 cash_movement
 *   3. SplitPayment (1 CASH + 1 CARD) + session OPEN → 1 movement IN
 *      (CASH tranche only, montant = tranche.amount ≠ order.total)
 *   4. SplitPayment 2 CASH tranches sans session → 422 + 0 movement
 *   5. Régression kiosk counter-collect : confirmCounterPayment continue
 *      à écrire un movement quand session OPEN existe (non-breakage)
 *   6. Régression kiosk counter-collect sans session → order PAID + log
 *      (legacy F-003 best-effort)
 */
class PosCashTrailTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branch;
    protected User $customer;
    protected User $operator;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('split_payment.enabled', true);
        Config::set('split_payment.max_tranches', 12);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        $this->branch = Branch::factory()->create();

        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => '0102030400',
        ]);
        $this->customer->assignRole('Customer');

        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => '0102030401',
        ]);
        $this->operator->assignRole('POS Operator');

        $tax = Tax::factory()->create([
            'name' => 'TVA 0%',
            'code' => 'TVA0',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 0.00,
            'status' => Status::ACTIVE,
        ]);

        $cat = ItemCategory::factory()->create([
            'name' => 'Cat',
            'wizard_template' => 'tacos',
            'has_menu' => true,
        ]);

        $this->item = Item::factory()->create([
            'item_category_id' => $cat->id,
            'tax_id' => $tax->id,
            'name' => 'Item',
            'price' => 25.00,
            'status' => Status::ACTIVE,
        ]);
    }

    private function openSessionForOperator(float $opening = 100.00): CashDrawerSession
    {
        // Use service so I1 + I3 invariants are respected.
        return app(CashDrawerService::class)
            ->openSession($this->branch->id, $this->operator->id, $opening);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'discount' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 25,
            'items' => json_encode([
                [
                    'item_id' => $this->item->id,
                    'item_price' => $this->item->price,
                    'quantity' => 1,
                    'total_price' => 25.00,
                    'item_variations' => [],
                    'item_extras' => [],
                ],
            ]),
        ], $overrides);
    }

    /** Path 1 — POS direct CASH + session OPEN → 1 movement IN */
    public function test_pos_cash_with_open_session_writes_one_cash_movement(): void
    {
        $this->actingAs($this->operator, 'sanctum');
        $session = $this->openSessionForOperator(100.00);

        $payload = $this->basePayload(); // single-tender CASH, pas de breakdown

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');
        $this->assertNotNull($orderId);

        $movements = CashMovement::query()
            ->where('order_id', $orderId)
            ->where('cash_drawer_session_id', $session->id)
            ->get();

        $this->assertCount(1, $movements, 'POS direct CASH doit écrire exactement 1 cash_movement IN.');
        $m = $movements->first();
        $this->assertSame(CashMovement::TYPE_ORDER_PAYMENT, $m->type);
        $this->assertSame(CashMovement::DIRECTION_IN, $m->direction);
        $this->assertEqualsWithDelta(25.00, (float) $m->amount, 0.01);
        $this->assertSame((int) $this->branch->id, (int) $m->branch_id);
    }

    /** Path 2 — POS direct CASH sans session OPEN → 422 + 0 order + 0 movement */
    public function test_pos_cash_without_open_session_returns_422_and_rolls_back(): void
    {
        $this->actingAs($this->operator, 'sanctum');
        // PAS de openSessionForOperator() — aucune session OPEN

        $payload = $this->basePayload();

        $orderCountBefore = Order::withoutGlobalScopes()->count();
        $movementCountBefore = CashMovement::count();

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(422);
        $this->assertSame('CASH_NO_OPEN_SESSION', $response->json('code'));

        $this->assertSame(
            $orderCountBefore,
            Order::withoutGlobalScopes()->count(),
            'POS CASH sans session OPEN ne doit pas créer d\'order (transaction rollback).'
        );
        $this->assertSame(
            $movementCountBefore,
            CashMovement::count(),
            'POS CASH sans session OPEN ne doit pas créer de movement.'
        );
    }

    /** Path 3 — Split (CASH 5 + CARD 20) + session OPEN → 1 movement = 5€ */
    public function test_split_payment_one_cash_one_card_with_session_writes_one_cash_movement(): void
    {
        $this->actingAs($this->operator, 'sanctum');
        $session = $this->openSessionForOperator(50.00);

        $payload = $this->basePayload([
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 5.00, 'tendered' => 5.00, 'change' => 0],
                ['mode' => PosPaymentMethod::CARD, 'amount' => 20.00, 'reference' => '4242'],
            ],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');
        $this->assertNotNull($orderId);

        // 2 order_payments persistées (par SplitPaymentService)
        $this->assertSame(2, OrderPayment::where('order_id', $orderId)->count());

        // 1 SEUL cash_movement (la tranche CARD ne génère pas de movement)
        $movements = CashMovement::where('order_id', $orderId)->get();
        $this->assertCount(1, $movements, 'Une seule tranche CASH → un seul cash_movement.');
        $m = $movements->first();
        $this->assertSame(CashMovement::TYPE_ORDER_PAYMENT, $m->type);
        $this->assertSame(CashMovement::DIRECTION_IN, $m->direction);
        $this->assertEqualsWithDelta(5.00, (float) $m->amount,  0.01,
            'Montant movement = tranche.amount (5€), pas order.total (25€).');
        $this->assertSame((int) $session->id, (int) $m->cash_drawer_session_id);
    }

    /** Path 4 — Split 2 tranches CASH sans session → 422 + 0 movement + 0 order */
    public function test_split_payment_two_cash_tranches_without_session_returns_422(): void
    {
        $this->actingAs($this->operator, 'sanctum');
        // PAS de session

        $payload = $this->basePayload([
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 10.00, 'tendered' => 10.00, 'change' => 0],
                ['mode' => PosPaymentMethod::CASH, 'amount' => 15.00, 'tendered' => 15.00, 'change' => 0],
            ],
        ]);

        $orderCountBefore = Order::withoutGlobalScopes()->count();

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(422);
        $this->assertSame('CASH_NO_OPEN_SESSION', $response->json('code'));

        $this->assertSame(
            $orderCountBefore,
            Order::withoutGlobalScopes()->count(),
            'Split CASH sans session OPEN ne doit pas créer d\'order.'
        );
        $this->assertSame(0, CashMovement::count());
        $this->assertSame(0, OrderPayment::count());
    }

    /** Path 5 — Régression kiosk counter-collect : session OPEN → movement écrit */
    public function test_regression_kiosk_counter_collect_with_session_writes_movement(): void
    {
        $cashService = app(CashDrawerService::class);
        $paymentService = app(PaymentService::class);

        $kioskUser = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => fake()->unique()->numerify('06########'),
        ]);
        $kioskUser->assignRole('POS Operator');

        $this->actingAs($kioskUser);
        $session = $cashService->openSession($this->branch->id, $kioskUser->id, 100.00);

        $order = Order::factory()->create([
            'branch_id'           => $this->branch->id,
            'user_id'             => $kioskUser->id,
            'total'               => 17.50,
            'subtotal'            => 17.50,
            'discount'            => 0,
            'total_tax'           => 0,
            'delivery_charge'     => 0,
            'status'              => OrderStatus::PENDING,
            'payment_status'      => PaymentStatus::UNPAID,
            'payment_method'      => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method'  => PosPaymentMethod::COUNTER_DEFERRED,
            'source_surface'      => 'kiosk',
        ]);

        $paymentService->confirmCounterPayment($order, PosPaymentMethod::CASH);

        $m = CashMovement::query()
            ->where('cash_drawer_session_id', $session->id)
            ->where('order_id', $order->id)
            ->first();

        $this->assertNotNull($m, 'Régression : kiosk counter-collect doit écrire 1 cash_movement.');
        $this->assertSame(CashMovement::TYPE_ORDER_PAYMENT, $m->type);
        $this->assertSame(CashMovement::DIRECTION_IN, $m->direction);
        $this->assertEqualsWithDelta(17.50, (float) $m->amount, 0.01);
    }

    /** Path 6 — Régression kiosk counter-collect sans session → order PAID + best-effort log */
    public function test_regression_kiosk_counter_collect_without_session_paid_no_movement(): void
    {
        $paymentService = app(PaymentService::class);

        $kioskUser = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => fake()->unique()->numerify('06########'),
        ]);
        $kioskUser->assignRole('POS Operator');
        $this->actingAs($kioskUser);

        $order = Order::factory()->create([
            'branch_id'           => $this->branch->id,
            'user_id'             => $kioskUser->id,
            'total'               => 8.00,
            'subtotal'            => 8.00,
            'discount'            => 0,
            'total_tax'           => 0,
            'delivery_charge'     => 0,
            'status'              => OrderStatus::PENDING,
            'payment_status'      => PaymentStatus::UNPAID,
            'payment_method'      => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method'  => PosPaymentMethod::COUNTER_DEFERRED,
            'source_surface'      => 'kiosk',
        ]);

        // Doit PAS throw — backward-compat (best-effort, strict=false)
        $paymentService->confirmCounterPayment($order, PosPaymentMethod::CASH);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status,
            'Kiosk counter-collect sans session : order doit rester PAID (legacy F-003 best-effort).');
        $this->assertSame(0, CashMovement::where('order_id', $order->id)->count());
    }
}
