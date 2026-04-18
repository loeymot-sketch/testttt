<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('order_items', 'allergens_snapshot')) {
                $table->json('allergens_snapshot')->nullable()->after('instruction');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('order_items', 'allergens_snapshot')) {
                $table->dropColumn('allergens_snapshot');
            }
        });
    }
};
