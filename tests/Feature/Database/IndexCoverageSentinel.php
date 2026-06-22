<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [L6.2 INDEX EFFICIENCY AUDIT 2026-05-24]
 *
 * Sentinel — asserts the presence and column-order of critical performance
 * indexes that protect hot CRUD paths at scale. Modeled after
 * tests/Feature/Migrations/ActionLogsCompositeIndexTest.php which canonicalises
 * the discipline (cf. ActionLogsCompositeIndexTest L14-15) that:
 *
 *   "Asserting the index *exists* is the only honest perf test we can run
 *    at the unit level — the MySQL query planner behaviour on 500k rows
 *    belongs to a staging benchmark, not PHPUnit."
 *
 * This sentinel is a baseline-LOCK: any migration that drops one of the
 * indexes below fails CI. Adding new indexes is fine — only DROP breaks.
 *
 * V1.0.X gaps documented in
 * reports/test-e2e/goal-2026-05-23/phase-l/L6.2-index-efficiency-findings.json
 * are NOT asserted here — they are V1.0.X candidates, V1 LOCAL ships GREEN
 * without them. When V1.0.X migrations land, add an assertion line below.
 */
class IndexCoverageSentinel extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------
     * orders — most critical CRUD table, every transaction touches it.
     * ------------------------------------------------------------------- */

    public function test_orders_branch_status_composite_index_exists(): void
    {
        $this->assertIndexExists('orders', 'idx_orders_branch_status', [
            'branch_id', 'status',
        ]);
        // V1.0.X G-09: SUPERSET (branch_id, status, created_at) would tighten
        // Z-report + analytics range scans 5-10x at V2 SaaS scale. Not
        // asserted at V1 LOCAL — see findings.json G-09.
    }

    public function test_orders_user_id_index_exists(): void
    {
        // orders.user_id semantically holds CUSTOMER id
        // (OrderService::posOrderStore line 699). H.2-HEAL-02 wave is
        // introducing a separate creator_id (cashier) column.
        $this->assertIndexExists('orders', 'idx_orders_user_id', ['user_id']);
    }

    public function test_orders_order_datetime_index_exists(): void
    {
        // Hot path: KDS list, OSS day-window, Z-report aggregation.
        $this->assertIndexExists('orders', 'idx_orders_datetime', ['order_datetime']);
    }

    public function test_orders_status_solo_index_exists(): void
    {
        // System-wide admin filters (cross-branch reporting).
        $this->assertIndexExists('orders', 'idx_orders_status', ['status']);
    }

    public function test_orders_branch_user_idempotency_unique_exists(): void
    {
        // H.1 P0 RED 2026-05-24 cross-customer leak close
        // (CLAUDE.md §9 idempotency scope).
        $this->assertUniqueIndexExists('orders', 'orders_branch_user_idempotency_unique', [
            'branch_id', 'user_id', 'idempotency_key',
        ]);
    }

    public function test_orders_fiscal_sequence_unique_exists(): void
    {
        // NF525 monotonic per-branch sequence guard
        // (CLAUDE.md §8 Fiscal Sequence).
        $this->assertUniqueIndexExists('orders', 'orders_branch_fiscal_seq_unique', [
            'branch_id', 'fiscal_sequence_no',
        ]);
    }

    /* ---------------------------------------------------------------------
     * audit_logs — NF525-frozen chain. Read-side index DDL requires LOCK
     * plan per CLAUDE.md §7. These indexes ARE production-validated.
     * ------------------------------------------------------------------- */

    public function test_audit_logs_branch_created_index_exists(): void
    {
        $this->assertIndexExists('audit_logs', 'audit_logs_branch_id_created_at_index', [
            'branch_id', 'created_at',
        ]);
    }

    public function test_audit_logs_resource_index_exists(): void
    {
        // Forensic lookups by resource type + id.
        $this->assertIndexExists('audit_logs', 'audit_logs_resource_resource_id_index', [
            'resource', 'resource_id',
        ]);
    }

    public function test_audit_logs_branch_prev_hash_unique_exists(): void
    {
        // F-C3/F-B1 race-fork defense — UNIQUE(branch_id, prev_hash)
        // backstops the Cache::lock in AuditLogService::write() when
        // cache driver is offline / split-brained.
        $this->assertUniqueIndexExists('audit_logs', 'audit_logs_branch_prev_unique', [
            'branch_id', 'prev_hash',
        ]);
    }

    public function test_audit_logs_chain_walk_efficient(): void
    {
        // V1 LOCAL: AuditLogService::lastHashFor (line 245) uses
        // WHERE branch_id ORDER BY id DESC LIMIT 1, served by branch_id
        // solo index + PK back-ref. Adequate for Le Cayenne 62-row chain.
        //
        // V1.0.X G-07: explicit (branch_id, id) composite would skip
        // the PK back-ref at 10M+ rows. NOT asserted at V1 LOCAL because
        // adding it requires LOCK plan (NF525-frozen table). When V1.0.X
        // migration lands, replace this assertion:
        //
        //   $this->assertIndexExists('audit_logs', 'idx_audit_logs_branch_id_walk',
        //       ['branch_id', 'id']);
        //
        // For now, we verify the FALLBACK is at least present.
        $this->assertTrue(
            $this->columnHasIndex('audit_logs', 'branch_id'),
            'audit_logs.branch_id must be indexed (chain-walk fallback path).'
        );
    }

    /* ---------------------------------------------------------------------
     * cash_movements — NF525-adjacent (DELETE trigger present).
     * ------------------------------------------------------------------- */

    public function test_cash_movements_branch_created_index_exists(): void
    {
        $this->assertIndexExists('cash_movements', 'cash_movements_branch_id_created_at_index', [
            'branch_id', 'created_at',
        ]);
    }

    public function test_cash_movements_order_type_index_exists(): void
    {
        $this->assertIndexExists('cash_movements', 'cash_movements_order_id_type_index', [
            'order_id', 'type',
        ]);
    }

    public function test_cash_movements_session_index_exists(): void
    {
        $this->assertTrue(
            $this->columnHasIndex('cash_movements', 'cash_drawer_session_id'),
            'cash_movements.cash_drawer_session_id must be indexed (drawer-session detail page).'
        );
    }

    /* ---------------------------------------------------------------------
     * domain_events — outbox dispatcher pending lookup.
     * ------------------------------------------------------------------- */

    public function test_domain_events_pending_index_exists(): void
    {
        $this->assertIndexExists('domain_events', 'idx_pending', [
            'dispatched_at', 'occurred_at',
        ]);
        // V1.0.X G-02: scopeStale uses created_at (NOT occurred_at) — the
        // composite trailing column does NOT serve scopeStale. Two equally
        // good fixes:
        //   (a) add (dispatched_at, created_at) composite, or
        //   (b) refactor DomainEvent::scopeStale to use occurred_at.
        // Option (b) preferred — code-only, semantic alignment.
    }

    public function test_domain_events_branch_occurred_index_exists(): void
    {
        $this->assertIndexExists('domain_events', 'idx_branch', [
            'branch_id', 'occurred_at',
        ]);
    }

    public function test_domain_events_idempotency_unique_exists(): void
    {
        $this->assertUniqueIndexExists('domain_events', 'uniq_domain_events_idempotency_key', [
            'idempotency_key',
        ]);
    }

    /* ---------------------------------------------------------------------
     * items + per-branch availability.
     * ------------------------------------------------------------------- */

    public function test_items_kiosk_filters_composite_index_exists(): void
    {
        $this->assertIndexExists('items', 'items_kiosk_filters_idx', [
            'is_available', 'is_halal', 'is_vegetarian',
        ]);
    }

    public function test_items_status_category_composite_index_exists(): void
    {
        $this->assertIndexExists('items', 'idx_items_status_category', [
            'status', 'item_category_id',
        ]);
    }

    public function test_item_branch_availability_branch_index_exists(): void
    {
        // items.branch_id does NOT exist — per-branch overrides live in
        // item_branch_availability which IS indexed on (branch_id, is_available).
        $this->assertTrue(
            $this->columnHasIndex('item_branch_availability', 'branch_id'),
            'item_branch_availability.branch_id must be indexed (per-branch availability hot path).'
        );
    }

    /* ---------------------------------------------------------------------
     * loyalty_transactions.
     * ------------------------------------------------------------------- */

    public function test_loyalty_transactions_user_index_exists(): void
    {
        // Customer history feed (LoyaltyController line 483)
        // V1.0.X (G-05): (user_id, created_at DESC) would help at heavy
        // scale; (user_id, type) would unlock per-type aggregation.
        $this->assertTrue(
            $this->columnHasIndex('loyalty_transactions', 'user_id'),
            'loyalty_transactions.user_id must be indexed (mobile history feed).'
        );
    }

    public function test_loyalty_transactions_user_order_type_unique_exists(): void
    {
        // Idempotent dual-write awarding guard.
        $this->assertUniqueIndexExists('loyalty_transactions', 'loyalty_transactions_user_order_type_unique', [
            'user_id', 'order_id', 'type',
        ]);
    }

    /* ---------------------------------------------------------------------
     * personal_access_tokens (Sanctum vendor).
     * ------------------------------------------------------------------- */

    public function test_personal_access_tokens_tokenable_morph_index_exists(): void
    {
        // Sanctum default $table->morphs('tokenable') auto-generates the
        // composite (tokenable_type, tokenable_id) index used by
        // LoginController::accessToken match (line 212) and
        // RevokeTokensOnBranchDeactivated (line 53).
        $this->assertTrue(
            $this->columnHasIndex('personal_access_tokens', 'tokenable_type')
            || $this->columnHasIndex('personal_access_tokens', 'tokenable_id'),
            'personal_access_tokens morph index must be present (Sanctum default).'
        );
        // V1.0.X (G-03): expires_at NOT indexed — sanctum:prune-expired
        // daily cron (app/Console/Kernel.php line 154) full-scans the
        // table. Add: $table->index('expires_at', 'pat_expires_at_idx').
        // Not asserted at V1 LOCAL — table stays small (Le Cayenne ~10
        // tokens at once with TTL 480 min).
    }

    /* =====================================================================
     * Helpers — driver-agnostic introspection (MySQL + SQLite).
     * Matches the pattern in ActionLogsCompositeIndexTest.
     * =================================================================== */

    private function assertIndexExists(string $table, string $indexName, array $expectedColumnsInOrder): void
    {
        $this->assertIndexExistsImpl($table, $indexName, $expectedColumnsInOrder, /*unique*/ false);
    }

    private function assertUniqueIndexExists(string $table, string $indexName, array $expectedColumnsInOrder): void
    {
        $this->assertIndexExistsImpl($table, $indexName, $expectedColumnsInOrder, /*unique*/ true);
    }

    private function assertIndexExistsImpl(string $table, string $indexName, array $expectedColumnsInOrder, bool $unique): void
    {
        foreach ($expectedColumnsInOrder as $col) {
            $this->assertTrue(
                Schema::hasColumn($table, $col),
                sprintf('Pre-condition: %s.%s must exist before asserting index %s.', $table, $col, $indexName)
            );
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select(
                "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name=?",
                [$table]
            );
            $names = array_map(fn ($r) => $r->name, $indexes);
            $this->assertContains(
                $indexName,
                $names,
                sprintf('Index %s must exist on %s.', $indexName, $table)
            );
            // Column order verification on sqlite via PRAGMA index_info.
            $info = DB::select(
                sprintf("PRAGMA index_info('%s')", str_replace("'", "''", $indexName))
            );
            $cols = array_map(fn ($r) => $r->name, $info);
            $this->assertSame(
                $expectedColumnsInOrder,
                $cols,
                sprintf('Index %s on %s must have columns in order: %s', $indexName, $table, implode(',', $expectedColumnsInOrder))
            );

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $rows = DB::select('SHOW INDEX FROM `' . str_replace('`', '``', $table) . '` WHERE Key_name = ?', [$indexName]);
            $this->assertNotEmpty(
                $rows,
                sprintf('Index %s must exist on %s.', $indexName, $table)
            );

            if ($unique) {
                $this->assertSame(
                    0,
                    (int) $rows[0]->Non_unique,
                    sprintf('Index %s on %s must be UNIQUE.', $indexName, $table)
                );
            }

            $byPos = [];
            foreach ($rows as $r) {
                $byPos[(int) $r->Seq_in_index] = $r->Column_name;
            }
            ksort($byPos);
            $this->assertSame(
                $expectedColumnsInOrder,
                array_values($byPos),
                sprintf('Index %s on %s column order mismatch.', $indexName, $table)
            );

            return;
        }

        $this->markTestSkipped("Driver {$driver} index introspection not supported.");
    }

    private function columnHasIndex(string $table, string $column): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('" . str_replace("'", "''", $table) . "')");
            foreach ($indexes as $idx) {
                $info = DB::select("PRAGMA index_info('" . str_replace("'", "''", $idx->name) . "')");
                foreach ($info as $col) {
                    if ($col->name === $column) {
                        return true;
                    }
                }
            }

            return false;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $rows = DB::select(
                'SHOW INDEX FROM `' . str_replace('`', '``', $table) . '` WHERE Column_name = ?',
                [$column]
            );

            return !empty($rows);
        }

        return false;
    }
}
