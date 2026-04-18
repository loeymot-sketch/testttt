<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('item_categories')
            ->whereNull('wizard_template')
            ->update(['wizard_template' => 'simple']);
    }

    public function down(): void
    {
        // Intentionally irreversible — NULL values were data gaps, not intentional.
    }
};
