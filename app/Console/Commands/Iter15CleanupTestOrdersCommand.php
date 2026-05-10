<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [iter15-mega-fix B-004 round-7 2026-05-10]
 *
 * Iter15 mega-audit B-004 (P2 → escalating, 4th cycle): the KDS pile was
 * showing ghost orders with tokens like AUDIT-CYCLE4-*, AUDIT-HEAL-*,
 * AUDIT-KIOSK-MULTI-*, RED-TEAM-*, ZZ-TEST-*, TEST-*, E2E-*. These are
 * leftover playwright fixtures from prior audit iterations that were never
 * swept up. Real cashiers / chefs see them as legitimate active orders.
 *
 * This command nukes orders whose token matches any of the test prefixes
 * AND whose status is in the live KDS pile (PENDING=1, ACCEPT=4,
 * PREPARING=7, PREPARED=8). We deliberately keep finalized rows
 * (DELIVERED=13, CANCELED=16) for traceability — they don't leak into
 * the active surface.
 *
 * Hard-refuses to run in production. Mirrors the safety pattern used by
 * `foodking:cleanup-test-fixtures` but is purpose-built for the orphan
 * tokens (the existing command is PW-prefix-only and required a
 * confirmation token, which is overkill for the iter15 cycle).
 */
class Iter15CleanupTestOrdersCommand extends Command
{
    protected $signature = 'iter15:cleanup-test-orders
        {--dry-run : Report matching rows without deleting (default)}
        {--apply : Actually delete the matching rows}
        {--json : Print machine-readable JSON output}';

    protected $description = '[iter15] Sweep orphan test orders (AUDIT-*, RED-TEAM-*, ZZ-TEST-*, TEST-*, E2E-*) from the live KDS pile.';

    /**
     * Token-LIKE patterns that mark an order as a Playwright/audit fixture.
     * NOTE: kept as `prefix%` — anchored at the start of the token.
     */
    private const TOKEN_PATTERNS = [
        'AUDIT-%',
        'RED-TEAM-%',
        'ZZ-TEST-%',
        'TEST-%',
        'E2E-%',
    ];

    /**
     * Statuses that put an order on the live KDS pile (or just before it).
     * Source: app/Enums/orderStatusEnum.php / resources/js/enums/modules/
     *   PENDING=1, ACCEPT=4, PREPARING=7, PREPARED=8.
     */
    private const ACTIVE_STATUSES = [1, 4, 7, 8];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run') || ! $apply;

        if ($apply && app()->environment('production')) {
            $this->error('Refusing to delete orders in production.');
            return 2;
        }

        $matchedIds = $this->matchingOrderIds();
        $beforeCount = $matchedIds->count();
        $deletedCount = 0;

        if ($apply && $beforeCount > 0) {
            $deletedCount = DB::transaction(function () use ($matchedIds): int {
                // [iter15-mega-fix B-004 round-7 2026-05-10] — delete the
                // dependent rows first so FK constraints don't bite. We
                // touch only the tables that reference orders.id and exist.
                $this->deleteWhereIn('order_status_transitions', 'order_id', $matchedIds);
                $this->deleteWhereIn('transactions', 'order_id', $matchedIds);
                $this->deleteWhereIn('domain_events', 'aggregate_id', $matchedIds);
                $this->deleteWhereIn('order_addresses', 'order_id', $matchedIds);
                $this->deleteWhereIn('order_items', 'order_id', $matchedIds);

                // hard delete (not soft) — these are fixtures, no audit trail kept.
                return DB::table('orders')->whereIn('id', $matchedIds)->delete();
            });
        }

        $payload = [
            'dry_run' => $dryRun,
            'applied' => $apply,
            'patterns' => self::TOKEN_PATTERNS,
            'active_statuses' => self::ACTIVE_STATUSES,
            'matched_count' => $beforeCount,
            'deleted_count' => $deletedCount,
            'matched_ids' => $matchedIds->values()->all(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        if ($apply) {
            $this->info("[iter15] Cleanup applied — deleted {$deletedCount} test orders (matched {$beforeCount}).");
        } else {
            $this->info("[iter15] Cleanup dry-run — {$beforeCount} test order(s) on KDS pile (re-run with --apply to delete).");
        }
        if ($beforeCount > 0) {
            foreach ($matchedIds->take(20) as $id) {
                $row = DB::table('orders')->where('id', $id)->first(['id', 'token', 'status']);
                if ($row) {
                    $this->line(sprintf('  - id=%d token=%s status=%d', $row->id, $row->token, $row->status));
                }
            }
        }

        return 0;
    }

    /**
     * @return \Illuminate\Support\Collection<int,int>
     */
    private function matchingOrderIds(): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('orders')) {
            return collect();
        }

        $query = DB::table('orders')
            ->where(function ($outer): void {
                foreach (self::TOKEN_PATTERNS as $pattern) {
                    $outer->orWhere('token', 'like', $pattern);
                }
            })
            ->whereIn('status', self::ACTIVE_STATUSES);

        return $query->pluck('id');
    }

    private function deleteWhereIn(string $table, string $column, \Illuminate\Support\Collection $ids): int
    {
        if ($ids->isEmpty() || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return (int) DB::table($table)->whereIn($column, $ids)->delete();
    }
}
