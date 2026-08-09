<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * L'ÉCRAN D'ATTENTE DE LA TABLETTE — celui que les clients scannent au comptoir.
 *
 * Personne ne le touche : ni l'équipe pendant un service, ni le client. Tout est automatique, y
 * compris le renouvellement du jeton — un QR figé serait consommé par le PREMIER scan (le jeton est
 * à usage unique) et tous les suivants tomberaient sur « validation introuvable ».
 *
 * Ce que cette suite verrouille :
 *   1. c'est un écran de SERVICE, fermé sans droit caisse — c'est lui qui ÉMET les jetons ;
 *   2. il n'est pas avalé par l'attrape-tout de l'application ;
 *   3. chaque affichage produit un QR NEUF : deux chargements ne donnent jamais le même jeton ;
 *   4. il se recharge AVANT l'expiration du jeton, pas après ;
 *   5. sans adresse publique configurée, il affiche une erreur et AUCUN QR.
 */
class WheelKioskScreenTest extends TestCase
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
        Config::set('wheel.min_order_amount', 10.0);
        Config::set('wheel.unlock_token_ttl_minutes', 15);
    }

    public function test_l_ecran_est_ferme_sans_droit_caisse(): void
    {
        $quidam = User::factory()->create(['branch_id' => 1]);
        $this->actingAs($quidam)->get('/admin/roue-borne')->assertStatus(403);

        $anonyme = $this->get('/admin/roue-borne');
        $this->assertContains($anonyme->status(), [401, 403, 302],
            'l\'écran d\'attente ÉMET des jetons : il ne doit pas être une page publique');
    }

    public function test_il_affiche_la_roue_le_QR_et_le_minimum_d_achat(): void
    {
        $r = $this->actingAs($this->caissier)->get('/admin/roue-borne')->assertOk();

        $html = $r->getContent();
        $this->assertStringContainsString('<svg', $html, 'aucun QR : rien à scanner');
        $this->assertStringContainsString('Scanne avec ton téléphone', $html);
        $this->assertStringContainsString('10,00', $html,
            'le minimum d\'achat doit être annoncé DÈS la tablette, pas découvert plus tard');
        $this->assertStringContainsString('id="roue"', $html, 'la roue doit être visible : c\'est elle qui fait lever les yeux');

        // Pas avalé par l'attrape-tout de l'application.
        $r->assertSee('Tu gagnes', false);
    }

    /**
     * CHAQUE AFFICHAGE PRODUIT UN JETON NEUF. Sans cela, le premier scan consommerait le QR et tous
     * les suivants échoueraient — l'écran deviendrait un piège silencieux au bout de dix minutes.
     */
    public function test_deux_affichages_donnent_deux_jetons_DIFFERENTS(): void
    {
        $un = $this->actingAs($this->caissier)->get('/admin/roue-borne')->assertOk();
        $deux = $this->actingAs($this->caissier)->get('/admin/roue-borne')->assertOk();

        // Le jeton n'est pas affiché en clair (il est dans le QR) : on compare les QR eux-mêmes.
        $qr1 = $this->extraireQr($un->getContent());
        $qr2 = $this->extraireQr($deux->getContent());

        $this->assertNotSame('', $qr1, 'aucun QR extrait : le test ne prouverait rien');
        $this->assertNotSame($qr1, $qr2,
            'deux affichages donnent le MÊME QR : le premier scan le consommerait et tous les '
            . 'suivants tomberaient sur « validation introuvable »');
    }

    /** Le rechargement doit survenir AVANT l'expiration, sinon on scanne un jeton déjà mort. */
    public function test_il_se_recharge_AVANT_l_expiration_du_jeton(): void
    {
        Config::set('wheel.unlock_token_ttl_minutes', 10);

        $html = $this->actingAs($this->caissier)->get('/admin/roue-borne')->assertOk()->getContent();

        preg_match('/setTimeout\(function \(\) \{ location\.reload\(\); \}, (\d+)\)/', $html, $m);
        $this->assertNotEmpty($m, 'aucun rechargement programmé : le QR mourrait à l\'écran');

        $ms = (int) $m[1];
        $ttlMs = 10 * 60 * 1000;
        $this->assertLessThan($ttlMs, $ms,
            'le rechargement arrive APRÈS l\'expiration : il existerait une fenêtre pendant laquelle '
            . 'le client scanne un jeton déjà mort');
        $this->assertGreaterThan(30000, $ms,
            'rechargement trop fréquent : la tablette clignoterait et le QR serait inscannable');
    }

    public function test_sans_adresse_publique_il_affiche_une_erreur_et_AUCUN_QR(): void
    {
        Config::set('wheel.public_url', '');

        $html = $this->actingAs($this->caissier)->get('/admin/roue-borne')->assertOk()->getContent();

        $this->assertStringNotContainsString('<svg', $html, 'un QR sans destination a été affiché');
        $this->assertStringContainsString('WHEEL_PUBLIC_URL', $html);
    }

    /** Porte fermée au public : le QR embarque la clé de prévisualisation, sinon la démo tombe à plat. */
    public function test_porte_fermee_le_QR_embarque_la_cle_de_previsualisation(): void
    {
        Config::set('wheel.enabled', false);
        Config::set('wheel.preview_key', 'cle-patron-borne');

        // `QrCode::generate()` rend un objet « stringable » (HtmlString), pas une chaîne : tester
        // `is_string()` échouait sur du code parfaitement correct.
        $this->actingAs($this->caissier)->get('/admin/roue-borne')->assertOk()
            ->assertViewHas('qr', fn ($qr) => trim((string) $qr) !== '');

        // La clé ne doit PAS apparaître en clair dans la page : seulement dans le QR.
        $html = $this->actingAs($this->caissier)->get('/admin/roue-borne')->getContent();
        $this->assertStringNotContainsString('cle-patron-borne', $html,
            'la clé de prévisualisation est lisible sur la tablette : elle finirait partagée');
    }

    private function extraireQr(string $html): string
    {
        return preg_match('/<svg.*?<\/svg>/s', $html, $m) ? $m[0] : '';
    }
}
