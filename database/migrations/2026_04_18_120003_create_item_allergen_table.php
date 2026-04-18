<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiosk Design V1 — Phase 1.1 (3/8)
 *
 * Pivot item ↔ allergen avec flag `is_trace` (indique trace vs ingrédient).
 *
 * Cohabite avec `items.allergen_flags` JSON existant — le pivot devient la
 * source normalisée ; le JSON sera projeté par un listener `AllergenService`
 * (Phase 2) pour ne pas régresser les consommateurs legacy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('item_allergen')) {
            Schema::create('item_allergen', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
                $table->foreignId('allergen_id')->constrained('allergens')->cascadeOnDelete();
                $table->boolean('is_trace')->default(false);
                $table->timestamps();

                $table->unique(['item_id', 'allergen_id'], 'item_allergen_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_allergen');
    }
};
