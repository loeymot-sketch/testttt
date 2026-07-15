<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [F-DONUT-CATCHALL 2026-07-15 / P2] channelStatistics n'avait aucun bucket fourre-tout →
 * une commande ni kiosk ni pos (ex. livraison source_surface='delivery', source null) était
 * dans $total mais dans aucun des 3 comptes → la somme des tranches du donut < 100 %. « Web »
 * est désormais le complément exact de (kiosk ∪ pos). Ce sentinel verrouille la somme = 100 %.
 */
class DashboardChannelCatchAllSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function order(Branch $branch, int $source, ?string $surface): void
    {
        Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total' => 10.00,
            'source' => $source,
            'source_surface' => $surface,
            'order_datetime' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_channel_statistics_sum_to_100_with_delivery_order(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $branch = Branch::factory()->create();
        $this->order($branch, Source::WEB, 'web');      // Web
        $this->order($branch, Source::APP, 'kiosk');    // Kiosk
        $this->order($branch, Source::POS, 'pos');      // POS
        // La commande LIVRAISON : source_surface='delivery', source neutre → auparavant dropée.
        $this->order($branch, 0, 'delivery');

        $channels = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard/channel-statistics')
            ->assertOk()
            ->json('data');

        $sum = collect($channels)->sum(fn ($c) => (float) $c['value']);
        $this->assertEqualsWithDelta(100.0, $sum, 0.02,
            'Les 3 tranches du donut doivent sommer à 100 % — la livraison rejoint le canal Web (catch-all).');

        // La livraison (1 sur 4) doit être comptée dans Web (25 %), pas perdue.
        $web = (float) collect($channels)->firstWhere('name', 'Web')['value'];
        $this->assertEqualsWithDelta(50.0, $web, 0.02, 'Web = web réel + livraison = 2/4 = 50 %.');
    }
}
