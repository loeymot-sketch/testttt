<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [POS-9-H.3.6 / F-A8]
 *
 * Confirms that the composite (branch_id, created_at) index is
 * actually installed on `action_logs`. Asserting the index *exists*
 * is the only honest perf test we can run at the unit level — the
 * MySQL query planner behaviour on 500k rows belongs to a staging
 * benchmark, not PHPUnit.
 */
class ActionLogsCompositeIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_composite_branch_created_index_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('action_logs', 'branch_id'));
        $this->assertTrue(Schema::hasColumn('action_logs', 'created_at'));

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: read from sqlite_master.
            $indexes = DB::select(
                "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='action_logs'"
            );
            $names = array_map(fn ($r) => $r->name, $indexes);
            $this->assertContains('action_logs_branch_created_idx', $names,
                'Composite index (branch_id, created_at) must be installed.');
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            $rows = DB::select('SHOW INDEX FROM action_logs WHERE Key_name = ?',
                ['action_logs_branch_created_idx']);
            $this->assertNotEmpty($rows,
                'Composite index (branch_id, created_at) must be installed.');
            // Verify column order — branch_id first (equality), then
            // created_at (range/order). Wrong order would still let
            // the query run but disable the index-order-scan optim.
            $byPos = [];
            foreach ($rows as $r) {
                $byPos[(int) $r->Seq_in_index] = $r->Column_name;
            }
            ksort($byPos);
            $this->assertSame(['branch_id', 'created_at'], array_values($byPos),
                'Index column order must be (branch_id, created_at).');
        } else {
            $this->markTestSkipped("Driver {$driver} index introspection not supported here.");
        }
    }
}
