<?php

namespace Tests\Feature\Onboarding;

use App\Models\Branch;
use App\Models\Currency;
use App\Models\User;
use Dipokhalder\EnvEditor\EnvEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [ONB-10 2026-08-27] Le commerçant ne pouvait pas enregistrer l'écran Site.
 *
 * Trouvé à l'écran Paramètres > Site : les champs CLÉ GOOGLE MAPS et COPYRIGHT
 * portent l'astérisque rouge des champs obligatoires, et sont VIDES. Vérification
 * en base : les deux valent NULL dans la table `settings`.
 *
 * Conséquence : l'écran entier est bloqué. Un commerçant qui veut changer son
 * fuseau horaire, son format de date, la position du symbole € ou la longueur des
 * numéros de téléphone se prend un 422 sur une clé d'API Google Maps qu'il n'a pas,
 * et qu'il n'a aucune raison d'avoir — V1 est mono-établissement, en local, avec la
 * livraison désactivée. Sa seule porte de sortie est d'inventer une valeur, qui est
 * alors écrite VERBATIM dans le `.env` (SiteService::update).
 *
 * Ce n'est pas un réglage oublié par le semoir : c'est une exigence qui n'a jamais
 * eu de raison d'être ici. Une clé d'API tierce et une mention de pied de page ne
 * conditionnent pas le fuseau horaire d'une caisse.
 *
 * Les deux passent en facultatif. Le garde-fou anti-injection `.env` reste en place
 * sur la clé Maps — c'est lui qui compte, et le dernier test le vérifie.
 */
class ReglagesDuSiteEnregistrablesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Currency $devise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branche = Branch::factory()->create();
        $this->admin = User::factory()->create(['branch_id' => $branche->id]);
        $this->admin->assignRole('Admin');

        $this->devise = Currency::query()->create([
            'name'          => 'Euro',
            'code'          => 'EUR',
            'symbol'        => '€',
            'is_cryptocurrency' => 0,
            'exchange_rate' => 1,
            'status'        => 5,
        ]);

        // `SiteService::update` écrit dans le VRAI fichier .env. On le remplace par un
        // double : ce test parle de validation, il n'a rien à faire dans le .env du
        // dépôt. Sans ça, lancer la suite modifierait la configuration de la machine.
        $this->mock(EnvEditor::class, function ($double) {
            $double->shouldReceive('addData')->andReturn(true);
            $double->shouldReceive('getValue')->andReturn(null);
        });
    }

    /** Le corps que l'écran envoie, avec les valeurs réelles de l'installation. */
    private function saisieDeLEcran(array $ecrasements = []): array
    {
        return array_merge([
            'site_date_format'                => 'd-m-Y',
            'site_time_format'                => 'H:i',
            'site_default_timezone'           => 'Europe/Paris',
            'site_default_branch'             => 1,
            'site_default_currency'           => $this->devise->id,
            'site_currency_position'          => 10,
            'site_digit_after_decimal_point'  => 2,
            'site_email_verification'         => 10,
            'site_phone_verification'         => 10,
            'site_default_language'           => 1,
            'site_language_switch'            => 5,
            'site_app_debug'                  => 10,
            'site_online_payment_gateway'     => 10,
            'site_guest_login'                => 5,
            'site_default_phone_digit_length' => 10,
            // Les deux valeurs réelles de l'installation : absentes.
        ], $ecrasements);
    }

    /**
     * Le geste réel : le commerçant ouvre l'écran Site et enregistre, sans avoir
     * jamais eu de clé Google Maps.
     */
    public function test_le_site_s_enregistre_sans_cle_google_maps_ni_copyright(): void
    {
        $reponse = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/setting/site', $this->saisieDeLEcran());

        // [ONB-05 2026-08-28] Cette assertion disait `assertNotSame(422, ...)`. Un agent
        // adverse lancé sur mon propre travail a montré qu'elle etait verte sur un
        // HTTP 500 : `SiteResource` lisait 22 clés SANS GARDE et le semoir de test n'en
        // fournit qu'une poignée. Le banc s'appelait « le site s'enregistre » et le site
        // ne s'est jamais enregistré une seule fois.
        //
        // On exige donc le VRAI succès, et surtout la PERSISTANCE : un 2xx ne prouve
        // rien si rien n'a été écrit.
        $this->assertTrue(
            $reponse->status() >= 200 && $reponse->status() < 300,
            "L'écran Site doit s'enregistrer sans clé Google Maps ni copyright.\n"
            . "Code obtenu : {$reponse->status()}. Erreurs : "
            . json_encode($reponse->json('errors'), JSON_UNESCAPED_UNICODE)
        );

        $this->assertSame(
            'Europe/Paris',
            Settings::group('site')->get('site_default_timezone'),
            "Le fuseau horaire doit être RELU depuis les réglages après enregistrement.\n"
            . 'Un code 2xx ne prouve rien si rien n\'a été écrit.'
        );
        $this->assertSame(
            'd-m-Y',
            Settings::group('site')->get('site_date_format'),
            'Le format de date non plus.'
        );
    }

    /**
     * Assertion séparée sur les deux champs, pour que l'échec les NOMME au lieu de
     * dire seulement « 422 ».
     */
    public function test_ces_deux_champs_ne_sont_plus_exiges(): void
    {
        $reponse = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/setting/site', $this->saisieDeLEcran());

        $erreurs = (array) $reponse->json('errors');

        $this->assertArrayNotHasKey(
            'site_google_map_key',
            $erreurs,
            "Une clé d'API tierce ne conditionne pas le fuseau horaire d'une caisse."
        );
        $this->assertArrayNotHasKey(
            'site_copyright',
            $erreurs,
            'Une mention de pied de page non plus.'
        );
    }

    /**
     * Contrôle négatif n°1 : rendre le champ facultatif ne doit pas désarmer le
     * garde-fou anti-injection `.env`. Une valeur contenant un saut de ligne peut
     * écrire une ligne indépendante dans le `.env` (par exemple APP_DEBUG=true).
     */
    public function test_le_garde_fou_anti_injection_env_mord_toujours(): void
    {
        $reponse = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/setting/site', $this->saisieDeLEcran([
                'site_google_map_key' => "AIza\nAPP_DEBUG=true",
            ]));

        $reponse->assertStatus(422)->assertJsonValidationErrors(['site_google_map_key']);
    }

    /**
     * Contrôle négatif n°2 : les champs qui pilotent VRAIMENT le comportement de la
     * caisse restent obligatoires. Sans cette assertion, on « réparerait » les tests
     * ci-dessus en rendant tout facultatif.
     */
    public function test_les_reglages_qui_pilotent_la_caisse_restent_obligatoires(): void
    {
        $saisie = $this->saisieDeLEcran();
        unset($saisie['site_default_timezone'], $saisie['site_default_currency']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/setting/site', $saisie)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_default_timezone', 'site_default_currency']);
    }
}
