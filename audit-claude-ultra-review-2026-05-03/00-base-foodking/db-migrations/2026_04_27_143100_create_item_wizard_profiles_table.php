<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_wizard_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->enum('template', ['simple', 'sandwich', 'tacos', 'assiette', 'snacking', 'menu', 'custom'])->default('simple');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('branch_id_scope')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();

            $table->index(['item_id', 'branch_id_scope'], 'item_wizard_profiles_item_branch_idx');
            $table->index('is_published', 'item_wizard_profiles_published_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_wizard_profiles');
    }
};
