<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dining_table_audit_logs')) {
            return;
        }

        Schema::create('dining_table_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->string('action', 24);
            $table->unsignedBigInteger('source_table_id')->nullable();
            $table->unsignedBigInteger('target_table_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['branch_id', 'created_at']);
            $table->index(['order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dining_table_audit_logs');
    }
};
