<?php

namespace Tests\Feature\Horaires;

use App\Models\User;
use App\Services\Delivery\DeliveryWaveService;
use App\Services\Horaires\HorairesService;
use App\Services\WaitEstimateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [HORAIRES 2026-11-05] Réglage des horaires dans l'admin — une seule source pour les commandes programmées, les tournées de
 * livraison, le « service ouvert » du site et l'affichage de l'application.
 */
class HorairesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['kds.scheduled_window_open' => '18:00', 'kds.scheduled_window_close' => '00:30', 'app.timezone' => 'Europe/Paris']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function h(): HorairesService
    {
        return app(HorairesService::class);
    }

    private function t(string $quand): Carbon
    {
        return Carbon::parse($quand, 'Europe/Paris');
    }

    private function semaine(array $par = []): array
    {
        $s = [];
        foreach (range(1, 7) as $iso) {
            $s[$iso] = $par[$iso] ?? ['ouvert' => true, 'ouverture' => '18:00', 'fermeture' => '00:30'];
        }

        return $s;
    }

    /** Sans réglage : EXACTEMENT l'ancienne fenêtre .env (18:00 → 00:30 incluses, tous les jours). */
    public function test_sans_reglage_le_comportement_d_avant_est_garde(): void
    {
        $h = $this->h();
        $this->assertTrue($h->estEnService($this->t('2026-11-05 18:00')));
        $this->assertTrue($h->estEnService($this->t('2026-11-05 23:59')));
        $this->assertTrue($h->estEnService($this->t('2026-11-06 00:30')));
        $this->assertFalse($h->estEnService($this->t('2026-11-06 00:31')));
        $this->assertFalse($h->estEnService($this->t('2026-11-05 17:59')));
        $this->assertSame('Tous les jours 18h – 00h30', $h->resume());
        $this->assertTrue(WaitEstimateService::serviceOuvert($this->t('2026-11-05 19:00')));
        $this->assertFalse(app(DeliveryWaveService::class)->inServiceWindow($this->t('2026-11-05 03:00')));
    }

    /** Jour fermé, service de midi, service qui passe minuit : rattaché au bon jour. */
    public function test_semaine_reglee_jour_par_jour(): void
    {
        // 2026-11-09 = lundi.
        $this->h()->enregistrer($this->semaine([
            1 => ['ouvert' => false, 'ouverture' => '18:00', 'fermeture' => '00:00'],
            2 => ['ouvert' => true, 'ouverture' => '11:30', 'fermeture' => '14:00'],
            7 => ['ouvert' => true, 'ouverture' => '18:00', 'fermeture' => '01:00'],
        ]), []);
        $h = $this->h();
        $this->assertFalse($h->estEnService($this->t('2026-11-09 19:00')), 'lundi fermé');
        $this->assertTrue($h->estEnService($this->t('2026-11-09 00:45')), 'nuit de dimanche à lundi : service de DIMANCHE jusqu\'à 01:00');
        $this->assertFalse($h->estEnService($this->t('2026-11-09 01:01')));
        $this->assertTrue($h->estEnService($this->t('2026-11-10 12:00')), 'mardi midi');
        $this->assertFalse($h->estEnService($this->t('2026-11-10 19:00')), 'mardi soir : seulement le midi');
        $p = $h->prochaineOuverture($this->t('2026-11-09 19:00'));
        $this->assertSame('2026-11-10', $p['jour']);
        $this->assertSame('11:30', $p['debut']->format('H:i'));
    }

    /** Fermeture exceptionnelle : plus de service, message affiché, réouverture après. */
    public function test_fermeture_exceptionnelle(): void
    {
        $this->h()->enregistrer($this->semaine(), [['du' => '2026-12-24', 'au' => '2026-12-25', 'message' => 'Fermé pour Noël']]);
        $h = $this->h();
        $this->assertFalse($h->estEnService($this->t('2026-12-24 19:00')));
        $this->assertFalse($h->estEnService($this->t('2026-12-25 19:00')));
        $this->assertTrue($h->estEnService($this->t('2026-12-24 00:15')), 'la nuit du 23 au 24 appartient au service du 23');
        $etat = $h->etatPublic($this->t('2026-12-24 19:00'));
        $this->assertFalse($etat['ouvert']);
        $this->assertSame('Fermé pour Noël', $etat['fermeture_exceptionnelle']['message']);
        $this->assertSame('2026-12-26', $etat['prochaine_ouverture']['date']);
        $this->assertFalse($etat['prochaine_ouverture']['aujourdhui']);
        $this->assertSame('samedi', $etat['prochaine_ouverture']['jour']);
    }

    /** L'estimation lue par le tunnel porte la prochaine ouverture RÉELLE et le message de fermeture. */
    public function test_estimation_du_site_suit_le_reglage(): void
    {
        $this->h()->enregistrer($this->semaine(), [['du' => '2026-12-24', 'au' => '2026-12-24', 'message' => 'Inventaire']]);
        Carbon::setTestNow($this->t('2026-12-24 19:00'));
        $e = app(WaitEstimateService::class)->estimate(1);
        $this->assertFalse($e['service_ouvert']);
        $this->assertSame('Inventaire', $e['fermeture_exceptionnelle']['message']);
        $this->assertSame('2026-12-25', $e['prochaine_ouverture']['date']);
        $this->assertSame('18:00', $e['ouverture']);
    }

    /** Admin : permission « settings » exigée ; l'écran enregistre, la lecture publique suit aussitôt. */
    public function test_admin_enregistre_et_la_lecture_publique_suit(): void
    {
        $payload = ['semaine' => $this->semaine([3 => ['ouvert' => false, 'ouverture' => '18:00', 'fermeture' => '00:00']]),
            'fermetures' => [['du' => '2026-12-31', 'au' => '2026-12-31', 'message' => 'Réveillon']]];

        $client = User::factory()->create(['branch_id' => 0]);
        $client->assignRole('Customer');
        Sanctum::actingAs($client, ['*']);
        $this->putJson('/api/admin/setting/horaires', $payload)->assertStatus(403);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);
        $this->putJson('/api/admin/setting/horaires', ['semaine' => $this->semaine([2 => ['ouvert' => true, 'ouverture' => '18:00', 'fermeture' => '18:00']]), 'fermetures' => []])
            ->assertStatus(422);
        // [AUDIT ABUSIF W4-P2] Clés « 01 » … « 07 » : refusées (elles fermaient les 7 jours sans erreur).
        $forge = [];
        foreach ($this->semaine() as $iso => $j) {
            $forge['0' . $iso] = $j;
        }
        $this->putJson('/api/admin/setting/horaires', ['semaine' => $forge, 'fermetures' => []])->assertStatus(422);
        $this->putJson('/api/admin/setting/horaires', $payload)->assertStatus(200);
        $this->getJson('/api/admin/setting/horaires')->assertStatus(200)->assertJsonPath('data.fermetures.0.message', 'Réveillon');

        $public = $this->getJson('/api/frontend/horaires')->assertStatus(200);
        $this->assertFalse($public->json('data.semaine.2.ouvert'), 'mercredi fermé (index 2 = 3ᵉ jour)');
        $this->assertSame('mercredi', $public->json('data.semaine.2.jour'));
    }

    /** Commande programmée : refusée un jour de fermeture, avec le message du restaurant. */
    public function test_commande_programmee_refusee_un_jour_de_fermeture(): void
    {
        Carbon::setTestNow($this->t('2026-12-23 12:00'));
        $this->h()->enregistrer($this->semaine(), [['du' => '2026-12-24', 'au' => '2026-12-24', 'message' => 'Fermé pour Noël']]);
        $erreurs = $this->erreursCreneau('2026-12-24 19:30:00');
        $this->assertStringContainsString('Fermé pour Noël', implode(' ', $erreurs));
        $this->assertSame([], $this->erreursCreneau('2026-12-23 19:30:00'), 'un jour ouvert reste accepté');
    }

    /** @return list<string> */
    private function erreursCreneau(string $quand): array
    {
        $req = \App\Http\Requests\OrderRequest::create('/api/frontend/order', 'POST', ['scheduled_at' => $quand]);
        $validator = \Illuminate\Support\Facades\Validator::make([], []);
        $m = new \ReflectionMethod($req, 'validateScheduledAtAfter');
        $m->setAccessible(true);
        $m->invoke($req, $validator);

        return $validator->errors()->get('scheduled_at');
    }
}
