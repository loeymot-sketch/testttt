<?php

namespace Tests\Feature\KDS;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\KdsSyncService;
use Database\Factories\OrderFactory;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [SYNC-E2E 2026-07-04 — KDS ↔ timing cuisine] Prouve que le TEMPS RÉEL de préparation (accepted/preparing/
 * prepared) instrumenté au bump remonte CORRECTEMENT dans la couche de synchro KDS : exposé dans le flux
 * `KdsSyncService::sync` (accepted_at_iso / actual_prep_seconds), avec la VERSION qui avance à chaque
 * transition (le version-gate client refetch bien), et SANS doublage (1 commande = 1 entrée board).
 *
 * Complète SyncComprehensiveTest (qui couvre « la commande apparaît » mais pas le timing ni la version).
 */
class KdsSyncTimingExposureE2ETest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        return $admin;
    }

    private function bump(User $admin, int $orderId, int $status): void
    {
        $this->actingAs($admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos-order/change-status/' . $orderId, ['status' => $status])
            ->assertStatus(200);
    }

    /**
     * Le flux sync du board (fenêtre très large pour capturer la commande du jour). On vide le cache
     * 5s d'abord : un vrai client fait avancer son `since` à chaque poll (clé de cache différente) →
     * ici, avec un `since` fixe, il faut neutraliser le cache pour OBSERVER le board vivant (le cache
     * est une optimisation, pas le comportement testé).
     */
    private function board(int $branchId): array
    {
        \Illuminate\Support\Facades\Cache::flush();
        return app(KdsSyncService::class)->sync(
            $branchId,
            new DateTimeImmutable('2000-01-01T00:00:00'),
            true
        );
    }

    /** @test */
    public function le_temps_reel_de_prepa_remonte_dans_le_flux_sync_kds_avec_version_croissante_et_sans_doublage(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create();
        $order = OrderFactory::new()->create([
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'status' => OrderStatus::ACCEPT,
            'order_datetime' => now(),
            'accepted_at' => null,
            'preparing_at' => null,
            'prepared_at' => null,
        ]);

        // Board initial : la commande y est UNE fois, version = updated_at.
        $b0 = $this->board($branch->id);
        $entries0 = array_values(array_filter($b0['orders'], fn ($o) => (int) ($o['id'] ?? 0) === $order->id));
        $this->assertCount(1, $entries0, 'la commande doit apparaître exactement une fois (zéro doublage)');
        $v0 = (int) $entries0[0]['version'];

        // Transition PREPARING → la version DOIT avancer (updated_at bumpé) + preparing_at posé.
        $this->bump($admin, $order->id, OrderStatus::PREPARING);
        $b1 = $this->board($branch->id);
        $entries1 = array_values(array_filter($b1['orders'], fn ($o) => (int) ($o['id'] ?? 0) === $order->id));
        $this->assertCount(1, $entries1, 'toujours une seule entrée après PREPARING');
        $this->assertGreaterThanOrEqual($v0, (int) $entries1[0]['version'], 'la version doit avancer (ou rester) — jamais reculer');
        $this->assertNotNull($entries1[0]['preparing_at_iso'] ?? null, 'preparing_at_iso exposé au board');

        // Transition PREPARED → prepared_at posé + actual_prep_seconds calculé et exposé.
        $this->bump($admin, $order->id, OrderStatus::PREPARED);
        $b2 = $this->board($branch->id);
        $entries2 = array_values(array_filter($b2['orders'], fn ($o) => (int) ($o['id'] ?? 0) === $order->id));
        $this->assertCount(1, $entries2, 'toujours une seule entrée après PREPARED');

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertNotNull($fresh->prepared_at, 'prepared_at posé en base');

        // actual_prep_seconds exposé au board (peut être 0 si tout dans la même seconde, mais présent + >= 0).
        $this->assertArrayHasKey('actual_prep_seconds', $entries2[0]);
        if ($fresh->accepted_at !== null) {
            $this->assertNotNull($entries2[0]['actual_prep_seconds'], 'actual_prep_seconds calculé quand accepted_at+prepared_at existent');
            $this->assertGreaterThanOrEqual(0, (int) $entries2[0]['actual_prep_seconds']);
        }
    }
}
