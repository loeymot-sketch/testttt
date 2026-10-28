<?php

namespace Tests\Feature\Observability;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * [G2 2026-09-03 · T2.4 · défaut V-05] L'audit de la purge attestait un fait NON accompli.
 *
 * `SyncOverviewController::outboxDrainFailed` écrivait la ligne `audit_logs`
 * `outbox.drain` avec `'deleted' => count($ids)` AVANT d'exécuter le `DELETE`. Trois
 * conséquences, toutes silencieuses :
 *
 *   1. un `DELETE` partiel (des candidats disparus entre la sélection et la suppression —
 *      cron `queue:prune-failed`, autre opérateur) laissait une ligne d'audit affirmant
 *      avoir supprimé 5 lignes là où 2 l'ont été ;
 *   2. un `DELETE` en échec laissait une ligne d'audit affirmant une suppression qui n'a
 *      jamais eu lieu ;
 *   3. cette ligne est IMMUABLE et SIGNÉE EN CHAÎNE (HMAC, `audit_logs`) : on ne peut pas
 *      la corriger a posteriori. Un audit NF525 qui ment est pire qu'un audit absent,
 *      parce qu'il est opposable.
 *
 * La règle : l'audit n'atteste QUE le fait accompli. Écriture APRÈS le `DELETE`, avec le
 * nombre de lignes réellement supprimées — et si l'audit ne peut pas s'écrire, la
 * suppression est annulée (invariant préexistant `test_sans_audit_possible_la_purge_ne_
 * supprime_rien`, conservé). Chaîne HMAC : on n'écrit qu'en AJOUT, jamais de correction.
 */
class OutboxDrainAuditApresSuppressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Storage::fake('local');
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        return $u;
    }

    /** Cinq travaux outbox en échec, tous purgeables (plus vieux que 24 h). */
    private function cinqCandidats(): array
    {
        $uuids = [];
        for ($i = 1; $i <= 5; $i++) {
            $uuid = sprintf('11111111-0000-0000-0000-00000000000%d', $i);
            $uuids[] = $uuid;
            DB::table('failed_jobs')->insert([
                'uuid' => $uuid,
                'connection' => 'database',
                'queue' => 'high',
                'payload' => json_encode(['uuid' => $uuid, 'displayName' => DispatchDomainEventsJob::class, 'data' => []]),
                'exception' => 'RuntimeException: boom',
                'failed_at' => now()->subDays(3),
            ]);
        }

        return $uuids;
    }

    /**
     * Injecte un comportement juste AVANT le `DELETE` du contrôleur, sur la connexion
     * réelle — pas un mock du contrôleur. C'est la fenêtre exacte où la réalité peut
     * diverger de la sélection.
     */
    private function avantLaSuppression(\Closure $action): void
    {
        $dejaFait = false;
        DB::connection()->beforeExecuting(function ($query, $bindings, $connection) use (&$dejaFait, $action) {
            if ($dejaFait) {
                return;
            }
            $sql = strtolower($query);
            if (! str_contains($sql, 'delete from') || ! str_contains($sql, 'failed_jobs')) {
                return;
            }
            $dejaFait = true;
            $action($connection);
        });
    }

    private function lignesAudit(): \Illuminate\Support\Collection
    {
        return DB::table('audit_logs')->where('action', 'outbox.drain')->get();
    }

    public function test_une_suppression_partielle_est_auditee_pour_ce_qu_elle_a_reellement_supprime(): void
    {
        $uuids = $this->cinqCandidats();
        // Trois des cinq candidats disparaissent entre la sélection et le `DELETE`.
        $this->avantLaSuppression(function ($connection) use ($uuids) {
            $connection->table('failed_jobs')->whereIn('uuid', array_slice($uuids, 0, 3))->delete();
        });

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/observability/outbox/drain-failed', ['older_than_hours' => 24])
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        $lignes = $this->lignesAudit();
        $this->assertCount(1, $lignes, 'une purge = une ligne d\'audit');
        $payload = json_decode((string) $lignes->first()->payload, true);
        $this->assertSame(
            2,
            (int) ($payload['deleted'] ?? -1),
            'l\'audit atteste 2 suppressions réelles, pas les 5 candidats sélectionnés'
        );
    }

    public function test_une_suppression_qui_echoue_ne_laisse_aucune_ligne_affirmant_la_suppression(): void
    {
        $uuids = $this->cinqCandidats();
        $this->avantLaSuppression(function () {
            throw new \RuntimeException('disque plein');
        });

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/observability/outbox/drain-failed', ['older_than_hours' => 24])
            ->assertStatus(500);

        $this->assertCount(
            0,
            $this->lignesAudit(),
            'aucune ligne immuable ne doit affirmer une suppression qui n\'a pas eu lieu'
        );

        foreach ($uuids as $uuid) {
            $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
        }
    }

    public function test_une_suppression_qui_n_atteint_aucune_ligne_ne_pretend_pas_le_contraire(): void
    {
        $uuids = $this->cinqCandidats();
        // Les cinq candidats ont déjà été purgés ailleurs : le `DELETE` porte sur zéro ligne.
        $this->avantLaSuppression(function ($connection) use ($uuids) {
            $connection->table('failed_jobs')->whereIn('uuid', $uuids)->delete();
        });

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/observability/outbox/drain-failed', ['older_than_hours' => 24])
            ->assertOk()
            ->assertJsonPath('deleted', 0);

        foreach ($this->lignesAudit() as $ligne) {
            $payload = json_decode((string) $ligne->payload, true);
            $this->assertSame(
                0,
                (int) ($payload['deleted'] ?? -1),
                'zéro ligne supprimée doit s\'auditer comme zéro'
            );
        }
    }

    public function test_le_cas_nominal_audite_le_compte_exact(): void
    {
        $this->cinqCandidats();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/observability/outbox/drain-failed', ['older_than_hours' => 24])
            ->assertOk()
            ->assertJsonPath('deleted', 5);

        $payload = json_decode((string) $this->lignesAudit()->first()->payload, true);
        $this->assertSame(5, (int) ($payload['deleted'] ?? -1));
        $this->assertCount(5, $payload['failed_job_ids'] ?? [], 'les identifiants restent tracés');
    }

    public function test_l_ecriture_d_audit_reste_un_ajout_jamais_une_correction(): void
    {
        $uuids = $this->cinqCandidats();
        $this->avantLaSuppression(function ($connection) use ($uuids) {
            $connection->table('failed_jobs')->whereIn('uuid', array_slice($uuids, 0, 3))->delete();
        });

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/observability/outbox/drain-failed', ['older_than_hours' => 24])
            ->assertOk();

        // Une seule ligne, écrite une seule fois : pas d'« intention » suivie d'une
        // « correction ». La chaîne HMAC n'autorise que l'ajout.
        $this->assertSame(1, (int) DB::table('audit_logs')->where('action', 'outbox.drain')->count());
        $ligne = $this->lignesAudit()->first();
        $this->assertNotEmpty($ligne->current_hash, 'la ligne reste signée en chaîne');
    }
}
