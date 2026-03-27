<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('item_variations', 'visible_on')) {
                $table->json('visible_on')->nullable()->after('status')
                    ->comment('null=all surfaces | ["kiosk","pos","web"]');
            }
        });
    }

    public function down(): void
    {
        Schema::table('item_variations', function (Blueprint $table) {
            $table->dropColumn('visible_on');
        });
    }
};
