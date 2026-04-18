<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiosk Design V1 — Phase 1.1 (4/8)
 *
 * Règles d'upsell administrables par branche.
 *
 * `trigger_type` (string enum-like, validé au niveau modèle/service) :
 *   - category_in_cart  → trigger_value = {"category_id": <int>}
 *   - item_in_cart      → trigger_value = {"item_id": <int>}
 *   - cart_total_gte    → trigger_value = {"amount": <float>}
 *
 * Invariant §1.5 : aucune génération auto (pas de ML, pas de stats de vente).
 * Configuration 100 % admin statique. Les ventes ne pilotent jamais la
 * suggestion.
 *
 * `priority` : tri décroissant (plus grand = affiché en premier).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('upsell_rules')) {
            Schema::create('upsell_rules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

                $table->string('trigger_type', 32);  // enforced by model
                $table->json('trigger_value');

                $table->foreignId('suggested_item_id')->constrained('items')->cascadeOnDelete();

                $table->boolean('active')->default(true);
                $table->integer('priority')->default(0);

                $table->dateTime('starts_at')->nullable();
                $table->dateTime('ends_at')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->index(['branch_id', 'active'], 'upsell_rules_branch_active_idx');
                $table->index(['trigger_type'], 'upsell_rules_trigger_idx');
                $table->index(['starts_at', 'ends_at'], 'upsell_rules_window_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('upsell_rules');
    }
};
