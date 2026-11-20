<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [OWNER 2026-10-21] Fiche du sandwich « Cayenne » : il n'y a PAS de jambon dans la recette (le site ne l'annonçait déjà pas ;
 * la base — donc la borne et la caisse — l'affichait encore), et la « sauce fromagère » se décrit comme ce qu'elle est :
 * un mélange maison de fromage et de crème fraîche.
 *
 * DATA seulement, idempotente, et jamais au détriment d'une retouche du propriétaire : on ne touche la fiche que si elle
 * porte encore le mot « jambon » ou l'ancien libellé (une fiche déjà réécrite à la main n'est pas écrasée).
 * Le nom de la SAUCE « Fromagère maison » (choix de sauce) ne change pas : seule la description du plat change.
 */
return new class extends Migration
{
    private const NEW = 'Poulet mariné, cheddar, oignons rouges, tomates, salade, mélange maison de fromage et crème fraîche.';

    public function up(): void
    {
        $cayenneIds = DB::table('items')
            ->where('name', 'Cayenne')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('description', 'like', '%jambon%')->orWhere('description', 'like', '%sauce fromagère maison%');
            })
            ->pluck('id');

        if ($cayenneIds->isEmpty()) {
            return;
        }

        DB::table('items')->whereIn('id', $cayenneIds)->update(['description' => self::NEW, 'updated_at' => now()]);

        // La recette de STOCK du Cayenne avait été déduite de l'ancienne description (RawMaterialFicheCommand::breadRecipe :
        // « jambon » cité → 1 tranche par sandwich) : sans cette ligne, chaque Cayenne vendu décomptait un jambon qui n'y est pas
        // et le stock de jambon fondait pour rien. La ligne est retirée ; les autres matières (pain, poulet, cheddar…) ne bougent pas.
        if (Schema::hasTable('raw_material_recipe_lines') && Schema::hasTable('raw_materials')) {
            $jambonIds = DB::table('raw_materials')->whereRaw('LOWER(name) = ?', ['jambon'])->pluck('id');
            DB::table('raw_material_recipe_lines')
                ->where('subject_type', 'App\\Models\\Item')
                ->whereIn('subject_id', $cayenneIds)
                ->whereIn('raw_material_id', $jambonIds)
                ->delete();
        }
    }

    public function down(): void
    {
        // No-op volontaire : remettre « jambon » ferait de nouveau mentir la fiche. Retour arrière = décision menu du propriétaire.
    }
};
