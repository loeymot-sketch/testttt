<?php

namespace Tests\Feature\Admin;

use App\Http\Requests\AnalyticRequest;
use App\Http\Requests\AnalyticSectionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — T-5.3.4]
 *
 * `AnalyticRequest` et `AnalyticSectionRequest` répondaient `return true;` à `authorize()`.
 * Ce n'était pas une faille immédiate — les routes `analytic*` sont derrière la permission
 * `settings` — mais l'autorisation reposait ENTIÈREMENT sur le middleware de route. Déplacer
 * une route hors du groupe protégé, un jour, l'aurait laissée sans aucune garde, sans qu'aucun
 * test ne s'en aperçoive.
 *
 * Ces deux requêtes vérifient désormais la permission elles-mêmes. Ce test le prouve au niveau
 * de la requête, pas de la route : c'est justement la couche que le middleware ne couvre pas.
 *
 * Cliquet `FormRequestAuthzDriftSentinelTest::RETURN_TRUE_BASELINE` : 64 → 62.
 *
 * @group sentinel
 * @group authz
 */
class AnalyticRequestAuthzTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        Permission::findOrCreate('settings', 'sanctum');
    }

    /** @return array<int, class-string> */
    private function requetesConcernees(): array
    {
        return [AnalyticRequest::class, AnalyticSectionRequest::class];
    }

    public function test_un_utilisateur_sans_permission_settings_est_refuse(): void
    {
        $sansDroit = User::factory()->create(['branch_id' => 1]);
        $this->actingAs($sansDroit, 'sanctum');

        foreach ($this->requetesConcernees() as $classe) {
            $requete = new $classe();
            $requete->setUserResolver(fn () => $sansDroit);

            $this->assertFalse(
                $requete->authorize(),
                $classe.' doit refuser un utilisateur sans la permission `settings`.',
            );
        }
    }

    public function test_un_porteur_de_settings_est_autorise(): void
    {
        $avecDroit = User::factory()->create(['branch_id' => 0]);
        $avecDroit->givePermissionTo('settings');
        $this->actingAs($avecDroit, 'sanctum');

        foreach ($this->requetesConcernees() as $classe) {
            $requete = new $classe();
            $requete->setUserResolver(fn () => $avecDroit);

            $this->assertTrue(
                $requete->authorize(),
                $classe.' doit autoriser un porteur de `settings`.',
            );
        }
    }

    public function test_une_requete_sans_utilisateur_est_refusee(): void
    {
        // `$this->user()?->can()` renvoie null sans utilisateur : le cast (bool) doit donner false,
        // jamais une autorisation par accident.
        foreach ($this->requetesConcernees() as $classe) {
            $requete = new $classe();
            $requete->setUserResolver(fn () => null);

            $this->assertFalse($requete->authorize(), $classe.' ne doit pas autoriser un anonyme.');
        }
    }

    public function test_les_deux_requetes_ne_repondent_plus_true_en_dur(): void
    {
        foreach (['AnalyticRequest', 'AnalyticSectionRequest'] as $nom) {
            $source = file_get_contents(base_path("app/Http/Requests/{$nom}.php"));
            $this->assertDoesNotMatchRegularExpression(
                '/public function authorize\(\)\s*:?\s*(bool)?\s*\{\s*return true;\s*\}/',
                $source,
                "{$nom} est retombée sur `return true;` — le cliquet doit être remonté ou le refactor refait.",
            );
        }
    }
}
