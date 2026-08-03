<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_categories', function (Blueprint $table) {
            $table->foreignId('wizard_profile_id')
                ->nullable()
                ->after('wizard_template')
                ->constrained('item_wizard_profiles')
                ->nullOnDelete();

            $table->index('wizard_profile_id', 'item_categories_wizard_profile_idx');
        });
    }

    public function down(): void
    {
        Schema::table('item_categories', function (Blueprint $table) {
            $table->dropForeign(['wizard_profile_id']);
            $table->dropIndex('item_categories_wizard_profile_idx');
            $table->dropColumn('wizard_profile_id');
        });
    }
};
