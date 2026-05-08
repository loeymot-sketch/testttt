<?php

namespace Tests\Feature\Frontend;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\OrderRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [WAVE-ALPHA-A3 / M-3] CSAT 5-star endpoint coverage.
 *
 * Couvre :
 *  - rating 1..5 accepté
 *  - rating < 1 ou > 5 rejeté (422)
 *  - idempotence : 2e POST sur même order met à jour (updateOrCreate)
 *  - comment optionnel + max 500 chars
 *  - 401 si non authentifié
 *  - branch_id hérité de l'order parent
 */
class OrderRatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function makeOrderForUser(): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'status'    => \App\Enums\Status::ACTIVE,
        ]);

        $order = FrontendOrder::create([
            'user_id'        => $user->id,
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::TAKEAWAY,
            'source'         => \App\Enums\Source::WEB,
            'source_surface' => 'kiosk',
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'payment_method' => 4,
            'subtotal'       => 10,
            'discount'       => 0,
            'delivery_charge'=> 0,
            'total_tax'      => 0,
            'total'          => 10,
            'order_datetime' => now(),
        ]);

        return [$branch, $user, $order];
    }

    public function test_customer_can_submit_rating_1_to_5(): void
    {
        [$branch, $user, $order] = $this->makeOrderForUser();

        foreach ([1, 2, 3, 4, 5] as $stars) {
            // updateOrCreate idempotent — purge entre itérations pour
            // tester chaque borne sur une création neuve.
            OrderRating::query()->delete();

            $response = $this->actingAs($user)
                ->postJson("/api/frontend/order/{$order->id}/rating", [
                    'rating' => $stars,
                    'source' => 'kiosk',
                ]);

            $response->assertStatus(201);
            $response->assertJsonPath('status', true);
            $response->assertJsonPath('data.rating', $stars);
            $this->assertDatabaseHas('order_ratings', [
                'order_id' => $order->id,
                'rating'   => $stars,
            ]);
        }
    }

    public function test_rating_below_1_or_above_5_rejected(): void
    {
        [$branch, $user, $order] = $this->makeOrderForUser();

        foreach ([0, -1, 6, 99] as $bogus) {
            $response = $this->actingAs($user)
                ->postJson("/api/frontend/order/{$order->id}/rating", [
                    'rating' => $bogus,
                ]);
            $response->assertStatus(422);
        }

        $this->assertDatabaseCount('order_ratings', 0);
    }

    public function test_same_order_rating_updateOrCreates(): void
    {
        [$branch, $user, $order] = $this->makeOrderForUser();

        $first = $this->actingAs($user)
            ->postJson("/api/frontend/order/{$order->id}/rating", ['rating' => 3]);
        $first->assertStatus(201);

        $second = $this->actingAs($user)
            ->postJson("/api/frontend/order/{$order->id}/rating", ['rating' => 5]);
        $second->assertStatus(201);

        // Toujours 1 ligne en DB — la 2e a updaté la 1re.
        $this->assertDatabaseCount('order_ratings', 1);
        $this->assertDatabaseHas('order_ratings', [
            'order_id' => $order->id,
            'rating'   => 5,
        ]);
    }

    public function test_comment_optional_max_500(): void
    {
        [$branch, $user, $order] = $this->makeOrderForUser();

        // Pas de comment → OK
        $r1 = $this->actingAs($user)
            ->postJson("/api/frontend/order/{$order->id}/rating", ['rating' => 4]);
        $r1->assertStatus(201);

        // Comment court accepté
        OrderRating::query()->delete();
        $r2 = $this->actingAs($user)
            ->postJson("/api/frontend/order/{$order->id}/rating", [
                'rating'  => 4,
                'comment' => 'Excellent burger',
            ]);
        $r2->assertStatus(201);
        $this->assertDatabaseHas('order_ratings', ['comment' => 'Excellent burger']);

        // Comment > 500 chars rejeté
        OrderRating::query()->delete();
        $r3 = $this->actingAs($user)
            ->postJson("/api/frontend/order/{$order->id}/rating", [
                'rating'  => 4,
                'comment' => str_repeat('a', 501),
            ]);
        $r3->assertStatus(422);
    }

    public function test_unauthenticated_rejected(): void
    {
        [$branch, $user, $order] = $this->makeOrderForUser();

        // Sans actingAs → auth:sanctum doit refuser.
        $response = $this->postJson("/api/frontend/order/{$order->id}/rating", [
            'rating' => 5,
        ]);

        // Selon configuration sanctum/api.php : 401 (Unauthenticated) ou 403.
        $this->assertContains($response->status(), [401, 403, 419]);
        $this->assertDatabaseCount('order_ratings', 0);
    }

    public function test_branch_id_inherited_from_order(): void
    {
        [$branch, $user, $order] = $this->makeOrderForUser();

        $response = $this->actingAs($user)
            ->postJson("/api/frontend/order/{$order->id}/rating", [
                'rating' => 5,
                'source' => 'kiosk',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('order_ratings', [
            'order_id'  => $order->id,
            'branch_id' => $branch->id,
        ]);
    }

    /**
     * [SEC / CLAUDE.md #8] Branch isolation : un user de la branche Y ne
     * doit JAMAIS pouvoir noter une commande de la branche X. BranchScope
     * cache l'order au find() → 404 et aucune écriture en DB.
     */
    public function test_cross_branch_user_cannot_rate_other_branch_order(): void
    {
        [$branchX, $userX, $orderX] = $this->makeOrderForUser();

        // User de la branche Y, totalement séparé de l'order de la branche X.
        $branchY = Branch::factory()->create();
        $userY = User::factory()->create([
            'branch_id' => $branchY->id,
            'status'    => \App\Enums\Status::ACTIVE,
        ]);

        $response = $this->actingAs($userY)
            ->postJson("/api/frontend/order/{$orderX->id}/rating", [
                'rating' => 5,
                'source' => 'kiosk',
            ]);

        // BranchScope masque l'order → controller retourne 404.
        $response->assertStatus(404);
        $this->assertDatabaseCount('order_ratings', 0);
    }
}
