<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V3.8 (2026-05-10) Phase D fix — revert menu choice sur catégories
 * Ojja + Omelettes (frites déjà incluses dans le prix base, owner audit).
 *
 * Owner gate : "les omelettes contiennent déjà des frites... ainsi que OJJA
 * alors nous proposons un menu etc. c'est faux".
 *
 * Audit catalog (descriptions DB confirmées) :
 *  - Cat 309 Assiettes : "+ Frites + Salade + Pain + Sauce" — frites incluses
 *    (PAS modifié par Phase D, has_menu déjà false, OK)
 *  - Cat 310 Ojja : "+ Frites + Pain" — frites incluses → revert has_menu=true → false
 *  - Cat 311 Omelettes : "+ Frites + Pain" — frites incluses → revert has_menu=true → false
 *  - Cat 312 Salades : pas de frites — has_menu=true CONSERVÉ (menu opt = upsell pertinent)
 *  - Cat 313 Poulet croustillant : pas de frites — has_menu=true CONSERVÉ
 *
 * Conséquences :
 *  - Wizard pour Omelettes/Ojja : sauce + garnitures + supplements + recap
 *    (pas de step menu fantôme ni frites_style step puisque frites inclues).
 *  - Les rows item_extras supplement_clone + frites_style attachées par
 *    Phase D restent dormant pour ces catégories (shouldShowStep filtre out).
 *    Pas de cleanup nécessaire — pas de pollution UI.
 *  - POS catalog : has_menu=false sur ces cats → POS Vanilla wizard ne propose
 *    plus le menu choice à staff (sync naturelle).
 */
return new class extends Migration {
    private const FRITES_INCLUDED_CATS = [310, 311]; // Ojja, Omelettes

    public function up(): void
    {
        DB::table('item_categories')
            ->whereIn('id', self::FRITES_INCLUDED_CATS)
            ->update([
                'has_menu'   => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Re-flip has_menu=true (rollback) — réintroduit l'incohérence
        // Phase D originale, à éviter sauf debug.
        DB::table('item_categories')
            ->whereIn('id', self::FRITES_INCLUDED_CATS)
            ->update([
                'has_menu'   => true,
                'updated_at' => now(),
            ]);
    }
};
