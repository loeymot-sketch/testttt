<?php

use App\Console\Commands\EnsureViandeSupplementExtrasCommand;
use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [OWNER 2026-08-03 « viande supplémentaire sur le Cayenne ne fonctionne pas »]
 * DATA-only, idempotente. Racine : le 07-31 (EnsureCayenneMixteCommand) a donné au
 * Cayenne un VRAI attribut viande (Poulet mariné / Viande Hachée / Mixte, gratuits)
 * mais `menu:ensure-viande-supplement-extras` n'a jamais été rejoué → PAS d'ItemExtra
 * « Viande supplémentaire » : le wizard caisse affiche +2,50 au-delà du quota mais ne
 * scelle RIEN (fantôme, classe du bug 07-01) et la borne désactive le dépassement.
 *
 * 1. Rejoue ensure() → l'extra « Viande supplémentaire » @2,50 est posé sur tout item
 *    à attribut viande (dont Cayenne #22).
 * 2. Désactive l'extra legacy « Viande en plus » (contournement viande-FIXE) sur les
 *    items qui ont DÉSORMAIS un attribut viande — sinon double exposition (tuile
 *    supplément générique + dépassement de tuile) et supplément non nommé en cuisine.
 * 3. Désactive l'extra PAYANT « Mixte (…) » résiduel : « Mixte » est un CHOIX GRATUIT
 *    (variation, mandat owner 07-31) — l'extra @2,50 le contredit et double-facture.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Extra « Viande supplémentaire » garanti (idempotent, commande canonique).
        EnsureViandeSupplementExtrasCommand::ensure();

        // Items portant un attribut viande (mêmes critères que la commande).
        $viandeAttrIds = DB::table('item_attributes')
            ->whereRaw('LOWER(name) LIKE ?', ['%viande%'])->pluck('id');
        $viandeItemIds = DB::table('item_variations')
            ->whereIn('item_attribute_id', $viandeAttrIds)
            ->where('status', Status::ACTIVE)->whereNull('deleted_at')
            ->distinct()->pluck('item_id');

        // 2. Legacy « Viande en plus » désactivé là où le dépassement de tuile existe.
        DB::table('item_extras')
            ->whereIn('item_id', $viandeItemIds)
            ->whereRaw("LOWER(name) = 'viande en plus'")
            ->where('status', Status::ACTIVE)
            ->update(['status' => Status::INACTIVE, 'updated_at' => now()]);

        // 3. Extra payant « Mixte … » contredisant la variation gratuite.
        DB::table('item_extras')
            ->whereIn('item_id', $viandeItemIds)
            ->whereRaw("LOWER(name) LIKE 'mixte%'")
            ->where('price', '>', 0)
            ->where('status', Status::ACTIVE)
            ->update(['status' => Status::INACTIVE, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // No-op volontaire : réactiver des extras legacy re-créerait le fantôme +2,50.
        // Retour arrière = décision menu owner, pas mécanique.
    }
};
