<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('event_type', 128);
            $table->string('aggregate_type', 128);
            $table->unsignedBigInteger('aggregate_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->json('payload');
            $table->string('channel', 255)->nullable();
            $table->string('broadcast_as', 128)->nullable();
            $table->char('correlation_id', 36)->nullable();
            $table->dateTime('occurred_at', 3);
            $table->dateTime('dispatched_at', 3)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['dispatched_at', 'occurred_at'], 'idx_pending');
            $table->index(['aggregate_type', 'aggregate_id'], 'idx_aggregate');
            $table->index(['branch_id', 'occurred_at'], 'idx_branch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
    }
};
