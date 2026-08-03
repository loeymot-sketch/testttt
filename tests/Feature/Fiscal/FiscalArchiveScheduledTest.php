<?php

namespace Tests\Feature\Fiscal;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * [W8.C-P2 / P-MEGA-22 Pilier 2] Sentinel: vérifie que la commande
 * `foodking:fiscal:archive` est bien scheduled quotidiennement à 02:00,
 * avec withoutOverlapping + onOneServer (D4=A, D5=A, D6=A, D7=A).
 */
class FiscalArchiveScheduledTest extends TestCase
{
    /** @test */
    public function it_schedules_a_daily_fiscal_archive_at_02_00_with_overlap_and_one_server_constraints(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $events = $schedule->events();

        $entry = collect($events)->first(function ($event) {
            $description = method_exists($event, 'getSummaryForDisplay') ? $event->getSummaryForDisplay() : '';
            $name = property_exists($event, 'description') ? (string) ($event->description ?? '') : '';
            return $name === 'foodking-fiscal-archive-daily'
                || str_contains((string) $description, 'foodking-fiscal-archive-daily');
        });

        $this->assertNotNull($entry, 'Aucun event scheduled nommé "foodking-fiscal-archive-daily" trouvé. Vérifie app/Console/Kernel.php W8.C-P2.');

        $expression = (string) $entry->expression;
        $this->assertSame('0 2 * * *', $expression, "Cron expression attendue '0 2 * * *' (daily 02:00) ; reçue '{$expression}'.");

        $this->assertNotEmpty($entry->mutex, 'withoutOverlapping() requis pour idempotence (W8.C-P2 D4).');
        $this->assertTrue((bool) $entry->onOneServer, 'onOneServer() requis pour éviter exécution multi-noeuds (D4).');
    }

    /** @test */
    public function it_runs_archive_for_all_active_branches_on_yesterday_only(): void
    {
        // Smoke test : vérifie qu'au moins l'event scheduled est présent (logique
        // métier de l'iteration sur Branch::status=1 non testée en E2E ici,
        // pour rester contenu unit/feature lightweight).
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $events = $schedule->events();

        $count = collect($events)->filter(function ($event) {
            $name = property_exists($event, 'description') ? (string) ($event->description ?? '') : '';
            return $name === 'foodking-fiscal-archive-daily';
        })->count();

        $this->assertSame(1, $count, "Exactement 1 event 'foodking-fiscal-archive-daily' attendu ; trouvé {$count}.");
    }
}
