<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_addons', function (Blueprint $table) {
            if (! Schema::hasColumn('item_addons', 'role')) {
                $table->enum('role', ['drink', 'side', 'dessert', 'menu_component', 'upsell'])
                    ->nullable()
                    ->after('addon_item_variation');
            }
        });
    }

    public function down(): void
    {
        Schema::table('item_addons', function (Blueprint $table) {
            if (Schema::hasColumn('item_addons', 'role')) {
                $table->dropColumn('role');
            }
        });
    }
};
