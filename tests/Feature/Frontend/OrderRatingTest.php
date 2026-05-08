<?php

namespace Tests\Feature\Frontend;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\OrderRating;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
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

    /**
     * [SEC-HEAL-2026-05-08] OrderRatingController requires `kiosk:order`
     * sanctum ability (parité UpsellController + UpsellRecommendationController).
     * Helper centralisé pour éviter de dupliquer Sanctum::actingAs partout.
     */
    private function actAsKiosk(User $user): self
    {
        Sanctum::actingAs($user, ['kiosk:order']);
        return $this;
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

            $response = $this->actAsKiosk($user)
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
            $response = $this->actAsKiosk($user)
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

        $first = $this->actAsKiosk($user)
            ->postJson("/api/frontend/order/{$order->id}/rating", ['rating' => 3]);
        $first->assertStatus(201);

        $second = $this->actAsKiosk($user)
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
        $r1 = $this->actAsKiosk($user)
            ->postJson("/api/frontend/order/{$order->id}/rating", ['rating' => 4]);
        $r1->assertStatus(201);

        // Comment court accepté
        OrderRating::query()->delete();
        $r2 = $this->actAsKiosk($user)
            ->postJson("/api/frontend/order/{$order->id}/rating", [
                'rating'  => 4,
                'comment' => 'Excellent burger',
            ]);
        $r2->assertStatus(201);
        $this->assertDatabaseHas('order_ratings', ['comment' => 'Excellent burger']);

        // Comment > 500 chars rejeté
        OrderRating::query()->delete();
        $r3 = $this->actAsKiosk($user)
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

        $response = $this->actAsKiosk($user)
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
     * [SEC-HEAL-2026-05-08] Ultra-review P0 finding : sanctum-authenticated
     * user without `kiosk:order` ability must be rejected (403). Cas couvert :
     * un user customer authentifié via app mobile (token sans `kiosk:order`)
     * tente de noter une commande — rejeté avant même la lecture de l'order.
     */
    public function test_authenticated_non_kiosk_user_cannot_rate(): void
    {
        [$branch, $user, $order] = $this->makeOrderForUser();

        // Token sans ability `kiosk:order` (ex: app mobile avec autres abilities)
        Sanctum::actingAs($user, ['mobile:read', 'profile:edit']);

        $response = $this->postJson("/api/frontend/order/{$order->id}/rating", [
            'rating' => 5,
            'source' => 'web',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('order_ratings', 0);
    }

    /**
     * [SEC-HEAL-2026-05-08 iter2] Ultra-review HEAL data integrity finding —
     * exploit confirmé : un kiosk valide pouvait POSTer 2× sur la même
     * commande physique en swappant le `order_type` (Order vs FrontendOrder
     * partagent la table `orders`), créant 2 rows pour la même commande
     * et polluant les agrégats CSAT/NPS.
     *
     * Fix : `order_type` n'est plus accepté en body, et l'index unique est
     * désormais sur `order_id` seul (1 rating par commande, point).
     *
     * Ce test démontre que l'exploit est définitivement clos.
     */
    public function test_double_rating_via_order_type_swap_blocked(): void
    {
        [$branch, $user, $order] = $this->makeOrderForUser();

        // Tentative 1 : ancien exploit, body order_type=Order.
        $r1 = $this->actAsKiosk($user)
            ->postJson("/api/frontend/order/{$order->id}/rating", [
                'rating'     => 3,
                'order_type' => 'Order', // user tente d'injecter — controller doit ignorer
                'source'     => 'kiosk',
            ]);
        $r1->assertStatus(201);

        // Tentative 2 : ancien exploit, body order_type=FrontendOrder.
        $r2 = $this->actAsKiosk($user)
            ->postJson("/api/frontend/order/{$order->id}/rating", [
                'rating'     => 5,
                'order_type' => 'FrontendOrder',
                'source'     => 'kiosk',
            ]);
        $r2->assertStatus(201);

        // Avant fix : 2 rows. Après fix : 1 seule row (la 2e a updaté la 1re).
        $this->assertDatabaseCount('order_ratings', 1);
        $this->assertDatabaseHas('order_ratings', [
            'order_id'   => $order->id,
            'order_type' => 'FrontendOrder', // forced server-side
            'rating'     => 5,                // last write wins via updateOrCreate
        ]);
    }

    /**
     * [SEC-HEAL-2026-05-08 iter2] Concurrent double-POST race-loser idempotency.
     *
     * Scenario simulé : 2 threads font POST `/rating` simultanément. L'updateOrCreate
     * fait SELECT-then-INSERT ; si entre les 2 ops un autre thread insère, le
     * thread perdant lève `QueryException` (1062 MySQL / "UNIQUE constraint
     * failed" SQLite). Le controller doit catcher et re-fetch le rating gagnant
     * pour préserver le contrat idempotent (201 + data).
     *
     * Implémentation test : on hook `creating` event, qui simule l'autre thread
     * qui aurait inséré entre `firstOrNew` (qui retourne un new model) et `save`.
     * On insère via `DB::table()` (bypass events) puis on throw QueryException
     * pour simuler le 1062 venant de la base.
     *
     * Verify : le controller catch QueryException + re-fetch + 201.
     */
    public function test_concurrent_double_post_idempotent(): void
    {
        [$branch, $user, $order] = $this->makeOrderForUser();

        $thrown = false;
        OrderRating::creating(function ($model) use ($order, $branch, &$thrown) {
            if ($thrown) {
                return;
            }
            $thrown = true;

            // Simule "l'autre thread" qui a inséré entre firstOrNew et save :
            // on insère un row directement (bypass model events).
            DB::table('order_ratings')->insert([
                'order_id'       => $order->id,
                'order_type'     => 'FrontendOrder',
                'branch_id'      => $branch->id,
                'rating'         => 4, // valeur du "winner"
                'comment'        => 'winner-thread',
                'source_surface' => 'kiosk',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            // Maintenant on lève la QueryException que MySQL/SQLite aurait
            // levée si on avait laissé save() faire l'INSERT lui-même.
            $previous = new \PDOException('UNIQUE constraint failed: order_ratings.order_id', 1062);
            $previous->errorInfo = ['23000', 1062, 'Duplicate entry for key order_rating_unique'];

            throw new QueryException(
                'insert into order_ratings (...) values (...)',
                [],
                $previous
            );
        });

        $response = $this->actAsKiosk($user)
            ->postJson("/api/frontend/order/{$order->id}/rating", [
                'rating' => 2, // valeur du loser — sera ignorée car winner a gagné la race
                'source' => 'kiosk',
            ]);

        // Cleanup : retire le listener pour ne pas leaker sur les autres tests.
        OrderRating::flushEventListeners();

        // Le controller doit catcher la QueryException, re-fetch le winner,
        // et renvoyer 201 idempotent.
        $response->assertStatus(201);
        $response->assertJsonPath('status', true);
        $response->assertJsonPath('data.rating', 4); // winner's value, not loser's 2
        $response->assertJsonPath('data.comment', 'winner-thread');

        // Une seule row en DB (le winner).
        $this->assertDatabaseCount('order_ratings', 1);
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

        $response = $this->actAsKiosk($userY)
            ->postJson("/api/frontend/order/{$orderX->id}/rating", [
                'rating' => 5,
                'source' => 'kiosk',
            ]);

        // BranchScope masque l'order → controller retourne 404.
        $response->assertStatus(404);
        $this->assertDatabaseCount('order_ratings', 0);
    }
}
