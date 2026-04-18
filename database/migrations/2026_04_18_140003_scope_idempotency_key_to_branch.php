<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'orders';
    private const COMPOSITE_INDEX = 'orders_branch_id_idempotency_key_unique';
    private const STANDALONE_INDEX = 'orders_idempotency_key_unique';

    public function up(): void
    {
        if (!Schema::hasColumn(self::TABLE, 'idempotency_key')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string('idempotency_key', 64)->nullable();
            });
        }

        $indexes = $this->uniqueIndexes();

        if ($this->hasCompositeIndex($indexes)) {
            return;
        }

        $standaloneIndex = $this->findStandaloneIndex($indexes);
        if ($standaloneIndex !== null) {
            $this->dropIndex($standaloneIndex);
        }

        if (!$this->hasCompositeIndex($this->uniqueIndexes())) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['branch_id', 'idempotency_key'], self::COMPOSITE_INDEX);
            });
        }
    }

    public function down(): void
    {
        $indexes = $this->uniqueIndexes();
        $compositeIndex = $this->findCompositeIndex($indexes);

        if ($compositeIndex !== null) {
            $this->dropIndex($compositeIndex);
        }

        if (Schema::hasColumn(self::TABLE, 'idempotency_key') && $this->findStandaloneIndex($this->uniqueIndexes()) === null) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('idempotency_key', self::STANDALONE_INDEX);
            });
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function uniqueIndexes(): array
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $definitions = [];
            $rows = DB::select("PRAGMA index_list('" . self::TABLE . "')");

            foreach ($rows as $row) {
                if ((int) ($row->unique ?? 0) !== 1) {
                    continue;
                }

                $indexName = (string) $row->name;
                $columns = DB::select("PRAGMA index_info('" . $indexName . "')");
                $definitions[$indexName] = array_values(array_map(
                    static fn ($column): string => (string) $column->name,
                    $columns
                ));
            }

            return $definitions;
        }

        $rows = DB::select('SHOW INDEX FROM `' . self::TABLE . '`');
        $definitions = [];

        foreach ($rows as $row) {
            if ((int) ($row->Non_unique ?? 1) !== 0) {
                continue;
            }

            $indexName = (string) $row->Key_name;
            $definitions[$indexName] ??= [];
            $definitions[$indexName][((int) $row->Seq_in_index) - 1] = (string) $row->Column_name;
        }

        foreach ($definitions as $indexName => $columns) {
            ksort($columns);
            $definitions[$indexName] = array_values($columns);
        }

        return $definitions;
    }

    /**
     * @param  array<string, list<string>>  $indexes
     */
    private function hasCompositeIndex(array $indexes): bool
    {
        return $this->findCompositeIndex($indexes) !== null;
    }

    /**
     * @param  array<string, list<string>>  $indexes
     */
    private function findCompositeIndex(array $indexes): ?string
    {
        foreach ($indexes as $name => $columns) {
            if ($columns === ['branch_id', 'idempotency_key']) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $indexes
     */
    private function findStandaloneIndex(array $indexes): ?string
    {
        foreach ($indexes as $name => $columns) {
            if ($columns === ['idempotency_key']) {
                return $name;
            }
        }

        return null;
    }

    private function dropIndex(string $indexName): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS "' . $indexName . '"');

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($indexName): void {
            $table->dropUnique($indexName);
        });
    }
};
