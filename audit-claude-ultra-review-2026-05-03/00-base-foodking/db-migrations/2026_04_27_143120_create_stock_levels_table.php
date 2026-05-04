<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('stockable_type');
            $table->unsignedBigInteger('stockable_id');
            $table->unsignedInteger('on_hand')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedInteger('threshold_low')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'stockable_type', 'stockable_id'], 'stock_levels_branch_stockable_unique');
            $table->index(['stockable_type', 'stockable_id'], 'stock_levels_stockable_idx');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_on_hand_non_negative CHECK (on_hand >= 0)');
            DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_reserved_non_negative CHECK (reserved >= 0)');
            DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_reserved_lte_on_hand CHECK (reserved <= on_hand)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
