<?php

namespace Tests\Feature\Pos;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — T-1.1.3]
 *
 * CE QUI S'EST RÉELLEMENT PASSÉ
 * ------------------------------
 * Pendant le cycle `CAISSE-SUPERVISOR-CONTROL-20260823`, le worker de file était **absent** :
 * 436 travaux en attente. La pastille de santé, tout juste corrigée, l'a signalé honnêtement
 * (« Traitement en retard »), puis est repassée au vert après redémarrage du worker. Le correctif
 * s'est donc fait valider par une panne réelle, et non par un test.
 *
 * CE QUE LA SUITE EXISTANTE NE COUVRAIT PAS
 * ------------------------------------------
 * `PosSystemHealthTest` prouve abondamment la DÉGRADATION (`test_lagging_worker_surfaces_degraded_sync`,
 * `test_queue_driver_failure_surfaces_null_instead_of_false_zero`, seuils, socket…). Aucun test ne
 * prouvait le RETOUR AU VERT.
 *
 * C'est pourtant la moitié qui compte pour le comptoir : une pastille qui sait s'allumer mais pas
 * s'éteindre finit ignorée. Et si l'état dégradé restait épinglé par un cache, le caissier verrait
 * « en retard » alors que la cuisine reçoit déjà — il rappellerait un technicien pour rien, ou pire,
 * il cesserait de croire la pastille.
 *
 * Ces tests épinglent donc : dégradation → rattrapage → vert, **sans aucune intervention manuelle
 * sur le cache**.
 */
class PosSystemHealthQueueRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key-pos-health-recovery';

    protected function setUp(): void
    {
        parent::setUp();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Cache::flush();
        config(['app.api_key' => self::API_KEY]);
        $this->withHeaders(['x-api-key' => self::API_KEY, 'Accept' => 'application/json']);
    }

    private function cashier(int $branchId = 1): User
    {
        if ($branchId > 0 && ! Branch::withoutGlobalScopes()->find($branchId)) {
            Branch::factory()->create(['id' => $branchId]);
        }
        $u = User::factory()->create(['branch_id' => $branchId]);
        $u->assignRole('POS Operator');

        return $u;
    }

    private function insertStaleOutboxEvents(int $n, int $branchId = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('domain_events')->insert([
                'event_type'     => 'TestStale',
                'aggregate_type' => 'Order',
                'aggregate_id'   => $i + 1,
                'branch_id'      => $branchId,
                'payload'        => json_encode([]),
                'occurred_at'    => now()->subMinutes(2),
                'dispatched_at'  => null,
                'attempts'       => 0,
                'last_error'     => null,
                'created_at'     => now()->subMinutes(2),
                'updated_at'     => now()->subMinutes(2),
            ]);
        }
    }

    /** Simule le rattrapage du worker : les events en attente partent enfin. */
    private function workerRattrapeLeRetard(): void
    {
        DB::table('domain_events')->whereNull('dispatched_at')->update(['dispatched_at' => now()]);
    }

    private function sante(): array
    {
        $res = $this->getJson('/api/admin/pos/system-health');
        $res->assertStatus(200);

        return $res->json();
    }

    public function test_la_pastille_repasse_au_vert_quand_le_worker_rattrape(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);

        $this->insertStaleOutboxEvents(11);
        $enPanne = $this->sante();
        $this->assertSame('warn', $enPanne['checks']['sync']['status'], 'Le retard doit être signalé.');
        $this->assertContains($enPanne['overall'], ['degraded', 'down']);

        $this->workerRattrapeLeRetard();

        $retabli = $this->sante();
        $this->assertSame(
            'ok',
            $retabli['checks']['sync']['status'],
            'Une pastille qui sait s\'allumer doit savoir s\'éteindre, sinon elle finit ignorée.',
        );
        $this->assertSame(0, $retabli['stale_events']);
    }

    public function test_le_retour_au_vert_ne_demande_aucun_vidage_de_cache_manuel(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);

        $this->insertStaleOutboxEvents(11);
        $this->assertSame('warn', $this->sante()['checks']['sync']['status']);

        $this->workerRattrapeLeRetard();

        // Aucun Cache::flush() ici — c'est tout l'intérêt du test.
        $this->assertSame(
            'ok',
            $this->sante()['checks']['sync']['status'],
            'L\'état dégradé ne doit pas rester épinglé par un cache après rattrapage.',
        );
    }

    public function test_l_etat_degrade_ne_reste_pas_colle_sur_plusieurs_lectures(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);

        $this->insertStaleOutboxEvents(11);
        // Plusieurs lectures pendant la panne : c'est là qu'un cache se serait rempli.
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame('warn', $this->sante()['checks']['sync']['status']);
        }

        $this->workerRattrapeLeRetard();

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(
                'ok',
                $this->sante()['checks']['sync']['status'],
                'Trois lectures pendant la panne ne doivent pas figer le rouge.',
            );
        }
    }

    public function test_un_rattrapage_partiel_sous_le_seuil_repasse_au_vert(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);

        $this->insertStaleOutboxEvents(15);
        $this->assertSame('warn', $this->sante()['checks']['sync']['status']);

        // Le worker traite 10 des 15 : il en reste 5, sous le seuil de 10.
        $ids = DB::table('domain_events')->whereNull('dispatched_at')->orderBy('id')->limit(10)->pluck('id');
        DB::table('domain_events')->whereIn('id', $ids)->update(['dispatched_at' => now()]);

        $apres = $this->sante();
        $this->assertSame('ok', $apres['checks']['sync']['status'], 'Sous le seuil, le retard n\'est plus une alerte.');
        $this->assertSame(5, $apres['stale_events']);
    }

    public function test_le_message_reste_actionnable_pendant_la_panne(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);
        $this->insertStaleOutboxEvents(11);

        $sync = $this->sante()['checks']['sync'];

        // Un caissier n'a pas à deviner : le message doit dire ce qui se passe, en français.
        $this->assertNotEmpty($sync['message'] ?? '', 'La panne doit produire un message, pas un statut nu.');
        $this->assertDoesNotMatchRegularExpression(
            '/[A-Za-z_]+\.[A-Za-z_]+\.[A-Za-z_]+/',
            (string) ($sync['message'] ?? ''),
            'Le message ne doit pas exposer une clé de traduction brute au comptoir.',
        );
    }
}
