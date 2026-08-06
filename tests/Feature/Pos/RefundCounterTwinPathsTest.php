<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentTerminal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [AUDIT-B round-1 2026-08-06] Sentinelles des 4 défauts « jumeaux oubliés »
 * du chemin argent/points — chacun avait été corrigé sur UN chemin, pas ses
 * jumeaux (rapport reports/goal-revision-absolue-2026-08-06/round-1/B-logique/).
 *
 *  D1  Refund d'un SPLIT : compensation PAR ORIGINE de tranche — avoir wallet
 *      = portion non-cash, tiroir OUT = portion cash, somme == total exact.
 *  D2  cancelCounterPayment claw-back les points GAGNÉS (3e jumeau, miroir de
 *      changeStatus + janitor).
 *  D3  web ≡ delivery dans la file caisse « web à traiter » (une livraison
 *      site PENDING doit être acceptable, sinon le janitor la perd).
 *  D4  Mixte à l'encaissement : le hook cash post-commit ne s'arme JAMAIS en
 *      multi-tender (les tranches portent déjà le cash-trail).
 */
class RefundCounterTwinPathsTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Branch $branch;

    private PaymentTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('split_payment.enabled', true);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('a', 40));

        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
        $this->terminal = PaymentTerminal::create([
            'branch_id' => $this->branch->id, 'name' => 'TPE Twins',
            'gateway_type' => PaymentTerminal::GATEWAY_MANUAL,
            'fee_percent' => 0, 'fee_fixed' => 0,
            'status' => PaymentTerminal::STATUS_ACTIVE,
        ]);
    }

    private function openSession(): void
    {
        CashDrawerSession::create([
            'branch_id' => $this->branch->id,
            'opened_by_user_id' => $this->cashier->id,
            'opened_at' => now(),
            'opening_amount' => 100.0,
            'status' => CashDrawerSession::STATUS_OPEN,
        ]);
    }

    /** @return array{0: Order, 1: User} commande PAYÉE en split card+cash avec tranches réelles */
    private function makePaidSplitOrder(float $total, float $card, float $cash, int $dominantMode): array
    {
        $customer = User::factory()->create(['branch_id' => $this->branch->id, 'balance' => 0]);
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $customer->id,
            'payment_status' => PaymentStatus::PAID,
            'pos_payment_method' => $dominantMode,
            'order_type' => OrderType::POS,
            'source_surface' => 'pos',
            'status' => OrderStatus::PREPARING,
            'subtotal' => $total,
            'total' => $total,
        ]);
        Transaction::create([
            'order_id' => $order->id, 'transaction_no' => 'TXN-TW-'.$order->id,
            'amount' => $total, 'payment_method' => 'counter_card', 'sign' => '+', 'type' => 'payment',
        ]);
        foreach ([[PosPaymentMethod::CARD, $card], [PosPaymentMethod::CASH, $cash]] as [$mode, $amount]) {
            OrderPayment::create([
                'order_id' => $order->id, 'branch_id' => $this->branch->id,
                'mode' => $mode, 'amount' => $amount,
                'tendered' => $mode === PosPaymentMethod::CASH ? $amount : null,
                'change_amount' => 0, 'paid_at' => now(),
                'terminal_id' => $mode === PosPaymentMethod::CARD ? $this->terminal->id : null,
            ]);
        }

        return [$order, $customer];
    }

    private function drawerOut(Order $order): float
    {
        return (float) CashMovement::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('type', CashMovement::TYPE_CASHBACK)
            ->where('direction', CashMovement::DIRECTION_OUT)
            ->sum('amount');
    }

    public function test_d1_split_refund_dominant_card_compensates_exactly_the_total(): void
    {
        Config::set('pos.simulation_hardware', false);
        $this->openSession();
        [$order, $customer] = $this->makePaidSplitOrder(20.01, 12.00, 8.01, PosPaymentMethod::CARD);

        $this->actingAs($this->cashier, 'sanctum');
        app(PaymentService::class)->cashBack($order, 'credit', 'TXN-RF-1');

        $wallet = (float) $customer->fresh()->balance;
        $out = $this->drawerOut($order);
        $this->assertEqualsWithDelta(12.00, $wallet, 0.001, 'avoir = portion NON-cash uniquement');
        $this->assertEqualsWithDelta(8.01, $out, 0.001, 'tiroir OUT = portion cash uniquement');
        $this->assertEqualsWithDelta(20.01, $wallet + $out, 0.001, 'compensation totale == total payé, au centime');
    }

    public function test_d1_split_refund_dominant_cash_still_compensates_the_card_tranche(): void
    {
        Config::set('pos.simulation_hardware', false);
        $this->openSession();
        [$order, $customer] = $this->makePaidSplitOrder(25.01, 12.00, 13.01, PosPaymentMethod::CASH);

        $this->actingAs($this->cashier, 'sanctum');
        // Miroir OrderService::changeStatus:2444 — dominant CASH → gateway 'cash'.
        app(PaymentService::class)->cashBack($order, 'cash', 'TXN-RF-2');

        $wallet = (float) $customer->fresh()->balance;
        $out = $this->drawerOut($order);
        $this->assertEqualsWithDelta(12.00, $wallet, 0.001, 'la tranche CARTE est compensée en avoir');
        $this->assertEqualsWithDelta(13.01, $out, 0.001, 'le tiroir ne sort QUE la portion cash');
        $this->assertEqualsWithDelta(25.01, $wallet + $out, 0.001);
    }

    public function test_d1_mono_cash_refund_unchanged_no_wallet(): void
    {
        Config::set('pos.simulation_hardware', false);
        $this->openSession();
        $customer = User::factory()->create(['branch_id' => $this->branch->id, 'balance' => 0]);
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id, 'user_id' => $customer->id,
            'payment_status' => PaymentStatus::PAID, 'pos_payment_method' => PosPaymentMethod::CASH,
            'order_type' => OrderType::POS, 'source_surface' => 'pos',
            'status' => OrderStatus::PREPARING, 'subtotal' => 10.0, 'total' => 10.0,
        ]);
        Transaction::create([
            'order_id' => $order->id, 'transaction_no' => 'TXN-TW-M'.$order->id,
            'amount' => 10.0, 'payment_method' => 'cash', 'sign' => '+', 'type' => 'payment',
        ]);
        // Encaissement cash réel enregistré (IN) — condition du repli tiroir (symétrie).
        CashMovement::create([
            'branch_id' => $this->branch->id, 'order_id' => $order->id,
            'cash_drawer_session_id' => CashDrawerSession::query()->value('id'),
            'type' => CashMovement::TYPE_ORDER_PAYMENT, 'direction' => CashMovement::DIRECTION_IN,
            'amount' => 10.0, 'user_id' => $this->cashier->id,
        ]);

        $this->actingAs($this->cashier, 'sanctum');
        app(PaymentService::class)->cashBack($order, 'cash', 'TXN-RF-3');

        $this->assertEqualsWithDelta(0.0, (float) $customer->fresh()->balance, 0.001, 'mono cash → AUCUN avoir (MP-01 inchangé)');
        $this->assertEqualsWithDelta(10.0, $this->drawerOut($order), 0.001, 'mono cash → tiroir OUT = total');
    }

    public function test_d2_counter_cancel_of_prepared_order_claws_back_awarded_points(): void
    {
        $loyal = User::factory()->create([
            'branch_id' => $this->branch->id,
            'loyalty_code' => 'LC-TWIN-1',
        ]);
        User::where('id', $loyal->id)->update(['loyalty_points' => 50]);

        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'payment_method' => \App\Enums\PaymentGateway::CASH_ON_DELIVERY,
            'order_type' => OrderType::KIOSK,
            'source_surface' => 'kiosk',
            'status' => OrderStatus::PREPARED,
            'subtotal' => 20.0, 'total' => 20.0,
            'loyalty_customer_code' => 'LC-TWIN-1',
            'loyalty_points_awarded' => 50,
        ]);

        $this->actingAs($this->cashier, 'sanctum');
        app(PaymentService::class)->cancelCounterPayment($order, 'Client parti sans payer');

        $this->assertSame(OrderStatus::CANCELED, (int) $order->refresh()->status);
        $this->assertSame(0, (int) User::where('id', $loyal->id)->value('loyalty_points'),
            'les points gagnés sur une vente jamais payée sont repris (3e jumeau du clawback)');
    }

    public function test_d3_delivery_pending_is_visible_and_acceptable_in_web_queue(): void
    {
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'payment_status' => PaymentStatus::UNPAID,
            'payment_method' => \App\Enums\PaymentGateway::CASH_ON_DELIVERY,
            'order_type' => OrderType::DELIVERY,
            'source_surface' => null, // FrontendOrder::creating force 'delivery'
            'status' => OrderStatus::PENDING,
            'subtotal' => 30.0, 'total' => 34.0,
        ]);
        $this->assertSame('delivery', (string) $order->refresh()->source_surface);

        $ids = collect($this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos/web-orders/pending')
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($order->id, $ids,
            'une LIVRAISON site PENDING doit être visible/acceptable en caisse (web ≡ delivery), sinon le janitor la perd');
    }

    public function test_d4_mixte_counter_collect_writes_exactly_one_cash_in_per_cash_tranche(): void
    {
        Config::set('pos.simulation_hardware', false);
        $this->openSession();
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'payment_method' => \App\Enums\PaymentGateway::CASH_ON_DELIVERY,
            'order_type' => OrderType::KIOSK,
            'source_surface' => 'kiosk',
            'status' => OrderStatus::ACCEPT,
            'subtotal' => 25.01, 'total' => 25.01,
        ]);

        $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'twin-d4-'.$order->id)
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CASH, // dominant : cash 13.01 >= card 12.00
                'payment_breakdown' => [
                    ['mode' => PosPaymentMethod::CARD, 'amount' => 12.00, 'terminal_id' => $this->terminal->id],
                    ['mode' => PosPaymentMethod::CASH, 'amount' => 13.01, 'tendered' => 13.01],
                ],
            ])->assertOk();

        $ins = CashMovement::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('type', CashMovement::TYPE_ORDER_PAYMENT)
            ->where('direction', CashMovement::DIRECTION_IN)
            ->pluck('amount')->map(fn ($a) => (float) $a)->all();

        $this->assertCount(1, $ins, 'UN seul IN (la tranche cash) — jamais le hook post-commit en multi-tender');
        $this->assertEqualsWithDelta(13.01, $ins[0], 0.001, 'le tiroir reçoit exactement les espèces réelles');
    }
}
