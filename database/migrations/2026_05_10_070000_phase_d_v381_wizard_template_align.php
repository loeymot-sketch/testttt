<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V3.8.1 (2026-05-10) Phase D — Aligner wizard_template DB sur le bon template.
 *
 * Owner audit complet : Ojja + Menus Enfants ont wizard_template='simple' en DB,
 * ce qui force le wizard à utiliser le 'default' template (frites_style +
 * supplements + recap) au lieu du bon template 'omelette' (sauce + garnitures
 * + supplements + recap).
 *
 * Items concernés :
 *  - Cat 310 Ojja (4 items) : wizard_template 'simple' → 'omelette'
 *    (frites incluses, doit avoir flow simple sauce+garnitures+supplements)
 *  - Cat 314 Menus Enfants (2 items) : 'simple' → 'omelette'
 *    (déjà formule complète Frites incluses)
 *
 * Cat 315 Frites & Accompagnements reste 'simple' (default template OK pour
 * frites items eux-mêmes — affichent frites_style step approprié).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('item_categories')
            ->whereIn('id', [310, 314])
            ->update([
                'wizard_template' => 'omelette',
                'updated_at'      => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('item_categories')
            ->whereIn('id', [310, 314])
            ->update([
                'wizard_template' => 'simple',
                'updated_at'      => now(),
            ]);
    }
};
