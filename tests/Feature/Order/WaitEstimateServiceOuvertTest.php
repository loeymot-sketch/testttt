<?php

namespace Tests\Feature\Order;

use App\Models\Branch;
use App\Services\WaitEstimateService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL STORES 2026-10-01] L'estimation dit enfin si le restaurant SERT.
 *
 * Mesuré sur la production à 02 h 35 : `GET order/wait-estimate` répondait « 10-15 min »,
 * restaurant fermé. Le site affichait donc « prête dans ~10-15 min » à un client devant
 * une porte close — et acceptait sa commande « dès que prêt » (la garde horaire
 * d'OrderRequest ne vise que les commandes PROGRAMMÉES).
 *
 * La fourchette constante 10-15 est une décision propriétaire (2026-09-23) : on n'y touche
 * pas. On AJOUTE deux champs, sans rien retirer au contrat existant :
 *   - `service_ouvert` : vrai dans la fenêtre de service `kds.scheduled_window_open/close`
 *     (la MÊME que celle qui valide les commandes programmées — une seule définition) ;
 *   - `ouverture` : l'heure d'ouverture (HH:MM), pour que le site propose le premier créneau.
 */
class WaitEstimateServiceOuvertTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        config(['kds.scheduled_window_open' => '18:00', 'kds.scheduled_window_close' => '00:30']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function aHeure(string $hhmm): array
    {
        $moment = Carbon::parse('2026-10-01 ' . $hhmm . ':00', config('app.timezone'));
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment->toImmutable());

        return app(WaitEstimateService::class)->estimate($this->branch->id);
    }

    /** @test */
    public function en_pleine_nuit_le_service_est_ferme_et_l_ouverture_est_donnee(): void
    {
        $r = $this->aHeure('02:35');

        $this->assertFalse($r['service_ouvert'], 'À 02 h 35 le restaurant est fermé : le site ne doit plus promettre de minutes.');
        $this->assertSame('18:00', $r['ouverture']);
    }

    /** @test */
    public function pendant_le_service_il_est_ouvert(): void
    {
        $this->assertTrue($this->aHeure('19:00')['service_ouvert']);
    }

    /** @test */
    public function apres_minuit_mais_avant_la_fin_de_fenetre_il_est_encore_ouvert(): void
    {
        $this->assertTrue($this->aHeure('00:15')['service_ouvert'], 'Fenêtre 18:00 → 00:30 : 00 h 15 est dans le service.');
    }

    /** @test */
    public function apres_la_fin_de_fenetre_il_est_ferme(): void
    {
        $this->assertFalse($this->aHeure('00:45')['service_ouvert']);
    }

    /** @test */
    public function la_fenetre_suit_la_configuration_et_non_une_valeur_codee(): void
    {
        config(['kds.scheduled_window_open' => '11:30', 'kds.scheduled_window_close' => '14:00']);

        $this->assertTrue($this->aHeure('12:00')['service_ouvert']);
        $this->assertSame('11:30', $this->aHeure('12:00')['ouverture']);
        $this->assertFalse($this->aHeure('15:00')['service_ouvert']);
    }

    /** @test */
    public function le_contrat_existant_est_intact(): void
    {
        $r = $this->aHeure('02:35');

        // Décision propriétaire 2026-09-23 : fourchette constante, inchangée par ce correctif.
        $this->assertSame(10, $r['wait_low']);
        $this->assertSame(15, $r['wait_high']);
        foreach (['queue_count', 'queue_count_displayed', 'closing_time', 'server_time'] as $cle) {
            $this->assertArrayHasKey($cle, $r);
        }
    }
}
