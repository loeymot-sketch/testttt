<?php

namespace Tests\Feature\Wheel;

use App\Http\Middleware\EnsureWheelAccess;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LA PASSE SIGNÉE — entrer dans les écrans de la roue depuis une caisse DÉJÀ connectée.
 *
 * ── LE BESOIN ────────────────────────────────────────────────────────────────────────────────
 * [2026-08-13 · propriétaire : « ce n'est pas le bouton, c'est le code PIN »] Le caissier retapait
 * le code de la maison plusieurs fois par service alors qu'il venait de se connecter. La cause est
 * structurelle et documentée dans {@see \App\Http\Middleware\EnsureWheelAccess} : la session
 * applicative vit dans un jeton Bearer, et une navigation de DOCUMENT ne porte jamais d'en-tête
 * `Authorization`.
 *
 * ── CE QUE CE BANC PROTÈGE ───────────────────────────────────────────────────────────────────
 * Une passe est un laissez-passer vers des écrans qui DONNENT des lots. Quatre propriétés, et il
 * les faut toutes les quatre :
 *   1. elle n'est délivrée qu'à un compte réellement habilité `pos` ;
 *   2. elle ouvre effectivement la porte (sinon on a ajouté un chemin qui ne mène nulle part) ;
 *   3. elle ne sert QU'UNE FOIS — une adresse recopiée dans une conversation ne resservira pas ;
 *   4. elle ne peut pas devenir une redirection ouverte signée par nos soins.
 */
class WheelScreenPassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        Branch::factory()->create();
        Config::set('wheel.access.pin', '481526');
        Config::set('wheel.access.session_minutes', 240);
        Config::set('wheel.counter_branch_id', 1);
        Config::set('wheel.public_url', 'https://exemple.test');
    }

    /**
     * Le banc de base pose `Accept: application/json`. Ce qu'on éprouve ici est le chemin du
     * NAVIGATEUR — celui qui doit voir une page, pas un JSON. Sans cet en-tête on testerait
     * l'autre branche en croyant avoir prouvé la bonne.
     */
    private function navigateur()
    {
        return $this->withHeaders(['Accept' => 'text/html,application/xhtml+xml']);
    }

    private function caissier(): User
    {
        $u = User::factory()->create(['branch_id' => 1]);
        $u->givePermissionTo('pos');

        return $u;
    }

    private function demanderPasse(User $u, string $ecran = 'accueil'): string
    {
        $r = $this->actingAs($u, 'sanctum')
            ->postJson('/api/admin/wheel/screen-pass', ['ecran' => $ecran])
            ->assertOk();

        return (string) $r->json('url');
    }

    /** Le chemin nominal, de bout en bout : la passe ouvre vraiment la porte. */
    public function test_une_passe_ouvre_les_ecrans_sans_le_code(): void
    {
        $url = $this->demanderPasse($this->caissier());
        $this->assertNotSame('', $url, 'aucune adresse rendue');

        // On suit la passe comme le ferait l'onglet ouvert par la caisse.
        $this->navigateur()->get($url)->assertRedirect(route('admin.wheel.home'));

        // LA PREUVE QUI COMPTE : la session est réellement ouverte. Sans cette assertion, une
        // redirection vers l'accueil aurait tout aussi bien pu être un REFUS — les deux se
        // ressemblent, et c'est exactement le genre de test qui ne peut pas échouer.
        $this->assertNotNull(session(EnsureWheelAccess::SESSION_KEY), 'la porte est restée fermée');
        $this->navigateur()->get('/admin/roue-reglages')->assertOk();
    }

    /** Un compte sans droit caisse n'obtient RIEN — la passe n'est pas un contournement. */
    public function test_un_compte_sans_droit_caisse_n_obtient_pas_de_passe(): void
    {
        $quidam = User::factory()->create(['branch_id' => 1]);

        $this->actingAs($quidam, 'sanctum')
            ->postJson('/api/admin/wheel/screen-pass')
            ->assertStatus(403);
    }

    /** Sans compte du tout, la route n'existe pas pour l'appelant. */
    public function test_sans_compte_la_passe_est_refusee(): void
    {
        $this->postJson('/api/admin/wheel/screen-pass')->assertStatus(401);
    }

    /**
     * L'USAGE UNIQUE — la propriété qui rend une adresse recopiée sans valeur.
     *
     * Le second passage doit non seulement échouer, mais échouer SANS ouvrir la session : c'est la
     * seconde assertion qui le prouve. Vérifier la seule redirection laisserait passer une porte
     * qui s'ouvre quand même.
     */
    public function test_une_passe_ne_sert_qu_une_seule_fois(): void
    {
        $url = $this->demanderPasse($this->caissier());

        $this->navigateur()->get($url)->assertRedirect(route('admin.wheel.home'));
        $this->flushSession();

        $this->navigateur()->get($url)->assertRedirect(route('admin.wheel.home'));
        $this->assertNull(session(EnsureWheelAccess::SESSION_KEY),
            'la même adresse a ouvert la porte une seconde fois');
    }

    /** Une signature bricolée ne vaut rien : c'est tout l'intérêt de porter la preuve dans l'adresse. */
    public function test_une_adresse_trafiquee_est_refusee(): void
    {
        $url = $this->demanderPasse($this->caissier());
        $truquee = preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $url);

        $this->navigateur()->get($truquee)->assertStatus(403);
        $this->assertNull(session(EnsureWheelAccess::SESSION_KEY));
    }

    /**
     * PAS DE REDIRECTION OUVERTE. L'écran demandé est choisi dans une liste FERMÉE : sans cette
     * garde, cette route deviendrait le rêve de n'importe quel hameçonnage — une adresse qui mène
     * ailleurs, signée par nous.
     */
    public function test_un_ecran_inconnu_retombe_sur_l_accueil_et_ne_redirige_pas_ailleurs(): void
    {
        $url = $this->demanderPasse($this->caissier(), 'reglages');
        $this->navigateur()->get($url)->assertRedirect(route('admin.wheel.settings'));

        $this->flushSession();

        // Une valeur hors liste ne doit produire aucune redirection externe.
        $r = $this->actingAs($this->caissier(), 'sanctum')
            ->postJson('/api/admin/wheel/screen-pass', ['ecran' => 'https://exemple-malveillant.test'])
            ->assertOk();

        $this->assertStringNotContainsString('exemple-malveillant', (string) $r->json('url'),
            'un écran hors liste est repris tel quel dans la passe');
        $this->navigateur()->get((string) $r->json('url'))
            ->assertRedirect(route('admin.wheel.home'));
    }
}
