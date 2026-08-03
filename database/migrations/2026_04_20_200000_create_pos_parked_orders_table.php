<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_parked_orders')) {
            return;
        }

        Schema::create('pos_parked_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->string('label', 80)->nullable();
            $table->longText('payload_json');
            $table->decimal('preview_total', 12, 2)->default(0);
            $table->unsignedSmallInteger('items_count')->default(0);
            $table->string('idempotency_token', 64)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'user_id', 'created_at'], 'pos_parked_branch_user_idx');
            $table->unique(['user_id', 'idempotency_token'], 'pos_parked_user_idem_uniq');

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_parked_orders');
    }
};
