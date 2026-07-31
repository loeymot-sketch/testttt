<?php

namespace App\Console\Commands;

use App\Enums\Status;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [OWNER CAYENNE-MIXTE 2026-07-31 · RÉÉCRIT] Le Cayenne (pain #22 + galette #24) :
 *   1. « Mixte (hachée + poulet) » = un CHOIX DE VIANDE GRATUIT (price 0), pas un supplément.
 *      L'owner : « viande en plus de ce qui est inclus = payant, MAIS le Cayenne mixte gratuit
 *      comme un choix de viande ». → variation attr1 « Viande 1 » @0, visible_on=null (borne+caisse+web).
 *   2. Backfill viande #22 : le Cayenne pain (#22) avait 0 variation viande en base alors que le
 *      web/borne affiche 7 viandes (pool partagé) → toute viande choisie était DROPPÉE au scellement
 *      (pickVariation ne trouvait rien). On copie les 7 viandes canoniques depuis le Cayenne galette
 *      (#24, même recette) @0.
 *   3. « Sans sauce » = un CHOIX DE SAUCE GRATUIT (price 0) dans l'étape sauce (attr5, min1) : le
 *      client peut explicitement ne pas prendre de sauce.
 *
 * Money-path : tout @0 → rien à facturer (le surplus viande reste l'ItemExtra « Viande supplémentaire »
 * @2,50, INCHANGÉ). Le prix est scellé backend (PricingService lit le prix DB de la variation).
 * DATA only, idempotent (par nom+item+attr), 0 frozen. L'ancien extra pos-only @2,50 « Mixte » n'a
 * jamais été wiré (aucune migration) → rien à nettoyer.
 */
class EnsureCayenneMixteCommand extends Command
{
    protected $signature = 'menu:ensure-cayenne-mixte {--dry-run : Compter sans écrire}';

    protected $description = "Le Cayenne : « Mixte (hachée+poulet) » + « Sans sauce » = choix GRATUITS (variations @0, borne+caisse+web) + backfill viandes #22. Idempotent.";

    public const MIXTE_NAME = 'Mixte (hachée + poulet)';

    public const SANS_SAUCE_NAME = 'Sans sauce';

    /** Attributs résolus par NOM (robuste aux ids ; le step wizard cible ces refs). */
    public const ATTR_VIANDE_1_NAME = 'Viande 1';

    public const ATTR_SAUCE_NAME = 'Sauce (1ère Gratuite)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $n = self::ensure($dry);
        $this->info(($dry ? '[dry-run] ' : '')."Le Cayenne mixte/sans-sauce — {$n} variation(s) ajoutée(s).");

        return self::SUCCESS;
    }

    /**
     * @return int nombre de variations créées (ou qui seraient créées en dry-run)
     */
    public static function ensure(bool $dryRun = false): int
    {
        $created = 0;

        // Attributs « Viande 1 » / « Sauce (1ère Gratuite) » par NOM (ids stables en prod : 1 & 5,
        // mais on ne les code pas en dur → migration/test robustes). Absent → on saute proprement.
        $viandeAttrId = self::attrIdByName(self::ATTR_VIANDE_1_NAME);
        $sauceAttrId = self::attrIdByName(self::ATTR_SAUCE_NAME);

        // Les 2 Cayenne, par nom (robuste aux ids). #22 = Sandwichs, #24 = Galette.
        $cayennes = DB::table('items')
            ->whereIn('name', ['Cayenne', 'Galette Cayenne'])
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->get();

        foreach ($cayennes as $item) {
            if ($viandeAttrId !== null) {
                // 2. Backfill viande si l'item n'a AUCUNE variation « Viande 1 » (cas #22 Cayenne pain).
                $meatCount = DB::table('item_variations')
                    ->where('item_id', $item->id)
                    ->where('item_attribute_id', $viandeAttrId)
                    ->whereNull('deleted_at')
                    ->count();

                if ($meatCount === 0) {
                    $created += self::backfillMeatsFromSibling($item->id, $viandeAttrId, $dryRun);
                }

                // 1. « Mixte (hachée + poulet) » @0 dans Viande 1.
                $created += self::ensureVariation($item->id, $viandeAttrId, self::MIXTE_NAME, $dryRun);
            }

            // 3. « Sans sauce » @0 dans l'étape sauce.
            if ($sauceAttrId !== null) {
                $created += self::ensureVariation($item->id, $sauceAttrId, self::SANS_SAUCE_NAME, $dryRun);
            }
        }

        return $created;
    }

    private static function attrIdByName(string $name): ?int
    {
        $id = DB::table('item_attributes')->where('name', $name)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Copie les 7 viandes canoniques depuis un item frère (même liste, price 0). Source : un item
     * ACTIF portant des variations « Viande 1 » distinctes de « Mixte » (ex. Galette Cayenne #24,
     * Méga #104). Aucune invention : on réplique une liste existante de la carte.
     */
    private static function backfillMeatsFromSibling(int $targetItemId, int $viandeAttrId, bool $dryRun): int
    {
        $sibling = DB::table('item_variations as v')
            ->join('items as i', 'i.id', '=', 'v.item_id')
            ->where('v.item_attribute_id', $viandeAttrId)
            ->where('v.name', '!=', self::MIXTE_NAME)
            ->where('i.status', Status::ACTIVE)
            ->whereNull('v.deleted_at')
            ->whereNull('i.deleted_at')
            ->where('v.item_id', '!=', $targetItemId)
            ->select('v.item_id')
            ->groupBy('v.item_id')
            ->orderByRaw('COUNT(*) DESC')
            ->first();

        if (! $sibling) {
            return 0;
        }

        $meats = DB::table('item_variations')
            ->where('item_id', $sibling->item_id)
            ->where('item_attribute_id', $viandeAttrId)
            ->where('name', '!=', self::MIXTE_NAME)
            ->whereNull('deleted_at')
            ->get();

        $created = 0;
        foreach ($meats as $m) {
            $created += self::ensureVariation($targetItemId, $viandeAttrId, $m->name, $dryRun, $m->image_path ?? null);
        }

        return $created;
    }

    /**
     * Insère une variation @0 si absente (idempotent par item+attr+nom). visible_on=null =
     * toutes surfaces (borne+caisse ; le web tire le mapping par nom). status ACTIVE.
     */
    private static function ensureVariation(int $itemId, int $attrId, string $name, bool $dryRun, ?string $imagePath = null): int
    {
        $exists = DB::table('item_variations')
            ->where('item_id', $itemId)
            ->where('item_attribute_id', $attrId)
            ->where('name', $name)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return 0;
        }

        if ($dryRun) {
            return 1;
        }

        $row = [
            'item_id'           => $itemId,
            'item_attribute_id' => $attrId,
            'name'              => $name,
            'price'             => 0,
            'status'            => Status::ACTIVE,
            'visible_on'        => null,
            'created_at'        => now(),
            'updated_at'        => now(),
        ];
        // image_path : présent en prod (MySQL), absent du schéma sqlite de test → conditionnel.
        if (Schema::hasColumn('item_variations', 'image_path')) {
            $row['image_path'] = $imagePath;
        }

        DB::table('item_variations')->insert($row);

        return 1;
    }
}
