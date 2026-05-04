<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_extras', function (Blueprint $table) {
            $table->boolean('is_available')->default(true)->after('group_label');
            $table->string('unavailable_reason', 64)->nullable()->after('is_available');
        });
    }

    public function down(): void
    {
        Schema::table('item_extras', function (Blueprint $table) {
            $table->dropColumn(['is_available', 'unavailable_reason']);
        });
    }
};
