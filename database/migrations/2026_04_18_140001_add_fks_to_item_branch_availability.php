<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('item_branch_availability')) {
            return;
        }

        DB::table('item_branch_availability')
            ->whereNotIn('item_id', DB::table('items')->select('id'))
            ->delete();

        DB::table('item_branch_availability')
            ->whereNotIn('branch_id', DB::table('branches')->select('id'))
            ->delete();

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('item_branch_availability', function (Blueprint $table) {
            $table->foreign('item_id')
                ->references('id')
                ->on('items')
                ->cascadeOnDelete();

            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('item_branch_availability') || DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('item_branch_availability', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->dropForeign(['branch_id']);
        });
    }
};
