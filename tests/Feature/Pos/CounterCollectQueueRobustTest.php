<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ENCAISSEMENT-ROBUSTE 2026-07-01] La file d'encaissement caisse (counter-collect/pending)
 * doit :
 *  - EXCLURE les commandes annulées (sinon « fantôme » qui 422 à l'encaissement) ;
 *  - RATTRAPER les commandes borne PENDING_COUNTER dont source_surface est NULL (donnée héritée)
 *    — sinon elles sont invisibles en caisse donc INENCAISSABLES.
 */
class CounterCollectQueueRobustTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
    }

    private function makeOrder(array $attrs): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'order_type' => OrderType::KIOSK,
            'source_surface' => 'kiosk',
            'status' => OrderStatus::ACCEPT,
        ], $attrs));
    }

    private function pendingIds(): array
    {
        $res = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos/counter-collect/pending');
        $res->assertOk();

        return collect($res->json('data'))->pluck('id')->all();
    }

    /** @test */
    public function commande_annulee_est_exclue_de_la_file(): void
    {
        $normal = $this->makeOrder([]);
        $cancelled = $this->makeOrder(['status' => OrderStatus::CANCELED]);

        $ids = $this->pendingIds();

        $this->assertContains($normal->id, $ids, 'commande normale doit être encaissable');
        $this->assertNotContains($cancelled->id, $ids, 'commande annulée ne doit PAS rester dans la file');
    }

    /** @test */
    public function commande_borne_source_surface_null_reste_encaissable(): void
    {
        $nullSurface = $this->makeOrder(['source_surface' => null, 'order_type' => OrderType::TAKEAWAY]);

        $ids = $this->pendingIds();

        $this->assertContains($nullSurface->id, $ids, 'une commande PENDING_COUNTER à source_surface NULL doit rester visible en caisse');
    }

    /** @test */
    public function commande_pos_counter_deferred_reste_visible(): void
    {
        $posDeferred = $this->makeOrder([
            'source_surface' => 'pos',
            'order_type' => OrderType::POS,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
        ]);

        $ids = $this->pendingIds();

        $this->assertContains($posDeferred->id, $ids);
    }
}
