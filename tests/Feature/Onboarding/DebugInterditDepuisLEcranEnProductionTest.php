<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Activity;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\User;
use Dipokhalder\EnvEditor\EnvEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ONB-10 2026-08-27] L'interrupteur « Debug application » pouvait éteindre la caisse.
 *
 * Vu sur l'écran Paramètres > Site, en bas : un interrupteur DEBUG APPLICATION,
 * présenté comme les autres réglages, avec deux boutons Activer / Désactiver.
 *
 * Ce qu'il fait réellement, bout à bout :
 *
 *   1. `SiteService::update` écrit `APP_DEBUG=true` dans le `.env` ;
 *   2. `AppServiceProvider` REFUSE DE DÉMARRER en production si `APP_DEBUG=true`.
 *
 * Le garde-fou (2) est volontaire et justifié : le mode debug expose les traces
 * d'exécution, les requêtes SQL, les identifiants de base et le contexte HMAC. Mais
 * combiné à (1), il transforme un clic sur un écran de réglages en ARRÊT COMPLET de
 * la caisse — à la requête suivante, sans retour possible depuis l'interface. Il faut
 * se connecter à la machine et éditer le `.env` à la main. En plein service.
 *
 * Le commentaire du garde-fou dit lui-même qu'il tient lieu de pansement « until the
 * EnvEditor allowlist heal lands » (backlog V1.0.2 M-P0-D/E/F). Ce test verrouille le
 * correctif à la source : on refuse l'écriture, plutôt que de laisser le commerçant
 * se couper le courant.
 *
 * En développement, l'interrupteur continue de fonctionner : le garde-fou de
 * production ne s'applique pas, et un développeur a de bonnes raisons d'activer le
 * debug. C'est le troisième test.
 */
class DebugInterditDepuisLEcranEnProductionTest extends TestCase
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
            'name'              => 'Euro',
            'code'              => 'EUR',
            'symbol'            => '€',
            'is_cryptocurrency' => 0,
            'exchange_rate'     => 1,
            'status'            => 5,
        ]);

        // Le `.env` du dépôt ne doit pas bouger parce qu'on lance la suite.
        $this->mock(EnvEditor::class, function ($double) {
            $double->shouldReceive('addData')->andReturn(true);
            $double->shouldReceive('getValue')->andReturn(null);
        });
    }

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
            'site_app_debug'                  => Activity::DISABLE,
            'site_online_payment_gateway'     => 10,
            'site_guest_login'                => 5,
            'site_default_phone_digit_length' => 10,
        ], $ecrasements);
    }

    /** Bascule l'application en production, le temps de la requête. */
    private function enProduction(): void
    {
        $this->app['env'] = 'production';
        $this->app->detectEnvironment(fn () => 'production');
    }

    public function test_en_production_l_ecran_refuse_d_activer_le_debug(): void
    {
        $this->enProduction();

        $reponse = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/setting/site', $this->saisieDeLEcran([
                'site_app_debug' => Activity::ENABLE,
            ]));

        $reponse->assertStatus(422)->assertJsonValidationErrors(['site_app_debug']);

        $this->assertStringContainsString(
            'refuserait de démarrer',
            (string) $reponse->json('errors.site_app_debug.0'),
            "Le message doit dire au commerçant CE QUI SE PASSERAIT, pas seulement que\n"
            . "c'est interdit. « Valeur invalide » ne lui apprend rien ; « le serveur\n"
            . "refuserait de démarrer » lui évite d'insister."
        );
    }

    /**
     * Le reste de l'écran doit rester enregistrable en production : on bride le seul
     * interrupteur dangereux, pas la page entière.
     */
    public function test_en_production_le_reste_de_l_ecran_reste_enregistrable(): void
    {
        $this->enProduction();

        $reponse = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/setting/site', $this->saisieDeLEcran());

        $this->assertNotSame(
            422,
            $reponse->status(),
            "Le garde ne doit bloquer QUE l'activation du debug. Bloquer toute la page\n"
            . "en production reproduirait le défaut qu'on vient de corriger.\n"
            . 'Erreurs : ' . json_encode($reponse->json('errors'), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Contrôle négatif : hors production, le garde-fou de démarrage ne s'applique pas
     * et un développeur a de bonnes raisons d'activer le debug. Sans cette assertion,
     * on « réparerait » le test ci-dessus en interdisant le debug partout.
     */
    public function test_hors_production_l_interrupteur_fonctionne_toujours(): void
    {
        $this->assertFalse(
            app()->environment('production'),
            'Ce test doit tourner hors production.'
        );

        $reponse = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/setting/site', $this->saisieDeLEcran([
                'site_app_debug' => Activity::ENABLE,
            ]));

        $erreurs = (array) $reponse->json('errors');

        $this->assertArrayNotHasKey(
            'site_app_debug',
            $erreurs,
            "En développement, activer le debug est legitime : le garde-fou de démarrage\n"
            . 'ne concerne que la production.'
        );
    }
}
