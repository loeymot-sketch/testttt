<?php

namespace Tests\Feature\Sentinels;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * @FK-ID FK-020 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M13-MIGRATIONS-SAFETY | @reason queue_number uniqueness currently relies on application locking/fallback without a database uniqueness guard.
 */
class QueueNumberUniquenessSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_have_database_unique_guard_for_branch_business_date_queue_number(): void
    {
        $this->assertTrue(
            DB::getSchemaBuilder()->hasColumn('orders', 'business_date'),
            'orders must expose business_date so queue numbers can reset safely per branch day.'
        );

        $this->assertTrue(
            $this->hasUniqueIndexContaining(['branch_id', 'business_date', 'queue_number']),
            'orders must have a unique index containing branch_id, business_date, and queue_number to protect concurrent POS/kiosk creation while allowing daily reset.'
        );
    }

    /**
     * @param array<int,string> $requiredColumns
     */
    private function hasUniqueIndexContaining(array $requiredColumns): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            foreach (DB::select("PRAGMA index_list('orders')") as $index) {
                if ((int) ($index->unique ?? 0) !== 1) {
                    continue;
                }

                $columns = array_map(
                    fn ($row) => (string) $row->name,
                    DB::select("PRAGMA index_info('" . str_replace("'", "''", (string) $index->name) . "')")
                );

                if (empty(array_diff($requiredColumns, $columns))) {
                    return true;
                }
            }

            return false;
        }

        $rows = DB::select("SHOW INDEX FROM orders WHERE Non_unique = 0");
        $byName = [];
        foreach ($rows as $row) {
            $byName[(string) $row->Key_name][] = (string) $row->Column_name;
        }

        foreach ($byName as $columns) {
            if (empty(array_diff($requiredColumns, $columns))) {
                return true;
            }
        }

        return false;
    }
}
