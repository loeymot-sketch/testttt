<?php

namespace Tests\Feature\Sync;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [NUIT-A 2026-07-03 — P2 alarme sync désensibilisée] Le signal « worker down » (staleCount) NE DOIT PAS
 * être gonflé par les orphelins terminaux (attempts >= 5 pending, jamais repris par aucune lane). Sinon
 * l'unique alarme de panne-synchro reste en FAILURE permanent → fatigue d'alerte → une vraie panne worker
 * masquée. Les terminaux sont une dimension DEAD-LETTER distincte.
 */
class OutboxMonitorDeadLetterTest extends TestCase
{
    use RefreshDatabase;

    private function seedEvent(int $attempts, int $ageMinutes): void
    {
        $ts = now()->subMinutes($ageMinutes);
        DB::table('domain_events')->insert([
            'event_type' => 'TestEvent',
            'aggregate_type' => 'App\\Models\\Order',
            'aggregate_id' => 1,
            'payload' => json_encode(['x' => 1]),
            'occurred_at' => $ts,
            'attempts' => $attempts,
            'dispatched_at' => null,
            'created_at' => $ts,
            'updated_at' => $ts,
        ]);
    }

    /** @test */
    public function les_orphelins_terminaux_ne_declenchent_pas_le_signal_worker_down(): void
    {
        // 15 dead-letter (attempts=5, pending, 1h) — bien au-dessus du threshold 10.
        for ($i = 0; $i < 15; $i++) {
            $this->seedEvent(5, 60);
        }

        // Le worker-down (staleCount) doit rester à 0 (tous exclus), donc le message annonce
        // « 0 undispatched retryable » — la panne worker N'est PAS faussement signalée.
        $this->artisan('foodking:outbox:monitor', ['--threshold' => 10, '--stale-after' => 30])
            ->expectsOutputToContain('0 undispatched retryable events')
            ->assertExitCode(1); // FAILURE, mais via la dimension dead-letter, pas worker-down
    }

    /** @test */
    public function les_events_retryables_recents_declenchent_bien_le_signal_worker_down(): void
    {
        // 15 retryables (attempts=1, pending, 1h) = vrai symptôme worker en panne.
        for ($i = 0; $i < 15; $i++) {
            $this->seedEvent(1, 60);
        }

        $this->artisan('foodking:outbox:monitor', ['--threshold' => 10, '--stale-after' => 30])
            ->expectsOutputToContain('15 undispatched retryable events')
            ->assertExitCode(1);
    }

    /** @test */
    public function pipeline_sain_retourne_ok(): void
    {
        $this->artisan('foodking:outbox:monitor', ['--threshold' => 10, '--stale-after' => 30])
            ->expectsOutputToContain('[OK]')
            ->assertExitCode(0);
    }
}
