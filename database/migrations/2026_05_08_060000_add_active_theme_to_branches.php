<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave Gamma G3 — V2-5 Skinning saisonnier (greenfield).
 *
 * Adds a per-branch active kiosk theme slot so admins can switch the kiosk
 * brand skin (Halloween, Noël, FIFA…) without redeploying. The column is
 * additive and nullable — kiosks fall back to `'standard'` when null,
 * which preserves the current visual identity for every existing branch.
 *
 * Theme switching is purely cosmetic (CSS variable overrides driven by a
 * `:root[data-kiosk-theme="..."]` selector); no business logic, NF525
 * fiscal flow, or accessibility constraint depends on this column. We
 * cap the string length at 32 to keep the slot lightweight in the
 * branches admin index queries — theme slugs stay as `[a-z0-9-]+`.
 *
 * Backfill is unnecessary: a NULL value is interpreted as `standard` by
 * the controller and the JS theme manager.
 *
 * @see app/Http/Controllers/Admin/KioskThemeController.php
 * @see resources/js/services/kioskThemeManager.js
 * @see plans/PLAN_DESIGN_V2_5_SKINNING_SAISONNIER_2026-05-08.md
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            if (!Schema::hasColumn('branches', 'active_kiosk_theme')) {
                // Slot per-branch pour le thème kiosk actif. Nullable → fallback 'standard'.
                $table->string('active_kiosk_theme', 32)->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            if (Schema::hasColumn('branches', 'active_kiosk_theme')) {
                $table->dropColumn('active_kiosk_theme');
            }
        });
    }
};
