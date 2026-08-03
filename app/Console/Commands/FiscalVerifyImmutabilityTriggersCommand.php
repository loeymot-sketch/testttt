<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [NF525 operational safety net — Immutability triggers presence check 2026-07-07]
 *
 * PROBLEM (found by ultra-audit Round 1, verified against the live
 * `foodking_e2e` MySQL database — `SHOW TRIGGERS` returned 0 rows):
 *   The immutability migrations (audit_logs / z_reports / cash_movements /
 *   cash_drawer_sessions / order_payments / stock_movements) are recorded as
 *   RUN in the `migrations` table, yet ZERO triggers exist on the database.
 *   The migration CODE is correct; the risk is OPERATIONAL and SILENT: a
 *   database provisioned by importing a `mysqldump` WITHOUT `--triggers`
 *   (the mysqldump default DOES export triggers, but a filtered/hand-rolled
 *   dump or a schema-only sync can drop them) loses every NF525 DELETE/UPDATE
 *   guard while the `migrations` ledger still claims they are installed.
 *   Nothing in the codebase VERIFIED their presence — until this command.
 *
 * WHAT IT DOES:
 *   - Lists the canonical set of immutability triggers that MUST exist on a
 *     production MySQL/MariaDB box.
 *   - Introspects `information_schema.TRIGGERS` for the active database.
 *   - FAILS LOUD (exit 1 + explicit per-trigger report) if any is missing.
 *   - Skips cleanly (exit 0) on SQLite — the PHPUnit default driver — because
 *     the SQLite trigger set differs (see note on delivery_boy_cash_* below)
 *     and this is a production-provisioning verification, not a unit check.
 *
 * WHEN TO RUN (operator doc):
 *   - After EVERY provisioning / dump-import / DB restore on the prod box.
 *   - As a post-deploy smoke step alongside `fiscal:verify-chain --all`.
 *   Remediation when it fails: `php artisan migrate` reinstalls the triggers,
 *   OR re-import the dump with `mysqldump --triggers --routines`.
 *
 * This command only READS the database state (SELECT on information_schema).
 * It never touches the FROZEN fiscal services (CLAUDE.md §7) nor any data.
 *
 * Exit codes (aligned with fiscal:verify-chain for monitoring routing):
 *   0 = every expected trigger present (or skipped cleanly on non-MySQL).
 *   1 = one or more immutability triggers MISSING (NF525 breach).
 *   3 = execution error (DB outage / information_schema unreadable).
 */
class FiscalVerifyImmutabilityTriggersCommand extends Command
{
    protected $signature = 'fiscal:verify-immutability-triggers'
        . ' {--json : Emit a machine-readable JSON summary instead of human-readable lines}';

    protected $description = 'NF525 operational check — verify the fiscal/audit immutability triggers are actually present on the live MySQL database (catches dump-import-without-triggers gaps).';

    /**
     * Canonical map of NF525 immutability triggers that MUST exist on a
     * MySQL/MariaDB production database: trigger name => expected table.
     *
     * These 8 triggers are created UNCONDITIONALLY on MySQL by their
     * migrations (given the table exists) — verified by reading each
     * migration body:
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
     * DELIBERATELY EXCLUDED: delivery_boy_cash_movements_no_delete and
     * delivery_boy_cash_sessions_no_delete — their migration
     * (2026_05_18_120300_add_delivery_boy_cash_no_delete_triggers_sqlite.php)
     * is SQLite-ONLY (`if ($driver !== 'sqlite') { return; }`) and creates
     * NOTHING on MySQL. Asserting them here would be a false failure.
     *
     * [HEAL 2026-07-11] order_items_composition_snapshot_no_update EST désormais vérifié :
     * c'est la défense runtime NF525 §8 (OrderItem.php:33) qui manquait en base parce que sa
     * migration se marquait « exécutée » sans créer le trigger ET que ce verify l'excluait —
     * l'audit a prouvé un UPDATE SQL brut du snapshot fiscal scellé non bloqué. Il est maintenant
     * installé par FiscalInstallImmutabilityTriggersCommand et asserté ici.
     *
     * [P1-1 2026-07-18] orders_no_delete_when_fiscalized ajouté (9 → 10) : ferme la réutilisation
     * de numéros fiscaux après HARD-delete d'un order fiscalisé (FiscalSequenceService::next() =
     * MAX(fiscal_sequence_no)+1, un hard-delete faisait redescendre le MAX → réémission). Créé par
     * 2026_07_18_130000 (MySQL SIGNAL, condition OLD.fiscal_sequence_no NON NULL) et ré-installable
     * par FiscalInstallImmutabilityTriggersCommand.
     */
    public const EXPECTED_TRIGGERS = [
        'audit_logs_no_delete' => 'audit_logs',
        'audit_logs_no_update' => 'audit_logs',
        'z_reports_no_delete' => 'z_reports',
        'cash_movements_no_delete' => 'cash_movements',
        'cash_drawer_sessions_no_delete' => 'cash_drawer_sessions',
        'order_payments_no_delete' => 'order_payments',
        'stock_movements_no_delete' => 'stock_movements',
        'stock_movements_no_update' => 'stock_movements',
        'order_items_composition_snapshot_no_update' => 'order_items',
        'orders_no_delete_when_fiscalized' => 'orders',
    ];

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            $message = sprintf(
                'SKIP: immutability triggers use MySQL SIGNAL syntax; current driver = %s. '
                . 'Run this check on the production MySQL/MariaDB box after provisioning.',
                $driver
            );

            if ($this->option('json')) {
                $this->line(json_encode([
                    'ok' => true,
                    'skipped' => true,
                    'driver' => $driver,
                    'expected' => count(self::EXPECTED_TRIGGERS),
                    'missing' => [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->info($message);
            }

            return self::SUCCESS;
        }

        try {
            $present = $this->presentTriggerNames();
        } catch (\Throwable $e) {
            $this->error('Trigger introspection FAILED to execute: ' . $e->getMessage());

            return 3; // execution error — distinct from a genuine missing-trigger breach
        }

        $missing = self::diffMissing($present);
        $presentCount = count(self::EXPECTED_TRIGGERS) - count($missing);

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => empty($missing),
                'skipped' => false,
                'driver' => $driver,
                'expected' => count(self::EXPECTED_TRIGGERS),
                'present' => $presentCount,
                'missing' => array_keys($missing),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (! empty($missing)) {
            Log::channel('observability')->error('fiscal.immutability_triggers.missing', [
                'event' => 'fiscal.immutability_triggers.missing',
                'missing' => array_keys($missing),
                'expected' => count(self::EXPECTED_TRIGGERS),
                'present' => $presentCount,
            ]);

            if (! $this->option('json')) {
                $this->error(sprintf(
                    'NF525 IMMUTABILITY BREACH: %d/%d immutability trigger(s) MISSING on this MySQL database.',
                    count($missing),
                    count(self::EXPECTED_TRIGGERS)
                ));
                foreach ($missing as $trigger => $table) {
                    $this->line(sprintf('  - %s (expected on %s)', $trigger, $table));
                }
                $this->line('');
                $this->line('Cause probable : base provisionnee par import de dump SANS les triggers.');
                $this->line('Remediation : `php artisan migrate` reinstalle les triggers, OU reimporter');
                $this->line('le dump avec `mysqldump --triggers --routines`. Puis relancer ce check.');
            }

            return self::FAILURE; // exit 1 — NF525 breach
        }

        if (! $this->option('json')) {
            $this->info(sprintf(
                'IMMUTABILITY TRIGGERS OK — %d/%d present on this MySQL database.',
                $presentCount,
                count(self::EXPECTED_TRIGGERS)
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Names of every trigger currently installed on the active database
     * schema. Uses information_schema.TRIGGERS scoped to DATABASE() — the
     * same set `SHOW TRIGGERS` returns, but easier to scope + test.
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

    /**
     * Pure comparison: expected triggers absent from the given present-set.
     *
     * Extracted + static so the detection contract is unit-testable on ANY
     * driver (SQLite CI) without a live MySQL information_schema — critical
     * because the fail path itself cannot be exercised on the default runner.
     *
     * @param  array<int, string>  $presentNames
     * @return array<string, string>  missing trigger name => expected table
     */
    public static function diffMissing(array $presentNames): array
    {
        $present = array_flip($presentNames);

        return array_filter(
            self::EXPECTED_TRIGGERS,
            static fn ($table, $trigger) => ! isset($present[$trigger]),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
