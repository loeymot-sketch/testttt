<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FrontendOrder uses the same `orders` table as Order — only migrate once.
        foreach (['orders', 'order_items', 'branches', 'item_categories'] as $tbl) {
            if (Schema::hasTable($tbl) && !Schema::hasColumn($tbl, 'deleted_at')) {
                Schema::table($tbl, function (Blueprint $table) {
                    $table->softDeletes();
                });
            }
        }

        if (!Schema::hasTable('deletion_log')) {
            Schema::create('deletion_log', function (Blueprint $table) {
                $table->id();
                $table->string('model_type', 191);
                $table->unsignedBigInteger('model_id');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('actor_type', 64)->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('deleted_at');
                $table->index(['model_type', 'model_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_log');

        foreach (['orders', 'order_items', 'branches', 'item_categories'] as $tbl) {
            if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, 'deleted_at')) {
                Schema::table($tbl, function (Blueprint $table) {
                    $table->dropSoftDeletes();
                });
            }
        }
    }
};
