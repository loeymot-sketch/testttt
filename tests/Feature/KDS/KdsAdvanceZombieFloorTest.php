<?php

namespace Tests\Feature\KDS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * [F-02 AUDIT CUISINIER 2026-08-01 · P1] Plancher d'âge des commandes À L'AVANCE.
 *
 * La branche « advance » de la requête du board n'avait AUCUN plancher (les commandes
 * standard, elles, ont $staleFloor) : une commande à l'avance jamais livrée restait
 * affichée POUR TOUJOURS. Constaté en base : une commande de 9 jours SANS AUCUNE LIGNE
 * (« ATTENTE 12389:38 ») occupait la tuile n°1 des 3 slots visibles du cuisinier, et
 * 15 zombies de 49 jours attendaient derrière — les vraies commandes du service étaient
 * repoussées dans « +N en attente ».
 *
 * Contrat scellé ici :
 *  1. une commande à l'avance EN RETARD RÉCENT (hier, non retirée) reste visible —
 *     c'est le cas d'usage légitime, il ne doit pas être cassé par le plancher ;
 *  2. un zombie (plus vieux que le plancher) QUITTE le board ;
 *  3. il n'est jamais supprimé : la ligne reste en base (historique/admin intacts).
 */
class KdsAdvanceZombieFloorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['oss.advance_stale_window_hours' => 48]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function advanceOrder(Branch $branch, Carbon $orderDatetime): Order
    {
        return Order::factory()->create([
            'branch_id'        => $branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::ACCEPT,
            'payment_status'   => PaymentStatus::PAID,
            'order_datetime'   => $orderDatetime,
            'is_advance_order' => Ask::YES,
            'scheduled_at'     => null,
        ]);
    }

    private function boardIds(int $branchId): array
    {
        return app(KitchenDisplaySystemOrderService::class)
            ->list(new Request(['branch_id' => $branchId]))
            ->pluck('id')
            ->all();
    }

    /** @test */
    public function une_commande_a_l_avance_en_retard_recent_reste_visible_mais_le_zombie_disparait(): void
    {
        $now = CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Paris');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $branch = Branch::factory()->create();
        $admin  = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        // Retard LÉGITIME : commandée hier, jamais retirée → le cuisinier doit la voir.
        $enRetard = $this->advanceOrder($branch, Carbon::parse('2026-03-09 19:00:00', 'Europe/Paris'));
        // ZOMBIE : 9 jours, exactement le cas constaté en production.
        $zombie = $this->advanceOrder($branch, Carbon::parse('2026-03-01 12:00:00', 'Europe/Paris'));

        $ids = $this->boardIds($branch->id);

        $this->assertContains($enRetard->id, $ids,
            'Une commande à l\'avance en retard récent doit RESTER visible en cuisine.');
        $this->assertNotContains($zombie->id, $ids,
            'Une commande à l\'avance vieille de 9 jours ne doit plus occuper un slot du board.');

        // Rien n'est supprimé : la commande existe toujours (historique/admin).
        $this->assertDatabaseHas('orders', ['id' => $zombie->id]);
    }
}
