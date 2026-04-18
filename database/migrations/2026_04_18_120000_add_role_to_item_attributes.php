<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('item_attributes') || Schema::hasColumn('item_attributes', 'role')) {
            return;
        }

        Schema::table('item_attributes', function (Blueprint $table) {
            $table->string('role', 32)->nullable()->after('name');
            $table->index('role', 'item_attributes_role_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('item_attributes') || ! Schema::hasColumn('item_attributes', 'role')) {
            return;
        }

        Schema::table('item_attributes', function (Blueprint $table) {
            $table->dropIndex('item_attributes_role_index');
            $table->dropColumn('role');
        });
    }
};
