<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('items')
            ->where('name', 'Menu (Frites + Boisson)')
            ->update(['price' => 3.00]);

        DB::table('items')
            ->where('name', 'Frites Seules')
            ->update(['price' => 2.00]);

        DB::table('items')
            ->where('name', 'Boisson Seule')
            ->update(['price' => 2.00]);
    }

    public function down(): void
    {
        DB::table('items')
            ->where('name', 'Menu (Frites + Boisson)')
            ->update(['price' => 2.50]);

        DB::table('items')
            ->where('name', 'Frites Seules')
            ->update(['price' => 2.50]);

        DB::table('items')
            ->where('name', 'Boisson Seule')
            ->update(['price' => 1.50]);
    }
};
