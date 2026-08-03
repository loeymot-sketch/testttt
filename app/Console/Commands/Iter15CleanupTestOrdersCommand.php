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
        {--json : Print machine-readable JSON output}
        {--token-prefix=* : Restrict sweep to one or more token prefixes (repeatable). Each value is suffixed with %% in the LIKE clause. When empty, falls back to DEFAULT_TOKEN_PATTERNS for back-compat.}';

    protected $description = '[iter15] Sweep orphan test orders (AUDIT-*, RED-TEAM-*, ZZ-TEST-*, TEST-*, E2E-*) from the live KDS pile.';

    /**
     * Default token-LIKE patterns that mark an order as a Playwright/audit
     * fixture. Used ONLY when --token-prefix is empty (back-compat).
     * NOTE: kept as `prefix%` — anchored at the start of the token.
     */
    private const DEFAULT_TOKEN_PATTERNS = [
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

        // [test-e2e fix A-015 round-4 2026-05-11] Wave-scoped sweep.
        // When --token-prefix is provided (one or more times), restrict the
        // WHERE clause to those prefixes only. This prevents the rush-hour
        // Wave B beforeAll cleanup from scooping Wave A's just-created
        // AUDIT-RUSH-A-% rows mid-burst (round-3 A-015 root cause). When
        // empty, fall back to DEFAULT_TOKEN_PATTERNS for back-compat with
        // the default sweep semantics used by older specs.
        $tokenPrefixes = (array) $this->option('token-prefix');
        $patterns = empty($tokenPrefixes)
            ? self::DEFAULT_TOKEN_PATTERNS
            : array_values(array_map(static fn (string $p): string => $p.'%', $tokenPrefixes));

        $matchedIds = $this->matchingOrderIds($patterns);
        $beforeCount = $matchedIds->count();
        $deletedCount = 0;

        if ($apply && $beforeCount > 0) {
            $deletedCount = DB::transaction(function () use ($matchedIds): int {
                // [SELF-AUDIT P3 2026-07-05 — TOCTOU NF525] matchingOrderIds() évalue le garde
                // whereNull(fiscal_sequence_no) HORS transaction. Un encaissement concurrent peut
                // fiscaliser un ordre entre le match et la suppression → risque de supprimer un ordre
                // fiscalisé (violation NF525) OU d'orpheliner ses enfants si on ne le supprimait que
                // lui. On RE-VALIDE ici sous verrou et on cascade/supprime UNIQUEMENT ce set sûr.
                $safeIds = collect(
                    DB::table('orders')
                        ->whereIn('id', $matchedIds)
                        ->whereNull('fiscal_sequence_no')
                        ->lockForUpdate()
                        ->pluck('id')
                        ->all()
                );
                if ($safeIds->isEmpty()) {
                    return 0;
                }

                // [iter15-mega-fix B-004 round-7 2026-05-10] — delete the
                // dependent rows first so FK constraints don't bite. We
                // touch only the tables that reference orders.id and exist.
                $this->deleteWhereIn('order_status_transitions', 'order_id', $safeIds);
                $this->deleteWhereIn('transactions', 'order_id', $safeIds);
                $this->deleteWhereIn('domain_events', 'aggregate_id', $safeIds);
                $this->deleteWhereIn('order_addresses', 'order_id', $safeIds);
                // [WAVE5 GÉRANCE 2026-07-04] cash_movements (référence order_id SANS FK → orphelinait
                // le trail caisse) + order_payments (FK RESTRICT → un ordre matché avec tranche faisait
                // rollback TOUT le nettoyage). On les cascade avant le delete orders. Le garde fiscal
                // ci-dessus exclut déjà les ordres fiscalisés, mais on cascade par robustesse.
                $this->deleteWhereIn('cash_movements', 'order_id', $safeIds);
                $this->deleteWhereIn('order_payments', 'order_id', $safeIds);
                // [SELF-AUDIT P3 2026-07-05 — FK bloquante manquée] order_coupons.order_id est
                // constrained('orders') = ON DELETE RESTRICT → un ordre matché portant un coupon
                // faisait throw le delete final → ROLLBACK de TOUT le nettoyage (rien supprimé, échec
                // silencieux en --apply). On cascade avant le delete orders.
                $this->deleteWhereIn('order_coupons', 'order_id', $safeIds);
                $this->deleteWhereIn('order_items', 'order_id', $safeIds);

                // hard delete (not soft) — these are fixtures, no audit trail kept.
                return DB::table('orders')->whereIn('id', $safeIds)->delete();
            });
        }

        $payload = [
            'dry_run' => $dryRun,
            'applied' => $apply,
            'patterns' => $patterns,
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
     * @param  array<int,string>  $patterns  LIKE patterns (already include %)
     * @return \Illuminate\Support\Collection<int,int>
     */
    private function matchingOrderIds(array $patterns): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('orders') || empty($patterns)) {
            return collect();
        }

        $query = DB::table('orders')
            ->where(function ($outer) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $outer->orWhere('token', 'like', $pattern);
                }
            })
            ->whereIn('status', self::ACTIVE_STATUSES)
            // [WAVE5 GÉRANCE 2026-07-04 — garde NF525] Ne JAMAIS matcher un ordre FISCALISÉ : ce
            // chemin fait un hard-delete (ligne ~97), qui retirerait PHYSIQUEMENT un n° de séquence
            // fiscale alloué = rupture gap-free NF525 (ZReportService agrège withTrashed → un
            // soft-delete survit, un hard-delete disparaît). Miroir du garde éprouvé de
            // CleanupWebTestOrdersCommand:39. Les fixtures fiscalisées restent intactes (trail fiscal > nettoyage test).
            ->whereNull('fiscal_sequence_no');

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
