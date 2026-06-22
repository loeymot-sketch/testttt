<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_wizard_profiles', function (Blueprint $table) {
            $table->foreignId('item_category_id')
                ->nullable()
                ->after('item_id')
                ->constrained('item_categories')
                ->cascadeOnDelete();

            $table->index('item_category_id', 'item_wizard_profiles_category_idx');
        });

        $this->setItemIdNullable();
        $this->addOwnerXorConstraint();
    }

    public function down(): void
    {
        $this->dropOwnerXorConstraint();
        $this->setItemIdNotNullable();

        Schema::table('item_wizard_profiles', function (Blueprint $table) {
            $table->dropForeign(['item_category_id']);
            $table->dropIndex('item_wizard_profiles_category_idx');
            $table->dropColumn('item_category_id');
        });
    }

    private function setItemIdNullable(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteProfilesTable(true);

            return;
        }

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement('ALTER TABLE item_wizard_profiles MODIFY item_id BIGINT UNSIGNED NULL'),
            'pgsql' => DB::statement('ALTER TABLE item_wizard_profiles ALTER COLUMN item_id DROP NOT NULL'),
            default => null,
        };
    }

    private function setItemIdNotNullable(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteProfilesTable(false);

            return;
        }

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement('ALTER TABLE item_wizard_profiles MODIFY item_id BIGINT UNSIGNED NOT NULL'),
            'pgsql' => DB::statement('ALTER TABLE item_wizard_profiles ALTER COLUMN item_id SET NOT NULL'),
            default => null,
        };
    }

    private function rebuildSqliteProfilesTable(bool $itemIdNullable): void
    {
        DB::statement('PRAGMA foreign_keys=OFF');

        $itemIdDefinition = $itemIdNullable ? 'INTEGER NULL' : 'INTEGER NOT NULL';
        DB::statement("CREATE TABLE item_wizard_profiles_tmp (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            item_id {$itemIdDefinition},
            item_category_id INTEGER NULL,
            template VARCHAR(255) NOT NULL DEFAULT 'simple',
            version INTEGER UNSIGNED NOT NULL DEFAULT '1',
            is_published TINYINT(1) NOT NULL DEFAULT '0',
            published_at DATETIME NULL,
            branch_id_scope INTEGER NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE,
            FOREIGN KEY(item_category_id) REFERENCES item_categories(id) ON DELETE CASCADE,
            FOREIGN KEY(branch_id_scope) REFERENCES branches(id) ON DELETE SET NULL
        )");

        $where = $itemIdNullable ? '' : ' WHERE item_id IS NOT NULL';
        DB::statement("INSERT INTO item_wizard_profiles_tmp (
            id, item_id, item_category_id, template, version, is_published, published_at,
            branch_id_scope, created_at, updated_at
        )
        SELECT id, item_id, item_category_id, template, version, is_published, published_at,
            branch_id_scope, created_at, updated_at
        FROM item_wizard_profiles{$where}");

        DB::statement('DROP TABLE item_wizard_profiles');
        DB::statement('ALTER TABLE item_wizard_profiles_tmp RENAME TO item_wizard_profiles');
        DB::statement('CREATE INDEX item_wizard_profiles_item_branch_idx ON item_wizard_profiles (item_id, branch_id_scope)');
        DB::statement('CREATE INDEX item_wizard_profiles_published_idx ON item_wizard_profiles (is_published)');
        DB::statement('CREATE INDEX item_wizard_profiles_category_idx ON item_wizard_profiles (item_category_id)');
        DB::statement('PRAGMA foreign_keys=ON');
    }

    private function addOwnerXorConstraint(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            'ALTER TABLE item_wizard_profiles
             ADD CONSTRAINT item_wizard_profiles_owner_xor_check
             CHECK ((item_id IS NOT NULL) <> (item_category_id IS NOT NULL))'
        );
    }

    private function dropOwnerXorConstraint(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE item_wizard_profiles DROP CONSTRAINT IF EXISTS item_wizard_profiles_owner_xor_check');

            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            try {
                DB::statement('ALTER TABLE item_wizard_profiles DROP CHECK item_wizard_profiles_owner_xor_check');
            } catch (\Throwable) {
                try {
                    DB::statement('ALTER TABLE item_wizard_profiles DROP CONSTRAINT item_wizard_profiles_owner_xor_check');
                } catch (\Throwable) {
                    // MySQL/MariaDB have no portable IF EXISTS for CHECK drops.
                }
            }
        }
    }
};
