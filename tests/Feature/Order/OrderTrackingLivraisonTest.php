<?php

namespace Tests\Feature\Order;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Order;
use App\Services\OrderTrackingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M2-102] Le suivi d'une LIVRAISON annonce son heure de départ réelle.
 *
 * Constat (commande 8100, deux fois) : créée à 00:01 pour la tournée de 20:30, le suivi
 * répondait `wait_low 10, wait_high 15, prevue_pour null` — « Prêt dans ~10–15 min » au client.
 * Le site envoie `scheduled_at = NULL` (et `delivery_time = 'ASAP'`) même pour un créneau
 * choisi : la seule heure vraie est `delivery_slot_at`, le départ de la tournée.
 */
class OrderTrackingLivraisonTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $maintenant = CarbonImmutable::parse('2026-10-22 00:01:00', config('app.timezone'));
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

    private function livraison(?string $depart, int $status = OrderStatus::PENDING, array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'        => $this->branch->id,
            'order_type'       => OrderType::DELIVERY,
            'source_surface'   => 'delivery',
            'status'           => $status,
            'payment_status'   => PaymentStatus::UNPAID,
            'order_datetime'   => now(),
            'is_advance_order' => Ask::NO,
            'scheduled_at'     => null,
            'delivery_time'    => 'ASAP',
            'delivery_slot_at' => $depart ? Carbon::parse($depart, config('app.timezone')) : null,
        ], $overrides));
    }

    /** @test */
    public function une_livraison_du_soir_annonce_son_depart_et_non_10_15_min(): void
    {
        $suivi = app(OrderTrackingService::class)->forOrder($this->livraison('2026-10-22 20:30:00'));

        $this->assertSame('20:30', $suivi['prevue_pour'], 'Le suivi doit annoncer l\'heure de départ de la tournée.');
        $this->assertSame('2026-10-22', $suivi['prevue_le']);
        // 00:01 → 20:30 = 1229 minutes. Jamais la fourchette générique 10-15.
        $this->assertSame(1229, $suivi['wait_low']);
        $this->assertSame(1229, $suivi['wait_high']);
    }

    /** @test */
    public function une_livraison_du_lendemain_porte_son_jour(): void
    {
        $suivi = app(OrderTrackingService::class)->forOrder($this->livraison('2026-10-23 20:00:00'));

        $this->assertSame('20:00', $suivi['prevue_pour']);
        $this->assertSame('2026-10-23', $suivi['prevue_le'], 'Sans le jour, « 20:00 » se lit « ce soir ».');
    }

    /** @test */
    public function le_temps_du_caissier_ne_masque_pas_un_depart_lointain(): void
    {
        $order = $this->livraison('2026-10-22 20:30:00', OrderStatus::ACCEPT, [
            'payment_status'                => PaymentStatus::PAID,
            'preparation_time'              => 15,
            'accepted_at'                   => now(),
            'preparation_time_confirmed_at' => now(),
        ]);

        $suivi = app(OrderTrackingService::class)->forOrder($order);

        $this->assertSame(1229, $suivi['wait_low'], 'Acceptée avec 15 min de préparation, elle ne part qu\'à 20:30.');
    }

    /** @test */
    public function la_route_du_client_frontend_order_lit_aussi_le_depart(): void
    {
        $order = $this->livraison('2026-10-22 20:30:00');

        $suivi = app(OrderTrackingService::class)->forOrder(FrontendOrder::query()->findOrFail($order->id));

        $this->assertSame('20:30', $suivi['prevue_pour']);
        $this->assertSame(1229, $suivi['wait_low']);
    }

    /** @test */
    public function une_livraison_sans_tournee_ou_deja_partie_garde_le_comportement_historique(): void
    {
        $sansTournee = app(OrderTrackingService::class)->forOrder($this->livraison(null));
        $this->assertNull($sansTournee['prevue_pour']);
        $this->assertNull($sansTournee['prevue_le']);

        $passee = app(OrderTrackingService::class)->forOrder($this->livraison('2026-10-21 23:30:00'));
        $this->assertNull($passee['prevue_pour'], 'Un départ passé n\'est plus une heure à venir.');
        $this->assertSame($sansTournee['wait_low'], $passee['wait_low'], 'Départ passé : même estimation qu\'une livraison sans tournée.');
    }
}
