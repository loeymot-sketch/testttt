<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Role as EnumRole;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\DeliveryBoyCashMovement;
use App\Models\Order;
use App\Models\User;
use App\Services\Delivery\DeliveryBoyCashSessionService;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\OrderService;
use App\Services\Pos\CounterCollectQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M4-001] Une livraison prise À LA CAISSE et payée à la porte (commande TÉLÉPHONE,
 * `source_surface = 'phone'`, ou vente caisse différée `'pos'`) doit être SCELLÉE à la remise, comme
 * une livraison du site : PAYÉE, numéro fiscal, trace d'encaissement (audit + caisse du livreur), et
 * retirée de la file « À encaisser » (sinon la caisse redemande l'argent déjà encaissé à la porte).
 *
 * Avant : `deliveryBoyOrderChangeStatus` ne reconnaissait que 'web' / 'delivery' → #8099 et #8115
 * (base locale) livrées, `payment_status = 15`, sans numéro fiscal, hors Z, toujours « À encaisser ».
 */
class PhoneDeliveryDoorstepSealTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    /** @var array<int, array> */
    private array $audits = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Role::firstOrCreate(['id' => EnumRole::DELIVERY_BOY], ['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);

        $audits = &$this->audits;
        $this->app->instance(AuditLogService::class, new class($audits) extends AuditLogService {
            private array $sink;

            public function __construct(array &$sink)
            {
                $this->sink = &$sink;
            }

            public function write(array $data): \App\Models\AuditLog
            {
                $this->sink[] = $data;

                return new \App\Models\AuditLog();
            }
        });
        $this->app->instance(FiscalSequenceService::class, new class extends FiscalSequenceService {
            private int $c = 9100;

            public function __construct() {}

            public function next(int $branchId): int
            {
                return ++$this->c;
            }
        });

        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);
    }

    public static function surfacesCaisse(): array
    {
        return [
            'commande téléphone' => ['phone'],
            'vente caisse différée' => ['pos'],
        ];
    }

    /** @dataProvider surfacesCaisse */
    public function test_caisse_delivery_paid_at_the_door_is_sealed_and_leaves_the_counter_queue(string $surface): void
    {
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::DELIVERY,
            'source' => Source::POS,
            'source_surface' => $surface,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::OUT_FOR_DELIVERY,
            'fiscal_sequence_no' => null,
            'total' => 4.90,
            'subtotal' => 1.00,
        ]);

        $livreur = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        $livreur->assignRole(EnumRole::DELIVERY_BOY);
        $order->delivery_boy_id = $livreur->id;
        $order->save();
        $session = app(DeliveryBoyCashSessionService::class)->openSession($this->branch->id, $livreur->id, 20.00, $livreur->id);

        $this->actingAs($livreur, 'sanctum');
        app(OrderService::class)->deliveryBoyOrderChangeStatus($order->fresh(), new Request(['status' => OrderStatus::DELIVERED]));

        $order->refresh();
        $this->assertSame(OrderStatus::DELIVERED, (int) $order->status);
        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status, 'Encaissée à la porte : la commande doit être PAYÉE.');
        $this->assertNotNull($order->fiscal_sequence_no, 'Encaissée à la porte : la vente doit entrer dans la chaîne fiscale (NF525).');

        $escrow = array_values(array_filter($this->audits, fn ($a) => ($a['action'] ?? null) === 'delivery.cash_collected_escrow'));
        $this->assertCount(1, $escrow, "L'encaissement à la porte doit laisser sa trace d'audit (NF525).");
        $this->assertSame((int) $order->id, (int) $escrow[0]['resource_id']);

        $mouvements = DeliveryBoyCashMovement::query()->where('delivery_boy_cash_session_id', $session->id)->where('order_id', $order->id)->get();
        $this->assertCount(1, $mouvements, "L'argent encaissé à la porte doit entrer dans la caisse du livreur.");
        $this->assertEqualsWithDelta(4.90, (float) $mouvements->first()->amount, 0.001);

        $this->assertFalse(
            CounterCollectQueue::query($this->branch->id)->whereKey($order->id)->exists(),
            'Encaissée à la porte, la commande ne doit plus être « À encaisser » (double encaissement).'
        );
    }
}
