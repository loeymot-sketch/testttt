<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_status_transitions')) {
            return;
        }

        Schema::create('order_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('order_type', 191);
            $table->unsignedSmallInteger('from_status');
            $table->unsignedSmallInteger('to_status');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type', 64)->nullable();
            $table->text('reason')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->index(['order_id', 'order_type', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_transitions');
    }
};
