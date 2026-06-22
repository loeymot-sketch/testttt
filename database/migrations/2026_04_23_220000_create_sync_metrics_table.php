<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_metrics', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('metric_type', 64)->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->bigInteger('value');
            $table->char('correlation_id', 36)->nullable();
            $table->json('labels')->nullable();
            $table->dateTime('occurred_at', 3)->index();

            $table->index(['metric_type', 'occurred_at'], 'sync_metrics_type_occurred_idx');
            $table->index(['branch_id', 'occurred_at'], 'sync_metrics_branch_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_metrics');
    }
};
