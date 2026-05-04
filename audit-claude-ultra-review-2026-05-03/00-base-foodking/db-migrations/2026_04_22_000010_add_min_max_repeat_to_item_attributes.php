<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('item_attributes', function (Blueprint $table) {
            if (! Schema::hasColumn('item_attributes', 'min_select')) {
                $table->unsignedInteger('min_select')->default(0)->after('name');
            }
            if (! Schema::hasColumn('item_attributes', 'max_select')) {
                $table->unsignedInteger('max_select')->default(1)->after('min_select');
            }
            if (! Schema::hasColumn('item_attributes', 'allow_repeat')) {
                $table->boolean('allow_repeat')->default(false)->after('max_select');
            }
        });
    }

    public function down(): void
    {
        Schema::table('item_attributes', function (Blueprint $table) {
            foreach (['allow_repeat', 'max_select', 'min_select'] as $col) {
                if (Schema::hasColumn('item_attributes', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
