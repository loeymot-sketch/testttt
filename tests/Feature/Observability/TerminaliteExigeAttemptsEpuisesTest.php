<?php

namespace Tests\Feature\Observability;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [G2 2026-09-03 · T2.6 · défaut V-10] « Terminal » se disait dès le premier essai.
 *
 * `SyncOverviewController::outboxOverview` comptait comme échec TERMINAL toute ligne
 * pendante portant un `last_error`, sans regarder `attempts`. Or `DispatchDomainEventsJob`
 * écrit `last_error` ET relâche le claim (`dispatched_at = null`) dès la PREMIÈRE des
 * six tentatives (Phase 3b) : un événement à `attempts = 1` est en cours de reprise
 * automatique — la courbe de backoff [1, 5, 15, 60, 300] lui laisse encore ~6 minutes.
 *
 * Le compteur « échecs terminaux » gonflait donc de tous les événements en cours de
 * reprise normale, et l'écran annonçait une population définitivement perdue là où le
 * système se réparait tout seul.
 *
 * Le seuil est LU SUR LE JOB (`$tries`), pas recopié : si la courbe change, la mesure suit.
 *
 * Exception conservée : une violation de contrat est terminale à `attempts = 1`, parce
 * que le job appelle `$this->fail()` immédiatement — un payload malformé ne guérit pas
 * en le rejouant (`DispatchDomainEventsJob.php` Phase 3b, sentinelle
 * `PayloadMismatchFailOnceSentinelTest`). C'est aussi le cas couvert par
 * `OutboxDeliverySemanticsTest::test_les_echecs_terminaux_sont_comptes_a_part`.
 */
class TerminaliteExigeAttemptsEpuisesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Cache::forget('ws:heartbeat');
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        return $u;
    }

    /** Le nombre d'essais réel du job — source unique, jamais recopiée. */
    private function essais(): int
    {
        return (int) (new DispatchDomainEventsJob(0))->tries;
    }

    private function evenement(array $ecrasements): void
    {
        DB::table('domain_events')->insert(array_merge([
            'event_type' => 'OrderUpdated',
            'aggregate_type' => 'order',
            'aggregate_id' => 1,
            'branch_id' => 1,
            'payload' => json_encode([]),
            'channel' => 'orders',
            'broadcast_as' => 'order.updated',
            'correlation_id' => null,
            'occurred_at' => now()->subMinutes(3),
            'dispatched_at' => null,
            'broadcast_at' => null,
            'attempts' => 0,
            'last_error' => null,
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ], $ecrasements));
    }

    private function apercu(): array
    {
        return $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/outbox')
            ->assertOk()
            ->json();
    }

    public function test_un_evenement_au_premier_essai_est_en_cours_de_reprise_pas_terminal(): void
    {
        $this->evenement(['aggregate_id' => 1, 'attempts' => 1, 'last_error' => 'broker unreachable']);

        $r = $this->apercu();

        $this->assertSame(0, $r['terminal_failures']['count'], 'attempts=1 sur 6 : la reprise n\'a pas encore eu lieu');
        $this->assertSame(1, $r['pending']['count'], 'il reste bien visible comme « en attente »');
    }

    public function test_un_evenement_a_l_avant_derniere_tentative_n_est_pas_terminal_non_plus(): void
    {
        $this->evenement(['aggregate_id' => 2, 'attempts' => $this->essais() - 1, 'last_error' => 'broker unreachable']);

        $r = $this->apercu();

        $this->assertSame(0, $r['terminal_failures']['count']);
    }

    public function test_un_evenement_qui_a_epuise_ses_essais_est_terminal(): void
    {
        $this->evenement(['aggregate_id' => 3, 'attempts' => $this->essais(), 'last_error' => 'broker unreachable']);

        $r = $this->apercu();

        $this->assertSame(1, $r['terminal_failures']['count']);
    }

    public function test_une_violation_de_contrat_est_terminale_des_le_premier_essai(): void
    {
        // Le job appelle `$this->fail()` : aucune reprise ne viendra, quel que soit `attempts`.
        $this->evenement(['aggregate_id' => 4, 'attempts' => 1, 'last_error' => 'contract_violation: bad payload']);

        $r = $this->apercu();

        $this->assertSame(1, $r['terminal_failures']['count']);
        $this->assertSame(1, $r['terminal_failures']['contract_violations']);
    }

    public function test_le_bouton_rejouer_suit_ce_qui_est_reellement_rejouable(): void
    {
        // Trois populations distinctes, trois verdicts distincts.
        $this->evenement(['aggregate_id' => 5, 'attempts' => 1, 'last_error' => 'broker unreachable']);       // en reprise
        $this->evenement(['aggregate_id' => 6, 'attempts' => $this->essais(), 'last_error' => 'broker down']); // terminal, rejouable
        $this->evenement(['aggregate_id' => 7, 'attempts' => 1, 'last_error' => 'contract_violation: nope']);  // terminal, NON rejouable

        $r = $this->apercu();

        $this->assertSame(2, $r['terminal_failures']['count'], 'épuisé + violation de contrat');
        $this->assertSame(1, $r['terminal_failures']['contract_violations']);
        // `replayable_events` = exactement la sélection de `outboxRetryFailed` : pendant,
        // avec `last_error`, hors violation de contrat, dans la fenêtre d'âge.
        $this->assertSame(2, $r['replayable_events']['count'], 'la relance manuelle ne regarde pas `attempts`');
    }

    public function test_un_echec_hors_fenetre_d_age_n_est_plus_rejouable(): void
    {
        $this->evenement([
            'aggregate_id' => 8,
            'attempts' => $this->essais(),
            'last_error' => 'broker unreachable',
            'created_at' => now()->subDays(30),
            'occurred_at' => now()->subDays(30),
        ]);

        $r = $this->apercu();

        $this->assertSame(1, $r['terminal_failures']['count'], 'il reste un échec terminal');
        $this->assertSame(0, $r['replayable_events']['count'], 'mais la relance manuelle ne le prendrait pas');
    }

    public function test_un_evenement_sain_n_est_ni_terminal_ni_rejouable(): void
    {
        $this->evenement(['aggregate_id' => 9]);

        $r = $this->apercu();

        $this->assertSame(1, $r['pending']['count']);
        $this->assertSame(0, $r['terminal_failures']['count']);
        $this->assertSame(0, $r['replayable_events']['count']);
    }
}
