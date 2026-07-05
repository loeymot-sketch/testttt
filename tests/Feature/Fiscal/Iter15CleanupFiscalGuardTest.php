<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [WAVE5 GÉRANCE 2026-07-04] `iter15:cleanup-test-orders` faisait un HARD-delete d'ordres en statut
 * actif matchant un token de test SANS exclure les ordres fiscalisés → un n° de séquence fiscale
 * alloué disparaissait physiquement = rupture gap-free NF525 (ZReportService agrège withTrashed, donc
 * un soft-delete survit mais un hard-delete disparaît). Ce test verrouille le garde NF525 (miroir du
 * jumeau sûr CleanupWebTestOrdersCommand) : un ordre FISCALISÉ ne doit JAMAIS être supprimé.
 */
class Iter15CleanupFiscalGuardTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $token, ?int $fiscal): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        return Order::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'token' => $token,
            'status' => OrderStatus::ACCEPT, // dans ACTIVE_STATUSES [1,4,7,8]
            'fiscal_sequence_no' => $fiscal,
        ]);
    }

    /** @test — un ordre FISCALISÉ (token de test, statut actif) NE DOIT JAMAIS être hard-deleté. */
    public function fiscalised_test_order_is_never_deleted(): void
    {
        $fiscal = $this->order('ITER15GUARD-fiscal-1', 4242);
        $plain = $this->order('ITER15GUARD-plain-1', null);

        Artisan::call('iter15:cleanup-test-orders', [
            '--token-prefix' => ['ITER15GUARD'],
            '--apply' => true,
        ]);

        $this->assertNotNull(
            Order::withTrashed()->withoutGlobalScopes()->find($fiscal->id),
            'Un ordre FISCALISÉ ne doit JAMAIS quitter la table (garde gap-free NF525).'
        );
        $this->assertNull(
            Order::withoutGlobalScopes()->find($plain->id),
            'Une fixture NON fiscalisée reste bien nettoyée (comportement préservé).'
        );
    }

    /** @test — le garde tient même si l'ordre fiscalisé est le n° de séquence MAX (risque de réutilisation). */
    public function fiscalised_max_sequence_order_survives_cleanup(): void
    {
        $max = $this->order('ITER15GUARD-max', 999999);

        Artisan::call('iter15:cleanup-test-orders', [
            '--token-prefix' => ['ITER15GUARD'],
            '--apply' => true,
        ]);

        $this->assertNotNull(
            Order::withTrashed()->withoutGlobalScopes()->find($max->id),
            'Hard-deleter le MAX fiscalisé provoquerait une réutilisation de n° de séquence — interdit.'
        );
    }

    /**
     * @test — [SELF-AUDIT P3 2026-07-05] un ordre matché PORTANT une ligne order_coupons est bien
     * supprimé. order_coupons.order_id = constrained('orders') = ON DELETE RESTRICT : avant le fix,
     * le delete final throwait → rollback de TOUT le lot (aucune fixture nettoyée, échec silencieux).
     */
    public function order_with_coupon_row_is_deleted_without_fk_rollback(): void
    {
        $withCoupon = $this->order('ITER15GUARD-coupon-1', null);
        $plain = $this->order('ITER15GUARD-coupon-2', null);

        DB::table('order_coupons')->insert([
            'order_id' => $withCoupon->id,
            'coupon_id' => 777,
            'user_id' => $withCoupon->user_id,
            'discount' => 1.50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('iter15:cleanup-test-orders', [
            '--token-prefix' => ['ITER15GUARD'],
            '--apply' => true,
        ]);
        $this->assertSame(0, $exit, 'La commande doit se terminer proprement (pas de FK qui casse le run).');

        $this->assertNull(
            Order::withoutGlobalScopes()->find($withCoupon->id),
            'L\'ordre AVEC coupon est supprimé (order_coupons cascadé avant le delete orders).'
        );
        $this->assertNull(
            Order::withoutGlobalScopes()->find($plain->id),
            'L\'autre fixture aussi — la FK RESTRICT ne rollback plus TOUT le lot.'
        );
        $this->assertSame(
            0,
            DB::table('order_coupons')->where('order_id', $withCoupon->id)->count(),
            'La ligne order_coupons est bien cascadée.'
        );
    }
}
