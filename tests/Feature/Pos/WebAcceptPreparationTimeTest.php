<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [CAISSE-WEB-INTEL 2026-08-06] À l'ACCEPT d'une commande web, le caissier
 * peut fixer le temps de préparation RÉEL (select 15/25/40 du tracker).
 * `preparation_time` est une colonne existante, stampée au défaut settings à
 * la création et DÉJÀ shippée au suivi client (OrderDetailsResource:89) —
 * on prouve ici : persistance à l'accept, défaut conservé sans le champ,
 * bornes de validation, et aucune écriture hors transition ACCEPT.
 * Harnais miroir de WebOrderInlineAcceptTest.
 */
class WebAcceptPreparationTimeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Permission::firstOrCreate(['name' => 'online-orders', 'guard_name' => 'sanctum']);

        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}
            public function write(array $data): \App\Models\AuditLog { return new \App\Models\AuditLog(); }
        });
        $seq = 9200;
        $this->app->instance(FiscalSequenceService::class, new class($seq) extends FiscalSequenceService {
            private int $c;
            public function __construct(int $s) { $this->c = $s; }
            public function next(int $branchId): int { return ++$this->c; }
        });

        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);
    }

    private function operator(): User
    {
        $u = User::factory()->create(['branch_id' => $this->branch->id]);
        $u->assignRole('POS Operator');
        $u->givePermissionTo('online-orders');
        return $u->fresh();
    }

    private function webOrder(array $over = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'          => $this->branch->id,
            'order_type'         => OrderType::TAKEAWAY,
            'source'             => Source::WEB,
            'source_surface'     => 'web',
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status'     => PaymentStatus::UNPAID,
            'pos_payment_method' => null,
            'status'             => OrderStatus::PENDING,
            'preparation_time'   => 15,
            'total'              => 18.50,
            'subtotal'           => 18.50,
        ], $over));
    }

    /** @test */
    public function accept_with_preparation_time_persists_the_cashier_choice(): void
    {
        $this->actingAs($this->operator());
        $order = $this->webOrder();

        $this->postJson("/api/admin/online-order/change-status/{$order->id}", [
            'status'           => OrderStatus::ACCEPT,
            'preparation_time' => 40,
        ])->assertSuccessful();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::ACCEPT, (int) $fresh->status);
        $this->assertSame(40, (int) $fresh->preparation_time, 'Le choix caissier doit remplacer le défaut settings.');
    }

    /** @test */
    public function accept_without_preparation_time_keeps_the_creation_default(): void
    {
        $this->actingAs($this->operator());
        $order = $this->webOrder(['preparation_time' => 15]);

        $this->postJson("/api/admin/online-order/change-status/{$order->id}", [
            'status' => OrderStatus::ACCEPT,
        ])->assertSuccessful();

        $this->assertSame(15, (int) $order->fresh()->preparation_time);
    }

    /** @test */
    public function out_of_bounds_preparation_time_is_rejected(): void
    {
        $this->actingAs($this->operator());
        $order = $this->webOrder();

        $this->postJson("/api/admin/online-order/change-status/{$order->id}", [
            'status'           => OrderStatus::ACCEPT,
            'preparation_time' => 999,
        ])->assertStatus(422);

        $this->assertSame(OrderStatus::PENDING, (int) $order->fresh()->status, 'Une validation refusée ne doit rien transiter.');
    }

    /** @test */
    public function preparation_time_is_ignored_outside_the_accept_transition(): void
    {
        $this->actingAs($this->operator());
        $order = $this->webOrder(['status' => OrderStatus::ACCEPT, 'payment_status' => PaymentStatus::PENDING_COUNTER]);

        $this->postJson("/api/admin/online-order/change-status/{$order->id}", [
            'status'           => OrderStatus::PREPARING,
            'preparation_time' => 40,
        ])->assertSuccessful();

        $this->assertSame(15, (int) $order->fresh()->preparation_time, 'Hors ACCEPT, le champ est ignoré (pas de réécriture tardive du contrat client).');
    }
}
