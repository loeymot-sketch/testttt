<?php

namespace Tests\Feature\Fiscal;

use App\Console\Commands\FiscalVerifyImmutabilityTriggersCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MysqlOnly;
use Tests\TestCase;

/**
 * [NF525 operational safety net — 2026-07-07]
 *
 * Covers `fiscal:verify-immutability-triggers`, the command that catches a
 * SILENT production gap: a MySQL database whose immutability triggers are
 * absent (dump imported without triggers) even though the `migrations`
 * ledger claims them installed. Verified against the live `foodking_e2e`
 * database where `SHOW TRIGGERS` returned 0 rows.
 *
 * Two layers of coverage:
 *   1. Pure logic (driver-agnostic, runs on the SQLite CI default): the
 *      canonical expected list + the missing-diff detection — the fail path
 *      itself cannot be exercised on SQLite, so we unit-test the comparison.
 *   2. Behavioural (MySQL-only, skips on SQLite): the command passes when
 *      RefreshDatabase installs every trigger, and FAILS loud (exit 1) when
 *      a trigger is dropped out from under it.
 */
class FiscalVerifyImmutabilityTriggersCommandTest extends TestCase
{
    use MysqlOnly;
    use RefreshDatabase;

    // ---------------------------------------------------------------------
    // Layer 1 — pure logic (runs on every driver, incl. SQLite CI default).
    // ---------------------------------------------------------------------

    public function test_expected_list_is_the_eight_mysql_fiscal_triggers(): void
    {
        $expected = FiscalVerifyImmutabilityTriggersCommand::EXPECTED_TRIGGERS;

        // [AUDIT-BASELINE-SYNC 2026-07-11] Baseline élargie de 8 → 9 : le HEAL du jour a ajouté
        // `order_items_composition_snapshot_no_update` (immuabilité NF525 du snapshot de composition,
        // migration présente + EXPECTED_TRIGGERS mis à jour) mais cette sentinelle hardcodait encore 8.
        $this->assertCount(
            9,
            $expected,
            'The canonical MySQL immutability set must be exactly 9 triggers.'
        );

        $this->assertSame(
            [
                'audit_logs_no_delete',
                'audit_logs_no_update',
                'z_reports_no_delete',
                'cash_movements_no_delete',
                'cash_drawer_sessions_no_delete',
                'order_payments_no_delete',
                'stock_movements_no_delete',
                'stock_movements_no_update',
                'order_items_composition_snapshot_no_update',
            ],
            array_keys($expected),
            'Expected trigger set drifted — update only after verifying the migration bodies.'
        );

        // delivery_boy_cash_* triggers are SQLite-only (their migration returns
        // early on non-sqlite) so they must NOT be asserted on MySQL.
        $this->assertArrayNotHasKey('delivery_boy_cash_movements_no_delete', $expected);
        $this->assertArrayNotHasKey('delivery_boy_cash_sessions_no_delete', $expected);
    }

    public function test_diff_missing_flags_an_absent_trigger(): void
    {
        $all = array_keys(FiscalVerifyImmutabilityTriggersCommand::EXPECTED_TRIGGERS);

        // Present-set missing exactly one expected trigger.
        $present = array_values(array_filter(
            $all,
            static fn ($name) => $name !== 'z_reports_no_delete'
        ));

        $missing = FiscalVerifyImmutabilityTriggersCommand::diffMissing($present);

        $this->assertSame(
            ['z_reports_no_delete' => 'z_reports'],
            $missing,
            'diffMissing must name the single absent trigger and its table.'
        );
    }

    public function test_diff_missing_is_empty_when_all_present(): void
    {
        $all = array_keys(FiscalVerifyImmutabilityTriggersCommand::EXPECTED_TRIGGERS);

        // Extra, unrelated triggers present on the DB must not confuse the diff.
        $present = array_merge($all, ['some_other_trigger', 'delivery_boy_cash_sessions_no_delete']);

        $this->assertSame(
            [],
            FiscalVerifyImmutabilityTriggersCommand::diffMissing($present),
            'A superset of the expected triggers is a PASS (no missing).'
        );
    }

    public function test_diff_missing_flags_all_when_none_present(): void
    {
        // The exact production catastrophe: SHOW TRIGGERS returns 0 rows.
        $missing = FiscalVerifyImmutabilityTriggersCommand::diffMissing([]);

        // [AUDIT-BASELINE-SYNC 2026-07-11] 9 depuis l'ajout du trigger composition_snapshot.
        $this->assertCount(
            9,
            $missing,
            'An empty database must report ALL 9 immutability triggers missing — this is the silent-gap detector.'
        );
    }

    public function test_command_skips_cleanly_on_sqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This assertion pins the SQLite skip path; running on a non-SQLite driver.');
        }

        $this->artisan('fiscal:verify-immutability-triggers')
            ->expectsOutputToContain('SKIP')
            ->assertExitCode(0);
    }

    // ---------------------------------------------------------------------
    // Layer 2 — behavioural (MySQL-only, skips on the SQLite CI default).
    // ---------------------------------------------------------------------

    public function test_command_passes_on_mysql_when_all_triggers_present(): void
    {
        $this->requiresMysqlDriver();

        // RefreshDatabase replayed every immutability migration on this
        // ephemeral MySQL schema, so all 8 triggers must be present.
        $this->artisan('fiscal:verify-immutability-triggers')
            ->expectsOutputToContain('IMMUTABILITY TRIGGERS OK')
            ->assertExitCode(0);
    }

    public function test_command_fails_on_mysql_when_a_trigger_is_dropped(): void
    {
        $this->requiresMysqlDriver();

        // Simulate the dump-without-triggers gap: drop one immutability trigger.
        DB::unprepared('DROP TRIGGER IF EXISTS order_payments_no_delete');

        $this->artisan('fiscal:verify-immutability-triggers')
            ->expectsOutputToContain('NF525 IMMUTABILITY BREACH')
            ->expectsOutputToContain('order_payments_no_delete')
            ->assertExitCode(1);
    }
}
