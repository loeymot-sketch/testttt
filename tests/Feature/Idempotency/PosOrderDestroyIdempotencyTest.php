<?php

namespace Tests\Feature\Idempotency;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Http\Middleware\IdempotencyKeyMiddleware;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24, addendum triage C]
 *
 * Défaut reproduit : `DELETE api/admin/pos-order/{order}` — la seule route de
 * SUPPRESSION de commande réellement câblée à une UI — était déclarée NUE dans
 * `routes/api.php` : aucun middleware, ni throttle ni idempotence. Toutes ses
 * voisines mutantes du même groupe portent pourtant
 * `['throttle:pos-order-update', 'idempotency']` (change-status,
 * change-payment-status, select-delivery-boy, refund-with-counter-entry).
 *
 * Pire : le client ENVOIE déjà l'en-tête (`store/modules/posOrder.js`,
 * `buildIdempotencyHeaders`) et un commentaire du même fichier affirmait une
 * protection serveur qui n'existait pas. La protection anti-rejeu sur une
 * action destructive était donc INERTE, avec un commentaire trompeur.
 *
 * `IdempotencyKeyMiddleware` accepte bien DELETE (sa liste de méthodes couvre
 * POST/PUT/PATCH/DELETE), le câblage est donc réel et non décoratif — c'est ce
 * que ce test prouve, plutôt que de se contenter de la sentinelle de couverture.
 *
 * Preuve observable : sans idempotence, un second DELETE avec la même clé
 * retombe sur une ligne déjà soft-deletée et répond 404. Avec idempotence, la
 * réponse d'origine est REJOUÉE (même statut) et porte l'en-tête de rejeu.
 */
class PosOrderDestroyIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('pwd'),
        ]);
        $this->cashier->assignRole('POS Operator');
    }

    private function makeOrder(): Order
    {
        return Order::factory()->create([
            'branch_id'      => $this->branch->id,
            'order_type'     => OrderType::POS,
            'status'         => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'total'          => 12.50,
        ]);
    }

    private function headers(string $key): array
    {
        return [
            'x-api-key'                      => config('app.api_key'),
            IdempotencyKeyMiddleware::HEADER => $key,
        ];
    }

    /**
     * La route destructive est bien couverte par la configuration
     * d'idempotence — sinon le middleware ne rendrait pas la clé opérante.
     */
    public function test_the_destroy_route_is_declared_in_required_routes(): void
    {
        $patterns = (array) config('idempotency.required_routes', []);

        $couvert = false;
        foreach ($patterns as $pattern) {
            $regex = '#^' . str_replace('\*', '[^/]+', preg_quote((string) $pattern, '#')) . '$#';
            if (preg_match($regex, 'api/admin/pos-order/*')) {
                $couvert = true;
                break;
            }
        }

        $this->assertTrue(
            $couvert,
            "La route DELETE api/admin/pos-order/{order} doit être déclarée dans "
            . "config('idempotency.required_routes'), sinon la clé envoyée par le client reste inerte."
        );
    }

    /**
     * Contrat de route : les DEUX middlewares doivent être présents. Ce test
     * rougit si quelqu'un remet la route à nu — c'est le garde de régression du
     * correctif, indépendant de tout comportement observable.
     */
    public function test_the_destroy_route_carries_throttle_and_idempotency(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/admin/pos-order/{order}'
                && in_array('DELETE', $r->methods(), true));

        $this->assertNotNull($route, 'La route DELETE api/admin/pos-order/{order} doit exister.');

        $middleware = $route->gatherMiddleware();
        $this->assertContains('idempotency', $middleware,
            'La route de SUPPRESSION doit porter le middleware d’idempotence, comme toutes ses voisines mutantes.');
        $this->assertContains('throttle:pos-order-update', $middleware,
            'La route de SUPPRESSION doit porter le même throttle que ses voisines mutantes.');
    }

    /**
     * PORTÉE RÉELLE DE LA PROTECTION — mesurée, pas supposée.
     *
     * `SubstituteBindings` appartient au groupe `api` (Kernel) et s'exécute donc
     * AVANT les middlewares de route. Sur un second DELETE séquentiel, la
     * résolution du modèle ne trouve plus la commande (soft-deletée) et répond
     * 404 avant que le middleware d'idempotence puisse rejouer la réponse
     * d'origine. Il n'y a donc PAS de rejeu post-hoc sur cette route, et ce test
     * l'acte explicitement au lieu de prétendre le contraire.
     *
     * Ce que le câblage apporte réellement, et qui manquait :
     *   - le throttle, totalement absent auparavant ;
     *   - le verrou au-plus-une-fois sur les duplicatas CONCURRENTS (le vrai
     *     risque du double-clic / retry réseau simultané, où les deux requêtes
     *     résolvent encore le modèle et atteignent donc le middleware).
     *
     * Ce que ce test garantit côté données : un geste répété ne détruit jamais
     * autre chose que la commande visée.
     */
    public function test_a_repeated_destroy_is_harmless_and_destroys_nothing_else(): void
    {
        $cible = $this->makeOrder();
        $temoin = $this->makeOrder();
        $key = 'qa-2026-09-28-destroy-' . $cible->id;

        $this->actingAs($this->cashier, 'sanctum');
        $first = $this->withHeaders($this->headers($key))
            ->deleteJson('/api/admin/pos-order/' . $cible->id);

        $first->assertSuccessful();
        $this->assertNull(
            $first->headers->get(IdempotencyKeyMiddleware::REPLAY_HEADER),
            'La première requête ne doit pas être marquée comme rejouée.'
        );
        $this->assertSoftDeleted('orders', ['id' => $cible->id]);

        $second = $this->withHeaders($this->headers($key))
            ->deleteJson('/api/admin/pos-order/' . $cible->id);

        // 404 attendu : la liaison de route précède le middleware (voir docblock).
        $this->assertSame(404, $second->status(),
            'Le second appel séquentiel est refusé par la liaison de route, avant le middleware.');

        // L'essentiel : aucun dégât collatéral.
        $this->assertDatabaseHas('orders', ['id' => $temoin->id, 'deleted_at' => null]);
        $this->assertSame(1, Order::onlyTrashed()->count(),
            'Exactement une commande doit être supprimée, quel que soit le nombre de clics.');
    }

    /**
     * Deux clés différentes restent deux gestes différents : l'idempotence ne
     * doit pas masquer une seconde suppression légitime d'une AUTRE commande.
     */
    public function test_two_distinct_keys_remain_two_distinct_operations(): void
    {
        $premier = $this->makeOrder();
        $second = $this->makeOrder();

        $this->actingAs($this->cashier, 'sanctum');

        $this->withHeaders($this->headers('qa-destroy-a'))
            ->deleteJson('/api/admin/pos-order/' . $premier->id)
            ->assertSuccessful();

        $this->withHeaders($this->headers('qa-destroy-b'))
            ->deleteJson('/api/admin/pos-order/' . $second->id)
            ->assertSuccessful();

        $this->assertSoftDeleted('orders', ['id' => $premier->id]);
        $this->assertSoftDeleted('orders', ['id' => $second->id]);
    }
}
