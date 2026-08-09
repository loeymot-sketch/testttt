<?php

namespace Tests\Feature\Pilotage;

use App\Models\Branch;
use App\Models\User;
use App\Services\Pilotage\InterrupteurService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [PILOTAGE 2026-08-09] Les bascules actionnables sans déploiement.
 *
 * Audit, point 4 : `split_payment.enabled` et `wheel.enabled` vivaient dans des
 * fichiers de configuration. Les changer exigeait une mise en ligne — alors que
 * ce sont les leviers qu'on veut actionner en quelques minutes.
 *
 * Le test le plus important de ce fichier est le DERNIER : il vérifie que
 * l'idempotence, elle, n'est PAS devenue basculable. C'est une protection NF525
 * sous garde de démarrage, pas une option de confort.
 */
class InterrupteurTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        return $u;
    }

    public function test_l_etat_liste_les_bascules_avec_de_quoi_decider(): void
    {
        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/interrupteurs')->assertOk()->json('data');

        $this->assertNotEmpty($r);
        foreach ($r as $i) {
            // Un interrupteur sans explication de ce qu'il coupe est un piège :
            // on ne bascule pas ce qu'on ne comprend pas.
            $this->assertNotEmpty($i['libelle']);
            $this->assertNotEmpty($i['consequence']);
            $this->assertIsBool($i['actif']);
        }
    }

    public function test_basculer_change_la_valeur_lue_par_le_code_metier(): void
    {
        // Le point entier de ce chantier : le code existant appelle
        // `config('wheel.enabled')` à trois endroits. La bascule doit se voir LÀ,
        // sans qu'aucun de ces appels n'ait été modifié.
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/admin/observability/interrupteurs/wheel', ['actif' => true])
            ->assertOk();

        $this->assertTrue((bool) Config::get('wheel.enabled'));

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/admin/observability/interrupteurs/wheel', ['actif' => false])
            ->assertOk();

        $this->assertFalse((bool) Config::get('wheel.enabled'));
    }

    public function test_la_valeur_reglee_survit_a_un_redemarrage(): void
    {
        $service = app(InterrupteurService::class);
        $service->regler('wheel', true);

        // On simule un nouveau démarrage : la configuration repart du fichier…
        Config::set('wheel.enabled', false);
        $service->appliquerAuDemarrage();

        // …et le réglage enregistré la reprend.
        $this->assertTrue((bool) Config::get('wheel.enabled'));
    }

    public function test_une_bascule_inconnue_est_refusee(): void
    {
        // Liste BLANCHE : sans ça, l'API deviendrait un `Config::set` ouvert sur
        // n'importe quelle clé de configuration de l'application.
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/admin/observability/interrupteurs/app.debug', ['actif' => true])
            ->assertNotFound();
    }

    public function test_un_editeur_de_branche_ne_bascule_rien(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        $this->actingAs($u, 'sanctum')
            ->putJson('/api/admin/observability/interrupteurs/wheel', ['actif' => true])
            ->assertForbidden();
    }

    public function test_l_idempotence_n_est_PAS_devenue_basculable(): void
    {
        // Le garde-fou du chantier. `idempotency.enabled` empêche l'encaissement
        // en double et AppServiceProvider refuse de démarrer en production sans
        // elle (CLAUDE.md §8). L'exposer dans un écran reviendrait à poser un
        // bouton « désactiver la protection fiscale » à portée de clic.
        $this->assertArrayNotHasKey('idempotency', InterrupteurService::CATALOGUE);

        foreach (InterrupteurService::CATALOGUE as $def) {
            $this->assertStringNotContainsString('idempotency', $def['cle']);
        }

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/admin/observability/interrupteurs/idempotency', ['actif' => false])
            ->assertNotFound();
    }
}
