<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('item_branch_availability')) {
            return;
        }

        Schema::create('item_branch_availability', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('branch_id');
            $table->boolean('is_available')->default(true);
            $table->string('unavailable_reason', 32)->nullable();
            $table->dateTime('unavailable_since')->nullable();
            $table->unsignedInteger('max_daily_qty')->nullable();
            $table->unsignedInteger('daily_consumed_qty')->default(0);
            $table->date('daily_reset_at');
            $table->timestamps();
            $table->unique(['item_id', 'branch_id']);
            $table->index(['branch_id', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_branch_availability');
    }
};
