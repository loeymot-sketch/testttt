<?php

namespace Tests\Feature\Fiscal;

use App\Console\Commands\FiscalInstallImmutabilityTriggersCommand;
use App\Console\Commands\FiscalVerifyImmutabilityTriggersCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MysqlOnly;
use Tests\TestCase;

/**
 * [NF525 operational remediation — 2026-07-07]
 *
 * Covers `fiscal:install-immutability-triggers`, the idempotent installer that
 * repairs the SILENT production gap where a MySQL database has ZERO immutability
 * triggers (dump imported without --triggers) even though the `migrations`
 * ledger claims them installed (so `migrate` is a no-op and cannot fix it).
 * Verified against the live `foodking_e2e` database (`SHOW TRIGGERS` = 0 rows).
 *
 * Two layers:
 *   1. Pure logic (driver-agnostic, runs on the SQLite CI default): the
 *      installer's definition set targets EXACTLY the 8 verified triggers.
 *   2. Behavioural (MySQL-only): after DROPPING all triggers, the installer
 *      re-creates all 8, the verifier passes, and re-running the installer is a
 *      safe idempotent no-op.
 */
class FiscalInstallImmutabilityTriggersCommandTest extends TestCase
{
    use MysqlOnly;
    use RefreshDatabase;

    // ── Layer 1 — pure logic (every driver incl. SQLite CI default) ───────────

    public function test_installer_targets_exactly_the_nine_verified_triggers(): void
    {
        $defs = FiscalInstallImmutabilityTriggersCommand::triggerDefinitions();

        // [HEAL 2026-07-11] 8 → 9 : ajout de order_items_composition_snapshot_no_update (défense
        // runtime NF525 §8 qui manquait en base — sa migration se marquait exécutée sans créer le
        // trigger). L'égalité install==verify ci-dessous garantit que les deux commandes ne dérivent pas.
        // [P1-1 2026-07-18] 9 → 10 : ajout de orders_no_delete_when_fiscalized (ferme la réutilisation
        // de numéros fiscaux après hard-delete d'un order fiscalisé).
        $this->assertCount(10, $defs, 'The installer must define exactly the 10 canonical MySQL immutability triggers.');

        // The installer's name+table set MUST equal the verifier's expected set
        // (locked together so the two commands never drift).
        $installerMap = array_map(static fn ($pair) => $pair[0], $defs); // name => table
        $this->assertSame(
            FiscalVerifyImmutabilityTriggersCommand::EXPECTED_TRIGGERS,
            $installerMap,
            'Installer trigger→table map must match the verifier EXPECTED_TRIGGERS exactly.'
        );

        // Each definition must be a genuine CREATE TRIGGER body with a SIGNAL guard.
        foreach ($defs as $name => [$table, $sql]) {
            $this->assertStringContainsString("CREATE TRIGGER {$name}", $sql, "SQL for {$name} must create the named trigger.");
            $this->assertStringContainsString($table, $sql, "SQL for {$name} must target table {$table}.");
            $this->assertStringContainsString("SIGNAL SQLSTATE '45000'", $sql, "SQL for {$name} must raise the NF525 SIGNAL guard.");
        }
    }

    public function test_command_skips_cleanly_on_sqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This assertion pins the SQLite skip path; running on a non-SQLite driver.');
        }

        $this->artisan('fiscal:install-immutability-triggers')
            ->expectsOutputToContain('SKIP')
            ->assertExitCode(0);
    }

    // ── Layer 2 — behavioural (MySQL-only) ────────────────────────────────────

    public function test_installer_recreates_all_triggers_after_they_are_dropped(): void
    {
        $this->requiresMysqlDriver();

        // Reproduce the exact production catastrophe: a database with NO triggers.
        foreach (array_keys(FiscalVerifyImmutabilityTriggersCommand::EXPECTED_TRIGGERS) as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        }
        $this->assertNotEmpty(
            FiscalVerifyImmutabilityTriggersCommand::diffMissing($this->presentTriggers()),
            'Precondition: triggers must be absent before the installer runs.'
        );

        // Install → all 8 present, verifier green.
        $this->artisan('fiscal:install-immutability-triggers')
            ->expectsOutputToContain('IMMUTABILITY TRIGGERS INSTALLED')
            ->assertExitCode(0);

        $this->assertSame(
            [],
            FiscalVerifyImmutabilityTriggersCommand::diffMissing($this->presentTriggers()),
            'After install, every expected immutability trigger must be present.'
        );

        $this->artisan('fiscal:verify-immutability-triggers')
            ->expectsOutputToContain('IMMUTABILITY TRIGGERS OK')
            ->assertExitCode(0);
    }

    public function test_installer_is_idempotent_on_repeated_runs(): void
    {
        $this->requiresMysqlDriver();

        // Triggers already present (RefreshDatabase installed them). Re-running
        // the installer must succeed without error (DROP IF EXISTS + CREATE).
        $this->artisan('fiscal:install-immutability-triggers')->assertExitCode(0);
        $this->artisan('fiscal:install-immutability-triggers')->assertExitCode(0);

        $this->assertSame(
            [],
            FiscalVerifyImmutabilityTriggersCommand::diffMissing($this->presentTriggers()),
            'Idempotent re-run must leave all 8 triggers present.'
        );
    }

    /**
     * @return array<int, string>
     */
    private function presentTriggers(): array
    {
        $rows = DB::select(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'
        );

        return array_map(static fn ($row) => (string) $row->TRIGGER_NAME, $rows);
    }
}
