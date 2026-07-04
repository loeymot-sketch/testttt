<?php

namespace Tests\Feature\KDS;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\KdsSyncService;
use App\Services\KitchenDisplaySystemOrderService;
use Database\Factories\OrderFactory;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * [ULTRA-AUDIT 2026-07-04 — P2 SSOT board-release] Le flux delta `KdsSyncService::sync()` doit appliquer
 * le MÊME filtre de release que le board autoritaire `list()` (KitchenReleaseRule) : une commande en statut
 * actif mais NON released par le paiement (UNPAID non-cash) ne doit apparaître NI dans sync NI dans list.
 * Avant : sync ne filtrait que par statut → fuite d'incohérence entre 2 chemins d'une fonction partagée.
 */
class KdsSyncBoardReleaseConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
    }

    private function syncIds(int $branchId): array
    {
        Cache::flush();
        $r = app(KdsSyncService::class)->sync($branchId, new DateTimeImmutable('2000-01-01T00:00:00'), true);
        return array_map(fn ($o) => (int) ($o['id'] ?? 0), $r['orders']);
    }

    private function make(Branch $branch, int $paymentStatus, int $orderType): Order
    {
        return OrderFactory::new()->create([
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => $paymentStatus,
            'order_type' => $orderType,
            'order_datetime' => now(),
        ]);
    }

    /** @test */
    public function une_commande_non_released_est_absente_du_flux_sync_comme_du_board(): void
    {
        $branch = Branch::factory()->create();
        // Released : PAID → visible partout.
        $paid = $this->make($branch, PaymentStatus::PAID, OrderType::DELIVERY);
        // NON released : UNPAID + livraison (pas POS-cash) → doit être filtrée des DEUX.
        $unpaid = $this->make($branch, PaymentStatus::UNPAID, OrderType::DELIVERY);

        $syncIds = $this->syncIds($branch->id);
        $this->assertContains($paid->id, $syncIds, 'la commande PAID est dans le flux sync');
        $this->assertNotContains($unpaid->id, $syncIds, 'la commande UNPAID non-cash NE DOIT PAS être dans sync (release filter)');

        // Cohérence avec le board autoritaire list() : même verdict (list() renvoie une Collection Eloquent).
        $listIds = collect(app(KitchenDisplaySystemOrderService::class)
            ->list(new \Illuminate\Http\Request(['branch_id' => $branch->id])))
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertNotContains($unpaid->id, $listIds, 'la commande UNPAID est aussi absente du board list()');
    }
}
