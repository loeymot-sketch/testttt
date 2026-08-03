<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'nfc_uid')) {
                $table->string('nfc_uid', 64)->nullable();
            }
        });

        if (! $this->indexExists('users', 'users_branch_nfc_uid_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique(['branch_id', 'nfc_uid'], 'users_branch_nfc_uid_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if ($this->indexExists('users', 'users_branch_nfc_uid_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_branch_nfc_uid_unique');
            });
        }

        if (Schema::hasColumn('users', 'nfc_uid')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('nfc_uid');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $schemaManager = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $schemaManager->listTableIndexes($table);

            foreach ($indexes as $index) {
                if (strtolower($index->getName()) === strtolower($indexName)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
};
