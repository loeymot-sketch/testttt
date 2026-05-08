<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [SEC-HEAL-2026-05-08 iter2] Ultra-review HEAL data integrity finding.
 *
 * Bug confirmed empirically : `unique(['order_id', 'order_type'])` permettait
 * 2 rows pour la même commande physique via swap discriminator (Order vs
 * FrontendOrder partagent la table `orders`). Un user pouvait POSTer
 * `order_type=Order` puis `order_type=FrontendOrder` et créer 2 rows
 * → polluer les agrégats CSAT/NPS d'une branche.
 *
 * Fix Option A (recommended) :
 *   - Drop l'index composite (order_id, order_type)
 *   - Recreate uniquement sur order_id (1 rating par commande, point)
 *   - Le controller force order_type='FrontendOrder' désormais ; la colonne
 *     reste pour traçabilité informative (legacy data).
 *
 * Cette migration est compatible SQLite (tests) + MySQL (prod) via
 * pattern hérité de scope_idempotency_key_to_branch.php.
 *
 * CLAUDE.md non-négociable #4 : "Real evidence is more important than
 * confidence." — l'exploit a été démontré, pas spéculé.
 */
return new class extends Migration {
    private const TABLE = 'order_ratings';
    private const OLD_INDEX = 'order_rating_unique';
    private const NEW_INDEX = 'order_rating_unique';

    public function up(): void
    {
        // Idempotent : si l'ancien index composite existe, le drop puis
        // recrée standalone. Si déjà standalone (re-run), skip.
        $indexes = $this->uniqueIndexes();
        $current = $indexes[self::OLD_INDEX] ?? null;

        // Si l'index existe déjà sur (order_id) seul → rien à faire.
        if ($current === ['order_id']) {
            return;
        }

        // Sinon : drop l'index existant (composite ou autre) et recrée standalone.
        if ($current !== null) {
            $this->dropIndex(self::OLD_INDEX);
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unique('order_id', self::NEW_INDEX);
        });
    }

    public function down(): void
    {
        $indexes = $this->uniqueIndexes();
        $current = $indexes[self::OLD_INDEX] ?? null;

        if ($current === ['order_id', 'order_type']) {
            return;
        }

        if ($current !== null) {
            $this->dropIndex(self::OLD_INDEX);
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unique(['order_id', 'order_type'], self::OLD_INDEX);
        });
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
