<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('items', 'kds_station')) {
            return;
        }

        Schema::table('items', function (Blueprint $table) {
            $table->enum('kds_station', ['bar', 'cuisine_chaude', 'cuisine_froide', 'none'])
                ->default('none')
                ->after('kiosk_emoji');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->index('kds_station');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('items', 'kds_station')) {
            return;
        }

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['kds_station']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('kds_station');
        });
    }
};
