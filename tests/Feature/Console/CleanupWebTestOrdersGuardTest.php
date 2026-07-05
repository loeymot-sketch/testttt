<?php

namespace Tests\Feature\Console;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * [SELF-AUDIT D 2026-07-05] `foodking:cleanup-web-test-orders` purge des commandes de TEST web
 * (source=WEB, non fiscalisées). Le canal Uber réutilise source=WEB → une commande Uber LIVE PAYÉE
 * (source_surface='uber_eats', PAID, fiscal_sequence_no NULL) matchait la purge → soft-delete →
 * le dédup Uber refuse ensuite de la recréer (tombstone) → PERTE DÉFINITIVE. Ce test verrouille
 * l'exclusion du canal aggregateur ET des commandes payées.
 */
class CleanupWebTestOrdersGuardTest extends TestCase
{
    use RefreshDatabase;

    private function webOrder(array $overrides): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        return Order::factory()->create(array_merge([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'source' => Source::WEB,
            'fiscal_sequence_no' => null,
            'status' => OrderStatus::ACCEPT,
        ], $overrides));
    }

    /** @test — une commande Uber LIVE PAYÉE (source=WEB collision) n'est JAMAIS touchée par la purge web-test. */
    public function paid_uber_order_is_never_soft_deleted(): void
    {
        $uber = $this->webOrder([
            'source_surface' => 'uber_eats',
            'payment_status' => PaymentStatus::PAID,
        ]);

        Artisan::call('foodking:cleanup-web-test-orders', ['--confirm' => true]);

        $this->assertNull(
            $uber->fresh()->deleted_at,
            'Une commande Uber PAYÉE (source=WEB) ne doit JAMAIS être soft-deletée par la purge web-test.'
        );
    }

    /** @test — un vrai invité e2e web NON payé reste nettoyé (comportement préservé). */
    public function genuine_unpaid_web_test_order_is_still_deleted(): void
    {
        $webTest = $this->webOrder([
            'source_surface' => null,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
        ]);

        Artisan::call('foodking:cleanup-web-test-orders', ['--confirm' => true]);

        $this->assertNotNull(
            $webTest->fresh()->deleted_at,
            'Un invité e2e web NON payé doit rester nettoyable (la purge garde son utilité).'
        );
    }

    /** @test — même une commande PAYÉE web NON-uber est épargnée (une vente réelle ne se purge pas). */
    public function paid_web_order_without_uber_surface_is_also_spared(): void
    {
        $paidWeb = $this->webOrder([
            'source_surface' => null,
            'payment_status' => PaymentStatus::PAID,
        ]);

        Artisan::call('foodking:cleanup-web-test-orders', ['--confirm' => true]);

        $this->assertNull(
            $paidWeb->fresh()->deleted_at,
            'Une commande web PAYÉE (vente réelle) ne doit pas être purgée par un outil de test.'
        );
    }
}
