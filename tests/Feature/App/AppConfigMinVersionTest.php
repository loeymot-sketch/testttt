<?php

namespace Tests\Feature\App;

use App\Enums\Status;
use App\Support\AppVersion;
use Database\Factories\BranchFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [STORES T-3.3.2 · 2026-10-01] Version minimale de l'application des stores.
 *
 * L'application Apple/Google embarque le site dans son paquet : une fois installée, elle
 * ne change plus tant que le client ne la met pas à jour, alors que le serveur, lui, évolue.
 * Sans mécanisme, un vieux paquet qui ne sait plus parler au serveur passe des commandes
 * fausses ou échoue sans un mot. Ce test fixe le contrat :
 *   · le serveur publie la version minimale qu'il accepte (`GET /api/frontend/app/config`) ;
 *   · une commande venue d'une application PLUS ANCIENNE est refusée avec un code que le
 *     site sait afficher (`APP_UPDATE_REQUIRED`), et aucune commande n'est créée ;
 *   · le site web et la borne, qui n'envoient pas de version, ne sont jamais concernés ;
 *   · une version illisible (en-tête ou réglage) ne bloque personne : une faute de frappe
 *     dans `.env` ne doit pas fermer la boutique à tous les clients de l'application.
 */
class AppConfigMinVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => '123456']);
    }

    private function client()
    {
        $branch = BranchFactory::new()->create();

        return UserFactory::new()->create(['branch_id' => $branch->id, 'status' => Status::ACTIVE]);
    }

    private function commander(?string $version)
    {
        $requete = $this->actingAs($this->client())->withHeader('x-api-key', '123456');
        if ($version !== null) {
            $requete = $requete->withHeader('X-LC-App-Version', $version);
        }

        return $requete->postJson('/api/frontend/order', []);
    }

    // ------------------------------------------------------------ configuration publiée

    public function test_la_configuration_publie_la_version_minimale_par_defaut(): void
    {
        $this->withHeader('x-api-key', '123456')->getJson('/api/frontend/app/config')
            ->assertOk()
            ->assertJson([
                'min_version' => '1.0.0',
                'android_url' => 'https://play.google.com/store/apps/details?id=fr.lecayenne.app',
                'ios_url'     => '',
            ])
            ->assertJsonStructure(['min_version', 'message', 'ios_url', 'android_url']);
    }

    public function test_la_configuration_suit_le_reglage_du_serveur(): void
    {
        config(['app_mobile.min_version' => '1.2.0', 'app_mobile.ios_url' => 'https://apps.apple.com/app/id123']);

        $this->withHeader('x-api-key', '123456')->getJson('/api/frontend/app/config')
            ->assertOk()
            ->assertJson(['min_version' => '1.2.0', 'ios_url' => 'https://apps.apple.com/app/id123']);
    }

    public function test_un_reglage_illisible_ne_bloque_personne(): void
    {
        config(['app_mobile.min_version' => 'un-point-deux']);

        $this->withHeader('x-api-key', '123456')->getJson('/api/frontend/app/config')
            ->assertOk()->assertJson(['min_version' => '0.0.0']);
        $this->assertNotSame('APP_UPDATE_REQUIRED', $this->commander('0.0.1')->json('code'));
    }

    // ------------------------------------------------------------ refus côté serveur

    public function test_une_application_trop_ancienne_ne_peut_pas_commander(): void
    {
        config(['app_mobile.min_version' => '1.1.0']);

        $reponse = $this->commander('1.0.9');

        $reponse->assertStatus(422)->assertJson([
            'status'      => false,
            'code'        => 'APP_UPDATE_REQUIRED',
            'min_version' => '1.1.0',
        ]);
        $this->assertNotEmpty($reponse->json('message'), 'Le client doit lire pourquoi, pas un code.');
        $this->assertSame(0, \App\Models\Order::withoutGlobalScopes()->count(), 'Aucune commande ne doit naître.');
    }

    public function test_une_application_a_jour_passe_le_filtre(): void
    {
        config(['app_mobile.min_version' => '1.1.0']);

        // La requête vide échoue ensuite sur la validation du corps : c'est le CHANGEMENT de
        // motif qui prouve que le filtre a laissé passer (et pas qu'il n'existe pas).
        $this->assertNotSame('APP_UPDATE_REQUIRED', $this->commander('1.1.0')->json('code'));
        $this->assertNotSame('APP_UPDATE_REQUIRED', $this->commander('2.0')->json('code'));
    }

    public function test_les_versions_se_comparent_en_nombres_pas_en_texte(): void
    {
        config(['app_mobile.min_version' => '1.10.0']);
        $this->assertSame('APP_UPDATE_REQUIRED', $this->commander('1.9.0')->json('code'));

        config(['app_mobile.min_version' => '1.9.0']);
        $this->assertNotSame('APP_UPDATE_REQUIRED', $this->commander('1.10.0')->json('code'));
    }

    public function test_le_site_et_la_borne_sans_version_ne_sont_jamais_concernes(): void
    {
        config(['app_mobile.min_version' => '9.0.0']);

        $this->assertNotSame('APP_UPDATE_REQUIRED', $this->commander(null)->json('code'));
        $this->assertNotSame('APP_UPDATE_REQUIRED', $this->commander('n/a')->json('code'));
    }

    // ------------------------------------------------------------ comparaison

    public function test_comparaison_des_versions(): void
    {
        $this->assertTrue(AppVersion::estAvant('1.0.0', '1.0.1'));
        $this->assertTrue(AppVersion::estAvant('1.9', '1.10'));
        $this->assertFalse(AppVersion::estAvant('1.0', '1.0.0'), '1.0 et 1.0.0 sont la même version.');
        $this->assertFalse(AppVersion::estAvant('2.0.0', '1.99.99'));
        $this->assertFalse(AppVersion::estAvant('', '1.0.0'), 'Version absente : on ne bloque pas.');
        $this->assertFalse(AppVersion::estAvant('1.0.0-beta', '2.0.0'), 'Version illisible : on ne bloque pas.');
        $this->assertFalse(AppVersion::estAvant('1.0.0', 'abc'), 'Minimum illisible : on ne bloque pas.');
    }
}
