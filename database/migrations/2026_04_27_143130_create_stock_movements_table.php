<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_level_id')->constrained('stock_levels')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->integer('delta');
            $table->enum('reason', ['order_created', 'order_canceled', 'refund', 'manual_in', 'manual_out', 'rupture_set']);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique('stock_movements_idempotency_unique');
            $table->timestamp('created_at')->nullable();

            $table->index(['branch_id', 'stock_level_id', 'created_at'], 'stock_movements_branch_level_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
