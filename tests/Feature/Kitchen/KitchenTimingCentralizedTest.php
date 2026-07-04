<?php

namespace Tests\Feature\Kitchen;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Database\Factories\OrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ULTRA-AUDIT 2026-07-04 — P2 timing centralisé] Le stamp d'horodatage cuisine vit maintenant au niveau
 * MODÈLE (hook saving) → couvre AUSSI les flux auto-prepare qui CRÉENT la commande directement à
 * ACCEPT/PREPARING sans passer par changeStatus (borne Plan B, POS direct, counter-collect). Avant : ces
 * flux (≈100 % du volume) laissaient accepted_at NULL → actual_prep_seconds toujours NULL.
 */
class KitchenTimingCentralizedTest extends TestCase
{
    use RefreshDatabase;

    private function order(int $status, array $extra = []): Order
    {
        $branch = Branch::factory()->create();
        return OrderFactory::new()->create(array_merge([
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'status' => $status,
            'accepted_at' => null,
            'preparing_at' => null,
            'prepared_at' => null,
        ], $extra));
    }

    /** @test */
    public function une_commande_creee_directement_a_accept_est_horodatee_accepted(): void
    {
        // Borne Plan B / counter-collect : née à ACCEPT sans transition PENDING→ACCEPT.
        $order = $this->order(OrderStatus::ACCEPT);
        $this->assertNotNull($order->fresh()->accepted_at, 'née à ACCEPT → accepted_at posé par le hook modèle');
    }

    /** @test */
    public function une_commande_creee_directement_a_preparing_est_horodatee_en_cascade(): void
    {
        // POS direct auto-prepare : née à PREPARING, saute ACCEPT.
        $order = $this->order(OrderStatus::PREPARING)->fresh();
        $this->assertNotNull($order->accepted_at, 'cascade : PREPARING implique accepted_at');
        $this->assertNotNull($order->preparing_at, 'preparing_at posé');
    }

    /** @test */
    public function actual_prep_seconds_n_est_plus_null_apres_preparation(): void
    {
        // Née à ACCEPT (accepted_at posé), puis marquée PREPARED → prepared_at posé → actual_prep non-null.
        $order = $this->order(OrderStatus::ACCEPT);
        $order->status = OrderStatus::PREPARED;
        $order->save();
        $fresh = $order->fresh();

        $this->assertNotNull($fresh->accepted_at);
        $this->assertNotNull($fresh->prepared_at, 'PREPARED → prepared_at (+ cascade preparing_at)');
        $this->assertNotNull($fresh->preparing_at, 'cascade : PREPARED implique preparing_at');

        // Le consommateur KDS calcule enfin une valeur (accepted_at && prepared_at tous deux présents).
        $resource = (new \App\Http\Resources\KDSOrderDetailsResource($fresh))->resolve();
        $this->assertNotNull($resource['actual_prep_seconds'], 'actual_prep_seconds calculé (plus NULL)');
        $this->assertGreaterThanOrEqual(0, (int) $resource['actual_prep_seconds']);
    }

    /** @test */
    public function une_commande_annulee_ou_en_attente_n_est_pas_horodatee(): void
    {
        $pending = $this->order(OrderStatus::PENDING)->fresh();
        $this->assertNull($pending->accepted_at, 'PENDING = pas de flux cuisine → pas d\'horodatage');
    }
}
