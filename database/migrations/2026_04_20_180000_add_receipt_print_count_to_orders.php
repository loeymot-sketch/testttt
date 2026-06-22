<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * [W8.C-P3 / P-MEGA-22 Pilier 3] Add receipt_print_count to track
     * NF525 DUPLICATA marker on POS receipt re-prints.
     *
     * - 0 = original
     * - 1+ = print count after the original print flow
     */
    public function up(): void
    {
        if (! Schema::hasTable('orders') || Schema::hasColumn('orders', 'receipt_print_count')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('receipt_print_count')
                ->default(0)
                ->comment('NF525 W8.C-P3: count of receipt prints; 0=original, 1+=duplicata');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'receipt_print_count')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('receipt_print_count');
        });
    }
};
