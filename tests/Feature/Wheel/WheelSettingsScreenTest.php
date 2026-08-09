<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\User;
use App\Services\Wheel\WheelSettingsService;
use App\Services\Wheel\WheelStepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * L'ÉCRAN QUI DÉBLOQUE LE JEU.
 *
 * Le propriétaire l'a dit sans détour : « jamais ça tourne ». Il avait raison. Les trois adresses du
 * parcours — lien d'avis Google, Instagram, Snapchat — sont SES comptes : lui seul peut les fournir.
 * Tant qu'elles vivaient dans des variables d'environnement, la fonctionnalité était finie et
 * DORMAIT en attendant que quelqu'un les pose sur le serveur.
 *
 * Ce que cette suite verrouille :
 *   1. les réglages saisis PRIMENT sur la configuration, et le parcours s'active SANS redéploiement ;
 *   2. l'écran dit son ÉTAT — « ça tourne » / « ça ne tourne pas encore, et voici pourquoi » — parce
 *      qu'un réglage dont on ne voit pas l'effet est un réglage qu'on ne touche pas ;
 *   3. DÉCOCHER une case a un effet : les cases non cochées ne sont pas envoyées par le navigateur,
 *      sans traitement explicite on ne pourrait jamais désactiver une étape ;
 *   4. un lien invalide est refusé — un lien cassé sur la tablette envoie le client nulle part, ce
 *      qui est pire que pas de lien ;
 *   5. un formulaire ne peut PAS écrire n'importe quelle clé de réglage de l'application ;
 *   6. l'écran est fermé aux comptes sans droit caisse.
 */
class WheelSettingsScreenTest extends TestCase
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

        // Départ : AUCUN lien configuré — l'état réel de la production avant cet écran.
        Config::set('wheel.steps', [
            'review' => ['required' => true, 'url' => '', 'dwell_seconds' => 20],
            'follow' => ['required' => true, 'instagram' => '', 'snapchat' => '', 'dwell_seconds' => 8],
        ]);
    }

    public function test_l_ecran_est_ferme_sans_droit_caisse(): void
    {
        $quidam = User::factory()->create(['branch_id' => 1]);
        $this->actingAs($quidam)->get('/admin/roue-reglages')->assertStatus(403);
        $this->actingAs($quidam)->post('/admin/roue-reglages')->assertStatus(403);
    }

    /** SANS lien : l'écran dit que ça ne tourne pas, et pourquoi. */
    public function test_sans_aucun_lien_l_ecran_dit_que_le_parcours_ne_tourne_PAS(): void
    {
        $r = $this->actingAs($this->caissier)->get('/admin/roue-reglages')->assertOk();

        $r->assertSee('ne tourne pas encore', false);
        $this->assertFalse(app(WheelSettingsService::class)->journeyReady());
        // Et les étapes sont bien sautées : on n'exige pas ce qu'on ne fournit pas.
        $this->assertFalse(app(WheelStepService::class)->required(WheelStepService::REVIEW));
    }

    /**
     * LE CŒUR DE CE LOT : coller un lien SUFFIT à activer le parcours. Pas de redéploiement, pas
     * d'accès serveur, pas de variable d'environnement.
     */
    public function test_coller_un_lien_ACTIVE_le_parcours_immediatement(): void
    {
        $this->actingAs($this->caissier)->post('/admin/roue-reglages', [
            'review_url' => 'https://g.page/r/CtestAvis/review',
            'review_required' => '1',
            'follow_required' => '1',
            'review_dwell' => 20,
            'follow_dwell' => 8,
            'min_order' => 10,
        ])->assertOk()->assertSee('Le parcours tourne', false);

        $reglages = app(WheelSettingsService::class);
        $this->assertTrue($reglages->journeyReady());
        $this->assertSame('https://g.page/r/CtestAvis/review', $reglages->reviewUrl());

        // Et l'étape est désormais RÉELLEMENT exigée par le service, sans rien redéployer.
        $steps = app(WheelStepService::class);
        $this->assertTrue($steps->required(WheelStepService::REVIEW),
            'le lien est collé mais l\'étape n\'est pas exigée : le jeu ne contrôlerait rien');
        // On n'a collé QUE le lien d'avis : l'abonnement reste légitimement signalé comme manquant.
        // C'est le comportement voulu — chaque étape est indépendante.
        $this->assertNotContains('review', $steps->missingLinks(),
            'l\'avis est configuré : il ne doit plus être signalé comme manquant');
        $this->assertContains('follow', $steps->missingLinks(),
            'l\'abonnement est demandé mais sans lien : il DOIT rester signalé');
    }

    public function test_les_comptes_sociaux_activent_l_etape_abonnement(): void
    {
        $this->actingAs($this->caissier)->post('/admin/roue-reglages', [
            'instagram_url' => 'https://instagram.com/lecayenne',
            'follow_required' => '1',
        ])->assertOk();

        $steps = app(WheelStepService::class);
        $this->assertTrue($steps->hasLink(WheelStepService::FOLLOW));
        $this->assertTrue($steps->required(WheelStepService::FOLLOW));

        // Snapchat seul suffit aussi : un seul réseau renseigné rend l'étape possible.
        $publiees = array_column($steps->publicSteps(), 'key');
        $this->assertContains('follow', $publiees);
        $this->assertNotContains('review', $publiees, 'une étape sans lien ne doit pas être publiée');
    }

    /**
     * DÉCOCHER DOIT MARCHER. Une case non cochée n'est PAS envoyée par le navigateur : sans
     * traitement explicite, on ne pourrait jamais désactiver une étape — le réglage serait à sens
     * unique, et personne ne comprendrait pourquoi.
     */
    public function test_decocher_une_case_a_bien_un_effet(): void
    {
        $this->actingAs($this->caissier)->post('/admin/roue-reglages', [
            'review_url' => 'https://g.page/r/CtestAvis/review',
            'review_required' => '1',
        ])->assertOk();
        $this->assertTrue(app(WheelSettingsService::class)->reviewRequired());

        // Deuxième envoi SANS la case : le navigateur ne l'envoie pas du tout.
        $this->actingAs($this->caissier)->post('/admin/roue-reglages', [
            'review_url' => 'https://g.page/r/CtestAvis/review',
        ])->assertOk();

        $this->assertFalse(app(WheelSettingsService::class)->reviewRequired(),
            'décocher n\'a eu aucun effet : le réglage serait à sens unique');
        // Le lien reste : on a désactivé l'obligation, pas retiré l'invitation.
        $this->assertSame('https://g.page/r/CtestAvis/review', app(WheelSettingsService::class)->reviewUrl());
    }

    public function test_un_lien_invalide_est_refuse(): void
    {
        // 302 (renvoi vers le formulaire) ou 422 (erreurs en JSON) selon ce que la requête accepte.
        // Ce qui compte n'est pas le code mais le RÉSULTAT : rien d'invalide n'est enregistré.
        $r = $this->actingAs($this->caissier)->post('/admin/roue-reglages', [
            'review_url' => 'pas-une-adresse',
        ]);
        $this->assertContains($r->status(), [302, 422]);

        $this->assertSame('', app(WheelSettingsService::class)->reviewUrl(),
            'un lien cassé a été enregistré : il enverrait le client nulle part');
    }

    /** Un formulaire n'a pas à pouvoir écrire n'importe quelle clé de réglage de l'application. */
    public function test_une_cle_inconnue_n_est_PAS_enregistree(): void
    {
        $this->actingAs($this->caissier)->post('/admin/roue-reglages', [
            'review_url' => 'https://g.page/r/CtestAvis/review',
            'app_key' => 'pirate',
            'wheel_enabled' => '1',
        ])->assertOk();

        $tout = app(WheelSettingsService::class)->all();
        $this->assertArrayNotHasKey('app_key', $tout);
        $this->assertArrayNotHasKey('wheel_enabled', $tout,
            'un formulaire a pu écrire une clé qui ne lui appartient pas');
    }

    /**
     * LA LISTE BLANCHE DU SERVICE, testée DIRECTEMENT. Le contrôleur ne transmet que des clés
     * validées, donc en passant par l'écran la liste blanche n'est jamais sollicitée — une mutation
     * l'a montré : la retirer ne cassait aucun test. C'est de la défense en profondeur, et on la
     * prouve en s'adressant au service lui-même.
     */
    public function test_le_service_refuse_directement_une_cle_inconnue(): void
    {
        $svc = app(WheelSettingsService::class);

        $svc->save([
            'review_url' => 'https://g.page/r/Ctest/review',
            'app_key' => 'pirate',
            'daily_total_cap' => '99999',
        ]);

        $tout = $svc->all();
        $this->assertSame('https://g.page/r/Ctest/review', $tout['review_url'],
            'la clé légitime doit passer');
        $this->assertArrayNotHasKey('app_key', $tout,
            'le service écrit une clé qui ne lui appartient pas : n\'importe quel appelant pourrait '
            . 'modifier un réglage de l\'application');
        $this->assertArrayNotHasKey('daily_total_cap', $tout,
            'le plafond journalier serait modifiable par un formulaire : c\'est le garde-fou du budget');
    }

    public function test_le_temps_d_attente_et_le_minimum_sont_saisissables(): void
    {
        $this->actingAs($this->caissier)->post('/admin/roue-reglages', [
            'review_url' => 'https://g.page/r/CtestAvis/review',
            'review_dwell' => 35,
            'follow_dwell' => 12,
            'min_order' => 15.5,
        ])->assertOk();

        $steps = app(WheelStepService::class);
        $this->assertSame(35, $steps->dwell(WheelStepService::REVIEW));
        $this->assertSame(12, $steps->dwell(WheelStepService::FOLLOW));
        $this->assertSame(15.5, app(WheelSettingsService::class)->minOrder());
    }
}
