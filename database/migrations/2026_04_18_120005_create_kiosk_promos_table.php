<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiosk Design V1 — Phase 1.1 (5/8)
 *
 * Promotions kiosk scoped par branche (distinctes des `coupons` globaux).
 * Les coupons globaux restent intacts et prioritaires ne sont PAS touchés.
 *
 * `type` : 'percent' (value = %) ou 'amount' (value = € fixe).
 *
 * SSOT : la valeur de discount finale est TOUJOURS recalculée serveur-side
 * (jamais écrite depuis le client). Cette table ne fait que stocker les
 * paramètres.
 *
 * Multi-tenant : `unique(branch_id, code)` permet le même code sur 2 branches.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('kiosk_promos')) {
            Schema::create('kiosk_promos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

                $table->string('code', 64);
                $table->string('type', 16);              // 'percent' | 'amount' — enforced by model
                $table->decimal('value', 10, 2);

                $table->decimal('min_cart', 10, 2)->default(0);
                $table->dateTime('valid_from')->nullable();
                $table->dateTime('valid_to')->nullable();

                $table->unsignedInteger('max_uses')->nullable();
                $table->unsignedInteger('uses_count')->default(0);

                $table->boolean('active')->default(true);

                $table->timestamps();
                $table->softDeletes();

                $table->unique(['branch_id', 'code'], 'kiosk_promos_branch_code_unique');
                $table->index(['branch_id', 'active'], 'kiosk_promos_branch_active_idx');
                $table->index(['valid_from', 'valid_to'], 'kiosk_promos_window_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_promos');
    }
};
