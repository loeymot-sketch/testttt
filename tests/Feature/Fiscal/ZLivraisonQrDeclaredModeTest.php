<?php

namespace Tests\Feature\Fiscal;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\AuditLog;
use App\Models\DeliveryBoyCashMovement;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\ZReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Delivery\Concerns\HandoverFixtures;
use Tests\TestCase;

/**
 * [LIVRAISON QR 2026-11-01] Le Z suit le mode DÉCLARÉ à la remise par QR — pas le choix initial du client.
 * Lecture de ZReportService::applyOrderToTotals (non modifié) : rangement par `pos_payment_method ?: payment_method`.
 * Parcours complet par l'API (connexion dédiée → lecture → « Remis »), vraie séquence fiscale, vrai journal d'audit.
 *
 * @group fiscal
 */
class ZLivraisonQrDeclaredModeTest extends TestCase
{
    use RefreshDatabase;
    use HandoverFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHandover();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownHandover();
        parent::tearDown();
    }

    /** @return array<string,float> */
    private function ventilationZ(): array
    {
        $agg = app(ZReportService::class)->aggregate(
            (int) $this->branch->id,
            \Illuminate\Support\Carbon::parse('2026-11-01 00:00:00'),
            \Illuminate\Support\Carbon::parse('2026-11-01 23:59:59')
        );

        return array_map('floatval', (array) ($agg['total_by_method'] ?? []));
    }

    private function remettre(array $order, string $declared, ?int $cents = null): Order
    {
        $stop = $this->departed($this->delivery($order + ['subtotal' => 23.60, 'discount' => 0, 'total_tax' => 0]));
        $qr = $this->qrToken($stop);
        $bearer = $this->handoverLogin('livreur.a@test.fr');
        $this->withHeaders($this->bearer($bearer))->postJson('/api/frontend/delivery-handover/confirm', [
            'token' => $qr, 'declared_mode' => $declared, 'collected_cents' => $cents ?? 2750,
        ])->assertOk();

        return Order::query()->withoutGlobalScopes()->findOrFail($stop->order_id);
    }

    public function test_card_declared_on_a_cash_order_lands_in_the_card_column_of_the_z_not_in_cash(): void
    {
        $o = $this->remettre([], 'card');

        $this->assertSame(PosPaymentMethod::CARD, (int) $o->pos_payment_method);
        $z = $this->ventilationZ();
        $this->assertEqualsWithDelta(27.5, $z[(string) PosPaymentMethod::CARD] ?? 0.0, 0.001);
        $this->assertArrayNotHasKey((string) PosPaymentMethod::CASH, $z, 'pas un euro en espèces');
        $this->assertArrayNotHasKey((string) PosPaymentMethod::COUNTER_DEFERRED, $z);
        $this->assertArrayNotHasKey((string) PaymentGateway::CASH_ON_DELIVERY, $z);
    }

    public function test_cash_declared_on_a_card_choice_lands_in_the_cash_column(): void
    {
        $this->remettre(['door_payment_mode' => 'card'], 'cash');

        $z = $this->ventilationZ();
        $this->assertEqualsWithDelta(27.5, $z[(string) PosPaymentMethod::CASH] ?? 0.0, 0.001);
        $this->assertArrayNotHasKey((string) PosPaymentMethod::CARD, $z);
    }

    public function test_two_deliveries_with_opposite_declarations_split_the_z_exactly(): void
    {
        $this->remettre([], 'card');
        $this->remettre(['door_payment_mode' => 'card'], 'cash');
        $this->remettre([], 'cash');

        $z = $this->ventilationZ();
        $this->assertEqualsWithDelta(27.5, $z[(string) PosPaymentMethod::CARD] ?? 0.0, 0.001);
        $this->assertEqualsWithDelta(55.0, $z[(string) PosPaymentMethod::CASH] ?? 0.0, 0.001);
    }

    public function test_the_fiscal_number_is_allocated_exactly_once_and_stays_on_a_replay(): void
    {
        $stop = $this->departed($this->delivery());
        $qr = $this->qrToken($stop);
        $bearer = $this->handoverLogin('livreur.a@test.fr');
        $body = ['token' => $qr, 'declared_mode' => 'cash', 'collected_cents' => 2750];
        $this->assertNull(Order::query()->withoutGlobalScopes()->find($stop->order_id)->fiscal_sequence_no);

        $this->withHeaders($this->bearer($bearer))->postJson('/api/frontend/delivery-handover/confirm', $body)->assertOk();
        $n = Order::query()->withoutGlobalScopes()->find($stop->order_id)->fiscal_sequence_no;
        $this->assertNotNull($n);
        $this->withHeaders($this->bearer($bearer))->postJson('/api/frontend/delivery-handover/confirm', $body)->assertOk();

        $this->assertSame((int) $n, (int) Order::query()->withoutGlobalScopes()->find($stop->order_id)->fiscal_sequence_no);
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->where('branch_id', $this->branch->id)->whereNotNull('fiscal_sequence_no')->count());
    }

    public function test_no_cash_escrow_and_no_cash_drawer_movement_for_a_card_collection(): void
    {
        $o = $this->remettre([], 'card');

        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count());
        $this->assertSame(0, DeliveryBoyCashMovement::query()->withoutGlobalScopes()->where('order_id', $o->id)->count());
        $this->assertSame(1, \App\Models\OrderPayment::query()->withoutGlobalScopes()->where('order_id', $o->id)->count());
    }

    public function test_online_paid_order_adds_nothing_to_the_z_through_the_qr_path(): void
    {
        $stop = $this->departed($this->delivery(['payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD, 'pos_payment_method' => PosPaymentMethod::CARD, 'fiscal_sequence_no' => 900]));
        $before = $this->ventilationZ();
        $qr = $this->qrToken($stop);
        $bearer = $this->handoverLogin('livreur.a@test.fr');

        $this->withHeaders($this->bearer($bearer))->postJson('/api/frontend/delivery-handover/confirm', ['token' => $qr])->assertOk();

        $this->assertSame($before, $this->ventilationZ());
        $this->assertSame(900, (int) Order::query()->withoutGlobalScopes()->find($stop->order_id)->fiscal_sequence_no);
    }

    public function test_audit_chain_verifies_after_the_whole_flow(): void
    {
        $this->remettre([], 'card');
        $this->remettre([], 'cash');

        $this->assertNull(app(AuditLogService::class)->verifyChain((int) $this->branch->id));
    }
}
