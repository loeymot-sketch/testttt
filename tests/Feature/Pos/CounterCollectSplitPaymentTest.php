<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentTerminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [GOAL-8AXES V6 T-3.3 2026-08-05] Multi-paiement à l'ENCAISSEMENT d'une
 * commande déjà passée (borne / web / téléphone — PENDING_COUNTER).
 *
 * Cas owner littéral : « commande à 20 € → je tape 12 € en carte, il me reste
 * 8 €, je choisis espèces pour le reste ». Le split existait pour une commande
 * CRÉÉE à la caisse (SplitPaymentEndToEndTest) mais PAS à l'encaissement :
 * PosCounterCollectModal:18 « multi-tranche split deferred », endpoint
 * counter-collect/confirm = mode unique (routes/api.php:961-971) — passer des
 * tranches y aurait PERDU les tranches 2..N en silence.
 *
 * Contrat : POST counter-collect/{order}/confirm accepte `payment_breakdown`
 * (mêmes règles que SplitPaymentService::validateBreakdown — somme au centime,
 * modes valides). Preuve monétaire : 20,01 € = 12,00 CB + 8,01 espèces.
 */
class CounterCollectSplitPaymentTest extends TestCase
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
        Config::set('pos.simulation_hardware', true);
        Config::set('split_payment.enabled', true);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('a', 40));

        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
        $this->terminal = PaymentTerminal::create([
            'branch_id' => $this->branch->id, 'name' => 'TPE Counter',
            'gateway_type' => PaymentTerminal::GATEWAY_MANUAL,
            'fee_percent' => 0, 'fee_fixed' => 0,
            'status' => PaymentTerminal::STATUS_ACTIVE,
        ]);
    }

    private function makePendingOrder(float $total = 20.01): Order
    {
        // Marqueur TRIPLE canonique d'une commande différée au comptoir
        // (PaymentService::assertCounterDeferredOrder) : PENDING_COUNTER +
        // COUNTER_DEFERRED + CASH_ON_DELIVERY — même triple que la borne Plan B
        // et la commande téléphone (PhoneOrderDeferredTest:147-148).
        return Order::factory()->create([
            'branch_id' => $this->branch->id,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'payment_method' => \App\Enums\PaymentGateway::CASH_ON_DELIVERY,
            'order_type' => OrderType::KIOSK,
            'source_surface' => 'kiosk',
            'status' => OrderStatus::ACCEPT,
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    private function confirm(Order $order, array $payload)
    {
        return $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'test-ccsplit-'.$order->id.'-'.md5(json_encode($payload)))
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", $payload);
    }

    public function test_split_card_plus_cash_collects_at_the_exact_cent(): void
    {
        $order = $this->makePendingOrder(20.01);

        $response = $this->confirm($order, [
            'mode' => PosPaymentMethod::CARD,
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CARD, 'amount' => 12.00, 'terminal_id' => $this->terminal->id],
                ['mode' => PosPaymentMethod::CASH, 'amount' => 8.01, 'tendered' => 10.00],
            ],
        ]);

        $response->assertOk();

        $order->refresh();
        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status);

        $payments = OrderPayment::withoutGlobalScopes()->where('order_id', $order->id)->get();
        $this->assertCount(2, $payments, 'Deux tranches persistées (CB + espèces).');
        $this->assertEqualsWithDelta(20.01, (float) $payments->sum('amount'), 0.0001,
            'La somme des tranches = total exact au centime.');
        $this->assertEqualsWithDelta(12.00, (float) $payments->firstWhere('mode', PosPaymentMethod::CARD)->amount, 0.0001);
        $this->assertEqualsWithDelta(8.01, (float) $payments->firstWhere('mode', PosPaymentMethod::CASH)->amount, 0.0001);
    }

    public function test_split_that_does_not_sum_to_total_is_rejected_and_order_stays_pending(): void
    {
        $order = $this->makePendingOrder(20.00);

        $response = $this->confirm($order, [
            'mode' => PosPaymentMethod::CARD,
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CARD, 'amount' => 12.00, 'terminal_id' => $this->terminal->id],
                ['mode' => PosPaymentMethod::CASH, 'amount' => 7.00, 'tendered' => 7.00],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $order->refresh()->payment_status,
            'Un split incomplet ne doit RIEN encaisser (pas de demi-état).');
        $this->assertSame(0, OrderPayment::withoutGlobalScopes()->where('order_id', $order->id)->count());
    }

    public function test_single_mode_path_unchanged(): void
    {
        $order = $this->makePendingOrder(9.50);

        $this->confirm($order, ['mode' => PosPaymentMethod::CASH, 'received' => 10.00])->assertOk();
        $this->assertSame(PaymentStatus::PAID, (int) $order->refresh()->payment_status);
    }

    public function test_double_collect_still_conflicts_409_with_breakdown(): void
    {
        $order = $this->makePendingOrder(15.00);
        $breakdown = [
            'mode' => PosPaymentMethod::CARD,
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CARD, 'amount' => 10.00, 'terminal_id' => $this->terminal->id],
                ['mode' => PosPaymentMethod::CASH, 'amount' => 5.00, 'tendered' => 5.00],
            ],
        ];

        $this->confirm($order, $breakdown)->assertOk();

        // Second encaissement par un AUTRE caissier → 409 payment_already_collected.
        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $other->assignRole('POS Operator');
        $res = $this->actingAs($other, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'test-ccsplit-other-'.$order->id)
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", $breakdown);

        $res->assertStatus(409);
        $this->assertSame(2, OrderPayment::withoutGlobalScopes()->where('order_id', $order->id)->count(),
            'Aucune tranche dupliquée par le second encaissement.');
    }
}
