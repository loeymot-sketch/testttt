<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * L'ÉCRAN DE VALIDATION AU COMPTOIR — le maillon humain, et donc le maillon à protéger.
 *
 * Valider un tour, c'est DONNER un lot. Ce que cette suite verrouille :
 *   1. l'écran est inaccessible sans être connecté, et sans le droit caisse ;
 *   2. il n'est PAS avalé par l'attrape-tout de la SPA (sinon la tablette affiche l'application
 *      au lieu de l'écran de validation, et personne ne peut valider quoi que ce soit) ;
 *   3. le QR mène bien à la page de la roue avec un jeton ;
 *   4. sans adresse publique configurée, on affiche une ERREUR — jamais un QR qui mène nulle part.
 */
class WheelCounterScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $caissier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $branch = Branch::factory()->create();
        $this->caissier = User::factory()->create(['branch_id' => $branch->id]);
        $this->caissier->givePermissionTo('pos');

        Config::set('wheel.public_url', 'https://www.lecayenne.fr');
        Config::set('wheel.preview_key', '');
    }

    public function test_l_ecran_est_ferme_sans_connexion_et_sans_droit_caisse(): void
    {
        // 401 et non une redirection : c'est le comportement d'authentification de ce projet, et il
        // convient pour une tablette (pas de page de connexion à traverser, un refus net). On
        // accepte les deux formes pour ne pas figer un détail de configuration dans ce test.
        $anonyme = $this->get('/admin/roue-validation');
        $this->assertContains($anonyme->status(), [401, 302],
            'l\'écran de validation est accessible sans connexion : n\'importe qui donnerait des lots');

        $quidam = User::factory()->create(['branch_id' => 1]);
        $this->actingAs($quidam)->get('/admin/roue-validation')->assertStatus(403);
        $this->actingAs($quidam)->post('/admin/roue-validation')->assertStatus(403);
    }

    /**
     * L'attrape-tout `/{any}` sert la SPA. Si nos routes passaient APRÈS, la tablette afficherait
     * l'application et personne ne pourrait valider un tour. On vérifie donc qu'on obtient bien
     * NOTRE écran, identifié par son texte, et pas la coquille de l'application.
     */
    public function test_l_ecran_n_est_pas_avale_par_l_attrape_tout_de_la_SPA(): void
    {
        $r = $this->actingAs($this->caissier)->get('/admin/roue-validation')->assertOk();

        $r->assertSee('Valider un tour de roue', false);
        $r->assertSee('avis Google', false);
    }

    public function test_la_validation_produit_un_QR_vers_la_page_de_la_roue_avec_un_jeton(): void
    {
        $r = $this->actingAs($this->caissier)->post('/admin/roue-validation')->assertOk();

        $html = $r->getContent();
        $this->assertStringContainsString('<svg', $html, 'aucun QR généré : rien à scanner');

        // [P1 2026-08-09] L'adresse n'est PLUS affichée en clair (elle était photographiable par
        // toute la file). On vérifie donc la donnée passée à la vue — c'est elle qui construit le
        // QR — plutôt que le texte rendu. Assertion tout aussi forte, sans exiger la fuite.
        $r->assertViewHas('url', fn ($u) => is_string($u) && str_contains($u, '/roue.html?t='));
        $this->assertStringNotContainsString('roue.html?t=', $html,
            'le jeton est de nouveau affiché en clair : n\'importe qui dans la file le photographie '
            . 'et consomme la validation avec SON numéro');
        $this->assertMatchesRegularExpression('/Valable\s+\d+\s+minutes/', $html,
            'la durée de validité doit être dite : un jeton sans échéance visible traîne');
    }

    /** Un QR qui mène nulle part est pire que pas de QR : le client scanne et tombe dans le vide. */
    public function test_sans_adresse_publique_on_affiche_une_ERREUR_et_aucun_QR(): void
    {
        Config::set('wheel.public_url', '');

        $r = $this->actingAs($this->caissier)->post('/admin/roue-validation')->assertOk();

        $html = $r->getContent();
        $this->assertStringNotContainsString('<svg', $html, 'un QR a été affiché sans destination');
        $this->assertStringContainsString('WHEEL_PUBLIC_URL', $html, 'la cause doit être dite à l\'exploitant');
    }

    /** Porte fermée : le QR embarque la clé de prévisualisation, sinon la démonstration tombe à plat. */
    public function test_porte_fermee_le_QR_embarque_la_cle_de_previsualisation(): void
    {
        Config::set('wheel.enabled', false);
        Config::set('wheel.preview_key', 'cle-de-test-du-patron');

        $this->actingAs($this->caissier)->post('/admin/roue-validation')->assertOk()
            ->assertViewHas('url', fn ($u) => str_contains((string) $u, 'preview=cle-de-test-du-patron'));
    }

    public function test_porte_OUVERTE_le_QR_ne_contient_PAS_la_cle(): void
    {
        Config::set('wheel.enabled', true);
        Config::set('wheel.preview_key', 'cle-de-test-du-patron');

        $r = $this->actingAs($this->caissier)->post('/admin/roue-validation')->assertOk();

        $r->assertViewHas('url', fn ($u) => ! str_contains((string) $u, 'cle-de-test-du-patron'));
        $this->assertStringNotContainsString('cle-de-test-du-patron', $r->getContent(),
            'la clé de prévisualisation fuite dans un QR public : elle finirait partagée');
    }
}
