<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * [NF525 operational remediation — Immutability triggers installer 2026-07-07]
 *
 * COMPANION to `fiscal:verify-immutability-triggers`.
 *
 * PROBLEM (found by ultra-audit Round 1, verified against the live
 * `foodking_e2e` MySQL database — `SHOW TRIGGERS` returned 0 rows):
 *   The immutability migrations are recorded as RUN in the `migrations` table,
 *   yet ZERO triggers exist on the database — a database provisioned by
 *   importing a `mysqldump` WITHOUT the triggers loses every NF525
 *   DELETE/UPDATE guard while the `migrations` ledger still claims them
 *   installed. `php artisan migrate` will NOT re-create them (the migration
 *   rows are already present → migrate is a no-op), so there was no clean,
 *   idempotent way to (re)install the guards on such a box — until this
 *   command.
 *
 * WHAT IT DOES (MySQL/MariaDB only):
 *   For EACH of the 8 canonical NF525 immutability triggers it runs
 *   `DROP TRIGGER IF EXISTS <name>` then re-`CREATE TRIGGER <name> ...` using
 *   the EXACT SQL from the source migrations (single source of truth — the
 *   bodies below are copied verbatim from the migration files referenced in
 *   FiscalVerifyImmutabilityTriggersCommand::EXPECTED_TRIGGERS). This is
 *   fully IDEMPOTENT: re-running is a safe no-op-equivalent (drop-then-create
 *   converges to the same state every time).
 *
 * SAFETY:
 *   - Only DDL on trigger objects. It NEVER touches fiscal data, never the
 *     FROZEN fiscal services (CLAUDE.md §7), never a row.
 *   - Skips cleanly on SQLite (the PHPUnit default) — the SQLite trigger set
 *     differs and this is a production-provisioning remediation, not a unit
 *     concern.
 *   - Each trigger is only (re)created if its base table exists (mirrors the
 *     Schema::hasTable() guards in the source migrations).
 *
 * WHEN TO RUN (operator doc):
 *   - After EVERY provisioning / dump-import / DB restore on the prod box,
 *     BEFORE going live. The deploy script calls this, then
 *     `fiscal:verify-immutability-triggers` must return VERT (8/8).
 *
 * Exit codes:
 *   0 = every expected trigger installed and verified present (or skipped
 *       cleanly on non-MySQL).
 *   1 = post-install verification still reports a missing trigger (should not
 *       happen; indicates a CREATE was rejected — e.g. missing base table).
 *   3 = execution error (DB outage / DDL rejected).
 */
class FiscalInstallImmutabilityTriggersCommand extends Command
{
    protected $signature = 'fiscal:install-immutability-triggers'
        . ' {--json : Emit a machine-readable JSON summary instead of human-readable lines}';

    protected $description = 'NF525 operational remediation — (re)install the fiscal/audit immutability triggers on the live MySQL database, idempotently (DROP IF EXISTS + CREATE). Fixes the dump-import-without-triggers gap that `migrate` cannot repair.';

    /**
     * Canonical MySQL/MariaDB immutability triggers.
     *
     * name => [table, CREATE-TRIGGER SQL] — the SQL bodies are copied VERBATIM
     * from the source migrations so there is a single, drift-free definition:
     *   - audit_logs_no_delete / audit_logs_no_update
     *       → 2026_04_22_000002_create_audit_logs_table.php
     *   - z_reports_no_delete
     *       → 2026_05_09_160000_add_z_reports_delete_trigger_immutability.php
     *   - cash_movements_no_delete / cash_drawer_sessions_no_delete
     *     / order_payments_no_delete
     *       → 2026_05_10_010000_secure_fiscal_audit_trail_immutability.php
     *   - stock_movements_no_delete / stock_movements_no_update
     *       → 2026_05_18_140000_add_stock_movements_immutability_triggers.php
     *
     * The trigger names + tables MUST match
     * FiscalVerifyImmutabilityTriggersCommand::EXPECTED_TRIGGERS exactly (a
     * test locks the two sets together).
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function triggerDefinitions(): array
    {
        // Ordering mirrors FiscalVerifyImmutabilityTriggersCommand::EXPECTED_TRIGGERS
        // (a test locks the two sequences identical). Install order is otherwise
        // irrelevant (each is an independent DROP IF EXISTS + CREATE).
        return [
            'audit_logs_no_delete' => ['audit_logs', <<<'SQL'
                CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'audit_logs is INSERT-only (NF525 / POS-9.4.3)';
                END
                SQL],
            'audit_logs_no_update' => ['audit_logs', <<<'SQL'
                CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'audit_logs is INSERT-only (NF525 / POS-9.4.3)';
                END
                SQL],
            'z_reports_no_delete' => ['z_reports', <<<'SQL'
                CREATE TRIGGER z_reports_no_delete
                BEFORE DELETE ON z_reports
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'z_reports is immutable post-close (NF525 / POS-9.4.6) — DELETE forbidden';
                END
                SQL],
            'cash_movements_no_delete' => ['cash_movements', <<<'SQL'
                CREATE TRIGGER cash_movements_no_delete
                BEFORE DELETE ON cash_movements
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'cash_movements is immutable (NF525 / P0-FIX-4) — DELETE forbidden';
                END
                SQL],
            'cash_drawer_sessions_no_delete' => ['cash_drawer_sessions', <<<'SQL'
                CREATE TRIGGER cash_drawer_sessions_no_delete
                BEFORE DELETE ON cash_drawer_sessions
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'cash_drawer_sessions is immutable (NF525 / P0-FIX-4) — DELETE forbidden';
                END
                SQL],
            'order_payments_no_delete' => ['order_payments', <<<'SQL'
                CREATE TRIGGER order_payments_no_delete
                BEFORE DELETE ON order_payments
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'order_payments is immutable (NF525 / P0-FIX-4) — DELETE forbidden';
                END
                SQL],
            'stock_movements_no_delete' => ['stock_movements', <<<'SQL'
                CREATE TRIGGER stock_movements_no_delete
                BEFORE DELETE ON stock_movements
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'stock_movements is append-only (Foundation F-6 P0 / NF525-aligned) - DELETE forbidden';
                END
                SQL],
            'stock_movements_no_update' => ['stock_movements', <<<'SQL'
                CREATE TRIGGER stock_movements_no_update
                BEFORE UPDATE ON stock_movements
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'stock_movements is append-only (Foundation F-6 P0 / NF525-aligned) - UPDATE forbidden';
                END
                SQL],
            // [HEAL 2026-07-11] order_items.composition_snapshot immutability — SQL VERBATIM de
            // 2026_05_24_040211_add_composition_snapshot_immutability_trigger.php. Cette migration
            // se marquait « exécutée » SANS créer le trigger (early-return hasTable('order_items')
            // avant que la table existe), et l'installer/verify l'excluaient → défense runtime NF525
            // §8 absente en base (snapshot fiscal mutable par SQL brut). L'ajouter ici le restaure
            // idempotemment à chaque `fiscal:install-immutability-triggers` (donc à chaque deploy).
            'order_items_composition_snapshot_no_update' => ['order_items', <<<'SQL'
                CREATE TRIGGER order_items_composition_snapshot_no_update
                BEFORE UPDATE ON order_items
                FOR EACH ROW
                BEGIN
                    IF NEW.composition_snapshot IS NOT NULL
                       AND OLD.composition_snapshot IS NOT NULL
                       AND NEW.composition_snapshot != OLD.composition_snapshot THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'NF525: composition_snapshot is immutable after creation (J2-HEAL-06)';
                    END IF;
                    IF NEW.composition_snapshot IS NULL
                       AND OLD.composition_snapshot IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'NF525: composition_snapshot cannot be nulled after creation (J2-HEAL-06)';
                    END IF;
                END
                SQL],
        ];
    }

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            $message = sprintf(
                'SKIP: immutability triggers use MySQL SIGNAL syntax; current driver = %s. '
                . 'Run this installer on the production MySQL/MariaDB box after provisioning.',
                $driver
            );

            if ($this->option('json')) {
                $this->line(json_encode([
                    'ok' => true,
                    'skipped' => true,
                    'driver' => $driver,
                    'installed' => [],
                    'skipped_missing_table' => [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->info($message);
            }

            return self::SUCCESS;
        }

        $installed = [];
        $skippedNoTable = [];

        try {
            foreach (self::triggerDefinitions() as $name => [$table, $sql]) {
                // Idempotent: always drop-then-create so re-runs converge.
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}");

                if (! Schema::hasTable($table)) {
                    // Mirrors the source migrations' Schema::hasTable() guards:
                    // do not attempt to attach a trigger to an absent table.
                    $skippedNoTable[$name] = $table;
                    continue;
                }

                DB::unprepared($sql);
                $installed[$name] = $table;
            }
        } catch (\Throwable $e) {
            $this->error('Trigger (re)installation FAILED: ' . $e->getMessage());

            return 3;
        }

        // Post-install verification: reuse the verifier's authoritative
        // expected-set + diff so the installer proves its own success.
        $present = $this->presentTriggerNames();
        $missing = FiscalVerifyImmutabilityTriggersCommand::diffMissing($present);

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => empty($missing),
                'skipped' => false,
                'driver' => $driver,
                'installed' => array_keys($installed),
                'skipped_missing_table' => array_keys($skippedNoTable),
                'missing_after_install' => array_keys($missing),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (! empty($missing)) {
            Log::channel('observability')->error('fiscal.immutability_triggers.install_incomplete', [
                'event' => 'fiscal.immutability_triggers.install_incomplete',
                'installed' => array_keys($installed),
                'skipped_missing_table' => array_keys($skippedNoTable),
                'missing_after_install' => array_keys($missing),
            ]);

            if (! $this->option('json')) {
                $this->error(sprintf(
                    'INSTALL INCOMPLETE: %d immutability trigger(s) still MISSING after installation.',
                    count($missing)
                ));
                foreach ($missing as $trigger => $table) {
                    $this->line(sprintf('  - %s (expected on %s — base table absent?)', $trigger, $table));
                }
            }

            return self::FAILURE;
        }

        if (! $this->option('json')) {
            $this->info(sprintf(
                'IMMUTABILITY TRIGGERS INSTALLED — %d/%d present on this MySQL database (idempotent).',
                count(FiscalVerifyImmutabilityTriggersCommand::EXPECTED_TRIGGERS),
                count(FiscalVerifyImmutabilityTriggersCommand::EXPECTED_TRIGGERS)
            ));
            if (! empty($skippedNoTable)) {
                $this->warn('Skipped (base table absent on this database):');
                foreach ($skippedNoTable as $trigger => $table) {
                    $this->line(sprintf('  - %s (table %s missing)', $trigger, $table));
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * Names of every trigger currently installed on the active DATABASE()
     * schema. Same set `SHOW TRIGGERS` returns.
     *
     * @return array<int, string>
     */
    private function presentTriggerNames(): array
    {
        $rows = DB::select(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'
        );

        return array_map(static fn ($row) => (string) $row->TRIGGER_NAME, $rows);
    }
}
