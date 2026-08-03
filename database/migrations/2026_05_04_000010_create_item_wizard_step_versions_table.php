<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('item_wizard_step_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('item_wizard_profiles')
                ->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->timestamp('published_at');
            $table->foreignId('published_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['profile_id', 'version'], 'item_wizard_step_versions_profile_version_unique');
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_wizard_step_versions');
    }
};
