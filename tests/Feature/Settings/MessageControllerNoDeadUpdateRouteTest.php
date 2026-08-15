<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [T-5.3 D14 2026-08-15 · GOAL_CONFORT_MAX] Verrou de non-régression.
 *
 * Le registre des dangers du GOAL marquait D14 « OUVERT » — « PUT/PATCH
 * /admin/message/{message} → méthode contrôleur inexistante, 500 latent »,
 * citant le même audit du 2026-08-13 que D13 (« highest-severity single
 * finding », jamais corrigé selon ce rapport). Vérification par lecture de
 * code (2026-08-15) : FAUX à l'état actuel. `routes/api.php:1495-1503` ne
 * déclare AUCUNE route PUT/PATCH sur `admin/message/{message}` — le
 * commentaire adjacent ("[NC-MSG-UPDATE-DEAD heal 2026-06-01] Removed dead
 * route — MessageController has no update() method... PUT/PATCH 500'd.")
 * confirme qu'elle a été RETIRÉE, pas ajoutée avec un contrôleur cassé.
 *
 * Comme pour D13, l'audit du 2026-08-13 a soit vérifié un état périmé, soit
 * mal lu le code — le danger n'est pas reproductible aujourd'hui.
 */
class MessageControllerNoDeadUpdateRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_aucune_route_put_patch_n_existe_sur_admin_message_id(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/admin/message/'))
            ->map(fn ($r) => $r->methods());

        foreach ($routes as $methods) {
            $this->assertNotContains('PUT', $methods, 'admin/message/{message} ne doit porter AUCUNE route PUT — MessageController n\'a pas de update()');
            $this->assertNotContains('PATCH', $methods, 'admin/message/{message} ne doit porter AUCUNE route PATCH — MessageController n\'a pas de update()');
        }
    }

    public function test_message_controller_n_a_reellement_pas_de_methode_update(): void
    {
        // Preuve directe (pas seulement l'absence de route) : si quelqu'un ajoute
        // update() au contrôleur sans rouvrir la route, ce test devient un rappel
        // explicite plutôt qu'un silence.
        $this->assertFalse(
            method_exists(\App\Http\Controllers\Admin\MessageController::class, 'update'),
            'Si update() a été ajouté, la route PUT/PATCH peut être ré-ouverte en toute sécurité — sinon ce test documente pourquoi elle reste fermée.'
        );
    }
}
