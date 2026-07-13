<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [WEB-CAISSE-SYNC 2026-07-13] La file « Commandes web à traiter » du POS
 * (admin/pos/web-orders/pending) doit rendre visibles sur l'écran caisse les
 * commandes du SITE (source_surface='web') restées PENDING — le paiement en ligne
 * étant OFF, elles arrivent au comptoir mais n'entrent PAS dans la file borne
 * « à encaisser ». La route est READ-ONLY (aucun changement de statut/paiement) et
 * ne doit remonter QUE les web PENDING de la branche, pas les borne ni les web déjà
 * acceptées/annulées.
 */
class WebOrdersPendingEndpointTest extends TestCase
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

    private function pendingIds(): array
    {
        $res = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos/web-orders/pending');
        $res->assertOk();

        return collect($res->json('data'))->pluck('id')->all();
    }

    /** @test */
    public function web_order_pending_est_visible_en_caisse(): void
    {
        $web = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'source_surface' => 'web',
            'order_type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
        ]);

        $this->assertContains($web->id, $this->pendingIds());
    }

    /** @test */
    public function exclut_borne_web_acceptee_et_autre_branche(): void
    {
        $kiosk = Order::factory()->create([
            'branch_id' => $this->branch->id, 'source_surface' => 'kiosk',
            'status' => OrderStatus::PENDING, 'payment_status' => PaymentStatus::PENDING_COUNTER,
        ]);
        $webAccepted = Order::factory()->create([
            'branch_id' => $this->branch->id, 'source_surface' => 'web',
            'status' => OrderStatus::ACCEPT, 'payment_status' => PaymentStatus::UNPAID,
        ]);
        $otherBranch = Order::factory()->create([
            'branch_id' => Branch::factory()->create()->id, 'source_surface' => 'web',
            'status' => OrderStatus::PENDING, 'payment_status' => PaymentStatus::UNPAID,
        ]);

        $ids = $this->pendingIds();

        $this->assertNotContains($kiosk->id, $ids, 'une commande borne ne doit pas apparaître dans la file web');
        $this->assertNotContains($webAccepted->id, $ids, 'une commande web déjà acceptée n\'est plus « à traiter »');
        $this->assertNotContains($otherBranch->id, $ids, 'isolation branche : pas de fuite cross-branch');
    }
}
