<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'orders';
    private const OLD_INDEX = 'orders_branch_queue_number_unique';
    private const INDEX = 'orders_branch_business_date_queue_unique';

    public function up(): void
    {
        if (!Schema::hasColumn(self::TABLE, 'branch_id') || !Schema::hasColumn(self::TABLE, 'queue_number')) {
            return;
        }

        if (!Schema::hasColumn(self::TABLE, 'business_date')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->date('business_date')->nullable()->after('queue_number');
            });
        }

        $this->backfillBusinessDate();
        $this->dropUniqueIndexIfExists(self::OLD_INDEX);

        if ($this->hasUniqueIndex(['branch_id', 'business_date', 'queue_number'])) {
            return;
        }

        $duplicate = DB::table(self::TABLE)
            ->select('branch_id', 'business_date', 'queue_number', DB::raw('COUNT(*) as duplicate_count'))
            ->whereNotNull('queue_number')
            ->whereNotNull('business_date')
            ->groupBy('branch_id', 'business_date', 'queue_number')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('duplicate_count')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(sprintf(
                'D-M13 blocked: duplicate queue_number %s exists for branch_id %s on business_date %s (%s rows). Run the signed backfill before adding %s.',
                (string) $duplicate->queue_number,
                (string) $duplicate->branch_id,
                (string) $duplicate->business_date,
                (string) $duplicate->duplicate_count,
                self::INDEX
            ));
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unique(['branch_id', 'business_date', 'queue_number'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn(self::TABLE, 'branch_id') || !Schema::hasColumn(self::TABLE, 'queue_number')) {
            return;
        }

        $this->dropUniqueIndexIfExists(self::INDEX);

        if (Schema::hasColumn(self::TABLE, 'business_date')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('business_date');
            });
        }
    }

    private function backfillBusinessDate(): void
    {
        if (!Schema::hasColumn(self::TABLE, 'business_date')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        $fallback = $driver === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'NOW()';

        DB::statement(sprintf(
            'UPDATE %s SET business_date = DATE(COALESCE(order_datetime, created_at, %s)) WHERE business_date IS NULL',
            self::TABLE,
            $fallback
        ));
    }

    /**
     * @param array<int,string> $expectedColumns
     */
    private function hasUniqueIndex(array $expectedColumns): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach (DB::select("PRAGMA index_list('" . self::TABLE . "')") as $index) {
                if ((int) ($index->unique ?? 0) !== 1) {
                    continue;
                }

                $columns = array_map(
                    static fn ($row): string => (string) $row->name,
                    DB::select("PRAGMA index_info('" . str_replace("'", "''", (string) $index->name) . "')")
                );

                if ($columns === $expectedColumns) {
                    return true;
                }
            }

            return false;
        }

        $rows = DB::select('SHOW INDEX FROM `' . self::TABLE . '` WHERE Non_unique = 0');
        $definitions = [];

        foreach ($rows as $row) {
            $name = (string) $row->Key_name;
            $definitions[$name] ??= [];
            $definitions[$name][((int) $row->Seq_in_index) - 1] = (string) $row->Column_name;
        }

        foreach ($definitions as $columns) {
            ksort($columns);
            if (array_values($columns) === $expectedColumns) {
                return true;
            }
        }

        return false;
    }

    private function dropUniqueIndexIfExists(string $indexName): void
    {
        if (!$this->hasIndexNamed($indexName)) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS "' . str_replace('"', '""', $indexName) . '"');

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($indexName): void {
            $table->dropUnique($indexName);
        });
    }

    private function hasIndexNamed(string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach (DB::select("PRAGMA index_list('" . self::TABLE . "')") as $index) {
                if ((string) $index->name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        foreach (DB::select('SHOW INDEX FROM `' . self::TABLE . '`') as $row) {
            if ((string) $row->Key_name === $indexName) {
                return true;
            }
        }

        return false;
    }
};
