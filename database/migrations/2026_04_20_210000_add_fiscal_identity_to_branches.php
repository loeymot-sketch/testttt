<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (! Schema::hasColumn('branches', 'siret')) {
                $table->string('siret', 14)->nullable()->after('zone');
            }
            if (! Schema::hasColumn('branches', 'vat_intra')) {
                $table->string('vat_intra', 16)->nullable()->after('siret');
            }
            if (! Schema::hasColumn('branches', 'register_id')) {
                $table->string('register_id', 32)->nullable()->after('vat_intra');
            }
            if (! Schema::hasColumn('branches', 'legal_footer')) {
                $table->string('legal_footer', 255)->nullable()->after('register_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            foreach (['legal_footer', 'register_id', 'vat_intra', 'siret'] as $col) {
                if (Schema::hasColumn('branches', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
