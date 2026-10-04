<?php

namespace Tests\Feature\Order;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Services\OrderTrackingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL STORES 2026-10-01] Le suivi d'une commande PROGRAMMÉE dit son heure.
 *
 * Avant : `OrderTrackingService::forOrder` ne regardait jamais `scheduled_at`. Une commande
 * passée à 03 h 00 pour 18 h 20 recevait la fourchette générique « 10-15 min » : la page de
 * suivi annonçait « prête dans ~10-15 min » devant un restaurant fermé, et le rappel natif de
 * l'application (qui lit `wait_low`) sonnait dix minutes plus tard, au lieu de 18 h 20.
 */
class OrderTrackingProgrammeeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $maintenant = CarbonImmutable::parse('2026-10-01 03:00:00', config('app.timezone'));
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

    private function commande(?string $prevue): Order
    {
        return Order::factory()->create([
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'order_datetime' => now(),
            'is_advance_order' => $prevue ? Ask::YES : Ask::NO,
            'scheduled_at' => $prevue ? Carbon::parse('2026-10-01 ' . $prevue . ':00', config('app.timezone')) : null,
        ]);
    }

    /** @test */
    public function une_commande_programmee_annonce_son_heure(): void
    {
        $suivi = app(OrderTrackingService::class)->forOrder($this->commande('18:20'));

        $this->assertSame('18:20', $suivi['prevue_pour']);
    }

    /** @test */
    public function ses_minutes_vont_jusqu_a_l_heure_programmee_et_non_10_15(): void
    {
        $suivi = app(OrderTrackingService::class)->forOrder($this->commande('18:20'));

        // 03:00 → 18:20 = 920 minutes. Jamais la fourchette générique 10-15.
        $this->assertSame(920, $suivi['wait_low']);
        $this->assertSame(920, $suivi['wait_high']);
    }

    /** @test */
    public function une_commande_immediate_garde_la_fourchette_generique(): void
    {
        $suivi = app(OrderTrackingService::class)->forOrder($this->commande(null));

        $this->assertNull($suivi['prevue_pour']);
        $this->assertSame(10, $suivi['wait_low']);
        $this->assertSame(15, $suivi['wait_high']);
    }

    /** @test */
    public function une_heure_programmee_deja_passee_n_est_plus_annoncee(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-01 18:30:00', config('app.timezone')));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 18:30:00', config('app.timezone')));

        $suivi = app(OrderTrackingService::class)->forOrder($this->commande('18:20'));

        $this->assertNull($suivi['prevue_pour'], 'Une heure dépassée ne doit plus s\'afficher comme une promesse.');
    }
}
