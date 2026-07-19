<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\KdsSyncService;
use App\Services\KitchenDisplaySystemOrderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * [GOAL ULTRA-SYNC W4 2026-07-20] Sentinelle minuit-straddle des commandes
 * PROGRAMMÉES — Le Cayenne opère APRÈS minuit (commandes réelles 23h-02h,
 * DB-prouvé, cf. OssKdsMidnightStraddleTest).
 *
 * Cas piégeux : à 23:50, une commande programmée pour 00:30 LE LENDEMAIN.
 * La fenêtre scheduled est un calcul d'INSTANTS (now + lead vs scheduled_at),
 * pas de jour civil — le passage de minuit ne doit ni la faire apparaître
 * trop tôt (23:50 : horizon 00:10 < 00:30 → invisible) ni la perdre après
 * minuit (00:15 : horizon 00:35 >= 00:30 → visible, et la fenêtre glissante
 * 8h garde son order_datetime de la veille). Verrouille list() ET sync()
 * (le delta doit refléter le board — leçon Wave 1).
 */
class ScheduledMidnightStraddleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['kds.scheduled_lead_minutes' => 20]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function freezeAt(string $parisTime): void
    {
        $now = CarbonImmutable::parse($parisTime, 'Europe/Paris');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    private function kdsListIds(Branch $branch): array
    {
        return app(KitchenDisplaySystemOrderService::class)
            ->list(new Request(['branch_id' => $branch->id]))
            ->pluck('id')
            ->all();
    }

    private function kdsSyncIds(Branch $branch): array
    {
        Cache::flush();

        return array_map(
            fn ($o) => (int) ($o['id'] ?? 0),
            app(KdsSyncService::class)->sync($branch->id, new DateTimeImmutable('2000-01-01T00:00:00'), true)['orders']
        );
    }

    /** @test */
    public function programmee_pour_00h30_invisible_a_23h50_puis_visible_a_00h15(): void
    {
        // « Maintenant » = 23:50 Paris (hiver CET — pas de piège DST).
        $this->freezeAt('2026-01-15 23:50:00');

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        // Commande passée à 23:50, programmée pour 00:30 le LENDEMAIN (J+1).
        $order = Order::factory()->create([
            'branch_id'        => $branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::ACCEPT,
            'payment_status'   => PaymentStatus::PAID,
            'order_datetime'   => Carbon::parse('2026-01-15 23:50:00', 'Europe/Paris'),
            'is_advance_order' => Ask::NO,
            'queue_number'     => 'A2350',
            'business_date'    => '2026-01-15',
            'scheduled_at'     => Carbon::parse('2026-01-16 00:30:00', 'Europe/Paris'),
        ]);

        // === Phase 1 — 23:50 : horizon = 00:10 < 00:30 → HORS fenêtre partout ===
        $this->assertNotContains($order->id, $this->kdsListIds($branch),
            'KDS list à 23:50 (lead 20) : la programmée de 00:30 J+1 est encore hors fenêtre — invisible.');
        $this->assertNotContains($order->id, $this->kdsSyncIds($branch),
            'KDS sync à 23:50 : le delta reflète le board — la programmée hors fenêtre n\'y est pas.');

        // === Phase 2 — 00:15 J+1 : horizon = 00:35 >= 00:30 → fenêtre OUVERTE ===
        // Le passage de minuit ne doit PAS la perdre : order_datetime 23:50 J
        // reste dans la fenêtre glissante 8h, et le calcul d'instants ouvre la
        // fenêtre scheduled à 00:10 J+1 (00:30 - lead).
        $this->freezeAt('2026-01-16 00:15:00');

        $this->assertContains($order->id, $this->kdsListIds($branch),
            'KDS list à 00:15 : la programmée de 00:30 DOIT être sur le board (T-15 < lead 20) malgré la bascule de minuit.');
        $this->assertContains($order->id, $this->kdsSyncIds($branch),
            'KDS sync à 00:15 : le delta la porte aussi (parité list — leçon Wave 1).');
    }
}
