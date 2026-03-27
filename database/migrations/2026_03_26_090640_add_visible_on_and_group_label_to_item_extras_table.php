<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_extras', function (Blueprint $table) {
            if (!Schema::hasColumn('item_extras', 'visible_on')) {
                // null = visible on all surfaces; JSON array restricts to listed surfaces.
                // Valid values: "kiosk", "pos", "web"
                $table->json('visible_on')->nullable()->after('status')
                    ->comment('null=all surfaces | ["kiosk","pos","web"]');
            }
            if (!Schema::hasColumn('item_extras', 'group_label')) {
                // Optional display group for the kiosk wizard (e.g. "Sauce", "Supplément", "Garniture")
                $table->string('group_label', 50)->nullable()->after('visible_on');
            }
        });
    }

    public function down(): void
    {
        Schema::table('item_extras', function (Blueprint $table) {
            $table->dropColumn(['visible_on', 'group_label']);
        });
    }
};
