<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia ultra-deep] Repas témoin : le SERVICE (matin / midi / soir) dont il est issu. Sans lui, un inspecteur
 * ne peut pas relier le plat gardé au service qu'il couvre. Colonne facultative : null pour tout ce qui n'est pas un repas témoin.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('label_print_logs', 'meal_slot')) return;
        Schema::table('label_print_logs', function (Blueprint $table) {
            $table->string('meal_slot', 10)->nullable()->after('action');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('label_print_logs', 'meal_slot')) return;
        Schema::table('label_print_logs', function (Blueprint $table) {
            $table->dropColumn('meal_slot');
        });
    }
};
