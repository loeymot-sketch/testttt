<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_wizard_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('item_wizard_profiles')->cascadeOnDelete();
            $table->string('step_key');
            $table->string('label');
            $table->enum('source_type', ['item_attribute', 'extra_group', 'addon', 'fixed']);
            $table->string('source_ref')->default('');
            $table->unsignedInteger('min_select')->default(0);
            $table->unsignedInteger('max_select')->default(1);
            $table->boolean('allow_repeat')->default(false);
            $table->json('visible_on')->nullable();
            $table->boolean('stockable_choices')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->enum('addon_role', ['drink', 'side', 'dessert', 'menu_component', 'upsell'])->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'position'], 'item_wizard_steps_profile_position_idx');
            $table->unique(['profile_id', 'step_key'], 'item_wizard_steps_profile_key_unique');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE item_wizard_steps ADD CONSTRAINT item_wizard_steps_min_lte_max CHECK (min_select <= max_select)');
            DB::statement('ALTER TABLE item_wizard_steps ADD CONSTRAINT item_wizard_steps_position_non_negative CHECK (position >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_wizard_steps');
    }
};
