<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [SIGNAL ANNULATION CUISINE 2026-08-19] Le board doit DIRE au cuisinier qu'une carte
 * vient de disparaître — sinon le plat reste sur le passe.
 *
 * Défaut réparé : `visibleStatuses()` = ACCEPT/PREPARING/PREPARED ; dès qu'une commande
 * passe à ANNULEE, elle sort de cet ensemble et sa carte s'évapore au sondage suivant,
 * en silence. Constaté en base : 12 annulations depuis PREPARING/PREPARED/OUT, dont
 * #6598 annulée 51 minutes APRÈS le bip « Prêt ».
 */
class KdsCanceledNoticeTest extends TestCase
{
    use RefreshDatabase;

    private function bootBranchAndAdmin(): User
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function makeOrder(array $attributes = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'          => 1,
            'order_type'         => OrderType::POS,
            'status'             => OrderStatus::CANCELED,
            'payment_status'     => PaymentStatus::PAID,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'is_advance_order'   => Ask::NO,
            'order_datetime'     => now(),
        ], $attributes));
    }

    private function recordCancel(Order $order, int $fromStatus, ?string $modelClass = null, ?string $reason = 'Client injoignable', ?int $minutesAgo = 2): void
    {
        OrderStatusTransition::create([
            'order_id'    => $order->id,
            'order_type'  => $modelClass ?? Order::class,
            'from_status' => $fromStatus,
            'to_status'   => OrderStatus::CANCELED,
            'actor_id'    => null,
            'actor_type'  => 'user',
            'reason'      => $reason,
            'occurred_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    public function test_une_commande_annulee_depuis_prete_est_signalee_avec_son_motif(): void
    {
        $this->bootBranchAndAdmin();
        $order = $this->makeOrder(['queue_number' => 'A0032']);
        $this->recordCancel($order, OrderStatus::PREPARED);

        $notices = app(KitchenDisplaySystemOrderService::class)->recentlyCanceled();

        $this->assertCount(1, $notices, 'La cuisine doit être prévenue de la disparition de la carte.');
        $this->assertSame((int) $order->id, $notices[0]['id']);
        $this->assertSame('A0032', $notices[0]['queue_number']);
        $this->assertSame(OrderStatus::PREPARED, $notices[0]['from_status']);
        $this->assertSame('Client injoignable', $notices[0]['reason']);
    }

    public function test_une_commande_jamais_arrivee_sur_le_board_ne_fait_pas_de_bruit(): void
    {
        $this->bootBranchAndAdmin();
        $order = $this->makeOrder();
        // PENDING n'est pas dans visibleStatuses() : le cuisinier n'a jamais vu cette carte.
        $this->recordCancel($order, OrderStatus::PENDING);

        $this->assertSame([], app(KitchenDisplaySystemOrderService::class)->recentlyCanceled());
    }

    /**
     * LE PIÈGE DU JUMEAU. `Order` et `FrontendOrder` écrivent dans la MÊME table `orders`,
     * et `order_status_transitions.order_type` porte l'un OU l'autre nom de classe (les deux
     * valeurs existent en base). Un filtre sur `Order::class` rendrait le bandeau MUET pour
     * toute commande venue du site — exactement le défaut « jumeau oublié » que ce dépôt a
     * déjà payé plusieurs fois.
     */
    public function test_une_annulation_ecrite_au_nom_de_frontend_order_est_signalee_aussi(): void
    {
        $this->bootBranchAndAdmin();
        $order = $this->makeOrder(['order_type' => OrderType::TAKEAWAY, 'pos_payment_method' => null]);
        $this->recordCancel($order, OrderStatus::PREPARING, \App\Models\FrontendOrder::class);

        $notices = app(KitchenDisplaySystemOrderService::class)->recentlyCanceled();

        $this->assertCount(1, $notices, 'Une commande du site annulée en cuisine doit alerter comme une commande caisse.');
        $this->assertSame((int) $order->id, $notices[0]['id']);
    }

    public function test_une_commande_non_liberee_au_paiement_ne_fait_pas_de_bruit(): void
    {
        $this->bootBranchAndAdmin();
        // Impayée, hors POS-cash : le board ne l'a jamais montrée (applyBoardReleaseFilter).
        $order = $this->makeOrder([
            'order_type'         => OrderType::TAKEAWAY,
            'payment_status'     => PaymentStatus::UNPAID,
            'pos_payment_method' => null,
        ]);
        $this->recordCancel($order, OrderStatus::PREPARED);

        $this->assertSame([], app(KitchenDisplaySystemOrderService::class)->recentlyCanceled());
    }

    public function test_le_bandeau_ne_ressasse_pas_les_annulations_anciennes(): void
    {
        $this->bootBranchAndAdmin();
        config(['kds.canceled_notice_minutes' => 20]);
        $order = $this->makeOrder();
        $this->recordCancel($order, OrderStatus::PREPARED, null, 'Erreur de saisie', 45);

        $this->assertSame([], app(KitchenDisplaySystemOrderService::class)->recentlyCanceled());
    }

    public function test_une_caisse_ne_voit_pas_les_annulations_dune_autre_branche(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);

        $orderAilleurs = $this->makeOrder(['branch_id' => 2]);
        $this->recordCancel($orderAilleurs, OrderStatus::PREPARED);

        $staff = User::factory()->create(['branch_id' => 1]);
        $staff->assignRole('Branch Manager');
        $this->actingAs($staff, 'sanctum');

        $this->assertSame([], app(KitchenDisplaySystemOrderService::class)->recentlyCanceled());
    }

    public function test_une_seule_entree_par_commande_meme_avec_plusieurs_transitions(): void
    {
        $this->bootBranchAndAdmin();
        $order = $this->makeOrder();
        $this->recordCancel($order, OrderStatus::PREPARING, null, 'Premier motif', 9);
        $this->recordCancel($order, OrderStatus::PREPARED, null, 'Motif le plus récent', 3);

        $notices = app(KitchenDisplaySystemOrderService::class)->recentlyCanceled();

        $this->assertCount(1, $notices, 'Le cuisinier a un plat à retirer, pas un historique.');
        $this->assertSame('Motif le plus récent', $notices[0]['reason']);
    }
}
