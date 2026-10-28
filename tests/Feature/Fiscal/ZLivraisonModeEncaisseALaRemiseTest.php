<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\Fiscal\ZReportService;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * [VG-2026-10-21 Z-livraison] Une livraison prise à la CAISSE (téléphone / vente différée) porte
 * pos_payment_method = 6 (paiement différé). Encaissée à la porte par le livreur, elle était scellée (numéro fiscal)
 * sans que ce marqueur change : le Z (ZReportService::applyOrderToTotals, `pos_payment_method ?: payment_method`)
 * la rangeait en « différé » au lieu d'« Espèces » / « Titre-restaurant ». À la REMISE, dans la transaction du
 * scellement, pos_payment_method prend le vrai mode encaissé. Les Z déjà signés ne sont pas réécrits.
 */
class ZLivraisonModeEncaisseALaRemiseTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $livreur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
        Carbon::setTestNow(Carbon::parse('2026-10-21 19:30:00'));

        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}

            public function write(array $data): \App\Models\AuditLog
            {
                return new \App\Models\AuditLog();
            }
        });
        $this->app->instance(FiscalSequenceService::class, new class extends FiscalSequenceService {
            private int $c = 8800;

            public function __construct() {}

            public function next(int $branchId): int
            {
                return ++$this->c;
            }
        });

        $this->branch = Branch::factory()->create();
        $this->livreur = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        $this->livreur->assignRole('Delivery Boy');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function livraisonCaisseEnRoute(int $paymentMethod, ?int $posPaymentMethod = PosPaymentMethod::COUNTER_DEFERRED, string $surface = 'phone'): Order
    {
        return Order::factory()->create([
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::DELIVERY,
            'source_surface' => $surface,
            'status' => OrderStatus::OUT_FOR_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'payment_method' => $paymentMethod,
            'pos_payment_method' => $posPaymentMethod,
            'delivery_boy_id' => $this->livreur->id,
            'fiscal_sequence_no' => null,
            'subtotal' => 18.00,
            'total' => 21.90,
            'discount' => 0,
            'total_tax' => 0,
            'created_at' => Carbon::parse('2026-10-21 19:00:00'),
        ]);
    }

    private function remettre(Order $order): Order
    {
        $this->actingAs($this->livreur, 'sanctum');

        return app(OrderService::class)->deliveryBoyOrderChangeStatus(
            $order->fresh(),
            Request::create('', 'POST', ['status' => OrderStatus::DELIVERED])
        )->fresh();
    }

    /** @return array<string, float> */
    private function ventilationZ(): array
    {
        $agg = app(ZReportService::class)->aggregate($this->branch->id, \Illuminate\Support\Carbon::parse('2026-10-21 00:00:00'), \Illuminate\Support\Carbon::parse('2026-10-21 23:59:59'));

        return array_map('floatval', (array) ($agg['total_by_method'] ?? []));
    }

    public function test_cash_delivery_taken_at_the_caisse_is_counted_as_cash_in_the_z_once_handed_over(): void
    {
        $order = $this->remettre($this->livraisonCaisseEnRoute(PaymentGateway::CASH_ON_DELIVERY));

        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status);
        $this->assertNotNull($order->fiscal_sequence_no);
        $this->assertSame(PosPaymentMethod::CASH, (int) $order->pos_payment_method, 'Encaissée en espèces à la porte.');

        $z = $this->ventilationZ();
        $this->assertEqualsWithDelta(21.90, $z[(string) PosPaymentMethod::CASH] ?? 0.0, 0.001, 'Rangée en Espèces au Z.');
        $this->assertArrayNotHasKey((string) PosPaymentMethod::COUNTER_DEFERRED, $z, 'Plus rien en « différé ».');
    }

    public function test_meal_voucher_delivery_taken_at_the_caisse_is_counted_as_meal_voucher_in_the_z(): void
    {
        $order = $this->remettre($this->livraisonCaisseEnRoute(PaymentGateway::TICKET_RESTAURANT));

        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status);
        $this->assertSame(PosPaymentMethod::TICKET_RESTAURANT, (int) $order->pos_payment_method);

        $z = $this->ventilationZ();
        $this->assertEqualsWithDelta(21.90, $z[(string) PosPaymentMethod::TICKET_RESTAURANT] ?? 0.0, 0.001);
        $this->assertArrayNotHasKey((string) PosPaymentMethod::COUNTER_DEFERRED, $z);
    }

    public function test_web_cash_delivery_without_caisse_marker_gets_the_cash_mode_too(): void
    {
        $order = $this->remettre($this->livraisonCaisseEnRoute(PaymentGateway::CASH_ON_DELIVERY, null, 'delivery'));

        $this->assertSame(PosPaymentMethod::CASH, (int) $order->pos_payment_method);
        $this->assertEqualsWithDelta(21.90, $this->ventilationZ()[(string) PosPaymentMethod::CASH] ?? 0.0, 0.001);
    }
}
