<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [H.1 P0 RED 2026-05-24] Add user_id to orders idempotency UNIQUE
 *
 * Per CLAUDE.md §9 the IdempotencyKey scope MUST be
 * (branch_id, user_id, hash(key)). The HTTP middleware
 * (IdempotencyKeyMiddleware — FROZEN §7) enforces this correctly at L7,
 * but the application-layer defense-in-depth (DB UNIQUE on the orders
 * table + OrderService::findExistingOrderForIdempotencyRecovery) queried
 * only (branch_id, idempotency_key). Phase H.1 reproduced the cross-
 * customer leak empirically (5/6 runs).
 *
 * Scope of this close (intentionally narrow, honest framing):
 *   - orders.user_id semantically stores the CUSTOMER id (line 699 of
 *     OrderService::posOrderStore : `'user_id' => $request->customer_id`).
 *   - Adding user_id to the UNIQUE + recovery query CLOSES the
 *     cross-customer collision case (two cashiers, two different
 *     customers, identical key on same branch).
 *   - Two anonymous walk-ins (customer = NULL) still race-collide at L4,
 *     because MySQL UNIQUE treats NULLs as distinct (this is
 *     intentional NULL-permissiveness — anonymous orders must remain
 *     creatable on retry). For that window the HTTP middleware (L7,
 *     scoped on Auth::id() cashier) is the close.
 *   - The H2-HEAL-02 wave introduces `creator_id` (cashier column) for a
 *     future cashier-level UNIQUE — out of scope for this heal.
 *
 * V1 LOCAL Le Cayenne single-branch risk = LOW.
 * V2 SaaS multi-tenant risk = HIGH — critical close before any V2 cutover.
 *
 * MySQL note: UNIQUE on (branch_id, user_id, idempotency_key) treats
 * each NULL on user_id as distinct, so the constraint is NULL-permissive
 * by design. Same on SQLite ≥ 3.9.
 *
 * NF525-adjacency: orders is Z aggregate source. Additive index change on
 * non-fiscal columns. NOT in deploy/ansible/site.yml REVOKE list. NOT in
 * CLAUDE.md §7 frozen scope (the §7 list names services + DELETE
 * triggers — orders additive UNIQUE not in scope).
 *
 * Down(): refuses production rollback (same pattern as
 * 2026_05_19_200000_add_unique_parent_order_id_to_orders.php and the
 * other NF525-adjacent migrations).
 */
return new class extends Migration
{
    private const TABLE        = 'orders';
    private const OLD_INDEX    = 'orders_branch_id_idempotency_key_unique';
    private const NEW_INDEX    = 'orders_branch_user_idempotency_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // Step 1: add the NEW UNIQUE first, so we always have a constraint
        // covering the column triple before we drop the old one. Idempotent.
        if (! $this->indexExists(self::TABLE, self::NEW_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(
                    ['branch_id', 'user_id', 'idempotency_key'],
                    self::NEW_INDEX
                );
            });
        }

        // Step 2: drop the old (branch_id, idempotency_key) UNIQUE if still
        // present. Wrapped — idempotent for replay.
        if ($this->indexExists(self::TABLE, self::OLD_INDEX)) {
            $this->dropIndexSafely(self::TABLE, self::OLD_INDEX);
        }
    }

    public function down(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'NF525-adjacent DDL rollback refused in production. '
                . 'Dropping UNIQUE(branch_id, user_id, idempotency_key) '
                . 'on orders would re-open the cross-customer idempotency '
                . 'leak documented in Phase H.1. Use a forward-only data '
                . 'migration if relaxation is required.'
            );
        }

        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // Restore the old composite UNIQUE first so we never sit without
        // any constraint on the column pair.
        if (! $this->indexExists(self::TABLE, self::OLD_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(
                    ['branch_id', 'idempotency_key'],
                    self::OLD_INDEX
                );
            });
        }

        if ($this->indexExists(self::TABLE, self::NEW_INDEX)) {
            $this->dropIndexSafely(self::TABLE, self::NEW_INDEX);
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select(
                'SELECT 1 FROM sqlite_master WHERE type = ? AND name = ? LIMIT 1',
                ['index', $indexName]
            );

            return count($rows) > 0;
        }

        // MySQL / MariaDB
        $database = Schema::getConnection()->getDatabaseName();
        $result   = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.statistics '
            . 'WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $indexName]
        );

        return $result && (int) $result->c > 0;
    }

    private function dropIndexSafely(string $table, string $indexName): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            try {
                DB::statement('DROP INDEX IF EXISTS "' . str_replace('"', '""', $indexName) . '"');
            } catch (\Throwable) {
                // idempotent — ignore
            }

            return;
        }

        try {
            DB::statement(
                'ALTER TABLE `' . str_replace('`', '``', $table) . '` '
                . 'DROP INDEX `' . str_replace('`', '``', $indexName) . '`'
            );
        } catch (\Throwable) {
            // idempotent — ignore
        }
    }
};
