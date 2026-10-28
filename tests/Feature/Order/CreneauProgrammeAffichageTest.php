<?php

namespace Tests\Feature\Order;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Http\Resources\KDSOrderDetailsResource;
use App\Http\Resources\OrderDetailsResource;
use App\Models\Branch;
use App\Models\Order;
use App\Support\CreneauRetrait;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [E2E stores · vague B · F-B2 · 2026-10-01] La caisse lisait « demain » pour une commande de ce soir.
 *
 * Une commande web passée à 04 h 00 « À l'ouverture » (scheduled_at 01/10 18:20) s'affichait
 * « Heure de livraison : 02-10-2026 » sur la fiche de la caisse, sans heure : les ressources
 * ajoutaient un jour à `order_datetime` dès que `is_advance_order` valait OUI (ancienne règle
 * « commande pour le lendemain ») et ignoraient `scheduled_at`. Le caissier acceptait donc à 4 h
 * une commande « pour demain » qui devait sortir à 18 h 20.
 */
class CreneauProgrammeAffichageTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $maintenant = CarbonImmutable::parse('2026-10-01 04:00:00', config('app.timezone'));
        Carbon::setTestNow($maintenant);
        CarbonImmutable::setTestNow($maintenant);
        $this->branch = Branch::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function commande(array $attributs): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'order_datetime' => now(),
            'delivery_time' => null,
        ], $attributs));
    }

    /** @test */
    public function une_commande_programmee_ce_soir_affiche_sa_date_et_son_heure(): void
    {
        $commande = $this->commande([
            'is_advance_order' => Ask::YES,
            'scheduled_at' => Carbon::parse('2026-10-01 18:20:00', config('app.timezone')),
        ]);

        $this->assertSame('01-10-2026', CreneauRetrait::date($commande));
        $this->assertSame('18:20', CreneauRetrait::heure($commande));

        $fiche = (new OrderDetailsResource($commande))->resolve();
        $this->assertSame('01-10-2026', $fiche['delivery_date'], 'La fiche de la caisse disait « 02-10-2026 ».');
        $this->assertSame('18:20', $fiche['delivery_time']);

        $cuisine = (new KDSOrderDetailsResource($commande))->resolve();
        $this->assertSame('01-10-2026', $cuisine['delivery_date']);
    }

    /** @test */
    public function l_ancienne_commande_pour_le_lendemain_sans_heure_programmee_ne_change_pas(): void
    {
        $commande = $this->commande(['is_advance_order' => Ask::YES, 'scheduled_at' => null]);

        $this->assertSame('02-10-2026', CreneauRetrait::date($commande));
    }

    /** @test */
    public function une_commande_immediate_sans_creneau_n_affiche_aucune_date(): void
    {
        $commande = $this->commande(['is_advance_order' => Ask::NO, 'scheduled_at' => null]);

        $fiche = (new OrderDetailsResource($commande))->resolve();
        $this->assertNull($fiche['delivery_date']);
    }
}
